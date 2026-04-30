<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX handlers ───────────────────────────────────────────── */
if(isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');

    /* Add buyer */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add'])){
        try {
            $code = 'BUY-'.date('Ymd').'-'.rand(100,999);
            $stmt = $pdo->prepare("
                INSERT INTO buyers (buyer_code,name,contact,phone,email,address)
                VALUES (?,?,?,?,?,?)
            ");
            $stmt->execute([
                $code, $_POST['name'], $_POST['contact'] ?? '',
                $_POST['phone'] ?? '', $_POST['email'] ?? '', $_POST['address'] ?? ''
            ]);
            $id = $pdo->lastInsertId();
            logAction($pdo,$_SESSION['user_id'],'CREATE','buyers',$id,"Added buyer: {$_POST['name']}");
            echo json_encode([
                'success' => true,
                'message' => "Buyer <strong>".htmlspecialchars($_POST['name'])."</strong> added.",
                'buyer'   => [
                    'id'         => $id,
                    'buyer_code' => $code,
                    'name'       => $_POST['name'],
                    'contact'    => $_POST['contact']  ?? '',
                    'phone'      => $_POST['phone']    ?? '',
                    'email'      => $_POST['email']    ?? '',
                ]
            ]);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* Delete buyer */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete'])){
        try {
            $pdo->prepare("DELETE FROM buyers WHERE id=?")->execute([$_POST['id']]);
            logAction($pdo,$_SESSION['user_id'],'DELETE','buyers',$_POST['id'],"Deleted buyer ID: {$_POST['id']}");
            echo json_encode(['success'=>true,'message'=>'Buyer removed.']);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }
}

/* ── Page data ───────────────────────────────────────────────── */
$page_title = 'Buyers';

$filters = ['search' => trim($_GET['search'] ?? '')];

$where = []; $params = [];
if($filters['search']){
    $where[] = '(b.name LIKE ? OR b.contact LIKE ? OR b.phone LIKE ? OR b.buyer_code LIKE ?)';
    $s = '%'.$filters['search'].'%';
    $params = [$s, $s, $s, $s];
}
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$stmt = $pdo->prepare("
    SELECT b.*,
           COUNT(s.id)               AS sale_count,
           COALESCE(SUM(s.quantity),0) AS total_sold
    FROM buyers b
    LEFT JOIN sales s ON s.buyer_id = b.id
    $where_sql
    GROUP BY b.id
    ORDER BY b.created_at DESC
");
$stmt->execute($params);
$buyers = $stmt->fetchAll();

$has_filters = (bool)array_filter($filters);

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-handshake" style="margin-right:.4rem;color:var(--text-muted)"></i>Buyer Management</h2>
    <button class="btn btn-primary" style="background:#10b981;border-color:#10b981" onclick="togglePanel()">
        <i class="fas fa-plus"></i> Add Buyer
    </button>
</div>

<!-- Add form -->
<div class="slide-panel" id="buy-panel">
    <h3><i class="fas fa-plus-circle" style="margin-right:.4rem"></i>Register New Buyer</h3>
    <form id="buy-form">
        <div class="form-grid form-grid-2">
            <div class="form-group">
                <label>Company / Person Name</label>
                <input type="text" name="name" placeholder="ABC Minerals Ltd." required>
            </div>
            <div class="form-group">
                <label>Contact Person</label>
                <input type="text" name="contact" placeholder="Full name">
            </div>
            <div class="form-group">
                <label>Phone</label>
                <input type="text" name="phone" placeholder="+250 7XX XXX XXX">
            </div>
            <div class="form-group">
                <label>Email</label>
                <input type="email" name="email" placeholder="buyer@company.com">
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Address</label>
                <textarea name="address" placeholder="Full address…"></textarea>
            </div>
        </div>
        <div class="slide-panel-btns">
            <button type="submit" id="buy-save-btn" class="btn btn-primary" style="background:#10b981;border-color:#10b981">
                <i class="fas fa-save"></i> Save Buyer
            </button>
            <button type="button" class="btn btn-secondary" onclick="togglePanel()">Cancel</button>
        </div>
    </form>
</div>

<!-- Filter bar -->
<form method="GET" action="buyers.php" class="filter-bar">
    <div class="filter-group">
        <label>Search</label>
        <input type="search" name="search" placeholder="Name, contact, phone, code…"
               value="<?= htmlspecialchars($filters['search']) ?>">
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem;background:#10b981;border-color:#10b981">
            <i class="fas fa-filter"></i> Filter
        </button>
        <?php if($has_filters): ?>
        <a href="buyers.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </div>
    <?php if($has_filters): ?>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> <?= count($buyers) ?> result<?= count($buyers)!==1?'s':'' ?></span>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Name</th>
                <th>Contact</th>
                <th>Phone</th>
                <th>Email</th>
                <th style="text-align:right">Sales</th>
                <th style="text-align:right">Total Qty (kg)</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="buy-tbody">
        <?php
        $i=0;
        foreach($buyers as $b): ?>
        <tr id="buy-row-<?= $b['id'] ?>">
            <td class="font-mono text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="fw-600"><?= htmlspecialchars($b['name']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($b['contact'] ?? '') ?></td>
            <td class="text-muted"><?= htmlspecialchars($b['phone'] ?? '') ?></td>
            <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($b['email'] ?? '') ?></td>
            <td style="text-align:right" class="text-muted"><?= (int)$b['sale_count'] ?></td>
            <td style="text-align:right" class="fw-600"><?= number_format($b['total_sold'], 3) ?></td>
            <td style="text-align:center;white-space:nowrap">
                <a href="sales.php?buyer_id=<?= $b['id'] ?>"
                   class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;margin-right:.3rem"
                   title="View sales">
                    <i class="fas fa-eye"></i> Sales
                </a>
                <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem"
                        onclick="deleteBuyer(<?= $b['id'] ?>, this)">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$buyers): ?>
        <tr id="empty-row"><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">
            <?= $has_filters ? 'No buyers match the current filters.' : 'No buyers yet. Add your first buyer above.' ?>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
function togglePanel(){
    document.getElementById('buy-panel').classList.toggle('open');
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

/* ── Add buyer ─────────────────────────────────────────────── */
document.getElementById('buy-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('buy-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData(this);
    fd.append('add', '1');

    fetch('buyers.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            showAlert('success', d.message);
            prependRow(d.buyer);
            togglePanel();
            this.reset();
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Buyer';
    })
    .catch(()=>{
        showAlert('error', 'Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Buyer';
    });
});

function prependRow(b){
    const tbody = document.getElementById('buy-tbody');
    const empty = document.getElementById('empty-row');
    if(empty) empty.remove();

    const tr = document.createElement('tr');
    tr.id = 'buy-row-' + b.id;
    tr.innerHTML =
        '<td class="font-mono text-muted" style="font-size:.78rem">' + esc(b.buyer_code) + '</td>' +
        '<td class="fw-600">' + esc(b.name) + '</td>' +
        '<td class="text-muted">' + esc(b.contact || '') + '</td>' +
        '<td class="text-muted">' + esc(b.phone || '') + '</td>' +
        '<td class="text-muted" style="font-size:.82rem">' + esc(b.email || '') + '</td>' +
        '<td style="text-align:right" class="text-muted">0</td>' +
        '<td style="text-align:right" class="fw-600">0.000</td>' +
        '<td style="text-align:center;white-space:nowrap">' +
            '<a href="sales.php?buyer_id=' + b.id + '" class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;margin-right:.3rem">' +
                '<i class="fas fa-eye"></i> Sales</a>' +
            '<button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteBuyer(' + b.id + ', this)">' +
                '<i class="fas fa-trash"></i></button>' +
        '</td>';
    tbody.insertBefore(tr, tbody.firstChild);
}

/* ── Delete buyer ──────────────────────────────────────────── */
function deleteBuyer(id, btn){
    if(!confirm('Delete this buyer? This cannot be undone.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('delete', '1');
    fd.append('id', id);

    fetch('buyers.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            const row = document.getElementById('buy-row-'+id);
            row.style.transition = 'opacity .3s';
            row.style.opacity = '0';
            setTimeout(()=>{
                row.remove();
                if(!document.querySelector('#buy-tbody tr:not(#empty-row)')){
                    document.getElementById('buy-tbody').innerHTML =
                        '<tr id="empty-row"><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No buyers yet.</td></tr>';
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
