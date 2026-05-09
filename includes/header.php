<?php if(!isset($_SESSION)) session_start(); ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($page_title??'Minerals Depot') ?> — MDMS</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<?php if(isset($extra_head)) echo $extra_head; ?>
</head>
<body>
<?php $cur = basename($_SERVER['PHP_SELF'],'.php'); ?>

<nav class="sidebar" id="sidebar">
    <div class="sidebar-brand">
        <div class="brand-icon"><i class="fas fa-mountain"></i></div>
        <div class="brand-text">
            <span>Minerals Depot</span>
            <small>Management System</small>
        </div>
    </div>

    <div class="sidebar-nav">
        <div class="nav-section-label">Main</div>
        <a href="dashboard.php"    class="nav-item <?= $cur==='dashboard'    ?'active':'' ?>"><i class="fas fa-gauge-high"></i> Dashboard</a>
        <a href="inventory.php"    class="nav-item <?= $cur==='inventory'    ?'active':'' ?>"><i class="fas fa-warehouse"></i> Inventory</a>
        <a href="batches.php"      class="nav-item <?= $cur==='batches'||$cur==='new-purchase' ?'active':'' ?>"><i class="fas fa-boxes-stacked"></i> Purchase</a>

        <a href="sales.php"        class="nav-item <?= $cur==='sales'||$cur==='new-sales'   ?'active':'' ?>"><i class="fas fa-chart-line"></i> Sales</a>
        <a href="buyers.php"       class="nav-item <?= $cur==='buyers'       ?'active':'' ?>"><i class="fas fa-handshake"></i> Buyers</a>
                <a href="suppliers.php"    class="nav-item <?= $cur==='suppliers'    ?'active':'' ?>"><i class="fas fa-building"></i> Suppliers</a>
        <a href="loans-payable.php"    class="nav-item <?= $cur==='loans-payable'    ?'active':'' ?>"><i class="fas fa-arrow-up"></i> Loan Payable</a>
        <a href="loans-receivable.php" class="nav-item <?= $cur==='loans-receivable' ?'active':'' ?>"><i class="fas fa-arrow-down"></i> Loan Receivable</a>
        <a href="accounts.php"     class="nav-item <?= $cur==='accounts'     ?'active':'' ?>"><i class="fas fa-building-columns"></i> Accounts</a>
        <a href="expenses.php"     class="nav-item <?= $cur==='expenses'     ?'active':'' ?>"><i class="fas fa-receipt"></i> Expenses</a>

        <div class="nav-section-label" style="margin-top:.5rem">Analytics</div>
        <a href="journal.php"      class="nav-item <?= $cur==='journal'      ?'active':'' ?>"><i class="fas fa-book-open"></i> Journal</a>
        <a href="reports.php"      class="nav-item <?= $cur==='reports'      ?'active':'' ?>"><i class="fas fa-chart-bar"></i> Reports</a>
        <a href="audit_log.php"    class="nav-item <?= $cur==='audit_log'    ?'active':'' ?>"><i class="fas fa-scroll"></i> Audit Log</a>

        <?php if(isset($_SESSION['role']) && $_SESSION['role']==='admin'): ?>
        <div class="nav-section-label" style="margin-top:.5rem">Admin</div>
        <a href="users.php"    class="nav-item <?= $cur==='users'    ?'active':'' ?>"><i class="fas fa-users"></i> Users</a>
        <a href="settings.php" class="nav-item <?= $cur==='settings' ?'active':'' ?>"><i class="fas fa-sliders"></i> Settings</a>
        <a href="backup.php"   class="nav-item <?= $cur==='backup'   ?'active':'' ?>"><i class="fas fa-database"></i> Backup</a>
        <?php endif; ?>
    </div>

    <div class="sidebar-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username']??'U',0,1)) ?></div>
        <div class="user-info">
            <div class="u-name"><?= htmlspecialchars($_SESSION['username']??'User') ?></div>
            <div class="u-role"><?= $_SESSION['role']??'' ?></div>
        </div>
        <a href="logout.php" class="user-logout" title="Logout"><i class="fas fa-right-from-bracket"></i></a>
    </div>
</nav>

<div class="main-wrapper">
    <header class="topbar">
        <button class="sidebar-toggle" onclick="toggleSidebar()"><i class="fas fa-bars"></i></button>
        <h1 class="topbar-title"><?= htmlspecialchars($page_title??'') ?></h1>
        <span class="topbar-meta"><i class="fas fa-calendar-days" style="margin-right:.35rem"></i><?= date('d M Y') ?></span>
    </header>
    <main class="page-content">
