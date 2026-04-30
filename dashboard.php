<?php
require_once 'config/database.php';
if (!isLoggedIn()) { header('Location: login.php'); exit; }

$page_title  = 'Dashboard';
$extra_head  = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';

/* ── KPI stats ───────────────────────────────────────────────────── */
$kpi = $pdo->query("
    SELECT
        /* revenue & profit this month */
        COALESCE((SELECT SUM(total_revenue) FROM sale_details
                   WHERE MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE())), 0) AS revenue_month,
        COALESCE((SELECT SUM(benefit)       FROM sale_details
                   WHERE MONTH(sale_date)=MONTH(CURDATE()) AND YEAR(sale_date)=YEAR(CURDATE())), 0) AS profit_month,
        /* revenue & profit last month */
        COALESCE((SELECT SUM(total_revenue) FROM sale_details
                   WHERE MONTH(sale_date)=MONTH(CURDATE()-INTERVAL 1 MONTH)
                     AND YEAR(sale_date)=YEAR(CURDATE()-INTERVAL 1 MONTH)), 0) AS revenue_prev,
        COALESCE((SELECT SUM(benefit)       FROM sale_details
                   WHERE MONTH(sale_date)=MONTH(CURDATE()-INTERVAL 1 MONTH)
                     AND YEAR(sale_date)=YEAR(CURDATE()-INTERVAL 1 MONTH)), 0) AS profit_prev,
        /* totals */
        COALESCE((SELECT SUM(current_stock) FROM inventory), 0)                                    AS total_stock,
        (SELECT COUNT(*) FROM suppliers)                                                            AS suppliers,
        (SELECT COUNT(*) FROM buyers)                                                               AS buyers,
        (SELECT COUNT(*) FROM batches
          WHERE MONTH(received_date)=MONTH(CURDATE()) AND YEAR(received_date)=YEAR(CURDATE()))     AS batches_month,
        /* outstanding loans */
        COALESCE((SELECT SUM(CASE WHEN type='loan' THEN amount ELSE -amount END)
                  FROM supplier_loans), 0)                                                         AS loans_balance
")->fetch();

/* ── Revenue & cost — last 6 months ─────────────────────────────── */
$chart_rows = $pdo->query("
    SELECT DATE_FORMAT(sale_date,'%b %Y') AS label,
           MONTH(sale_date) AS mo, YEAR(sale_date) AS yr,
           SUM(total_revenue) AS revenue,
           SUM(total_cost)    AS cost
    FROM sale_details
    WHERE sale_date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
    GROUP BY yr, mo, label
    ORDER BY yr, mo
")->fetchAll();

$chart_labels  = array_column($chart_rows, 'label');
$chart_revenue = array_column($chart_rows, 'revenue');
$chart_cost    = array_column($chart_rows, 'cost');

/* ── Stock by mineral (donut) ────────────────────────────────────── */
$stock_rows = $pdo->query("
    SELECT mt.name, i.current_stock
    FROM inventory i
    JOIN mineral_types mt ON i.mineral_type_id = mt.id
    WHERE i.current_stock > 0
    ORDER BY i.current_stock DESC
")->fetchAll();

/* ── Inventory table + max for bar ──────────────────────────────── */
$inventory = $pdo->query("
    SELECT mt.name, i.current_stock
    FROM inventory i
    JOIN mineral_types mt ON i.mineral_type_id = mt.id
    ORDER BY i.current_stock DESC
")->fetchAll();
$maxStock = count($inventory) ? max(array_column($inventory,'current_stock')) : 1;
if ($maxStock == 0) $maxStock = 1;

/* ── Low-stock minerals (< 500 kg) ──────────────────────────────── */
$low_stock = $pdo->query("
    SELECT mt.name, i.current_stock
    FROM inventory i
    JOIN mineral_types mt ON i.mineral_type_id = mt.id
    WHERE i.current_stock < 500
    ORDER BY i.current_stock ASC
")->fetchAll();

/* ── Recent sales ────────────────────────────────────────────────── */
$recent_sales = $pdo->query("
    SELECT s.sale_id, mt.name AS mineral, b.name AS buyer,
           s.quantity, s.sale_date,
           sd.total_revenue, sd.benefit, sd.currency_used
    FROM sales s
    JOIN mineral_types mt ON s.mineral_type_id = mt.id
    JOIN buyers b          ON s.buyer_id = b.id
    LEFT JOIN sale_details sd ON sd.sale_id = s.id
    ORDER BY s.sale_date DESC, s.id DESC
    LIMIT 6
")->fetchAll();

/* ── Recent batches ──────────────────────────────────────────────── */
$recent_batches = $pdo->query("
    SELECT b.batch_id, mt.name AS mineral, s.name AS supplier,
           b.quantity, b.quality_grade, b.received_date
    FROM batches b
    JOIN mineral_types mt ON b.mineral_type_id = mt.id
    JOIN suppliers s       ON b.supplier_id = s.id
    ORDER BY b.received_date DESC, b.id DESC
    LIMIT 6
")->fetchAll();

/* ── Supplier loans outstanding ──────────────────────────────────── */
$loan_suppliers = $pdo->query("
    SELECT s.name,
           SUM(CASE WHEN sl.type='loan' THEN sl.amount ELSE -sl.amount END) AS balance
    FROM supplier_loans sl
    JOIN suppliers s ON s.id = sl.supplier_id
    GROUP BY sl.supplier_id, s.name
    HAVING balance > 0
    ORDER BY balance DESC
    LIMIT 5
")->fetchAll();

/* ── Helpers ─────────────────────────────────────────────────────── */
function fmt($n) {
    $n = (float)$n;
    if (abs($n) >= 1_000_000_000) return number_format($n/1_000_000_000, 2).'B';
    if (abs($n) >= 1_000_000)     return number_format($n/1_000_000, 2).'M';
    if (abs($n) >= 1_000)         return number_format($n/1_000, 1).'K';
    return number_format($n, 0);
}
function fmtKg($n) { return number_format((float)$n, 1); }
function pctChange($now, $prev) {
    if ($prev == 0) return null;
    return round(($now - $prev) / abs($prev) * 100, 1);
}
$rev_pct  = pctChange($kpi['revenue_month'], $kpi['revenue_prev']);
$prof_pct = pctChange($kpi['profit_month'],  $kpi['profit_prev']);

require 'includes/header.php';
?>

<!-- ── KPI Row ────────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:1rem;margin-bottom:1.25rem">

    <div style="background:#1d4ed8;border-radius:var(--r);padding:1.25rem 1rem;text-align:center;color:#fff;display:flex;flex-direction:column;align-items:center;gap:.4rem">
        <i class="fas fa-sack-dollar" style="font-size:1.4rem;opacity:.85"></i>
        <div style="font-size:1.6rem;font-weight:800;line-height:1"><?= fmt($kpi['revenue_month']) ?></div>
        <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;opacity:.8">Revenue · FRW</div>
        <?php if ($rev_pct !== null): ?>
        <div style="font-size:.75rem;background:rgba(255,255,255,.15);border-radius:20px;padding:.15rem .6rem;margin-top:.1rem">
            <i class="fas fa-arrow-<?= $rev_pct>=0?'up':'down' ?>"></i> <?= abs($rev_pct) ?>% vs last month
        </div>
        <?php endif; ?>
    </div>

    <div style="background:#059669;border-radius:var(--r);padding:1.25rem 1rem;text-align:center;color:#fff;display:flex;flex-direction:column;align-items:center;gap:.4rem">
        <i class="fas fa-chart-line" style="font-size:1.4rem;opacity:.85"></i>
        <div style="font-size:1.6rem;font-weight:800;line-height:1;color:<?= $kpi['profit_month']>=0?'#fff':'#fca5a5' ?>"><?= fmt($kpi['profit_month']) ?></div>
        <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;opacity:.8">Net Profit · FRW</div>
        <?php if ($prof_pct !== null): ?>
        <div style="font-size:.75rem;background:rgba(255,255,255,.15);border-radius:20px;padding:.15rem .6rem;margin-top:.1rem">
            <i class="fas fa-arrow-<?= $prof_pct>=0?'up':'down' ?>"></i> <?= abs($prof_pct) ?>% vs last month
        </div>
        <?php endif; ?>
    </div>

    <div style="background:#d97706;border-radius:var(--r);padding:1.25rem 1rem;text-align:center;color:#fff;display:flex;flex-direction:column;align-items:center;gap:.4rem">
        <i class="fas fa-warehouse" style="font-size:1.4rem;opacity:.85"></i>
        <div style="font-size:1.6rem;font-weight:800;line-height:1"><?= fmtKg($kpi['total_stock']) ?></div>
        <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;opacity:.8">Total Stock · kg</div>
        <div style="font-size:.75rem;background:rgba(255,255,255,.15);border-radius:20px;padding:.15rem .6rem;margin-top:.1rem">
            <?= count($inventory) ?> mineral types
        </div>
    </div>

    <div style="background:#0891b2;border-radius:var(--r);padding:1.25rem 1rem;text-align:center;color:#fff;display:flex;flex-direction:column;align-items:center;gap:.4rem">
        <i class="fas fa-boxes-stacked" style="font-size:1.4rem;opacity:.85"></i>
        <div style="font-size:1.6rem;font-weight:800;line-height:1"><?= $kpi['batches_month'] ?></div>
        <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;opacity:.8">Batches This Month</div>
        <div style="font-size:.75rem;background:rgba(255,255,255,.15);border-radius:20px;padding:.15rem .6rem;margin-top:.1rem">
            Lots received
        </div>
    </div>

    <div style="background:<?= $kpi['loans_balance']>0?'#dc2626':'#16a34a' ?>;border-radius:var(--r);padding:1.25rem 1rem;text-align:center;color:#fff;display:flex;flex-direction:column;align-items:center;gap:.4rem">
        <i class="fas fa-hand-holding-dollar" style="font-size:1.4rem;opacity:.85"></i>
        <div style="font-size:1.6rem;font-weight:800;line-height:1"><?= fmt($kpi['loans_balance']) ?></div>
        <div style="font-size:.62rem;font-weight:700;text-transform:uppercase;letter-spacing:.07em;opacity:.8">Unpaid Loans · FRW</div>
        <a href="loans.php" style="font-size:.75rem;background:rgba(255,255,255,.15);border-radius:20px;padding:.15rem .6rem;margin-top:.1rem;color:#fff;text-decoration:none">
            View details
        </a>
    </div>
</div>

<?php if ($low_stock): ?>
<!-- ── Low-stock alert banner ─────────────────────────────────────── -->
<div style="background:#fff7ed;border:1px solid #fed7aa;border-radius:var(--r);padding:.75rem 1rem;margin-bottom:1.25rem;display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
    <i class="fas fa-triangle-exclamation" style="color:#ea580c;font-size:1rem;flex-shrink:0"></i>
    <span style="font-size:.82rem;font-weight:600;color:#9a3412">Low stock alert:</span>
    <?php foreach ($low_stock as $ls): ?>
    <span style="background:#ffedd5;color:#9a3412;border-radius:20px;padding:.2rem .65rem;font-size:.75rem;font-weight:600">
        <?= htmlspecialchars($ls['name']) ?> — <?= fmtKg($ls['current_stock']) ?> kg
    </span>
    <?php endforeach; ?>
    <a href="inventory.php" style="margin-left:auto;font-size:.78rem;color:#ea580c;font-weight:600;white-space:nowrap">Manage inventory <i class="fas fa-arrow-right"></i></a>
</div>
<?php endif; ?>

<!-- ── Charts row ─────────────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 340px;gap:1.25rem;margin-bottom:1.25rem">

    <!-- Revenue vs Cost bar chart -->
    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-chart-bar" style="margin-right:.4rem;color:var(--text-muted)"></i>Revenue vs Cost — Last 6 Months</span>
            <a href="reports.php" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .65rem">Full report <i class="fas fa-arrow-right"></i></a>
        </div>
        <div style="padding:1rem 1.25rem">
            <?php if (empty($chart_rows)): ?>
            <div style="text-align:center;padding:3rem 0;color:var(--text-muted);font-size:.85rem">
                <i class="fas fa-chart-bar" style="font-size:2rem;margin-bottom:.5rem;display:block;opacity:.3"></i>
                No sales data yet
            </div>
            <?php else: ?>
            <canvas id="revenueChart" height="220"></canvas>
            <?php endif; ?>
        </div>
    </div>

    <!-- Stock donut -->
    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-circle-half-stroke" style="margin-right:.4rem;color:var(--text-muted)"></i>Stock Distribution</span>
        </div>
        <div style="padding:1rem;display:flex;flex-direction:column;align-items:center;gap:.75rem">
            <?php if (empty($stock_rows)): ?>
            <div style="padding:3rem 0;color:var(--text-muted);font-size:.85rem;text-align:center">No stock data</div>
            <?php else: ?>
            <canvas id="stockDonut" width="200" height="200"></canvas>
            <div style="width:100%;display:flex;flex-direction:column;gap:.35rem">
                <?php
                $donut_colors = ['#3b82f6','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4'];
                foreach ($stock_rows as $si => $sr):
                    $c = $donut_colors[$si % count($donut_colors)];
                ?>
                <div style="display:flex;align-items:center;gap:.5rem;font-size:.78rem">
                    <span style="width:10px;height:10px;border-radius:3px;background:<?= $c ?>;flex-shrink:0"></span>
                    <span style="flex:1;color:var(--text-sec)"><?= htmlspecialchars($sr['name']) ?></span>
                    <span style="font-weight:700;color:var(--text)"><?= fmtKg($sr['current_stock']) ?> kg</span>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- ── Inventory + Loans row ──────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem;margin-bottom:1.25rem">

    <!-- Inventory levels -->
    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-warehouse" style="margin-right:.4rem;color:var(--text-muted)"></i>Inventory Levels</span>
            <a href="inventory.php" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .65rem">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <div style="padding:.75rem 1.25rem;display:flex;flex-direction:column;gap:.85rem">
            <?php if (empty($inventory)): ?>
            <p style="color:var(--text-muted);font-size:.85rem;text-align:center;padding:1.5rem 0">No inventory data</p>
            <?php endif; ?>
            <?php foreach ($inventory as $item):
                $pct = round(($item['current_stock'] / $maxStock) * 100);
                $bar_color = $item['current_stock'] < 100
                    ? 'var(--danger)'
                    : ($item['current_stock'] < 500 ? 'var(--warning)' : 'var(--success)');
            ?>
            <div>
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:.3rem">
                    <span style="font-size:.82rem;font-weight:600"><?= htmlspecialchars($item['name']) ?></span>
                    <span style="font-size:.78rem;color:var(--text-muted)"><?= fmtKg($item['current_stock']) ?> kg</span>
                </div>
                <div style="height:7px;background:var(--border);border-radius:99px;overflow:hidden">
                    <div style="height:100%;width:<?= $pct ?>%;background:<?= $bar_color ?>;border-radius:99px;transition:width .4s"></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Outstanding loans by supplier -->
    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-hand-holding-dollar" style="margin-right:.4rem;color:var(--text-muted)"></i>Outstanding Loans by Supplier</span>
            <a href="loans.php" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .65rem">Manage <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($loan_suppliers)): ?>
        <div style="padding:3rem 1.25rem;text-align:center;color:var(--text-muted);font-size:.85rem">
            <i class="fas fa-check-circle" style="font-size:1.75rem;color:var(--success);display:block;margin-bottom:.4rem"></i>
            No outstanding loans
        </div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Supplier</th>
                        <th style="text-align:right">Balance (FRW)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($loan_suppliers as $ls): ?>
                    <tr>
                        <td><?= htmlspecialchars($ls['name']) ?></td>
                        <td style="text-align:right;color:var(--danger);font-weight:700"><?= fmt($ls['balance']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- ── Recent activity row ────────────────────────────────────────── -->
<div style="display:grid;grid-template-columns:1fr 1fr;gap:1.25rem">

    <!-- Recent sales -->
    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-chart-line" style="margin-right:.4rem;color:var(--text-muted)"></i>Recent Sales</span>
            <a href="sales.php" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .65rem">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($recent_sales)): ?>
        <div style="padding:3rem 1.25rem;text-align:center;color:var(--text-muted);font-size:.85rem">No sales yet</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Mineral</th>
                        <th>Buyer</th>
                        <th style="text-align:right">Qty (kg)</th>
                        <th style="text-align:right">Revenue</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_sales as $rs): ?>
                    <tr>
                        <td style="font-weight:600"><?= htmlspecialchars($rs['mineral']) ?></td>
                        <td style="color:var(--text-sec)"><?= htmlspecialchars($rs['buyer']) ?></td>
                        <td style="text-align:right"><?= fmtKg($rs['quantity']) ?></td>
                        <td style="text-align:right;font-weight:600;color:var(--success)">
                            <?= $rs['total_revenue'] !== null ? fmt($rs['total_revenue']) : '—' ?>
                        </td>
                        <td style="color:var(--text-muted);white-space:nowrap"><?= date('d M', strtotime($rs['sale_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>

    <!-- Recent batches -->
    <div class="card">
        <div class="card-header">
            <span><i class="fas fa-boxes-stacked" style="margin-right:.4rem;color:var(--text-muted)"></i>Recent Batches Received</span>
            <a href="batches.php" class="btn btn-secondary" style="font-size:.75rem;padding:.3rem .65rem">View all <i class="fas fa-arrow-right"></i></a>
        </div>
        <?php if (empty($recent_batches)): ?>
        <div style="padding:3rem 1.25rem;text-align:center;color:var(--text-muted);font-size:.85rem">No batches yet</div>
        <?php else: ?>
        <div style="overflow-x:auto">
            <table>
                <thead>
                    <tr>
                        <th>Batch</th>
                        <th>Mineral</th>
                        <th>Supplier</th>
                        <th style="text-align:right">Qty (kg)</th>
                        <th>Date</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recent_batches as $rb): ?>
                    <tr>
                        <td style="font-family:monospace;font-size:.78rem;color:var(--text-muted)"><?= htmlspecialchars($rb['batch_id']) ?></td>
                        <td style="font-weight:600"><?= htmlspecialchars($rb['mineral']) ?></td>
                        <td style="color:var(--text-sec)"><?= htmlspecialchars($rb['supplier']) ?></td>
                        <td style="text-align:right"><?= fmtKg($rb['quantity']) ?></td>
                        <td style="color:var(--text-muted);white-space:nowrap"><?= date('d M', strtotime($rb['received_date'])) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php
$extra_script = "
const revLabels  = " . json_encode($chart_labels) . ";
const revData    = " . json_encode(array_map('floatval', $chart_revenue)) . ";
const costData   = " . json_encode(array_map('floatval', $chart_cost)) . ";
const stockNames = " . json_encode(array_column($stock_rows, 'name')) . ";
const stockVals  = " . json_encode(array_map(fn($r) => (float)$r['current_stock'], $stock_rows)) . ";
const donutColors= ['#3b82f6','#f59e0b','#10b981','#ef4444','#8b5cf6','#06b6d4'];

if(document.getElementById('revenueChart') && revLabels.length){
    new Chart(document.getElementById('revenueChart'), {
        type: 'bar',
        data: {
            labels: revLabels,
            datasets: [
                { label:'Revenue', data: revData, backgroundColor:'rgba(59,130,246,.8)', borderRadius:5, borderSkipped:false },
                { label:'Cost',    data: costData, backgroundColor:'rgba(239,68,68,.6)',  borderRadius:5, borderSkipped:false }
            ]
        },
        options: {
            responsive:true,
            plugins:{ legend:{ position:'top', labels:{ font:{size:11}, boxWidth:12, padding:12 } } },
            scales:{
                x:{ grid:{display:false}, ticks:{font:{size:11}} },
                y:{ grid:{color:'rgba(0,0,0,.05)'}, ticks:{font:{size:11}, callback: v => v.toLocaleString() } }
            }
        }
    });
}

if(document.getElementById('stockDonut') && stockNames.length){
    new Chart(document.getElementById('stockDonut'), {
        type: 'doughnut',
        data: {
            labels: stockNames,
            datasets:[{ data: stockVals, backgroundColor: donutColors, borderWidth:2, borderColor:'#fff', hoverOffset:6 }]
        },
        options: {
            cutout:'68%',
            plugins:{
                legend:{ display:false },
                tooltip:{ callbacks:{ label: ctx => ' ' + ctx.label + ': ' + ctx.raw.toFixed(1) + ' kg' } }
            }
        }
    });
}
";
require 'includes/footer.php';
?>
