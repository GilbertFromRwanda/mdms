<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX: save manual journal entry ─────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['save_entry'])){
    header('Content-Type: application/json');
    try {
        $date    = $_POST['entry_date'] ?? date('Y-m-d');
        $amount  = round(floatval(str_replace(',', '', $_POST['amount'] ?? '0')), 2);
        $comment = trim($_POST['comment'] ?? '');
        $type    = in_array($_POST['entry_type'] ?? '', ['credit','debit']) ? $_POST['entry_type'] : null;

        if(!$comment) throw new Exception('Comment is required.');
        if($amount <= 0) throw new Exception('Amount must be greater than 0.');
        if(!$type) throw new Exception('Please choose Credit or Debit.');

        $pdo->prepare("INSERT INTO manual_journal (entry_date,amount,`comment`,entry_type,created_by) VALUES (?,?,?,?,?)")
            ->execute([$date,$amount,$comment,$type,$_SESSION['user_id']]);
        $newId = $pdo->lastInsertId();
        logAction($pdo,$_SESSION['user_id'],'CREATE','manual_journal',$newId,"Manual $type: $comment — $amount FRW");

        $rs = $pdo->prepare("SELECT mj.*,u.username AS created_by_name FROM manual_journal mj LEFT JOIN users u ON u.id=mj.created_by WHERE mj.id=?");
        $rs->execute([$newId]); $row=$rs->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'message'=>'Journal entry recorded.','row'=>$row]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ── AJAX: delete manual journal entry ───────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['delete_entry'])){
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if(!$id) throw new Exception('Invalid record.');
        $row=$pdo->prepare("SELECT * FROM manual_journal WHERE id=?"); $row->execute([$id]); $rec=$row->fetch();
        if(!$rec) throw new Exception('Record not found.');
        $pdo->prepare("DELETE FROM manual_journal WHERE id=?")->execute([$id]);
        logAction($pdo,$_SESSION['user_id'],'DELETE','manual_journal',$id,"Deleted manual {$rec['entry_type']} {$rec['amount']} FRW: {$rec['comment']}");
        echo json_encode(['success'=>true,'message'=>'Entry deleted.']);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ══════════════════════════════════════════════════════════════
   Page data
══════════════════════════════════════════════════════════════ */
$page_title = 'Manual Journal';

$f = [
    'date_from'  => $_GET['date_from']  ?? date('Y-m-d'),
    'date_to'    => $_GET['date_to']    ?? date('Y-m-d'),
    'entry_type' => $_GET['entry_type'] ?? '',
];

$wh=[]; $wp=[];
if($f['date_from'])  { $wh[]="entry_date>=?"; $wp[]=$f['date_from']; }
if($f['date_to'])    { $wh[]="entry_date<=?"; $wp[]=$f['date_to'];   }
if($f['entry_type']) { $wh[]="entry_type=?";  $wp[]=$f['entry_type']; }
$wsql = $wh ? 'WHERE '.implode(' AND ',$wh) : '';

/* Stats (period totals, respect filters) */
$stat_s = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN entry_type='credit' THEN amount END),0) AS total_credit,
        COALESCE(SUM(CASE WHEN entry_type='debit'  THEN amount END),0) AS total_debit,
        COUNT(*) AS cnt
    FROM manual_journal $wsql
");
$stat_s->execute($wp); $stats = $stat_s->fetch(PDO::FETCH_ASSOC);
$net = $stats['total_credit'] - $stats['total_debit'];

/* Paginated list — chronological (ledger order) */
$per_page = 50;
$page     = max(1, intval($_GET['page'] ?? 1));
$cnt_s    = $pdo->prepare("SELECT COUNT(*) FROM manual_journal $wsql"); $cnt_s->execute($wp);
$total_rows  = (int)$cnt_s->fetchColumn();
$total_pages = max(1,(int)ceil($total_rows/$per_page));
$page        = min($page,$total_pages);
$offset      = ($page-1)*$per_page;

$data_s = $pdo->prepare("SELECT mj.*,u.username AS created_by_name FROM manual_journal mj LEFT JOIN users u ON u.id=mj.created_by $wsql ORDER BY mj.entry_date ASC, mj.created_at ASC, mj.id ASC LIMIT $per_page OFFSET $offset");
$data_s->execute($wp); $entries=$data_s->fetchAll(PDO::FETCH_ASSOC);

/* ── Running balance (full ledger history, independent of the Type filter) ── */
$hist = $pdo->query("SELECT id, entry_date, amount, entry_type FROM manual_journal ORDER BY entry_date ASC, created_at ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);
$bal = 0.0; $balance_after = []; $opening_balance = 0.0;
foreach($hist as $h){
    $bal += $h['entry_type']==='credit' ? (float)$h['amount'] : -(float)$h['amount'];
    $balance_after[$h['id']] = $bal;
    if($f['date_from'] && $h['entry_date'] < $f['date_from']) $opening_balance = $bal;
}
$overall_balance = $bal;
/* On later pages, the running balance already carries the correct total, so we
   only show the explicit "Balance b/f" row on page 1. */
$show_opening_row = ($page === 1);

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-pen-to-square" style="margin-right:.4rem;color:var(--text-muted)"></i>Manual Journal</h2>
    <div style="display:flex;gap:.5rem">
        <button class="btn btn-secondary" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
        <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add Entry</button>
    </div>
</div>

<!-- Shown only when printing -->
<div class="print-only" style="display:none;margin-bottom:1rem">
    <h2 style="margin:0 0 .2rem">Manual Journal</h2>
    <div style="font-size:.85rem;color:#333">
        Period: <?= $f['date_from'] ? date('d M Y',strtotime($f['date_from'])) : 'All time' ?>
        &ndash;
        <?= $f['date_to'] ? date('d M Y',strtotime($f['date_to'])) : 'Today' ?>
        <?php if($f['entry_type']): ?> &middot; Type: <?= ucfirst($f['entry_type']) ?><?php endif; ?>
        &middot; Printed <?= date('d M Y H:i') ?>
    </div>
</div>

<!-- Stats -->
<div class="mj-stats" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem;margin-bottom:1rem">
    <div style="border:1px solid var(--border);border-left:4px solid #16a34a;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.25rem;display:flex;align-items:center;gap:.4rem">
            <i class="fas fa-circle-plus" style="color:#16a34a"></i> Total Credit
        </div>
        <div style="font-size:1.1rem;font-weight:700;color:#16a34a"><?= number_format($stats['total_credit'],2) ?> <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">FRW</span></div>
    </div>
    <div style="border:1px solid var(--border);border-left:4px solid #dc2626;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.25rem;display:flex;align-items:center;gap:.4rem">
            <i class="fas fa-circle-minus" style="color:#dc2626"></i> Total Debit
        </div>
        <div style="font-size:1.1rem;font-weight:700;color:#dc2626"><?= number_format($stats['total_debit'],2) ?> <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">FRW</span></div>
    </div>
    <div style="border:1px solid var(--border);border-left:4px solid <?= $net>=0?'#2563eb':'#dc2626' ?>;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.25rem;display:flex;align-items:center;gap:.4rem">
            <i class="fas fa-scale-balanced" style="color:<?= $net>=0?'#2563eb':'#dc2626' ?>"></i> Net (period)
        </div>
        <div style="font-size:1.1rem;font-weight:700;color:<?= $net>=0?'#2563eb':'#dc2626' ?>"><?= number_format($net,2) ?> <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">FRW</span></div>
        <div style="font-size:.72rem;color:var(--text-muted);margin-top:.1rem"><?= $stats['cnt'] ?> entr<?= $stats['cnt']==1?'y':'ies' ?></div>
    </div>
    <div style="border:1px solid var(--border);border-left:4px solid #7c3aed;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.25rem;display:flex;align-items:center;gap:.4rem">
            <i class="fas fa-book" style="color:#7c3aed"></i> Current Balance
        </div>
        <div style="font-size:1.1rem;font-weight:700;color:#7c3aed"><?= number_format($overall_balance,2) ?> <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">FRW</span></div>
        <div style="font-size:.72rem;color:var(--text-muted);margin-top:.1rem">As of today, all-time</div>
    </div>
</div>

<!-- Filters -->
<form method="GET" action="manual_journal.php" class="filter-bar" style="margin-bottom:1rem">
    <div class="filter-group">
        <label>From</label>
        <input type="date" name="date_from" value="<?= htmlspecialchars($f['date_from']) ?>">
    </div>
    <div class="filter-group">
        <label>To</label>
        <input type="date" name="date_to" value="<?= htmlspecialchars($f['date_to']) ?>">
    </div>
    <div class="filter-group">
        <label>Type</label>
        <select name="entry_type">
            <option value="">All types</option>
            <option value="credit" <?= $f['entry_type']==='credit'?'selected':'' ?>>Credit</option>
            <option value="debit"  <?= $f['entry_type']==='debit' ?'selected':'' ?>>Debit</option>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-filter"></i> Filter</button>
        <a href="manual_journal.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-xmark"></i> Clear</a>
    </div>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> <?= $total_rows ?> record<?= $total_rows!=1?'s':'' ?></span>
</form>

<?php if($f['entry_type']): ?>
<div style="font-size:.78rem;color:var(--text-muted);margin:-.5rem 0 .75rem;display:flex;align-items:center;gap:.35rem">
    <i class="fas fa-circle-info"></i> Balance reflects the full ledger — the Type filter only limits which rows are shown.
</div>
<?php endif; ?>

<style>
/* White page background, scoped to this page */
body, .page-content { background:#fff; }

/* Excel-style grid borders, scoped to this page's ledger table */
.ledger-table { border-collapse:collapse; }
.ledger-table th, .ledger-table td { border:1px solid var(--border); }
.ledger-table tbody tr:last-child td { border-bottom:1px solid var(--border); }
.ledger-table tfoot td { border:1px solid var(--border); }

/* Print */
@media print {
    .topnav, .quickbar, .topbar, .filter-bar, .page-header button,
    #page-alert, .modal-backdrop, .pagination-wrap, .pagination-info,
    .mj-stats { display:none !important; }
    body, .page-content { background:#fff; padding:0; }
    .print-only { display:block !important; }
    .table-wrap { border:none; box-shadow:none; overflow:visible; }
    .ledger-table { font-size:.8rem; }
    .ledger-table th, .ledger-table td { border-color:#000; }
    .ledger-table th:last-child, .ledger-table td:last-child { display:none; }
}
</style>

<!-- Table -->
<div class="table-wrap">
    <table class="ledger-table">
        <thead>
            <tr>
                <th>Date</th>
                <th>Particulars</th>
                <th style="text-align:right">Debit (FRW)</th>
                <th style="text-align:right">Credit (FRW)</th>
                <th style="text-align:right">Balance (FRW)</th>
                <th>By</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="mj-tbody">
        <?php if(!$entries && !$show_opening_row): ?>
        <tr id="empty-row"><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No manual journal entries for this period.</td></tr>
        <?php endif; ?>
        <?php if($show_opening_row): ?>
        <tr style="background:var(--bg)">
            <td class="text-muted" style="font-size:.8rem;white-space:nowrap"><?= $f['date_from'] ? date('d M Y',strtotime($f['date_from'])) : '—' ?></td>
            <td style="font-size:.83rem;font-style:italic;color:var(--text-muted)">Balance brought forward</td>
            <td></td>
            <td></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $opening_balance>=0?'#2563eb':'#dc2626' ?>"><?= number_format($opening_balance,2) ?></td>
            <td></td>
            <td></td>
        </tr>
        <?php endif; ?>
        <?php $page_debit=0; $page_credit=0; $last_balance=$opening_balance;
        foreach($entries as $en): $isCredit = $en['entry_type']==='credit';
            $rowBal = $balance_after[$en['id']] ?? $last_balance;
            $last_balance = $rowBal;
            if($isCredit) $page_credit += $en['amount']; else $page_debit += $en['amount'];
        ?>
        <tr id="mj-row-<?= $en['id'] ?>">
            <td class="text-muted" style="font-size:.82rem;white-space:nowrap">
                <?= date('d M Y',strtotime($en['entry_date'])) ?>
                <div style="font-size:.7rem">Processed: <?= date('H:i',strtotime($en['created_at'])) ?></div>
            </td>
            <td style="font-size:.85rem;font-weight:500"><?= htmlspecialchars($en['comment']) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:600;color:#dc2626"><?= $isCredit ? '' : number_format($en['amount'],2) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:600;color:#16a34a"><?= $isCredit ? number_format($en['amount'],2) : '' ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $rowBal>=0?'var(--text)':'#dc2626' ?>"><?= number_format($rowBal,2) ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($en['created_by_name'] ?? '—') ?></td>
            <td>
                <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteEntry(<?= $en['id'] ?>,this)">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <?php if($entries): ?>
        <tfoot>
            <tr style="border-top:2px solid var(--border);background:var(--surface,var(--bg))">
                <td colspan="2" style="font-size:.82rem;font-weight:600;padding:.6rem 1rem;color:var(--text-muted)">Page total</td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#dc2626;padding:.6rem 1rem"><?= number_format($page_debit,2) ?></td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#16a34a;padding:.6rem 1rem"><?= number_format($page_credit,2) ?></td>
                <td style="text-align:right;font-family:monospace;font-weight:700;padding:.6rem 1rem">Bal: <?= number_format($last_balance,2) ?></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
        <?php endif; ?>
    </table>
    <?php if($total_pages>1): ?><?= paginate($page,$total_pages,array_filter($f),'manual_journal.php') ?><?php endif; ?>
</div>

<!-- ── Add Entry Modal ─────────────────────────────────────────── -->
<div class="modal-backdrop" id="mj-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <h3><i class="fas fa-pen-to-square" style="margin-right:.4rem;color:var(--primary)"></i>Add Manual Journal Entry</h3>
            <button class="modal-close" onclick="closeModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div id="modal-alert" style="display:none;margin-bottom:.75rem;padding:.55rem .75rem;border-radius:6px;font-size:.83rem;align-items:center;gap:.5rem"></div>
            <form id="mj-form">
                <input type="hidden" name="save_entry" value="1">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Date</label>
                        <input type="date" name="entry_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Type</label>
                        <select name="entry_type" required>
                            <option value="credit">Credit</option>
                            <option value="debit">Debit</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Amount (FRW)</label>
                        <input type="text" name="amount" id="mj-amount" placeholder="0.00" required style="font-family:monospace" inputmode="decimal" oninput="formatAmountInput(this)">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Particulars</label>
                        <textarea name="comment" placeholder="Reason for this entry…" style="min-height:70px" required></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="mj-form" id="m-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Entry
            </button>
        </div>
    </div>
</div>

<script>
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

/* ── Modal open / close ─────────────────────────────────────── */
function openModal(){
    document.getElementById('mj-modal').classList.add('open');
    document.body.style.overflow='hidden';
    clearModalAlert();
}
function closeModal(){
    document.getElementById('mj-modal').classList.remove('open');
    document.body.style.overflow='';
    clearModalAlert();
}
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeModal(); });

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

/* ── Live thousand-separator formatting (e.g. 1,000 / 10,000) ── */
function formatAmountInput(el){
    const caretFromEnd = el.value.length - el.selectionEnd;
    let raw = el.value.replace(/[^\d.]/g,'');
    const firstDot = raw.indexOf('.');
    if(firstDot !== -1) raw = raw.slice(0,firstDot+1) + raw.slice(firstDot+1).replace(/\./g,'');
    const [intPart,decPart] = raw.split('.');
    const grouped = intPart ? parseInt(intPart,10).toLocaleString('en-US') : '';
    el.value = grouped + (decPart !== undefined ? '.'+decPart.slice(0,2) : '');
    const pos = Math.max(0, el.value.length - caretFromEnd);
    el.setSelectionRange(pos,pos);
}

/* ── Flash message across reload (balances must be recomputed server-side) ── */
function flash(type,msg){ sessionStorage.setItem('mj_flash', JSON.stringify({type,msg})); }
(function showFlash(){
    const raw = sessionStorage.getItem('mj_flash');
    if(!raw) return;
    sessionStorage.removeItem('mj_flash');
    try { const {type,msg} = JSON.parse(raw); showAlert(type,msg); } catch(e){}
})();

/* ── Form submit ────────────────────────────────────────────── */
document.getElementById('mj-form').addEventListener('submit',function(e){
    e.preventDefault();
    const btn=document.getElementById('m-save-btn');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
    fetch('manual_journal.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new FormData(this)})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            flash('success',d.message);
            location.reload();
        } else {
            showModalAlert(d.message);
            btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Entry';
        }
    }).catch(()=>{showModalAlert('Network error. Please try again.');btn.disabled=false;btn.innerHTML='<i class="fas fa-save"></i> Save Entry';});
});

/* ── Delete ─────────────────────────────────────────────────── */
function deleteEntry(id,btn){
    if(!confirm('Delete this journal entry? This cannot be undone.')) return;
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    const fd=new FormData(); fd.append('delete_entry','1'); fd.append('id',id);
    fetch('manual_journal.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){ flash('success',d.message); location.reload(); }
        else { showAlert('error',d.message); btn.disabled=false; btn.innerHTML='<i class="fas fa-trash"></i>'; }
    }).catch(()=>{ showAlert('error','Network error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-trash"></i>'; });
}
</script>

<?php include 'includes/footer.php'; ?>
