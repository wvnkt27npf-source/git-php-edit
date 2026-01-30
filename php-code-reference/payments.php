<?php
/**
 * =====================================================
 * PAYMENTS REMINDER PAGE
 * =====================================================
 * Party-wise aur Agent-wise outstanding with WhatsApp Reminder
 */

include 'db.php';
include 'header.php';

// Get UltraMsg settings from database
$settings = $conn->query("SELECT * FROM settings LIMIT 1")->fetch_assoc();
$ultramsg_token = $settings['wa_token'] ?? '';
$ultramsg_instance = $settings['wa_instance_id'] ?? '';

// UltraMsg Send Function (Text Only - No PDF for reminders)
// FIXED: Token must be passed as GET parameter in URL
function sendWhatsAppReminder($to, $message, $token, $instance) {
    $to = preg_replace('/[^0-9]/', '', $to);
    if (strlen($to) == 10) $to = '91' . $to;
    
    // Token passed as GET parameter in URL (as required by UltraMsg API)
    $url = "https://api.ultramsg.com/$instance/messages/chat?token=" . urlencode($token);
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'to' => $to,
            'body' => $message
        ]),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ]);
    $response = curl_exec($curl);
    curl_close($curl);
    return json_decode($response, true);
}

// Handle WhatsApp Send Action
$alert_script = '';
if (isset($_POST['send_reminder'])) {
    $client_id = intval($_POST['client_id']);
    $send_to = $_POST['send_to'];
    $recipient_type = $_POST['recipient_type'];
    $total_amount = floatval($_POST['total_amount']);
    $outstanding_amount = floatval($_POST['outstanding_amount'] ?? $total_amount);
    $bills = $_POST['bills'];
    $party_name = $_POST['party_name'];
    
    // Build message with both amounts
    $message = "🔔 *Payment Reminder*\n\n";
    $message .= "Party: *$party_name*\n\n";
    $message .= "📋 Outstanding Bills:\n$bills\n\n";
    $message .= "💵 *Bill Amount: ₹" . number_format($total_amount, 2) . "*\n";
    $message .= "💰 *Outstanding: ₹" . number_format($outstanding_amount, 2) . "*\n\n";
    $message .= "Please arrange payment at earliest.\nThank you! 🙏";
    
    $result = sendWhatsAppReminder($send_to, $message, $ultramsg_token, $ultramsg_instance);
    
    if (isset($result['sent']) && $result['sent'] == 'true') {
        $alert_script = "<script>Swal.fire('Sent!', 'Reminder sent to $recipient_type successfully', 'success');</script>";
    } else {
        $error_msg = $result['error'] ?? 'Unknown error';
        $alert_script = "<script>Swal.fire('Error', 'Failed to send: $error_msg', 'error');</script>";
    }
}

// Handle Agent Bulk Send
if (isset($_POST['send_agent_reminder'])) {
    $agent_phone = $_POST['agent_phone'];
    $agent_name = $_POST['agent_name'];
    $total_amount = floatval($_POST['total_amount']);
    $parties_list = $_POST['parties_list'];
    
    $message = "🔔 *Payment Collection Reminder*\n\n";
    $message .= "Agent: *$agent_name*\n\n";
    $message .= "📋 Outstanding Parties:\n$parties_list\n\n";
    $message .= "💰 *Total Collection: ₹" . number_format($total_amount, 2) . "*\n\n";
    $message .= "Please collect payments.\nThank you! 🙏";
    
    $result = sendWhatsAppReminder($agent_phone, $message, $ultramsg_token, $ultramsg_instance);
    
    if (isset($result['sent']) && $result['sent'] == 'true') {
        $alert_script = "<script>Swal.fire('Sent!', 'Reminder sent to Agent $agent_name successfully', 'success');</script>";
    } else {
        $error_msg = $result['error'] ?? 'Unknown error';
        $alert_script = "<script>Swal.fire('Error', 'Failed to send: $error_msg', 'error');</script>";
    }
}

// Get current view mode
$view_mode = $_GET['view'] ?? 'party';

// =====================================================
// PARTY-WISE QUERY
// =====================================================
// Query with both Bill Amount and Total Amount
// total_amount = Sum of all invoice amounts for party
// outstanding_amount = Same as total for unpaid invoices (can be modified if partial payments exist)
$party_query = "SELECT 
    c.id as client_id,
    c.party_name,
    c.phone as party_phone,
    c.agent_name,
    c.agent_phone,
    GROUP_CONCAT(DISTINCT i.invoice_no ORDER BY i.date DESC SEPARATOR ', ') as bill_numbers,
    GROUP_CONCAT(DISTINCT CONCAT('#', i.invoice_no, ' - ₹', FORMAT(COALESCE(i.total_amount, 0), 2)) ORDER BY i.date DESC SEPARATOR '\n') as bill_details,
    SUM(COALESCE(i.total_amount, 0)) as total_amount,
    SUM(COALESCE(i.total_amount, 0) - COALESCE(i.paid_amount, 0)) as outstanding_amount,
    COUNT(i.id) as invoice_count
FROM clients c
JOIN invoices i ON c.id = i.party_id
WHERE i.status NOT IN ('Closed', 'Paid')
GROUP BY c.id
HAVING outstanding_amount > 0
ORDER BY outstanding_amount DESC";

$party_result = $conn->query($party_query);
$party_data = [];
$grand_total = 0;
$grand_total_amount = 0;
$grand_outstanding = 0;
while ($row = $party_result->fetch_assoc()) {
    $party_data[] = $row;
    $grand_total_amount += $row['total_amount'];
    $grand_outstanding += $row['outstanding_amount'];
}

// =====================================================
// AGENT-WISE GROUPING
// =====================================================
$agent_data = [];
foreach ($party_data as $party) {
    $agent = !empty($party['agent_name']) ? $party['agent_name'] : 'DIRECT';
    $agent_phone = !empty($party['agent_phone']) ? $party['agent_phone'] : '';
    
    if (!isset($agent_data[$agent])) {
        $agent_data[$agent] = [
            'agent_name' => $agent,
            'agent_phone' => $agent_phone,
            'parties' => [],
            'total_amount' => 0,
            'outstanding_amount' => 0
        ];
    }
    $agent_data[$agent]['parties'][] = $party;
    $agent_data[$agent]['total_amount'] += $party['total_amount'];
    $agent_data[$agent]['outstanding_amount'] += $party['outstanding_amount'];
}

// Sort by outstanding descending
uasort($agent_data, function($a, $b) {
    return $b['outstanding_amount'] <=> $a['outstanding_amount'];
});
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments Reminder</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .view-toggle .btn {
            border-radius: 20px;
            padding: 8px 25px;
            font-weight: 600;
        }
        .view-toggle .btn.active {
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        .party-card {
            border-left: 4px solid #667eea;
            transition: all 0.3s ease;
        }
        .party-card:hover {
            transform: translateX(5px);
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .agent-section {
            margin-bottom: 30px;
            border-radius: 15px;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0,0,0,0.1);
        }
        .agent-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
        }
        .agent-header.direct {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
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
        .total-banner {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .bill-list {
            font-size: 12px;
            white-space: pre-line;
            color: #666;
        }
    </style>
</head>
<body>
    <?php echo $alert_script; ?>
    
    <div class="container-fluid py-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-md-6">
                <h3><i class="fas fa-money-bill-wave text-success"></i> Payments Reminder</h3>
                <p class="text-muted mb-0">Party-wise aur Agent-wise outstanding dekhein aur WhatsApp reminder bhejein</p>
            </div>
            <div class="col-md-6 text-end">
                <!-- View Toggle Buttons -->
                <div class="view-toggle btn-group">
                    <a href="?view=party" class="btn btn-outline-primary <?= $view_mode == 'party' ? 'active' : '' ?>">
                        <i class="fas fa-user"></i> Party-wise
                    </a>
                    <a href="?view=agent" class="btn btn-outline-primary <?= $view_mode == 'agent' ? 'active' : '' ?>">
                        <i class="fas fa-user-tie"></i> Agent-wise
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Total Banner with Both Amounts -->
        <div class="total-banner">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <h5 class="mb-0"><i class="fas fa-file-invoice-dollar"></i> Total Bill Amount</h5>
                    <h3 class="mb-0">₹<?= number_format($grand_total_amount, 2) ?></h3>
                </div>
                <div class="col-md-4">
                    <h5 class="mb-0"><i class="fas fa-exclamation-circle"></i> Outstanding Amount</h5>
                    <h3 class="mb-0 text-warning">₹<?= number_format($grand_outstanding, 2) ?></h3>
                </div>
                <div class="col-md-4 text-end">
                    <small><?= count($party_data) ?> parties with pending payments</small>
                </div>
            </div>
        </div>
        
        <?php if ($view_mode == 'party'): ?>
        <!-- =====================================================
             PARTY-WISE VIEW
             ===================================================== -->
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <i class="fas fa-users"></i> Party-wise Outstanding
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th>Party Name</th>
                                <th>Phone</th>
                                <th>Agent</th>
                                <th>Bills</th>
                                <th class="text-end">Bill Amount</th>
                                <th class="text-end">Outstanding</th>
                                <th class="text-center">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($party_data as $party): ?>
                            <?php
                                // Determine who to send to
                                if (!empty($party['agent_phone']) && $party['agent_name'] != 'DIRECT') {
                                    $send_to = $party['agent_phone'];
                                    $recipient_type = 'Agent';
                                } else {
                                    $send_to = $party['party_phone'];
                                    $recipient_type = 'Party';
                                }
                            ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($party['party_name']) ?></strong>
                                </td>
                                <td>
                                    <small class="text-muted"><?= htmlspecialchars($party['party_phone']) ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($party['agent_name'])): ?>
                                        <span class="badge bg-info"><?= htmlspecialchars($party['agent_name']) ?></span>
                                        <br><small class="text-muted"><?= htmlspecialchars($party['agent_phone']) ?></small>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">DIRECT</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge bg-warning text-dark"><?= $party['invoice_count'] ?> bills</span>
                                    <br><small class="text-muted"><?= htmlspecialchars($party['bill_numbers']) ?></small>
                                </td>
                                <td class="text-end">
                                    <strong class="text-primary">₹<?= number_format($party['total_amount'], 2) ?></strong>
                                </td>
                                <td class="text-end">
                                    <strong class="text-danger fs-5">₹<?= number_format($party['outstanding_amount'], 2) ?></strong>
                                </td>
                                <td class="text-center">
                                    <?php if (!empty($send_to)): ?>
                                    <form method="POST" class="d-inline">
                                        <input type="hidden" name="send_reminder" value="1">
                                        <input type="hidden" name="client_id" value="<?= $party['client_id'] ?>">
                                        <input type="hidden" name="send_to" value="<?= htmlspecialchars($send_to) ?>">
                                        <input type="hidden" name="recipient_type" value="<?= $recipient_type ?>">
                                        <input type="hidden" name="total_amount" value="<?= $party['total_amount'] ?>">
                                        <input type="hidden" name="outstanding_amount" value="<?= $party['outstanding_amount'] ?>">
                                        <input type="hidden" name="party_name" value="<?= htmlspecialchars($party['party_name']) ?>">
                                        <input type="hidden" name="bills" value="<?= htmlspecialchars($party['bill_details']) ?>">
                                        <button type="submit" class="btn btn-whatsapp btn-sm">
                                            <i class="fab fa-whatsapp"></i> Send to <?= $recipient_type ?>
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
        </div>
        
        <?php else: ?>
        <!-- =====================================================
             AGENT-WISE VIEW
             ===================================================== -->
        <?php foreach ($agent_data as $agent): ?>
        <?php $is_direct = ($agent['agent_name'] == 'DIRECT'); ?>
        <div class="agent-section">
            <div class="agent-header <?= $is_direct ? 'direct' : '' ?>">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <h5 class="mb-0">
                            <?php if ($is_direct): ?>
                                <i class="fas fa-user"></i> DIRECT PARTIES
                            <?php else: ?>
                                <i class="fas fa-user-tie"></i> Agent: <?= htmlspecialchars($agent['agent_name']) ?>
                                <small class="ms-2">(<?= htmlspecialchars($agent['agent_phone']) ?>)</small>
                            <?php endif; ?>
                        </h5>
                        <small><?= count($agent['parties']) ?> parties | Bill: ₹<?= number_format($agent['total_amount'], 2) ?> | Outstanding: ₹<?= number_format($agent['outstanding_amount'], 2) ?></small>
                    </div>
                    <div>
                        <?php if (!$is_direct && !empty($agent['agent_phone'])): ?>
                        <?php
                            // Build parties list for agent message
                            $parties_list = '';
                            foreach ($agent['parties'] as $p) {
                                $parties_list .= "• " . $p['party_name'] . " - Outstanding: ₹" . number_format($p['outstanding_amount'], 2) . " (Bill: ₹" . number_format($p['total_amount'], 2) . ")\n";
                            }
                        ?>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="send_agent_reminder" value="1">
                            <input type="hidden" name="agent_phone" value="<?= htmlspecialchars($agent['agent_phone']) ?>">
                            <input type="hidden" name="agent_name" value="<?= htmlspecialchars($agent['agent_name']) ?>">
                            <input type="hidden" name="total_amount" value="<?= $agent['outstanding_amount'] ?>">
                            <input type="hidden" name="parties_list" value="<?= htmlspecialchars($parties_list) ?>">
                            <button type="submit" class="btn btn-light btn-sm">
                                <i class="fab fa-whatsapp text-success"></i> Send All to Agent
                            </button>
                        </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="card-body bg-white p-3">
                <?php foreach ($agent['parties'] as $party): ?>
                <div class="party-card card mb-2">
                    <div class="card-body py-2">
                        <div class="row align-items-center">
                            <div class="col-md-4">
                                <strong><?= htmlspecialchars($party['party_name']) ?></strong>
                                <br><small class="text-muted"><?= htmlspecialchars($party['party_phone']) ?></small>
                            </div>
                            <div class="col-md-2">
                                <span class="badge bg-warning text-dark"><?= $party['invoice_count'] ?> bills</span>
                                <br><small class="text-muted"><?= htmlspecialchars($party['bill_numbers']) ?></small>
                            </div>
                            <div class="col-md-2 text-end">
                                <small class="text-muted">Bill:</small><br>
                                <strong class="text-primary">₹<?= number_format($party['total_amount'], 2) ?></strong>
                            </div>
                            <div class="col-md-2 text-end">
                                <small class="text-muted">Outstanding:</small><br>
                                <strong class="text-danger">₹<?= number_format($party['outstanding_amount'], 2) ?></strong>
                            </div>
                            <div class="col-md-2 text-end">
                                <?php if (!empty($party['party_phone'])): ?>
                                <form method="POST" class="d-inline">
                                    <input type="hidden" name="send_reminder" value="1">
                                    <input type="hidden" name="client_id" value="<?= $party['client_id'] ?>">
                                    <input type="hidden" name="send_to" value="<?= htmlspecialchars($party['party_phone']) ?>">
                                    <input type="hidden" name="recipient_type" value="Party">
                                    <input type="hidden" name="total_amount" value="<?= $party['total_outstanding'] ?>">
                                    <input type="hidden" name="party_name" value="<?= htmlspecialchars($party['party_name']) ?>">
                                    <input type="hidden" name="bills" value="<?= htmlspecialchars($party['bill_details']) ?>">
                                    <button type="submit" class="btn btn-whatsapp btn-sm">
                                        <i class="fab fa-whatsapp"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
        
        <?php if (empty($party_data)): ?>
            <div class="alert alert-success text-center py-5">
                <i class="fas fa-check-circle fa-3x mb-3"></i>
                <h4>No Outstanding Payments!</h4>
                <p class="mb-0">All invoices are either Closed or Paid.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
