<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── Page data ───────────────────────────────────────────────── */
$flash_count = isset($_GET['created']) ? (int)$_GET['created'] : 0;
$page_title  = 'Sales';

$minerals = $pdo->query("SELECT * FROM mineral_types ORDER BY name")->fetchAll();
$buyers   = $pdo->query("SELECT * FROM buyers ORDER BY name")->fetchAll();

/* ── Filters & pagination ───────────────────────────────────── */
$filters = [
    'mineral_type_id' => $_GET['mineral_type_id'] ?? '',
    'buyer_id'        => $_GET['buyer_id']         ?? '',
    'date_from'       => $_GET['date_from']        ?? date('Y-m-01'),
    'date_to'         => $_GET['date_to']          ?? date('Y-m-t'),
];
$per_page = 20;
$page     = max(1, intval($_GET['page'] ?? 1));

$where = []; $params = [];
if($filters['mineral_type_id']) { $where[] = 's.mineral_type_id=?'; $params[] = $filters['mineral_type_id']; }
if($filters['buyer_id'])        { $where[] = 's.buyer_id=?';        $params[] = $filters['buyer_id']; }
if($filters['date_from'])       { $where[] = 's.sale_date>=?';      $params[] = $filters['date_from']; }
if($filters['date_to'])         { $where[] = 's.sale_date<=?';      $params[] = $filters['date_to']; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$count_s = $pdo->prepare("SELECT COUNT(*) FROM sales s $where_sql");
$count_s->execute($params);
$total       = (int)$count_s->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT s.*, mt.name AS mineral_name, b.name AS buyer_name,
           sd.currency_used, sd.selling_price, sd.cost_price,
           sd.total_revenue, sd.total_cost, sd.benefit,
           u.username AS created_by_name
    FROM sales s
    JOIN mineral_types mt ON s.mineral_type_id = mt.id
    JOIN buyers b         ON s.buyer_id        = b.id
    LEFT JOIN sale_details sd ON sd.sale_id    = s.id
    LEFT JOIN users u         ON u.id          = s.created_by
    $where_sql
    ORDER BY s.id DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$sales = $stmt->fetchAll();

/* ── Summary totals (same filters) ─────────────────────────── */
$sum_s = $pdo->prepare("
    SELECT
        COUNT(*)                              AS sale_count,
        COUNT(DISTINCT s.buyer_id)            AS buyer_count,
        COALESCE(SUM(s.quantity),0)           AS total_qty,
        COALESCE(SUM(sd.total_revenue),0)     AS total_revenue,
        COALESCE(SUM(sd.total_cost),0)        AS total_cost,
        COALESCE(SUM(sd.benefit),0)           AS total_benefit
    FROM sales s
    LEFT JOIN sale_details sd ON sd.sale_id = s.id
    $where_sql
");
$sum_s->execute($params);
$totals = $sum_s->fetch(PDO::FETCH_ASSOC);

$has_filters = (bool)array_filter($filters);
$margin_pct  = $totals['total_revenue'] > 0
    ? round($totals['total_benefit'] / $totals['total_revenue'] * 100, 1)
    : 0;

include 'includes/header.php';
?>

<?php if($flash_count > 0): ?>
<div class="alert alert-success mb-15">
    <i class="fas fa-circle-check"></i>
    <?= $flash_count ?> sale<?= $flash_count > 1 ? 's' : '' ?> registered successfully.
</div>
<?php endif; ?>

<div class="page-header">
    <h2><i class="fas fa-chart-line" style="margin-right:.4rem;color:var(--text-muted)"></i>Sales Register</h2>
    <a href="new-sales.php" class="btn btn-primary" style="background:#10b981;border-color:#10b981">
        <i class="fas fa-plus"></i> New Sale
    </a>
</div>

<!-- Filter bar -->
<form method="GET" action="sales.php" class="filter-bar">
    <div class="filter-group">
        <label>Mineral</label>
        <select name="mineral_type_id">
            <option value="">All minerals</option>
            <?php foreach($minerals as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $filters['mineral_type_id']==$m['id']?'selected':'' ?>>
                <?= htmlspecialchars($m['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Buyer</label>
        <select name="buyer_id">
            <option value="">All buyers</option>
            <?php foreach($buyers as $b): ?>
            <option value="<?= $b['id'] ?>" <?= $filters['buyer_id']==$b['id']?'selected':'' ?>>
                <?= htmlspecialchars($b['name']) ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($filters['date_from']) ?>">
    </div>
    <div class="filter-group">
        <label>To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($filters['date_to']) ?>">
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem;background:#10b981;border-color:#10b981">
            <i class="fas fa-filter"></i> Filter
        </button>
        <?php if($has_filters): ?>
        <a href="sales.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </div>
    <?php if($has_filters): ?>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> Filtered — <?= $total ?> result<?= $total!==1?'s':'' ?></span>
    <?php endif; ?>
</form>

<?php $fmt = fn($v,$d=2) => number_format($v,$d); ?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.75rem;margin-bottom:1rem">
    <?php
    $cards = [
        ['fas fa-receipt',        'Sales',          $totals['sale_count'],             '',        '#10b981'],
        ['fas fa-users',          'Buyers',          $totals['buyer_count'],            '',        '#8b5cf6'],
        ['fas fa-weight-hanging', 'Total Qty',       $fmt($totals['total_qty'],3),     ' kg',     '#3b82f6'],
        ['fas fa-arrow-up-right-dots', 'Total Revenue', $fmt($totals['total_revenue']), ' FRW',   '#f59e0b'],
        ['fas fa-arrow-down-right','Total Cost',     $fmt($totals['total_cost']),      ' FRW',    '#dc2626'],
        ['fas fa-sack-dollar',    'Net Benefit',     $fmt($totals['total_benefit']),   ' FRW',    '#10b981'],
        ['fas fa-percent',        'Avg Margin',      $margin_pct,                       '%',       '#6366f1'],
    ];
    foreach($cards as [$icon,$label,$value,$unit,$color]): ?>
    <div style="border:1px solid var(--border);border-left:4px solid <?= $color ?>;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.3rem;display:flex;align-items:center;gap:.4rem">
            <i class="<?= $icon ?>" style="color:<?= $color ?>"></i> <?= $label ?>
        </div>
        <div style="font-size:1.05rem;font-weight:700;color:<?= $color ?>;line-height:1.2">
            <?= $value ?><span style="font-size:.75rem;font-weight:400;color:var(--text-muted)"><?= $unit ?></span>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th style="width:2rem"></th>
                <th>#</th>
                 <th>Date</th>
                <th>Mineral</th>
                <th>Buyer</th>
                <th style="text-align:right">Qty (kg)</th>
                <th style="text-align:right">Sell Price/kg</th>
                <th style="text-align:right">Cost Price/kg</th>
                <th style="text-align:right">Benefit</th>
               
                <th>Created By</th>
            </tr>
        </thead>
        <tbody id="sales-tbody">
            <?php
            
            $i = 0;
            foreach($sales as $row):
                $has_detail = $row['benefit'] !== null;
                $cur        = $row['currency_used'] ?? 'FRW';
                $margin     = ($row['total_revenue'] > 0)
                    ? round($row['benefit'] / $row['total_revenue'] * 100, 1)
                    : 0;
            ?>
            <tr style="<?= $has_detail ? 'cursor:pointer' : '' ?>"
                <?= $has_detail ? 'onclick="toggleDetail('.$row['id'].')"' : '' ?>>
                <td style="text-align:center;width:2rem">
                    <?php if($has_detail): ?>
                    <i class="fas fa-chevron-right" id="icon-<?= $row['id'] ?>"
                       style="font-size:.72rem;color:var(--text-muted);transition:transform .18s"></i>
                    <?php endif; ?>
                </td>
                <td class="font-mono fw-600" style="font-size:.82rem"><?= ++$i ?></td>
                 <td class="text-muted"><?= $row['sale_date'] ?></td>
                <td><?= htmlspecialchars($row['mineral_name']) ?></td>
                <td><?= htmlspecialchars($row['buyer_name']) ?></td>
                <td style="text-align:right" class="fw-600"><?= number_format($row['quantity'],3) ?></td>
                <td style="text-align:right">
                    <?php if($row['selling_price'] !== null): ?>
                        <?= number_format($row['selling_price'],2) ?>
                        <small class="text-muted"><?= $cur ?></small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right">
                    <?php if($row['cost_price'] !== null): ?>
                        <?= number_format($row['cost_price'],2) ?>
                        <small class="text-muted"><?= $cur ?></small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;font-weight:700;color:#10b981">
                    <?php if($row['benefit'] !== null): ?>
                        <?= number_format($row['benefit'],2) ?>
                        <small style="font-weight:400;color:var(--text-muted)"><?= $cur ?></small>
                    <?php else: ?>
                        <span class="text-muted" style="font-weight:400">—</span>
                    <?php endif; ?>
                </td>
               
                <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($row['created_by_name'] ?? '—') ?></td>
            </tr>
            <?php if($has_detail): ?>
            <tr id="detail-<?= $row['id'] ?>" style="display:none;background:rgba(16,185,129,.04)">
                <td colspan="10" style="padding:.6rem 1rem .75rem 3.5rem;border-top:none">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.35rem .9rem;font-size:.82rem">
                        <?php if($row['total_revenue'] !== null): ?>
                        <div><span class="text-muted">Total Revenue:</span>
                             <strong><?= number_format($row['total_revenue'],2) ?> <?= $cur ?></strong></div>
                        <?php endif; ?>
                        <?php if($row['total_cost'] !== null): ?>
                        <div><span class="text-muted">Total Cost:</span>
                             <strong><?= number_format($row['total_cost'],2) ?> <?= $cur ?></strong></div>
                        <?php endif; ?>
                        <?php if($row['benefit'] !== null): ?>
                        <div><span class="text-muted">Benefit:</span>
                             <strong style="color:#10b981"><?= number_format($row['benefit'],2) ?> <?= $cur ?></strong></div>
                        <?php endif; ?>
                        <div><span class="text-muted">Margin:</span>
                             <strong><?= $margin ?>%</strong></div>
                        <div><span class="text-muted">Currency:</span>
                             <strong><?= htmlspecialchars($cur) ?></strong></div>
                        <?php if($row['notes']): ?>
                        <div style="grid-column:1/-1"><span class="text-muted">Notes:</span>
                             <?= htmlspecialchars($row['notes']) ?></div>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if(!$sales): ?>
            <tr id="empty-row"><td colspan="10" style="text-align:center;padding:2rem;color:var(--text-muted)">
                <?= $has_filters ? 'No sales match the current filters.' : 'No sales found.' ?>
            </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if($total_pages > 1): ?>
    <?= paginate($page, $total_pages, $filters, 'sales.php') ?>
    <p class="pagination-info" style="padding-bottom:.5rem">
        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> of <?= $total ?> sales
    </p>
    <?php endif; ?>
</div>

<script>
function toggleDetail(id) {
    const row  = document.getElementById('detail-' + id);
    const icon = document.getElementById('icon-'   + id);
    if (!row) return;
    const open = row.style.display !== 'none';
    row.style.display       = open ? 'none' : '';
    if (icon) icon.style.transform = open ? '' : 'rotate(90deg)';
}
</script>

<?php include 'includes/footer.php'; ?>
