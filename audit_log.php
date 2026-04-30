<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

$page_title = 'Audit Log';

$logs = $pdo->query("
    SELECT al.*, u.username, u.full_name
    FROM audit_log al
    LEFT JOIN users u ON al.user_id = u.id
    ORDER BY al.created_at DESC
    LIMIT 200
")->fetchAll();

include 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-scroll" style="margin-right:.4rem;color:var(--text-muted)"></i>System Audit Log</h2>
    <span class="text-muted" style="font-size:.82rem">Last <?= count($logs) ?> entries</span>
</div>

<p class="text-muted mb-15" style="font-size:.855rem">
    <i class="fas fa-circle-info" style="margin-right:.35rem"></i>
    All user actions are recorded for compliance and traceability.
</p>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>Timestamp</th>
                <th>User</th>
                <th>Action</th>
                <th>Table</th>
                <th>Record ID</th>
                <th>Details</th>
                <th>IP Address</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($logs as $log): ?>
            <tr>
                <td class="font-mono text-muted" style="font-size:.78rem;white-space:nowrap"><?= $log['created_at'] ?></td>
                <td>
                    <div class="fw-600" style="font-size:.82rem"><?= htmlspecialchars($log['username']??'System') ?></div>
                    <?php if($log['full_name']): ?>
                    <div class="text-muted" style="font-size:.72rem"><?= htmlspecialchars($log['full_name']) ?></div>
                    <?php endif; ?>
                </td>
                <td>
                    <?php
                    $ac = match($log['action']){
                        'CREATE' => 'badge-success',
                        'DELETE' => 'badge-danger',
                        'UPDATE' => 'badge-warning',
                        default  => 'badge-info'
                    };
                    ?>
                    <span class="badge <?= $ac ?>"><?= htmlspecialchars($log['action']) ?></span>
                </td>
                <td class="font-mono" style="font-size:.8rem"><?= htmlspecialchars($log['table_name']) ?></td>
                <td class="text-muted font-mono" style="font-size:.8rem"><?= $log['record_id'] ?></td>
                <td style="max-width:280px;font-size:.82rem;color:var(--text-sec)"><?= htmlspecialchars($log['details']) ?></td>
                <td class="font-mono text-muted" style="font-size:.78rem"><?= htmlspecialchars($log['ip_address']) ?></td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$logs): ?>
            <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No audit entries found.</td></tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
