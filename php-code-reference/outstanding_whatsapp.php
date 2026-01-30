<?php
/**
 * Outstanding Debtors - WhatsApp to Agent/Party
 * Logic: Agent ho to Agent ko, DIRECT ho to Party ko
 */

include 'db.php';
include 'header.php';

// Get UltraMsg settings from database
$settings = $conn->query("SELECT * FROM settings LIMIT 1")->fetch_assoc();
$ultramsg_token = $settings['wa_token'] ?? '';
$ultramsg_instance = $settings['wa_instance_id'] ?? '';

// UltraMsg Send Function (with PDF support)
function sendUltraMsgWithDoc($to, $message, $file_url, $token, $instance) {
    $to = preg_replace('/[^0-9]/', '', $to);
    if (strlen($to) == 10) $to = '91' . $to;
    
    // Send document via UltraMsg API
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.ultramsg.com/$instance/messages/document",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => [
            'token' => $token,
            'to' => $to,
            'document' => $file_url,
            'filename' => basename($file_url),
            'caption' => $message
        ]
    ]);
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}

// Handle WhatsApp Send Action
$alert_script = '';
if (isset($_POST['send_whatsapp'])) {
    $inv_id = intval($_POST['inv_id']);
    $send_to = $_POST['send_to'];
    $recipient_type = $_POST['recipient_type'];
    
    // Get invoice details
    $inv = $conn->query("SELECT i.*, c.party_name FROM invoices i JOIN clients c ON i.party_id = c.id WHERE i.id = $inv_id")->fetch_assoc();
    
    if ($inv) {
        $message = "Outstanding Invoice: #" . $inv['invoice_no'] . "\nParty: " . $inv['party_name'];
        
        // Send PDF if exists
        $file_url = '';
        if (!empty($inv['invoice_path'])) {
            $file_url = 'https://' . $_SERVER['HTTP_HOST'] . '/' . $inv['invoice_path'];
        }
        
        $result = sendUltraMsgWithDoc($send_to, $message, $file_url, $ultramsg_token, $ultramsg_instance);
        
        if (isset($result['sent']) && $result['sent'] == 'true') {
            $alert_script = "<script>Swal.fire('Sent!', 'WhatsApp sent to $recipient_type successfully', 'success');</script>";
        } else {
            $alert_script = "<script>Swal.fire('Error', 'Failed to send WhatsApp', 'error');</script>";
        }
    }
}

// Get Outstanding Invoices with Agent info
$query = "SELECT i.*, c.party_name, c.phone as party_phone, 
                 c.agent_name, c.agent_phone
          FROM invoices i
          JOIN clients c ON i.party_id = c.id
          WHERE i.status != 'Closed' AND i.status != 'Paid'
          ORDER BY c.agent_name, c.party_name, i.date DESC";
$result = $conn->query($query);

// Group by Agent
$by_agent = [];
while ($row = $result->fetch_assoc()) {
    $agent = !empty($row['agent_name']) ? $row['agent_name'] : 'DIRECT';
    $by_agent[$agent][] = $row;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Outstanding Debtors - WhatsApp</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .agent-section {
            margin-bottom: 30px;
            border: 1px solid #ddd;
            border-radius: 10px;
            overflow: hidden;
        }
        .agent-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
        }
        .agent-header.direct {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .badge-doc {
            font-size: 10px;
            padding: 3px 6px;
        }
        .btn-whatsapp {
            background: #25D366;
            border: none;
            color: white;
        }
        .btn-whatsapp:hover {
            background: #128C7E;
            color: white;
        }
    </style>
</head>
<body>
    <?php echo $alert_script; ?>
    
    <div class="container-fluid py-4">
        <div class="row mb-4">
            <div class="col-12">
                <div class="card bg-warning text-dark">
                    <div class="card-body">
                        <h4><i class="fab fa-whatsapp"></i> Outstanding Debtors - WhatsApp</h4>
                        <p class="mb-0">Agent ho to Agent ko PDF jayega, DIRECT ho to Party ko jayega</p>
                    </div>
                </div>
            </div>
        </div>
        
        <?php foreach ($by_agent as $agent => $invoices): ?>
            <?php 
            $is_direct = ($agent == 'DIRECT');
            ?>
            
            <div class="agent-section">
                <div class="agent-header <?= $is_direct ? 'direct' : '' ?>">
                    <h5 class="mb-0">
                        <?php if ($is_direct): ?>
                            <i class="fas fa-user"></i> DIRECT PARTIES
                        <?php else: ?>
                            <i class="fas fa-user-tie"></i> Agent: <?= htmlspecialchars($agent) ?>
                        <?php endif; ?>
                    </h5>
                </div>
                
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Invoice #</th>
                                <th>Date</th>
                                <th>Party</th>
                                <th>Documents</th>
                                <th>Status</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($invoices as $inv): ?>
                                <?php
                                // Determine who to send to
                                if (!$is_direct && !empty($inv['agent_phone'])) {
                                    $send_to = $inv['agent_phone'];
                                    $recipient_type = 'Agent';
                                    $btn_label = 'Send to Agent';
                                } else {
                                    $send_to = $inv['party_phone'];
                                    $recipient_type = 'Party';
                                    $btn_label = 'Send to Party';
                                }
                                ?>
                                <tr>
                                    <td><strong>#<?= htmlspecialchars($inv['invoice_no']) ?></strong></td>
                                    <td><?= date('d-M-Y', strtotime($inv['date'])) ?></td>
                                    <td>
                                        <?= htmlspecialchars($inv['party_name']) ?>
                                        <br><small class="text-muted"><?= htmlspecialchars($inv['party_phone']) ?></small>
                                    </td>
                                    <td>
                                        <?php if (!empty($inv['invoice_path'])): ?>
                                            <span class="badge bg-success badge-doc">INV</span>
                                        <?php endif; ?>
                                        <?php if (!empty($inv['packing_path'])): ?>
                                            <span class="badge bg-info badge-doc">PKG</span>
                                        <?php endif; ?>
                                        <?php if (!empty($inv['bilti_path'])): ?>
                                            <span class="badge bg-secondary badge-doc">BLT</span>
                                        <?php endif; ?>
                                        <?php if (empty($inv['invoice_path']) && empty($inv['packing_path']) && empty($inv['bilti_path'])): ?>
                                            <span class="badge bg-danger badge-doc">No Docs</span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge bg-<?= $inv['status'] == 'Open' ? 'warning' : 'info' ?>">
                                            <?= htmlspecialchars($inv['status']) ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if (!empty($send_to)): ?>
                                            <form method="POST" class="d-inline">
                                                <input type="hidden" name="send_whatsapp" value="1">
                                                <input type="hidden" name="inv_id" value="<?= $inv['id'] ?>">
                                                <input type="hidden" name="send_to" value="<?= htmlspecialchars($send_to) ?>">
                                                <input type="hidden" name="recipient_type" value="<?= $recipient_type ?>">
                                                <button type="submit" class="btn btn-whatsapp btn-sm">
                                                    <i class="fab fa-whatsapp"></i> <?= $btn_label ?>
                                                </button>
                                            </form>
                                        <?php else: ?>
                                            <span class="text-muted">No Phone</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>
        
        <?php if (empty($by_agent)): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> No outstanding invoices found!
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
