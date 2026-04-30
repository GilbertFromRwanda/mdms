<?php
require_once 'config/database.php';
if (!isLoggedIn() || !hasRole('admin')) {
    header('Location: dashboard.php'); exit;
}

/* ── trigger download ───────────────────────────────────────────── */
if (isset($_POST['download'])) {
    $filename = 'mdms_backup_' . date('Ymd_His') . '.sql';

    // fetch all tables
    $tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    ob_start();
    echo "-- MDMS Database Backup\n";
    echo "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    echo "-- Database: minerals_depot\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n\n";

    foreach ($tables as $table) {
        // structure
        $row = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        echo "-- ----------------------------\n";
        echo "-- Table: $table\n";
        echo "-- ----------------------------\n";
        echo "DROP TABLE IF EXISTS `$table`;\n";
        echo $row[1] . ";\n\n";

        // data
        $rows = $pdo->query("SELECT * FROM `$table`")->fetchAll(PDO::FETCH_NUM);
        if ($rows) {
            $cols = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
            $colList = implode('`, `', $cols);
            foreach ($rows as $r) {
                $vals = array_map(function($v) use ($pdo) {
                    return $v === null ? 'NULL' : $pdo->quote($v);
                }, $r);
                echo "INSERT INTO `$table` (`$colList`) VALUES (" . implode(', ', $vals) . ");\n";
            }
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    $sql = ob_get_clean();

    logAction($pdo, $_SESSION['user_id'], 'BACKUP', 'database', 0, 'Full DB backup downloaded');

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($sql));
    echo $sql;
    exit;
}

/* ── stats for display ──────────────────────────────────────────── */
$tables  = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
$dbSize  = 0;
$tableStats = [];
foreach ($tables as $t) {
    $s = $pdo->query("SHOW TABLE STATUS LIKE '$t'")->fetch(PDO::FETCH_ASSOC);
    $size = ($s['Data_length'] ?? 0) + ($s['Index_length'] ?? 0);
    $rows = (int)($s['Rows'] ?? 0);
    $tableStats[] = ['name' => $t, 'rows' => $rows, 'size' => $size];
    $dbSize += $size;
}

$page_title = 'Database Backup';
require 'includes/header.php';
?>

<div class="page-card" style="max-width:860px;margin:0 auto">

    <div class="card-header" style="display:flex;align-items:center;gap:.75rem">
        <i class="fas fa-database" style="font-size:1.3rem;color:var(--accent)"></i>
        <div>
            <h2 style="margin:0;font-size:1.1rem">Database Backup</h2>
            <p style="margin:0;font-size:.8rem;color:var(--muted)">
                <?= count($tables) ?> tables &nbsp;·&nbsp;
                <?= $dbSize >= 1048576
                    ? number_format($dbSize/1048576,2).' MB'
                    : number_format($dbSize/1024,1).' KB' ?>
                total size
            </p>
        </div>
        <form method="post" style="margin-left:auto">
            <button name="download" class="btn btn-primary" style="display:flex;align-items:center;gap:.5rem">
                <i class="fas fa-download"></i> Download .sql
            </button>
        </form>
    </div>

    <div style="padding:1.25rem">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Table</th>
                    <th style="text-align:right">Rows</th>
                    <th style="text-align:right">Size</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tableStats as $i => $t): ?>
                <tr>
                    <td class="muted"><?= $i+1 ?></td>
                    <td><i class="fas fa-table" style="color:var(--accent);margin-right:.4rem;font-size:.8rem"></i><?= htmlspecialchars($t['name']) ?></td>
                    <td style="text-align:right"><?= number_format($t['rows']) ?></td>
                    <td style="text-align:right" class="muted">
                        <?= $t['size'] >= 1048576
                            ? number_format($t['size']/1048576,2).' MB'
                            : number_format($t['size']/1024,1).' KB' ?>
                    </td>
                </tr>
                <?php endforeach ?>
            </tbody>
        </table>

        <p style="margin-top:1rem;font-size:.8rem;color:var(--muted)">
            <i class="fas fa-info-circle"></i>
            The downloaded file is a full SQL dump — structure + data for every table.
            Store it in a secure location.
        </p>
    </div>
</div>

<?php require 'includes/footer.php'; ?>
