
# BILLING PHP Enhancement Plan

## Summary
Is plan mein teen main changes honge aapke PHP billing system mein:
1. **Outstanding Debtors WhatsApp** - Agent ko PDF, ya sirf Party ko (agar Agent nahi hai)
2. **Popup Optimization** - invoices.php mein extra popups kam karna
3. **Drag-Drop Upload** - 3 separate upload zones for Commercial Invoice, Packing List, Bilti

---

## Change 1: Outstanding Debtors - Smart WhatsApp Routing

### Current Situation
- `dispatch.php` mein manually Party, Agent, Group select karna padta hai
- Outstanding invoices ki bulk WhatsApp feature nahi hai

### New Logic

```text
For each Outstanding Invoice:
  -> Check: Does client have agent_phone?
  -> YES (Has Agent): Send PDF to AGENT only (not Party)
  -> NO (DIRECT): Send PDF to PARTY phone
```

### Implementation

**New File: `outstanding_whatsapp.php`**

This page will show:
- Outstanding invoices grouped by Agent
- "Send to Agent" button for each agent group
- For DIRECT clients (no agent), "Send to Party" button

Key database query:
```sql
SELECT i.*, c.party_name, c.phone as party_phone, 
       c.agent_name, c.agent_phone
FROM invoices i
JOIN clients c ON i.party_id = c.id
WHERE i.status != 'Closed'
ORDER BY c.agent_name, i.date DESC
```

WhatsApp routing logic:
```php
// If Agent exists -> send to Agent
if (!empty($agent_phone)) {
    $send_to = $agent_phone;
    $recipient_type = "Agent";
}
// If no Agent (DIRECT) -> send to Party
else {
    $send_to = $party_phone;
    $recipient_type = "Party";
}
```

---

## Change 2: Popup Reduction in invoices.php

### Current Problem
SweetAlert popups har action pe aa rahe hain:
- Delete ke baad
- Upload ke baad
- Local mark ke baad
- Invoice create ke baad

### Proposed Changes

| Action | Current | New |
|--------|---------|-----|
| Invoice Created | Popup + Redirect | Silent Redirect |
| File Uploaded | Popup + Redirect | Silent Redirect (page refresh kaafi hai) |
| Local Marked | Popup + Redirect | Silent Redirect |
| File Deleted | Popup + Redirect | Silent Redirect |
| Invoice Deleted | Popup + Redirect | **KEEP** (important confirmation) |
| Bilti Uploaded | Popup + Redirect to Dispatch | **KEEP** (important notification) |

### Code Changes in invoices.php

Lines to modify:
- Line 22: Delete invoice popup - KEEP
- Line 29: Mark local popup - REMOVE (change to silent redirect)
- Line 45: Delete file popup - REMOVE
- Line 85: Upload success popup - REMOVE
- Line 106: Invoice created popup - REMOVE

Example change:
```php
// BEFORE (Line 29)
$alert_script = "<script>Swal.fire('Marked Local'...).then(()=>window.location='invoices.php');</script>";

// AFTER
$alert_script = "<script>window.location='invoices.php';</script>";
```

---

## Change 3: Drag-Drop Multi-Document Upload

### Current Situation (Lines 224-249 in invoices.php)
- Modal has dropdown to select document type
- One file upload at a time
- User has to open modal 3 times for 3 documents

### New Design

```text
+------------------------------------------+
|    Upload Documents for #FSD00123        |
+------------------------------------------+
| +----------------+  +----------------+   |
| | DRAG & DROP    |  | DRAG & DROP    |   |
| | Commercial     |  | Packing List   |   |
| | Invoice        |  |                |   |
| | [file.pdf]     |  | [drop here]    |   |
| +----------------+  +----------------+   |
|                                          |
| +------------------------------------+   |
| | DRAG & DROP                        |   |
| | Bilti / LR                         |   |
| | [drop here]                        |   |
| +------------------------------------+   |
|                                          |
| [========= Upload All Documents =========]|
+------------------------------------------+
```

### Implementation

**A. New Modal HTML (replace lines 224-249)**
- 3 separate drag-drop zones
- Each zone for: invoice_file, packing_file, bilti_file
- Single submit button for all files

**B. New CSS**
```css
.upload-zone {
    border: 2px dashed #ccc;
    border-radius: 10px;
    padding: 30px;
    text-align: center;
    cursor: pointer;
    transition: 0.3s;
    background: #fafafa;
}
.upload-zone:hover, .upload-zone.dragover {
    border-color: #198754;
    background: #e8f5e9;
}
.upload-zone.has-file {
    border-color: #198754;
    background: #d4edda;
}
```

**C. New JavaScript for Drag-Drop**
- Click zone = open file picker
- Drag over = highlight zone
- Drop file = show filename
- Multiple files can be selected before submit

**D. New PHP Handler (add at top of invoices.php)**
```php
if (isset($_POST['multi_upload'])) {
    $inv_id = intval($_POST['inv_id']);
    $has_bilti = false;
    
    $files_map = [
        'invoice_file' => 'invoice_path',
        'packing_file' => 'packing_path',
        'bilti_file' => 'bilti_path'
    ];
    
    foreach ($files_map as $input => $col) {
        if (!empty($_FILES[$input]['name'])) {
            // Validate + Upload
            // Update database
            if ($col == 'bilti_path') $has_bilti = true;
        }
    }
    
    // Redirect: If bilti uploaded -> dispatch.php, else invoices.php
    if ($has_bilti) {
        header("Location: dispatch.php?id=$inv_id");
    } else {
        header("Location: invoices.php");
    }
    exit;
}
```

---

## Files Summary

| File | Action | Description |
|------|--------|-------------|
| `outstanding_whatsapp.php` | NEW | Outstanding Debtors with WhatsApp to Agent/Party |
| `invoices.php` | MODIFY | Popup reduction + Drag-drop upload |
| `header.php` | MODIFY | Add navigation link for new page |

---

## Technical Details

### Database Schema Used
From `clients` table:
- `agent_phone` - Agent ka WhatsApp number
- `phone` - Party ka direct number
- `wa_group_id` - WhatsApp group (optional)

From `invoices` table:
- `invoice_path`, `packing_path`, `bilti_path` - Document paths
- `status` - 'Open', 'Dispatched', 'Closed'

### UltraMsg Integration
Will use existing `sendUltraMsg()` function from `dispatch.php`:
```php
function sendUltraMsg($to, $message, $file_path = null)
```

---

## Implementation Steps

1. **Step 1**: Create `outstanding_whatsapp.php` with Agent/Party routing logic
2. **Step 2**: Modify `invoices.php` popup lines for silent redirects
3. **Step 3**: Replace upload modal with drag-drop HTML
4. **Step 4**: Add CSS styles for drag-drop zones
5. **Step 5**: Add JavaScript for drag-drop functionality
6. **Step 6**: Add multi-upload PHP handler
7. **Step 7**: Add navigation link in `header.php`

---

## Notes

- Lovable React/TypeScript stack use karta hai, PHP directly edit nahi kar sakta
- Main aapko exact PHP code changes provide karunga
- Aap un changes ko manually apne GitHub repo mein commit karenge
- All changes use existing UltraMsg API configuration from settings table
