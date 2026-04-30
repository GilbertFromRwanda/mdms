<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

$page_title = 'Inventory';

$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'status' => $_GET['status']      ?? '',
    'unit'   => $_GET['unit']        ?? '',
];

$where = []; $params = [];
if($filters['search']) { $where[] = 'mt.name LIKE ?'; $params[] = '%'.$filters['search'].'%'; }
if($filters['unit'])   { $where[] = 'mt.unit = ?';    $params[] = $filters['unit']; }
if($filters['status'] === 'low')    { $where[] = 'i.current_stock < 100'; }
if($filters['status'] === 'medium') { $where[] = 'i.current_stock >= 100 AND i.current_stock < 500'; }
if($filters['status'] === 'good')   { $where[] = 'i.current_stock >= 500'; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT i.*, mt.name AS mineral_name, mt.unit
    FROM inventory i
    JOIN mineral_types mt ON i.mineral_type_id = mt.id
    $where_sql
    ORDER BY i.current_stock DESC
");
$stmt->execute($params);
$inventory = $stmt->fetchAll();

$units_raw = $pdo->query("SELECT DISTINCT unit FROM mineral_types ORDER BY unit")->fetchAll(PDO::FETCH_COLUMN);

$has_filters = (bool)array_filter($filters);
$maxStock = count($inventory) ? max(array_column($inventory,'current_stock')) : 1;
if($maxStock == 0) $maxStock = 1;

include 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-warehouse" style="margin-right:.4rem;color:var(--text-muted)"></i>Stock Levels</h2>
    <span class="text-muted" style="font-size:.82rem"><?= count($inventory) ?> mineral type<?= count($inventory)!==1?'s':'' ?></span>
</div>

<form method="GET" action="inventory.php" class="filter-bar">
    <div class="filter-group">
        <label>Search</label>
        <input type="search" name="search" placeholder="Mineral name…"
               value="<?= htmlspecialchars($filters['search']) ?>">
    </div>
    <div class="filter-group">
        <label>Status</label>
        <select name="status">
            <option value="">All statuses</option>
            <option value="low"    <?= $filters['status']==='low'    ?'selected':'' ?>>Low (&lt; 100)</option>
            <option value="medium" <?= $filters['status']==='medium' ?'selected':'' ?>>Medium (100–499)</option>
            <option value="good"   <?= $filters['status']==='good'   ?'selected':'' ?>>Good (≥ 500)</option>
        </select>
    </div>
    <?php if(count($units_raw) > 1): ?>
    <div class="filter-group">
        <label>Unit</label>
        <select name="unit">
            <option value="">All units</option>
            <?php foreach($units_raw as $u): ?>
            <option value="<?= htmlspecialchars($u) ?>" <?= $filters['unit']===$u?'selected':'' ?>>
                <?= htmlspecialchars($u) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <?php endif; ?>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-filter"></i> Filter
        </button>
        <?php if($has_filters): ?>
        <a href="inventory.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </div>
    <?php if($has_filters): ?>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> Filtered — <?= count($inventory) ?> result<?= count($inventory)!==1?'s':'' ?></span>
    <?php endif; ?>
</form>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Mineral Type</th>
                <th>Current Stock</th>
                <th>Unit</th>
                <th style="min-width:160px">Level</th>
                <th>Status</th>
                <th>Last Updated</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach($inventory as $i => $item):
                $pct = round(($item['current_stock']/$maxStock)*100);
                $cls = $item['current_stock']<100 ? 'low' : ($item['current_stock']<500 ? 'medium' : '');
                $isLow = $item['current_stock'] < 100;
            ?>
            <tr>
                <td class="text-muted"><?= $i+1 ?></td>
                <td class="fw-700"><?= htmlspecialchars($item['mineral_name']) ?></td>
                <td class="<?= $isLow ? 'low-stock' : '' ?> fw-600">
                    <?= number_format($item['current_stock'],2) ?>
                </td>
                <td class="text-muted"><?= htmlspecialchars($item['unit']) ?></td>
                <td>
                    <div class="stock-bar-wrap">
                        <div class="stock-bar">
                            <div class="stock-bar-fill <?= $cls ?>" style="width:<?= $pct ?>%"></div>
                        </div>
                        <span class="stock-pct"><?= $pct ?>%</span>
                    </div>
                </td>
                <td>
                    <?php if($isLow): ?>
                        <span class="badge badge-danger"><i class="fas fa-triangle-exclamation" style="margin-right:.2rem"></i>Low</span>
                    <?php elseif($cls==='medium'): ?>
                        <span class="badge badge-warning">Medium</span>
                    <?php else: ?>
                        <span class="badge badge-success">Good</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted font-mono" style="font-size:.78rem"><?= $item['last_updated'] ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php include 'includes/footer.php'; ?>
