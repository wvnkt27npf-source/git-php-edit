<?php
include 'db.php';

// =====================================================
// EXPORT TO CSV - MUST BE BEFORE ANY HTML OUTPUT!
// =====================================================
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices_export_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel
    
    // Write headers without spaces to avoid auto-quoting
    fputcsv($output, [
        'SrNo', 'InvoiceNo', 'Date', 'PartyName', 'PartyPhone',
        'AgentName', 'AgentPhone', 'TotalAmount', 'PaidAmount',
        'Outstanding', 'Status', 'Remarks'
    ]);
    
    $export_query = "SELECT i.*, c.party_name, c.phone as party_phone, c.agent_name, c.agent_phone
                     FROM invoices i LEFT JOIN clients c ON i.party_id = c.id
                     ORDER BY i.date DESC, i.id DESC";
    
    $result = $conn->query($export_query);
    $sr = 1;
    
    while ($row = $result->fetch_assoc()) {
        $outstanding = ($row['total_amount'] ?? 0) - ($row['paid_amount'] ?? 0);
        fputcsv($output, [
            $sr++,
            $row['invoice_no'],
            date('d-m-Y', strtotime($row['date'])),
            $row['party_name'],
            $row['party_phone'],
            $row['agent_name'],
            $row['agent_phone'],
            $row['total_amount'],
            $row['paid_amount'] ?? 0,
            $outstanding,
            $row['status'],
            $row['remarks'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;
}

// =====================================================
// API: GET INVOICE DATA FOR EDIT - BEFORE HTML OUTPUT
// =====================================================
if (isset($_GET['get_invoice']) && isset($_GET['id'])) {
    header('Content-Type: application/json');
    $inv_id = intval($_GET['id']);
    
    $result = $conn->query("SELECT i.*, c.party_name 
                            FROM invoices i 
                            LEFT JOIN clients c ON i.party_id = c.id 
                            WHERE i.id = $inv_id");
    
    if ($result->num_rows > 0) {
        echo json_encode($result->fetch_assoc());
    } else {
        echo json_encode(['error' => 'Invoice not found']);
    }
    exit;
}

// NOW include header after all non-HTML handlers
include 'header.php';

// --- SELECT2 CSS ---
echo '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />';

$alert_script = ""; // Global variable for alerts

// =====================================================
// IMPORT FROM CSV
// =====================================================

// =====================================================
// IMPORT FROM CSV
// =====================================================
if (isset($_POST['import_csv']) && !empty($_FILES['csv_file']['name'])) {
    $file = $_FILES['csv_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($ext != 'csv') {
        $alert_script = "<script>Swal.fire('Error','Please upload a CSV file only','error');</script>";
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        
        // Detect delimiter: TAB or COMMA
        $first_line = fgets($handle);
        rewind($handle);
        $delimiter = (strpos($first_line, "\t") !== false) ? "\t" : ",";
        
        $header = fgetcsv($handle, 0, $delimiter); // Skip header with correct delimiter
        
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $errors = [];
        
        while (($data = fgetcsv($handle, 0, $delimiter)) !== FALSE) {
            if (count($data) < 4) { $skipped++; continue; }
            
            // Skip if this looks like a header row (check if first column is "Sr No" or similar)
            $first_col = strtolower(trim($data[0]));
            if (in_array($first_col, ['sr no', 'sr', 'sno', 's.no', 'invoice no', 'invoice_no', 'invoiceno', '#', 'no', 'no.'])) {
                continue; // Skip header-like rows
            }
            
            // CSV format: SrNo, InvoiceNo, Date, PartyName, PartyPhone, AgentName, AgentPhone, TotalAmount, PaidAmount, Outstanding, Status, Remarks
            $invoice_no = $conn->real_escape_string(trim($data[1])); // Column 1 = InvoiceNo
            $invoice_date = date('Y-m-d', strtotime(trim($data[2]))); // Column 2 = Date
            $party_name = $conn->real_escape_string(trim($data[3])); // Column 3 = PartyName
            $party_phone = isset($data[4]) ? $conn->real_escape_string(trim($data[4])) : ''; // Column 4 = PartyPhone
            $total_amount = isset($data[7]) ? floatval(str_replace(',', '', trim($data[7]))) : 0; // Column 7 = TotalAmount
            $paid_amount = isset($data[8]) ? floatval(str_replace(',', '', trim($data[8]))) : 0; // Column 8 = PaidAmount
            $status = isset($data[10]) ? $conn->real_escape_string(trim($data[10])) : 'Pending'; // Column 10 = Status
            $remarks = isset($data[11]) ? $conn->real_escape_string(trim($data[11])) : ''; // Column 11 = Remarks
            
            // Find or create party
            $party_check = $conn->query("SELECT id FROM clients WHERE party_name = '$party_name'");
            if ($party_check->num_rows > 0) {
                $party_id = $party_check->fetch_assoc()['id'];
            } else {
                $conn->query("INSERT INTO clients (party_name, phone) VALUES ('$party_name', '$party_phone')");
                $party_id = $conn->insert_id;
            }
            
            // UPSERT: Check if invoice exists - UPDATE if yes, INSERT if no
            $check = $conn->query("SELECT id FROM invoices WHERE invoice_no = '$invoice_no'");
            if ($check->num_rows > 0) {
                // UPDATE existing invoice
                $existing_id = $check->fetch_assoc()['id'];
                $conn->query("UPDATE invoices SET 
                    date = '$invoice_date',
                    party_id = $party_id,
                    total_amount = $total_amount,
                    paid_amount = $paid_amount,
                    status = '$status',
                    remarks = '$remarks'
                WHERE id = $existing_id");
                $updated++;
            } else {
                // INSERT new invoice
                $conn->query("INSERT INTO invoices (invoice_no, date, party_id, total_amount, paid_amount, remarks, status) 
                              VALUES ('$invoice_no', '$invoice_date', $party_id, $total_amount, $paid_amount, '$remarks', '$status')");
                $imported++;
            }
        }
        
        fclose($handle);
        
        $error_msg = count($errors) > 0 ? '<br><small>' . implode('<br>', array_slice($errors, 0, 5)) . '</small>' : '';
        $alert_script = "<script>Swal.fire('Import Complete','New: $imported<br>Updated: $updated<br>Skipped: $skipped$error_msg','success').then(()=>window.location='invoices.php');</script>";
    }
}

// =====================================================
// EDIT INVOICE HANDLER
// =====================================================
if (isset($_POST['edit_invoice'])) {
    $inv_id = intval($_POST['invoice_id']);
    $invoice_no = $conn->real_escape_string($_POST['invoice_no']);
    $invoice_date = $_POST['invoice_date'];
    $party_id = intval($_POST['party_id']);
    $total_amount = floatval($_POST['total_amount']);
    $remarks = $conn->real_escape_string($_POST['remarks'] ?? '');
    
    $conn->query("UPDATE invoices SET 
        invoice_no = '$invoice_no',
        date = '$invoice_date',
        party_id = $party_id,
        total_amount = $total_amount,
        remarks = '$remarks',
        updated_at = NOW()
    WHERE id = $inv_id");
    
    $alert_script = "<script>Swal.fire('Updated!','Invoice updated successfully','success').then(()=>window.location='invoices.php');</script>";
}

// =====================================================
// MULTI-UPLOAD HANDLER (Drag-Drop)
// =====================================================
if (isset($_POST['multi_upload'])) {
    $inv_id = intval($_POST['inv_id']);
    $has_bilti = false;
    $uploaded = 0;
    
    $files_map = [
        'invoice_file' => 'invoice_path',
        'packing_file' => 'packing_path',
        'bilti_file' => 'bilti_path'
    ];
    
    foreach ($files_map as $input_name => $col_name) {
        if (!empty($_FILES[$input_name]['name'])) {
            $file = $_FILES[$input_name];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            
            if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                $new_name = $col_name . "_" . $inv_id . "_" . time() . "." . $ext;
                if (!is_dir('uploads')) mkdir('uploads', 0777, true);
                $upload_path = "uploads/" . $new_name;
                
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    $conn->query("UPDATE invoices SET $col_name = '$upload_path' WHERE id = $inv_id");
                    $uploaded++;
                    if ($col_name == 'bilti_path') $has_bilti = true;
                }
            }
        }
    }
    
    if ($has_bilti) {
        header("Location: dispatch.php?id=$inv_id");
        exit;
    } else {
        header("Location: invoices.php");
        exit;
    }
}

// =====================================================
// DELETE INVOICE
// =====================================================
if (isset($_POST['delete_invoice'])) {
    $id = intval($_POST['del_id']);
    $files = $conn->query("SELECT invoice_path, packing_path, bilti_path FROM invoices WHERE id=$id")->fetch_assoc();
    if($files) {
        if(!empty($files['invoice_path']) && file_exists($files['invoice_path'])) unlink($files['invoice_path']);
        if(!empty($files['packing_path']) && file_exists($files['packing_path'])) unlink($files['packing_path']);
        if(!empty($files['bilti_path']) && file_exists($files['bilti_path'])) unlink($files['bilti_path']);
    }
    $conn->query("DELETE FROM invoices WHERE id=$id");
    $alert_script = "<script>Swal.fire('Deleted!', 'Invoice has been removed.', 'success').then(() => { window.location='invoices.php'; });</script>";
}

// =====================================================
// MARK LOCAL DELIVERY
// =====================================================
if (isset($_POST['mark_local'])) {
    $id = intval($_POST['inv_id']);
    $conn->query("UPDATE invoices SET status = 'Closed' WHERE id=$id");
    $alert_script = "<script>window.location='invoices.php';</script>";
}

// =====================================================
// DELETE SINGLE FILE
// =====================================================
if (isset($_POST['delete_file'])) {
    $inv_id = intval($_POST['inv_id']);
    $col_name = $_POST['col_name'];
    $file_path = $_POST['file_path'];
    
    $allowed_cols = ['invoice_path', 'packing_path', 'bilti_path'];
    if (in_array($col_name, $allowed_cols)) {
        if (file_exists($file_path)) unlink($file_path);
        $conn->query("UPDATE invoices SET $col_name = NULL WHERE id=$inv_id");
        $alert_script = "<script>window.location='invoices.php';</script>";
    } else {
        $alert_script = "<script>Swal.fire('Error', 'Invalid column name.', 'error');</script>";
    }
}

// =====================================================
// OLD SINGLE UPLOADS (Backward compatibility)
// =====================================================
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_file'])) {
    $inv_id = intval($_POST['inv_id']);
    $col_name = $_POST['file_type']; 
    
    $allowed_cols = ['invoice_path', 'packing_path', 'bilti_path'];
    if (!in_array($col_name, $allowed_cols)) {
        $alert_script = "<script>Swal.fire('Error', 'Invalid file type.', 'error');</script>";
    } else {
        $allowed = ['pdf', 'jpg', 'jpeg', 'png'];
        $ext = strtolower(pathinfo($_FILES['file']['name'], PATHINFO_EXTENSION));
        
        if (in_array($ext, $allowed)) {
            $new_name = $col_name . "_" . $inv_id . "_" . time() . "." . $ext;
            if (!is_dir('uploads')) mkdir('uploads', 0777, true);
            
            if (move_uploaded_file($_FILES['file']['tmp_name'], "uploads/" . $new_name)) {
                $conn->query("UPDATE invoices SET $col_name = 'uploads/$new_name' WHERE id=$inv_id");
                
                if ($col_name == 'bilti_path') {
                    $alert_script = "
                    <script>
                        Swal.fire({
                            title: 'Bilti Uploaded!',
                            text: 'Redirecting to Dispatch Page...',
                            icon: 'success',
                            timer: 2000,
                            showConfirmButton: false
                        }).then(() => { 
                            window.location.href = 'dispatch.php?id=$inv_id'; 
                        });
                    </script>";
                } else {
                    $alert_script = "<script>window.location='invoices.php';</script>";
                }
            }
        } else {
            $alert_script = "<script>Swal.fire('Error', 'Invalid file type. Only PDF/JPG allowed.', 'error');</script>";
        }
    }
}

// =====================================================
// CREATE INVOICE
// =====================================================
if (isset($_POST['create_inv'])) {
    $inv = $conn->real_escape_string(trim($_POST['invoice_no']));
    $pid = intval($_POST['party_id']);
    $date = !empty($_POST['invoice_date']) ? $_POST['invoice_date'] : date('Y-m-d');
    $total_amount = floatval($_POST['total_amount'] ?? 0);
    
    $check = $conn->query("SELECT id FROM invoices WHERE invoice_no = '$inv'");
    
    if($check->num_rows > 0){
        $alert_script = "<script>Swal.fire('Duplicate Error', 'Invoice Number $inv already exists!', 'warning');</script>";
    } else {
        $conn->query("INSERT INTO invoices (invoice_no, party_id, date, total_amount, status) VALUES ('$inv', '$pid', '$date', '$total_amount', 'Open')");
        $alert_script = "<script>window.location='invoices.php';</script>";
    }
}

// =====================================================
// FETCH & SEPARATE DATA
// =====================================================
$pending_invoices = [];
$completed_invoices = [];
$res = $conn->query("SELECT i.*, COALESCE(c.party_name, 'Unknown Client') as party_name FROM invoices i LEFT JOIN clients c ON i.party_id=c.id ORDER BY i.id DESC");

while($row = $res->fetch_assoc()) {
    $has_all_docs = (!empty($row['invoice_path']) && !empty($row['packing_path']) && !empty($row['bilti_path']));
    
    if($has_all_docs || $row['status'] == 'Closed') {
        $completed_invoices[] = $row;
    } else {
        $pending_invoices[] = $row;
    }
}
?>

<!-- CSS -->
<style>
.upload-zone {
    border: 2px dashed #dee2e6;
    border-radius: 15px;
    padding: 30px 20px;
    text-align: center;
    cursor: pointer;
    transition: all 0.3s ease;
    background: #f8f9fa;
    min-height: 150px;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
}

.upload-zone:hover {
    border-color: #0d6efd;
    background: #e7f1ff;
    transform: translateY(-2px);
    box-shadow: 0 5px 15px rgba(13, 110, 253, 0.1);
}

.upload-zone.dragover {
    border-color: #198754;
    background: #d1e7dd;
    border-style: solid;
    transform: scale(1.02);
}

.upload-zone.has-file {
    border-color: #198754;
    background: #d1e7dd;
    border-style: solid;
}

.upload-zone.has-file i {
    color: #198754 !important;
}

.upload-zone-bilti {
    background: linear-gradient(135deg, #f8f9fa 0%, #e9ecef 100%);
}

.upload-zone h6 {
    margin-bottom: 5px;
    font-weight: 600;
}

.upload-zone .file-name {
    word-break: break-all;
    max-width: 100%;
}
</style>

<!-- =====================================================
     HEADER BUTTONS: Generate + Export + Import
     ===================================================== -->
<div class="row mb-4">
    <div class="col-12">
        <div class="d-flex gap-2 flex-wrap">
            <!-- Generate Invoice Button -->
            <button type="button" class="btn btn-primary btn-lg shadow-sm" onclick="openGenerateInvoiceModal()">
                <i class="fas fa-plus-circle me-2"></i> Generate New Invoice
            </button>
            
            <!-- Export CSV Button (One Click) -->
            <a href="invoices.php?export=csv" class="btn btn-success btn-lg shadow-sm">
                <i class="fas fa-file-csv me-1"></i> Export CSV
            </a>
            
            <!-- Import CSV Button -->
            <button class="btn btn-info btn-lg text-white shadow-sm" data-bs-toggle="modal" data-bs-target="#importModal">
                <i class="fas fa-file-import me-1"></i> Import CSV
            </button>
        </div>
    </div>
</div>

<!-- =====================================================
     PENDING INVOICES TABLE
     ===================================================== -->
<div class="row">
    <div class="col-12 mb-4">
        <div class="card shadow-sm h-100 border-warning" style="border-left: 5px solid #ffc107;">
            <div class="card-header bg-warning bg-opacity-10 text-dark fw-bold d-flex justify-content-between align-items-center">
                <span><i class="fas fa-history text-warning me-2"></i> Pending Documentation</span>
                <span class="badge bg-warning text-dark"><?php echo count($pending_invoices); ?> Pending</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="pendingTable" class="table table-hover align-middle mb-0 datatable" style="width:100%">
                        <thead class="bg-light">
                            <tr>
                                <th>Invoice</th>
                                <th>Date</th>
                                <th>Amount (₹)</th>
                                <th>Uploaded Docs</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($pending_invoices as $row): 
                                $missing = [];
                                if(empty($row['invoice_path'])) $missing['invoice_path'] = "Commercial Invoice";
                                if(empty($row['packing_path'])) $missing['packing_path'] = "Packing List";
                                if(empty($row['bilti_path']))   $missing['bilti_path'] = "Bilti / LR";
                            ?>
                            <tr>
                                <td data-order="<?php echo $row['id']; ?>">
                                    <span class="fw-bold text-dark fs-5">#<?php echo htmlspecialchars($row['invoice_no']); ?></span><br>
                                    <small class="text-muted fw-bold"><?php echo htmlspecialchars($row['party_name']); ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="fas fa-calendar-alt text-primary me-1"></i>
                                        <?php echo !empty($row['date']) ? date('d M Y', strtotime($row['date'])) : '-'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">
                                        ₹<?php echo number_format($row['total_amount'] ?? 0, 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex flex-wrap gap-2">
                                        <?php 
                                        $docs = ['invoice_path'=>'Invoice', 'packing_path'=>'Packing', 'bilti_path'=>'Bilti'];
                                        $has_doc = false;
                                        foreach($docs as $col => $label):
                                            if(!empty($row[$col])): $has_doc = true; ?>
                                            <div class="btn-group btn-group-sm shadow-sm">
                                                <a href="<?php echo htmlspecialchars($row[$col]); ?>" target="_blank" class="btn btn-outline-success fw-bold" title="View"><i class="fas fa-check-circle"></i> <?php echo $label; ?></a>
                                                <button type="button" class="btn btn-outline-danger" onclick="confirmDeleteFile(<?php echo $row['id']; ?>, '<?php echo $col; ?>', '<?php echo htmlspecialchars($row[$col]); ?>')"><i class="fas fa-times"></i></button>
                                            </div>
                                        <?php endif; endforeach; ?>
                                        
                                        <?php if(!$has_doc): ?>
                                            <span class="badge bg-secondary bg-opacity-10 text-secondary border border-secondary border-opacity-25 p-2">Waiting for Uploads...</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <div class="btn-group">
                                        <!-- EDIT Button -->
                                        <button type="button" class="btn btn-sm btn-warning" onclick="openEditModal(<?php echo $row['id']; ?>)" title="Edit Invoice">
                                            <i class="fas fa-edit"></i>
                                        </button>
                                        
                                        <!-- Upload Button -->
                                        <button type="button" class="btn btn-sm btn-primary" onclick="openUploadModal(<?php echo $row['id']; ?>, '<?php echo htmlspecialchars($row['invoice_no']); ?>')" title="Upload Docs">
                                            <i class="fas fa-cloud-upload-alt"></i> Upload
                                        </button>
                                        
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Mark as Local Delivery? (Bilti will not be required)');">
                                            <input type="hidden" name="inv_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="mark_local" class="btn btn-sm btn-info text-white" title="Mark Local Delivery"><i class="fas fa-shipping-fast"></i> Local</button>
                                        </form>

                                        <a href="dispatch.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-outline-warning text-dark" title="Go to Dispatch"><i class="fas fa-paper-plane"></i></a>
                                        
                                        <form method="POST" onsubmit="return confirm('Are you sure you want to delete this invoice?');" class="d-inline">
                                            <input type="hidden" name="del_id" value="<?php echo $row['id']; ?>">
                                            <button type="submit" name="delete_invoice" class="btn btn-sm btn-outline-danger border-start-0"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================
     COMPLETED INVOICES TABLE
     ===================================================== -->
<div class="row">
    <div class="col-12">
        <div class="card shadow-sm border-success" style="border-left: 5px solid #198754;">
            <div class="card-header bg-success text-white fw-bold d-flex justify-content-between">
                <span><i class="fas fa-check-double"></i> Completed History</span>
                <small>(Includes Local Deliveries)</small>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table id="completedTable" class="table table-hover align-middle mb-0 datatable" style="width:100%">
                        <thead class="bg-light">
                            <tr>
                                <th>ID</th>
                                <th>Invoice Details</th>
                                <th>Date</th>
                                <th>Amount (₹)</th>
                                <th>Type / Docs</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach($completed_invoices as $row): ?>
                            <tr>
                                <td><?php echo $row['id']; ?></td>
                                <td>
                                    <span class="fw-bold text-success">#<?php echo htmlspecialchars($row['invoice_no']); ?></span>
                                    <span class="text-muted mx-2">|</span>
                                    <?php echo htmlspecialchars($row['party_name']); ?>
                                </td>
                                <td>
                                    <span class="badge bg-light text-dark border">
                                        <i class="fas fa-calendar-alt text-success me-1"></i>
                                        <?php echo !empty($row['date']) ? date('d M Y', strtotime($row['date'])) : '-'; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="fw-bold text-success fs-6">
                                        ₹<?php echo number_format($row['total_amount'] ?? 0, 2); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if($row['status'] == 'Closed'): ?>
                                        <span class="badge bg-info mb-1"><i class="fas fa-map-marker-alt"></i> Local Delivery</span><br>
                                    <?php endif; ?>
                                    <div class="d-flex gap-2">
                                        <?php 
                                        $docs = ['invoice_path'=>'Invoice', 'packing_path'=>'Packing', 'bilti_path'=>'Bilti'];
                                        foreach($docs as $col => $label): 
                                            if(!empty($row[$col])): ?>
                                            <div class="btn-group btn-group-sm border rounded">
                                                <a href="<?php echo htmlspecialchars($row[$col]); ?>" target="_blank" class="btn btn-light text-dark fw-bold"><?php echo $label; ?></a>
                                                <button type="button" class="btn btn-light text-danger border-start" onclick="confirmDeleteFile(<?php echo $row['id']; ?>, '<?php echo $col; ?>', '<?php echo htmlspecialchars($row[$col]); ?>')"><i class="fas fa-times"></i></button>
                                            </div>
                                        <?php endif; endforeach; ?>
                                    </div>
                                </td>
                                <td class="text-end">
                                    <!-- EDIT Button -->
                                    <button type="button" class="btn btn-sm btn-warning me-1" onclick="openEditModal(<?php echo $row['id']; ?>)" title="Edit Invoice">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <a href="dispatch.php?id=<?php echo $row['id']; ?>" class="btn btn-sm btn-warning"><i class="fas fa-paper-plane"></i> Dispatch</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete File Form (Hidden) -->
<form id="deleteFileForm" method="POST" style="display:none;">
    <input type="hidden" name="inv_id" id="del_inv_id">
    <input type="hidden" name="col_name" id="del_col_name">
    <input type="hidden" name="file_path" id="del_file_path">
    <input type="hidden" name="delete_file" value="1">
</form>

<!-- =====================================================
     GENERATE INVOICE MODAL
     ===================================================== -->
<div class="modal fade" id="generateInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header bg-primary text-white py-3">
                <h5 class="modal-title">
                    <i class="fas fa-plus-circle me-2"></i> Generate New Invoice
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body p-4">
                <form method="POST" id="generateInvoiceForm">
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted">
                            <i class="fas fa-hashtag text-primary me-1"></i> Invoice Number
                        </label>
                        <input type="text" name="invoice_no" class="form-control form-control-lg" required placeholder="e.g. FSD-1001" autocomplete="off">
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold text-muted">
                            <i class="fas fa-user text-primary me-1"></i> Select Client
                        </label>
                        <select name="party_id" class="form-select form-select-lg select2-modal" required style="width: 100%;">
                            <option value="">Choose Client...</option>
                            <?php 
                            $c = $conn->query("SELECT * FROM clients ORDER BY party_name ASC");
                            while($r=$c->fetch_assoc()) echo "<option value='{$r['id']}'>".htmlspecialchars($r['party_name'])."</option>";
                            ?>
                        </select>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-muted">
                                <i class="fas fa-calendar text-primary me-1"></i> Invoice Date
                            </label>
                            <input type="date" name="invoice_date" class="form-control form-control-lg" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-bold text-muted">
                                <i class="fas fa-rupee-sign text-primary me-1"></i> Total Amount
                            </label>
                            <div class="input-group input-group-lg">
                                <span class="input-group-text bg-success text-white">₹</span>
                                <input type="number" name="total_amount" class="form-control" step="0.01" min="0" placeholder="0.00" required>
                            </div>
                        </div>
                    </div>
                    
                    <hr class="my-3">
                    
                    <button type="submit" name="create_inv" class="btn btn-primary btn-lg w-100 shadow">
                        <i class="fas fa-check-circle me-2"></i> Create Invoice
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<!-- =====================================================
     EDIT INVOICE MODAL
     ===================================================== -->
<div class="modal fade" id="editInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <form method="POST" id="editInvoiceForm">
                <input type="hidden" name="edit_invoice" value="1">
                <input type="hidden" name="invoice_id" id="edit_invoice_id">
                
                <div class="modal-header bg-warning py-3">
                    <h5 class="modal-title">
                        <i class="fas fa-edit me-2"></i> Edit Invoice
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-hashtag text-warning me-1"></i> Invoice Number
                        </label>
                        <input type="text" name="invoice_no" id="edit_invoice_no" class="form-control form-control-lg" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-calendar text-warning me-1"></i> Invoice Date
                        </label>
                        <input type="date" name="invoice_date" id="edit_invoice_date" class="form-control form-control-lg" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-user text-warning me-1"></i> Party
                        </label>
                        <select name="party_id" id="edit_party_id" class="form-select form-select-lg" required>
                            <option value="">-- Select Party --</option>
                            <?php
                            $parties = $conn->query("SELECT id, party_name FROM clients ORDER BY party_name");
                            while ($p = $parties->fetch_assoc()) {
                                echo '<option value="'.$p['id'].'">'.htmlspecialchars($p['party_name']).'</option>';
                            }
                            ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-rupee-sign text-warning me-1"></i> Total Amount
                        </label>
                        <div class="input-group input-group-lg">
                            <span class="input-group-text bg-warning">₹</span>
                            <input type="number" name="total_amount" id="edit_total_amount" class="form-control" step="0.01" min="0" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">
                            <i class="fas fa-sticky-note text-warning me-1"></i> Remarks
                        </label>
                        <textarea name="remarks" id="edit_remarks" class="form-control" rows="2" placeholder="Optional notes..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning btn-lg">
                        <i class="fas fa-save me-1"></i> Update Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =====================================================
     IMPORT CSV MODAL
     ===================================================== -->
<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content shadow-lg border-0">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="import_csv" value="1">
                
                <div class="modal-header bg-info text-white py-3">
                    <h5 class="modal-title">
                        <i class="fas fa-file-import me-2"></i> Import Invoices from CSV
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body p-4">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>CSV Format Required:</strong>
                        <br>Columns: Invoice No, Date, Party Name, Total Amount, Remarks
                        <br><small>First row should be headers (will be skipped)</small>
                    </div>
                    
                    <!-- Sample CSV Download -->
                    <div class="mb-3">
                        <a href="#" onclick="downloadSampleCSV(); return false;" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-download me-1"></i> Download Sample CSV
                        </a>
                    </div>
                    
                    <!-- File Upload Zone -->
                    <div class="upload-zone p-4 text-center" id="csvDropZone" style="cursor:pointer;">
                        <input type="file" name="csv_file" id="csv_file" hidden accept=".csv">
                        <i class="fas fa-file-csv fa-3x text-info mb-2"></i>
                        <h6>Drag & Drop CSV File Here</h6>
                        <small class="text-muted drop-text">or click to browse</small>
                        <div class="file-name text-success fw-bold mt-2" id="csvFileName" style="display:none;"></div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info btn-lg text-white" id="importBtn" disabled>
                        <i class="fas fa-upload me-1"></i> Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- =====================================================
     DRAG-DROP UPLOAD MODAL
     ===================================================== -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-cloud-upload-alt"></i> Upload Documents - <span id="modal_invoice_no"></span>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <form method="POST" enctype="multipart/form-data" id="multiUploadForm">
                    <input type="hidden" name="inv_id" id="upload_inv_id">
                    <input type="hidden" name="multi_upload" value="1">
                    
                    <div class="row g-3">
                        <!-- Commercial Invoice Drop Zone -->
                        <div class="col-md-6">
                            <div class="upload-zone" id="zone_invoice" data-type="invoice">
                                <input type="file" name="invoice_file" id="file_invoice" hidden accept=".pdf,.jpg,.jpeg,.png">
                                <i class="fas fa-file-invoice fa-3x text-primary mb-2"></i>
                                <h6>Commercial Invoice</h6>
                                <small class="text-muted drop-text">Drag & Drop or Click to Upload</small>
                                <div class="file-name text-success fw-bold mt-2" style="display:none;"></div>
                            </div>
                        </div>
                        
                        <!-- Packing List Drop Zone -->
                        <div class="col-md-6">
                            <div class="upload-zone" id="zone_packing" data-type="packing">
                                <input type="file" name="packing_file" id="file_packing" hidden accept=".pdf,.jpg,.jpeg,.png">
                                <i class="fas fa-box fa-3x text-info mb-2"></i>
                                <h6>Packing List</h6>
                                <small class="text-muted drop-text">Drag & Drop or Click to Upload</small>
                                <div class="file-name text-success fw-bold mt-2" style="display:none;"></div>
                            </div>
                        </div>
                        
                        <!-- Bilti / LR Drop Zone -->
                        <div class="col-12">
                            <div class="upload-zone upload-zone-bilti" id="zone_bilti" data-type="bilti">
                                <input type="file" name="bilti_file" id="file_bilti" hidden accept=".pdf,.jpg,.jpeg,.png">
                                <i class="fas fa-truck fa-3x text-secondary mb-2"></i>
                                <h6>Bilti / LR (Lorry Receipt)</h6>
                                <small class="text-muted drop-text">Drag & Drop or Click to Upload</small>
                                <div class="file-name text-success fw-bold mt-2" style="display:none;"></div>
                                <div class="alert alert-info mt-2 mb-0 p-2" style="font-size: 12px;">
                                    <i class="fas fa-info-circle"></i> Bilti upload karne ke baad automatically Dispatch page pe jayenge
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <hr>
                    
                    <button type="submit" class="btn btn-success btn-lg w-100" id="submitUpload">
                        <i class="fas fa-cloud-upload-alt"></i> Upload Selected Documents
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<?php echo $alert_script; ?>

<script>
$(document).ready(function() {
    // Select2 Initialize
    $('.select2').select2({ 
        theme: 'bootstrap-5', 
        placeholder: 'Choose Client...', 
        allowClear: true 
    });

    // DataTables
    if ($.fn.DataTable) {
        if ($.fn.DataTable.isDataTable('#pendingTable')) {
            $('#pendingTable').DataTable().destroy();
        }
        if ($.fn.DataTable.isDataTable('#completedTable')) {
            $('#completedTable').DataTable().destroy();
        }

        $('#pendingTable').DataTable({
            order: [[0, 'desc']],
            paging: true,
            info: true,
            pageLength: 10,
            responsive: true,
            language: { search: "_INPUT_", searchPlaceholder: "Search records..." }
        });

        $('#completedTable').DataTable({
            order: [[0, 'desc']],
            paging: true,
            info: true,
            pageLength: 10,
            responsive: true,
            language: { search: "_INPUT_", searchPlaceholder: "Search records..." }
        });
    }
});

// =====================================================
// DELETE FILE CONFIRMATION
// =====================================================
function confirmDeleteFile(inv_id, col_name, file_path) {
    Swal.fire({
        title: 'Delete this file?',
        text: 'This action cannot be undone!',
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc3545',
        cancelButtonColor: '#6c757d',
        confirmButtonText: 'Yes, Delete!'
    }).then((result) => {
        if (result.isConfirmed) {
            document.getElementById('del_inv_id').value = inv_id;
            document.getElementById('del_col_name').value = col_name;
            document.getElementById('del_file_path').value = file_path;
            document.getElementById('deleteFileForm').submit();
        }
    });
}

// =====================================================
// GENERATE INVOICE MODAL
// =====================================================
function openGenerateInvoiceModal() {
    var modal = new bootstrap.Modal(document.getElementById('generateInvoiceModal'));
    modal.show();
    
    $('#generateInvoiceModal').on('shown.bs.modal', function () {
        $('.select2-modal').select2({
            theme: 'bootstrap-5',
            placeholder: 'Choose Client...',
            allowClear: true,
            dropdownParent: $('#generateInvoiceModal')
        });
    });
}

// =====================================================
// EDIT INVOICE MODAL
// =====================================================
function openEditModal(invoiceId) {
    fetch('invoices.php?get_invoice=1&id=' + invoiceId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                Swal.fire('Error', data.error, 'error');
                return;
            }
            
            document.getElementById('edit_invoice_id').value = data.id;
            document.getElementById('edit_invoice_no').value = data.invoice_no;
            document.getElementById('edit_invoice_date').value = data.date;
            document.getElementById('edit_party_id').value = data.party_id;
            document.getElementById('edit_total_amount').value = data.total_amount;
            document.getElementById('edit_remarks').value = data.remarks || '';
            
            new bootstrap.Modal(document.getElementById('editInvoiceModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load invoice data', 'error');
        });
}

// =====================================================
// IMPORT CSV FUNCTIONS
// =====================================================
const csvDropZone = document.getElementById('csvDropZone');
const csvFileInput = document.getElementById('csv_file');
const csvFileName = document.getElementById('csvFileName');
const importBtn = document.getElementById('importBtn');

csvDropZone.addEventListener('click', () => csvFileInput.click());

csvDropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    csvDropZone.classList.add('dragover');
});

csvDropZone.addEventListener('dragleave', (e) => {
    e.preventDefault();
    csvDropZone.classList.remove('dragover');
});

csvDropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    csvDropZone.classList.remove('dragover');
    
    const files = e.dataTransfer.files;
    if (files.length > 0 && files[0].name.endsWith('.csv')) {
        csvFileInput.files = files;
        showCSVFileName(files[0].name);
    } else {
        Swal.fire('Invalid File', 'Please drop a CSV file only', 'warning');
    }
});

csvFileInput.addEventListener('change', () => {
    if (csvFileInput.files.length > 0) {
        showCSVFileName(csvFileInput.files[0].name);
    }
});

function showCSVFileName(name) {
    csvFileName.innerHTML = '<i class="fas fa-check-circle"></i> ' + name;
    csvFileName.style.display = 'block';
    csvDropZone.classList.add('has-file');
    importBtn.disabled = false;
}

function downloadSampleCSV() {
    const csvContent = `Invoice No,Date,Party Name,Total Amount,Remarks
FSD00001,2026-01-15,ABC Traders,50000,Sample invoice 1
FSD00002,2026-01-16,XYZ Enterprises,75000,Sample invoice 2
FSD00003,2026-01-17,PQR Industries,100000,Sample invoice 3`;
    
    const blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = 'sample_invoices_import.csv';
    link.click();
}

// =====================================================
// DRAG-DROP UPLOAD FUNCTIONS
// =====================================================
function openUploadModal(invId, invoiceNo) {
    document.getElementById('upload_inv_id').value = invId;
    document.getElementById('modal_invoice_no').textContent = '#' + invoiceNo;
    
    ['invoice', 'packing', 'bilti'].forEach(type => {
        const zone = document.getElementById('zone_' + type);
        const input = document.getElementById('file_' + type);
        zone.classList.remove('has-file');
        zone.querySelector('.file-name').style.display = 'none';
        zone.querySelector('.file-name').textContent = '';
        zone.querySelector('.drop-text').style.display = 'block';
        input.value = '';
    });
    
    new bootstrap.Modal(document.getElementById('uploadModal')).show();
}

// Initialize Drag & Drop for document upload zones
['invoice', 'packing', 'bilti'].forEach(type => {
    const zone = document.getElementById('zone_' + type);
    const input = document.getElementById('file_' + type);
    
    zone.addEventListener('click', (e) => {
        if (e.target !== input) input.click();
    });
    
    zone.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.add('dragover');
    });
    
    zone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.remove('dragover');
    });
    
    zone.addEventListener('drop', (e) => {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.remove('dragover');
        
        const files = e.dataTransfer.files;
        if (files.length > 0) {
            input.files = files;
            updateZoneUI(zone, files[0].name);
        }
    });
    
    input.addEventListener('change', () => {
        if (input.files.length > 0) {
            updateZoneUI(zone, input.files[0].name);
        }
    });
});

function updateZoneUI(zone, fileName) {
    zone.classList.add('has-file');
    zone.querySelector('.drop-text').style.display = 'none';
    zone.querySelector('.file-name').style.display = 'block';
    zone.querySelector('.file-name').innerHTML = '<i class="fas fa-check-circle"></i> ' + fileName;
}

// Form validation before submit
document.getElementById('multiUploadForm').addEventListener('submit', function(e) {
    const invoice = document.getElementById('file_invoice').files.length;
    const packing = document.getElementById('file_packing').files.length;
    const bilti = document.getElementById('file_bilti').files.length;
    
    if (invoice === 0 && packing === 0 && bilti === 0) {
        e.preventDefault();
        Swal.fire('No Files Selected', 'Please select at least one file to upload', 'warning');
        return false;
    }
    
    document.getElementById('submitUpload').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    document.getElementById('submitUpload').disabled = true;
});
</script>
