<?php
/**
 * =====================================================
 * PAYMENTS MANAGEMENT - FULLY FEATURED
 * =====================================================
 * 
 * Features:
 * - Bill-wise Outstanding with Mark Payment Received
 * - Payment History tracking
 * - Today's Collection summary
 * - WhatsApp Reminders (Party/Agent wise)
 * - Search and Filter options
 * 
 * Database Requirements:
 * 1. ALTER TABLE invoices ADD COLUMN paid_amount DECIMAL(12,2) DEFAULT 0 AFTER total_amount;
 * 2. CREATE TABLE payment_receipts (
 *        id INT AUTO_INCREMENT PRIMARY KEY,
 *        invoice_id INT NOT NULL,
 *        amount DECIMAL(12,2) NOT NULL,
 *        received_date DATE NOT NULL,
 *        payment_mode VARCHAR(50) DEFAULT 'Cash',
 *        remarks TEXT,
 *        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
 *        FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
 *    );
 */

include 'db.php';
include 'header.php';

// Get UltraMsg settings from database
$settings = $conn->query("SELECT * FROM settings LIMIT 1")->fetch_assoc();
$ultramsg_token = $settings['wa_token'] ?? '';
$ultramsg_instance = $settings['wa_instance_id'] ?? '';

// =====================================================
// WHATSAPP SEND FUNCTION
// =====================================================
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

// =====================================================
// HANDLE MARK PAYMENT RECEIVED
// =====================================================
$alert_script = '';
if (isset($_POST['mark_payment'])) {
    $invoice_id = intval($_POST['invoice_id']);
    $amount = floatval($_POST['amount_received']);
    $payment_mode = $conn->real_escape_string($_POST['payment_mode']);
    $received_date = $_POST['received_date'];
    $remarks = $conn->real_escape_string($_POST['remarks'] ?? '');
    
    // Insert into payment_receipts
    $conn->query("INSERT INTO payment_receipts (invoice_id, amount, received_date, payment_mode, remarks) 
                  VALUES ($invoice_id, $amount, '$received_date', '$payment_mode', '$remarks')");
    
    // Update paid_amount in invoices
    $conn->query("UPDATE invoices SET paid_amount = paid_amount + $amount WHERE id = $invoice_id");
    
    // Check if fully paid - update status
    $check = $conn->query("SELECT total_amount, paid_amount FROM invoices WHERE id = $invoice_id")->fetch_assoc();
    if ($check && $check['paid_amount'] >= $check['total_amount']) {
        $conn->query("UPDATE invoices SET status = 'Paid' WHERE id = $invoice_id");
    }
    
    // Silent redirect (no popup as per user preference)
    header("Location: payments.php");
    exit;
}

// =====================================================
// HANDLE WHATSAPP REMINDERS
// =====================================================
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

// =====================================================
// FETCH DATA - Outstanding Invoices (Bill-wise)
// =====================================================
$search_query = $_GET['search'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';

// Build WHERE clause for filters
$where_conditions = ["i.status NOT IN ('Closed', 'Paid')", "(COALESCE(i.total_amount, 0) - COALESCE(i.paid_amount, 0)) > 0"];

if (!empty($search_query)) {
    $search_escaped = $conn->real_escape_string($search_query);
    $where_conditions[] = "(i.invoice_no LIKE '%$search_escaped%' OR c.party_name LIKE '%$search_escaped%' OR c.agent_name LIKE '%$search_escaped%')";
}
if (!empty($date_from)) {
    $where_conditions[] = "i.date >= '$date_from'";
}
if (!empty($date_to)) {
    $where_conditions[] = "i.date <= '$date_to'";
}

$where_clause = implode(' AND ', $where_conditions);

// Outstanding Invoices Query (Bill-wise)
$outstanding_query = "SELECT 
    i.id as invoice_id,
    i.invoice_no,
    i.date,
    i.total_amount,
    COALESCE(i.paid_amount, 0) as paid_amount,
    (COALESCE(i.total_amount, 0) - COALESCE(i.paid_amount, 0)) as outstanding,
    c.id as client_id,
    c.party_name,
    c.phone as party_phone,
    c.agent_name,
    c.agent_phone
FROM invoices i
LEFT JOIN clients c ON i.party_id = c.id
WHERE $where_clause
ORDER BY i.date DESC";

$outstanding_result = $conn->query($outstanding_query);

// Calculate totals
$total_bill = 0;
$total_outstanding = 0;
$outstanding_invoices = [];

if ($outstanding_result && $outstanding_result->num_rows > 0) {
    while ($row = $outstanding_result->fetch_assoc()) {
        $outstanding_invoices[] = $row;
        $total_bill += $row['total_amount'];
        $total_outstanding += $row['outstanding'];
    }
}

// =====================================================
// PAYMENT HISTORY
// =====================================================
$history_query = "SELECT 
    pr.*,
    i.invoice_no,
    c.party_name
FROM payment_receipts pr
JOIN invoices i ON pr.invoice_id = i.id
LEFT JOIN clients c ON i.party_id = c.id
ORDER BY pr.received_date DESC, pr.id DESC
LIMIT 100";
$history_result = $conn->query($history_query);

// =====================================================
// TODAY'S COLLECTION
// =====================================================
$today_query = "SELECT 
    COALESCE(SUM(pr.amount), 0) as total_collected,
    COUNT(pr.id) as receipt_count
FROM payment_receipts pr
WHERE pr.received_date = CURDATE()";
$today_result = $conn->query($today_query);
$today_data = $today_result ? $today_result->fetch_assoc() : ['total_collected' => 0, 'receipt_count' => 0];

// Today's detailed receipts
$today_detail_query = "SELECT 
    pr.*,
    i.invoice_no,
    c.party_name
FROM payment_receipts pr
JOIN invoices i ON pr.invoice_id = i.id
LEFT JOIN clients c ON i.party_id = c.id
WHERE pr.received_date = CURDATE()
ORDER BY pr.id DESC";
$today_detail_result = $conn->query($today_detail_query);

// =====================================================
// GROUP BY AGENT FOR WHATSAPP
// =====================================================
$agent_grouped = [];
$party_direct = [];

foreach ($outstanding_invoices as $inv) {
    if (!empty($inv['agent_name']) && $inv['agent_name'] != 'DIRECT') {
        $agent_key = $inv['agent_name'] . '|' . $inv['agent_phone'];
        if (!isset($agent_grouped[$agent_key])) {
            $agent_grouped[$agent_key] = [
                'agent_name' => $inv['agent_name'],
                'agent_phone' => $inv['agent_phone'],
                'invoices' => [],
                'total_bill' => 0,
                'total_outstanding' => 0
            ];
        }
        $agent_grouped[$agent_key]['invoices'][] = $inv;
        $agent_grouped[$agent_key]['total_bill'] += $inv['total_amount'];
        $agent_grouped[$agent_key]['total_outstanding'] += $inv['outstanding'];
    } else {
        // Direct party
        $party_key = $inv['party_name'] . '|' . $inv['party_phone'];
        if (!isset($party_direct[$party_key])) {
            $party_direct[$party_key] = [
                'party_name' => $inv['party_name'],
                'party_phone' => $inv['party_phone'],
                'invoices' => [],
                'total_bill' => 0,
                'total_outstanding' => 0
            ];
        }
        $party_direct[$party_key]['invoices'][] = $inv;
        $party_direct[$party_key]['total_bill'] += $inv['total_amount'];
        $party_direct[$party_key]['total_outstanding'] += $inv['outstanding'];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Payments Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <style>
        .summary-card {
            border-left: 4px solid;
            transition: transform 0.2s;
        }
        .summary-card:hover {
            transform: translateY(-2px);
        }
        .summary-card.total { border-left-color: #0d6efd; }
        .summary-card.outstanding { border-left-color: #dc3545; }
        .summary-card.today { border-left-color: #198754; }
        
        .badge-partial {
            background: linear-gradient(135deg, #ffc107, #fd7e14);
            color: #000;
        }
        
        .nav-tabs .nav-link {
            border: none;
            color: #6c757d;
            font-weight: 500;
            padding: 12px 20px;
        }
        .nav-tabs .nav-link.active {
            color: #0d6efd;
            border-bottom: 3px solid #0d6efd;
            background: transparent;
        }
        .nav-tabs .nav-link:hover:not(.active) {
            color: #495057;
            border-bottom: 3px solid #dee2e6;
        }
        
        .table-outstanding tr:hover {
            background-color: #f8f9fa;
        }
        
        .btn-mark-paid {
            background: linear-gradient(135deg, #198754, #20c997);
            border: none;
            color: white;
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            transition: all 0.3s;
        }
        .btn-mark-paid:hover {
            transform: scale(1.05);
            box-shadow: 0 4px 12px rgba(25, 135, 84, 0.4);
            color: white;
        }
        
        .filter-section {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .whatsapp-card {
            border: 1px solid #25D366;
            border-radius: 10px;
            margin-bottom: 15px;
        }
        .whatsapp-card .card-header {
            background: linear-gradient(135deg, #25D366, #128C7E);
            color: white;
            border-radius: 9px 9px 0 0;
        }
        
        .payment-mode-badge {
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        .payment-mode-badge.cash { background: #d4edda; color: #155724; }
        .payment-mode-badge.upi { background: #cce5ff; color: #004085; }
        .payment-mode-badge.bank { background: #fff3cd; color: #856404; }
        .payment-mode-badge.cheque { background: #f8d7da; color: #721c24; }
        .payment-mode-badge.other { background: #e2e3e5; color: #383d41; }
        
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
        <!-- Page Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2 class="mb-0"><i class="fas fa-money-bill-wave text-success"></i> Payments Management</h2>
                <small class="text-muted">Bill-wise payment tracking, history & WhatsApp reminders</small>
            </div>
            <div>
                <span class="badge bg-light text-dark fs-6">
                    <i class="fas fa-calendar"></i> <?php echo date('d M Y'); ?>
                </span>
            </div>
        </div>

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card summary-card total">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted mb-1">Total Bill Amount</h6>
                                <h3 class="mb-0 text-primary">₹<?php echo number_format($total_bill, 2); ?></h3>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-file-invoice fa-2x text-primary opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card summary-card outstanding">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted mb-1">Outstanding Balance</h6>
                                <h3 class="mb-0 text-danger">₹<?php echo number_format($total_outstanding, 2); ?></h3>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-exclamation-triangle fa-2x text-danger opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card summary-card today">
                    <div class="card-body">
                        <div class="d-flex justify-content-between">
                            <div>
                                <h6 class="text-muted mb-1">Today's Collection</h6>
                                <h3 class="mb-0 text-success">₹<?php echo number_format($today_data['total_collected'], 2); ?></h3>
                                <small class="text-muted"><?php echo $today_data['receipt_count']; ?> receipts</small>
                            </div>
                            <div class="align-self-center">
                                <i class="fas fa-hand-holding-usd fa-2x text-success opacity-50"></i>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Tab Navigation -->
        <ul class="nav nav-tabs mb-4" id="paymentTabs" role="tablist">
            <li class="nav-item">
                <a class="nav-link active" id="outstanding-tab" data-bs-toggle="tab" href="#outstanding" role="tab">
                    <i class="fas fa-exclamation-circle"></i> Outstanding Bills
                    <span class="badge bg-danger ms-1"><?php echo count($outstanding_invoices); ?></span>
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="history-tab" data-bs-toggle="tab" href="#history" role="tab">
                    <i class="fas fa-history"></i> Payment History
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="today-tab" data-bs-toggle="tab" href="#today" role="tab">
                    <i class="fas fa-calendar-day"></i> Today's Collection
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" id="whatsapp-tab" data-bs-toggle="tab" href="#whatsapp" role="tab">
                    <i class="fab fa-whatsapp"></i> WhatsApp Reminders
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content" id="paymentTabsContent">
            
            <!-- TAB 1: Outstanding Bills -->
            <div class="tab-pane fade show active" id="outstanding" role="tabpanel">
                <!-- Filters -->
                <div class="filter-section">
                    <form method="GET" class="row g-3 align-items-end">
                        <div class="col-md-4">
                            <label class="form-label"><i class="fas fa-search"></i> Search</label>
                            <input type="text" name="search" class="form-control" 
                                   placeholder="Invoice No, Party, Agent..." 
                                   value="<?php echo htmlspecialchars($search_query); ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">From Date</label>
                            <input type="date" name="date_from" class="form-control" value="<?php echo $date_from; ?>">
                        </div>
                        <div class="col-md-2">
                            <label class="form-label">To Date</label>
                            <input type="date" name="date_to" class="form-control" value="<?php echo $date_to; ?>">
                        </div>
                        <div class="col-md-2">
                            <button type="submit" class="btn btn-primary w-100">
                                <i class="fas fa-filter"></i> Filter
                            </button>
                        </div>
                        <div class="col-md-2">
                            <a href="payments.php" class="btn btn-outline-secondary w-100">
                                <i class="fas fa-times"></i> Clear
                            </a>
                        </div>
                    </form>
                </div>

                <!-- Outstanding Table -->
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-outstanding table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Invoice</th>
                                        <th>Date</th>
                                        <th>Party</th>
                                        <th>Agent</th>
                                        <th class="text-end">Bill Amt</th>
                                        <th class="text-end">Paid</th>
                                        <th class="text-end">Outstanding</th>
                                        <th class="text-center">Action</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (count($outstanding_invoices) > 0): ?>
                                        <?php foreach ($outstanding_invoices as $inv): ?>
                                            <tr>
                                                <td>
                                                    <strong class="text-primary">#<?php echo htmlspecialchars($inv['invoice_no']); ?></strong>
                                                    <?php if ($inv['paid_amount'] > 0): ?>
                                                        <span class="badge badge-partial ms-1">Partial</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?php echo date('d M Y', strtotime($inv['date'])); ?></td>
                                                <td>
                                                    <?php echo htmlspecialchars($inv['party_name']); ?>
                                                    <?php if (!empty($inv['party_phone'])): ?>
                                                        <br><small class="text-muted"><i class="fas fa-phone"></i> <?php echo $inv['party_phone']; ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($inv['agent_name'] ?? 'DIRECT'); ?>
                                                </td>
                                                <td class="text-end">₹<?php echo number_format($inv['total_amount'], 2); ?></td>
                                                <td class="text-end text-success">₹<?php echo number_format($inv['paid_amount'], 2); ?></td>
                                                <td class="text-end"><strong class="text-danger">₹<?php echo number_format($inv['outstanding'], 2); ?></strong></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-mark-paid" 
                                                            onclick="openMarkPaymentModal(
                                                                <?php echo $inv['invoice_id']; ?>,
                                                                '<?php echo addslashes($inv['invoice_no']); ?>',
                                                                '<?php echo addslashes($inv['party_name']); ?>',
                                                                <?php echo $inv['total_amount']; ?>,
                                                                <?php echo $inv['paid_amount']; ?>,
                                                                <?php echo $inv['outstanding']; ?>
                                                            )">
                                                        <i class="fas fa-check"></i> Mark Paid
                                                    </button>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="8" class="text-center py-4 text-muted">
                                                <i class="fas fa-check-circle fa-3x mb-3 text-success"></i>
                                                <p class="mb-0">No outstanding bills found!</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 2: Payment History -->
            <div class="tab-pane fade" id="history" role="tabpanel">
                <div class="card">
                    <div class="card-header bg-white">
                        <h5 class="mb-0"><i class="fas fa-history text-primary"></i> Recent Payment History</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Date</th>
                                        <th>Invoice</th>
                                        <th>Party</th>
                                        <th>Mode</th>
                                        <th class="text-end">Amount</th>
                                        <th>Remarks</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if ($history_result && $history_result->num_rows > 0): ?>
                                        <?php while ($h = $history_result->fetch_assoc()): ?>
                                            <tr>
                                                <td><?php echo date('d M Y', strtotime($h['received_date'])); ?></td>
                                                <td><strong class="text-primary">#<?php echo htmlspecialchars($h['invoice_no']); ?></strong></td>
                                                <td><?php echo htmlspecialchars($h['party_name']); ?></td>
                                                <td>
                                                    <?php 
                                                        $mode_class = strtolower(str_replace(' ', '', $h['payment_mode']));
                                                        if ($mode_class == 'banktransfer') $mode_class = 'bank';
                                                    ?>
                                                    <span class="payment-mode-badge <?php echo $mode_class; ?>">
                                                        <?php echo htmlspecialchars($h['payment_mode']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><strong class="text-success">₹<?php echo number_format($h['amount'], 2); ?></strong></td>
                                                <td><small class="text-muted"><?php echo htmlspecialchars($h['remarks'] ?? '-'); ?></small></td>
                                            </tr>
                                        <?php endwhile; ?>
                                    <?php else: ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4 text-muted">
                                                <i class="fas fa-inbox fa-3x mb-3"></i>
                                                <p class="mb-0">No payment history found</p>
                                            </td>
                                        </tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 3: Today's Collection -->
            <div class="tab-pane fade" id="today" role="tabpanel">
                <div class="row">
                    <div class="col-md-4">
                        <div class="card bg-success text-white">
                            <div class="card-body text-center">
                                <h6 class="opacity-75">Today's Total Collection</h6>
                                <h1 class="display-4 mb-0">₹<?php echo number_format($today_data['total_collected'], 2); ?></h1>
                                <p class="mb-0 opacity-75"><?php echo $today_data['receipt_count']; ?> payment(s) received</p>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-8">
                        <div class="card">
                            <div class="card-header bg-white">
                                <h5 class="mb-0"><i class="fas fa-calendar-day text-success"></i> Today's Receipts (<?php echo date('d M Y'); ?>)</h5>
                            </div>
                            <div class="card-body p-0">
                                <div class="table-responsive">
                                    <table class="table table-hover mb-0">
                                        <thead class="table-light">
                                            <tr>
                                                <th>Invoice</th>
                                                <th>Party</th>
                                                <th>Mode</th>
                                                <th class="text-end">Amount</th>
                                                <th>Remarks</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php if ($today_detail_result && $today_detail_result->num_rows > 0): ?>
                                                <?php while ($t = $today_detail_result->fetch_assoc()): ?>
                                                    <tr>
                                                        <td><strong class="text-primary">#<?php echo htmlspecialchars($t['invoice_no']); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($t['party_name']); ?></td>
                                                        <td>
                                                            <?php 
                                                                $mode_class = strtolower(str_replace(' ', '', $t['payment_mode']));
                                                                if ($mode_class == 'banktransfer') $mode_class = 'bank';
                                                            ?>
                                                            <span class="payment-mode-badge <?php echo $mode_class; ?>">
                                                                <?php echo htmlspecialchars($t['payment_mode']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end"><strong class="text-success">₹<?php echo number_format($t['amount'], 2); ?></strong></td>
                                                        <td><small class="text-muted"><?php echo htmlspecialchars($t['remarks'] ?? '-'); ?></small></td>
                                                    </tr>
                                                <?php endwhile; ?>
                                            <?php else: ?>
                                                <tr>
                                                    <td colspan="5" class="text-center py-4 text-muted">
                                                        <i class="fas fa-inbox fa-3x mb-3"></i>
                                                        <p class="mb-0">No payments received today yet</p>
                                                    </td>
                                                </tr>
                                            <?php endif; ?>
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- TAB 4: WhatsApp Reminders -->
            <div class="tab-pane fade" id="whatsapp" role="tabpanel">
                <div class="row">
                    <!-- Agent-wise Reminders -->
                    <div class="col-md-6">
                        <h5 class="mb-3"><i class="fas fa-user-tie text-primary"></i> Agent-wise Reminders</h5>
                        <?php if (count($agent_grouped) > 0): ?>
                            <?php foreach ($agent_grouped as $agent): ?>
                                <div class="whatsapp-card card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($agent['agent_name']); ?></strong>
                                            <br><small><i class="fas fa-phone"></i> <?php echo $agent['agent_phone']; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-light text-dark">
                                                <?php echo count($agent['invoices']); ?> bills
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-2">
                                            <strong>Total Bill:</strong> ₹<?php echo number_format($agent['total_bill'], 2); ?><br>
                                            <strong class="text-danger">Outstanding:</strong> ₹<?php echo number_format($agent['total_outstanding'], 2); ?>
                                        </p>
                                        <small class="text-muted">Bills: 
                                            <?php 
                                            $bill_list = [];
                                            foreach ($agent['invoices'] as $inv) {
                                                $bill_list[] = '#' . $inv['invoice_no'] . ' (₹' . number_format($inv['outstanding'], 0) . ')';
                                            }
                                            echo implode(', ', array_slice($bill_list, 0, 5));
                                            if (count($bill_list) > 5) echo ' +' . (count($bill_list) - 5) . ' more';
                                            ?>
                                        </small>
                                        <hr>
                                        <?php
                                            // Build parties list for agent message
                                            $parties_list = '';
                                            $unique_parties = [];
                                            foreach ($agent['invoices'] as $i) {
                                                if (!in_array($i['party_name'], $unique_parties)) {
                                                    $unique_parties[] = $i['party_name'];
                                                }
                                            }
                                            foreach ($unique_parties as $pname) {
                                                $party_outstanding = 0;
                                                foreach ($agent['invoices'] as $i) {
                                                    if ($i['party_name'] == $pname) {
                                                        $party_outstanding += $i['outstanding'];
                                                    }
                                                }
                                                $parties_list .= "• $pname - ₹" . number_format($party_outstanding, 2) . "\n";
                                            }
                                        ?>
                                        <form method="POST">
                                            <input type="hidden" name="send_agent_reminder" value="1">
                                            <input type="hidden" name="agent_phone" value="<?php echo htmlspecialchars($agent['agent_phone']); ?>">
                                            <input type="hidden" name="agent_name" value="<?php echo htmlspecialchars($agent['agent_name']); ?>">
                                            <input type="hidden" name="total_amount" value="<?php echo $agent['total_outstanding']; ?>">
                                            <input type="hidden" name="parties_list" value="<?php echo htmlspecialchars($parties_list); ?>">
                                            <button type="submit" class="btn btn-whatsapp btn-sm w-100">
                                                <i class="fab fa-whatsapp"></i> Send Reminder to Agent
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">No agent reminders pending</div>
                        <?php endif; ?>
                    </div>

                    <!-- Direct Party Reminders -->
                    <div class="col-md-6">
                        <h5 class="mb-3"><i class="fas fa-building text-warning"></i> Direct Party Reminders</h5>
                        <?php if (count($party_direct) > 0): ?>
                            <?php foreach ($party_direct as $party): ?>
                                <div class="whatsapp-card card">
                                    <div class="card-header d-flex justify-content-between align-items-center">
                                        <div>
                                            <strong><?php echo htmlspecialchars($party['party_name']); ?></strong>
                                            <br><small><i class="fas fa-phone"></i> <?php echo $party['party_phone']; ?></small>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-light text-dark">
                                                <?php echo count($party['invoices']); ?> bills
                                            </span>
                                        </div>
                                    </div>
                                    <div class="card-body">
                                        <p class="mb-2">
                                            <strong>Total Bill:</strong> ₹<?php echo number_format($party['total_bill'], 2); ?><br>
                                            <strong class="text-danger">Outstanding:</strong> ₹<?php echo number_format($party['total_outstanding'], 2); ?>
                                        </p>
                                        <hr>
                                        <?php
                                            $bill_details = '';
                                            foreach ($party['invoices'] as $i) {
                                                $bill_details .= "#" . $i['invoice_no'] . " - ₹" . number_format($i['outstanding'], 2) . "\n";
                                            }
                                        ?>
                                        <form method="POST">
                                            <input type="hidden" name="send_reminder" value="1">
                                            <input type="hidden" name="client_id" value="<?php echo $party['invoices'][0]['client_id'] ?? 0; ?>">
                                            <input type="hidden" name="send_to" value="<?php echo htmlspecialchars($party['party_phone']); ?>">
                                            <input type="hidden" name="recipient_type" value="Party">
                                            <input type="hidden" name="total_amount" value="<?php echo $party['total_bill']; ?>">
                                            <input type="hidden" name="outstanding_amount" value="<?php echo $party['total_outstanding']; ?>">
                                            <input type="hidden" name="party_name" value="<?php echo htmlspecialchars($party['party_name']); ?>">
                                            <input type="hidden" name="bills" value="<?php echo htmlspecialchars($bill_details); ?>">
                                            <button type="submit" class="btn btn-whatsapp btn-sm w-100">
                                                <i class="fab fa-whatsapp"></i> Send Reminder to Party
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info">No direct party reminders pending</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Mark Payment Modal -->
    <div class="modal fade" id="markPaymentModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="mark_payment" value="1">
                    <input type="hidden" name="invoice_id" id="mp_invoice_id">
                    
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title"><i class="fas fa-rupee-sign"></i> Mark Payment Received</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body">
                        <div class="alert alert-info mb-3">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong id="mp_invoice_no"></strong>
                                    <br><span id="mp_party_name" class="text-muted"></span>
                                </div>
                            </div>
                            <hr class="my-2">
                            <div class="row text-center">
                                <div class="col-4">
                                    <small class="text-muted d-block">Bill</small>
                                    <strong id="mp_bill_amount"></strong>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted d-block">Paid</small>
                                    <span id="mp_paid_amount" class="text-success"></span>
                                </div>
                                <div class="col-4">
                                    <small class="text-muted d-block">Due</small>
                                    <strong id="mp_outstanding" class="text-danger"></strong>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label"><strong>Amount Received (₹)</strong></label>
                            <input type="number" name="amount_received" id="mp_amount" step="0.01" min="0.01" required 
                                   class="form-control form-control-lg text-center" placeholder="0.00">
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Payment Mode</label>
                                <select name="payment_mode" class="form-select">
                                    <option value="Cash">💵 Cash</option>
                                    <option value="UPI">📱 UPI</option>
                                    <option value="Bank Transfer">🏦 Bank Transfer</option>
                                    <option value="Cheque">📝 Cheque</option>
                                    <option value="Other">📋 Other</option>
                                </select>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Received Date</label>
                                <input type="date" name="received_date" class="form-control" 
                                       value="<?php echo date('Y-m-d'); ?>" required>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label class="form-label">Remarks (Optional)</label>
                            <input type="text" name="remarks" class="form-control" 
                                   placeholder="e.g., Partial payment, Advance, Cash received by...">
                        </div>
                    </div>
                    
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> Cancel
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-check"></i> Save Payment
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    function openMarkPaymentModal(invoiceId, invoiceNo, partyName, billAmount, paidAmount, outstanding) {
        document.getElementById('mp_invoice_id').value = invoiceId;
        document.getElementById('mp_invoice_no').textContent = '#' + invoiceNo;
        document.getElementById('mp_party_name').textContent = partyName;
        document.getElementById('mp_bill_amount').textContent = '₹' + billAmount.toLocaleString('en-IN', {minimumFractionDigits: 2});
        document.getElementById('mp_paid_amount').textContent = '₹' + paidAmount.toLocaleString('en-IN', {minimumFractionDigits: 2});
        document.getElementById('mp_outstanding').textContent = '₹' + outstanding.toLocaleString('en-IN', {minimumFractionDigits: 2});
        document.getElementById('mp_amount').max = outstanding;
        document.getElementById('mp_amount').value = outstanding;
        
        new bootstrap.Modal(document.getElementById('markPaymentModal')).show();
    }
    </script>
</body>
</html>

<?php include 'footer.php'; ?>
