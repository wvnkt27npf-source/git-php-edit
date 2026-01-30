<?php
/**
 * =====================================================
 * HEADER.PHP MODIFICATION
 * =====================================================
 * 
 * Add this navigation link in your sidebar/menu
 */
?>

<!-- Add this link in your navigation menu (header.php or sidebar) -->
<!-- Find the Reports section and add this item -->

<li class="nav-item">
    <a class="nav-link" href="payments.php">
        <i class="fas fa-money-bill-wave text-success"></i>
        <span>Payments Reminder</span>
    </a>
</li>

<li class="nav-item">
    <a class="nav-link" href="outstanding_whatsapp.php">
        <i class="fab fa-whatsapp text-success"></i>
        <span>Outstanding - WhatsApp</span>
    </a>
</li>

<!-- OR if using dropdown menu style -->
<a class="dropdown-item" href="payments.php">
    <i class="fas fa-money-bill-wave text-success"></i> Payments Reminder
</a>
<a class="dropdown-item" href="outstanding_whatsapp.php">
    <i class="fab fa-whatsapp text-success"></i> Outstanding Debtors WhatsApp
</a>

<?php
/**
 * =====================================================
 * COMPLETE NAVIGATION EXAMPLE
 * =====================================================
 */
?>

<!-- Example: If your sidebar looks like this -->
<ul class="navbar-nav">
    <li class="nav-item">
        <a class="nav-link" href="dashboard.php">
            <i class="fas fa-tachometer-alt"></i> Dashboard
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="invoices.php">
            <i class="fas fa-file-invoice"></i> Invoices
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link" href="dispatch.php">
            <i class="fas fa-truck"></i> Dispatch
        </a>
    </li>
    
    <!-- ADD THIS NEW ITEM -->
    <li class="nav-item">
        <a class="nav-link" href="outstanding_whatsapp.php">
            <i class="fab fa-whatsapp text-success"></i> Outstanding WhatsApp
        </a>
    </li>
    <!-- END NEW ITEM -->
    
    <li class="nav-item">
        <a class="nav-link" href="reports.php">
            <i class="fas fa-chart-bar"></i> Reports
        </a>
    </li>
</ul>
