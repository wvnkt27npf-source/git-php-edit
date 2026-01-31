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
 * - WhatsApp Reminders (Party/Agent wise) with Send Tracking
 * - Auto-mark sent, Auto-unmark on new invoice
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
 * 3. CREATE TABLE wa_reminder_log (
 *        id INT AUTO_INCREMENT PRIMARY KEY,
 *        client_id INT NOT NULL,
 *        recipient_type ENUM('Agent', 'Party') DEFAULT 'Party',
 *        sent_to VARCHAR(20),
 *        sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
 *        last_invoice_id INT NOT NULL,
 *        status ENUM('Sent', 'Failed') DEFAULT 'Sent',
 *        FOREIGN KEY (client_id) REFERENCES clients(id) ON DELETE CASCADE
 *    );
 */

include 'db.php';
include 'header.php';

// Indian Lakh/Crore number format function (₹13,87,629.00)
function formatIndianCurrency($num, $decimals = 2) {
    $num = floatval($num);
    $isNegative = $num < 0;
    $num = abs($num);
    
    // Split into integer and decimal parts
    $parts = explode('.', number_format($num, $decimals, '.', ''));
    $intPart = $parts[0];
    $decPart = $parts[1] ?? str_repeat('0', $decimals);
    
    $len = strlen($intPart);
    if ($len <= 3) {
        $result = $intPart;
    } else {
        // Last 3 digits
        $result = substr($intPart, -3);
        // Remaining digits in groups of 2
        $remaining = substr($intPart, 0, -3);
        while (strlen($remaining) > 2) {
            $result = substr($remaining, -2) . ',' . $result;
            $remaining = substr($remaining, 0, -2);
        }
        if (strlen($remaining) > 0) {
            $result = $remaining . ',' . $result;
        }
    }
    
    return ($isNegative ? '-' : '') . $result . '.' . $decPart;
}

// Get UltraMsg settings AND Company info from database
$settings = $conn->query("SELECT * FROM settings LIMIT 1")->fetch_assoc();
$ultramsg_token = $settings['wa_token'] ?? '';
$ultramsg_instance = $settings['wa_instance_id'] ?? '';

// Company/Sender Details from settings (using actual field names from settings table)
$company_name = $settings['display_name'] ?? $settings['company_name'] ?? 'Our Company';
$email_signature = $settings['email_signature'] ?? '';
$company_phone = $settings['company_phone'] ?? $settings['phone'] ?? '';

// Parse email_signature to extract sender details
// Format: "Thanks & Regards,\nRahul Jat\nBhilwara Spinners Limited\nAddress...\nMo: 9351545935"
$signature_lines = array_filter(array_map('trim', explode("\n", $email_signature)));
$sender_name = '';
$sender_phone_from_sig = '';
$sender_address = '';

// Extract details from signature
foreach ($signature_lines as $idx => $line) {
    // Skip greeting lines like "Thanks & Regards,"
    if (stripos($line, 'thanks') !== false || stripos($line, 'regards') !== false) continue;
    
    // First meaningful line after greeting is typically the person's name
    if (empty($sender_name) && !empty($line) && stripos($line, 'mo:') === false && stripos($line, 'phone') === false) {
        $sender_name = $line;
        continue;
    }
    
    // Extract phone from signature if present
    if (stripos($line, 'mo:') !== false || stripos($line, 'mob:') !== false || stripos($line, 'phone') !== false) {
        $sender_phone_from_sig = preg_replace('/[^0-9]/', '', $line);
    }
    
    // Collect address lines
    if (!empty($sender_name) && stripos($line, 'mo:') === false && $line != $sender_name && $line != $company_name) {
        $sender_address .= (!empty($sender_address) ? ', ' : '') . $line;
    }
}

// Use phone from signature if company_phone not set
if (empty($company_phone) && !empty($sender_phone_from_sig)) {
    $company_phone = $sender_phone_from_sig;
}

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
// CHECK IF REMINDER ALREADY SENT (and no new invoices added)
// =====================================================
function isReminderSent($conn, $client_id, $recipient_type) {
    // Get last reminder log for this client
    $log = $conn->query("SELECT * FROM wa_reminder_log 
                         WHERE client_id = $client_id AND recipient_type = '$recipient_type' 
                         ORDER BY sent_at DESC LIMIT 1")->fetch_assoc();
    
    if (!$log) return false;
    
    // Check if any new invoice added after the reminder was sent
    $last_sent_invoice_id = $log['last_invoice_id'];
    $new_invoice = $conn->query("SELECT id FROM invoices 
                                  WHERE party_id = $client_id AND id > $last_sent_invoice_id 
                                  AND status NOT IN ('Closed', 'Paid') 
                                  LIMIT 1")->fetch_assoc();
    
    // If new invoice found, reminder should be re-sent
    if ($new_invoice) return false;
    
    return $log; // Return log data if sent and no new invoices
}

// =====================================================
// LOG REMINDER SENT
// =====================================================
function logReminderSent($conn, $client_id, $recipient_type, $sent_to, $status = 'Sent') {
    // Get the latest invoice ID for this client
    $latest = $conn->query("SELECT MAX(id) as max_id FROM invoices WHERE party_id = $client_id")->fetch_assoc();
    $last_invoice_id = $latest['max_id'] ?? 0;
    
    $sent_to = $conn->real_escape_string($sent_to);
    $conn->query("INSERT INTO wa_reminder_log (client_id, recipient_type, sent_to, last_invoice_id, status) 
                  VALUES ($client_id, '$recipient_type', '$sent_to', $last_invoice_id, '$status')");
}

// =====================================================
// BUILD PROFESSIONAL MESSAGE WITH SIGNATURE
// =====================================================
function buildReminderMessage($party_name, $bills, $total_bill, $outstanding, $company_name, $sender_name, $company_phone, $is_agent = false, $agent_name = '', $parties_list = '') {
    $message = "";
    
    if ($is_agent) {
        // Agent Message - Clean & Professional
        $message .= "Namaskar *$agent_name* Ji 🙏\n\n";
        $message .= "Pending collection ke liye reminder:\n\n";
        $message .= "$parties_list\n";
        $message .= "*Total Due: ₹" . formatIndianCurrency($outstanding, 2) . "*\n\n";
        $message .= "Kripya collection karwa dein.\n\n";
    } else {
        // Party Message - Clean & Professional
        $message .= "Namaskar *$party_name* Ji 🙏\n\n";
        $message .= "Aapke pending bills:\n\n";
        $message .= "$bills\n";
        $message .= "*Outstanding: ₹" . formatIndianCurrency($outstanding, 2) . "*\n\n";
        $message .= "Kripya payment karein.\n\n";
    }
    
    // Clean Signature
    $message .= "— *$company_name*";
    if (!empty($sender_name) && $sender_name != $company_name) {
        $message .= "\n$sender_name";
    }
    if (!empty($company_phone)) {
        $message .= "\n📞 $company_phone";
    }
    
    return $message;
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
// HANDLE WHATSAPP REMINDERS - PARTY (with custom message support)
// =====================================================
if (isset($_POST['send_reminder'])) {
    $client_id = intval($_POST['client_id']);
    $send_to = $_POST['send_to'];
    $recipient_type = $_POST['recipient_type'];
    $total_amount = floatval($_POST['total_amount']);
    $outstanding_amount = floatval($_POST['outstanding_amount'] ?? $total_amount);
    $bills = $_POST['bills'];
    $party_name = $_POST['party_name'];
    
    // Use custom message if provided, otherwise build default
    if (!empty($_POST['custom_message'])) {
        $message = $_POST['custom_message'];
    } else {
        $message = buildReminderMessage(
            $party_name, 
            $bills, 
            $total_amount, 
            $outstanding_amount, 
            $company_name, 
            $sender_name, 
            $company_phone,
            false
        );
    }
    
    $result = sendWhatsAppReminder($send_to, $message, $ultramsg_token, $ultramsg_instance);
    
    if (isset($result['sent']) && $result['sent'] == 'true') {
        // Log the successful send
        logReminderSent($conn, $client_id, 'Party', $send_to, 'Sent');
        $alert_script = "<script>Swal.fire('Sent!', 'Reminder sent to $party_name successfully', 'success');</script>";
    } else {
        // Log the failed attempt
        logReminderSent($conn, $client_id, 'Party', $send_to, 'Failed');
        $error_msg = $result['error'] ?? 'Unknown error';
        $alert_script = "<script>Swal.fire('Error', 'Failed to send: $error_msg', 'error');</script>";
    }
}

// Handle Agent Bulk Send (with custom message support)
if (isset($_POST['send_agent_reminder'])) {
    $agent_phone = $_POST['agent_phone'];
    $agent_name = $_POST['agent_name'];
    $total_amount = floatval($_POST['total_amount']);
    $parties_list = $_POST['parties_list'];
    $client_ids = explode(',', $_POST['client_ids'] ?? '');
    
    // Use custom message if provided, otherwise build default
    if (!empty($_POST['custom_message'])) {
        $message = $_POST['custom_message'];
    } else {
        $message = buildReminderMessage(
            '', 
            '', 
            $total_amount, 
            $total_amount, 
            $company_name, 
            $sender_name, 
            $company_phone,
            true,
            $agent_name,
            $parties_list
        );
    }
    
    $result = sendWhatsAppReminder($agent_phone, $message, $ultramsg_token, $ultramsg_instance);
    
    if (isset($result['sent']) && $result['sent'] == 'true') {
        // Log for all clients under this agent
        foreach ($client_ids as $cid) {
            $cid = intval($cid);
            if ($cid > 0) {
                logReminderSent($conn, $cid, 'Agent', $agent_phone, 'Sent');
            }
        }
        $alert_script = "<script>Swal.fire('Sent!', 'Reminder sent to Agent $agent_name successfully', 'success');</script>";
    } else {
        // Log failed for all clients
        foreach ($client_ids as $cid) {
            $cid = intval($cid);
            if ($cid > 0) {
                logReminderSent($conn, $cid, 'Agent', $agent_phone, 'Failed');
            }
        }
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
    $where_conditions[] = "(i.invoice_no LIKE '%$search_escaped%' OR p.party_name LIKE '%$search_escaped%' OR a.agent_name LIKE '%$search_escaped%')";
}
if (!empty($date_from)) {
    $where_conditions[] = "i.date >= '$date_from'";
}
if (!empty($date_to)) {
    $where_conditions[] = "i.date <= '$date_to'";
}

$where_clause = implode(' AND ', $where_conditions);

// Outstanding Invoices Query (Bill-wise) - UPDATED: Join with parties and agents tables
$outstanding_query = "SELECT 
    i.id as invoice_id,
    i.invoice_no,
    i.date,
    i.total_amount,
    COALESCE(i.paid_amount, 0) as paid_amount,
    (COALESCE(i.total_amount, 0) - COALESCE(i.paid_amount, 0)) as outstanding,
    p.id as client_id,
    p.party_name,
    p.phone as party_phone,
    a.agent_name,
    a.phone as agent_phone
FROM invoices i
LEFT JOIN parties p ON i.party_id = p.id
LEFT JOIN agents a ON p.agent_id = a.id
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
// PAYMENT HISTORY - UPDATED: Join with parties table
// =====================================================
$history_query = "SELECT 
    pr.*,
    i.invoice_no,
    p.party_name
FROM payment_receipts pr
JOIN invoices i ON pr.invoice_id = i.id
LEFT JOIN parties p ON i.party_id = p.id
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

// Today's detailed receipts - UPDATED: Join with parties table
$today_detail_query = "SELECT 
    pr.*,
    i.invoice_no,
    p.party_name
FROM payment_receipts pr
JOIN invoices i ON pr.invoice_id = i.id
LEFT JOIN parties p ON i.party_id = p.id
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
                'total_outstanding' => 0,
                'client_ids' => []
            ];
        }
        $agent_grouped[$agent_key]['invoices'][] = $inv;
        $agent_grouped[$agent_key]['total_bill'] += $inv['total_amount'];
        $agent_grouped[$agent_key]['total_outstanding'] += $inv['outstanding'];
        if (!in_array($inv['client_id'], $agent_grouped[$agent_key]['client_ids'])) {
            $agent_grouped[$agent_key]['client_ids'][] = $inv['client_id'];
        }
    } else {
        // Direct party
        $party_key = $inv['party_name'] . '|' . $inv['party_phone'];
        if (!isset($party_direct[$party_key])) {
            $party_direct[$party_key] = [
                'party_name' => $inv['party_name'],
                'party_phone' => $inv['party_phone'],
                'client_id' => $inv['client_id'],
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
        
        .badge-sent {
            background: linear-gradient(135deg, #25D366, #128C7E);
            color: white;
            font-size: 10px;
            padding: 3px 8px;
        }
        
        .badge-pending {
            background: #ffc107;
            color: #000;
            font-size: 10px;
            padding: 3px 8px;
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
        .whatsapp-card.sent {
            border-color: #6c757d;
            opacity: 0.85;
        }
        .whatsapp-card.sent .card-header {
            background: linear-gradient(135deg, #6c757d, #495057);
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
        .btn-whatsapp.resend {
            background: #6c757d;
        }
        .btn-whatsapp.resend:hover {
            background: #495057;
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
                                <h3 class="mb-0 text-primary">₹<?php echo formatIndianCurrency($total_bill, 2); ?></h3>
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
                                <h3 class="mb-0 text-danger">₹<?php echo formatIndianCurrency($total_outstanding, 2); ?></h3>
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
                                <h3 class="mb-0 text-success">₹<?php echo formatIndianCurrency($today_data['total_collected'], 2); ?></h3>
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
                                                    <?php echo htmlspecialchars($inv['party_name'] ?? 'Unknown Party'); ?>
                                                    <?php if (!empty($inv['party_phone'])): ?>
                                                        <br><small class="text-muted"><i class="fas fa-phone"></i> <?php echo htmlspecialchars($inv['party_phone'] ?? ''); ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <?php echo htmlspecialchars($inv['agent_name'] ?? 'DIRECT'); ?>
                                                </td>
                                                <td class="text-end">₹<?php echo formatIndianCurrency($inv['total_amount'], 2); ?></td>
                                                <td class="text-end text-success">₹<?php echo formatIndianCurrency($inv['paid_amount'], 2); ?></td>
                                                <td class="text-end"><strong class="text-danger">₹<?php echo formatIndianCurrency($inv['outstanding'], 2); ?></strong></td>
                                                <td class="text-center">
                                                    <button type="button" class="btn btn-mark-paid" 
                                                            onclick="openMarkPaymentModal(
                                                                <?php echo $inv['invoice_id']; ?>,
                                                                '<?php echo addslashes($inv['invoice_no'] ?? ''); ?>',
                                                                '<?php echo addslashes($inv['party_name'] ?? 'Unknown Party'); ?>',
                                                                <?php echo $inv['total_amount'] ?? 0; ?>,
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
                                                <td><strong class="text-primary">#<?php echo htmlspecialchars($h['invoice_no'] ?? ''); ?></strong></td>
                                                <td><?php echo htmlspecialchars($h['party_name'] ?? 'Unknown Party'); ?></td>
                                                <td>
                                                    <?php 
                                                        $mode_class = strtolower(str_replace(' ', '', $h['payment_mode']));
                                                        if ($mode_class == 'banktransfer') $mode_class = 'bank';
                                                    ?>
                                                    <span class="payment-mode-badge <?php echo $mode_class; ?>">
                                                        <?php echo htmlspecialchars($h['payment_mode']); ?>
                                                    </span>
                                                </td>
                                                <td class="text-end"><strong class="text-success">₹<?php echo formatIndianCurrency($h['amount'], 2); ?></strong></td>
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
                                <h1 class="display-4 mb-0">₹<?php echo formatIndianCurrency($today_data['total_collected'], 2); ?></h1>
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
                                                        <td><strong class="text-primary">#<?php echo htmlspecialchars($t['invoice_no'] ?? ''); ?></strong></td>
                                                        <td><?php echo htmlspecialchars($t['party_name'] ?? 'Unknown Party'); ?></td>
                                                        <td>
                                                            <?php 
                                                                $mode_class = strtolower(str_replace(' ', '', $t['payment_mode']));
                                                                if ($mode_class == 'banktransfer') $mode_class = 'bank';
                                                            ?>
                                                            <span class="payment-mode-badge <?php echo $mode_class; ?>">
                                                                <?php echo htmlspecialchars($t['payment_mode']); ?>
                                                            </span>
                                                        </td>
                                                        <td class="text-end"><strong class="text-success">₹<?php echo formatIndianCurrency($t['amount'], 2); ?></strong></td>
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

            <!-- TAB 4: WhatsApp Reminders - UPGRADED WITH TABLES, SORTING, FILTERING -->
            <div class="tab-pane fade" id="whatsapp" role="tabpanel">
                <!-- Legend & Info -->
                <div class="alert alert-info mb-4">
                    <div class="d-flex justify-content-between align-items-center flex-wrap">
                        <div>
                            <i class="fas fa-info-circle"></i> 
                            <strong>Auto-Tracking:</strong> 
                            <span class="badge badge-sent me-1">✓ Sent</span> = Message sent
                            <span class="badge badge-pending ms-2">⏳ Pending</span> = Pending or new invoice added
                        </div>
                        <div class="mt-2 mt-md-0">
                            <strong>Logic:</strong> Agent ho to Agent ko, DIRECT ho to Party ko
                        </div>
                    </div>
                </div>

                <!-- Search & Filter Bar for WhatsApp Tab -->
                <div class="card mb-4">
                    <div class="card-body">
                        <form method="GET" class="row g-3 align-items-end" id="waFilterForm">
                            <input type="hidden" name="tab" value="whatsapp">
                            <div class="col-md-4">
                                <label class="form-label small text-muted">Search Party / Invoice / Agent</label>
                                <div class="input-group">
                                    <span class="input-group-text"><i class="fas fa-search"></i></span>
                                    <input type="text" name="wa_search" id="wa_search" class="form-control" 
                                           placeholder="Party name, Invoice no, Phone..." 
                                           value="<?php echo htmlspecialchars($_GET['wa_search'] ?? ''); ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small text-muted">Status</label>
                                <select name="wa_status" id="wa_status" class="form-select">
                                    <option value="all" <?php echo (($_GET['wa_status'] ?? 'all') == 'all') ? 'selected' : ''; ?>>All Status</option>
                                    <option value="pending" <?php echo (($_GET['wa_status'] ?? '') == 'pending') ? 'selected' : ''; ?>>Pending Only</option>
                                    <option value="sent" <?php echo (($_GET['wa_status'] ?? '') == 'sent') ? 'selected' : ''; ?>>Sent Only</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <label class="form-label small text-muted">Sort By</label>
                                <select name="wa_sort" id="wa_sort" class="form-select">
                                    <option value="outstanding_desc" <?php echo (($_GET['wa_sort'] ?? 'outstanding_desc') == 'outstanding_desc') ? 'selected' : ''; ?>>Outstanding ↓</option>
                                    <option value="outstanding_asc" <?php echo (($_GET['wa_sort'] ?? '') == 'outstanding_asc') ? 'selected' : ''; ?>>Outstanding ↑</option>
                                    <option value="party_asc" <?php echo (($_GET['wa_sort'] ?? '') == 'party_asc') ? 'selected' : ''; ?>>Party A-Z</option>
                                    <option value="party_desc" <?php echo (($_GET['wa_sort'] ?? '') == 'party_desc') ? 'selected' : ''; ?>>Party Z-A</option>
                                    <option value="date_desc" <?php echo (($_GET['wa_sort'] ?? '') == 'date_desc') ? 'selected' : ''; ?>>Date (Newest)</option>
                                    <option value="date_asc" <?php echo (($_GET['wa_sort'] ?? '') == 'date_asc') ? 'selected' : ''; ?>>Date (Oldest)</option>
                                </select>
                            </div>
                            <div class="col-md-2">
                                <button type="submit" class="btn btn-primary w-100">
                                    <i class="fas fa-filter"></i> Apply
                                </button>
                            </div>
                            <div class="col-md-2">
                                <a href="payments.php?tab=whatsapp" class="btn btn-outline-secondary w-100">
                                    <i class="fas fa-times"></i> Clear
                                </a>
                            </div>
                        </form>
                    </div>
                </div>

                <!-- WhatsApp Sub-Tabs: Agent vs Direct -->
                <ul class="nav nav-pills mb-4" id="waSubTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="agent-tab" data-bs-toggle="pill" data-bs-target="#agent-parties" type="button">
                            <i class="fas fa-user-tie"></i> Agent Parties 
                            <span class="badge bg-primary ms-1"><?php echo count($agent_grouped); ?></span>
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="direct-tab" data-bs-toggle="pill" data-bs-target="#direct-parties" type="button">
                            <i class="fas fa-building"></i> Direct Parties
                            <span class="badge bg-warning text-dark ms-1"><?php echo count($party_direct); ?></span>
                        </button>
                    </li>
                </ul>

                <div class="tab-content" id="waSubTabContent">
                    <!-- AGENT PARTIES TAB -->
                    <div class="tab-pane fade show active" id="agent-parties" role="tabpanel">
                        <?php if (count($agent_grouped) > 0): ?>
                            <?php 
                            // Apply search & sort filters to agent_grouped
                            $wa_search = strtolower($_GET['wa_search'] ?? '');
                            $wa_status = $_GET['wa_status'] ?? 'all';
                            $wa_sort = $_GET['wa_sort'] ?? 'outstanding_desc';
                            
                            // Convert to array for sorting
                            $agent_array = array_values($agent_grouped);
                            
                            // Sort agents
                            usort($agent_array, function($a, $b) use ($wa_sort) {
                                switch ($wa_sort) {
                                    case 'outstanding_asc': return $a['total_outstanding'] - $b['total_outstanding'];
                                    case 'outstanding_desc': return $b['total_outstanding'] - $a['total_outstanding'];
                                    case 'party_asc': return strcasecmp($a['agent_name'], $b['agent_name']);
                                    case 'party_desc': return strcasecmp($b['agent_name'], $a['agent_name']);
                                    default: return $b['total_outstanding'] - $a['total_outstanding'];
                                }
                            });
                            ?>
                            
                            <?php foreach ($agent_array as $agent): ?>
                                <?php
                                    // Search filter
                                    if (!empty($wa_search)) {
                                        $match = false;
                                        if (stripos($agent['agent_name'], $wa_search) !== false || 
                                            stripos($agent['agent_phone'], $wa_search) !== false) {
                                            $match = true;
                                        }
                                        foreach ($agent['invoices'] as $inv) {
                                            if (stripos($inv['party_name'], $wa_search) !== false || 
                                                stripos($inv['invoice_no'], $wa_search) !== false) {
                                                $match = true;
                                                break;
                                            }
                                        }
                                        if (!$match) continue;
                                    }
                                    
                                    // Check if reminder already sent
                                    $first_client_id = $agent['client_ids'][0] ?? 0;
                                    $is_sent = isReminderSent($conn, $first_client_id, 'Agent');
                                    
                                    // Status filter
                                    if ($wa_status == 'pending' && $is_sent) continue;
                                    if ($wa_status == 'sent' && !$is_sent) continue;
                                    
                                    // Build parties list
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
                                        $parties_list .= "• $pname - ₹" . formatIndianCurrency($party_outstanding, 2) . "\n";
                                    }
                                ?>
                                
                                <div class="card mb-3 <?php echo $is_sent ? 'border-success' : 'border-warning'; ?>">
                                    <div class="card-header d-flex justify-content-between align-items-center" 
                                         style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white;">
                                        <div>
                                            <i class="fas fa-user-tie"></i>
                                            <strong class="ms-2"><?php echo htmlspecialchars($agent['agent_name'] ?? 'Unknown Agent'); ?></strong>
                                            <?php if ($is_sent): ?>
                                                <span class="badge bg-success ms-2">✓ Sent</span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark ms-2">⏳ Pending</span>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <span class="badge bg-white text-dark"><?php echo count($agent['invoices']); ?> Bills</span>
                                            <span class="badge bg-danger ms-1">₹<?php echo formatIndianCurrency($agent['total_outstanding'], 2); ?> Due</span>
                                        </div>
                                    </div>
                                    <div class="card-body p-0">
                                        <!-- Agent Info Row -->
                                        <div class="p-3 bg-light border-bottom d-flex justify-content-between align-items-center flex-wrap">
                                            <div>
                                                <i class="fas fa-phone text-muted"></i> 
                                                <strong><?php echo htmlspecialchars($agent['agent_phone'] ?? ''); ?></strong>
                                                <?php if ($is_sent): ?>
                                                    <small class="text-muted ms-3">
                                                        <i class="fas fa-check-circle text-success"></i> 
                                                        Last sent: <?php echo date('d M Y, h:i A', strtotime($is_sent['sent_at'])); ?>
                                                    </small>
                                                <?php endif; ?>
                                            </div>
                                            <?php
                                                // Generate the preview message for agent
                                                $agent_preview_msg = buildReminderMessage(
                                                    '', 
                                                    '', 
                                                    $agent['total_outstanding'], 
                                                    $agent['total_outstanding'], 
                                                    $company_name, 
                                                    $sender_name, 
                                                    $company_phone,
                                                    true,
                                                    $agent['agent_name'],
                                                    $parties_list
                                                );
                                            ?>
                                            <button type="button" class="btn btn-whatsapp btn-sm wa-preview-btn"
                                                data-type="agent"
                                                data-clientid="<?php echo implode(',', $agent['client_ids']); ?>"
                                                data-phone="<?php echo htmlspecialchars($agent['agent_phone']); ?>"
                                                data-name="<?php echo htmlspecialchars($agent['agent_name']); ?>"
                                                data-total="<?php echo $agent['total_outstanding']; ?>"
                                                data-outstanding="<?php echo $agent['total_outstanding']; ?>"
                                                data-bills="<?php echo htmlspecialchars($parties_list); ?>"
                                                data-message="<?php echo htmlspecialchars($agent_preview_msg); ?>">
                                                <i class="fab fa-whatsapp"></i> 
                                                <?php echo $is_sent ? 'Re-send' : 'Send'; ?> to Agent
                                            </button>
                                        </div>
                                        
                                        <!-- Invoices Table -->
                                        <div class="table-responsive">
                                            <table class="table table-sm table-hover mb-0">
                                                <thead class="table-light">
                                                    <tr>
                                                        <th>Invoice #</th>
                                                        <th>Date</th>
                                                        <th>Party</th>
                                                        <th>Phone</th>
                                                        <th class="text-end">Bill Amt</th>
                                                        <th class="text-end">Outstanding</th>
                                                        <th>Status</th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php foreach ($agent['invoices'] as $inv): ?>
                                                        <tr>
                                                            <td><strong class="text-primary">#<?php echo htmlspecialchars($inv['invoice_no'] ?? ''); ?></strong></td>
                                                            <td><?php echo date('d M Y', strtotime($inv['date'])); ?></td>
                                                            <td><?php echo htmlspecialchars($inv['party_name'] ?? 'Unknown Party'); ?></td>
                                                            <td><small><?php echo htmlspecialchars($inv['party_phone'] ?? '-'); ?></small></td>
                                                            <td class="text-end">₹<?php echo formatIndianCurrency($inv['total_amount'], 2); ?></td>
                                                            <td class="text-end"><strong class="text-danger">₹<?php echo formatIndianCurrency($inv['outstanding'], 2); ?></strong></td>
                                                            <td>
                                                                <?php if ($inv['paid_amount'] > 0): ?>
                                                                    <span class="badge bg-warning text-dark">Partial</span>
                                                                <?php else: ?>
                                                                    <span class="badge bg-danger">Open</span>
                                                                <?php endif; ?>
                                                            </td>
                                                        </tr>
                                                    <?php endforeach; ?>
                                                </tbody>
                                                <tfoot class="table-secondary">
                                                    <tr>
                                                        <th colspan="4" class="text-end">Total:</th>
                                                        <th class="text-end">₹<?php echo formatIndianCurrency($agent['total_bill'], 2); ?></th>
                                                        <th class="text-end text-danger">₹<?php echo formatIndianCurrency($agent['total_outstanding'], 2); ?></th>
                                                        <th></th>
                                                    </tr>
                                                </tfoot>
                                            </table>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-success text-center">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p class="mb-0">No agent reminders pending!</p>
                            </div>
                        <?php endif; ?>
                    </div>

                    <!-- DIRECT PARTIES TAB -->
                    <div class="tab-pane fade" id="direct-parties" role="tabpanel">
                        <?php 
                        // Define wa_sort for Direct Parties tab (fix undefined variable)
                        $wa_search = strtolower($_GET['wa_search'] ?? '');
                        $wa_status = $_GET['wa_status'] ?? 'all';
                        $wa_sort = $_GET['wa_sort'] ?? 'outstanding_desc';
                        ?>
                        <?php if (count($party_direct) > 0): ?>
                            <div class="card">
                                <div class="card-header" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white;">
                                    <i class="fas fa-building"></i>
                                    <strong class="ms-2">Direct Parties</strong>
                                    <small class="ms-2">- WhatsApp directly to party (no agent)</small>
                                </div>
                                <div class="card-body p-0">
                                    <div class="table-responsive">
                                        <table class="table table-hover mb-0" id="directPartiesTable">
                                            <thead class="table-light">
                                                <tr>
                                                    <th style="cursor:pointer" onclick="applySort('party_<?php echo ($wa_sort == 'party_asc') ? 'desc' : 'asc'; ?>')">
                                                        Party 
                                                        <?php if ($wa_sort == 'party_asc'): ?><i class="fas fa-sort-alpha-down ms-1"></i>
                                                        <?php elseif ($wa_sort == 'party_desc'): ?><i class="fas fa-sort-alpha-up ms-1"></i>
                                                        <?php else: ?><i class="fas fa-sort ms-1 text-muted"></i>
                                                        <?php endif; ?>
                                                    </th>
                                                    <th>Phone</th>
                                                    <th class="text-center">Bills</th>
                                                    <th class="text-end">Total Bill</th>
                                                    <th class="text-end" style="cursor:pointer" onclick="applySort('outstanding_<?php echo ($wa_sort == 'outstanding_desc') ? 'asc' : 'desc'; ?>')">
                                                        Outstanding 
                                                        <?php if ($wa_sort == 'outstanding_desc'): ?><i class="fas fa-sort-amount-down ms-1"></i>
                                                        <?php elseif ($wa_sort == 'outstanding_asc'): ?><i class="fas fa-sort-amount-up ms-1"></i>
                                                        <?php else: ?><i class="fas fa-sort ms-1 text-muted"></i>
                                                        <?php endif; ?>
                                                    </th>
                                                    <th class="text-center">Status</th>
                                                    <th class="text-center">Action</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                // Apply search & sort filters
                                                $party_array = array_values($party_direct);
                                                
                                                // Sort
                                                usort($party_array, function($a, $b) use ($wa_sort) {
                                                    switch ($wa_sort) {
                                                        case 'outstanding_asc': return $a['total_outstanding'] - $b['total_outstanding'];
                                                        case 'outstanding_desc': return $b['total_outstanding'] - $a['total_outstanding'];
                                                        case 'party_asc': return strcasecmp($a['party_name'], $b['party_name']);
                                                        case 'party_desc': return strcasecmp($b['party_name'], $a['party_name']);
                                                        default: return $b['total_outstanding'] - $a['total_outstanding'];
                                                    }
                                                });
                                                
                                                foreach ($party_array as $party): 
                                                    // Search filter
                                                    if (!empty($wa_search)) {
                                                        $match = false;
                                                        if (stripos($party['party_name'], $wa_search) !== false || 
                                                            stripos($party['party_phone'], $wa_search) !== false) {
                                                            $match = true;
                                                        }
                                                        foreach ($party['invoices'] as $inv) {
                                                            if (stripos($inv['invoice_no'], $wa_search) !== false) {
                                                                $match = true;
                                                                break;
                                                            }
                                                        }
                                                        if (!$match) continue;
                                                    }
                                                    
                                                    // Check if reminder already sent
                                                    $is_sent = isReminderSent($conn, $party['client_id'], 'Party');
                                                    
                                                    // Status filter
                                                    if ($wa_status == 'pending' && $is_sent) continue;
                                                    if ($wa_status == 'sent' && !$is_sent) continue;
                                                    
                                                    // Build bill details
                                                    $bill_details = '';
                                                    foreach ($party['invoices'] as $i) {
                                                        $bill_details .= "#" . $i['invoice_no'] . " - ₹" . formatIndianCurrency($i['outstanding'], 2) . "\n";
                                                    }
                                                ?>
                                                    <tr>
                                                        <td>
                                                            <strong><?php echo htmlspecialchars($party['party_name'] ?? 'Unknown Party'); ?></strong>
                                                            <?php if ($is_sent): ?>
                                                                <br><small class="text-muted">
                                                                    <i class="fas fa-check-circle text-success"></i> 
                                                                    Sent: <?php echo date('d M, h:i A', strtotime($is_sent['sent_at'])); ?>
                                                                </small>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <i class="fas fa-phone text-muted"></i> 
                                                            <?php echo htmlspecialchars($party['party_phone'] ?? ''); ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <span class="badge bg-secondary"><?php echo count($party['invoices']); ?></span>
                                                        </td>
                                                        <td class="text-end">₹<?php echo formatIndianCurrency($party['total_bill'], 2); ?></td>
                                                        <td class="text-end"><strong class="text-danger">₹<?php echo formatIndianCurrency($party['total_outstanding'], 2); ?></strong></td>
                                                        <td class="text-center">
                                                            <?php if ($is_sent): ?>
                                                                <span class="badge badge-sent">✓ Sent</span>
                                                            <?php else: ?>
                                                                <span class="badge badge-pending">⏳ Pending</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td class="text-center">
                                                            <?php if (!empty($party['party_phone'])): 
                                                                // Generate the preview message
                                                                $preview_msg = buildReminderMessage(
                                                                    $party['party_name'], 
                                                                    $bill_details, 
                                                                    $party['total_bill'], 
                                                                    $party['total_outstanding'], 
                                                                    $company_name, 
                                                                    $sender_name, 
                                                                    $company_phone,
                                                                    false
                                                                );
                                                            ?>
                                                                <button type="button" class="btn btn-whatsapp btn-sm wa-preview-btn" 
                                                                    data-type="party"
                                                                    data-clientid="<?php echo $party['client_id']; ?>"
                                                                    data-phone="<?php echo htmlspecialchars($party['party_phone']); ?>"
                                                                    data-name="<?php echo htmlspecialchars($party['party_name']); ?>"
                                                                    data-total="<?php echo $party['total_bill']; ?>"
                                                                    data-outstanding="<?php echo $party['total_outstanding']; ?>"
                                                                    data-bills="<?php echo htmlspecialchars($bill_details); ?>"
                                                                    data-message="<?php echo htmlspecialchars($preview_msg); ?>">
                                                                    <i class="fab fa-whatsapp"></i> 
                                                                    <?php echo $is_sent ? 'Re-send' : 'Send'; ?>
                                                                </button>
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
                            <div class="alert alert-success text-center">
                                <i class="fas fa-check-circle fa-2x mb-2"></i>
                                <p class="mb-0">No direct party reminders pending!</p>
                            </div>
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

    <!-- WhatsApp Preview Modal - Professional Design -->
    <div class="modal fade" id="whatsappPreviewModal" tabindex="-1">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content border-0 shadow-lg" style="border-radius: 16px; overflow: hidden;">
                <form method="POST" id="waPreviewForm">
                    <!-- Hidden fields -->
                    <input type="hidden" name="send_reminder" id="wa_send_type" value="1">
                    <input type="hidden" name="client_id" id="wa_client_id">
                    <input type="hidden" name="send_to" id="wa_send_to">
                    <input type="hidden" name="recipient_type" id="wa_recipient_type" value="Party">
                    <input type="hidden" name="total_amount" id="wa_total_amount">
                    <input type="hidden" name="outstanding_amount" id="wa_outstanding_amount">
                    <input type="hidden" name="party_name" id="wa_party_name">
                    <input type="hidden" name="bills" id="wa_bills">
                    <input type="hidden" name="agent_phone" id="wa_agent_phone">
                    <input type="hidden" name="agent_name" id="wa_agent_name">
                    <input type="hidden" name="parties_list" id="wa_parties_list">
                    <input type="hidden" name="client_ids" id="wa_client_ids">
                    
                    <div class="modal-header border-0 py-3" style="background: #075E54;">
                        <div class="d-flex align-items-center">
                            <div class="rounded-circle bg-white bg-opacity-25 d-flex align-items-center justify-content-center me-3" style="width: 45px; height: 45px;">
                                <i class="fab fa-whatsapp text-white fa-lg"></i>
                            </div>
                            <div class="text-white">
                                <h6 class="mb-0 fw-semibold" id="wa_display_name">Recipient</h6>
                                <small class="opacity-75" id="wa_display_phone">+91 XXXXXXXXXX</small>
                            </div>
                        </div>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    
                    <div class="modal-body p-0" style="background: #ECE5DD url('data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAADIAAAAyCAMAAAAp4XiDAAAAUVBMVEWFhYWDg4N3d3dtbW17e3t1dXWBgYGHh4d5eXlzc3Oeli3ecCbn5teleadings...');">
                        <!-- Amount Badge -->
                        <div class="px-3 py-2">
                            <span class="badge rounded-pill px-3 py-2" style="background: #DCF8C6; color: #075E54; font-size: 14px;">
                                <i class="fas fa-rupee-sign me-1"></i>
                                <span id="wa_display_amount">₹0</span> Outstanding
                            </span>
                        </div>
                        
                        <!-- Message Bubble -->
                        <div class="px-3 pb-3">
                            <div class="position-relative" style="background: #DCF8C6; border-radius: 0 12px 12px 12px; padding: 12px 14px; box-shadow: 0 1px 2px rgba(0,0,0,0.1);">
                                <textarea 
                                    name="custom_message" 
                                    id="wa_message_textarea" 
                                    class="form-control border-0 p-0" 
                                    rows="10" 
                                    style="background: transparent; resize: none; font-size: 14px; line-height: 1.6;"
                                ></textarea>
                                <div class="text-end mt-2">
                                    <small class="text-muted" style="font-size: 11px;">
                                        <span id="charCount">0</span> chars
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="modal-footer border-0 bg-white py-3 gap-2">
                        <button type="button" class="btn btn-outline-secondary rounded-pill px-4" onclick="resetMessage()">
                            <i class="fas fa-undo me-1"></i> Reset
                        </button>
                        <button type="button" class="btn btn-light rounded-pill px-4" data-bs-dismiss="modal">
                            Cancel
                        </button>
                        <button type="submit" class="btn rounded-pill px-4 fw-semibold" style="background: #25D366; color: white;">
                            <i class="fab fa-whatsapp me-1"></i> Send Message
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
    // Store original message for reset
    let originalMessage = '';
    
    // Parse number that may have commas or currency symbols
    function parseLocalizedNumber(value) {
        if (!value || value === "") return 0;
        let str = String(value).trim();
        // Remove currency symbols, spaces, ₹
        str = str.replace(/[₹$\u20AC£¥\s]/g, "");
        // Remove all commas (Indian/International format)
        str = str.replace(/,/g, "");
        const parsed = parseFloat(str);
        return isNaN(parsed) ? 0 : parsed;
    }
    
    // Format number in Indian Lakh/Crore format (₹13,87,629.00)
    function formatIndianNumber(num) {
        num = parseLocalizedNumber(num);
        // Handle negative numbers
        const isNegative = num < 0;
        num = Math.abs(num);
        
        // Split into integer and decimal parts
        const [intPart, decPart] = num.toFixed(2).split('.');
        
        // Apply Indian comma format: first 3 digits, then every 2 digits
        let result = '';
        const len = intPart.length;
        
        if (len <= 3) {
            result = intPart;
        } else {
            // Last 3 digits
            result = intPart.slice(-3);
            // Remaining digits in groups of 2
            let remaining = intPart.slice(0, -3);
            while (remaining.length > 2) {
                result = remaining.slice(-2) + ',' + result;
                remaining = remaining.slice(0, -2);
            }
            if (remaining.length > 0) {
                result = remaining + ',' + result;
            }
        }
        
        return (isNegative ? '-' : '') + result + '.' + decPart;
    }
    
    // Update character count
    function updateCharCount() {
        const textarea = document.getElementById('wa_message_textarea');
        const count = document.getElementById('charCount');
        if (textarea && count) {
            count.textContent = textarea.value.length;
        }
    }
    
    function openWhatsAppPreview(type, clientId, phone, name, totalAmount, outstandingAmount, billsOrParties, previewMessage) {
        // Store original message
        originalMessage = previewMessage;
        
        // Parse amounts properly
        const parsedTotal = parseLocalizedNumber(totalAmount);
        const parsedOutstanding = parseLocalizedNumber(outstandingAmount);
        
        // Set form fields based on type
        if (type === 'agent') {
            document.getElementById('wa_send_type').name = 'send_agent_reminder';
            document.getElementById('wa_agent_phone').value = phone;
            document.getElementById('wa_agent_name').value = name;
            document.getElementById('wa_parties_list').value = billsOrParties;
            document.getElementById('wa_client_ids').value = clientId;
            document.getElementById('wa_total_amount').value = parsedTotal;
        } else {
            document.getElementById('wa_send_type').name = 'send_reminder';
            document.getElementById('wa_client_id').value = clientId;
            document.getElementById('wa_send_to').value = phone;
            document.getElementById('wa_party_name').value = name;
            document.getElementById('wa_total_amount').value = parsedTotal;
            document.getElementById('wa_outstanding_amount').value = parsedOutstanding;
            document.getElementById('wa_bills').value = billsOrParties;
            document.getElementById('wa_recipient_type').value = 'Party';
        }
        
        // Display info
        document.getElementById('wa_display_name').textContent = (type === 'agent' ? 'Agent: ' : '') + name;
        document.getElementById('wa_display_phone').textContent = '+91 ' + phone.replace(/^91/, '');
        document.getElementById('wa_display_amount').textContent = '₹' + formatIndianNumber(parsedOutstanding);
        
        // Set message in textarea
        const textarea = document.getElementById('wa_message_textarea');
        textarea.value = previewMessage;
        updateCharCount();
        
        // Show modal
        new bootstrap.Modal(document.getElementById('whatsappPreviewModal')).show();
    }
    
    function resetMessage() {
        document.getElementById('wa_message_textarea').value = originalMessage;
        updateCharCount();
    }
    
    // Attach char count listener
    document.getElementById('wa_message_textarea')?.addEventListener('input', updateCharCount);
    
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
    
    // Sorting function for table headers
    function applySort(sortValue) {
        const url = new URL(window.location.href);
        url.searchParams.set('tab', 'whatsapp');
        url.searchParams.set('wa_sort', sortValue);
        window.location.href = url.toString();
    }
    
    // Auto-select correct tab based on URL parameter
    document.addEventListener('DOMContentLoaded', function() {
        const urlParams = new URLSearchParams(window.location.search);
        const activeTab = urlParams.get('tab');
        
        if (activeTab) {
            // Deactivate all tabs
            document.querySelectorAll('#paymentTabs .nav-link').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('#paymentTabsContent .tab-pane').forEach(pane => {
                pane.classList.remove('show', 'active');
            });
            
            // Activate the correct tab
            const targetTab = document.getElementById(activeTab + '-tab');
            const targetPane = document.getElementById(activeTab);
            
            if (targetTab && targetPane) {
                targetTab.classList.add('active');
                targetPane.classList.add('show', 'active');
            }
        }
        
        // Also handle wa_search, wa_sort, wa_status - they indicate WhatsApp tab
        if (urlParams.get('wa_search') || urlParams.get('wa_sort') || urlParams.get('wa_status')) {
            document.querySelectorAll('#paymentTabs .nav-link').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('#paymentTabsContent .tab-pane').forEach(pane => {
                pane.classList.remove('show', 'active');
            });
            
            const whatsappTab = document.getElementById('whatsapp-tab');
            const whatsappPane = document.getElementById('whatsapp');
            
            if (whatsappTab && whatsappPane) {
                whatsappTab.classList.add('active');
                whatsappPane.classList.add('show', 'active');
            }
        }
        
        // Event delegation for WhatsApp preview buttons
        document.addEventListener('click', function(e) {
            const btn = e.target.closest('.wa-preview-btn');
            if (!btn) return;
            
            const type = btn.dataset.type;
            const clientId = btn.dataset.clientid;
            const phone = btn.dataset.phone;
            const name = btn.dataset.name;
            const totalAmount = btn.dataset.total;
            const outstandingAmount = btn.dataset.outstanding;
            const billsOrParties = btn.dataset.bills;
            const previewMessage = btn.dataset.message;
            
            openWhatsAppPreview(type, clientId, phone, name, totalAmount, outstandingAmount, billsOrParties, previewMessage);
        });
    });
    </script>
</body>
</html>

<?php include 'footer.php'; ?>
