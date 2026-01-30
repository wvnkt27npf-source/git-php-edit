

# Enhanced Payments Page - Bill-wise Payment Received Feature

## Summary
Payments page ko fully featured banana hai with:
1. **Bill-wise Payment Received** - Individual invoices pe "Received" mark kar sakein
2. **Improved UI** - Better layout with filters, search, and organized sections
3. **Payment History** - Track all received payments with date

---

## Database Changes Required

Pehle database mein do changes karne honge:

```sql
-- 1. Add paid_amount column if not exists
ALTER TABLE invoices ADD COLUMN paid_amount DECIMAL(12,2) DEFAULT 0 AFTER total_amount;

-- 2. Create payment_receipts table for history
CREATE TABLE payment_receipts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    invoice_id INT NOT NULL,
    amount DECIMAL(12,2) NOT NULL,
    received_date DATE NOT NULL,
    payment_mode VARCHAR(50) DEFAULT 'Cash',
    remarks TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
);
```

---

## New payments.php Structure

### A. Page Layout (3 Tabs)

```text
+------------------------------------------------------------------+
|  PAYMENTS MANAGEMENT                                              |
|  [Outstanding] [Payment History] [Received Today]                 |
+------------------------------------------------------------------+

TAB 1: OUTSTANDING
+------------------------------------------------------------------+
| FILTERS: [All | Party-wise | Agent-wise] [Search...] [Date Range]|
+------------------------------------------------------------------+
| Total Bill: Rs. 5,00,000 | Outstanding: Rs. 3,50,000             |
+------------------------------------------------------------------+

BILL-WISE TABLE:
+-------+--------+-------+---------+-----------+--------+-----------+
|Invoice| Date   | Party | Agent   | Bill Amt  | Paid   | Action    |
+-------+--------+-------+---------+-----------+--------+-----------+
|#123   |15 Jan  | ABC   | Ramesh  | Rs.50,000 |Rs.0    |[Mark Paid]|
|#124   |16 Jan  | XYZ   | Suresh  | Rs.30,000 |Rs.10k  |[Mark Paid]|
+-------+--------+-------+---------+-----------+--------+-----------+

TAB 2: PAYMENT HISTORY
+-------+--------+----------+-------+--------+-----------+
|Date   |Invoice | Party    | Mode  | Amount | Remarks   |
+-------+--------+----------+-------+--------+-----------+
|30 Jan |#123    | ABC      | Cash  | Rs.25k | Partial   |
|29 Jan |#124    | XYZ      | UPI   | Rs.30k | Full      |
+-------+--------+----------+-------+--------+-----------+

TAB 3: RECEIVED TODAY
Quick summary of today's collections
```

### B. Mark Payment Received Modal

```text
+------------------------------------------+
|  MARK PAYMENT RECEIVED                   |
+------------------------------------------+
| Invoice: #FSD00123                       |
| Party: ABC Traders                       |
| Bill Amount: Rs. 50,000                  |
| Already Paid: Rs. 0                      |
| Outstanding: Rs. 50,000                  |
+------------------------------------------+
| Amount Received: [___________]           |
| Payment Mode: [Cash v]                   |
|   Options: Cash, UPI, Bank Transfer,     |
|            Cheque, Other                 |
| Date: [2026-01-30]                       |
| Remarks: [________________]              |
+------------------------------------------+
|            [Cancel]  [Save Payment]      |
+------------------------------------------+
```

### C. WhatsApp Reminder (Party/Agent-wise)
- Same existing functionality with grouped data
- Added "Received" badge for partially paid invoices

---

## Code Changes

### 1. New PHP Handlers

```php
// Handle Mark Payment Received
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
    
    // Check if fully paid
    $check = $conn->query("SELECT total_amount, paid_amount FROM invoices WHERE id = $invoice_id")->fetch_assoc();
    if ($check['paid_amount'] >= $check['total_amount']) {
        $conn->query("UPDATE invoices SET status = 'Paid' WHERE id = $invoice_id");
    }
    
    // Silent redirect
    header("Location: payments.php");
    exit;
}
```

### 2. New Queries

```php
// Outstanding Invoices (Bill-wise)
$outstanding_query = "SELECT 
    i.id as invoice_id,
    i.invoice_no,
    i.date,
    i.total_amount,
    COALESCE(i.paid_amount, 0) as paid_amount,
    (COALESCE(i.total_amount, 0) - COALESCE(i.paid_amount, 0)) as outstanding,
    c.party_name,
    c.phone as party_phone,
    c.agent_name,
    c.agent_phone
FROM invoices i
LEFT JOIN clients c ON i.party_id = c.id
WHERE i.status NOT IN ('Closed', 'Paid')
  AND (COALESCE(i.total_amount, 0) - COALESCE(i.paid_amount, 0)) > 0
ORDER BY i.date DESC";

// Payment History
$history_query = "SELECT 
    pr.*,
    i.invoice_no,
    c.party_name
FROM payment_receipts pr
JOIN invoices i ON pr.invoice_id = i.id
LEFT JOIN clients c ON i.party_id = c.id
ORDER BY pr.received_date DESC, pr.id DESC
LIMIT 100";

// Today's Collection
$today_query = "SELECT 
    SUM(pr.amount) as total_collected,
    COUNT(pr.id) as receipt_count
FROM payment_receipts pr
WHERE pr.received_date = CURDATE()";
```

### 3. UI Components

**A. Tab Navigation**
```html
<ul class="nav nav-tabs" id="paymentTabs">
    <li class="nav-item">
        <a class="nav-link active" data-bs-toggle="tab" href="#outstanding">
            <i class="fas fa-exclamation-circle"></i> Outstanding
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#history">
            <i class="fas fa-history"></i> Payment History
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" data-bs-toggle="tab" href="#today">
            <i class="fas fa-calendar-day"></i> Today's Collection
        </a>
    </li>
</ul>
```

**B. Mark Payment Modal**
```html
<div class="modal fade" id="markPaymentModal">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <input type="hidden" name="mark_payment" value="1">
                <input type="hidden" name="invoice_id" id="mp_invoice_id">
                
                <div class="modal-header bg-success text-white">
                    <h5><i class="fas fa-rupee-sign"></i> Mark Payment Received</h5>
                </div>
                
                <div class="modal-body">
                    <div class="alert alert-info">
                        <strong id="mp_invoice_no"></strong> - <span id="mp_party_name"></span><br>
                        Bill: <strong id="mp_bill_amount"></strong> | 
                        Paid: <span id="mp_paid_amount"></span> | 
                        Due: <strong class="text-danger" id="mp_outstanding"></strong>
                    </div>
                    
                    <div class="mb-3">
                        <label>Amount Received (Rs.)</label>
                        <input type="number" name="amount_received" step="0.01" required 
                               class="form-control form-control-lg" id="mp_amount">
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label>Payment Mode</label>
                            <select name="payment_mode" class="form-select">
                                <option value="Cash">Cash</option>
                                <option value="UPI">UPI</option>
                                <option value="Bank Transfer">Bank Transfer</option>
                                <option value="Cheque">Cheque</option>
                                <option value="Other">Other</option>
                            </select>
                        </div>
                        <div class="col-md-6 mb-3">
                            <label>Date</label>
                            <input type="date" name="received_date" class="form-control" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label>Remarks (Optional)</label>
                        <input type="text" name="remarks" class="form-control" 
                               placeholder="e.g., Partial payment, Cash received">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-check"></i> Save Payment
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
```

**C. JavaScript for Modal**
```javascript
function openMarkPaymentModal(invoiceId, invoiceNo, partyName, billAmount, paidAmount, outstanding) {
    document.getElementById('mp_invoice_id').value = invoiceId;
    document.getElementById('mp_invoice_no').textContent = '#' + invoiceNo;
    document.getElementById('mp_party_name').textContent = partyName;
    document.getElementById('mp_bill_amount').textContent = 'Rs. ' + billAmount;
    document.getElementById('mp_paid_amount').textContent = 'Rs. ' + paidAmount;
    document.getElementById('mp_outstanding').textContent = 'Rs. ' + outstanding;
    document.getElementById('mp_amount').max = outstanding;
    document.getElementById('mp_amount').value = outstanding;
    
    new bootstrap.Modal(document.getElementById('markPaymentModal')).show();
}
```

---

## Features Summary

| Feature | Description |
|---------|-------------|
| Bill-wise Payment | Individual invoice pe payment mark kar sakein |
| Partial Payments | Multiple payments track ho sakein per invoice |
| Payment History | Complete log of all received payments |
| Today's Collection | Quick view of day's total collection |
| Payment Modes | Cash, UPI, Bank Transfer, Cheque, Other |
| WhatsApp Reminder | Party/Agent wise reminder with correct outstanding |
| Outstanding Filter | Party-wise, Agent-wise, or All view |
| Search | Invoice number ya party name se search |

---

## Files Summary

| File | Action | Description |
|------|--------|-------------|
| payments.php | REWRITE | Full featured payments page with tabs, modals, history |

---

## Implementation Steps

1. Run SQL to create payment_receipts table and add paid_amount column
2. Rewrite payments.php with:
   - Tab-based navigation (Outstanding, History, Today)
   - Bill-wise outstanding table with "Mark Paid" button
   - Mark Payment modal with amount, mode, date, remarks
   - Payment history table
   - Today's collection summary
   - Keep existing WhatsApp reminder functionality
3. Test payment marking flow
4. Test partial payment tracking

