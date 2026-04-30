<?php
require_once 'config/database.php';
if (!isLoggedIn()) { header('Location: login.php'); exit; }

$page_title = 'Reports';

$tab  = $_GET['tab'] ?? 'profit_loss';
$dFrom = date('Y-m-01');
$dTo   = date('Y-m-d');

/* ── CSV export helper ───────────────────────────────────────────── */
function csv_out(string $name, array $headers, array $rows): void {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    $f = fopen('php://output', 'w');
    fputcsv($f, $headers);
    foreach ($rows as $r) fputcsv($f, $r);
    fclose($f);
    exit;
}

/* ── Dropdown data ───────────────────────────────────────────────── */
$minerals  = $pdo->query("SELECT * FROM mineral_types ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();

/* ── Tab data ────────────────────────────────────────────────────── */
$data = [];

/* 1 · Profit & Loss */
if ($tab === 'profit_loss') {
    $from = $_GET['from'] ?? $dFrom;
    $to   = $_GET['to']   ?? $dTo;
    $mid  = $_GET['mineral'] ?? '';
    $w = ['sd.sale_date BETWEEN ? AND ?']; $p = [$from, $to];
    if ($mid) { $w[] = 'sd.mineral_id=?'; $p[] = $mid; }
    $ws = implode(' AND ', $w);
    $stmt = $pdo->prepare("
        SELECT mt.name AS mineral, COUNT(s.id) AS sales,
               COALESCE(SUM(sd.qty),0) AS qty,
               COALESCE(SUM(sd.total_revenue),0) AS revenue,
               COALESCE(SUM(sd.total_cost),0) AS cost,
               COALESCE(SUM(sd.benefit),0) AS profit
        FROM sale_details sd
        JOIN sales s ON sd.sale_id = s.id
        JOIN mineral_types mt ON sd.mineral_id = mt.id
        WHERE $ws GROUP BY mt.id, mt.name ORDER BY revenue DESC
    ");
    $stmt->execute($p);
    $data['rows'] = $stmt->fetchAll();
    $data += ['from'=>$from,'to'=>$to,'mineral'=>$mid];
    if (isset($_GET['export'])) {
        csv_out("profit_loss_{$from}_{$to}.csv",
            ['Mineral','Sales','Qty (kg)','Revenue (FRW)','Cost (FRW)','Profit (FRW)','Margin %'],
            array_map(fn($r) => [$r['mineral'],$r['sales'],
                number_format($r['qty'],3), number_format($r['revenue'],2),
                number_format($r['cost'],2), number_format($r['profit'],2),
                $r['revenue']>0 ? number_format($r['profit']/$r['revenue']*100,1).'%' : '0%'
            ], $data['rows']));
    }
}

/* 2 · Sales by Buyer */
if ($tab === 'sales_buyer') {
    $from = $_GET['from'] ?? $dFrom;
    $to   = $_GET['to']   ?? $dTo;
    $mid  = $_GET['mineral'] ?? '';
    $w = ['sd.sale_date BETWEEN ? AND ?']; $p = [$from, $to];
    if ($mid) { $w[] = 'sd.mineral_id=?'; $p[] = $mid; }
    $ws = implode(' AND ', $w);
    $stmt = $pdo->prepare("
        SELECT b.name AS buyer, COUNT(DISTINCT s.id) AS transactions,
               COALESCE(SUM(sd.qty),0) AS qty,
               COALESCE(SUM(sd.total_revenue),0) AS revenue,
               COALESCE(SUM(sd.benefit),0) AS profit
        FROM sale_details sd
        JOIN sales s ON sd.sale_id = s.id
        JOIN buyers b ON sd.buyer_id = b.id
        WHERE $ws GROUP BY b.id, b.name ORDER BY revenue DESC
    ");
    $stmt->execute($p);
    $data['rows'] = $stmt->fetchAll();
    $data += ['from'=>$from,'to'=>$to,'mineral'=>$mid];
    if (isset($_GET['export'])) {
        csv_out("sales_by_buyer_{$from}_{$to}.csv",
            ['Buyer','Transactions','Qty (kg)','Revenue (FRW)','Profit (FRW)'],
            array_map(fn($r) => [$r['buyer'],$r['transactions'],
                number_format($r['qty'],3), number_format($r['revenue'],2),
                number_format($r['profit'],2)
            ], $data['rows']));
    }
}

/* 3 · Mineral Profitability */
if ($tab === 'profitability') {
    $from = $_GET['from'] ?? $dFrom;
    $to   = $_GET['to']   ?? $dTo;
    $stmt = $pdo->prepare("
        SELECT mt.name AS mineral,
               COALESCE(SUM(sd.qty),0) AS qty,
               COALESCE(SUM(sd.total_revenue),0) AS revenue,
               COALESCE(SUM(sd.total_cost),0) AS cost,
               COALESCE(SUM(sd.benefit),0) AS profit,
               ROUND(COALESCE(SUM(sd.benefit),0)/NULLIF(COALESCE(SUM(sd.total_revenue),0),0)*100,2) AS margin
        FROM sale_details sd
        JOIN mineral_types mt ON sd.mineral_id = mt.id
        WHERE sd.sale_date BETWEEN ? AND ?
        GROUP BY mt.id, mt.name ORDER BY margin DESC
    ");
    $stmt->execute([$from, $to]);
    $data['rows'] = $stmt->fetchAll();
    $data += ['from'=>$from,'to'=>$to];
    if (isset($_GET['export'])) {
        csv_out("mineral_profitability_{$from}_{$to}.csv",
            ['Mineral','Qty Sold (kg)','Revenue (FRW)','Cost (FRW)','Profit (FRW)','Margin %'],
            array_map(fn($r) => [$r['mineral'],
                number_format($r['qty'],3), number_format($r['revenue'],2),
                number_format($r['cost'],2), number_format($r['profit'],2),
                number_format($r['margin'],2).'%'
            ], $data['rows']));
    }
}

/* 4 · Purchase Summary */
if ($tab === 'purchases') {
    $from = $_GET['from'] ?? $dFrom;
    $to   = $_GET['to']   ?? $dTo;
    $sid  = $_GET['supplier'] ?? '';
    $w = ['b.received_date BETWEEN ? AND ?']; $p = [$from, $to];
    if ($sid) { $w[] = 'b.supplier_id=?'; $p[] = $sid; }
    $ws = implode(' AND ', $w);
    $stmt = $pdo->prepare("
        SELECT s.name AS supplier, mt.name AS mineral,
               COUNT(b.id) AS batches, SUM(b.quantity) AS qty
        FROM batches b
        JOIN suppliers s ON b.supplier_id = s.id
        JOIN mineral_types mt ON b.mineral_type_id = mt.id
        WHERE $ws GROUP BY s.id, s.name, mt.id, mt.name
        ORDER BY s.name, qty DESC
    ");
    $stmt->execute($p);
    $data['rows'] = $stmt->fetchAll();
    $data += ['from'=>$from,'to'=>$to,'supplier'=>$sid];
    if (isset($_GET['export'])) {
        csv_out("purchase_summary_{$from}_{$to}.csv",
            ['Supplier','Mineral','Batches','Total Qty (kg)'],
            array_map(fn($r) => [$r['supplier'],$r['mineral'],
                $r['batches'], number_format($r['qty'],3)
            ], $data['rows']));
    }
}

/* 5 · Purchase vs Sales */
if ($tab === 'pvs') {
    $from = $_GET['from'] ?? $dFrom;
    $to   = $_GET['to']   ?? $dTo;
    $stmt = $pdo->prepare("
        SELECT mt.name AS mineral,
               COALESCE(SUM(CASE WHEN t.transaction_type='IN'  THEN t.quantity ELSE 0 END),0) AS purchased,
               COALESCE(SUM(CASE WHEN t.transaction_type='OUT' THEN t.quantity ELSE 0 END),0) AS sold
        FROM mineral_types mt
        LEFT JOIN transactions t ON t.mineral_type_id = mt.id
          AND t.transaction_date BETWEEN ? AND ?
        GROUP BY mt.id, mt.name ORDER BY mt.name
    ");
    $stmt->execute([$from, $to]);
    $data['rows'] = $stmt->fetchAll();
    $data += ['from'=>$from,'to'=>$to];
    if (isset($_GET['export'])) {
        csv_out("purchase_vs_sales_{$from}_{$to}.csv",
            ['Mineral','Purchased (kg)','Sold (kg)','Net (kg)'],
            array_map(fn($r) => [$r['mineral'],
                number_format($r['purchased'],3), number_format($r['sold'],3),
                number_format($r['purchased']-$r['sold'],3)
            ], $data['rows']));
    }
}

/* 6 · Stock Movement */
if ($tab === 'stock_movement') {
    $from = $_GET['from'] ?? $dFrom;
    $to   = $_GET['to']   ?? $dTo;
    $mid  = $_GET['mineral'] ?? '';
    $w = ['t.transaction_date BETWEEN ? AND ?']; $p = [$from, $to];
    if ($mid) { $w[] = 't.mineral_type_id=?'; $p[] = $mid; }
    $ws = implode(' AND ', $w);
    $stmt = $pdo->prepare("
        SELECT DATE(t.transaction_date) AS date, mt.name AS mineral,
               SUM(CASE WHEN t.transaction_type='IN'  THEN t.quantity ELSE 0 END) AS stock_in,
               SUM(CASE WHEN t.transaction_type='OUT' THEN t.quantity ELSE 0 END) AS stock_out
        FROM transactions t
        JOIN mineral_types mt ON t.mineral_type_id = mt.id
        WHERE $ws
        GROUP BY DATE(t.transaction_date), mt.id, mt.name
        ORDER BY date DESC, mt.name
    ");
    $stmt->execute($p);
    $data['rows'] = $stmt->fetchAll();
    $data += ['from'=>$from,'to'=>$to,'mineral'=>$mid];
    if (isset($_GET['export'])) {
        csv_out("stock_movement_{$from}_{$to}.csv",
            ['Date','Mineral','Stock IN (kg)','Stock OUT (kg)','Net (kg)'],
            array_map(fn($r) => [$r['date'],$r['mineral'],
                number_format($r['stock_in'],3), number_format($r['stock_out'],3),
                number_format($r['stock_in']-$r['stock_out'],3)
            ], $data['rows']));
    }
}

/* 7 · Stock Valuation */
if ($tab === 'stock_valuation') {
    $stmt = $pdo->prepare("
        SELECT mt.name AS mineral, i.current_stock,
               mps.quality_grade, mps.selling_price, mps.purchase_price,
               ROUND(i.current_stock * COALESCE(mps.selling_price,0), 2)  AS market_value,
               ROUND(i.current_stock * COALESCE(mps.purchase_price,0), 2) AS cost_value,
               i.last_updated
        FROM inventory i
        JOIN mineral_types mt ON i.mineral_type_id = mt.id
        LEFT JOIN mineral_price_settings mps ON mps.mineral_type_id = mt.id
        WHERE i.current_stock > 0
        ORDER BY market_value DESC
    ");
    $stmt->execute();
    $data['rows'] = $stmt->fetchAll();
    if (isset($_GET['export'])) {
        csv_out('stock_valuation_' . date('Ymd') . '.csv',
            ['Mineral','Grade','Stock (kg)','Sell Price/kg','Buy Price/kg','Market Value (FRW)','Cost Value (FRW)'],
            array_map(fn($r) => [$r['mineral'], $r['quality_grade'] ?? '—',
                number_format($r['current_stock'],3),
                number_format($r['selling_price'] ?? 0,2),
                number_format($r['purchase_price'] ?? 0,2),
                number_format($r['market_value'],2),
                number_format($r['cost_value'],2)
            ], $data['rows']));
    }
}

/* 8 · Loan Aging */
if ($tab === 'loan_aging') {
    $data['rows'] = $pdo->query("
        SELECT s.name AS supplier,
               SUM(CASE WHEN sl.type='loan' THEN sl.amount ELSE 0 END) AS total_loaned,
               SUM(CASE WHEN sl.type='repayment' THEN sl.amount ELSE 0 END) AS total_repaid,
               SUM(CASE WHEN sl.type='loan' THEN sl.amount ELSE -sl.amount END) AS balance,
               MIN(CASE WHEN sl.type='loan' THEN DATE(sl.created_at) END) AS first_loan_date,
               DATEDIFF(CURDATE(), MIN(CASE WHEN sl.type='loan' THEN sl.created_at END)) AS days
        FROM supplier_loans sl
        JOIN suppliers s ON s.id = sl.supplier_id
        GROUP BY sl.supplier_id, s.name
        HAVING balance > 0
        ORDER BY days DESC
    ")->fetchAll();
    if (isset($_GET['export'])) {
        csv_out('loan_aging_' . date('Ymd') . '.csv',
            ['Supplier','Total Loaned (FRW)','Total Repaid (FRW)','Balance (FRW)','First Loan','Days Outstanding','Bucket'],
            array_map(fn($r) => [$r['supplier'],
                number_format($r['total_loaned'],2), number_format($r['total_repaid'],2),
                number_format($r['balance'],2), $r['first_loan_date'] ?? '—', $r['days'] ?? 0,
                ($r['days'] > 90 ? '90+ days' : ($r['days'] > 60 ? '61–90 days' : ($r['days'] > 30 ? '31–60 days' : '0–30 days')))
            ], $data['rows']));
    }
}

$rows = $data['rows'] ?? [];

/* ── Export URL builder ──────────────────────────────────────────── */
function export_url(array $get): string {
    $p = array_merge($get, ['export' => '1']);
    unset($p['export']); // remove first then re-add to ensure it's last
    return 'reports.php?' . http_build_query(array_merge($p, ['export' => '1']));
}

require 'includes/header.php';

/* ── Helpers ─────────────────────────────────────────────────────── */
function fmtN($n, $dec=2) { return number_format((float)$n, $dec); }
function pctBar($pct, $color='#3b82f6') {
    $w = max(0, min(100, (float)$pct));
    return "<div style='height:6px;background:#e2e8f0;border-radius:99px;min-width:60px'><div style='height:100%;width:{$w}%;background:{$color};border-radius:99px'></div></div>";
}
?>

<!-- ── Tab nav ────────────────────────────────────────────────────── -->
<?php
$tabs = [
    'profit_loss'    => ['icon'=>'fa-sack-dollar',        'label'=>'Profit & Loss'],
    'sales_buyer'    => ['icon'=>'fa-handshake',          'label'=>'Sales by Buyer'],
    'profitability'  => ['icon'=>'fa-chart-pie',          'label'=>'Mineral Profitability'],
    'purchases'      => ['icon'=>'fa-truck-ramp-box',     'label'=>'Purchase Summary'],
    'pvs'            => ['icon'=>'fa-arrows-left-right',  'label'=>'Purchase vs Sales'],
    'stock_movement' => ['icon'=>'fa-arrow-right-arrow-left','label'=>'Stock Movement'],
    'stock_valuation'=> ['icon'=>'fa-coins',              'label'=>'Stock Valuation'],
    'loan_aging'     => ['icon'=>'fa-clock-rotate-left',  'label'=>'Loan Aging'],
];
?>
<div style="display:flex;gap:.35rem;flex-wrap:wrap;margin-bottom:1.25rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--r);padding:.5rem">
    <?php foreach ($tabs as $key => $t): ?>
    <a href="reports.php?tab=<?= $key ?>"
       style="display:flex;align-items:center;gap:.4rem;padding:.45rem .8rem;border-radius:var(--r-sm);font-size:.8rem;font-weight:600;text-decoration:none;white-space:nowrap;
              <?= $tab===$key ? 'background:var(--primary);color:#fff' : 'color:var(--text-sec)' ?>">
        <i class="fas <?= $t['icon'] ?>" style="font-size:.75rem"></i>
        <?= $t['label'] ?>
    </a>
    <?php endforeach; ?>
</div>

<?php /* ══════════════════════════════════════════════════════════
   1 · PROFIT & LOSS
══════════════════════════════════════════════════════════ */
if ($tab === 'profit_loss'): ?>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-sack-dollar" style="margin-right:.4rem;color:var(--text-muted)"></i>Profit &amp; Loss by Mineral</span>
        <a href="<?= export_url($_GET) ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.35rem .7rem">
            <i class="fas fa-download"></i> Export CSV
        </a>
    </div>
    <div class="card-body">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="tab" value="profit_loss">
            <div class="form-group" style="margin:0">
                <label>From</label>
                <input type="date" name="from" value="<?= htmlspecialchars($data['from'] ?? $dFrom) ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label>To</label>
                <input type="date" name="to" value="<?= htmlspecialchars($data['to'] ?? $dTo) ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label>Mineral</label>
                <select name="mineral">
                    <option value="">All minerals</option>
                    <?php foreach ($minerals as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($data['mineral']??'')==$m['id']?'selected':'' ?>><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" style="height:2.2rem;padding:0 1rem">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>
    <?php
    $tot_rev = array_sum(array_column($rows,'revenue'));
    $tot_cost= array_sum(array_column($rows,'cost'));
    $tot_pro = array_sum(array_column($rows,'profit'));
    ?>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr>
                <th>Mineral</th><th>Sales</th><th>Qty (kg)</th>
                <th style="text-align:right">Revenue (FRW)</th>
                <th style="text-align:right">Cost (FRW)</th>
                <th style="text-align:right">Profit (FRW)</th>
                <th>Margin</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No data for this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $margin = $r['revenue'] > 0 ? $r['profit']/$r['revenue']*100 : 0;
            $mcolor = $margin >= 20 ? '#10b981' : ($margin >= 10 ? '#f59e0b' : '#ef4444');
        ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($r['mineral']) ?></td>
            <td><?= $r['sales'] ?></td>
            <td><?= fmtN($r['qty'],3) ?></td>
            <td style="text-align:right;font-family:monospace"><?= fmtN($r['revenue']) ?></td>
            <td style="text-align:right;font-family:monospace;color:var(--text-muted)"><?= fmtN($r['cost']) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $r['profit']>=0?'#10b981':'#ef4444' ?>"><?= fmtN($r['profit']) ?></td>
            <td style="min-width:100px">
                <div style="display:flex;align-items:center;gap:.5rem;font-size:.78rem;font-weight:600;color:<?= $mcolor ?>">
                    <?= fmtN($margin,1) ?>%
                    <?= pctBar($margin, $mcolor) ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot>
            <tr style="background:#f8fafc;font-weight:700">
                <td colspan="3">Total</td>
                <td style="text-align:right;font-family:monospace"><?= fmtN($tot_rev) ?></td>
                <td style="text-align:right;font-family:monospace;color:var(--text-muted)"><?= fmtN($tot_cost) ?></td>
                <td style="text-align:right;font-family:monospace;color:<?= $tot_pro>=0?'#10b981':'#ef4444' ?>"><?= fmtN($tot_pro) ?></td>
                <td style="font-weight:700;color:#10b981"><?= $tot_rev>0?fmtN($tot_pro/$tot_rev*100,1).'%':'—' ?></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>

<?php /* ══════════════════════════════════════════════════════════
   2 · SALES BY BUYER
══════════════════════════════════════════════════════════ */
elseif ($tab === 'sales_buyer'): ?>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-handshake" style="margin-right:.4rem;color:var(--text-muted)"></i>Sales by Buyer</span>
        <a href="<?= export_url($_GET) ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.35rem .7rem">
            <i class="fas fa-download"></i> Export CSV
        </a>
    </div>
    <div class="card-body">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="tab" value="sales_buyer">
            <div class="form-group" style="margin:0"><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($data['from']??$dFrom) ?>"></div>
            <div class="form-group" style="margin:0"><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($data['to']??$dTo) ?>"></div>
            <div class="form-group" style="margin:0">
                <label>Mineral</label>
                <select name="mineral">
                    <option value="">All</option>
                    <?php foreach ($minerals as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($data['mineral']??'')==$m['id']?'selected':'' ?>><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" style="height:2.2rem;padding:0 1rem"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>
    <?php $maxRev = $rows ? max(array_column($rows,'revenue')) : 1; if (!$maxRev) $maxRev=1; ?>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>#</th><th>Buyer</th><th>Transactions</th><th>Qty (kg)</th>
                <th style="text-align:right">Revenue (FRW)</th>
                <th style="text-align:right">Profit (FRW)</th>
                <th style="min-width:120px">Share</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No data.</td></tr><?php endif; ?>
        <?php foreach ($rows as $i => $r): $pct = $r['revenue']/$maxRev*100; ?>
        <tr>
            <td style="color:var(--text-muted)"><?= $i+1 ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($r['buyer']) ?></td>
            <td><?= $r['transactions'] ?></td>
            <td><?= fmtN($r['qty'],3) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:600"><?= fmtN($r['revenue']) ?></td>
            <td style="text-align:right;font-family:monospace;color:<?= $r['profit']>=0?'#10b981':'#ef4444' ?>;font-weight:600"><?= fmtN($r['profit']) ?></td>
            <td><?= pctBar($pct, '#3b82f6') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot><tr style="background:#f8fafc;font-weight:700">
            <td colspan="3">Total</td>
            <td><?= fmtN(array_sum(array_column($rows,'qty')),3) ?></td>
            <td style="text-align:right;font-family:monospace"><?= fmtN(array_sum(array_column($rows,'revenue'))) ?></td>
            <td style="text-align:right;font-family:monospace;color:#10b981"><?= fmtN(array_sum(array_column($rows,'profit'))) ?></td>
            <td></td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>

<?php /* ══════════════════════════════════════════════════════════
   3 · MINERAL PROFITABILITY
══════════════════════════════════════════════════════════ */
elseif ($tab === 'profitability'): ?>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-chart-pie" style="margin-right:.4rem;color:var(--text-muted)"></i>Mineral Profitability</span>
        <a href="<?= export_url($_GET) ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.35rem .7rem"><i class="fas fa-download"></i> Export CSV</a>
    </div>
    <div class="card-body">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="tab" value="profitability">
            <div class="form-group" style="margin:0"><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($data['from']??$dFrom) ?>"></div>
            <div class="form-group" style="margin:0"><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($data['to']??$dTo) ?>"></div>
            <button class="btn btn-primary" style="height:2.2rem;padding:0 1rem"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Mineral</th><th>Qty Sold (kg)</th>
                <th style="text-align:right">Revenue (FRW)</th>
                <th style="text-align:right">Cost (FRW)</th>
                <th style="text-align:right">Profit (FRW)</th>
                <th style="min-width:140px">Margin</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="6" style="text-align:center;padding:2rem;color:var(--text-muted)">No data.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
            $m = (float)$r['margin'];
            $mc = $m>=20?'#10b981':($m>=10?'#f59e0b':'#ef4444');
        ?>
        <tr>
            <td style="font-weight:700"><?= htmlspecialchars($r['mineral']) ?></td>
            <td><?= fmtN($r['qty'],3) ?></td>
            <td style="text-align:right;font-family:monospace"><?= fmtN($r['revenue']) ?></td>
            <td style="text-align:right;font-family:monospace;color:var(--text-muted)"><?= fmtN($r['cost']) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $r['profit']>=0?'#10b981':'#ef4444' ?>"><?= fmtN($r['profit']) ?></td>
            <td>
                <div style="display:flex;align-items:center;gap:.5rem">
                    <span style="font-weight:700;font-size:.85rem;color:<?= $mc ?>;min-width:42px"><?= fmtN($m,1) ?>%</span>
                    <?= pctBar($m, $mc) ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php /* ══════════════════════════════════════════════════════════
   4 · PURCHASE SUMMARY
══════════════════════════════════════════════════════════ */
elseif ($tab === 'purchases'): ?>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-truck-ramp-box" style="margin-right:.4rem;color:var(--text-muted)"></i>Purchase Summary</span>
        <a href="<?= export_url($_GET) ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.35rem .7rem"><i class="fas fa-download"></i> Export CSV</a>
    </div>
    <div class="card-body">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="tab" value="purchases">
            <div class="form-group" style="margin:0"><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($data['from']??$dFrom) ?>"></div>
            <div class="form-group" style="margin:0"><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($data['to']??$dTo) ?>"></div>
            <div class="form-group" style="margin:0">
                <label>Supplier</label>
                <select name="supplier">
                    <option value="">All</option>
                    <?php foreach ($suppliers as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= ($data['supplier']??'')==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" style="height:2.2rem;padding:0 1rem"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Supplier</th><th>Mineral</th><th>Batches</th><th style="text-align:right">Total Qty (kg)</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="4" style="text-align:center;padding:2rem;color:var(--text-muted)">No data.</td></tr><?php endif; ?>
        <?php $prev_sup = null; foreach ($rows as $r): ?>
        <tr>
            <td style="font-weight:600;color:<?= $r['supplier']===$prev_sup?'transparent':'var(--text)' ?>">
                <?= htmlspecialchars($r['supplier']) ?>
            </td>
            <td><?= htmlspecialchars($r['mineral']) ?></td>
            <td><?= $r['batches'] ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:600"><?= fmtN($r['qty'],3) ?></td>
        </tr>
        <?php $prev_sup = $r['supplier']; endforeach; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot><tr style="background:#f8fafc;font-weight:700">
            <td colspan="2">Total</td>
            <td><?= array_sum(array_column($rows,'batches')) ?></td>
            <td style="text-align:right;font-family:monospace"><?= fmtN(array_sum(array_column($rows,'qty')),3) ?></td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>

<?php /* ══════════════════════════════════════════════════════════
   5 · PURCHASE VS SALES
══════════════════════════════════════════════════════════ */
elseif ($tab === 'pvs'): ?>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-arrows-left-right" style="margin-right:.4rem;color:var(--text-muted)"></i>Purchase vs Sales</span>
        <a href="<?= export_url($_GET) ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.35rem .7rem"><i class="fas fa-download"></i> Export CSV</a>
    </div>
    <div class="card-body">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="tab" value="pvs">
            <div class="form-group" style="margin:0"><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($data['from']??$dFrom) ?>"></div>
            <div class="form-group" style="margin:0"><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($data['to']??$dTo) ?>"></div>
            <button class="btn btn-primary" style="height:2.2rem;padding:0 1rem"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Mineral</th>
                <th style="text-align:right;color:#3b82f6">Purchased (kg)</th>
                <th style="text-align:right;color:#10b981">Sold (kg)</th>
                <th style="text-align:right">Net (kg)</th>
                <th style="min-width:160px">Balance</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-muted)">No data.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r):
            $net = $r['purchased'] - $r['sold'];
            $maxV = max($r['purchased'], $r['sold'], 1);
        ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($r['mineral']) ?></td>
            <td style="text-align:right;font-family:monospace;color:#3b82f6;font-weight:600"><?= fmtN($r['purchased'],3) ?></td>
            <td style="text-align:right;font-family:monospace;color:#10b981;font-weight:600"><?= fmtN($r['sold'],3) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $net>=0?'#f59e0b':'#ef4444' ?>"><?= fmtN($net,3) ?></td>
            <td>
                <div style="display:flex;gap:2px;height:8px;border-radius:4px;overflow:hidden">
                    <div style="flex:<?= $r['purchased'] ?>;background:#bfdbfe"></div>
                    <div style="flex:<?= $r['sold'] ?>;background:#a7f3d0"></div>
                </div>
                <div style="display:flex;justify-content:space-between;font-size:.65rem;color:var(--text-muted);margin-top:.2rem">
                    <span style="color:#3b82f6">▪ Bought</span><span style="color:#10b981">▪ Sold</span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php /* ══════════════════════════════════════════════════════════
   6 · STOCK MOVEMENT
══════════════════════════════════════════════════════════ */
elseif ($tab === 'stock_movement'): ?>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-arrow-right-arrow-left" style="margin-right:.4rem;color:var(--text-muted)"></i>Stock Movement</span>
        <a href="<?= export_url($_GET) ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.35rem .7rem"><i class="fas fa-download"></i> Export CSV</a>
    </div>
    <div class="card-body">
        <form method="GET" style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
            <input type="hidden" name="tab" value="stock_movement">
            <div class="form-group" style="margin:0"><label>From</label><input type="date" name="from" value="<?= htmlspecialchars($data['from']??$dFrom) ?>"></div>
            <div class="form-group" style="margin:0"><label>To</label><input type="date" name="to" value="<?= htmlspecialchars($data['to']??$dTo) ?>"></div>
            <div class="form-group" style="margin:0">
                <label>Mineral</label>
                <select name="mineral">
                    <option value="">All</option>
                    <?php foreach ($minerals as $m): ?>
                    <option value="<?= $m['id'] ?>" <?= ($data['mineral']??'')==$m['id']?'selected':'' ?>><?= htmlspecialchars($m['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <button class="btn btn-primary" style="height:2.2rem;padding:0 1rem"><i class="fas fa-filter"></i> Filter</button>
        </form>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Date</th><th>Mineral</th>
                <th style="text-align:right;color:#10b981">IN (kg)</th>
                <th style="text-align:right;color:#ef4444">OUT (kg)</th>
                <th style="text-align:right">Net (kg)</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="5" style="text-align:center;padding:2rem;color:var(--text-muted)">No data.</td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $net = $r['stock_in'] - $r['stock_out']; ?>
        <tr>
            <td style="color:var(--text-muted);font-size:.82rem;white-space:nowrap"><?= date('d M Y', strtotime($r['date'])) ?></td>
            <td style="font-weight:600"><?= htmlspecialchars($r['mineral']) ?></td>
            <td style="text-align:right;font-family:monospace;color:#10b981;font-weight:600"><?= $r['stock_in']>0?'+'.fmtN($r['stock_in'],3):'—' ?></td>
            <td style="text-align:right;font-family:monospace;color:#ef4444;font-weight:600"><?= $r['stock_out']>0?'−'.fmtN($r['stock_out'],3):'—' ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $net>=0?'#10b981':'#ef4444' ?>"><?= fmtN($net,3) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<?php /* ══════════════════════════════════════════════════════════
   7 · STOCK VALUATION
══════════════════════════════════════════════════════════ */
elseif ($tab === 'stock_valuation'): ?>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-coins" style="margin-right:.4rem;color:var(--text-muted)"></i>Stock Valuation <small style="font-weight:400;color:var(--text-muted);font-size:.75rem">— snapshot as of today</small></span>
        <a href="<?= export_url($_GET) ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.35rem .7rem"><i class="fas fa-download"></i> Export CSV</a>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Mineral</th><th>Grade</th><th style="text-align:right">Stock (kg)</th>
                <th style="text-align:right">Sell Price/kg</th>
                <th style="text-align:right">Buy Price/kg</th>
                <th style="text-align:right;color:#10b981">Market Value (FRW)</th>
                <th style="text-align:right;color:var(--text-muted)">Cost Value (FRW)</th>
            </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No inventory data or no price settings configured.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r): ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($r['mineral']) ?></td>
            <td><span style="background:#f1f5f9;border-radius:4px;padding:.15rem .5rem;font-size:.78rem"><?= htmlspecialchars($r['quality_grade'] ?? '—') ?></span></td>
            <td style="text-align:right;font-family:monospace"><?= fmtN($r['current_stock'],3) ?></td>
            <td style="text-align:right;font-family:monospace"><?= $r['selling_price']  ? fmtN($r['selling_price'])  : '—' ?></td>
            <td style="text-align:right;font-family:monospace;color:var(--text-muted)"><?= $r['purchase_price'] ? fmtN($r['purchase_price']) : '—' ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:#10b981"><?= $r['market_value'] ? fmtN($r['market_value']) : '—' ?></td>
            <td style="text-align:right;font-family:monospace;color:var(--text-muted)"><?= $r['cost_value'] ? fmtN($r['cost_value']) : '—' ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot><tr style="background:#f8fafc;font-weight:700">
            <td colspan="5">Total</td>
            <td style="text-align:right;font-family:monospace;color:#10b981"><?= fmtN(array_sum(array_column($rows,'market_value'))) ?></td>
            <td style="text-align:right;font-family:monospace;color:var(--text-muted)"><?= fmtN(array_sum(array_column($rows,'cost_value'))) ?></td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>

<?php /* ══════════════════════════════════════════════════════════
   8 · LOAN AGING
══════════════════════════════════════════════════════════ */
elseif ($tab === 'loan_aging'): ?>

<div class="card">
    <div class="card-header">
        <span><i class="fas fa-clock-rotate-left" style="margin-right:.4rem;color:var(--text-muted)"></i>Loan Aging Report <small style="font-weight:400;color:var(--text-muted);font-size:.75rem">— outstanding balances only</small></span>
        <a href="<?= export_url($_GET) ?>" class="btn btn-secondary" style="font-size:.78rem;padding:.35rem .7rem"><i class="fas fa-download"></i> Export CSV</a>
    </div>
    <div style="overflow-x:auto">
    <table>
        <thead>
            <tr><th>Supplier</th><th style="text-align:right">Total Loaned (FRW)</th>
                <th style="text-align:right">Total Repaid (FRW)</th>
                <th style="text-align:right">Balance (FRW)</th>
                <th>First Loan</th><th>Days Outstanding</th><th>Bucket</th></tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">
            <i class="fas fa-circle-check" style="color:#10b981;font-size:1.5rem;display:block;margin-bottom:.4rem"></i>
            No outstanding loans.
        </td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $r):
            $days = (int)($r['days'] ?? 0);
            if ($days > 90)      { $bcolor='#fee2e2'; $tcolor='#dc2626'; $blabel='90+ days'; }
            elseif ($days > 60)  { $bcolor='#ffedd5'; $tcolor='#ea580c'; $blabel='61–90 days'; }
            elseif ($days > 30)  { $bcolor='#fef9c3'; $tcolor='#ca8a04'; $blabel='31–60 days'; }
            else                 { $bcolor='#dcfce7'; $tcolor='#16a34a'; $blabel='0–30 days'; }
        ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($r['supplier']) ?></td>
            <td style="text-align:right;font-family:monospace"><?= fmtN($r['total_loaned']) ?></td>
            <td style="text-align:right;font-family:monospace;color:#16a34a"><?= fmtN($r['total_repaid']) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:#dc2626"><?= fmtN($r['balance']) ?></td>
            <td style="color:var(--text-muted);font-size:.82rem"><?= $r['first_loan_date'] ? date('d M Y', strtotime($r['first_loan_date'])) : '—' ?></td>
            <td style="font-weight:700;color:<?= $tcolor ?>"><?= $days ?> days</td>
            <td><span style="background:<?= $bcolor ?>;color:<?= $tcolor ?>;border-radius:20px;padding:.2rem .65rem;font-size:.75rem;font-weight:700"><?= $blabel ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if ($rows): ?>
        <tfoot><tr style="background:#f8fafc;font-weight:700">
            <td>Total</td>
            <td style="text-align:right;font-family:monospace"><?= fmtN(array_sum(array_column($rows,'total_loaned'))) ?></td>
            <td style="text-align:right;font-family:monospace;color:#16a34a"><?= fmtN(array_sum(array_column($rows,'total_repaid'))) ?></td>
            <td style="text-align:right;font-family:monospace;color:#dc2626"><?= fmtN(array_sum(array_column($rows,'balance'))) ?></td>
            <td colspan="3"></td>
        </tr></tfoot>
        <?php endif; ?>
    </table>
    </div>
</div>

<?php endif; ?>

<?php require 'includes/footer.php'; ?>
