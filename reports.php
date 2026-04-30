<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

$page_title = 'Reports';
$extra_head = '<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>';

$from_date = $_GET['from_date'] ?? date('Y-m-01');
$to_date   = $_GET['to_date']   ?? date('Y-m-d');

function fetchReportData($pdo, $from, $to){
    /* Summary */
    $stmt = $pdo->prepare("
        SELECT transaction_type, SUM(quantity) AS total_qty, COUNT(*) AS cnt
        FROM transactions
        WHERE transaction_date BETWEEN ? AND ?
        GROUP BY transaction_type
    ");
    $stmt->execute([$from,$to]);
    $summary = [];
    foreach($stmt->fetchAll() as $r) $summary[$r['transaction_type']] = $r;

    /* Mineral report */
    $stmt = $pdo->prepare("
        SELECT mt.name AS mineral,
            SUM(CASE WHEN t.transaction_type='IN'  THEN t.quantity ELSE 0 END) AS total_in,
            SUM(CASE WHEN t.transaction_type='OUT' THEN t.quantity ELSE 0 END) AS total_out
        FROM transactions t
        JOIN mineral_types mt ON t.mineral_type_id = mt.id
        WHERE t.transaction_date BETWEEN ? AND ?
        GROUP BY mt.id ORDER BY mt.name
    ");
    $stmt->execute([$from,$to]);
    $minerals = $stmt->fetchAll();

    /* Top suppliers */
    $stmt = $pdo->prepare("
        SELECT s.name, SUM(b.quantity) AS total_supplied
        FROM batches b
        JOIN suppliers s ON b.supplier_id = s.id
        WHERE b.received_date BETWEEN ? AND ?
        GROUP BY s.id ORDER BY total_supplied DESC LIMIT 10
    ");
    $stmt->execute([$from,$to]);
    $suppliers = $stmt->fetchAll();

    return compact('summary','minerals','suppliers');
}

/* ── AJAX: return JSON ───────────────────────────────────────── */
if(isset($_GET['ajax'])){
    header('Content-Type: application/json');
    echo json_encode(fetchReportData($pdo,$from_date,$to_date));
    exit;
}

/* ── Initial load ────────────────────────────────────────────── */
$data = fetchReportData($pdo,$from_date,$to_date);
extract($data); // $summary, $minerals, $suppliers

include 'includes/header.php';
?>

<!-- Date filter -->
<div class="card mb-15">
    <div class="card-body">
        <form id="report-form" style="display:flex;align-items:flex-end;gap:1rem;flex-wrap:wrap">
            <div class="form-group" style="margin:0">
                <label>From Date</label>
                <input type="date" name="from_date" id="from_date" value="<?= $from_date ?>">
            </div>
            <div class="form-group" style="margin:0">
                <label>To Date</label>
                <input type="date" name="to_date" id="to_date" value="<?= $to_date ?>">
            </div>
            <button type="submit" id="gen-btn" class="btn btn-primary">
                <i class="fas fa-filter"></i> Generate
            </button>
            <button type="button" class="btn btn-secondary" onclick="window.print()">
                <i class="fas fa-print"></i> Print
            </button>
        </form>
    </div>
</div>

<!-- Summary stats -->
<div class="stats-grid" id="report-stats" style="grid-template-columns:repeat(auto-fit,minmax(200px,1fr));margin-bottom:1.25rem">
    <?php
    $totalIn  = floatval($summary['IN']['total_qty']  ?? 0);
    $totalOut = floatval($summary['OUT']['total_qty'] ?? 0);
    $cntIn    = intval($summary['IN']['cnt']  ?? 0);
    $cntOut   = intval($summary['OUT']['cnt'] ?? 0);
    $net      = $totalIn - $totalOut;
    ?>
    <div class="stat-card" id="stat-in">
        <div class="stat-icon si-green"><i class="fas fa-arrow-down"></i></div>
        <div class="stat-info">
            <div class="stat-label">Total IN</div>
            <div class="stat-value"><?= number_format($totalIn/1000,1) ?>t</div>
            <div class="stat-sub"><?= $cntIn ?> transactions</div>
        </div>
    </div>
    <div class="stat-card" id="stat-out">
        <div class="stat-icon si-red"><i class="fas fa-arrow-up"></i></div>
        <div class="stat-info">
            <div class="stat-label">Total OUT</div>
            <div class="stat-value"><?= number_format($totalOut/1000,1) ?>t</div>
            <div class="stat-sub"><?= $cntOut ?> transactions</div>
        </div>
    </div>
    <div class="stat-card" id="stat-net">
        <div class="stat-icon si-blue"><i class="fas fa-scale-balanced"></i></div>
        <div class="stat-info">
            <div class="stat-label">Net Balance</div>
            <div class="stat-value" style="color:<?= $net>=0?'var(--success)':'var(--danger)' ?>"><?= number_format($net/1000,1) ?>t</div>
            <div class="stat-sub"><?= number_format($net,0) ?> kg net</div>
        </div>
    </div>
    <div class="stat-card" id="stat-sup">
        <div class="stat-icon si-amber"><i class="fas fa-building"></i></div>
        <div class="stat-info">
            <div class="stat-label">Active Suppliers</div>
            <div class="stat-value"><?= count($suppliers) ?></div>
            <div class="stat-sub">This period</div>
        </div>
    </div>
</div>

<!-- Chart + Top suppliers -->
<div class="grid grid-2" style="margin-bottom:1.25rem;align-items:start">
    <div class="card">
        <div class="card-header"><span><i class="fas fa-chart-bar" style="margin-right:.4rem;color:var(--text-muted)"></i>Mineral Flow</span></div>
        <div class="card-body" style="position:relative;height:260px"><canvas id="mineralChart"></canvas></div>
    </div>
    <div class="card">
        <div class="card-header"><span><i class="fas fa-trophy" style="margin-right:.4rem;color:var(--text-muted)"></i>Top Suppliers</span></div>
        <div style="overflow-x:auto">
            <table>
                <thead><tr><th>#</th><th>Supplier</th><th>Supplied (kg)</th></tr></thead>
                <tbody id="sup-tbody">
                    <?php foreach($suppliers as $i => $sup): ?>
                    <tr>
                        <td class="text-muted"><?= $i+1 ?></td>
                        <td class="fw-600"><?= htmlspecialchars($sup['name']) ?></td>
                        <td><?= number_format($sup['total_supplied'],2) ?></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(!$suppliers): ?>
                    <tr><td colspan="3" style="text-align:center;padding:1.5rem;color:var(--text-muted)">No data.</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Detailed report -->
<div class="card">
    <div class="card-header"><span><i class="fas fa-table" style="margin-right:.4rem;color:var(--text-muted)"></i>Mineral Detail</span></div>
    <div style="overflow-x:auto">
        <table>
            <thead><tr><th>Mineral</th><th>IN (kg)</th><th>OUT (kg)</th><th>Net (kg)</th></tr></thead>
            <tbody id="mineral-tbody">
                <?php foreach($minerals as $m): $n=$m['total_in']-$m['total_out']; ?>
                <tr>
                    <td class="fw-600"><?= htmlspecialchars($m['mineral']) ?></td>
                    <td class="text-success fw-600"><?= number_format($m['total_in'],2) ?></td>
                    <td class="text-danger fw-600"><?= number_format($m['total_out'],2) ?></td>
                    <td class="fw-700" style="color:<?= $n>=0?'var(--success)':'var(--danger)' ?>"><?= number_format($n,2) ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$minerals): ?>
                <tr><td colspan="4" style="text-align:center;padding:1.5rem;color:var(--text-muted)">No transactions in this range.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
/* ── Build chart ─────────────────────────────────────────────── */
let chart = null;

function buildChart(labels, dataIn, dataOut){
    const ctx = document.getElementById('mineralChart').getContext('2d');
    if(chart) chart.destroy();
    if(!labels.length){ chart = null; return; }
    chart = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                { label:'IN (kg)',  data:dataIn,  backgroundColor:'rgba(16,185,129,.7)', borderColor:'#10b981', borderWidth:1.5, borderRadius:4 },
                { label:'OUT (kg)', data:dataOut, backgroundColor:'rgba(239,68,68,.7)',  borderColor:'#ef4444', borderWidth:1.5, borderRadius:4 }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins:{ legend:{ position:'top' } },
            scales:{
                y:{ beginAtZero:true, grid:{ color:'rgba(0,0,0,.05)' } },
                x:{ grid:{ display:false } }
            }
        }
    });
}

/* Initial chart */
buildChart(
    <?= json_encode(array_column($minerals,'mineral')) ?>,
    <?= json_encode(array_map('floatval', array_column($minerals,'total_in'))) ?>,
    <?= json_encode(array_map('floatval', array_column($minerals,'total_out'))) ?>
);

/* ── AJAX filter ─────────────────────────────────────────────── */
function fmt(n){ return parseFloat(n||0); }
function fmtT(n){ return (fmt(n)/1000).toFixed(1)+'t'; }
function fmtKg(n){ return fmt(n).toLocaleString(undefined,{minimumFractionDigits:2}); }
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }

document.getElementById('report-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('gen-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Loading…';

    const params = new URLSearchParams({
        from_date: document.getElementById('from_date').value,
        to_date:   document.getElementById('to_date').value,
        ajax:      '1'
    });

    fetch('reports.php?' + params.toString())
    .then(r => r.json())
    .then(d => {
        updateStats(d.summary);
        updateSuppliers(d.suppliers);
        updateMineralTable(d.minerals);
        buildChart(
            d.minerals.map(m=>m.mineral),
            d.minerals.map(m=>fmt(m.total_in)),
            d.minerals.map(m=>fmt(m.total_out))
        );
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-filter"></i> Generate';
    })
    .catch(()=>{
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-filter"></i> Generate';
    });
});

function updateStats(s){
    const tin  = fmt(s.IN  ? s.IN.total_qty  : 0);
    const tout = fmt(s.OUT ? s.OUT.total_qty : 0);
    const cin  = s.IN  ? s.IN.cnt  : 0;
    const cout = s.OUT ? s.OUT.cnt : 0;
    const net  = tin - tout;

    document.querySelector('#stat-in  .stat-value').textContent = fmtT(tin);
    document.querySelector('#stat-in  .stat-sub').textContent   = cin+' transactions';
    document.querySelector('#stat-out .stat-value').textContent = fmtT(tout);
    document.querySelector('#stat-out .stat-sub').textContent   = cout+' transactions';
    const netEl = document.querySelector('#stat-net .stat-value');
    netEl.textContent = fmtT(net);
    netEl.style.color = net >= 0 ? 'var(--success)' : 'var(--danger)';
    document.querySelector('#stat-net .stat-sub').textContent = fmtKg(net)+' kg net';
}

function updateSuppliers(list){
    const tb = document.getElementById('sup-tbody');
    if(!list.length){
        tb.innerHTML = '<tr><td colspan="3" style="text-align:center;padding:1.5rem;color:var(--text-muted)">No data.</td></tr>';
        document.querySelector('#stat-sup .stat-value').textContent = '0';
        return;
    }
    document.querySelector('#stat-sup .stat-value').textContent = list.length;
    tb.innerHTML = list.map((s,i)=>
        `<tr><td class="text-muted">${i+1}</td><td class="fw-600">${esc(s.name)}</td><td>${fmtKg(s.total_supplied)}</td></tr>`
    ).join('');
}

function updateMineralTable(list){
    const tb = document.getElementById('mineral-tbody');
    if(!list.length){
        tb.innerHTML = '<tr><td colspan="4" style="text-align:center;padding:1.5rem;color:var(--text-muted)">No transactions in this range.</td></tr>';
        return;
    }
    tb.innerHTML = list.map(m => {
        const n = fmt(m.total_in) - fmt(m.total_out);
        const c = n>=0?'var(--success)':'var(--danger)';
        return `<tr>
            <td class="fw-600">${esc(m.mineral)}</td>
            <td class="text-success fw-600">${fmtKg(m.total_in)}</td>
            <td class="text-danger fw-600">${fmtKg(m.total_out)}</td>
            <td class="fw-700" style="color:${c}">${fmtKg(n)}</td>
        </tr>`;
    }).join('');
}
</script>

<?php include 'includes/footer.php'; ?>
