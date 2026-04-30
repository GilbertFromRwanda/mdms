<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX: delete loan record ───────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['delete'])){
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if(!$id) throw new Exception('Invalid record.');
        $row = $pdo->prepare("SELECT * FROM supplier_loans WHERE id=?");
        $row->execute([$id]);
        $rec = $row->fetch();
        if(!$rec) throw new Exception('Record not found.');
        $pdo->prepare("DELETE FROM supplier_loans WHERE id=?")->execute([$id]);
        logAction($pdo, $_SESSION['user_id'], 'DELETE', 'supplier_loans', $id,
            "Deleted {$rec['type']} of {$rec['amount']} FRW for supplier #{$rec['supplier_id']}");
        $bs = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' THEN amount ELSE -amount END),0) FROM supplier_loans WHERE supplier_id=?");
        $bs->execute([$rec['supplier_id']]);
        echo json_encode(['success' => true, 'message' => 'Record deleted.', 'supplier_id' => (int)$rec['supplier_id'], 'new_balance' => (float)$bs->fetchColumn()]);
    } catch(Exception $e){
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── AJAX: record loan / repayment ──────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    try {
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $type        = $_POST['type'] ?? '';
        $amount      = floatval($_POST['amount'] ?? 0);
        $notes       = trim($_POST['notes'] ?? '');

        if(!$supplier_id)                          throw new Exception('Please select a supplier.');
        if(!in_array($type, ['loan','repayment'])) throw new Exception('Invalid type.');
        if($amount <= 0)                           throw new Exception('Amount must be greater than 0.');

        if($type === 'repayment'){
            $cb = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' THEN amount ELSE -amount END),0) FROM supplier_loans WHERE supplier_id=?");
            $cb->execute([$supplier_id]);
            $current_balance = (float)$cb->fetchColumn();
            if($current_balance <= 0)
                throw new Exception('This supplier has no outstanding loan to repay.');
            if($amount > $current_balance)
                throw new Exception('Repayment of ' . number_format($amount,2) . ' FRW exceeds the outstanding balance of ' . number_format($current_balance,2) . ' FRW.');
        }

        $pdo->prepare("
            INSERT INTO supplier_loans (supplier_id, batch_id, type, amount, notes, created_by)
            VALUES (?, NULL, ?, ?, ?, ?)
        ")->execute([$supplier_id, $type, $amount, $notes, $_SESSION['user_id']]);
        $newId = $pdo->lastInsertId();

        logAction($pdo, $_SESSION['user_id'], 'CREATE', 'supplier_loans', $newId,
            ($type === 'loan' ? 'Loan given' : 'Repayment recorded') . " for supplier #$supplier_id: $amount FRW");

        // New balance
        $bs = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' THEN amount ELSE -amount END),0) AS bal FROM supplier_loans WHERE supplier_id=?");
        $bs->execute([$supplier_id]);
        $new_balance = (float)$bs->fetchColumn();

        // Fetch the row to return for prepending
        $rs = $pdo->prepare("
            SELECT sl.*, s.name AS supplier_name, u.username AS created_by_name
            FROM supplier_loans sl
            JOIN suppliers s ON sl.supplier_id = s.id
            LEFT JOIN users u ON sl.created_by = u.id
            WHERE sl.id = ?
        ");
        $rs->execute([$newId]);
        $row = $rs->fetch(PDO::FETCH_ASSOC);

        echo json_encode([
            'success'      => true,
            'message'      => ucfirst($type) . ' of ' . number_format($amount, 2) . ' FRW recorded.',
            'row'          => $row,
            'new_balance'  => $new_balance,
            'supplier_id'  => $supplier_id,
        ]);
    } catch(Exception $e){
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}

/* ── Page data ──────────────────────────────────────────────── */
$page_title = 'Supplier Loans';

$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();

// Per-supplier balances
$bal_raw = $pdo->query("
    SELECT s.id, s.name,
           COALESCE(SUM(CASE WHEN sl.type='loan' THEN sl.amount ELSE -sl.amount END), 0) AS balance,
           COUNT(sl.id) AS entries
    FROM suppliers s
    LEFT JOIN supplier_loans sl ON sl.supplier_id = s.id
    GROUP BY s.id, s.name
    ORDER BY balance DESC, s.name
")->fetchAll(PDO::FETCH_ASSOC);

/* ── Filters & pagination ───────────────────────────────────── */
$filters = [
    'supplier_id' => $_GET['supplier_id'] ?? '',
    'type'        => $_GET['type']        ?? '',
    'date_from'   => $_GET['date_from']   ?? '',
    'date_to'     => $_GET['date_to']     ?? '',
];
$per_page = 25;
$page     = max(1, intval($_GET['page'] ?? 1));

$where = []; $params = [];
if($filters['supplier_id']) { $where[] = 'sl.supplier_id=?'; $params[] = $filters['supplier_id']; }
if($filters['type'])        { $where[] = 'sl.type=?';        $params[] = $filters['type']; }
if($filters['date_from'])   { $where[] = 'DATE(sl.created_at)>=?'; $params[] = $filters['date_from']; }
if($filters['date_to'])     { $where[] = 'DATE(sl.created_at)<=?'; $params[] = $filters['date_to']; }
$where_sql = $where ? 'WHERE '.implode(' AND ', $where) : '';

$count_s = $pdo->prepare("SELECT COUNT(*) FROM supplier_loans sl $where_sql");
$count_s->execute($params);
$total       = (int)$count_s->fetchColumn();
$total_pages = max(1, (int)ceil($total / $per_page));
$page        = min($page, $total_pages);
$offset      = ($page - 1) * $per_page;

$stmt = $pdo->prepare("
    SELECT sl.*, s.name AS supplier_name, b.batch_id AS batch_code, u.username AS created_by_name
    FROM supplier_loans sl
    JOIN suppliers s  ON sl.supplier_id = s.id
    LEFT JOIN batches b ON sl.batch_id = b.id
    LEFT JOIN users u   ON sl.created_by = u.id
    $where_sql
    ORDER BY sl.created_at DESC
    LIMIT $per_page OFFSET $offset
");
$stmt->execute($params);
$loans = $stmt->fetchAll();

$has_filters = (bool)array_filter($filters);

// Summary totals for filtered view
$tot_s = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN sl.type='loan' THEN sl.amount ELSE 0 END),0) AS total_loans,
        COALESCE(SUM(CASE WHEN sl.type='repayment' THEN sl.amount ELSE 0 END),0) AS total_repayments
    FROM supplier_loans sl $where_sql
");
$tot_s->execute($params);
$totals = $tot_s->fetch(PDO::FETCH_ASSOC);

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-hand-holding-dollar" style="margin-right:.4rem;color:var(--text-muted)"></i>Supplier Loans</h2>
    <button class="btn btn-primary" onclick="openModal()">
        <i class="fas fa-plus"></i> Record Loan / Repayment
    </button>
</div>

<!-- ── Balance summary cards ─────────────────────────────────── -->
<?php
$with_balance = array_filter($bal_raw, fn($r) => $r['balance'] != 0 || $r['entries'] > 0);
if($with_balance):
?>
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:.75rem;margin-bottom:1.25rem">
    <?php foreach($with_balance as $sup):
        $bal = (float)$sup['balance'];
        $isDebt = $bal > 0;
        $color  = $isDebt ? '#dc2626' : '#16a34a';
        $bg     = $isDebt ? '#fef2f2' : '#f0fdf4';
        $label  = $isDebt ? 'Outstanding' : ($bal < 0 ? 'Credit' : 'Settled');
    ?>
    <div style="border:1px solid <?= $isDebt ? '#fca5a5' : '#86efac' ?>;border-left:4px solid <?= $color ?>;border-radius:8px;padding:.8rem 1rem;background:<?= $bg ?>">
        <div style="font-weight:700;font-size:.88rem;color:var(--text);margin-bottom:.25rem;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($sup['name']) ?>">
            <?= htmlspecialchars($sup['name']) ?>
        </div>
        <div style="font-size:1.05rem;font-weight:800;color:<?= $color ?>;font-family:monospace">
            <?= ($bal > 0 ? '' : ($bal < 0 ? '−' : '')) . number_format(abs($bal), 2) ?> FRW
        </div>
        <div style="font-size:.75rem;color:<?= $color ?>;margin-top:.15rem"><?= $label ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Modal ─────────────────────────────────────────────────── -->
<div class="modal-backdrop" id="loan-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <h3><i class="fas fa-hand-holding-dollar" style="margin-right:.4rem;color:var(--primary)"></i>Record Loan / Repayment</h3>
            <button class="modal-close" onclick="closeModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="loan-form">
                <div class="form-grid form-grid-2">
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Supplier</label>
                        <select name="supplier_id" id="modal-supplier" required onchange="updateModalBalance(this.value)">
                            <option value="">— Select supplier —</option>
                            <?php foreach($suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div id="modal-balance-line" style="margin-top:.4rem;font-size:.82rem;display:none"></div>
                    </div>
                    <div class="form-group">
                        <label>Action</label>
                        <select name="type" id="modal-type" required onchange="checkRepaymentLimit()">
                            <option value="">— Choose —</option>
                            <option value="loan">Give / Increase Loan</option>
                            <option value="repayment">Deduct Repayment</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (FRW)</label>
                        <input type="text" name="amount" id="modal-amount" placeholder="0.00" required oninput="checkRepaymentLimit()">
                        <div id="amount-warning" style="display:none;margin-top:.35rem;font-size:.8rem;color:#dc2626">
                            <i class="fas fa-triangle-exclamation"></i> <span id="amount-warning-text"></span>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Optional…" style="min-height:70px"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="loan-form" id="loan-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Save
            </button>
        </div>
    </div>
</div>

<!-- ── Filters ───────────────────────────────────────────────── -->
<form method="GET" action="loans.php" class="filter-bar">
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
        <label>Type</label>
        <select name="type">
            <option value="">All types</option>
            <option value="loan"      <?= $filters['type']==='loan'      ?'selected':'' ?>>Loan</option>
            <option value="repayment" <?= $filters['type']==='repayment' ?'selected':'' ?>>Repayment</option>
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
        <a href="loans.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </div>
    <?php if($has_filters): ?>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i>
        Filtered — <?= $total ?> record<?= $total!==1?'s':'' ?>
        &nbsp;|&nbsp; Loans: <strong><?= number_format($totals['total_loans'],2) ?> FRW</strong>
        &nbsp;|&nbsp; Repayments: <strong><?= number_format($totals['total_repayments'],2) ?> FRW</strong>
    </span>
    <?php endif; ?>
</form>

<!-- ── Table ─────────────────────────────────────────────────── -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Supplier</th>
                <th>Type</th>
                <th style="text-align:right">Amount (FRW)</th>
                <th>Purchase</th>
                <th>Notes</th>
                <th>By</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="loans-tbody">
        <?php 
        $i=0;
        foreach($loans as $ln): ?>
        <tr id="loan-row-<?= $ln['id'] ?>">
            <td class="text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="text-muted" style="font-size:.82rem;white-space:nowrap"><?= date('d M Y H:i', strtotime($ln['created_at'])) ?></td>
            <td class="fw-600"><?= htmlspecialchars($ln['supplier_name']) ?></td>
            <td>
                <?php if($ln['type']==='loan'): ?>
                <span class="badge badge-danger"><i class="fas fa-arrow-up" style="font-size:.65rem"></i> Loan</span>
                <?php else: ?>
                <span class="badge badge-success"><i class="fas fa-arrow-down" style="font-size:.65rem"></i> Repayment</span>
                <?php endif; ?>
            </td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $ln['type']==='loan'?'#dc2626':'#16a34a' ?>">
                <?= ($ln['type']==='loan'?'+':'−') . number_format($ln['amount'],2) ?>
            </td>
            <td class="font-mono" style="font-size:.78rem">
                <?php if($ln['batch_id'] && $ln['batch_code']): ?>
                <a href="batches.php?bid=<?= (int)$ln['batch_id'] ?>"
                   style="color:var(--primary);text-decoration:none;font-weight:600"
                   title="View batch record">
                    <i class="fas fa-arrow-up-right-from-square" style="font-size:.65rem"></i>
                    <?= htmlspecialchars($ln['batch_code']) ?>
                </a>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($ln['notes'] ?? '') ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($ln['created_by_name'] ?? '') ?></td>
            <td>
                <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem"
                        onclick="deleteLoan(<?= $ln['id'] ?>, this)">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$loans): ?>
        <tr id="empty-row"><td colspan="9" style="text-align:center;padding:2rem;color:var(--text-muted)">
            <?= $has_filters ? 'No records match the current filters.' : 'No loan records found.' ?>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
    <?php if($total_pages > 1): ?>
    <?= paginate($page, $total_pages, $filters, 'loans.php') ?>
    <p class="pagination-info" style="padding-bottom:.5rem">
        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> of <?= $total ?> records
    </p>
    <?php endif; ?>
</div>

<script>
const loanBalances = <?= json_encode(array_column($bal_raw, 'balance', 'id')) ?>;

function openModal() {
    document.getElementById('loan-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal() {
    document.getElementById('loan-modal').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e => { if(e.key === 'Escape') closeModal(); });

function updateModalBalance(suppId) {
    const line = document.getElementById('modal-balance-line');
    if(!suppId){ line.style.display = 'none'; checkRepaymentLimit(); return; }
    const bal = parseFloat(loanBalances[suppId] || 0);
    const isDebt = bal > 0;
    line.style.display = '';
    line.style.color = isDebt ? '#dc2626' : '#16a34a';
    line.innerHTML = isDebt
        ? '<i class="fas fa-circle-exclamation"></i> Outstanding loan: <strong>' + bal.toLocaleString('en-US',{minimumFractionDigits:2}) + ' FRW</strong>'
        : '<i class="fas fa-circle-check"></i> ' + (bal < 0 ? 'Credit balance: <strong>' + Math.abs(bal).toLocaleString('en-US',{minimumFractionDigits:2}) + ' FRW</strong>' : 'No outstanding loan');
    checkRepaymentLimit();
}

function checkRepaymentLimit() {
    const suppId  = document.getElementById('modal-supplier').value;
    const type    = document.getElementById('modal-type').value;
    const amount  = parseFloat(document.getElementById('modal-amount').value) || 0;
    const warning = document.getElementById('amount-warning');
    const wtext   = document.getElementById('amount-warning-text');

    if(type !== 'repayment' || !suppId){ warning.style.display = 'none'; return; }

    const bal = parseFloat(loanBalances[suppId] || 0);
    if(bal <= 0){
        wtext.textContent = 'This supplier has no outstanding loan to repay.';
        warning.style.display = '';
        return;
    }
    if(amount > bal){
        wtext.textContent = 'Exceeds outstanding balance of ' + bal.toLocaleString('en-US',{minimumFractionDigits:2}) + ' FRW.';
        warning.style.display = '';
    } else {
        warning.style.display = 'none';
    }
}

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showAlert(type, msg){
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-' + type + ' mb-15';
    el.innerHTML = '<i class="fas fa-' + (type==='success'?'circle-check':'circle-xmark') + '"></i> ' + msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 5000);
}

document.getElementById('loan-form').addEventListener('submit', function(e){
    e.preventDefault();
    if(document.getElementById('amount-warning').style.display !== 'none'){
        showAlert('error', document.getElementById('amount-warning-text').textContent);
        return;
    }
    const btn = document.getElementById('loan-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    fetch('loans.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            showAlert('success', d.message);
            // Update in-memory balance
            loanBalances[d.supplier_id] = d.new_balance;
            // Prepend row to table
            prependRow(d.row);
            closeModal();
            this.reset();
            document.getElementById('modal-balance-line').style.display = 'none';
            // Refresh page to update balance cards
            setTimeout(() => location.reload(), 1200);
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save';
    })
    .catch(() => {
        showAlert('error', 'Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save';
    });
});

function prependRow(r){
    const tbody = document.getElementById('loans-tbody');
    const empty = document.getElementById('empty-row');
    if(empty) empty.remove();
    const isLoan = r.type === 'loan';
    const dateStr = new Date(r.created_at.replace(' ','T')).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}) + ' ' +
                    r.created_at.slice(11,16);
    const tr = document.createElement('tr');
    tr.id = 'loan-row-' + r.id;
    tr.innerHTML =
        '<td class="text-muted" style="font-size:.78rem">' + esc(r.id) + '</td>' +
        '<td class="text-muted" style="font-size:.82rem;white-space:nowrap">' + esc(dateStr) + '</td>' +
        '<td class="fw-600">' + esc(r.supplier_name) + '</td>' +
        '<td>' + (isLoan
            ? '<span class="badge badge-danger"><i class="fas fa-arrow-up" style="font-size:.65rem"></i> Loan</span>'
            : '<span class="badge badge-success"><i class="fas fa-arrow-down" style="font-size:.65rem"></i> Repayment</span>') + '</td>' +
        '<td style="text-align:right;font-family:monospace;font-weight:700;color:' + (isLoan?'#dc2626':'#16a34a') + '">' +
            (isLoan ? '+' : '−') + parseFloat(r.amount).toLocaleString('en-US',{minimumFractionDigits:2}) + '</td>' +
        '<td class="font-mono text-muted" style="font-size:.78rem">—</td>' +
        '<td class="text-muted" style="font-size:.82rem">' + esc(r.notes || '') + '</td>' +
        '<td class="text-muted" style="font-size:.78rem">' + esc(r.created_by_name || '') + '</td>' +
        '<td><button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteLoan(' + r.id + ', this)"><i class="fas fa-trash"></i></button></td>';
    tbody.insertBefore(tr, tbody.firstChild);
}

function deleteLoan(id, btn){
    if(!confirm('Delete this loan record? This cannot be undone.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('delete', '1');
    fd.append('id', id);

    fetch('loans.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            const row = document.getElementById('loan-row-' + id);
            row.style.transition = 'opacity .3s';
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
                if(!document.querySelector('#loans-tbody tr:not(#empty-row)')){
                    document.getElementById('loans-tbody').innerHTML =
                        '<tr id="empty-row"><td colspan="9" style="text-align:center;padding:2rem;color:var(--text-muted)">No loan records found.</td></tr>';
                }
            }, 300);
            loanBalances[d.supplier_id] = d.new_balance;
            showAlert('success', d.message);
        } else {
            showAlert('error', d.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i>';
        }
    })
    .catch(() => {
        showAlert('error', 'Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash"></i>';
    });
}
</script>

<?php include 'includes/footer.php'; ?>
