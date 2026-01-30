<?php
/**
 * =====================================================
 * INVOICES.PHP - EDIT & IMPORT/EXPORT FEATURES
 * =====================================================
 * 
 * Yeh file invoices.php mein add karne ke liye hai:
 * 1. Edit Invoice Feature
 * 2. Export to CSV/Excel
 * 3. Import from CSV
 */

// =====================================================
// CHANGE 1: Add these handlers at TOP of invoices.php
// =====================================================

// EDIT INVOICE HANDLER
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
    
    header("Location: invoices.php");
    exit;
}

// EXPORT TO CSV HANDLER
if (isset($_GET['export']) && $_GET['export'] == 'csv') {
    // Set headers for CSV download
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="invoices_export_' . date('Y-m-d_His') . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // CSV Headers
    fputcsv($output, [
        'Sr No',
        'Invoice No',
        'Date',
        'Party Name',
        'Party Phone',
        'Agent Name',
        'Agent Phone',
        'Total Amount',
        'Paid Amount',
        'Outstanding',
        'Status',
        'Remarks'
    ]);
    
    // Fetch all invoices
    $export_query = "SELECT 
        i.*,
        c.party_name,
        c.phone as party_phone,
        c.agent_name,
        c.agent_phone
    FROM invoices i
    LEFT JOIN clients c ON i.party_id = c.id
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

// EXPORT TO EXCEL HANDLER
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="invoices_export_' . date('Y-m-d_His') . '.xls"');
    
    echo '<html xmlns:x="urn:schemas-microsoft-com:office:excel">';
    echo '<head><meta charset="UTF-8"></head>';
    echo '<body>';
    echo '<table border="1">';
    
    // Headers
    echo '<tr style="background:#4CAF50; color:white; font-weight:bold;">';
    echo '<th>Sr No</th>';
    echo '<th>Invoice No</th>';
    echo '<th>Date</th>';
    echo '<th>Party Name</th>';
    echo '<th>Party Phone</th>';
    echo '<th>Agent Name</th>';
    echo '<th>Agent Phone</th>';
    echo '<th>Total Amount</th>';
    echo '<th>Paid Amount</th>';
    echo '<th>Outstanding</th>';
    echo '<th>Status</th>';
    echo '<th>Remarks</th>';
    echo '</tr>';
    
    $export_query = "SELECT 
        i.*,
        c.party_name,
        c.phone as party_phone,
        c.agent_name,
        c.agent_phone
    FROM invoices i
    LEFT JOIN clients c ON i.party_id = c.id
    ORDER BY i.date DESC, i.id DESC";
    
    $result = $conn->query($export_query);
    $sr = 1;
    
    while ($row = $result->fetch_assoc()) {
        $outstanding = ($row['total_amount'] ?? 0) - ($row['paid_amount'] ?? 0);
        $row_class = $outstanding > 0 ? 'style="background:#fff3cd;"' : '';
        
        echo "<tr $row_class>";
        echo '<td>' . $sr++ . '</td>';
        echo '<td>' . htmlspecialchars($row['invoice_no']) . '</td>';
        echo '<td>' . date('d-m-Y', strtotime($row['date'])) . '</td>';
        echo '<td>' . htmlspecialchars($row['party_name']) . '</td>';
        echo '<td>' . $row['party_phone'] . '</td>';
        echo '<td>' . htmlspecialchars($row['agent_name']) . '</td>';
        echo '<td>' . $row['agent_phone'] . '</td>';
        echo '<td style="text-align:right;">' . number_format($row['total_amount'], 2) . '</td>';
        echo '<td style="text-align:right;">' . number_format($row['paid_amount'] ?? 0, 2) . '</td>';
        echo '<td style="text-align:right; color:' . ($outstanding > 0 ? 'red' : 'green') . ';">' . number_format($outstanding, 2) . '</td>';
        echo '<td>' . $row['status'] . '</td>';
        echo '<td>' . htmlspecialchars($row['remarks'] ?? '') . '</td>';
        echo '</tr>';
    }
    
    echo '</table>';
    echo '</body></html>';
    exit;
}

// IMPORT FROM CSV HANDLER
if (isset($_POST['import_csv']) && !empty($_FILES['csv_file']['name'])) {
    $file = $_FILES['csv_file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    
    if ($ext != 'csv') {
        $alert_script = "<script>Swal.fire('Error','Please upload a CSV file only','error');</script>";
    } else {
        $handle = fopen($file['tmp_name'], 'r');
        
        // Skip header row
        $header = fgetcsv($handle);
        
        $imported = 0;
        $skipped = 0;
        $errors = [];
        
        while (($data = fgetcsv($handle)) !== FALSE) {
            // Expected format: Invoice No, Date, Party Name, Total Amount, Remarks
            if (count($data) < 4) {
                $skipped++;
                continue;
            }
            
            $invoice_no = $conn->real_escape_string(trim($data[0]));
            $invoice_date = date('Y-m-d', strtotime(trim($data[1])));
            $party_name = $conn->real_escape_string(trim($data[2]));
            $total_amount = floatval(str_replace(',', '', trim($data[3])));
            $remarks = isset($data[4]) ? $conn->real_escape_string(trim($data[4])) : '';
            
            // Check if invoice already exists
            $check = $conn->query("SELECT id FROM invoices WHERE invoice_no = '$invoice_no'");
            if ($check->num_rows > 0) {
                $skipped++;
                $errors[] = "Invoice #$invoice_no already exists";
                continue;
            }
            
            // Find or create party
            $party_check = $conn->query("SELECT id FROM clients WHERE party_name = '$party_name'");
            if ($party_check->num_rows > 0) {
                $party_id = $party_check->fetch_assoc()['id'];
            } else {
                // Create new party with minimal info
                $conn->query("INSERT INTO clients (party_name) VALUES ('$party_name')");
                $party_id = $conn->insert_id;
            }
            
            // Insert invoice
            $conn->query("INSERT INTO invoices (invoice_no, date, party_id, total_amount, remarks, status) 
                          VALUES ('$invoice_no', '$invoice_date', $party_id, $total_amount, '$remarks', 'Pending')");
            
            if ($conn->affected_rows > 0) {
                $imported++;
            } else {
                $skipped++;
            }
        }
        
        fclose($handle);
        
        $error_msg = count($errors) > 0 ? '<br><small>' . implode('<br>', array_slice($errors, 0, 5)) . '</small>' : '';
        $alert_script = "<script>Swal.fire('Import Complete','Imported: $imported invoices<br>Skipped: $skipped$error_msg','info').then(()=>window.location='invoices.php');</script>";
    }
}

// =====================================================
// CHANGE 2: Add API endpoint for fetching invoice data
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

?>

<!-- =====================================================
     CHANGE 3: Add Export/Import Buttons in Header Area
     Add this near your existing "Add Invoice" button
     ===================================================== -->

<div class="d-flex gap-2 flex-wrap mb-3">
    <!-- Existing Add Invoice Button -->
    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addInvoiceModal">
        <i class="fas fa-plus"></i> Add Invoice
    </button>
    
    <!-- Export Dropdown -->
    <div class="dropdown">
        <button class="btn btn-success dropdown-toggle" type="button" data-bs-toggle="dropdown">
            <i class="fas fa-file-export"></i> Export
        </button>
        <ul class="dropdown-menu">
            <li>
                <a class="dropdown-item" href="invoices.php?export=csv">
                    <i class="fas fa-file-csv text-success"></i> Export as CSV
                </a>
            </li>
            <li>
                <a class="dropdown-item" href="invoices.php?export=excel">
                    <i class="fas fa-file-excel text-success"></i> Export as Excel
                </a>
            </li>
        </ul>
    </div>
    
    <!-- Import Button -->
    <button class="btn btn-info" data-bs-toggle="modal" data-bs-target="#importModal">
        <i class="fas fa-file-import"></i> Import CSV
    </button>
</div>


<!-- =====================================================
     CHANGE 4: Add Edit Button in Invoice Table Row
     Add this in your actions column for each invoice row
     ===================================================== -->

<!-- In your invoice table row, add Edit button -->
<button class="btn btn-sm btn-warning" onclick="openEditModal(<?= $row['id'] ?>)" title="Edit">
    <i class="fas fa-edit"></i>
</button>


<!-- =====================================================
     CHANGE 5: EDIT INVOICE MODAL
     Add this modal HTML before </body>
     ===================================================== -->

<div class="modal fade" id="editInvoiceModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="editInvoiceForm">
                <input type="hidden" name="edit_invoice" value="1">
                <input type="hidden" name="invoice_id" id="edit_invoice_id">
                
                <div class="modal-header bg-warning">
                    <h5 class="modal-title">
                        <i class="fas fa-edit"></i> Edit Invoice
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-bold">Invoice Number</label>
                        <input type="text" name="invoice_no" id="edit_invoice_no" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Invoice Date</label>
                        <input type="date" name="invoice_date" id="edit_invoice_date" class="form-control" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Party</label>
                        <select name="party_id" id="edit_party_id" class="form-select" required>
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
                        <label class="form-label fw-bold">Total Amount (Rs.)</label>
                        <input type="number" name="total_amount" id="edit_total_amount" 
                               class="form-control form-control-lg" step="0.01" min="0" required>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label fw-bold">Remarks</label>
                        <textarea name="remarks" id="edit_remarks" class="form-control" rows="2" 
                                  placeholder="Optional notes..."></textarea>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-save"></i> Update Invoice
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- =====================================================
     CHANGE 6: IMPORT CSV MODAL
     ===================================================== -->

<div class="modal fade" id="importModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="import_csv" value="1">
                
                <div class="modal-header bg-info text-white">
                    <h5 class="modal-title">
                        <i class="fas fa-file-import"></i> Import Invoices from CSV
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle"></i> 
                        <strong>CSV Format Required:</strong>
                        <br>Columns: Invoice No, Date, Party Name, Total Amount, Remarks
                        <br><small>First row should be headers (will be skipped)</small>
                    </div>
                    
                    <!-- Sample CSV Download -->
                    <div class="mb-3">
                        <a href="#" onclick="downloadSampleCSV()" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-download"></i> Download Sample CSV
                        </a>
                    </div>
                    
                    <!-- File Upload Zone -->
                    <div class="upload-zone p-4 text-center border border-2 border-dashed rounded" 
                         id="csvDropZone" style="cursor:pointer;">
                        <input type="file" name="csv_file" id="csv_file" hidden accept=".csv">
                        <i class="fas fa-file-csv fa-3x text-info mb-2"></i>
                        <h6>Drag & Drop CSV File Here</h6>
                        <small class="text-muted">or click to browse</small>
                        <div class="file-name text-success fw-bold mt-2" id="csvFileName" style="display:none;"></div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-info" id="importBtn" disabled>
                        <i class="fas fa-upload"></i> Import
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- =====================================================
     CHANGE 7: JavaScript for Edit & Import functionality
     Add before </body>
     ===================================================== -->

<script>
// ========== EDIT INVOICE FUNCTIONS ==========

function openEditModal(invoiceId) {
    // Fetch invoice data via AJAX
    fetch('invoices.php?get_invoice=1&id=' + invoiceId)
        .then(response => response.json())
        .then(data => {
            if (data.error) {
                Swal.fire('Error', data.error, 'error');
                return;
            }
            
            // Populate form fields
            document.getElementById('edit_invoice_id').value = data.id;
            document.getElementById('edit_invoice_no').value = data.invoice_no;
            document.getElementById('edit_invoice_date').value = data.date;
            document.getElementById('edit_party_id').value = data.party_id;
            document.getElementById('edit_total_amount').value = data.total_amount;
            document.getElementById('edit_remarks').value = data.remarks || '';
            
            // Open modal
            new bootstrap.Modal(document.getElementById('editInvoiceModal')).show();
        })
        .catch(error => {
            console.error('Error:', error);
            Swal.fire('Error', 'Failed to load invoice data', 'error');
        });
}

// ========== IMPORT CSV FUNCTIONS ==========

// CSV Drop Zone
const csvDropZone = document.getElementById('csvDropZone');
const csvFileInput = document.getElementById('csv_file');
const csvFileName = document.getElementById('csvFileName');
const importBtn = document.getElementById('importBtn');

csvDropZone.addEventListener('click', () => csvFileInput.click());

csvDropZone.addEventListener('dragover', (e) => {
    e.preventDefault();
    csvDropZone.classList.add('border-primary', 'bg-light');
});

csvDropZone.addEventListener('dragleave', (e) => {
    e.preventDefault();
    csvDropZone.classList.remove('border-primary', 'bg-light');
});

csvDropZone.addEventListener('drop', (e) => {
    e.preventDefault();
    csvDropZone.classList.remove('border-primary', 'bg-light');
    
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
    csvDropZone.classList.add('border-success');
    importBtn.disabled = false;
}

// Download Sample CSV
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
</script>


<?php
// =====================================================
// SUMMARY: What to add where in invoices.php
// =====================================================
/*
1. TOP OF FILE (after includes):
   - Add Edit Invoice handler (if isset($_POST['edit_invoice']))
   - Add Export CSV handler (if isset($_GET['export']))
   - Add Import CSV handler (if isset($_POST['import_csv']))
   - Add Get Invoice API (if isset($_GET['get_invoice']))

2. HEADER AREA (near Add Invoice button):
   - Add Export dropdown (CSV/Excel options)
   - Add Import CSV button

3. TABLE ROW (in actions column):
   - Add Edit button with onclick="openEditModal(id)"

4. BEFORE </body>:
   - Add Edit Invoice Modal HTML
   - Add Import CSV Modal HTML
   - Add JavaScript functions (openEditModal, CSV handling, downloadSampleCSV)
*/
?>
