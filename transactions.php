<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX: add OUT transaction ───────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    try {
        $mid = $_POST['mineral_type_id'];
        $qty = floatval($_POST['quantity']);
        $pu  = isset($_POST['price_per_unit']) && $_POST['price_per_unit']!=='' ? floatval($_POST['price_per_unit']) : null;
        $ta  = $pu !== null ? round($pu * $qty, 2) : null;

        $pdo->beginTransaction();

        /* Lock the inventory row so concurrent dispatches can't over-spend stock */
        $stmt = $pdo->prepare("SELECT current_stock FROM inventory WHERE mineral_type_id=? FOR UPDATE");
        $stmt->execute([$mid]);
        $stock = floatval($stmt->fetch()['current_stock'] ?? 0);

        if($qty <= 0){
            $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'Quantity must be greater than zero.']);
            exit;
        }
        if($stock < $qty){
            $pdo->rollBack();
            echo json_encode([
                'success' => false,
                'message' => 'Insufficient stock! Available: <strong>'.number_format($stock,2).' kg</strong>'
            ]);
            exit;
        }

        $tc = 'OUT-'.date('YmdHis');
        $pdo->prepare("
            INSERT INTO transactions
              (transaction_code,transaction_type,mineral_type_id,quantity,
               transaction_date,price_per_unit,total_amount,
               recipient_company,driver_name,vehicle_number,notes,created_by)
            VALUES (?,'OUT',?,?,?,?,?,?,?,?,?,?)
        ")->execute([
            $tc, $mid, $qty,
            $_POST['transaction_date'], $pu, $ta,
            $_POST['recipient_company'],
            $_POST['driver_name'],       $_POST['vehicle_number'],
            $_POST['notes'],             $_SESSION['user_id']
        ]);
        $lastId = $pdo->lastInsertId();
        logAction($pdo,$_SESSION['user_id'],'CREATE','transactions',$lastId,"OUT transaction: $tc");

        $stmt2 = $pdo->prepare("SELECT current_stock FROM inventory WHERE mineral_type_id=?");
        $stmt2->execute([$mid]);
        $newStock = floatval($stmt2->fetch()['current_stock'] ?? ($stock - $qty));

        $mn = $pdo->prepare("SELECT name FROM mineral_types WHERE id=?");
        $mn->execute([$mid]);
        $mineralName = $mn->fetch()['name'];

        $pdo->commit();

        echo json_encode([
            'success'   => true,
            'message'   => "Dispatch <strong>$tc</strong> recorded.",
            'transaction' => [
                'transaction_code' => $tc,
                'transaction_type' => 'OUT',
                'mineral_name'     => $mineralName,
                'quantity'         => $qty,
                'price_per_unit'   => $pu,
                'total_amount'     => $ta,
                'transaction_date' => $_POST['transaction_date'],
                'recipient_company'=> $_POST['recipient_company'] ?? '—',
            ],
            'new_stock'      => $newStock,
            'mineral_type_id'=> intval($mid),
        ]);
    } catch(Exception $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

/* ── Initial page data ───────────────────────────────────────── */
$page_title = 'Transactions';

$inventory = $pdo->query("
    SELECT mt.id, mt.name, i.current_stock
    FROM inventory i
    JOIN mineral_types mt ON i.mineral_type_id = mt.id
    ORDER BY mt.name
")->fetchAll();

$minerals_list = $pdo->query("SELECT id, name FROM mineral_types ORDER BY name")->fetchAll();

$sm_raw   = $pdo->query("SELECT mineral_type_id,quality_grade,selling_price FROM mineral_price_settings ORDER BY quality_grade")->fetchAll();
$sell_map = [];
foreach($sm_raw as $p){ $sell_map[$p['mineral_type_id']][$p['quality_grade']] = (float)$p['selling_price']; }

/* ── Filters & pagination ───────────────────────────────────── */
$filters = [
    'search'          => trim($_GET['search']     ?? ''),
    'type'            => $_GET['type']            ?? '',
    'mineral_type_id' => $_GET['mineral_type_id'] ?? '',
    'date_from'       => $_GET['date_from']       ?? '',
    'date_to'         => $_GET['date_to']         ?? '',
];
$per_page = 25;
$page     = max(1, intval($_GET['page'] ?? 1));

$where = []; $params = [];
if($filters['search'])          { $where[] = '(t.transaction_code LIKE ? OR t.recipient_company LIKE ?)'; $params[] = '%'.$filters['search'].'%'; $params[] = '%'.$filters['search'].'%'; }
if($filters['type'])            { $where[] = 't.transaction_type=?';  $params[] = $filters['type']; }
if($filters['mineral_type_id']) { $where[] = 't.mineral_type_id=?';   $params[] = $filters['mineral_type_id']; }
if($filters['date_from'])       { $where[] = 't.transaction_date>=?'; $params[] = $filters['date_from']; }
if($filters['date_to'])         { $where[] = 't.transaction_date<=?'; $params[] = $filters['date_to']; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$count_s = $pdo->prepare("SELECT COUNT(*) FROM transactions t $where_sql");
$count_s->execute($params);
$total       = (int)$count_s->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT t.*, mt.name AS mineral_name
    FROM transactions t
    JOIN mineral_types mt ON t.mineral_type_id = mt.id
    $where_sql
    ORDER BY t.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$transactions = $stmt->fetchAll();

$has_filters = (bool)array_filter($filters);

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-right-left" style="margin-right:.4rem;color:var(--text-muted)"></i>Transactions</h2>
    <button class="btn btn-primary" onclick="openModal()">
        <i class="fas fa-truck"></i> New Dispatch
    </button>
</div>

<!-- Dispatch modal -->
<div class="modal-backdrop" id="dispatch-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal">
        <div class="modal-header">
            <h3><i class="fas fa-arrow-up-from-bracket" style="margin-right:.4rem;color:var(--danger)"></i>Process Dispatch (OUT)</h3>
            <button class="modal-close" onclick="closeModal()" type="button" aria-label="Close">
                <i class="fas fa-xmark"></i>
            </button>
        </div>
        <div class="modal-body">
            <form id="dispatch-form">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Mineral Type</label>
                        <select name="mineral_type_id" id="mineral-select" required onchange="updateStock(this)">
                            <option value="">— Select mineral —</option>
                            <?php foreach($inventory as $inv): ?>
                            <option value="<?= $inv['id'] ?>"
                                    data-stock="<?= $inv['current_stock'] ?>"
                                    data-name="<?= htmlspecialchars($inv['name']) ?>">
                                <?= htmlspecialchars($inv['name']) ?> — <?= number_format($inv['current_stock'],2) ?> kg
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity (kg)</label>
                        <input type="number" step="0.001" name="quantity" id="qty-input"
                               placeholder="0.000" required oninput="onQtyChange()">
                        <small class="err" id="stock-warn"></small>
                    </div>
                    <div class="form-group">
                        <label>Quality Grade</label>
                        <select name="quality_grade" id="grade-sel" onchange="onGradeChange()">
                            <option value="">— No grade selected —</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Selling Price / unit</label>
                        <div style="display:flex;gap:.5rem;align-items:center">
                            <input type="number" name="price_per_unit" id="price-input"
                                   step="0.01" min="0" placeholder="0.00" style="flex:1" oninput="calcTotal()">
                            <span class="badge badge-success" id="price-badge" style="display:none">From settings</span>
                        </div>
                    </div>
                    <div class="form-group" id="total-group" style="display:none">
                        <label>Total Amount</label>
                        <input type="text" id="total-display" readonly
                               style="background:var(--bg);color:var(--text-muted);cursor:default">
                    </div>
                    <div class="form-group">
                        <label>Transaction Date</label>
                        <input type="date" name="transaction_date" required value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Recipient Company</label>
                        <input type="text" name="recipient_company" placeholder="Company name" required>
                    </div>
                    <div class="form-group">
                        <label>Driver Name</label>
                        <input type="text" name="driver_name" placeholder="Full name">
                    </div>
                    <div class="form-group">
                        <label>Vehicle Number</label>
                        <input type="text" name="vehicle_number" placeholder="e.g. ABC-1234">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Optional notes…"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="dispatch-form" id="dispatch-btn" class="btn btn-primary">
                <i class="fas fa-truck"></i> Process Dispatch
            </button>
        </div>
    </div>
</div>

<!-- Filter bar -->
<form method="GET" action="transactions.php" class="filter-bar">
    <div class="filter-group">
        <label>Search</label>
        <input type="search" name="search" placeholder="Code or recipient…"
               value="<?= htmlspecialchars($filters['search']) ?>">
    </div>
    <div class="filter-group">
        <label>Type</label>
        <select name="type">
            <option value="">All types</option>
            <option value="IN"  <?= $filters['type']==='IN' ?'selected':'' ?>>IN (received)</option>
            <option value="OUT" <?= $filters['type']==='OUT'?'selected':'' ?>>OUT (dispatched)</option>
        </select>
    </div>
    <div class="filter-group">
        <label>Mineral</label>
        <select name="mineral_type_id">
            <option value="">All minerals</option>
            <?php foreach($minerals_list as $m): ?>
            <option value="<?= $m['id'] ?>" <?= $filters['mineral_type_id']==$m['id']?'selected':'' ?>>
                <?= htmlspecialchars($m['name']) ?>
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
        <a href="transactions.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </div>
    <?php if($has_filters): ?>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> Filtered — <?= $total ?> result<?= $total!==1?'s':'' ?></span>
    <?php endif; ?>
</form>

<!-- Transactions table (full width) -->
<div class="card">
    <div class="card-header">
        <span><i class="fas fa-clock-rotate-left" style="margin-right:.4rem;color:var(--text-muted)"></i>
            <?= $has_filters ? 'Filtered Transactions' : 'Recent Transactions' ?>
        </span>
        <span class="badge badge-secondary" id="txn-count"><?= $total ?></span>
    </div>
    <div style="overflow-x:auto">
        <table>
            <thead>
                <tr>
                    <th>Code</th><th>Type</th><th>Mineral</th>
                    <th>Qty (kg)</th>
                    <th style="text-align:right">Price/unit</th>
                    <th style="text-align:right">Total</th>
                    <th>Date</th><th>Recipient</th>
                </tr>
            </thead>
            <tbody id="txn-tbody">
                <?php foreach($transactions as $t): ?>
                <tr>
                    <td class="font-mono" style="font-size:.78rem"><?= htmlspecialchars($t['transaction_code']) ?></td>
                    <td>
                        <span class="badge <?= $t['transaction_type']==='IN'?'badge-success':'badge-danger' ?>">
                            <?= $t['transaction_type'] ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($t['mineral_name']) ?></td>
                    <td class="fw-600"><?= number_format($t['quantity'],2) ?></td>
                    <td style="text-align:right" class="fw-600">
                        <?= $t['price_per_unit'] !== null ? '$'.number_format($t['price_per_unit'],2) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td style="text-align:right" class="fw-600">
                        <?= $t['total_amount'] !== null ? '$'.number_format($t['total_amount'],2) : '<span class="text-muted">—</span>' ?>
                    </td>
                    <td class="text-muted"><?= $t['transaction_date'] ?></td>
                    <td class="text-muted"><?= htmlspecialchars($t['recipient_company']??'—') ?></td>
                </tr>
                <?php endforeach; ?>
                <?php if(!$transactions): ?>
                <tr id="empty-row"><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">
                    <?= $has_filters ? 'No transactions match the current filters.' : 'No transactions yet.' ?>
                </td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if($total_pages > 1): ?>
    <?= paginate($page, $total_pages, $filters, 'transactions.php') ?>
    <p class="pagination-info" style="padding-bottom:.5rem">
        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> of <?= $total ?> transactions
    </p>
    <?php endif; ?>
</div>

<script>
let txnCount     = <?= $total ?>;
let currentStock = 0;
const sellMap    = <?= json_encode($sell_map) ?>;
const hasFilters = <?= $has_filters ? 'true' : 'false' ?>;

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showAlert(type, msg){
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-'+type+' mb-15';
    el.innerHTML = '<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(()=>{ el.style.display='none'; }, 5000);
}

function openModal(){
    document.getElementById('dispatch-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(){
    document.getElementById('dispatch-modal').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeModal(); });

function updateStock(sel){
    currentStock = parseFloat(sel.options[sel.selectedIndex]?.dataset.stock || 0);
    document.getElementById('qty-input').max = currentStock;
    checkStock();
    updateGrades(sel.value);
}

function onQtyChange(){ checkStock(); calcTotal(); }

function checkStock(){
    const qty  = parseFloat(document.getElementById('qty-input').value) || 0;
    const warn = document.getElementById('stock-warn');
    warn.textContent = qty > 0 && currentStock > 0 && qty > currentStock
        ? 'Exceeds stock! Max: '+currentStock.toFixed(2)+' kg'
        : '';
}

function updateGrades(mid){
    const grades = sellMap[mid] || {};
    const sel    = document.getElementById('grade-sel');
    sel.innerHTML = '<option value="">— No grade selected —</option>';
    Object.entries(grades).forEach(([g, price]) => {
        const opt = new Option(g, g);
        opt.dataset.price = price;
        sel.appendChild(opt);
    });
    onGradeChange();
}

function onGradeChange(){
    const sel   = document.getElementById('grade-sel');
    const input = document.getElementById('price-input');
    const badge = document.getElementById('price-badge');
    const opt   = sel.options[sel.selectedIndex];
    if(opt && opt.dataset.price !== undefined){
        input.value = parseFloat(opt.dataset.price).toFixed(2);
        badge.style.display = 'inline-flex';
    } else {
        badge.style.display = 'none';
    }
    calcTotal();
}

function calcTotal(){
    const qty   = parseFloat(document.getElementById('qty-input').value)   || 0;
    const price = parseFloat(document.getElementById('price-input').value) || 0;
    const group = document.getElementById('total-group');
    if(qty > 0 && price > 0){
        document.getElementById('total-display').value = '$' + (qty * price).toFixed(2);
        group.style.display = '';
    } else {
        group.style.display = 'none';
    }
}

document.getElementById('dispatch-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('dispatch-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing…';

    fetch('transactions.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            showAlert('success', d.message);
            if(!hasFilters) prependRow(d.transaction);
            updateSelectStock(d.mineral_type_id, d.new_stock, d.transaction.mineral_name);
            closeModal();
            this.reset();
            this.querySelector('[name=transaction_date]').value = new Date().toISOString().slice(0,10);
            document.getElementById('stock-warn').textContent = '';
            document.getElementById('grade-sel').innerHTML = '<option value="">— No grade selected —</option>';
            document.getElementById('price-input').value = '';
            document.getElementById('price-badge').style.display = 'none';
            document.getElementById('total-group').style.display = 'none';
            currentStock = 0;
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-truck"></i> Process Dispatch';
    })
    .catch(()=>{
        showAlert('error', 'Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-truck"></i> Process Dispatch';
    });
});

function prependRow(t){
    const tbody = document.getElementById('txn-tbody');
    const empty = document.getElementById('empty-row');
    if(empty) empty.remove();
    const tr = document.createElement('tr');
    tr.innerHTML =
        '<td class="font-mono" style="font-size:.78rem">'+esc(t.transaction_code)+'</td>'+
        '<td><span class="badge badge-danger">OUT</span></td>'+
        '<td>'+esc(t.mineral_name)+'</td>'+
        '<td class="fw-600">'+parseFloat(t.quantity).toFixed(2)+'</td>'+
        '<td style="text-align:right" class="fw-600">'+(t.price_per_unit != null ? '$'+parseFloat(t.price_per_unit).toFixed(2) : '<span class="text-muted">—</span>')+'</td>'+
        '<td style="text-align:right" class="fw-600">'+(t.total_amount   != null ? '$'+parseFloat(t.total_amount).toFixed(2)   : '<span class="text-muted">—</span>')+'</td>'+
        '<td class="text-muted">'+esc(t.transaction_date)+'</td>'+
        '<td class="text-muted">'+esc(t.recipient_company)+'</td>';
    tbody.insertBefore(tr, tbody.firstChild);
    txnCount++;
    document.getElementById('txn-count').textContent = txnCount;
}

function updateSelectStock(mid, newStock, name){
    const opt = document.querySelector('#mineral-select option[value="'+mid+'"]');
    if(opt){
        opt.dataset.stock = newStock;
        opt.textContent   = name+' — '+newStock.toFixed(2)+' kg';
    }
    document.getElementById('qty-input').max = newStock;
}
</script>

<?php include 'includes/footer.php'; ?>
