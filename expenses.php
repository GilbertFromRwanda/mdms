<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }


$CATEGORIES = [
    'Electricity'      => 'fa-bolt',
    'Water'            => 'fa-droplet',
    'Internet'         => 'fa-wifi',
    'Airtime'          => 'fa-phone',
    'Gas'              => 'fa-fire',
    'Waste Management' => 'fa-trash',
    'Security'         => 'fa-shield-halved',
    'Transport'        => 'fa-truck',
    'Salaries'         => 'fa-users',
    'Office Supplies'  => 'fa-box',
    'Equipment'        => 'fa-screwdriver-wrench',
    'Maintenance'      => 'fa-hammer',
    'Government Fees'  => 'fa-landmark',
    'Other'            => 'fa-tag',
];

/* Ensure table exists */
try { $pdo->exec("CREATE TABLE IF NOT EXISTS `expenses` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `expense_date` date NOT NULL,
  `category` varchar(100) NOT NULL DEFAULT 'Other',
  `description` varchar(255) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `payment_method` enum('cash','bank','momo','mixed') NOT NULL DEFAULT 'cash',
  `account_id` int(11) DEFAULT NULL,
  `notes` text DEFAULT NULL,
  `created_by` int(11) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `expense_date` (`expense_date`),
  KEY `created_by` (`created_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci"); } catch(Exception $e){}
/* Upgrade existing tables that predate the 'mixed' value */
try { $pdo->exec("ALTER TABLE expenses MODIFY payment_method ENUM('cash','bank','momo','mixed') NOT NULL DEFAULT 'cash'"); } catch(Exception $e){}

/* ── AJAX: save expense ──────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['save_expense'])){
    header('Content-Type: application/json');
    try {
        $date  = $_POST['expense_date'] ?? date('Y-m-d');
        $cat   = trim($_POST['category']    ?? 'Other');
        $desc  = trim($_POST['description'] ?? '');
        $notes = trim($_POST['notes'] ?? '');
        if(!$desc) throw new Exception('Description is required.');

        /* Parse multi-payment rows */
        $valid_payments = []; $total_amount = 0.0;
        foreach($_POST['payments'] ?? [] as $p){
            $pm  = in_array($p['method']??'',['cash','bank','momo']) ? $p['method'] : null;
            $amt = round(floatval($p['amount']??0), 2);
            $aid = intval($p['account_id']??0) ?: null;
            if(!$pm || $amt <= 0) continue;
            $valid_payments[] = ['method'=>$pm,'account_id'=>$aid,'amount'=>$amt];
            $total_amount += $amt;
        }
        $total_amount = round($total_amount, 2);
        if(empty($valid_payments)) throw new Exception('Please add at least one payment row.');
        if($total_amount <= 0)     throw new Exception('Total amount must be greater than 0.');

        $pm_label = count($valid_payments) > 1 ? 'mixed' : $valid_payments[0]['method'];
        $pm_acct  = count($valid_payments) === 1 ? ($valid_payments[0]['account_id'] ?: null) : null;

        $pdo->beginTransaction();

        $stmtAcct = $pdo->prepare("SELECT balance,account_name FROM company_accounts WHERE id=? AND is_active=1 AND account_type=? FOR UPDATE");
        $stmtUpd  = $pdo->prepare("UPDATE company_accounts SET balance=? WHERE id=?");
        $stmtTxn  = $pdo->prepare("INSERT INTO account_transactions (account_id,txn_type,amount,balance_after,reference_type,reference_id,description,created_by) VALUES (?,'debit',?,?,'expense',NULL,?,?)");
        foreach($valid_payments as $vp){
            if(!$vp['account_id']) continue;
            $stmtAcct->execute([$vp['account_id'],$vp['method']]); $acct=$stmtAcct->fetch();
            if(!$acct) throw new Exception('Account #'.$vp['account_id'].' not found or type mismatch.');
            if($acct['balance'] < $vp['amount'])
                throw new Exception("Insufficient balance in \"{$acct['account_name']}\". Available: ".number_format($acct['balance'],2)." FRW.");
            $newBal = round($acct['balance'] - $vp['amount'], 2);
            $stmtUpd->execute([$newBal,$vp['account_id']]);
            $stmtTxn->execute([$vp['account_id'],$vp['amount'],$newBal,$desc,$_SESSION['user_id']]);
        }

        $pdo->prepare("INSERT INTO expenses (expense_date,category,description,amount,payment_method,account_id,notes,created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$date,$cat,$desc,$total_amount,$pm_label,$pm_acct,$notes,$_SESSION['user_id']]);
        $newId = $pdo->lastInsertId();
        logAction($pdo,$_SESSION['user_id'],'CREATE','expenses',$newId,"Expense: $desc — $total_amount FRW via $pm_label");
        $pdo->commit();

        $rs = $pdo->prepare("SELECT e.*,ca.account_name,u.username AS created_by_name FROM expenses e LEFT JOIN company_accounts ca ON ca.id=e.account_id LEFT JOIN users u ON u.id=e.created_by WHERE e.id=?");
        $rs->execute([$newId]); $row=$rs->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'message'=>'Expense of '.number_format($total_amount,2).' FRW recorded.','row'=>$row]);
    } catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ── AJAX: delete expense ────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['delete_expense'])){
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if(!$id) throw new Exception('Invalid record.');
        $row=$pdo->prepare("SELECT * FROM expenses WHERE id=?"); $row->execute([$id]); $rec=$row->fetch();
        if(!$rec) throw new Exception('Record not found.');
        $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$id]);
        logAction($pdo,$_SESSION['user_id'],'DELETE','expenses',$id,"Deleted expense {$rec['amount']} FRW: {$rec['description']}");
        echo json_encode(['success'=>true,'message'=>'Expense deleted.']);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ══════════════════════════════════════════════════════════════
   Page data
══════════════════════════════════════════════════════════════ */
$page_title = 'Expenses';

$f = [
    'date_from' => $_GET['date_from'] ?? date('Y-m-01'),
    'date_to'   => $_GET['date_to']   ?? date('Y-m-t'),
    'category'  => $_GET['category']  ?? '',
];

$company_accounts = $pdo->query("SELECT id,account_type,account_name,balance FROM company_accounts WHERE is_active=1 ORDER BY account_type,account_name")->fetchAll(PDO::FETCH_ASSOC);

/* WHERE clause shared by all queries */
$wh=[]; $wp=[];
if($f['date_from']){ $wh[]="expense_date>=?"; $wp[]=$f['date_from']; }
if($f['date_to'])  { $wh[]="expense_date<=?"; $wp[]=$f['date_to'];   }
if($f['category']) { $wh[]="category=?";             $wp[]=$f['category'];  }
$wsql = $wh ? 'WHERE '.implode(' AND ',$wh) : '';

/* Stats */
$stat_s = $pdo->prepare("SELECT COALESCE(SUM(amount),0) AS total, COUNT(*) AS cnt FROM expenses $wsql");
$stat_s->execute($wp); $stats = $stat_s->fetch(PDO::FETCH_ASSOC);

/* Category breakdown */
$cat_s = $pdo->prepare("SELECT category, COALESCE(SUM(amount),0) AS total FROM expenses $wsql GROUP BY category ORDER BY total DESC LIMIT 5");
$cat_s->execute($wp); $cat_totals = $cat_s->fetchAll(PDO::FETCH_ASSOC);

/* Method breakdown */
$mth_s = $pdo->prepare("SELECT payment_method, COALESCE(SUM(amount),0) AS total FROM expenses $wsql GROUP BY payment_method");
$mth_s->execute($wp); $mth_raw = $mth_s->fetchAll(PDO::FETCH_ASSOC);
$mth_totals = array_column($mth_raw,'total','payment_method');

/* Paginated list */
$per_page = 50;
$page     = max(1, intval($_GET['page'] ?? 1));
$cnt_s    = $pdo->prepare("SELECT COUNT(*) FROM expenses $wsql"); $cnt_s->execute($wp);
$total_rows  = (int)$cnt_s->fetchColumn();
$total_pages = max(1,(int)ceil($total_rows/$per_page));
$page        = min($page,$total_pages);
$offset      = ($page-1)*$per_page;

$data_s = $pdo->prepare("SELECT e.*,ca.account_name,u.username AS created_by_name FROM expenses e LEFT JOIN company_accounts ca ON ca.id=e.account_id LEFT JOIN users u ON u.id=e.created_by $wsql ORDER BY e.expense_date DESC, e.created_at DESC LIMIT $per_page OFFSET $offset");
$data_s->execute($wp); $expenses=$data_s->fetchAll(PDO::FETCH_ASSOC);

$method_labels = ['cash'=>'Cash','bank'=>'Bank','momo'=>'MoMo','mixed'=>'Mixed'];
$method_colors = ['cash'=>'#16a34a','bank'=>'#2563eb','momo'=>'#7c3aed','mixed'=>'#6b7280'];

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-receipt" style="margin-right:.4rem;color:var(--text-muted)"></i>Expenses</h2>
    <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add Expense</button>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem;margin-bottom:1rem">
    <div style="border:1px solid var(--border);border-left:4px solid #dc2626;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.25rem;display:flex;align-items:center;gap:.4rem">
            <i class="fas fa-receipt" style="color:#dc2626"></i> Total Expenses
        </div>
        <div style="font-size:1.1rem;font-weight:700;color:#dc2626"><?= number_format($stats['total'],2) ?> <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">FRW</span></div>
        <div style="font-size:.72rem;color:var(--text-muted);margin-top:.1rem"><?= $stats['cnt'] ?> transaction<?= $stats['cnt']!=1?'s':'' ?></div>
    </div>
    <div style="border:1px solid var(--border);border-left:4px solid #16a34a;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.25rem;display:flex;align-items:center;gap:.4rem">
            <i class="fas fa-money-bill-wave" style="color:#16a34a"></i> Cash Paid
        </div>
        <div style="font-size:1rem;font-weight:700;color:#16a34a"><?= number_format($mth_totals['cash']??0,2) ?> <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">FRW</span></div>
    </div>
    <div style="border:1px solid var(--border);border-left:4px solid #2563eb;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.25rem;display:flex;align-items:center;gap:.4rem">
            <i class="fas fa-building-columns" style="color:#2563eb"></i> Bank Paid
        </div>
        <div style="font-size:1rem;font-weight:700;color:#2563eb"><?= number_format($mth_totals['bank']??0,2) ?> <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">FRW</span></div>
    </div>
    <div style="border:1px solid var(--border);border-left:4px solid #7c3aed;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.25rem;display:flex;align-items:center;gap:.4rem">
            <i class="fas fa-mobile-screen" style="color:#7c3aed"></i> MoMo Paid
        </div>
        <div style="font-size:1rem;font-weight:700;color:#7c3aed"><?= number_format($mth_totals['momo']??0,2) ?> <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">FRW</span></div>
    </div>
</div>

<?php if($cat_totals): ?>
<!-- Category breakdown bar -->
<div style="border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem;margin-bottom:1rem;background:var(--surface,var(--bg))">
    <div style="font-size:.78rem;font-weight:600;color:var(--text-muted);margin-bottom:.6rem;text-transform:uppercase;letter-spacing:.05em">By Category</div>
    <div style="display:flex;flex-wrap:wrap;gap:.5rem">
    <?php foreach($cat_totals as $ct): $pct=round($ct['total']/$stats['total']*100); ?>
        <div style="display:flex;align-items:center;gap:.4rem;padding:.3rem .65rem;border-radius:20px;background:var(--bg);border:1px solid var(--border);font-size:.8rem">
            <i class="fas <?= $CATEGORIES[$ct['category']] ?? 'fa-tag' ?>" style="color:#6b7280;font-size:.7rem"></i>
            <span style="font-weight:500"><?= htmlspecialchars($ct['category']) ?></span>
            <span style="font-family:monospace;font-weight:700;color:#dc2626"><?= number_format($ct['total'],0) ?></span>
            <span style="color:var(--text-muted);font-size:.72rem">(<?= $pct ?>%)</span>
        </div>
    <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- Filters -->
<form method="GET" action="expenses.php" class="filter-bar" style="margin-bottom:1rem">
    <div class="filter-group">
        <label>From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($f['date_from']) ?>">
    </div>
    <div class="filter-group">
        <label>To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($f['date_to']) ?>">
    </div>
    <div class="filter-group">
        <label>Category</label>
        <select name="category">
            <option value="">All categories</option>
            <?php foreach($CATEGORIES as $c => $_icon): ?>
            <option value="<?= $c ?>" <?= $f['category']===$c?'selected':'' ?>><?= $c ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-filter"></i> Filter</button>
        <a href="expenses.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-xmark"></i> Clear</a>
    </div>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> <?= $total_rows ?> record<?= $total_rows!=1?'s':'' ?></span>
</form>

<!-- Table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date</th>
                <th>Category</th>
                <th>Description</th>
                <th>Method</th>
                <th>Account</th>
                <th style="text-align:right">Amount (FRW)</th>
                <th>Notes</th>
                <th>By</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="expense-tbody">
        <?php if(!$expenses): ?>
        <tr id="empty-row"><td colspan="10" style="text-align:center;padding:2rem;color:var(--text-muted)">No expenses found for this period.</td></tr>
        <?php endif; ?>
        <?php $i=$offset; foreach($expenses as $ex): ?>
        <tr id="exp-row-<?= $ex['id'] ?>">
            <td class="text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="text-muted" style="font-size:.82rem;white-space:nowrap"><?= date('d M Y',strtotime($ex['expense_date'])) ?></td>
            <td>
                <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.76rem;font-weight:600;padding:.2rem .55rem;border-radius:4px;background:var(--bg);border:1px solid var(--border)">
                    <i class="fas <?= $CATEGORIES[$ex['category']] ?? 'fa-tag' ?>" style="color:#6b7280"></i>
                    <?= htmlspecialchars($ex['category']) ?>
                </span>
            </td>
            <td style="font-size:.85rem;font-weight:500"><?= htmlspecialchars($ex['description']) ?></td>
            <td>
                <?php $mc=$method_colors[$ex['payment_method']]??'#6b7280'; ?>
                <span style="font-size:.76rem;font-weight:600;padding:.2rem .5rem;border-radius:4px;background:<?= $mc ?>18;color:<?= $mc ?>">
                    <?= $method_labels[$ex['payment_method']] ?? $ex['payment_method'] ?>
                </span>
            </td>
            <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($ex['account_name'] ?? '—') ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:#dc2626"><?= number_format($ex['amount'],2) ?></td>
            <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($ex['notes'] ?? '') ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($ex['created_by_name'] ?? '—') ?></td>
            <td>
                <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteExpense(<?= $ex['id'] ?>,this)">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php if($total_pages>1): ?><?= paginate($page,$total_pages,array_filter($f),'expenses.php') ?><?php endif; ?>
</div>

<!-- ── Add Expense Modal ───────────────────────────────────────── -->
<div class="modal-backdrop" id="exp-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" style="max-width:480px">
        <div class="modal-header">
            <h3><i class="fas fa-receipt" style="margin-right:.4rem;color:#dc2626"></i>Add Expense</h3>
            <button class="modal-close" onclick="closeModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div id="modal-alert" style="display:none;margin-bottom:.75rem;padding:.55rem .75rem;border-radius:6px;font-size:.83rem;display:none;align-items:center;gap:.5rem"></div>
            <form id="exp-form">
                <input type="hidden" name="save_expense" value="1">
                <div class="form-grid form-grid-2">

                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="expense_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Category</label>
                        <select name="category" id="exp-category" required onchange="autoFillDesc()">
                            <?php foreach($CATEGORIES as $c => $_icon): ?>
                            <option value="<?= $c ?>"><?= $c ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group" style="grid-column:1/-1">
                        <label>Description</label>
                        <input type="text" name="description" id="exp-description" placeholder="e.g. Electricity bill — May 2026" required oninput="expDescEdited=true">
                    </div>

                    <div class="form-group" style="grid-column:1/-1">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
                            <label style="margin:0">Payment Breakdown</label>
                            <button type="button" class="btn btn-secondary" style="padding:.25rem .6rem;font-size:.78rem" onclick="addExpPayRow()">
                                <i class="fas fa-plus"></i> Add Row
                            </button>
                        </div>
                        <!-- Column headers -->
                        <div style="display:grid;grid-template-columns:120px 1fr 120px 28px;gap:.4rem;padding:0 .1rem;margin-bottom:.3rem">
                            <span style="font-size:.74rem;font-weight:600;color:var(--text-muted)">Method</span>
                            <span style="font-size:.74rem;font-weight:600;color:var(--text-muted)">Account</span>
                            <span style="font-size:.74rem;font-weight:600;color:var(--text-muted)">Amount (FRW)</span>
                            <span></span>
                        </div>
                        <div id="exp-pay-rows" style="display:flex;flex-direction:column;gap:.4rem"></div>
                        <div style="margin-top:.5rem;padding:.4rem .75rem;background:var(--bg);border-radius:6px;font-size:.85rem;display:flex;justify-content:space-between;align-items:center">
                            <span style="color:var(--text-muted)">Total:</span>
                            <strong id="exp-pay-total" style="font-family:monospace;color:var(--text)">0.00 FRW</strong>
                        </div>
                    </div>

                    <div class="form-group" style="grid-column:1/-1">
                        <label>Notes <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                        <textarea name="notes" placeholder="Optional…" style="min-height:56px"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="exp-form" id="m-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Expense
            </button>
        </div>
    </div>
</div>

<script>
const companyAccounts = <?= json_encode(array_values($company_accounts)) ?>;
const CAT_ICONS  = <?= json_encode($CATEGORIES) ?>;
const MTH_COLORS = {cash:'#16a34a',bank:'#2563eb',momo:'#7c3aed',mixed:'#6b7280'};
const MTH_LABELS = {cash:'Cash',bank:'Bank',momo:'MoMo',mixed:'Mixed'};

/* ── Category → description auto-fill ──────────────────────── */
const CAT_DESC = {
    'Electricity':      () => 'Electricity bill — '+monthLabel(),
    'Water':            () => 'Water bill — '+monthLabel(),
    'Internet':         () => 'Internet subscription — '+monthLabel(),
    'Airtime':          () => 'Airtime top-up',
    'Gas':              () => 'Gas refill',
    'Waste Management': () => 'Waste management fee — '+monthLabel(),
    'Security':         () => 'Security fee — '+monthLabel(),
    'Transport':        () => 'Transport expense',
    'Salaries':         () => 'Salaries — '+monthLabel(),
    'Office Supplies':  () => 'Office supplies purchase',
    'Equipment':        () => 'Equipment purchase',
    'Maintenance':      () => 'Maintenance work',
    'Government Fees':  () => 'Government fees payment',
    'Other':            () => '',
};
function monthLabel(){
    return new Date().toLocaleDateString('en-GB',{month:'long',year:'numeric'});
}
let expDescEdited = false;
let expLastAutoDesc = '';
function autoFillDesc(){
    const cat  = document.getElementById('exp-category')?.value;
    const inp  = document.getElementById('exp-description');
    if(!inp) return;
    if(expDescEdited && inp.value !== expLastAutoDesc) return;
    const suggested = CAT_DESC[cat] ? CAT_DESC[cat]() : '';
    inp.value = suggested;
    expLastAutoDesc = suggested;
    expDescEdited = false;
}

/* ── Modal open / close ─────────────────────────────────────── */
function openModal(){
    document.getElementById('exp-modal').classList.add('open');
    document.body.style.overflow='hidden';
    clearModalAlert();
    if(!document.getElementById('exp-pay-rows').children.length) addExpPayRow();
    expDescEdited = false;
    autoFillDesc();
}
function closeModal(){
    document.getElementById('exp-modal').classList.remove('open');
    document.body.style.overflow='';
    clearModalAlert();
}
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeModal(); });

/* ── Payment row builder ────────────────────────────────────── */
let expPayRowN = 0;

function buildAcctOpts(method){
    const filtered = companyAccounts.filter(a=>a.account_type===method);
    if(!filtered.length) return '<option value="">No '+method+' accounts</option>';
    return '<option value="">— Account —</option>'+filtered.map(a=>
        `<option value="${a.id}" data-balance="${a.balance}">${esc(a.account_name)} (${parseFloat(a.balance).toLocaleString('en-US',{minimumFractionDigits:2})} FRW)</option>`
    ).join('');
}

function addExpPayRow(){
    const n = ++expPayRowN;
    const wrap = document.createElement('div');
    wrap.id = 'epr-'+n;
    wrap.style.cssText = 'display:grid;grid-template-columns:120px 1fr 120px 28px;gap:.4rem;align-items:start';
    wrap.innerHTML =
        `<select name="payments[${n}][method]" onchange="onExpPayRowMethod(${n})"
            style="padding:.38rem .45rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;width:100%">
            <option value="">Method</option>
            <option value="cash">Cash</option>
            <option value="bank">Bank</option>
            <option value="momo">MoMo</option>
        </select>
        <select name="payments[${n}][account_id]" id="epr-acct-${n}" onchange="onExpPayRowAcct(${n})"
            style="padding:.38rem .45rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;width:100%">
            <option value="">Select method first</option>
        </select>
        <div>
            <input name="payments[${n}][amount]" type="text" placeholder="0.00" oninput="recalcExpTotal()"
                id="epr-amt-${n}"
                style="padding:.38rem .45rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;font-family:monospace;width:100%;box-sizing:border-box;text-align:right">
            <div id="epr-warn-${n}" style="display:none;font-size:.74rem;color:#dc2626;margin-top:.2rem">
                <i class="fas fa-triangle-exclamation"></i> <span id="epr-warn-txt-${n}"></span>
            </div>
        </div>
        <button type="button" onclick="removeExpPayRow(${n})"
            style="padding:.38rem .4rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:#dc2626;cursor:pointer;line-height:1;display:${expPayRowN>1?'block':'none'}">
            <i class="fas fa-trash" style="font-size:.72rem"></i>
        </button>`;
    document.getElementById('exp-pay-rows').appendChild(wrap);
    recalcExpTotal();
}

function removeExpPayRow(n){
    document.getElementById('epr-'+n)?.remove();
    recalcExpTotal();
}

function onExpPayRowMethod(n){
    const method = document.querySelector(`#epr-${n} [name="payments[${n}][method]"]`)?.value;
    const sel    = document.getElementById('epr-acct-'+n);
    if(!sel) return;
    sel.innerHTML = method ? buildAcctOpts(method) : '<option value="">Select method first</option>';
    const opts = [...sel.options].filter(o=>o.value);
    if(opts.length===1){ sel.value=opts[0].value; }
    onExpPayRowAcct(n);
}

function onExpPayRowAcct(n){
    checkExpRowBalance(n);
}

function checkExpRowBalance(n){
    const sel   = document.getElementById('epr-acct-'+n);
    const amt   = parseFloat(document.getElementById('epr-amt-'+n)?.value||0)||0;
    const warn  = document.getElementById('epr-warn-'+n);
    const wtxt  = document.getElementById('epr-warn-txt-'+n);
    if(!sel||!warn) return;
    const opt = sel.options[sel.selectedIndex];
    if(!sel.value||!opt||amt<=0){ warn.style.display='none'; return; }
    const bal = parseFloat(opt.dataset.balance||0);
    if(amt>bal){
        wtxt.textContent='Exceeds balance of '+bal.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW.';
        warn.style.display='';
    } else {
        warn.style.display='none';
    }
}

function recalcExpTotal(){
    let total=0;
    document.querySelectorAll('[id^="epr-amt-"]').forEach(inp=>{
        total+=parseFloat(inp.value)||0;
        const n=inp.id.replace('epr-amt-','');
        checkExpRowBalance(n);
    });
    const tv=document.getElementById('exp-pay-total');
    if(tv) tv.textContent=total.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW';
}

/* ── Utilities ──────────────────────────────────────────────── */
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showAlert(type,msg){
    const el=document.getElementById('page-alert');
    el.className='alert alert-'+type+' mb-15';
    el.innerHTML='<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display='flex'; clearTimeout(el._t); el._t=setTimeout(()=>{el.style.display='none'},5000);
}
function showModalAlert(msg){
    const el=document.getElementById('modal-alert');
    el.style.cssText='display:flex;align-items:center;gap:.5rem;margin-bottom:.75rem;padding:.55rem .75rem;border-radius:6px;font-size:.83rem;background:#fef2f2;color:#dc2626;border:1px solid #fecaca';
    el.innerHTML='<i class="fas fa-circle-xmark" style="flex-shrink:0"></i><span>'+msg+'</span>';
}
function clearModalAlert(){
    const el=document.getElementById('modal-alert');
    if(el) el.style.display='none';
}

/* ── Form submit ────────────────────────────────────────────── */
document.getElementById('exp-form').addEventListener('submit',function(e){
    e.preventDefault();
    /* Check any balance warnings */
    const hasWarn = [...document.querySelectorAll('div[id^="epr-warn-"]')].some(w=>w.style.display!=='none');
    if(hasWarn){ showModalAlert('One or more payment rows exceed the account balance.'); return; }
    const total=parseFloat(document.getElementById('exp-pay-total')?.textContent)||0;
    if(total<=0){ showModalAlert('Please add at least one payment row with an amount.'); return; }

    const btn=document.getElementById('m-save-btn');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
    fetch('expenses.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new FormData(this)})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showAlert('success',d.message);
            prependRow(d.row);
            closeModal();
            this.reset();
            document.getElementById('exp-pay-rows').innerHTML='';
            expPayRowN=0; expDescEdited=false; expLastAutoDesc='';
            document.getElementById('exp-pay-total').textContent='0.00 FRW';
        } else showModalAlert(d.message);
        btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Expense';
    }).catch(()=>{showModalAlert('Network error. Please try again.');btn.disabled=false;btn.innerHTML='<i class="fas fa-save"></i> Save Expense';});
});

/* ── Prepend new row to table ───────────────────────────────── */
function prependRow(r){
    const tbody=document.getElementById('expense-tbody');
    document.getElementById('empty-row')?.remove();
    const mclr=MTH_COLORS[r.payment_method]||'#6b7280';
    const icon=CAT_ICONS[r.category]||'fa-tag';
    const d=new Date(r.expense_date+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
    const tr=document.createElement('tr'); tr.id='exp-row-'+r.id;
    tr.innerHTML=
        '<td class="text-muted" style="font-size:.78rem">—</td>'+
        '<td class="text-muted" style="font-size:.82rem;white-space:nowrap">'+esc(d)+'</td>'+
        '<td><span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.76rem;font-weight:600;padding:.2rem .55rem;border-radius:4px;background:var(--bg);border:1px solid var(--border)"><i class="fas '+esc(icon)+'" style="color:#6b7280"></i> '+esc(r.category)+'</span></td>'+
        '<td style="font-size:.85rem;font-weight:500">'+esc(r.description)+'</td>'+
        '<td><span style="font-size:.76rem;font-weight:600;padding:.2rem .5rem;border-radius:4px;background:'+mclr+'18;color:'+mclr+'">'+esc(MTH_LABELS[r.payment_method]||r.payment_method)+'</span></td>'+
        '<td class="text-muted" style="font-size:.8rem">'+esc(r.account_name||'—')+'</td>'+
        '<td style="text-align:right;font-family:monospace;font-weight:700;color:#dc2626">'+parseFloat(r.amount).toLocaleString('en-US',{minimumFractionDigits:2})+'</td>'+
        '<td class="text-muted" style="font-size:.8rem">'+esc(r.notes||'')+'</td>'+
        '<td class="text-muted" style="font-size:.78rem">'+esc(r.created_by_name||'—')+'</td>'+
        '<td><button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteExpense('+r.id+',this)"><i class="fas fa-trash"></i></button></td>';
    tbody.insertBefore(tr,tbody.firstChild);
}

/* ── Delete ─────────────────────────────────────────────────── */
function deleteExpense(id,btn){
    if(!confirm('Delete this expense? This cannot be undone.')) return;
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    const fd=new FormData(); fd.append('delete_expense','1'); fd.append('id',id);
    fetch('expenses.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            const row=document.getElementById('exp-row-'+id);
            if(row){ row.style.transition='opacity .3s'; row.style.opacity='0'; setTimeout(()=>row.remove(),300); }
            showAlert('success',d.message);
        } else { showAlert('error',d.message); btn.disabled=false; btn.innerHTML='<i class="fas fa-trash"></i>'; }
    }).catch(()=>{ showAlert('error','Network error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-trash"></i>'; });
}
</script>

<?php include 'includes/footer.php'; ?>
