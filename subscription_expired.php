<?php
require_once 'config/database.php';

$sub = null;
try { $sub = $pdo->query("SELECT * FROM subscription WHERE is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch (PDOException $e) {}

$is_admin  = isset($_SESSION['user_id']) && ($_SESSION['role'] ?? '') === 'superadmin';
$is_logged = isset($_SESSION['user_id']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Subscription Expired — MDMS</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/vendor/fontawesome/all.min.css">
</head>
<body style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg,#f1f5f9)">

<div style="max-width:460px;width:100%;margin:2rem;text-align:center;font-family:system-ui,sans-serif">

    <div style="width:72px;height:72px;border-radius:50%;background:#fef2f2;display:flex;align-items:center;justify-content:center;margin:0 auto 1.25rem">
        <i class="fas fa-lock" style="font-size:2rem;color:#dc2626"></i>
    </div>

    <h1 style="font-size:1.45rem;font-weight:700;color:var(--text,#1e293b);margin-bottom:.45rem">Subscription Expired</h1>
    <p style="color:var(--text-muted,#64748b);font-size:.88rem;margin-bottom:1.5rem;line-height:1.6">
        Access to <strong>Minerals Depot Management System</strong> has been restricted because the subscription has expired.
    </p>

    <?php if($sub): ?>
    <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:10px;padding:1rem 1.25rem;margin-bottom:1.5rem;text-align:left">
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;border-bottom:1px solid var(--border,#e2e8f0)">
            <span style="font-size:.8rem;color:var(--text-muted,#64748b)">Client</span>
            <span style="font-size:.84rem;font-weight:600;color:var(--text,#1e293b)"><?= htmlspecialchars($sub['client_name']) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;border-bottom:1px solid var(--border,#e2e8f0)">
            <span style="font-size:.8rem;color:var(--text-muted,#64748b)">Plan</span>
            <span style="font-size:.84rem;font-weight:600;color:var(--text,#1e293b)"><?= htmlspecialchars($sub['plan_name']) ?></span>
        </div>
        <div style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0">
            <span style="font-size:.8rem;color:var(--text-muted,#64748b)">Expired on</span>
            <span style="font-size:.84rem;font-weight:700;color:#dc2626"><?= date('d M Y', strtotime($sub['expiry_date'])) ?></span>
        </div>
    </div>
    <?php endif; ?>

    <p style="font-size:.83rem;color:var(--text-muted,#64748b);margin-bottom:1.5rem;line-height:1.6">
        Please contact your system administrator to renew the subscription.
        <?php if($sub && $sub['client_email']): ?>
        <br><a href="mailto:<?= htmlspecialchars($sub['client_email']) ?>" style="color:#2563eb"><?= htmlspecialchars($sub['client_email']) ?></a>
        <?php endif; ?>
    </p>

    <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
        <?php if($is_logged): ?>
        <a href="activate.php" style="display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.2rem;background:#16a34a;color:#fff;border-radius:8px;text-decoration:none;font-size:.88rem;font-weight:600">
            <i class="fas fa-key"></i> Activate License
        </a>
        <?php endif; ?>
        <?php if($is_admin): ?>
        <a href="subscriptions.php" style="display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.2rem;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-size:.88rem;font-weight:600">
            <i class="fas fa-rotate"></i> Manage Subscription
        </a>
        <?php elseif($is_logged): ?>
        <a href="logout.php" style="display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.2rem;background:#6b7280;color:#fff;border-radius:8px;text-decoration:none;font-size:.88rem;font-weight:600">
            <i class="fas fa-right-from-bracket"></i> Logout
        </a>
        <?php else: ?>
        <a href="login.php" style="display:inline-flex;align-items:center;gap:.5rem;padding:.6rem 1.2rem;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-size:.88rem;font-weight:600">
            <i class="fas fa-right-to-bracket"></i> Admin Login
        </a>
        <?php endif; ?>
    </div>

</div>
</body>
</html>
