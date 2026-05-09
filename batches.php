<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── Page data ───────────────────────────────────────────────── */
$flash_count = isset($_GET['created']) ? (int)$_GET['created'] : 0;
$page_title  = 'Batches';

$minerals  = $pdo->query("SELECT * FROM mineral_types ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();

/* ── Filters & pagination ───────────────────────────────────── */
$bid = isset($_GET['bid']) ? (int)$_GET['bid'] : 0;

$filters = [
    'mineral_type_id' => $_GET['mineral_type_id'] ?? '',
    'supplier_id'     => $_GET['supplier_id']     ?? '',
    'date_from'       => $_GET['date_from']       ?? ($bid ? '' : date('Y-m-01')),
    'date_to'         => $_GET['date_to']         ?? ($bid ? '' : date('Y-m-t')),
];
$per_page = 50;
$page     = max(1, intval($_GET['page'] ?? 1));

$where = []; $params = [];
if($bid)                        { $where[] = 'b.id=?';              $params[] = $bid; }
if($filters['mineral_type_id']) { $where[] = 'b.mineral_type_id=?'; $params[] = $filters['mineral_type_id']; }
if($filters['supplier_id'])     { $where[] = 'b.supplier_id=?';     $params[] = $filters['supplier_id']; }
if($filters['date_from'])       { $where[] = 'b.received_date>=?';  $params[] = $filters['date_from']; }
if($filters['date_to'])         { $where[] = 'b.received_date<=?';  $params[] = $filters['date_to']; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$count_s = $pdo->prepare("SELECT COUNT(*) FROM batches b $where_sql");
$count_s->execute($params);
$total       = (int)$count_s->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT b.*, mt.name AS mineral_name, s.name AS supplier_name,
           t.price_per_unit, t.total_amount,
           pd.sample, pd.rwf_rate, pd.fees_1, pd.fees_2,
           pd.tag, pd.rma, pd.rra,
           pd.lma, pd.tmt, pd.tantal,
           pd.unit_price  AS pd_unit_price,
           pd.take_home,  pd.loan_action, pd.loan_amount,
           pd.currency_used AS pd_currency,
           u.username AS created_by_name
    FROM batches b
    JOIN mineral_types mt ON b.mineral_type_id = mt.id
    JOIN suppliers s      ON b.supplier_id     = s.id
    LEFT JOIN transactions t    ON t.batch_id  = b.id AND t.transaction_type = 'IN'
    LEFT JOIN purchase_details pd ON pd.batch_id = b.id
    LEFT JOIN users u             ON u.id        = b.created_by
    $where_sql
    ORDER BY b.id DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$batches = $stmt->fetchAll();

/* ── Payment records for displayed batches ──────────────────── */
$payments_by_batch = [];
if($batches){
    $batch_ids    = array_column($batches, 'id');
    $placeholders = implode(',', array_fill(0, count($batch_ids), '?'));
    $pay_s = $pdo->prepare("
        SELECT pp.batch_id, pp.payment_method, pp.amount, ca.account_name
        FROM purchase_payments pp
        LEFT JOIN company_accounts ca ON ca.id = pp.account_id
        WHERE pp.batch_id IN ($placeholders)
        ORDER BY pp.batch_id, pp.id
    ");
    $pay_s->execute($batch_ids);
    foreach($pay_s->fetchAll() as $pay)
        $payments_by_batch[$pay['batch_id']][] = $pay;
}

/* ── Summary totals (same filters) ─────────────────────────── */
$sum_s = $pdo->prepare("
    SELECT
        COUNT(*)                                                        AS batch_count,
        COUNT(DISTINCT b.supplier_id)                                   AS supplier_count,
        COALESCE(SUM(b.quantity),0)                                     AS total_qty,
        COALESCE(SUM(pd.take_home),0)                                   AS total_take_home,
        COALESCE(SUM(CASE WHEN pd.loan_action='give'   THEN pd.loan_amount ELSE 0 END),0) AS total_loan_given,
        COALESCE(SUM(CASE WHEN pd.loan_action='deduct' THEN pd.loan_amount ELSE 0 END),0) AS total_repaid
    FROM batches b
    LEFT JOIN purchase_details pd ON pd.batch_id = b.id
    $where_sql
");
$sum_s->execute($params);
$totals = $sum_s->fetch(PDO::FETCH_ASSOC);

$has_filters = (bool)array_filter($filters);

include 'includes/header.php';
?>

<?php if($bid): ?>
<div class="alert mb-15" style="background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8;display:flex;align-items:center;gap:.6rem">
    <i class="fas fa-filter"></i>
    Showing batch linked from Loans.
    <a href="batches.php" style="margin-left:auto;color:#1d4ed8;font-weight:600;white-space:nowrap">
        <i class="fas fa-xmark"></i> Clear filter
    </a>
</div>
<?php endif; ?>

<?php if($flash_count > 0): ?>
<div class="alert alert-success mb-15">
    <i class="fas fa-circle-check"></i>
    <?= $flash_count ?> batch<?= $flash_count > 1 ? 'es' : '' ?> registered successfully.
</div>
<?php endif; ?>

<div class="page-header">
    <h2><i class="fas fa-boxes-stacked" style="margin-right:.4rem;color:var(--text-muted)"></i>Purchase / Lot Register</h2>
    <a href="new-purchase.php" class="btn btn-primary">
        <i class="fas fa-plus"></i> New Purchase
    </a>
</div>

<!-- Filter bar -->
<form method="GET" action="batches.php" class="filter-bar">
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
        <label>Supplier</label>
        <select name="supplier_id">
            <option value="">All suppliers</option>
            <?php foreach($suppliers as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $filters['supplier_id']==$s['id']?'selected':'' ?>>
                <?= htmlspecialchars($s['name']) ?>
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
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-filter"></i> Filter
        </button>
        <?php if($has_filters): ?>
        <a href="batches.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </div>
    <?php if($has_filters): ?>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> Filtered — <?= $total ?> result<?= $total!==1?'s':'' ?></span>
    <?php endif; ?>
</form>

<?php
$net_loan = $totals['total_loan_given'] - $totals['total_repaid'];
$fmt = fn($v,$d=2) => number_format($v,$d);
?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.75rem;margin-bottom:1rem">
    <?php
    $cards = [
        ['fas fa-boxes-stacked', 'Batches',          $totals['batch_count'],    '',        'var(--primary)'],
        ['fas fa-users',         'Suppliers',         $totals['supplier_count'], '',        '#8b5cf6'],
        ['fas fa-weight-hanging','Total Qty',         $fmt($totals['total_qty'],3), ' kg',  '#10b981'],
        ['fas fa-coins',         'Total Take Home',   $fmt($totals['total_take_home']), ' FRW', '#f59e0b'],
        ['fas fa-hand-holding-dollar','Loans Given',  $fmt($totals['total_loan_given']), ' FRW', '#16a34a'],
        ['fas fa-rotate-left',   'Repaid',            $fmt($totals['total_repaid']), ' FRW', '#dc2626'],
        ['fas fa-scale-balanced','Net Loan',          $fmt(abs($net_loan)), ' FRW', $net_loan > 0 ? '#f59e0b' : '#6b7280'],
    ];
    foreach($cards as [$icon,$label,$value,$unit,$color]): ?>
    <div style="border:1px solid var(--border);border-left:4px solid <?= $color ?>;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.3rem;display:flex;align-items:center;gap:.4rem">
            <i class="<?= $icon ?>" style="color:<?= $color ?>"></i> <?= $label ?>
        </div>
        <div style="font-size:1.05rem;font-weight:700;color:<?= $color ?>;line-height:1.2">
            <?= $value ?><span style="font-size:.75rem;font-weight:400;color:var(--text-muted)"><?= $unit ?></span>
        </div>
        <?php if($label === 'Net Loan' && $net_loan != 0): ?>
        <div style="font-size:.72rem;color:var(--text-muted);margin-top:.15rem">
            <?= $net_loan > 0 ? 'Outstanding' : 'Overpaid' ?>
        </div>
        <?php endif; ?>
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
                <th>Supplier</th>
                <th style="text-align:right">Qty (kg)</th>
                <th style="text-align:right">Sample</th>
                <th style="text-align:right">Unit Price</th>
                <th style="text-align:right">Take Home</th>
                <th>Loan</th>
                <th>Payment</th>
                <th>Created By</th>
            </tr>
        </thead>
        <tbody id="batches-tbody">
            <?php
            $i = 0;
            foreach($batches as $b):
                $has_detail = $b['take_home'] !== null;
                $cur        = $b['pd_currency'] ?? 'FRW';
                $px_label   = $b['lma']    !== null ? 'LMA'
                            : ($b['tantal'] !== null ? 'TANTAL'
                            : ($b['tmt']    !== null ? 'TMT' : null));
                $px_value   = $b['lma'] ?? $b['tantal'] ?? $b['tmt'];
            ?>
            <tr style="<?= $has_detail ? 'cursor:pointer' : '' ?>"
                <?= $has_detail ? 'onclick="toggleDetail('.$b['id'].')"' : '' ?>>
                <td style="text-align:center;width:2rem">
                    <?php if($has_detail): ?>
                    <i class="fas fa-chevron-right" id="icon-<?= $b['id'] ?>"
                       style="font-size:.72rem;color:var(--text-muted);transition:transform .18s"></i>
                    <?php endif; ?>
                </td>
                <td class="font-mono fw-600" style="font-size:.82rem"><?= ++$i ?></td>
                 <td class="text-muted"><?= $b['received_date'] ?></td>
                <td><?= htmlspecialchars($b['mineral_name']) ?></td>
                <td><?= htmlspecialchars($b['supplier_name']) ?></td>
                <td style="text-align:right" class="fw-600"><?= number_format($b['quantity'],3) ?></td>
                <td style="text-align:right">
                    <?= $b['sample'] !== null ? number_format($b['sample'],4) : '<span class="text-muted">—</span>' ?>
                </td>
                <td style="text-align:right" class="fw-600">
                    <?php if($b['pd_unit_price'] !== null): ?>
                        <?= number_format($b['pd_unit_price'],2) ?>
                        <small class="text-muted"><?= $cur ?></small>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td style="text-align:right;font-weight:700;color:var(--primary)">
                    <?php if($b['take_home'] !== null): ?>
                        <?= number_format($b['take_home'],2) ?>
                        <small style="font-weight:400;color:var(--text-muted)"><?= $cur ?></small>
                    <?php else: ?>
                        <span class="text-muted" style="font-weight:400">—</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($b['loan_action'] === 'give'): ?>
                        <span style="color:#16a34a;font-weight:600;font-size:.8rem">
                            <i class="fas fa-plus-circle"></i> <?= number_format($b['loan_amount'],0) ?> FRW
                        </span>
                    <?php elseif($b['loan_action'] === 'deduct'): ?>
                        <span style="color:#dc2626;font-weight:600;font-size:.8rem">
                            <i class="fas fa-minus-circle"></i> <?= number_format($b['loan_amount'],0) ?> FRW
                        </span>
                    <?php else: ?>
                        <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td style="white-space:nowrap">
                    <?php
                    $bpays = $payments_by_batch[$b['id']] ?? [];
                    if($bpays):
                        $method_styles = [
                            'cash' => ['#16a34a','#f0fdf4','Cash'],
                            'bank' => ['#2563eb','#eff6ff','Bank'],
                            'momo' => ['#7c3aed','#f5f3ff','MoMo'],
                        ];
                        $seen = [];
                        foreach($bpays as $bp):
                            $m = $bp['payment_method'];
                            if(isset($seen[$m])) continue; $seen[$m] = true;
                            [$clr,$bg,$lbl] = $method_styles[$m] ?? ['#6b7280','#f3f4f6',$m];
                    ?>
                    <span style="display:inline-block;font-size:.72rem;font-weight:600;padding:.15rem .45rem;border-radius:4px;background:<?= $bg ?>;color:<?= $clr ?>;margin-right:.2rem"><?= $lbl ?></span>
                    <?php endforeach; ?>
                    <?php else: ?>
                    <span class="text-muted">—</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($b['created_by_name'] ?? '—') ?></td>
            </tr>
            <?php if($has_detail): ?>
            <tr id="detail-<?= $b['id'] ?>" style="display:none;background:rgba(var(--primary-rgb,37,99,235),.03)">
                <td colspan="12" style="padding:.6rem 1rem .75rem 3.5rem;border-top:none">
                    <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(170px,1fr));gap:.35rem .9rem;font-size:.82rem">
                        <?php if($px_label): ?>
                        <div><span class="text-muted"><?= $px_label ?>:</span>
                             <strong><?= number_format($px_value,4) ?></strong></div>
                        <?php endif; ?>
                        <?php if($b['rwf_rate'] !== null): ?>
                        <div><span class="text-muted">RWF Rate:</span>
                             <strong><?= number_format($b['rwf_rate'],2) ?></strong></div>
                        <?php endif; ?>
                        <?php if($b['fees_1'] !== null && $b['fees_1'] > 0): ?>
                        <div><span class="text-muted">Fees 1:</span>
                             <strong><?= number_format($b['fees_1'],2) ?> FRW</strong></div>
                        <?php endif; ?>
                        <?php if($b['fees_2'] !== null && $b['fees_2'] > 0): ?>
                        <div><span class="text-muted">Fees 2:</span>
                             <strong><?= number_format($b['fees_2'],2) ?> FRW</strong></div>
                        <?php endif; ?>
                        <?php if($b['tag'] !== null): ?>
                        <div><span class="text-muted">Tag:</span>
                             <strong><?= number_format($b['tag'],2) ?> FRW</strong></div>
                        <?php endif; ?>
                        <?php if($b['rma'] !== null): ?>
                        <div><span class="text-muted">RMA:</span>
                             <strong><?= number_format($b['rma'],2) ?> FRW</strong></div>
                        <?php endif; ?>
                        <?php if($b['rra'] !== null): ?>
                        <div><span class="text-muted">RRA:</span>
                             <strong><?= number_format($b['rra'],4) ?> FRW</strong></div>
                        <?php endif; ?>
                        <div><span class="text-muted">Currency:</span>
                             <strong><?= htmlspecialchars($cur) ?></strong></div>
                    </div>
                    <?php if(!empty($payments_by_batch[$b['id']])): ?>
                    <div style="margin-top:.55rem;padding-top:.45rem;border-top:1px solid var(--border)22;font-size:.82rem">
                        <span class="text-muted" style="font-size:.75rem;text-transform:uppercase;letter-spacing:.04em;font-weight:600">Payments</span>
                        <div style="display:flex;flex-wrap:wrap;gap:.4rem .9rem;margin-top:.3rem">
                            <?php
                            $method_icons = ['cash'=>'fa-money-bill-wave','bank'=>'fa-building-columns','momo'=>'fa-mobile-screen'];
                            $method_styles = ['cash'=>['#16a34a','Cash'],'bank'=>['#2563eb','Bank Transfer'],'momo'=>['#7c3aed','Mobile Money']];
                            foreach($payments_by_batch[$b['id']] as $bp):
                                $m = $bp['payment_method'];
                                [$clr,$lbl] = $method_styles[$m] ?? ['#6b7280', $m];
                                $icon = $method_icons[$m] ?? 'fa-credit-card';
                            ?>
                            <span style="color:<?= $clr ?>;font-weight:600">
                                <i class="fas <?= $icon ?>"></i> <?= $lbl ?>:
                                <strong><?= number_format($bp['amount'],2) ?> FRW</strong>
                                <?php if(!empty($bp['account_name'])): ?>
                                <span class="text-muted" style="font-weight:400">(<?= htmlspecialchars($bp['account_name']) ?>)</span>
                                <?php endif; ?>
                            </span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endif; ?>
            <?php endforeach; ?>
            <?php if(!$batches): ?>
            <tr id="empty-row"><td colspan="12" style="text-align:center;padding:2rem;color:var(--text-muted)">
                <?= $has_filters ? 'No batches match the current filters.' : 'No batches found.' ?>
            </td></tr>
            <?php endif; ?>
        </tbody>
    </table>
    <?php if($total_pages > 1): ?>
    <?= paginate($page, $total_pages, $filters, 'batches.php') ?>
    <p class="pagination-info" style="padding-bottom:.5rem">
        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> of <?= $total ?> batches
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
