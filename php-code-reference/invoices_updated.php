<?php
include 'db.php';
include 'header.php';

// --- SELECT2 CSS ---
echo '<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />';
echo '<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" />';

$alert_script = ""; // Global variable for alerts

// =====================================================
// NEW: MULTI-UPLOAD HANDLER (Drag-Drop wala)
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
            
            // Validate file type
            if (in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'])) {
                // Create unique filename
                $new_name = $col_name . "_" . $inv_id . "_" . time() . "." . $ext;
                if (!is_dir('uploads')) mkdir('uploads', 0777, true);
                $upload_path = "uploads/" . $new_name;
                
                if (move_uploaded_file($file['tmp_name'], $upload_path)) {
                    // Update database
                    $conn->query("UPDATE invoices SET $col_name = '$upload_path' WHERE id = $inv_id");
                    $uploaded++;
                    
                    if ($col_name == 'bilti_path') {
                        $has_bilti = true;
                    }
                }
            }
        }
    }
    
    // Redirect based on what was uploaded
    if ($has_bilti) {
        header("Location: dispatch.php?id=$inv_id");
        exit;
    } else {
        header("Location: invoices.php");
        exit;
    }
}

// --- 1. HANDLE INVOICE DELETE (KEEP POPUP - Important Confirmation) ---
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

// --- 2. HANDLE LOCAL DELIVERY MARK (POPUP REMOVED - Silent Redirect) ---
if (isset($_POST['mark_local'])) {
    $id = intval($_POST['inv_id']);
    $conn->query("UPDATE invoices SET status = 'Closed' WHERE id=$id");
    // CHANGED: Silent redirect instead of popup
    $alert_script = "<script>window.location='invoices.php';</script>";
}

// --- 3. HANDLE SINGLE FILE DELETE (POPUP REMOVED - Silent Redirect) ---
if (isset($_POST['delete_file'])) {
    $inv_id = intval($_POST['inv_id']);
    $col_name = $_POST['col_name'];
    $file_path = $_POST['file_path'];
    
    // Validate column name to prevent SQL injection
    $allowed_cols = ['invoice_path', 'packing_path', 'bilti_path'];
    if (in_array($col_name, $allowed_cols)) {
        if (file_exists($file_path)) unlink($file_path);
        $conn->query("UPDATE invoices SET $col_name = NULL WHERE id=$inv_id");
        // CHANGED: Silent redirect instead of popup
        $alert_script = "<script>window.location='invoices.php';</script>";
    } else {
        $alert_script = "<script>Swal.fire('Error', 'Invalid column name.', 'error');</script>";
    }
}

// --- 4. HANDLE OLD SINGLE UPLOADS (Kept for backward compatibility) ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['upload_file'])) {
    $inv_id = intval($_POST['inv_id']);
    $col_name = $_POST['file_type']; 
    
    // Validate column name
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
                    // CHANGED: Silent redirect instead of popup
                    $alert_script = "<script>window.location='invoices.php';</script>";
                }
            }
        } else {
            $alert_script = "<script>Swal.fire('Error', 'Invalid file type. Only PDF/JPG allowed.', 'error');</script>";
        }
    }
}

// --- 5. CREATE INVOICE (POPUP REMOVED - Silent Redirect) ---
if (isset($_POST['create_inv'])) {
    $inv = $conn->real_escape_string(trim($_POST['invoice_no']));
    $pid = intval($_POST['party_id']);
    $date = date('Y-m-d');
    
    $check = $conn->query("SELECT id FROM invoices WHERE invoice_no = '$inv'");
    
    if($check->num_rows > 0){
        $alert_script = "<script>Swal.fire('Duplicate Error', 'Invoice Number $inv already exists!', 'warning');</script>";
    } else {
        $conn->query("INSERT INTO invoices (invoice_no, party_id, date, status) VALUES ('$inv', '$pid', '$date', 'Open')");
        // CHANGED: Silent redirect instead of popup
        $alert_script = "<script>window.location='invoices.php';</script>";
    }
}

// --- 6. FETCH & SEPARATE DATA ---
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

<!-- =====================================================
     NEW CSS: Drag-Drop Upload Zones
     ===================================================== -->
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

<div class="row">
    <div class="col-md-4 mb-4">
        <div class="card shadow-sm h-100 border-0">
            <div class="card-header bg-primary text-white fw-bold">
                <i class="fas fa-plus-circle"></i> Generate Invoice
            </div>
            <div class="card-body bg-light">
                <form method="POST">
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Invoice Number</label>
                        <div class="input-group">
                            <span class="input-group-text bg-white"><i class="fas fa-hashtag"></i></span>
                            <input type="text" name="invoice_no" class="form-control fw-bold" required placeholder="e.g. 1001" autocomplete="off">
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label small fw-bold text-muted">Select Client</label>
                        <select name="party_id" class="form-select select2" required>
                            <option value="">Choose Client...</option>
                            <?php 
                            $c = $conn->query("SELECT * FROM clients ORDER BY party_name ASC");
                            while($r=$c->fetch_assoc()) echo "<option value='{$r['id']}'>".htmlspecialchars($r['party_name'])."</option>";
                            ?>
                        </select>
                    </div>
                    <button type="submit" name="create_inv" class="btn btn-primary w-100 fw-bold shadow-sm">
                        Create Invoice <i class="fas fa-arrow-right ms-1"></i>
                    </button>
                </form>
            </div>
        </div>
    </div>

    <div class="col-md-8 mb-4">
        <div class="card shadow-sm h-100 border-warning" style="border-left: 5px solid #ffc107;">
            <div class="card-header bg-warning bg-opacity-10 text-dark fw-bold d-flex justify-content-between align-items-center">
                <span><i class="fas fa-history text-warning me-2"></i> Pending Documentation</span>
                <span class="badge bg-warning text-dark"><?php echo count($pending_invoices); ?> Pending</span>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table id="pendingTable" class="table table-hover align-middle mb-0 datatable" style="width:100%">
                        <thead class="bg-light"><tr><th>Invoice</th><th>Uploaded Docs</th><th class="text-end">Actions</th></tr></thead>
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
                                        <!-- NEW: openUploadModal() function call -->
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
                            <tr><th>ID</th><th>Invoice Details</th><th>Type / Docs</th><th class="text-end">Actions</th></tr>
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
     NEW: DRAG-DROP UPLOAD MODAL
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

// Delete file confirmation
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
// NEW: DRAG-DROP UPLOAD FUNCTIONS
// =====================================================

// Open upload modal with invoice ID
function openUploadModal(invId, invoiceNo) {
    document.getElementById('upload_inv_id').value = invId;
    document.getElementById('modal_invoice_no').textContent = '#' + invoiceNo;
    
    // Reset all zones
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

// Initialize Drag & Drop for all zones
['invoice', 'packing', 'bilti'].forEach(type => {
    const zone = document.getElementById('zone_' + type);
    const input = document.getElementById('file_' + type);
    
    // Click to open file picker
    zone.addEventListener('click', (e) => {
        if (e.target !== input) {
            input.click();
        }
    });
    
    // Drag over
    zone.addEventListener('dragover', (e) => {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.add('dragover');
    });
    
    // Drag leave
    zone.addEventListener('dragleave', (e) => {
        e.preventDefault();
        e.stopPropagation();
        zone.classList.remove('dragover');
    });
    
    // Drop file
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
    
    // File input change
    input.addEventListener('change', () => {
        if (input.files.length > 0) {
            updateZoneUI(zone, input.files[0].name);
        }
    });
});

// Update zone UI when file is selected
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
    
    // Show loading
    document.getElementById('submitUpload').innerHTML = '<i class="fas fa-spinner fa-spin"></i> Uploading...';
    document.getElementById('submitUpload').disabled = true;
});
</script>
