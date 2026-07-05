<?php
/**
 * One-time seed: creates the system owner and company admin users if they
 * don't already exist. Run once from the browser, then delete this file.
 */
require_once 'config/database.php';

$seeds = [
    [
        'username'  => 'system',
        'password'  => 'system123',
        'full_name' => 'System Owner',
        'email'     => 'system@mdms.local',
        'role'      => 'system',
    ],
    [
        'username'  => 'admin',
        'password'  => 'admin123',
        'full_name' => 'Company Admin',
        'email'     => 'admin@mdms.local',
        'role'      => 'admin',
    ],
];

$results = [];
foreach($seeds as $s){
    $chk = $pdo->prepare("SELECT id FROM users WHERE username = ?");
    $chk->execute([$s['username']]);
    if($chk->fetch()){
        $results[] = ['user'=>$s['username'],'role'=>$s['role'],'status'=>'skipped','note'=>'Already exists'];
    } else {
        $hash = password_hash($s['password'], PASSWORD_DEFAULT);
        $pdo->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?,?,?,?,?)")
            ->execute([$s['username'], $hash, $s['full_name'], $s['email'], $s['role']]);
        $results[] = ['user'=>$s['username'],'role'=>$s['role'],'password'=>$s['password'],'status'=>'created'];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Seed Users — MDMS</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="css/vendor/fontawesome/all.min.css">
</head>
<body style="min-height:100vh;display:flex;align-items:center;justify-content:center;background:var(--bg,#f1f5f9)">
<div style="max-width:500px;width:100%;margin:2rem;font-family:system-ui,sans-serif">

    <div style="background:var(--surface,#fff);border:1px solid var(--border,#e2e8f0);border-radius:12px;overflow:hidden">
        <div style="padding:1.1rem 1.25rem;border-bottom:1px solid var(--border,#e2e8f0);display:flex;align-items:center;gap:.6rem">
            <i class="fas fa-seedling" style="color:#16a34a"></i>
            <span style="font-weight:700;font-size:.95rem">User Seed Results</span>
        </div>
        <div style="padding:1rem 1.25rem;display:flex;flex-direction:column;gap:.65rem">
            <?php foreach($results as $r): $ok = $r['status']==='created'; ?>
            <div style="border:1px solid <?= $ok?'#bbf7d0':'#e2e8f0' ?>;border-radius:8px;padding:.75rem 1rem;background:<?= $ok?'#f0fdf4':'var(--bg,#f8fafc)' ?>">
                <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:<?= $ok?'.45rem':'0' ?>">
                    <div style="display:flex;align-items:center;gap:.5rem">
                        <i class="fas fa-<?= $ok?'circle-check':'circle-minus' ?>" style="color:<?= $ok?'#16a34a':'#94a3b8' ?>"></i>
                        <strong style="font-size:.88rem"><?= htmlspecialchars($r['user']) ?></strong>
                        <span style="font-size:.74rem;padding:.1rem .45rem;border-radius:99px;background:<?= $r['role']==='system'?'#1e40af18':'#16a34a18' ?>;color:<?= $r['role']==='system'?'#1e40af':'#15803d' ?>;font-weight:600"><?= htmlspecialchars($r['role']) ?></span>
                    </div>
                    <span style="font-size:.78rem;color:<?= $ok?'#16a34a':'#94a3b8' ?>;font-weight:600"><?= $ok?'Created':'Skipped' ?></span>
                </div>
                <?php if($ok): ?>
                <div style="font-size:.8rem;color:#374151;display:flex;align-items:center;gap:.4rem;font-family:monospace;background:#fff;border:1px solid #d1fae5;border-radius:5px;padding:.3rem .6rem">
                    <i class="fas fa-key" style="color:#6b7280;font-size:.7rem"></i>
                    Password: <strong><?= htmlspecialchars($r['password']) ?></strong>
                </div>
                <?php else: ?>
                <div style="font-size:.78rem;color:#94a3b8;margin-top:.2rem"><?= htmlspecialchars($r['note']) ?></div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <div style="padding:.85rem 1.25rem;border-top:1px solid var(--border,#e2e8f0);background:#fffbeb;display:flex;gap:.5rem;align-items:flex-start">
            <i class="fas fa-triangle-exclamation" style="color:#d97706;margin-top:.1rem;flex-shrink:0"></i>
            <span style="font-size:.8rem;color:#92400e;line-height:1.5">
                <strong>Delete this file after use.</strong> It creates users without authentication and should not remain on a production server.
            </span>
        </div>
    </div>

    <div style="margin-top:1rem;display:flex;gap:.75rem;justify-content:center">
        <a href="login.php" style="display:inline-flex;align-items:center;gap:.4rem;padding:.55rem 1.1rem;background:#2563eb;color:#fff;border-radius:8px;text-decoration:none;font-size:.85rem;font-weight:600">
            <i class="fas fa-right-to-bracket"></i> Go to Login
        </a>
    </div>

</div>
</body>
</html>
