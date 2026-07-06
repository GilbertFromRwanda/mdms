<?php if(!isset($_SESSION)) session_start(); ?>
<?php
try {
    $sock = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    socket_connect($sock, '8.8.8.8', 80);
    socket_getsockname($sock, $_nav_server_ip);
    socket_close($sock);
} catch (Throwable $e) {
    $_nav_server_ip = gethostbyname(gethostname());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($page_title??'Minerals Depot') ?> — MDMS</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/vendor/fontawesome/all.min.css">
<?php if(isset($extra_head)) echo $extra_head; ?>
</head>
<body>
<?php $cur = basename($_SERVER['PHP_SELF'],'.php'); ?>

<nav class="topnav" id="topnav">
    <div class="topnav-brand">
        <div class="brand-icon"><i class="fas fa-mountain"></i></div>
        <span class="brand-text">Minerals Depot</span>
    </div>

    <div class="topnav-links" id="topnav-links">
        <a href="dashboard.php"  class="nav-link <?= $cur==='dashboard'  ?'active':'' ?>"><i class="fas fa-gauge-high"></i> Dashboard</a>
        <a href="inventory.php"  class="nav-link <?= $cur==='inventory'  ?'active':'' ?>"><i class="fas fa-warehouse"></i> Inventory</a>

        <div class="nav-drop <?= in_array($cur,['batches','new-purchase'])?'active':'' ?>">
            <button class="nav-link nav-drop-toggle"><i class="fas fa-boxes-stacked"></i> Purchase <i class="fas fa-chevron-down nav-caret"></i></button>
            <div class="nav-drop-menu">
                <a href="batches.php"      class="<?= $cur==='batches'      ?'active':'' ?>"><i class="fas fa-list"></i> View All</a>
                <a href="new-purchase.php" class="<?= $cur==='new-purchase' ?'active':'' ?>"><i class="fas fa-plus"></i> New Purchase</a>
            </div>
        </div>

        <div class="nav-drop <?= in_array($cur,['sales','new-sales'])?'active':'' ?>">
            <button class="nav-link nav-drop-toggle"><i class="fas fa-chart-line"></i> Sales <i class="fas fa-chevron-down nav-caret"></i></button>
            <div class="nav-drop-menu">
                <a href="sales.php"     class="<?= $cur==='sales'     ?'active':'' ?>"><i class="fas fa-list"></i> View All</a>
                <a href="new-sales.php" class="<?= $cur==='new-sales' ?'active':'' ?>"><i class="fas fa-plus"></i> New Sale</a>
            </div>
        </div>

        <div class="nav-drop <?= in_array($cur,['buyers','suppliers','supply-stock'])?'active':'' ?>">
            <button class="nav-link nav-drop-toggle"><i class="fas fa-handshake"></i> Parties <i class="fas fa-chevron-down nav-caret"></i></button>
            <div class="nav-drop-menu">
                <a href="buyers.php"       class="<?= $cur==='buyers'       ?'active':'' ?>"><i class="fas fa-handshake"></i> Buyers</a>
                <a href="suppliers.php"    class="<?= $cur==='suppliers'    ?'active':'' ?>"><i class="fas fa-building"></i> Suppliers</a>
                <a href="supply-stock.php" class="<?= $cur==='supply-stock' ?'active':'' ?>"><i class="fas fa-warehouse"></i> Supply Stock</a>
            </div>
        </div>

        <div class="nav-drop <?= in_array($cur,['loans-payable','loans-receivable','accounts','expenses','activate'])?'active':'' ?>">
            <button class="nav-link nav-drop-toggle"><i class="fas fa-coins"></i> Finance <i class="fas fa-chevron-down nav-caret"></i></button>
            <div class="nav-drop-menu">
                <a href="loans-payable.php"    class="<?= $cur==='loans-payable'    ?'active':'' ?>"><i class="fas fa-arrow-up"></i> Loan Payable</a>
                <a href="loans-receivable.php" class="<?= $cur==='loans-receivable' ?'active':'' ?>"><i class="fas fa-arrow-down"></i> Loan Receivable</a>
                <a href="accounts.php"         class="<?= $cur==='accounts'         ?'active':'' ?>"><i class="fas fa-building-columns"></i> Accounts</a>
                <a href="expenses.php"         class="<?= $cur==='expenses'         ?'active':'' ?>"><i class="fas fa-receipt"></i> Expenses</a>
                <a href="activate.php"        class="<?= $cur==='activate'         ?'active':'' ?>"><i class="fas fa-unlock"></i> Activate License</a>
            </div>
        </div>

        <div class="nav-drop <?= in_array($cur,['journal','manual_journal','reports','audit_log'])?'active':'' ?>">
            <button class="nav-link nav-drop-toggle"><i class="fas fa-chart-bar"></i> Analytics <i class="fas fa-chevron-down nav-caret"></i></button>
            <div class="nav-drop-menu">
                <a href="journal.php"        class="<?= $cur==='journal'        ?'active':'' ?>"><i class="fas fa-book-open"></i> Automatic Journal</a>
                <a href="manual_journal.php" class="<?= $cur==='manual_journal' ?'active':'' ?>"><i class="fas fa-pen-to-square"></i> Manual Journal</a>
                <a href="reports.php"        class="<?= $cur==='reports'        ?'active':'' ?>"><i class="fas fa-chart-bar"></i> Reports</a>
                <a href="audit_log.php"      class="<?= $cur==='audit_log'      ?'active':'' ?>"><i class="fas fa-scroll"></i> Audit Log</a>
            </div>
        </div>

        <?php if(isset($_SESSION['role']) && in_array($_SESSION['role'],['admin','superadmin'])): ?>
        <div class="nav-drop <?= in_array($cur,['users','settings','run_update','backup','subscriptions'])?'active':'' ?>">
            <button class="nav-link nav-drop-toggle"><i class="fas fa-shield-halved"></i> Admin <i class="fas fa-chevron-down nav-caret"></i></button>
            <div class="nav-drop-menu">
                <a href="users.php"         class="<?= $cur==='users'          ?'active':'' ?>"><i class="fas fa-users"></i> Users</a>
                <a href="settings.php"      class="<?= $cur==='settings'       ?'active':'' ?>"><i class="fas fa-sliders"></i> Settings</a>
                <?php if(isset($_SESSION['role']) && $_SESSION['role'] === 'superadmin'): ?>
                    <a href="subscriptions.php" class="<?= $cur==='subscriptions'  ?'active':'' ?>"><i class="fas fa-key"></i> Subscription</a>
                <?php endif; ?>
                <a href="run_updates.php"><i class="fas fa-gear"></i> Run Updates</a>
                <a href="backup.php"        class="<?= $cur==='backup'         ?'active':'' ?>"><i class="fas fa-database"></i> Backup</a>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <button class="topnav-toggle" onclick="toggleTopnav()" aria-label="Menu"><i class="fas fa-bars"></i></button>

    <div class="topnav-user">
        <div class="user-avatar"><?= strtoupper(substr($_SESSION['username']??'U',0,1)) ?></div>
        <div class="user-info">
            <div class="u-name"><?= htmlspecialchars($_SESSION['username']??'User') ?></div>
            <div class="u-role"><?= $_SESSION['role']??'' ?></div>
        </div>
        <a href="logout.php" class="user-logout" title="Logout"><i class="fas fa-right-from-bracket"></i></a>
    </div>
</nav>

<div class="quickbar">
    <a href="new-purchase.php" class="qb-item <?= $cur==='new-purchase' ?'qb-active':'' ?>"><i class="fas fa-boxes-stacked"></i> New Purchase</a>
    <a href="new-sales.php"    class="qb-item qb-green <?= $cur==='new-sales'    ?'qb-active':'' ?>"><i class="fas fa-chart-line"></i> New Sale</a>
    <a href="expenses.php"     class="qb-item qb-red <?= $cur==='expenses'       ?'qb-active':'' ?>"><i class="fas fa-receipt"></i> Expenses</a>
    <div class="qb-divider"></div>
    <a href="accounts.php"     class="qb-item qb-purple <?= $cur==='accounts'    ?'qb-active':'' ?>"><i class="fas fa-building-columns"></i> Accounts</a>
    <a href="buyers.php"       class="qb-item qb-amber <?= $cur==='buyers'       ?'qb-active':'' ?>"><i class="fas fa-handshake"></i> Buyers</a>
    <a href="supply-stock.php" class="qb-item qb-amber <?= $cur==='supply-stock' ?'qb-active':'' ?>"><i class="fas fa-warehouse"></i> Supplier Stock</a>
    <div class="qb-divider"></div>
    <a href="journal.php"        class="qb-item qb-cyan <?= $cur==='journal'        ?'qb-active':'' ?>"><i class="fas fa-book-open"></i> Automatic Journal</a>
    <a href="manual_journal.php" class="qb-item qb-cyan <?= $cur==='manual_journal' ?'qb-active':'' ?>"><i class="fas fa-pen-to-square"></i> Manual Journal</a>
    <!-- <a href="reports.php"        class="qb-item qb-cyan <?= $cur==='reports'        ?'qb-active':'' ?>"><i class="fas fa-chart-bar"></i> Reports</a> -->
    <?php if(isset($_SESSION['role']) && in_array($_SESSION['role'],['superadmin'])): ?>
    <div class="qb-divider"></div>
    <a href="subscriptions.php" class="qb-item <?= $cur==='subscriptions' ?'qb-active':'' ?>" style="color:#7c3aed"><i class="fas fa-key"></i> Subscription</a>
    <?php endif; ?>
    <a href="#" class="qb-item" id="ip-copy-btn" onclick="copyServerIp(event)" title="Click to copy app URL"><i class="fas fa-copy"></i> IP:<?= $_nav_server_ip ?></a>
</div>

<div class="main-wrapper">
    <header class="topbar">
        <h1 class="topbar-title"><?= htmlspecialchars($page_title??'') ?></h1>
        <span class="topbar-meta"><i class="fas fa-calendar-days" style="margin-right:.35rem"></i><?= date('d M Y') ?></span>
    </header>
    <main class="page-content">
<script>
function toggleTopnav() {
    document.getElementById('topnav-links').classList.toggle('open');
}
function copyServerIp(e) {
    e.preventDefault();
    var url = window.location.protocol + '//<?= $_nav_server_ip ?>' + '<?= rtrim(dirname($_SERVER['PHP_SELF']), '/\\') ?>/';
    var btn = document.getElementById('ip-copy-btn');
    navigator.clipboard.writeText(url).then(function() {
        var original = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> Copied!';
        setTimeout(function(){ btn.innerHTML = original; }, 2000);
    });
}
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('.nav-drop-toggle').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            e.stopPropagation();
            var drop = this.closest('.nav-drop');
            var isOpen = drop.classList.contains('open');
            document.querySelectorAll('.nav-drop').forEach(function(d){ d.classList.remove('open'); });
            if (!isOpen) drop.classList.add('open');
        });
    });
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.nav-drop')) {
            document.querySelectorAll('.nav-drop').forEach(function(d){ d.classList.remove('open'); });
        }
    });
});
</script>
