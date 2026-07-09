<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX: increment stock ───────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['add_stock'])){
    header('Content-Type: application/json');
    try {
        $mineralId  = (int)($_POST['mineral_type_id'] ?? 0);
        $supplierId = (int)($_POST['supplier_id'] ?? 0);
        $qty        = round(floatval($_POST['qty'] ?? 0), 3);
        $notes      = trim($_POST['notes'] ?? '');
        if($mineralId <= 0) throw new Exception('Invalid mineral.');
        if($supplierId <= 0) throw new Exception('Please select a supplier.');
        if($qty <= 0) throw new Exception('Quantity must be greater than 0.');

        $sn = $pdo->prepare("SELECT name FROM suppliers WHERE id=?");
        $sn->execute([$supplierId]);
        $supplierName = $sn->fetchColumn();
        if(!$supplierName) throw new Exception('Supplier not found.');

        $pdo->beginTransaction();

        $pdo->prepare("
            INSERT INTO supply_stock (supplier_id, mineral_id, qty, status, notes, created_by)
            VALUES (?,?,?,'in',?,?)
        ")->execute([$supplierId, $mineralId, $qty, $notes ?: null, $_SESSION['user_id']]);

        $pdo->prepare("
            INSERT INTO inventory (mineral_type_id, current_stock) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)
        ")->execute([$mineralId, $qty]);

        $stmt = $pdo->prepare("SELECT current_stock FROM inventory WHERE mineral_type_id=?");
        $stmt->execute([$mineralId]);
        $newStock = (float)$stmt->fetchColumn();

        $pdo->commit();

        logAction($pdo, $_SESSION['user_id'], 'UPDATE', 'inventory', $mineralId, "Added $qty from supplier \"$supplierName\" (new total: $newStock)");
        echo json_encode(['success'=>true,'message'=>'Stock added.','current_stock'=>$newStock]);
    } catch(Exception $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

$page_title = 'Inventory';

$today = date('Y-m-d');
$filters = [
    'search' => trim($_GET['search'] ?? ''),
    'status' => $_GET['status']      ?? '',
    'unit'   => $_GET['unit']        ?? '',
    'from'   => $_GET['from']        ?? $today,
    'to'     => $_GET['to']          ?? $today,
];

$where = []; $params = [];
if($filters['search']) { $where[] = 'mt.name LIKE ?'; $params[] = '%'.$filters['search'].'%'; }
if($filters['unit'])   { $where[] = 'mt.unit = ?';    $params[] = $filters['unit']; }
if($filters['status'] === 'low')    { $where[] = 'i.current_stock < 100'; }
if($filters['status'] === 'medium') { $where[] = 'i.current_stock >= 100 AND i.current_stock < 500'; }
if($filters['status'] === 'good')   { $where[] = 'i.current_stock >= 500'; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$stmt = $pdo->prepare("
    SELECT i.*, mt.name AS mineral_name, mt.unit,
           CAST(AVG(pd.sample) AS DECIMAL(10,2)) AS avg_sample
    FROM inventory i
    JOIN mineral_types mt ON i.mineral_type_id = mt.id
    LEFT JOIN purchase_details pd ON pd.mineral_id = i.mineral_type_id
        AND pd.purchase_date BETWEEN ? AND ?
    $where_sql
    GROUP BY i.id, mt.name, mt.unit
    ORDER BY i.current_stock DESC
");
$stmt->execute([$filters['from'], $filters['to'], ...$params]);
$inventory = $stmt->fetchAll();

$units_raw = $pdo->query("SELECT DISTINCT unit FROM mineral_types ORDER BY unit")->fetchAll(PDO::FETCH_COLUMN);
$suppliers = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$minerals  = $pdo->query("SELECT id, name, unit FROM mineral_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$has_filters = $filters['search'] || $filters['status'] || $filters['unit']
             || $filters['from'] !== $today || $filters['to'] !== $today;
$maxStock = count($inventory) ? max(array_column($inventory,'current_stock')) : 1;
if($maxStock == 0) $maxStock = 1;

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-warehouse" style="margin-right:.4rem;color:var(--text-muted)"></i>Stock Levels</h2>
    <div style="display:flex;align-items:center;gap:.7rem">
        <span class="text-muted" style="font-size:.82rem"><?= count($inventory) ?> mineral type<?= count($inventory)!==1?'s':'' ?></span>
        <button type="button" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem" onclick="openAddStockModal()">
            <i class="fas fa-plus"></i> Add Stock
        </button>
    </div>
</div>

<!-- Add Stock Modal -->
<div class="modal-backdrop" id="add-stock-modal" onclick="if(event.target===this)closeAddStockModal()">
    <div class="modal" style="max-width:380px">
        <div class="modal-header">
            <h3><i class="fas fa-plus-circle" style="margin-right:.4rem;color:var(--primary)"></i>Add Stock</h3>
            <button class="modal-close" onclick="closeAddStockModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="add-stock-form">
                <div class="form-group">
                    <label>Mineral <span style="color:var(--danger)">*</span></label>
                    <select id="as-mineral" required>
                        <option value="">— select mineral —</option>
                        <?php foreach($minerals as $min): ?>
                        <option value="<?= $min['id'] ?>"><?= htmlspecialchars($min['name']) ?> (<?= htmlspecialchars($min['unit']) ?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Supplier <span style="color:var(--danger)">*</span></label>
                    <select id="as-supplier" required>
                        <option value="">— select supplier —</option>
                        <?php foreach($suppliers as $sup): ?>
                        <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Quantity to Add <span style="color:var(--danger)">*</span></label>
                    <input type="number" id="as-qty" step="0.001" min="0.001" placeholder="0.000" required>
                </div>
                <div class="form-group">
                    <label>Notes</label>
                    <textarea id="as-notes" placeholder="Optional notes…" style="min-height:50px"></textarea>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeAddStockModal()">Cancel</button>
            <button type="submit" form="add-stock-form" id="as-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Add
            </button>
        </div>
    </div>
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
    <div class="filter-group">
        <label>Sample From</label>
        <input type="date" name="from" value="<?= htmlspecialchars($filters['from']) ?>">
    </div>
    <div class="filter-group">
        <label>Sample To</label>
        <input type="date" name="to" value="<?= htmlspecialchars($filters['to']) ?>">
    </div>
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

<div class="table-wrap text-complete">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Mineral Type</th>
                <th>Current Stock</th>
                <th>Unit</th>
                <th>Status</th>
                <th>Avg. Sample(%)</th>
                <th>Last Updated</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="inv-tbody">
            <?php foreach($inventory as $i => $item):
                $pct = round(($item['current_stock']/$maxStock)*100);
                $cls = $item['current_stock']<100 ? 'low' : ($item['current_stock']<500 ? 'medium' : '');
                $isLow = $item['current_stock'] < 100;
            ?>
            <tr id="inv-row-<?= $item['mineral_type_id'] ?>">
                <td class="text-muted"><?= $i+1 ?></td>
                <td class="fw-700"><?= htmlspecialchars($item['mineral_name']) ?></td>
                <td class="<?= $isLow ? 'low-stock' : '' ?> fw-600" id="inv-stock-<?= $item['mineral_type_id'] ?>">
                    <?= number_format($item['current_stock'],2) ?>
                </td>
                <td class="text-muted"><?= htmlspecialchars($item['unit']) ?></td>
                <td>
                    <?php if($isLow): ?>
                        <span class="badge badge-danger"><i class="fas fa-triangle-exclamation" style="margin-right:.2rem"></i>Low</span>
                    <?php elseif($cls==='medium'): ?>
                        <span class="badge badge-warning">Medium</span>
                    <?php else: ?>
                        <span class="badge badge-success">Good</span>
                    <?php endif; ?>
                </td>
                <td class="text-muted"><?= $item['avg_sample'] !== null ? number_format((float)$item['avg_sample'], 2) : '—' ?></td>
                <td class="text-muted font-mono" style="font-size:.78rem"><?= $item['last_updated'] ?></td>
                <td style="text-align:center">
                    <button class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem"
                            onclick="openAddStockModal(<?= $item['mineral_type_id'] ?>)"
                            title="Add Stock">
                        <i class="fas fa-plus"></i> Add
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
function openAddStockModal(mineralId){
    document.getElementById('as-mineral').value = mineralId || '';
    document.getElementById('as-supplier').value = '';
    document.getElementById('as-qty').value = '';
    document.getElementById('as-notes').value = '';
    document.getElementById('add-stock-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
    setTimeout(() => document.getElementById(mineralId ? 'as-supplier' : 'as-mineral').focus(), 50);
}
function closeAddStockModal(){
    document.getElementById('add-stock-modal').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeAddStockModal(); });

function showAlert(type, msg){
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-'+type+' mb-15';
    el.innerHTML = '<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(()=>{ el.style.display='none'; }, 5000);
}

document.getElementById('add-stock-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('as-save-btn');
    const mineralId  = document.getElementById('as-mineral').value;
    const supplierId = document.getElementById('as-supplier').value;
    const qty   = document.getElementById('as-qty').value;
    const notes = document.getElementById('as-notes').value;
    if(!mineralId){ showAlert('error','Please select a mineral.'); return; }
    if(!supplierId){ showAlert('error','Please select a supplier.'); return; }
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding…';

    const fd = new FormData();
    fd.append('add_stock','1');
    fd.append('mineral_type_id', mineralId);
    fd.append('supplier_id', supplierId);
    fd.append('qty', qty);
    fd.append('notes', notes);

    fetch('inventory.php', {
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest'},
        body:fd
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            showAlert('success', d.message);
            closeAddStockModal();
            const cell = document.getElementById('inv-stock-'+mineralId);
            if(cell){
                cell.textContent = parseFloat(d.current_stock).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
            } else {
                setTimeout(() => window.location.reload(), 600);
            }
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Add';
    })
    .catch(() => {
        showAlert('error','Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Add';
    });
});
</script>

<?php include 'includes/footer.php'; ?>
