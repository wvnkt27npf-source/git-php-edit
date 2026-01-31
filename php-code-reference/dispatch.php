<?php
include 'db.php';
include 'header.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;
use PHPMailer\PHPMailer\SMTP;

// --- 1. SETTINGS & CC LOGIC ---
$settings_result = $conn->query("SELECT * FROM settings LIMIT 1");
$settings = $settings_result ? $settings_result->fetch_assoc() : [];
$company = $settings['display_name'] ?? "Logistics Pro";
$sig = nl2br($settings['signature'] ?? "Regards,\nDispatch Team");
$alert_script = ""; 

// --- 2. HELPER: ULTRAMSG SENDING ---
function sendUltraMsg($to, $message, $file_path = null) {
    global $conn;
    $s = $conn->query("SELECT wa_token, wa_instance_id FROM settings LIMIT 1")->fetch_assoc();
    if(empty($s['wa_token']) || empty($s['wa_instance_id'])) return false;

    $instanceId = trim($s['wa_instance_id']);
    $token = trim($s['wa_token']);
    
    // Group ID check (contains '-' or '@')
    if (strpos($to, '-') === false && strpos($to, '@') === false) {
        $to = preg_replace('/[^0-9]/', '', $to);
        if(strlen($to) == 10) $to = "91" . $to;
    }

    $api_url = ($file_path && file_exists($file_path)) 
               ? "https://api.ultramsg.com/$instanceId/messages/document" 
               : "https://api.ultramsg.com/$instanceId/messages/chat";

    $params = ["token" => $token, "to" => $to];
    if ($file_path && file_exists($file_path)) {
        $params["filename"] = basename($file_path);
        $params["document"] = "https://" . $_SERVER['HTTP_HOST'] . "/" . $file_path;
        $params["caption"] = $message;
    } else {
        $params["body"] = $message;
    }

    $ch = curl_init($api_url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($params));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $res = curl_exec($ch);
    curl_close($ch);
    return json_decode($res, true);
}

// --- 3. HELPER: PREMIUM EMAIL GENERATOR ---
function generateEmailBody($party_name, $inv_no, $msg, $sig, $docs, $comp) {
    $theme = "#f37121";
    $rows = "";
    foreach($docs as $d) {
        $rows .= "<tr>
            <td style='padding:15px; border-bottom:1px solid #f0f0f0; font-size:14px; color:#333;'>
                <span style='font-size:18px; margin-right:10px;'>📎</span> ".htmlspecialchars($d)."
            </td>
            <td style='padding:15px; border-bottom:1px solid #f0f0f0; text-align:right;'>
                <span style='background:#fff0e6; color:$theme; padding:5px 12px; border-radius:15px; font-size:11px; font-weight:bold; border:1px solid $theme;'>ATTACHED</span>
            </td>
        </tr>";
    }
    return "
    <div style='background:#f8f9fa; padding:40px 10px; font-family:\"Helvetica Neue\", Helvetica, Arial, sans-serif;'>
        <table width='100%' style='max-width:650px; margin:0 auto; background:#fff; border-radius:12px; overflow:hidden; box-shadow:0 10px 30px rgba(0,0,0,0.05); border:1px solid #eee;'>
            <tr>
                <td style='background:$theme; padding:40px; text-align:center;'>
                    <h1 style='margin:0; color:#fff; font-size:28px; letter-spacing:1px; text-transform:uppercase;'>".htmlspecialchars($comp)."</h1>
                    <p style='color:rgba(255,255,255,0.8); margin-top:10px; font-size:14px;'>Official Dispatch Notification</p>
                </td>
            </tr>
            <tr>
                <td style='padding:40px;'>
                    <h2 style='color:#1a1a1a; font-size:22px; margin-top:0;'>Invoice #".htmlspecialchars($inv_no)." Details</h2>
                    <p style='color:#555; font-size:16px; line-height:1.6;'>Dear <strong>".htmlspecialchars($party_name)."</strong>,</p>
                    <p style='color:#555; font-size:16px; line-height:1.6;'>Greetings! We are pleased to share the shipping documents for your recent order.</p>
                    
                    <div style='background:#fff9f5; border-left:5px solid $theme; padding:25px; margin:25px 0; border-radius:4px; color:#444; font-size:15px; line-height:1.8; border:1px solid #ffe8d9;'>
                        ".nl2br(htmlspecialchars($msg))."
                    </div>

                    <h4 style='color:$theme; font-size:14px; margin-bottom:15px; text-transform:uppercase; letter-spacing:1px;'>Document Checklist</h4>
                    <table width='100%' cellspacing='0' style='border:1px solid #f0f0f0; border-radius:8px; border-collapse: separate;'>
                        $rows
                    </table>

                    <div style='margin-top:40px; padding-top:30px; border-top:1px solid #eee;'>
                        <div style='color:#888; font-size:14px; line-height:1.6;'>$sig</div>
                    </div>
                </td>
            </tr>
            <tr>
                <td style='background:#fafafa; padding:20px; text-align:center; color:#999; font-size:12px; border-top:1px solid #eee;'>
                    This is a dispatch email from ".htmlspecialchars($comp).".
                </td>
            </tr>
        </table>
    </div>";
}

// --- 4. ACTION: SEND DISPATCH (FIXED: SQL Injection Prevention) ---
if (isset($_POST['send_dispatch'])) {
    if (file_exists('PHPMailer/src/PHPMailer.php')) {
        require 'PHPMailer/src/Exception.php'; require 'PHPMailer/src/PHPMailer.php'; require 'PHPMailer/src/SMTP.php';

        $inv_id = intval($_POST['inv_id']); 
        $inv_no = $conn->real_escape_string($_POST['inv_no']);
        $party_name = $conn->real_escape_string($_POST['party_name']); 
        $to_email = filter_var($_POST['to_email'], FILTER_SANITIZE_EMAIL);
        $recipients = $_POST['wa_recipients'] ?? []; 

        $email_files = []; $wa_selected = $_POST['wa_files'] ?? []; $doc_names = [];

        if(isset($_POST['inc_inv'])) { $email_files[] = $_POST['file_inv']; $doc_names[] = "Commercial Invoice"; }
        if(isset($_POST['inc_pack'])) { $email_files[] = $_POST['file_pack']; $doc_names[] = "Packing List"; }
        if(isset($_POST['inc_bilti'])) { $email_files[] = $_POST['file_bilti']; $doc_names[] = "Bilti / LR Copy"; }

        $mail = new PHPMailer(true);
        try {
            $mail->isSMTP(); $mail->Host = $settings['smtp_host'];
            $mail->SMTPAuth = true; $mail->Username = $settings['smtp_email'];
            $mail->Password = $settings['smtp_password'];
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; $mail->Port = $settings['smtp_port'];
            $mail->setFrom($settings['smtp_email'], $company);
            $mail->addAddress($to_email);
            
            if(!empty($_POST['cc_email'])) {
                foreach(explode(',', $_POST['cc_email']) as $cc) if(trim($cc)) $mail->addCC(trim($cc));
            }
            foreach($email_files as $f) if(!empty($f) && file_exists($f)) $mail->addAttachment($f);

            $mail->isHTML(true);
            $mail->Subject = $conn->real_escape_string($_POST['email_subject']);
            $mail->Body = generateEmailBody($party_name, $inv_no, $_POST['message'], $sig, $doc_names, $company);
            
            if($mail->send()) {
                $upd = ["email_status='Sent'", "status='Dispatched'"];
                
                // WhatsApp Loop for Multiple Recipients
                if(!empty($wa_selected) && !empty($recipients)) {
                    foreach($recipients as $wa_to) {
                        foreach($wa_selected as $ftype) {
                            $p = ""; $fld = ""; $lbl = "";
                            if($ftype == 'invoice') { $p = $_POST['file_inv']; $fld = "wa_inv_sent"; $lbl = "Invoice"; }
                            if($ftype == 'packing') { $p = $_POST['file_pack']; $fld = "wa_pkg_sent"; $lbl = "Packing List"; }
                            if($ftype == 'bilti') { $p = $_POST['file_bilti']; $fld = "wa_blt_sent"; $lbl = "Bilti/LR"; }

                            if(!empty($p) && file_exists($p)) {
                                $wa_msg = "🚚 *Dispatch Alert: $lbl*\n\nDear *$party_name*,\nPlease find attached the *$lbl* for Invoice *#$inv_no*.\n\nRegards,\n*$company*";
                                $res = sendUltraMsg($wa_to, $wa_msg, $p);
                                if(isset($res['sent']) && $res['sent'] == 'true') $upd[] = "$fld = 1";
                            }
                        }
                    }
                    $upd[] = "whatsapp_status='Sent'";
                }
                
                $conn->query("UPDATE invoices SET " . implode(", ", array_unique($upd)) . " WHERE id=$inv_id");
                $alert_script = "<script>Swal.fire('Dispatched', 'Documents sent to all selected recipients!', 'success').then(()=>location.href='dispatch.php');</script>";
            }
        } catch (Exception $e) { $alert_script = "<script>Swal.fire('Error', 'Mailer: {$mail->ErrorInfo}', 'error');</script>"; }
    }
}
?>

<style>
    :root { --theme: #f37121; --theme-light: #fff5ed; }
    .theme-bg { background: var(--theme) !important; color: #fff !important; }
    .theme-text { color: var(--theme) !important; }
    .card-active { border-left: 5px solid var(--theme) !important; background: var(--theme-light) !important; }
    .form-check-input:checked { background-color: var(--theme); border-color: var(--theme); }
    .btn-send { background: var(--theme); border: none; color: #fff; padding: 15px 30px; border-radius: 8px; font-weight: bold; width: 100%; transition: 0.3s; font-size: 16px; letter-spacing: 1px; }
    .btn-send:hover { background: #d65a10; transform: translateY(-2px); box-shadow: 0 6px 20px rgba(243, 113, 33, 0.4); }
    .recipient-box { transition: 0.2s; border: 1px solid #eee; cursor: pointer; }
    .recipient-box:hover { border-color: var(--theme); background: var(--theme-light); }
</style>

<div class="container-fluid py-4">
    <div class="row g-3">
        <div class="col-lg-3 col-md-4">
            <div class="card shadow-sm border-0 h-100">
                <div class="card-header bg-white py-3">
                    <input type="text" id="sideSearch" class="form-control form-control-sm" placeholder="Search Invoice...">
                </div>
                <div class="list-group list-group-flush overflow-auto" style="max-height: 80vh;" id="invList">
                    <?php
                    // UPDATED: LEFT JOIN with parties and agents tables
                    $q = $conn->query("SELECT i.id, i.invoice_no, i.email_status, i.whatsapp_status, 
                                              COALESCE(p.party_name, 'Unknown Client') as party_name 
                                       FROM invoices i 
                                       LEFT JOIN parties p ON i.party_id = p.id 
                                       ORDER BY CAST(i.invoice_no AS UNSIGNED) DESC");
                    while($r=$q->fetch_assoc()):
                        $active = (isset($_GET['id']) && $_GET['id']==$r['id']) ? 'card-active' : '';
                    ?>
                    <a href="?id=<?php echo $r['id']; ?>" class="list-group-item list-group-item-action <?php echo $active; ?>">
                        <div class="d-flex justify-content-between">
                            <span class="fw-bold text-dark">#<?php echo htmlspecialchars($r['invoice_no']); ?></span>
                            <?php 
                            $email_sent = ($r['email_status'] ?? '') == 'Sent';
                            $wa_sent = ($r['whatsapp_status'] ?? '') == 'Sent';
                            if($email_sent): ?><small class="text-success">✅</small><?php endif; 
                            if($wa_sent): ?><small class="text-success ms-1">📱</small><?php endif; 
                            ?>
                        </div>
                        <div class="small text-muted text-truncate"><?php echo htmlspecialchars($r['party_name']); ?></div>
                    </a>
                    <?php endwhile; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-9 col-md-8">
            <?php if(isset($_GET['id'])): 
                $id = intval($_GET['id']);
                // UPDATED: LEFT JOIN with parties and agents tables
                $data_result = $conn->query("SELECT i.*, 
                                                    COALESCE(p.party_name, 'Unknown Client') as party_name, 
                                                    p.email, p.phone, p.wa_group_id,
                                                    a.agent_name, a.email as agent_email, a.phone as agent_phone
                                             FROM invoices i 
                                             LEFT JOIN parties p ON i.party_id = p.id 
                                             LEFT JOIN agents a ON p.agent_id = a.id
                                             WHERE i.id=$id");
                $data = $data_result ? $data_result->fetch_assoc() : null;
                
                if($data):
                    $to_email = !empty($data['email']) ? $data['email'] : ($data['agent_email'] ?? '');
                    $cc_list = [];
                    if(!empty($data['email']) && !empty($data['agent_email'])) $cc_list[] = $data['agent_email'];
                    if(!empty($settings['cc_emails'])) $cc_list[] = $settings['cc_emails'];
                    $cc_val = implode(',', $cc_list);
            ?>
            <div class="card shadow-sm border-0">
                <form method="POST" id="dispatchForm">
                    <div class="card-header theme-bg p-4 d-flex justify-content-between align-items-center">
                        <h5 class="mb-0 fw-bold"><i class="fas fa-paper-plane me-2"></i> Dispatch Portal: #<?php echo htmlspecialchars($data['invoice_no']); ?></h5>
                        <span class="badge bg-white text-dark"><?php echo htmlspecialchars($data['party_name']); ?></span>
                    </div>
                    <div class="card-body p-4">
                        <input type="hidden" name="inv_id" value="<?php echo $id; ?>">
                        <input type="hidden" name="inv_no" value="<?php echo htmlspecialchars($data['invoice_no']); ?>">
                        <input type="hidden" name="party_name" value="<?php echo htmlspecialchars($data['party_name']); ?>">
                        <input type="hidden" name="file_inv" value="<?php echo htmlspecialchars($data['invoice_path'] ?? ''); ?>">
                        <input type="hidden" name="file_pack" value="<?php echo htmlspecialchars($data['packing_path'] ?? ''); ?>">
                        <input type="hidden" name="file_bilti" value="<?php echo htmlspecialchars($data['bilti_path'] ?? ''); ?>">

                        <div class="table-responsive mb-4">
                            <table class="table border align-middle bg-light rounded">
                                <thead class="small text-uppercase text-muted">
                                    <tr>
                                        <th class="ps-3">Document</th>
                                        <th class="text-center">Email Attachment</th>
                                        <th class="text-center">WhatsApp File</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $files = [
                                        ['k'=>'inv', 'n'=>'Commercial Invoice', 'p'=>$data['invoice_path'] ?? ''],
                                        ['k'=>'pack', 'n'=>'Detailed Packing List', 'p'=>$data['packing_path'] ?? ''],
                                        ['k'=>'bilti', 'n'=>'Bilti / LR Document', 'p'=>$data['bilti_path'] ?? '']
                                    ];
                                    foreach($files as $f): $ex = !empty($f['p']) && file_exists($f['p']);
                                    ?>
                                    <tr>
                                        <td class="ps-3">
                                            <div class="fw-bold text-dark"><?php echo $f['n']; ?></div>
                                            <?php if($ex): ?><a href="<?php echo htmlspecialchars($f['p']); ?>" target="_blank" class="small theme-text text-decoration-none"><i class="fas fa-external-link-alt me-1"></i> Preview</a><?php endif; ?>
                                        </td>
                                        <td class="text-center"><input type="checkbox" name="inc_<?php echo $f['k']; ?>" class="form-check-input" <?php echo $ex?'checked':'disabled'; ?>></td>
                                        <td class="text-center"><input type="checkbox" name="wa_files[]" value="<?php echo $f['k']=='inv'?'invoice':($f['k']=='pack'?'packing':'bilti'); ?>" class="form-check-input" <?php echo $ex?'checked':'disabled'; ?>></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mb-4">
                            <label class="fw-bold small text-muted text-uppercase mb-3 d-block"><i class="fab fa-whatsapp theme-text"></i> WhatsApp Recipients (Multiple Selection Allowed)</label>
                            <div class="row g-3">
                                <?php if(!empty($data['phone'])): ?>
                                <div class="col-md-4">
                                    <div class="p-3 rounded recipient-box">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="wa_recipients[]" value="<?php echo htmlspecialchars($data['phone']); ?>" id="w1" checked>
                                            <label class="form-check-label d-block" for="w1">
                                                <span class="d-block fw-bold">Primary Party</span>
                                                <small class="text-muted"><?php echo htmlspecialchars($data['phone']); ?></small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                
                                <?php if(!empty($data['agent_phone'])): ?>
                                <div class="col-md-4">
                                    <div class="p-3 rounded recipient-box">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="wa_recipients[]" value="<?php echo htmlspecialchars($data['agent_phone']); ?>" id="w2" checked>
                                            <label class="form-check-label d-block" for="w2">
                                                <span class="d-block fw-bold">Agent: <?php echo htmlspecialchars($data['agent_name'] ?? 'Agent'); ?></span>
                                                <small class="text-muted"><?php echo htmlspecialchars($data['agent_phone']); ?></small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>

                                <?php if(!empty($data['wa_group_id'])): ?>
                                <div class="col-md-4">
                                    <div class="p-3 rounded recipient-box">
                                        <div class="form-check">
                                            <input class="form-check-input" type="checkbox" name="wa_recipients[]" value="<?php echo htmlspecialchars($data['wa_group_id']); ?>" id="w3" checked>
                                            <label class="form-check-label d-block" for="w3">
                                                <span class="d-block fw-bold text-success"><i class="fas fa-users"></i> Company Group</span>
                                                <small class="text-muted">WhatsApp Group</small>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>

                        <hr class="my-4">

                        <div class="row g-4">
                            <div class="col-md-6">
                                <label class="form-label fw-bold"><i class="fas fa-envelope me-1 theme-text"></i> Email To</label>
                                <input type="email" name="to_email" class="form-control" value="<?php echo htmlspecialchars($to_email); ?>" required>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-bold"><i class="fas fa-copy me-1 theme-text"></i> CC (comma separated)</label>
                                <input type="text" name="cc_email" class="form-control" value="<?php echo htmlspecialchars($cc_val); ?>">
                            </div>
                        </div>

                        <div class="mt-4">
                            <label class="form-label fw-bold"><i class="fas fa-heading me-1 theme-text"></i> Email Subject</label>
                            <input type="text" name="email_subject" class="form-control" value="Dispatch Documents: Invoice #<?php echo htmlspecialchars($data['invoice_no']); ?> - <?php echo htmlspecialchars($company); ?>" required>
                        </div>

                        <div class="mt-4">
                            <label class="form-label fw-bold"><i class="fas fa-comment me-1 theme-text"></i> Message Body</label>
                            <textarea name="message" class="form-control" rows="4" placeholder="Add a personalized message...">Your shipment documents for Invoice #<?php echo htmlspecialchars($data['invoice_no']); ?> are ready. Please find the attached documents.</textarea>
                        </div>

                        <div class="mt-4">
                            <button type="submit" name="send_dispatch" class="btn-send">
                                <i class="fas fa-paper-plane me-2"></i> SEND DISPATCH
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <?php else: ?>
                <div class="alert alert-warning">Invoice not found or has no associated client.</div>
            <?php endif; ?>
            <?php else: ?>
            <div class="card shadow-sm border-0 h-100">
                <div class="card-body d-flex align-items-center justify-content-center text-muted" style="min-height:400px;">
                    <div class="text-center">
                        <i class="fas fa-inbox fa-4x mb-3 text-secondary"></i>
                        <h5>Select an Invoice</h5>
                        <p class="small">Choose an invoice from the left panel to dispatch documents</p>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'footer.php'; ?>
<?php echo $alert_script; ?>

<script>
document.getElementById('sideSearch').addEventListener('keyup', function() {
    var filter = this.value.toLowerCase();
    var items = document.querySelectorAll('#invList .list-group-item');
    items.forEach(function(item) {
        var text = item.textContent.toLowerCase();
        item.style.display = text.includes(filter) ? '' : 'none';
    });
});
</script>
