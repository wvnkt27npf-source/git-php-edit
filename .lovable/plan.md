

# PHP Billing System - Payments Section Plan

## Summary
Do main changes karne hain:
1. **invoices.php** - Generate Invoice mein Date + Total Amount fields add karna
2. **payments.php** - Naya Payments Reminder page with Party/Agent wise view + WhatsApp reminder

---

## Change 1: Generate Invoice Form Update (invoices.php)

### Current Form
- Invoice Number
- Select Client

### New Form
- Invoice Number
- Select Client
- **Date** (Date Picker - default today)
- **Total Amount** (Number input)

### Code Changes

**A. Update CREATE INVOICE Handler (Line 143-158)**
```php
// BEFORE
if (isset($_POST['create_inv'])) {
    $inv = $conn->real_escape_string(trim($_POST['invoice_no']));
    $pid = intval($_POST['party_id']);
    $date = date('Y-m-d'); // <-- Fixed date
    ...
    $conn->query("INSERT INTO invoices (invoice_no, party_id, date, status) VALUES (...)");
}

// AFTER
if (isset($_POST['create_inv'])) {
    $inv = $conn->real_escape_string(trim($_POST['invoice_no']));
    $pid = intval($_POST['party_id']);
    $date = $_POST['invoice_date'] ?? date('Y-m-d'); // NEW: User selected date
    $total_amount = floatval($_POST['total_amount'] ?? 0); // NEW: Amount
    ...
    $conn->query("INSERT INTO invoices (invoice_no, party_id, date, total_amount, status) VALUES (...)");
}
```

**B. Update Form HTML (Lines 241-262)**
```html
<!-- Existing: Invoice Number -->
<div class="mb-3">
    <label>Invoice Number</label>
    <input type="text" name="invoice_no" required>
</div>

<!-- Existing: Select Client -->
<div class="mb-3">
    <label>Select Client</label>
    <select name="party_id" required>...</select>
</div>

<!-- NEW: Invoice Date -->
<div class="mb-3">
    <label>Invoice Date</label>
    <input type="date" name="invoice_date" value="<?= date('Y-m-d') ?>" required>
</div>

<!-- NEW: Total Amount -->
<div class="mb-3">
    <label>Total Amount (Rs.)</label>
    <input type="number" name="total_amount" step="0.01" min="0" required>
</div>
```

---

## Change 2: New Payments Reminder Page (payments.php)

### Features
- Party-wise aur Agent-wise outstanding amounts
- Toggle buttons: "View by Party" | "View by Agent"
- Each row shows: Party Name, Phone, Total Outstanding, Bill Numbers
- "Send Reminder" button - WhatsApp message bhejega

### WhatsApp Message Format
```text
Reminder: Aapke Rs. 50,000 baki hain

Bills:
- #FSD00123 - Rs. 25,000
- #FSD00124 - Rs. 25,000

Please arrange payment at earliest.
Thank you!
```

### Database Query
```sql
-- Party-wise outstanding
SELECT 
    c.id as client_id,
    c.party_name,
    c.phone,
    c.agent_name,
    c.agent_phone,
    GROUP_CONCAT(i.invoice_no) as bill_numbers,
    GROUP_CONCAT(CONCAT(i.invoice_no, ':', COALESCE(i.total_amount, 0))) as bill_details,
    SUM(COALESCE(i.total_amount, 0)) as total_outstanding,
    COUNT(i.id) as invoice_count
FROM clients c
JOIN invoices i ON c.id = i.party_id
WHERE i.status NOT IN ('Closed', 'Paid')
GROUP BY c.id
ORDER BY total_outstanding DESC
```

### UI Layout

```text
+----------------------------------------------------------+
|  PAYMENTS REMINDER                                        |
|  [View by Party] [View by Agent]                         |
+----------------------------------------------------------+

PARTY-WISE VIEW:
+-------------+--------+-----------+----------------+--------+
| Party Name  | Phone  | Bills     | Outstanding    | Action |
+-------------+--------+-----------+----------------+--------+
| ABC Traders | 98xxx  | #123,#124 | Rs. 50,000     | [Send] |
| XYZ Store   | 97xxx  | #125      | Rs. 25,000     | [Send] |
+-------------+--------+-----------+----------------+--------+

AGENT-WISE VIEW:
+----------------------------------------------------------+
| AGENT: Ramesh (9876543210)                    [Send All] |
+----------------------------------------------------------+
| Party: ABC Traders - Rs. 50,000 (2 bills)                |
| Party: DEF Shop - Rs. 30,000 (1 bill)                    |
| Total: Rs. 80,000                                        |
+----------------------------------------------------------+
```

### UltraMsg Integration
Same `sendUltraMsgWithDoc()` function from settings table:
- `wa_token` = API Token
- `wa_instance_id` = Instance ID

---

## Files Summary

| File | Action | Description |
|------|--------|-------------|
| `invoices_updated.php` | MODIFY | Add Date + Amount fields in Generate Invoice form |
| `payments.php` | NEW | Payments Reminder page with Party/Agent wise view |
| `header.php` | MODIFY | Add "Payments" link in navigation |

---

## Database Note

Agar `invoices` table mein `total_amount` column nahi hai, toh pehle yeh SQL run karein:

```sql
ALTER TABLE invoices ADD COLUMN total_amount DECIMAL(12,2) DEFAULT 0 AFTER date;
```

---

## Implementation Steps

1. Check if `total_amount` column exists in `invoices` table
2. Update `invoices_updated.php`:
   - Add Date input field in form
   - Add Total Amount input field in form
   - Update INSERT query to include new fields
3. Create `payments.php`:
   - Party-wise grouped outstanding data
   - Agent-wise grouped outstanding data
   - Toggle view buttons
   - Send Reminder WhatsApp functionality
4. Add navigation link for Payments page

