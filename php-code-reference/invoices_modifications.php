<?php
/**
 * =====================================================
 * INVOICES.PHP MODIFICATIONS
 * =====================================================
 * 
 * Yeh file aapko batayegi ki invoices.php mein kya changes karne hain.
 * Copy-paste karein relevant sections ko apni original file mein.
 */

// =====================================================
// CHANGE 1: Add this at TOP of invoices.php (after includes)
// Multi-Upload Handler
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


// =====================================================
// CHANGE 2: Popup Reduction
// Replace these alert_script lines with silent redirects
// =====================================================

// ORIGINAL (Line ~29 - Mark Local):
// $alert_script = "<script>Swal.fire('Marked Local','Invoice marked as local delivery','success').then(()=>window.location='invoices.php');</script>";
// REPLACE WITH:
$alert_script = "<script>window.location='invoices.php';</script>";

// ORIGINAL (Line ~45 - Delete File):
// $alert_script = "<script>Swal.fire('Deleted','File deleted successfully','success').then(()=>window.location='invoices.php');</script>";
// REPLACE WITH:
$alert_script = "<script>window.location='invoices.php';</script>";

// ORIGINAL (Line ~85 - Upload Success):
// $alert_script = "<script>Swal.fire('Uploaded','File uploaded successfully','success').then(()=>window.location='invoices.php');</script>";
// REPLACE WITH:
$alert_script = "<script>window.location='invoices.php';</script>";

// ORIGINAL (Line ~106 - Invoice Created):
// $alert_script = "<script>Swal.fire('Created','Invoice #$inv_no created','success').then(()=>window.location='invoices.php');</script>";
// REPLACE WITH:
$alert_script = "<script>window.location='invoices.php';</script>";

// KEEP THESE AS IS (important confirmations):
// - Line ~22: Delete invoice confirmation
// - Line ~75: Bilti upload redirect to dispatch.php


// =====================================================
// CHANGE 3: New Upload Modal HTML
// Replace existing upload modal (Lines 224-249) with this
// =====================================================
?>

<!-- NEW UPLOAD MODAL - Replace old modal -->
<div class="modal fade" id="uploadModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">
                    <i class="fas fa-cloud-upload-alt"></i> Upload Documents
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

<!-- CSS for Drag-Drop Zones - Add to your <head> or CSS file -->
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

<!-- JavaScript for Drag-Drop - Add before </body> -->
<script>
// Open upload modal with invoice ID
function openUploadModal(invId) {
    document.getElementById('upload_inv_id').value = invId;
    
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

<?php
// =====================================================
// CHANGE 4: Update Upload Button in Invoice Table Row
// Find the existing upload button and change it to call openUploadModal()
// =====================================================
?>

<!-- OLD Upload Button (find this in your table) -->
<button class="btn btn-sm btn-info" data-bs-toggle="modal" data-bs-target="#uploadModal" onclick="setUploadId(<?= $row['id'] ?>)">
    <i class="fas fa-upload"></i>
</button>

<!-- NEW Upload Button (replace with this) -->
<button class="btn btn-sm btn-info" onclick="openUploadModal(<?= $row['id'] ?>)">
    <i class="fas fa-upload"></i> Upload
</button>
