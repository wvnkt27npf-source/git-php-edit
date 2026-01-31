<?php
include 'db.php';
include 'header.php';

$alert_script = ""; // Popups ke liye variable

// --- 1. HANDLE API SENDING (UltraMsg Logic with SweetAlert) ---
if (isset($_POST['send_wa'])) {
    $target = $_POST['send_wa']; 
    $inv_id = $_POST['inv_id'];
    $selected_files = $_POST['files'] ?? [];

    if (empty($selected_files)) {
        $alert_script = "<script>Swal.fire('Error', 'Please select at least one file!', 'error');</script>";
    } else {
        // UPDATED: Join with parties and agents tables
        $query = "SELECT i.*, 
                         p.party_name, p.phone as party_phone, p.wa_group_id,
                         a.phone as agent_phone
                  FROM invoices i 
                  LEFT JOIN parties p ON i.party_id = p.id 
                  LEFT JOIN agents a ON p.agent_id = a.id
                  WHERE i.id = $inv_id";
        $inv = $conn->query($query)->fetch_assoc();
        $s = $conn->query("SELECT wa_token, wa_instance_id, display_name FROM settings WHERE id=1")->fetch_assoc();

        $instanceId = trim($s['wa_instance_id']);
        $token = trim($s['wa_token']);
        $company = $s['display_name'] ?? "Logistics Team";
        
        $to = "";
        if ($target == 'party') {
            $to = preg_replace('/[^0-9]/', '', $inv['party_phone']);
            if(strlen($to) == 10) $to = "91" . $to;
        } elseif ($target == 'agent') {
            $to = preg_replace('/[^0-9]/', '', $inv['agent_phone']);
            if(strlen($to) == 10) $to = "91" . $to;
        } elseif ($target == 'group') {
            $to = trim($inv['wa_group_id']);
        }

        if (empty($to)) {
            $alert_script = "<script>Swal.fire('Warning', 'No contact or Group ID found!', 'warning');</script>";
        } else {
            $success_count = 0;
            $error_log = "";
            $update_fields = [];

            foreach ($selected_files as $file_type) {
                $path = ""; $label = ""; $db_field = "";
                if ($file_type == 'invoice') { $path = $inv['invoice_path']; $label = "Invoice"; $db_field = "wa_inv_sent"; }
                if ($file_type == 'packing') { $path = $inv['packing_path']; $label = "Packing List"; $db_field = "wa_pkg_sent"; }
                if ($file_type == 'bilti') { $path = $inv['bilti_path']; $label = "Bilti/LR"; $db_field = "wa_blt_sent"; }

                if (!empty($path) && file_exists($path)) {
                    $file_url = "https://" . $_SERVER['HTTP_HOST'] . "/" . $path;
                    $caption = "Attached: $label for Invoice #" . $inv['invoice_no'] . ".\n\nRegards, $company";

                    $params = [
                        "token" => $token,
                        "to" => $to,
                        "filename" => basename($path),
                        "document" => $file_url,
                        "caption" => $caption
                    ];

                    $ch = curl_init("https://api.ultramsg.com/$instanceId/messages/document");
                    curl_setopt($ch, CURLOPT_POST, 1);
                    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                    $response = curl_exec($ch);
                    $res_data = json_decode($response, true);
                    curl_close($ch);

                    if (isset($res_data['sent']) && $res_data['sent'] == 'true') {
                        $success_count++;
                        $update_fields[] = "$db_field = 1";
                    } else {
                        $error_log .= "$label: " . ($res_data['error'] ?? 'API Error') . " ";
                    }
                }
            }

            if ($success_count > 0) {
                $conn->query("UPDATE invoices SET " . implode(", ", $update_fields) . ", whatsapp_status='Sent' WHERE id=$inv_id");
                $alert_script = "<script>Swal.fire('Sent!', 'Successfully sent $success_count files to " . ucfirst($target) . "!', 'success').then(() => { window.location.href='whatsapp.php'; });</script>";
            } else {
                $alert_script = "<script>Swal.fire('Failed', 'Error: $error_log', 'error');</script>";
            }
        }
    }
}
?>

<div class="container-fluid">
    <div class="card shadow-sm border-0 mb-4">
        <div class="card-header bg-success text-white d-flex justify-content-between align-items-center py-3">
            <h5 class="mb-0 fw-bold"><i class="fab fa-whatsapp me-2"></i> WhatsApp Dispatch Manager</h5>
            <span class="badge bg-white text-success shadow-sm">Ready to Send</span>
        </div>
        <div class="card-body bg-light">
            <div class="table-responsive">
                <table class="table table-hover align-middle bg-white shadow-sm rounded overflow-hidden" id="waMasterTable">
                    <thead class="table-dark">
                        <tr>
                            <th>Invoice #</th>
                            <th>Date</th>
                            <th>Client Details</th>
                            <th class="text-center">Ready Files</th>
                            <th>Status</th>
                            <th class="text-end">Send Documents</th>
                        </tr>
                    </thead>
                    <tbody>
    <?php
    // UPDATED: Join with parties and agents tables
    $res = $conn->query("SELECT i.*, 
                                COALESCE(p.party_name, 'Unknown Client') as party_name, 
                                p.phone, p.wa_group_id,
                                a.agent_name, a.phone as agent_phone
                         FROM invoices i 
                         LEFT JOIN parties p ON i.party_id = p.id 
                         LEFT JOIN agents a ON p.agent_id = a.id
                         ORDER BY CAST(i.invoice_no AS UNSIGNED) DESC");
    while($row = $res->fetch_assoc()):
        $is_sent = ($row['whatsapp_status'] ?? '') == 'Sent';
    ?>

                        <tr>
                            <td><span class="fw-bold text-primary fs-5">#<?php echo $row['invoice_no']; ?></span></td>
                            <td data-sort="<?php echo strtotime($row['date']); ?>">
                                <small class="text-muted fw-bold"><?php echo date('d-m-Y', strtotime($row['date'])); ?></small>
                            </td>
                            <td>
                                <div class="fw-bold text-dark"><?php echo $row['party_name']; ?></div>
                                <div class="d-flex flex-column gap-1 mt-1">
                                    <small class="text-muted"><i class="fas fa-user-tie text-info tiny"></i> Agent: <?php echo $row['agent_name'] ?? 'DIRECT'; ?></small>
                                    <?php if($row['wa_group_id']): ?>
                                        <small class="text-success fw-bold"><i class="fas fa-users tiny"></i> Group Active</small>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="text-center">
                                <?php 
                                $files_list = ['invoice_path'=>'INV', 'packing_path'=>'PKG', 'bilti_path'=>'BLT'];
                                $checks = ['invoice_path'=>'wa_inv_sent', 'packing_path'=>'wa_pkg_sent', 'bilti_path'=>'wa_blt_sent'];
                                foreach($files_list as $key => $lbl) {
                                    if(!empty($row[$key])) {
                                        $sent_icon = $row[$checks[$key]] ? '<i class="fas fa-check-circle text-success ms-1"></i>' : '';
                                        echo "<span class='badge bg-secondary mb-1 me-1'>$lbl $sent_icon</span>";
                                    }
                                }
                                ?>
                            </td>
                            <td>
                                <span class="badge rounded-pill <?php echo $is_sent ? 'bg-success' : 'bg-warning text-dark'; ?>">
                                    <?php echo $row['whatsapp_status']; ?>
                                </span>
                            </td>
                            <td class="text-end">
                                <form method="POST" class="d-flex align-items-center justify-content-end gap-2">
                                    <input type="hidden" name="inv_id" value="<?php echo $row['id']; ?>">
                                    
                                    <div class="btn-group btn-group-sm border rounded bg-light px-2 py-1 shadow-xs">
                                        <?php if(!empty($row['invoice_path'])): ?>
                                            <div class="form-check form-check-inline m-0 me-2">
                                                <input class="form-check-input" type="checkbox" name="files[]" value="invoice" checked id="inv_<?php echo $row['id']; ?>">
                                                <label class="form-check-label tiny fw-bold" for="inv_<?php echo $row['id']; ?>">INV</label>
                                            </div>
                                        <?php endif; ?>
                                        <?php if(!empty($row['packing_path'])): ?>
                                            <div class="form-check form-check-inline m-0 me-2">
                                                <input class="form-check-input" type="checkbox" name="files[]" value="packing" checked id="pkg_<?php echo $row['id']; ?>">
                                                <label class="form-check-label tiny fw-bold" for="pkg_<?php echo $row['id']; ?>">PKG</label>
                                            </div>
                                        <?php endif; ?>
                                        <?php if(!empty($row['bilti_path'])): ?>
                                            <div class="form-check form-check-inline m-0">
                                                <input class="form-check-input" type="checkbox" name="files[]" value="bilti" checked id="blt_<?php echo $row['id']; ?>">
                                                <label class="form-check-label tiny fw-bold" for="blt_<?php echo $row['id']; ?>">BLT</label>
                                            </div>
                                        <?php endif; ?>
                                    </div>

                                    <div class="btn-group shadow-sm">
                                        <?php if(!empty($row['phone'])): ?>
                                            <button type="submit" name="send_wa" value="party" class="btn btn-sm btn-primary" title="Send to Party"><i class="fas fa-user"></i></button>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($row['agent_phone'])): ?>
                                            <button type="submit" name="send_wa" value="agent" class="btn btn-sm btn-info text-white" title="Send to Agent"><i class="fas fa-user-tie"></i></button>
                                        <?php endif; ?>
                                        
                                        <?php if(!empty($row['wa_group_id'])): ?>
                                            <button type="submit" name="send_wa" value="group" class="btn btn-sm btn-success" title="Send to Group"><i class="fas fa-users"></i></button>
                                        <?php endif; ?>
                                    </div>
                                </form>
                            </td>
                        </tr>
                        <?php endwhile; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style>
    .tiny { font-size: 0.75rem; }
    .shadow-xs { box-shadow: 0 .125rem .25rem rgba(0,0,0,.04)!important; }
    #waMasterTable thead th { border-bottom: none; font-size: 0.8rem; text-transform: uppercase; }
</style>

<?php include 'footer.php'; ?>
<?php echo $alert_script; // SweetAlert popup render yahan hoga ?>

<script>
$(document).ready(function() {
    if ($.fn.DataTable.isDataTable('#waMasterTable')) {
        $('#waMasterTable').DataTable().destroy();
    }
    $('#waMasterTable').DataTable({
        "order": [[ 0, "desc" ]],
        "pageLength": 25,
        "language": { "search": "_INPUT_", "searchPlaceholder": "Search Invoices..." },
        "columnDefs": [{ "orderable": false, "targets": [3, 5] }]
    });
});
</script>
