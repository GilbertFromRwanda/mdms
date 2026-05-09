<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX handlers ───────────────────────────────────────────── */
if(isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');

    /* Add supplier */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add'])){
        try {
            $code = 'SUP-'.date('Ymd').'-'.rand(100,999);
            $stmt = $pdo->prepare("
                INSERT INTO suppliers
                  (supplier_code,name,contact_person,phone,email,license_number,address)
                VALUES (?,?,?,?,?,?,?)
            ");
            $stmt->execute([
                $code, $_POST['name'], $_POST['contact_person'],
                $_POST['phone'], $_POST['email'],
                $_POST['license_number'], $_POST['address']
            ]);
            $id = $pdo->lastInsertId();
            logAction($pdo,$_SESSION['user_id'],'CREATE','suppliers',$id,"Added supplier: {$_POST['name']}");
            echo json_encode([
                'success'  => true,
                'message'  => "Supplier <strong>".htmlspecialchars($_POST['name'])."</strong> added.",
                'supplier' => [
                    'id'             => $id,
                    'supplier_code'  => $code,
                    'name'           => $_POST['name'],
                    'contact_person' => $_POST['contact_person'] ?? '',
                    'phone'          => $_POST['phone']          ?? '',
                    'email'          => $_POST['email']          ?? '',
                    'license_number' => $_POST['license_number'] ?? '',
                ]
            ]);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* Delete supplier */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete'])){
        try {
            $stmt = $pdo->prepare("DELETE FROM suppliers WHERE id=?");
            $stmt->execute([$_POST['id']]);
            logAction($pdo,$_SESSION['user_id'],'DELETE','suppliers',$_POST['id'],"Deleted supplier ID: {$_POST['id']}");
            echo json_encode(['success'=>true,'message'=>'Supplier removed.']);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }
}

/* ── Initial page data ───────────────────────────────────────── */
$page_title = 'Suppliers';

$filters = [
    'search'      => trim($_GET['search']      ?? ''),
    'loan_status' => $_GET['loan_status']      ?? '',
];

$where = []; $params = [];
if($filters['search']){
    $where[] = '(s.name LIKE ? OR s.contact_person LIKE ? OR s.phone LIKE ? OR s.license_number LIKE ?)';
    $s = '%'.$filters['search'].'%';
    $params = [$s, $s, $s, $s];
}
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$loan_having = '';
if($filters['loan_status'] === 'with_loan') $loan_having = 'HAVING loan_balance > 0';
if($filters['loan_status'] === 'no_loan')   $loan_having = 'HAVING loan_balance <= 0';

$stmt = $pdo->prepare("
    SELECT s.*,
           COALESCE(SUM(b.quantity),0) AS total_supplied,
           COALESCE((SELECT SUM(CASE WHEN type='loan' AND is_deferred=0 THEN amount WHEN type='repayment' THEN -amount ELSE 0 END)
                     FROM supplier_loans WHERE supplier_id=s.id),0) AS loan_balance,
           COALESCE((SELECT SUM(CASE WHEN type='loan' AND is_deferred=1 THEN amount
                                        WHEN type='repayment' AND is_deferred=1 THEN -amount
                                        ELSE 0 END)
                     FROM supplier_loans WHERE supplier_id=s.id),0) AS deferred_balance
    FROM suppliers s
    LEFT JOIN batches b ON b.supplier_id = s.id
    $where_sql
    GROUP BY s.id
    $loan_having
    ORDER BY s.created_at DESC
");
$stmt->execute($params);
$suppliers = $stmt->fetchAll();

$has_filters = (bool)array_filter($filters);

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-building" style="margin-right:.4rem;color:var(--text-muted)"></i>Supplier Management</h2>
    <button class="btn btn-primary" onclick="togglePanel()">
        <i class="fas fa-plus"></i> Add Supplier
    </button>
</div>

<!-- Add form -->
<div class="slide-panel" id="sup-panel">
    <h3><i class="fas fa-plus-circle" style="margin-right:.4rem"></i>Register New Supplier</h3>
    <form id="sup-form">
        <div class="form-grid form-grid-2">
            <div class="form-group">
                <label>Company Name</label>
                <input type="text" name="name" placeholder="ABC Mining Co." required>
            </div>
            <div class="form-group">
                <label>Contact Person</label>
                <input type="text" name="contact_person" placeholder="Full name">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" placeholder="+1 555 000 0000">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" placeholder="contact@company.com">
            </div>
            <div class="form-group">
                <label>License Number</label>
                <input type="text" name="license_number" placeholder="LIC-XXXX">
            </div>
            <div class="form-group">
                <label>Address</label>
                <textarea name="address" placeholder="Full address…"></textarea>
            </div>
        </div>
        <div class="slide-panel-btns">
            <button type="submit" id="sup-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Supplier
            </button>
            <button type="button" class="btn btn-secondary" onclick="togglePanel()">Cancel</button>
        </div>
    </form>
</div>

<!-- Filter bar -->
<form method="GET" action="suppliers.php" class="filter-bar">
    <div class="filter-group">
        <label>Search</label>
        <input type="search" name="search" placeholder="Name, contact, phone, license…"
               value="<?= htmlspecialchars($filters['search']) ?>">
    </div>
    <div class="filter-group">
        <label>Loan Status</label>
        <select name="loan_status">
            <option value="">All suppliers</option>
            <option value="with_loan" <?= $filters['loan_status']==='with_loan'?'selected':'' ?>>Has outstanding loan</option>
            <option value="no_loan"   <?= $filters['loan_status']==='no_loan'  ?'selected':'' ?>>No outstanding loan</option>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-filter"></i> Filter
        </button>
        <?php if($has_filters): ?>
        <a href="suppliers.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </div>
    <?php if($has_filters): ?>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> <?= count($suppliers) ?> result<?= count($suppliers)!==1?'s':'' ?></span>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Contact Person</th>
                <th>Phone</th>
                <th>Email</th>
                <th>License</th>
                <th style="text-align:right">Supplied (kg)</th>
                <th style="text-align:right">Loan Receivable (FRW)</th>
                <th style="text-align:right">Loan Payable (FRW)</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="sup-tbody">
        <?php
        $i=0;
        foreach($suppliers as $sup):
            $lb    = (float)$sup['loan_balance'];
            $lbPos = $lb > 0;
            $lbClr = $lbPos ? '#dc2626' : '#16a34a';
            $def   = (float)$sup['deferred_balance'];
            $defClr = $def > 0 ? '#ea580c' : '#16a34a';
        ?>
        <tr id="sup-row-<?= $sup['id'] ?>">
            <td class="font-mono text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="fw-600"><?= htmlspecialchars($sup['name']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($sup['contact_person'] ?? '') ?></td>
            <td class="text-muted"><?= htmlspecialchars($sup['phone'] ?? '') ?></td>
            <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($sup['email'] ?? '') ?></td>
            <td class="font-mono text-muted" style="font-size:.78rem"><?= htmlspecialchars($sup['license_number'] ?? '') ?></td>
            <td style="text-align:right" class="fw-600"><?= number_format($sup['total_supplied'], 3) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $lbClr ?>">
                <?= number_format(abs($lb), 2) ?>
                <div style="font-size:.7rem;font-weight:400;color:<?= $lbClr ?>"><?= $lbPos ? 'Owes us' : 'Clear' ?></div>
            </td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $defClr ?>">
                <?php if($def > 0): ?>
                <a href="loans-payable.php?supplier_id=<?= $sup['id'] ?>" style="color:<?= $defClr ?>;text-decoration:none">
                    <?= number_format($def, 2) ?>
                    <div style="font-size:.7rem;font-weight:400">We owe</div>
                </a>
                <?php else: ?>
                <span style="color:#16a34a">0.00<div style="font-size:.7rem;font-weight:400">Clear</div></span>
                <?php endif; ?>
            </td>
            <td style="text-align:center;white-space:nowrap">
                <a href="loans.php?supplier_id=<?= $sup['id'] ?>"
                   class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;margin-right:.3rem">
                    <i class="fas fa-eye"></i> Loans
                </a>
                <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem"
                        onclick="deleteSupplier(<?= $sup['id'] ?>, this)">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$suppliers): ?>
        <tr id="empty-row"><td colspan="10" style="text-align:center;padding:2rem;color:var(--text-muted)">
            <?= $has_filters ? 'No suppliers match the current filters.' : 'No suppliers yet. Add your first supplier above.' ?>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function togglePanel(){
    document.getElementById('sup-panel').classList.toggle('open');
}

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showAlert(type, msg){
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-'+type+' mb-15';
    el.innerHTML = '<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(()=>{ el.style.display='none'; }, 5000);
}

/* ── Add supplier ──────────────────────────────────────────── */
document.getElementById('sup-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('sup-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData(this);
    fd.append('add', '1');

    fetch('suppliers.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            showAlert('success', d.message);
            prependCard(d.supplier);
            togglePanel();
            this.reset();
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Supplier';
    })
    .catch(()=>{
        showAlert('error', 'Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Supplier';
    });
});

function prependCard(s){
    const tbody = document.getElementById('sup-tbody');
    const empty = document.getElementById('empty-row');
    if(empty) empty.remove();

    const tr = document.createElement('tr');
    tr.id = 'sup-row-' + s.id;
    tr.innerHTML =
        '<td class="font-mono text-muted" style="font-size:.78rem">' + esc(s.supplier_code) + '</td>' +
        '<td class="fw-600">' + esc(s.name) + '</td>' +
        '<td class="text-muted">' + esc(s.contact_person || '') + '</td>' +
        '<td class="text-muted">' + esc(s.phone || '') + '</td>' +
        '<td class="text-muted" style="font-size:.82rem">' + esc(s.email || '') + '</td>' +
        '<td class="font-mono text-muted" style="font-size:.78rem">' + esc(s.license_number || '') + '</td>' +
        '<td style="text-align:right" class="fw-600">0.000</td>' +
        '<td style="text-align:right;font-family:monospace;font-weight:700;color:#16a34a">' +
            '0.00<div style="font-size:.7rem;font-weight:400;color:#16a34a">Clear</div></td>' +
        '<td style="text-align:right;font-family:monospace;font-weight:700;color:#16a34a">' +
            '0.00<div style="font-size:.7rem;font-weight:400;color:#16a34a">Clear</div></td>' +
        '<td style="text-align:center;white-space:nowrap">' +
            '<a href="loans.php?supplier_id=' + s.id + '" class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;margin-right:.3rem">' +
                '<i class="fas fa-eye"></i> Loans</a>' +
            '<button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteSupplier(' + s.id + ', this)">' +
                '<i class="fas fa-trash"></i></button>' +
        '</td>';
    tbody.insertBefore(tr, tbody.firstChild);
}

/* ── Delete supplier ───────────────────────────────────────── */
function deleteSupplier(id, btn){
    if(!confirm('Delete this supplier?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('delete', '1');
    fd.append('id', id);

    fetch('suppliers.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            const row = document.getElementById('sup-row-'+id);
            row.style.transition = 'opacity .3s';
            row.style.opacity = '0';
            setTimeout(()=>{
                row.remove();
                if(!document.querySelector('#sup-tbody tr:not(#empty-row)')){
                    document.getElementById('sup-tbody').innerHTML =
                        '<tr id="empty-row"><td colspan="10" style="text-align:center;padding:2rem;color:var(--text-muted)">No suppliers yet.</td></tr>';
                }
            }, 300);
            showAlert('success', d.message);
        } else {
            showAlert('error', d.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i>';
        }
    })
    .catch(()=>{
        showAlert('error', 'Network error.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash"></i>';
    });
}
</script>

<?php include 'includes/footer.php'; ?>
