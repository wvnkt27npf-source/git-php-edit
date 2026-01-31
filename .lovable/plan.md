# Database Schema - Agents & Parties (Updated)

## Summary
Database ko restructure kiya gaya hai jisme:
1. **agents** - Separate table for Agents
2. **parties** - Parties table with `agent_id` foreign key linking to agents
3. All PHP files updated to use JOIN queries

---

## Database Tables Required

### 1. AGENTS TABLE
```sql
CREATE TABLE `agents` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `agent_name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `wa_group_id` VARCHAR(100) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 2. PARTIES TABLE
```sql
CREATE TABLE `parties` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY,
  `party_name` VARCHAR(255) NOT NULL,
  `email` VARCHAR(255) DEFAULT NULL,
  `phone` VARCHAR(50) DEFAULT NULL,
  `gstin` VARCHAR(20) DEFAULT NULL,
  `address` TEXT DEFAULT NULL,
  `contact_person` VARCHAR(100) DEFAULT NULL,
  `agent_id` INT DEFAULT NULL,
  `wa_group_id` VARCHAR(100) DEFAULT NULL,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`agent_id`) REFERENCES `agents`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

### 3. INVOICES TABLE (existing - no changes needed)
```sql
-- invoices.party_id references parties.id
```

### 4. PAYMENT_RECEIPTS TABLE (existing)
```sql
CREATE TABLE `payment_receipts` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `invoice_id` INT NOT NULL,
  `amount` DECIMAL(12,2) NOT NULL,
  `received_date` DATE NOT NULL,
  `payment_mode` VARCHAR(50) DEFAULT 'Cash',
  `remarks` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (`invoice_id`) REFERENCES `invoices`(`id`) ON DELETE CASCADE
);
```

### 5. WA_REMINDER_LOG TABLE (existing)
```sql
CREATE TABLE `wa_reminder_log` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `client_id` INT NOT NULL,
  `recipient_type` ENUM('Agent', 'Party') DEFAULT 'Party',
  `sent_to` VARCHAR(20),
  `sent_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `last_invoice_id` INT NOT NULL,
  `status` ENUM('Sent', 'Failed') DEFAULT 'Sent',
  FOREIGN KEY (`client_id`) REFERENCES `parties`(`id`) ON DELETE CASCADE
);
```

---

## Migration from OLD clients table to NEW structure

If you have existing `clients` table with embedded agent info, run these queries:

```sql
-- Step 1: Create agents table from unique agents in clients
INSERT INTO agents (agent_name, email, phone)
SELECT DISTINCT agent_name, agent_email, agent_phone 
FROM clients 
WHERE agent_name IS NOT NULL AND agent_name != '' AND agent_name != 'DIRECT';

-- Step 2: Create parties table from clients
INSERT INTO parties (id, party_name, email, phone, gstin, address, contact_person, agent_id, wa_group_id)
SELECT 
    c.id, 
    c.party_name, 
    c.email, 
    c.phone, 
    c.gstin, 
    c.address, 
    c.contact_person,
    a.id as agent_id,
    c.wa_group_id
FROM clients c
LEFT JOIN agents a ON c.agent_name = a.agent_name;

-- Step 3: After verification, you can drop the old clients table
-- DROP TABLE clients;
```

---

## Updated PHP Files

| File | Changes |
|------|---------|
| `invoices.php` | All queries updated to JOIN parties + agents |
| `dispatch.php` | All queries updated to JOIN parties + agents |
| `whatsapp.php` | All queries updated to JOIN parties + agents |
| `payments.php` | All queries updated to JOIN parties + agents |
| `clients.php` | Full restructure with Agents/Parties tabs |

---

## Query Pattern Used

```php
// OLD Query (embedded agents in clients)
SELECT i.*, c.party_name, c.agent_name, c.agent_phone 
FROM invoices i 
LEFT JOIN clients c ON i.party_id = c.id

// NEW Query (separate agents table)
SELECT i.*, 
       p.party_name, p.phone as party_phone, p.wa_group_id,
       a.agent_name, a.phone as agent_phone, a.email as agent_email
FROM invoices i 
LEFT JOIN parties p ON i.party_id = p.id
LEFT JOIN agents a ON p.agent_id = a.id
```

---

## Features Summary

| Feature | Description |
|---------|-------------|
| Separate Agent Master | Add/Edit/Delete agents independently |
| Separate Party Master | Add/Edit/Delete parties with agent linking |
| Agent-Party Linking | Link parties to agents via dropdown |
| WA Group for Both | Both agents and parties can have WhatsApp group IDs |
| Bulk Import/Export | CSV import/export with UPSERT for both |
| Indian Currency | ₹ formatting with Lakh/Crore comma pattern |
