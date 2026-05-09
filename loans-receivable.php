<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── Ensure payment columns exist ───────────────────────────── */
try { $pdo->exec("ALTER TABLE supplier_loans ADD COLUMN payment_method ENUM('cash','bank','momo') DEFAULT NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE supplier_loans ADD COLUMN account_id INT DEFAULT NULL"); } catch(Exception $e){}

/* ── AJAX: delete supplier loan ─────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['delete'])){
    header('Content-Type: application/json');
    try {
        $id=intval($_POST['id']??0);
        if(!$id) throw new Exception('Invalid record.');
        $row=$pdo->prepare("SELECT * FROM supplier_loans WHERE id=?"); $row->execute([$id]); $rec=$row->fetch();
        if(!$rec) throw new Exception('Record not found.');
        $pdo->prepare("DELETE FROM supplier_loans WHERE id=?")->execute([$id]);
        logAction($pdo,$_SESSION['user_id'],'DELETE','supplier_loans',$id,"Deleted {$rec['type']} {$rec['amount']} FRW supplier#{$rec['supplier_id']}");
        $bs=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' AND is_deferred=0 THEN amount WHEN type='repayment' AND is_deferred=0 THEN -amount ELSE 0 END),0) FROM supplier_loans WHERE supplier_id=?");
        $bs->execute([$rec['supplier_id']]);
        echo json_encode(['success'=>true,'message'=>'Record deleted.','supplier_id'=>(int)$rec['supplier_id'],'new_balance'=>(float)$bs->fetchColumn()]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ── AJAX: record supplier advance / repayment ──────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    try {
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $type        = $_POST['type'] ?? '';
        $notes       = trim($_POST['notes'] ?? '');
        if(!$supplier_id)                         throw new Exception('Please select a supplier.');
        if(!in_array($type,['loan','repayment']))  throw new Exception('Invalid type.');

        /* Parse payment rows */
        $valid_payments=[]; $amount=0.0;
        foreach($_POST['payments'] ?? [] as $p){
            $pm=$p['method']??''; $aid=intval($p['account_id']??0); $amt=round(floatval($p['amount']??0),2);
            if(!in_array($pm,['cash','bank','momo'],true)||$amt<=0) continue;
            $valid_payments[]=['method'=>$pm,'account_id'=>($aid?:null),'amount'=>$amt]; $amount=round($amount+$amt,2);
        }
        if(empty($valid_payments)) throw new Exception('Please add at least one payment row.');

        if($type==='repayment'){
            $cb=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' AND is_deferred=0 THEN amount WHEN type='repayment' AND is_deferred=0 THEN -amount ELSE 0 END),0) FROM supplier_loans WHERE supplier_id=?");
            $cb->execute([$supplier_id]); $bal=(float)$cb->fetchColumn();
            if($bal<=0)      throw new Exception('This supplier has no outstanding advance to repay.');
            if($amount>$bal) throw new Exception('Total of '.number_format($amount,2).' FRW exceeds advance balance of '.number_format($bal,2).' FRW.');
        }

        $pdo->beginTransaction();
        $stmtAcct   =$pdo->prepare("SELECT balance,account_name FROM company_accounts WHERE id=? AND is_active=1 AND account_type=? FOR UPDATE");
        $stmtUpdBal =$pdo->prepare("UPDATE company_accounts SET balance=? WHERE id=?");
        $stmtAcctTxn=$pdo->prepare("INSERT INTO account_transactions (account_id,txn_type,amount,balance_after,reference_type,reference_id,description,created_by) VALUES (?,?,?,?,'supplier_loan',NULL,?,?)");

        foreach($valid_payments as $vp){
            if(!$vp['account_id']) continue;
            $stmtAcct->execute([$vp['account_id'],$vp['method']]); $acct=$stmtAcct->fetch();
            if(!$acct) throw new Exception('Account #'.$vp['account_id'].' not found or type mismatch.');
            if($type==='loan'){
                if($acct['balance']<$vp['amount']) throw new Exception('Insufficient balance in "'.$acct['account_name'].'". Available: '.number_format($acct['balance'],2).' FRW, needed: '.number_format($vp['amount'],2).' FRW.');
                $nb=round($acct['balance']-$vp['amount'],2); $txnType='debit'; $desc='Advance to supplier#'.$supplier_id;
            } else {
                $nb=round($acct['balance']+$vp['amount'],2); $txnType='credit'; $desc='Repayment from supplier#'.$supplier_id;
            }
            $stmtUpdBal->execute([$nb,$vp['account_id']]);
            $stmtAcctTxn->execute([$vp['account_id'],$txnType,$vp['amount'],$nb,$desc,$_SESSION['user_id']]);
        }

        $pm_label=count($valid_payments)>1?'mixed':$valid_payments[0]['method'];
        $pm_acct =count($valid_payments)===1?($valid_payments[0]['account_id']?:null):null;
        $pdo->prepare("INSERT INTO supplier_loans (supplier_id,batch_id,type,amount,notes,payment_method,account_id,created_by) VALUES (?,NULL,?,?,?,?,?,?)")
            ->execute([$supplier_id,$type,$amount,$notes,$pm_label,$pm_acct,$_SESSION['user_id']]);
        $newId=$pdo->lastInsertId();
        logAction($pdo,$_SESSION['user_id'],'CREATE','supplier_loans',$newId,($type==='loan'?'Advance given':'Repayment recorded')." supplier#$supplier_id: $amount FRW via $pm_label");
        $pdo->commit();

        $bs=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' AND is_deferred=0 THEN amount WHEN type='repayment' AND is_deferred=0 THEN -amount ELSE 0 END),0) FROM supplier_loans WHERE supplier_id=?");
        $bs->execute([$supplier_id]); $new_bal=(float)$bs->fetchColumn();
        $rs=$pdo->prepare("SELECT sl.*,s.name AS supplier_name,u.username AS created_by_name FROM supplier_loans sl JOIN suppliers s ON sl.supplier_id=s.id LEFT JOIN users u ON sl.created_by=u.id WHERE sl.id=?");
        $rs->execute([$newId]); $row=$rs->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'message'=>($type==='loan'?'Advance':'Repayment').' of '.number_format($amount,2).' FRW recorded.','row'=>$row,'new_balance'=>$new_bal,'supplier_id'=>$supplier_id]);
    } catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ══════════════════════════════════════════════════════════════
   Page data
══════════════════════════════════════════════════════════════ */
$page_title = 'Loan Receivable';

$suppliers        = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
$company_accounts = $pdo->query("SELECT id,account_type,account_name,balance FROM company_accounts WHERE is_active=1 ORDER BY account_type,account_name")->fetchAll(PDO::FETCH_ASSOC);

/* ── Supplier advance balances ────────────────────────────────── */
$sup_adv_raw = $pdo->query("
    SELECT s.id, s.name,
           COALESCE(SUM(CASE WHEN sl.type='loan' AND sl.is_deferred=0 THEN sl.amount ELSE 0 END),0) AS total_advanced,
           COALESCE(SUM(CASE WHEN sl.type='repayment' AND sl.is_deferred=0 THEN sl.amount ELSE 0 END),0) AS total_repaid,
           COALESCE(SUM(CASE WHEN sl.type='loan' AND sl.is_deferred=0 THEN sl.amount
                             WHEN sl.type='repayment' AND sl.is_deferred=0 THEN -sl.amount ELSE 0 END),0) AS balance,
           COUNT(sl.id) AS entries
    FROM suppliers s
    LEFT JOIN supplier_loans sl ON sl.supplier_id=s.id
    GROUP BY s.id, s.name ORDER BY balance DESC, s.name
")->fetchAll(PDO::FETCH_ASSOC);
$sup_adv_map=[];
foreach($sup_adv_raw as $r) $sup_adv_map[(int)$r['id']]=$r;

/* ── Global stats ─────────────────────────────────────────────── */
$stat_advances = array_sum(array_column(array_filter($sup_adv_raw,fn($r)=>$r['balance']>0),'balance'));
$stat_total_adv = array_sum(array_column($sup_adv_raw,'total_advanced'));
$stat_total_rep = array_sum(array_column($sup_adv_raw,'total_repaid'));
$cnt_advances   = count(array_filter($sup_adv_raw,fn($r)=>$r['balance']>0));

/* ── Detail routing ──────────────────────────────────────────── */
$view_supplier_id = intval($_GET['supplier_id'] ?? 0);
$view_sup_adv     = $view_supplier_id ? ($sup_adv_map[$view_supplier_id] ?? null) : null;

if($view_supplier_id && !$view_sup_adv){
    $q=$pdo->prepare("SELECT id,name FROM suppliers WHERE id=?"); $q->execute([$view_supplier_id]);
    if($r=$q->fetch()) $view_sup_adv=['id'=>$r['id'],'name'=>$r['name'],'total_advanced'=>0,'total_repaid'=>0,'balance'=>0,'entries'=>0];
}

/* ── History for detail view ─────────────────────────────────── */
$loans=[]; $total=0; $total_pages=1; $page=1; $offset=0; $per_page=25;
$date_from=$_GET['date_from']??''; $date_to=$_GET['date_to']??'';

if($view_supplier_id && $view_sup_adv){
    $page=max(1,intval($_GET['page']??1));
    $wh=["sl.supplier_id=?","(sl.type='repayment' OR (sl.type='loan' AND sl.is_deferred=0))"]; $pa=[$view_supplier_id];
    if($date_from){$wh[]='DATE(sl.created_at)>=?';$pa[]=$date_from;}
    if($date_to)  {$wh[]='DATE(sl.created_at)<=?';$pa[]=$date_to;}
    $wsql='WHERE '.implode(' AND ',$wh);
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM supplier_loans sl $wsql"); $cnt->execute($pa);
    $total=(int)$cnt->fetchColumn(); $total_pages=max(1,(int)ceil($total/$per_page));
    $page=min($page,$total_pages); $offset=($page-1)*$per_page;
    $s=$pdo->prepare("SELECT sl.*,u.username AS created_by_name FROM supplier_loans sl LEFT JOIN users u ON sl.created_by=u.id $wsql ORDER BY sl.created_at DESC LIMIT $per_page OFFSET $offset");
    $s->execute($pa); $loans=$s->fetchAll();
}

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<?php
/* ════ SUPPLIER ADVANCE DETAIL ═════════════════════════════════ */
if($view_supplier_id && $view_sup_adv):
    $bal=(float)$view_sup_adv['balance']; $col=$bal>0?'#dc2626':'#16a34a';
?>
<div class="page-header">
    <div style="display:flex;align-items:center;gap:.75rem">
        <a href="loans-receivable.php" class="btn btn-secondary" style="padding:.4rem .75rem"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h2 style="margin:0"><i class="fas fa-industry" style="margin-right:.4rem;color:var(--text-muted)"></i><?= htmlspecialchars($view_sup_adv['name']) ?></h2>
            <div style="font-size:.8rem;color:var(--text-muted);margin-top:.1rem">Cash Advances — Supplier Owes Us</div>
        </div>
    </div>
    <div style="display:flex;gap:.5rem">
        <a href="loans-payable.php?supplier_id=<?= $view_supplier_id ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-right-arrow-left"></i> View Deferred Payments
        </a>
        <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Record Advance / Repayment</button>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem">
    <div class="stat-card">
        <div class="stat-icon si-red"><i class="fas fa-hand-holding-dollar"></i></div>
        <div class="stat-info"><div class="stat-label">Total Advanced</div>
            <div class="stat-value"><?= number_format($view_sup_adv['total_advanced'],0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub">Cash given to supplier</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-green"><i class="fas fa-rotate-left"></i></div>
        <div class="stat-info"><div class="stat-label">Total Repaid</div>
            <div class="stat-value"><?= number_format($view_sup_adv['total_repaid'],0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub">Recovered from supplier</div></div>
    </div>
    <div class="stat-card" style="border-left:4px solid <?= $col ?>">
        <div class="stat-icon" style="background:<?= $bal>0?'#fef2f2':'#f0fdf4' ?>;color:<?= $col ?>"><i class="fas fa-scale-balanced"></i></div>
        <div class="stat-info"><div class="stat-label">Outstanding</div>
            <div class="stat-value" style="color:<?= $col ?>"><?= number_format(abs($bal),0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub" style="color:<?= $col ?>"><?= $bal>0?'Supplier owes us':'Settled' ?></div></div>
    </div>
</div>

<form method="GET" action="loans-receivable.php" class="filter-bar" style="margin-bottom:1rem">
    <input type="hidden" name="supplier_id" value="<?= $view_supplier_id ?>">
    <div class="filter-group"><label>From</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
    <div class="filter-group"><label>To</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-filter"></i> Filter</button>
        <?php if($date_from||$date_to): ?><a href="loans-receivable.php?supplier_id=<?= $view_supplier_id ?>" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-xmark"></i> Clear</a><?php endif; ?>
    </div>
</form>

<div class="table-wrap">
    <table>
        <thead><tr><th>#</th><th>Date</th><th>Type</th><th style="text-align:right">Amount (FRW)</th><th>Method</th><th>Notes</th><th>By</th><th></th></tr></thead>
        <tbody id="loans-tbody">
        <?php $i=0; foreach($loans as $ln): ?>
        <tr id="loan-row-<?= $ln['id'] ?>">
            <td class="text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="text-muted" style="font-size:.82rem;white-space:nowrap"><?= date('d M Y H:i',strtotime($ln['created_at'])) ?></td>
            <td><?= $ln['type']==='loan'
                ? '<span class="badge badge-danger"><i class="fas fa-arrow-up" style="font-size:.65rem"></i> Advance</span>'
                : '<span class="badge badge-success"><i class="fas fa-arrow-down" style="font-size:.65rem"></i> Repayment</span>' ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $ln['type']==='loan'?'#dc2626':'#16a34a' ?>">
                <?= ($ln['type']==='loan'?'+':'−').number_format($ln['amount'],2) ?></td>
            <td style="font-size:.78rem"><?= $ln['payment_method'] ? '<span class="badge" style="background:var(--border);color:var(--text-muted)">'.strtoupper($ln['payment_method']).'</span>' : '<span class="text-muted">—</span>' ?></td>
            <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($ln['notes']??'') ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($ln['created_by_name']??'') ?></td>
            <td><button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteLoan(<?= $ln['id'] ?>,this)"><i class="fas fa-trash"></i></button></td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$loans): ?><tr id="empty-row"><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No advance records found.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if($total_pages>1): ?><?= paginate($page,$total_pages,['supplier_id'=>$view_supplier_id,'date_from'=>$date_from,'date_to'=>$date_to],'loans-receivable.php') ?><?php endif; ?>
</div>

<?php
/* ════ LIST VIEW ═══════════════════════════════════════════════ */
else:
?>
<div class="page-header">
    <h2><i class="fas fa-arrow-down" style="margin-right:.4rem;color:var(--text-muted)"></i>Loan Receivable</h2>
    <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Record Supplier Advance</button>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem">
    <div class="stat-card">
        <div class="stat-icon si-red"><i class="fas fa-hand-holding-dollar"></i></div>
        <div class="stat-info"><div class="stat-label">Total Advanced</div>
            <div class="stat-value"><?= number_format($stat_total_adv,0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub">All time cash given to suppliers</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-green"><i class="fas fa-rotate-left"></i></div>
        <div class="stat-info"><div class="stat-label">Total Repaid</div>
            <div class="stat-value"><?= number_format($stat_total_rep,0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub">All time recovered</div></div>
    </div>
    <div class="stat-card" style="border-left:4px solid <?= $stat_advances>0?'var(--danger)':'var(--success)' ?>">
        <div class="stat-icon <?= $stat_advances>0?'si-red':'si-green' ?>"><i class="fas fa-scale-balanced"></i></div>
        <div class="stat-info"><div class="stat-label">Outstanding</div>
            <div class="stat-value" style="color:<?= $stat_advances>0?'var(--danger)':'var(--success)' ?>"><?= number_format($stat_advances,0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub"><?= $cnt_advances ?> supplier<?= $cnt_advances!=1?'s':'' ?> with outstanding advances</div></div>
    </div>
</div>

<div style="display:flex;align-items:center;gap:.6rem;margin-bottom:.75rem">
    <div style="width:4px;height:1.4rem;background:#16a34a;border-radius:2px"></div>
    <span style="font-weight:700;font-size:.9rem">Suppliers — Cash Advances (They Owe Us Back)</span>
    <span style="font-size:.78rem;color:var(--text-muted);font-family:monospace"><?= number_format($stat_advances,2) ?> FRW outstanding</span>
</div>

<?php $adv_list=array_filter($sup_adv_raw,fn($r)=>(int)$r['entries']>0); ?>
<?php if($adv_list): ?>
<div class="table-wrap">
    <table>
        <thead><tr><th>Supplier</th><th style="text-align:right">Total Advanced</th><th style="text-align:right">Total Repaid</th><th style="text-align:right">Outstanding</th><th style="text-align:center">Records</th><th style="text-align:center">Status</th><th></th></tr></thead>
        <tbody>
        <?php foreach($adv_list as $s):
            $bal=(float)$s['balance']; $col=$bal>0?'#dc2626':($bal<0?'#2563eb':'#16a34a');
            $lbl=$bal>0?'Owes Us':($bal<0?'Credit':'Settled');
            $bdg=$bal>0?'badge-danger':($bal<0?'badge-primary':'badge-success');
        ?>
        <tr style="cursor:pointer" onclick="location.href='loans-receivable.php?supplier_id=<?= $s['id'] ?>'"
            onmouseover="this.style.background='var(--border)22'" onmouseout="this.style.background=''">
            <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
            <td style="text-align:right;font-family:monospace;color:#dc2626;font-weight:600"><?= number_format($s['total_advanced'],2) ?></td>
            <td style="text-align:right;font-family:monospace;color:#16a34a;font-weight:600"><?= number_format($s['total_repaid'],2) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $col ?>"><?= number_format(abs($bal),2) ?> <span style="font-size:.7rem;font-weight:500">FRW</span></td>
            <td style="text-align:center"><span class="badge" style="background:var(--border);color:var(--text-muted)"><?= (int)$s['entries'] ?></span></td>
            <td style="text-align:center"><span class="badge <?= $bdg ?>"><?= $lbl ?></span></td>
            <td onclick="event.stopPropagation()"><a href="loans-receivable.php?supplier_id=<?= $s['id'] ?>" class="btn btn-secondary" style="padding:.3rem .65rem;font-size:.78rem"><i class="fas fa-eye"></i> View</a></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div style="border:1px dashed var(--border);border-radius:8px;padding:1.5rem;text-align:center;color:var(--text-muted);font-size:.85rem">
    No supplier advance records yet. Use "Record Supplier Advance" to add one.
</div>
<?php endif; ?>

<?php endif; ?>

<!-- ── Supplier Advance Modal ─────────────────────────────────── -->
<div class="modal-backdrop" id="loan-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <h3><i class="fas fa-industry" style="margin-right:.4rem;color:var(--primary)"></i>Record Supplier Advance / Repayment</h3>
            <button class="modal-close" onclick="closeModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="loan-form">
                <div class="form-grid form-grid-2">
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Supplier</label>
                        <select name="supplier_id" id="m-supplier" required onchange="updateBalance(this.value)" <?= $view_supplier_id?'disabled':'' ?>>
                            <option value="">— Select supplier —</option>
                            <?php foreach($suppliers as $s): ?><option value="<?= $s['id'] ?>" <?= $view_supplier_id==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option><?php endforeach; ?>
                        </select>
                        <?php if($view_supplier_id): ?><input type="hidden" name="supplier_id" value="<?= $view_supplier_id ?>"><?php endif; ?>
                        <div id="m-balance" style="margin-top:.4rem;font-size:.82rem;display:none"></div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Type</label>
                        <select name="type" id="m-type" required onchange="onAdvTypeChange()">
                            <option value="">— Choose —</option>
                            <option value="loan">Give Cash Advance to Supplier</option>
                            <option value="repayment">Record Repayment from Supplier</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Payment <button type="button" onclick="addAdvPayRow()" style="margin-left:.5rem;padding:.15rem .5rem;font-size:.75rem" class="btn btn-secondary"><i class="fas fa-plus"></i> Add Row</button></label>
                        <div id="adv-pay-rows" style="margin-top:.3rem"></div>
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-top:.4rem;padding:.4rem .5rem;background:var(--surface);border:1px solid var(--border);border-radius:6px;font-size:.82rem">
                            <span style="color:var(--text-muted)">Total</span>
                            <strong id="adv-pay-total">0.00 FRW</strong>
                        </div>
                        <div id="adv-warning" style="display:none;margin-top:.35rem;font-size:.8rem;color:#dc2626"></div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Optional…" style="min-height:52px"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="loan-form" id="m-save-btn" class="btn btn-primary"><i class="fas fa-save"></i> Save</button>
        </div>
    </div>
</div>

<script>
const supplierBalances = <?= json_encode(array_column($sup_adv_raw,'balance','id')) ?>;
const viewSupplierId   = <?= $view_supplier_id ?: 'null' ?>;
const companyAccounts  = <?= json_encode(array_values($company_accounts)) ?>;

let advPayRowN = 0;

function buildAdvAcctOpts(method){
    const filtered=companyAccounts.filter(a=>a.account_type===method);
    return filtered.length
        ?'<option value="">— Account —</option>'+filtered.map(a=>`<option value="${a.id}">${a.account_name} (${parseFloat(a.balance).toLocaleString('en-US',{minimumFractionDigits:2})} FRW)</option>`).join('')
        :'<option value="">No '+method+' accounts</option>';
}

function addAdvPayRow(){
    const n=advPayRowN++;
    const row=document.createElement('div');
    row.className='pay-row'; row.id='adv-pr-'+n;
    row.style.cssText='display:flex;gap:.4rem;align-items:center;margin-bottom:.4rem';
    row.innerHTML=`<select onchange="onAdvPayRowMethod(${n})" style="flex:0 0 85px;font-size:.82rem;padding:.3rem .4rem">
        <option value="">Method</option>
        <option value="cash">Cash</option>
        <option value="bank">Bank</option>
        <option value="momo">MoMo</option>
    </select>
    <select id="adv-acc-${n}" style="flex:1;font-size:.82rem;padding:.3rem .4rem">
        <option value="">— select method —</option>
    </select>
    <input type="number" id="adv-amt-${n}" placeholder="Amount" min="0" step="0.01"
           style="flex:0 0 110px;font-size:.82rem;padding:.3rem .4rem" oninput="recalcAdvPayTotal()">
    <button type="button" onclick="removeAdvPayRow(${n})"
            style="flex:none;background:none;border:none;color:#dc2626;cursor:pointer;font-size:1rem;padding:.15rem .35rem" title="Remove">
        <i class="fas fa-times-circle"></i>
    </button>`;
    document.getElementById('adv-pay-rows').appendChild(row);
    recalcAdvPayTotal();
}

function removeAdvPayRow(n){
    document.getElementById('adv-pr-'+n)?.remove();
    recalcAdvPayTotal();
}

function onAdvPayRowMethod(n){
    const row=document.getElementById('adv-pr-'+n);
    const method=row.querySelector('select').value;
    document.getElementById('adv-acc-'+n).innerHTML=buildAdvAcctOpts(method);
    recalcAdvPayTotal();
}

function recalcAdvPayTotal(){
    let total=0;
    document.querySelectorAll('#adv-pay-rows .pay-row').forEach(row=>{
        const amt=parseFloat(row.querySelector('input[type="number"]')?.value||0);
        if(!isNaN(amt)&&amt>0) total=Math.round((total+amt)*100)/100;
    });
    document.getElementById('adv-pay-total').textContent=total.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW';
    onAdvTypeChange();
}

function onAdvTypeChange(){
    const type=document.getElementById('m-type').value;
    const suppId=document.getElementById('m-supplier')?.value||(viewSupplierId||'');
    const warn=document.getElementById('adv-warning');
    if(type!=='repayment'||!suppId){warn.style.display='none';return;}
    const bal=parseFloat(supplierBalances[suppId]||0);
    let total=0;
    document.querySelectorAll('#adv-pay-rows .pay-row').forEach(row=>{
        const amt=parseFloat(row.querySelector('input[type="number"]')?.value||0);
        if(!isNaN(amt)&&amt>0) total=Math.round((total+amt)*100)/100;
    });
    if(bal<=0){
        warn.innerHTML='<i class="fas fa-triangle-exclamation"></i> This supplier has no outstanding advance to repay.';
        warn.style.display='';
    } else if(total>bal){
        warn.innerHTML='<i class="fas fa-triangle-exclamation"></i> Total '+total.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW exceeds advance balance of '+bal.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW.';
        warn.style.display='';
    } else{
        warn.style.display='none';
    }
}

function openModal(){
    advPayRowN=0;
    document.getElementById('adv-pay-rows').innerHTML='';
    document.getElementById('m-type').value='';
    document.getElementById('adv-warning').style.display='none';
    document.getElementById('adv-pay-total').textContent='0.00 FRW';
    document.getElementById('loan-modal').classList.add('open'); document.body.style.overflow='hidden';
    if(viewSupplierId) updateBalance(viewSupplierId);
    addAdvPayRow();
}
function closeModal(){ document.getElementById('loan-modal').classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('keydown',e=>{ if(e.key==='Escape') closeModal(); });

function updateBalance(id){
    const line=document.getElementById('m-balance');
    if(!id){line.style.display='none';return;}
    const bal=parseFloat(supplierBalances[id]||0);
    line.style.display=''; line.style.color=bal>0?'#dc2626':'#16a34a';
    line.innerHTML=bal>0
        ?'<i class="fas fa-circle-exclamation"></i> Advance outstanding: <strong>'+bal.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW</strong>'
        :'<i class="fas fa-circle-check"></i> No outstanding advance';
    onAdvTypeChange();
}

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function showAlert(type,msg){
    const el=document.getElementById('page-alert');
    el.className='alert alert-'+type+' mb-15';
    el.innerHTML='<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display='flex'; clearTimeout(el._t); el._t=setTimeout(()=>{el.style.display='none'},5000);
}

document.getElementById('loan-form').addEventListener('submit',function(e){
    e.preventDefault();
    if(document.getElementById('adv-warning').style.display!=='none'){
        showAlert('error',document.getElementById('adv-warning').textContent.trim());return;
    }
    const fd=new FormData(this);
    let idx=0; let hasRows=false;
    document.querySelectorAll('#adv-pay-rows .pay-row').forEach(row=>{
        const sels=row.querySelectorAll('select');
        const method=sels[0].value; const acctId=sels[1].value;
        const amt=row.querySelector('input[type="number"]').value;
        if(!method||!amt||parseFloat(amt)<=0) return;
        fd.append(`payments[${idx}][method]`,method);
        fd.append(`payments[${idx}][account_id]`,acctId||'');
        fd.append(`payments[${idx}][amount]`,amt);
        idx++; hasRows=true;
    });
    if(!hasRows){showAlert('error','Please add at least one payment row with method and amount.');return;}
    const btn=document.getElementById('m-save-btn'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
    fetch('loans-receivable.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showAlert('success',d.message);
            supplierBalances[d.supplier_id]=d.new_balance;
            if(viewSupplierId) prependRow(d.row);
            closeModal(); this.reset();
            setTimeout(()=>location.reload(),1000);
        } else showAlert('error',d.message);
        btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save';
    }).catch(()=>{showAlert('error','Network error.');btn.disabled=false;btn.innerHTML='<i class="fas fa-save"></i> Save';});
});

function prependRow(r){
    const tbody=document.getElementById('loans-tbody'); if(!tbody) return;
    document.getElementById('empty-row')?.remove();
    const isLoan=r.type==='loan';
    const d=new Date(r.created_at.replace(' ','T')).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})+' '+r.created_at.slice(11,16);
    const tr=document.createElement('tr'); tr.id='loan-row-'+r.id;
    const meth=r.payment_method?'<span class="badge" style="background:var(--border);color:var(--text-muted)">'+r.payment_method.toUpperCase()+'</span>':'<span class="text-muted">—</span>';
    tr.innerHTML='<td class="text-muted" style="font-size:.78rem">'+esc(r.id)+'</td>'+
        '<td class="text-muted" style="font-size:.82rem;white-space:nowrap">'+esc(d)+'</td>'+
        '<td>'+(isLoan?'<span class="badge badge-danger"><i class="fas fa-arrow-up" style="font-size:.65rem"></i> Advance</span>':'<span class="badge badge-success"><i class="fas fa-arrow-down" style="font-size:.65rem"></i> Repayment</span>')+'</td>'+
        '<td style="text-align:right;font-family:monospace;font-weight:700;color:'+(isLoan?'#dc2626':'#16a34a')+'">'+(isLoan?'+':'−')+parseFloat(r.amount).toLocaleString('en-US',{minimumFractionDigits:2})+'</td>'+
        '<td style="font-size:.78rem">'+meth+'</td>'+
        '<td class="text-muted" style="font-size:.82rem">'+esc(r.notes||'')+'</td>'+
        '<td class="text-muted" style="font-size:.78rem">'+esc(r.created_by_name||'')+'</td>'+
        '<td><button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteLoan('+r.id+',this)"><i class="fas fa-trash"></i></button></td>';
    tbody.insertBefore(tr,tbody.firstChild);
}

function deleteLoan(id,btn){
    if(!confirm('Delete this record? This cannot be undone.')) return;
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    const fd=new FormData(); fd.append('delete','1'); fd.append('id',id);
    fetch('loans-receivable.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            const row=document.getElementById('loan-row-'+id);
            row.style.transition='opacity .3s'; row.style.opacity='0';
            setTimeout(()=>{ row.remove();
                if(!document.querySelector('#loans-tbody tr:not(#empty-row)'))
                    document.getElementById('loans-tbody').innerHTML='<tr id="empty-row"><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No records found.</td></tr>';
            },300);
            supplierBalances[d.supplier_id]=d.new_balance;
            showAlert('success',d.message);
        } else{showAlert('error',d.message);btn.disabled=false;btn.innerHTML='<i class="fas fa-trash"></i>';}
    }).catch(()=>{showAlert('error','Network error.');btn.disabled=false;btn.innerHTML='<i class="fas fa-trash"></i>';});
}
</script>

<?php include 'includes/footer.php'; ?>
