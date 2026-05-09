<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── Shared payment helpers ─────────────────────────────────── */
function parsePaymentRows(array $rows): array {
    $valid=[]; $total=0.0;
    foreach($rows as $row){
        $method=$row['method']??''; $acct=intval($row['account_id']??0); $amt=round(floatval($row['amount']??0),2);
        if(!in_array($method,['cash','bank','momo'],true)||$amt<=0) continue;
        $valid[]=['method'=>$method,'account_id'=>($acct?:null),'amount'=>$amt]; $total+=$amt;
    }
    return [$valid,round($total,2)];
}
function applyPaymentRows(PDO $pdo,array $rows,string $txnType,string $refType,string $desc,int $userId): void {
    foreach($rows as $row){
        if(!$row['account_id']) continue;
        $ar=$pdo->prepare("SELECT balance FROM company_accounts WHERE id=? AND is_active=1 AND account_type=? FOR UPDATE");
        $ar->execute([$row['account_id'],$row['method']]); $acct=$ar->fetch();
        if(!$acct) throw new Exception('Account #'.$row['account_id'].' not found or type mismatch.');
        $nb=round($txnType==='debit'?$acct['balance']-$row['amount']:$acct['balance']+$row['amount'],2);
        $pdo->prepare("UPDATE company_accounts SET balance=? WHERE id=?")->execute([$nb,$row['account_id']]);
        $pdo->prepare("INSERT INTO account_transactions (account_id,txn_type,amount,balance_after,reference_type,reference_id,description,created_by) VALUES (?,?,?,?,?,NULL,?,?)")
            ->execute([$row['account_id'],$txnType,$row['amount'],$nb,$refType,$desc,$userId]);
    }
}

/* ── AJAX: delete buyer loan ────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['delete_buyer'])){
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if(!$id) throw new Exception('Invalid record.');
        $row = $pdo->prepare("SELECT * FROM buyer_loans WHERE id=?"); $row->execute([$id]); $rec=$row->fetch();
        if(!$rec) throw new Exception('Record not found.');
        $pdo->prepare("DELETE FROM buyer_loans WHERE id=?")->execute([$id]);
        logAction($pdo,$_SESSION['user_id'],'DELETE','buyer_loans',$id,"Deleted {$rec['type']} {$rec['amount']} FRW buyer#{$rec['buyer_id']}");
        $bs=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' THEN amount ELSE -amount END),0) FROM buyer_loans WHERE buyer_id=?");
        $bs->execute([$rec['buyer_id']]);
        echo json_encode(['success'=>true,'message'=>'Record deleted.','buyer_id'=>(int)$rec['buyer_id'],'new_balance'=>(float)$bs->fetchColumn()]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ── AJAX: pay supplier deferred ────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['pay_supplier'])){
    header('Content-Type: application/json');
    try {
        $supplier_id = intval($_POST['supplier_id'] ?? 0);
        $notes       = trim($_POST['notes'] ?? '');
        if(!$supplier_id) throw new Exception('Invalid supplier.');
        [$valid_payments,$total_paid] = parsePaymentRows($_POST['payments'] ?? []);
        if(empty($valid_payments)) throw new Exception('Please add at least one payment row.');
        $cb=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' AND is_deferred=1 THEN amount WHEN type='repayment' AND is_deferred=1 THEN -amount ELSE 0 END),0) FROM supplier_loans WHERE supplier_id=?");
        $cb->execute([$supplier_id]); $bal=(float)$cb->fetchColumn();
        if($bal<=0)           throw new Exception('No outstanding deferred balance for this supplier.');
        if($total_paid>$bal)  throw new Exception('Total payment of '.number_format($total_paid,2).' FRW exceeds outstanding balance of '.number_format($bal,2).' FRW.');
        $pdo->beginTransaction();
        applyPaymentRows($pdo,$valid_payments,'debit','supplier_loan','Payment to supplier#'.$supplier_id,$_SESSION['user_id']);
        $pm_label = count($valid_payments)>1?'mixed':$valid_payments[0]['method'];
        $pm_acct  = count($valid_payments)===1?($valid_payments[0]['account_id']?:null):null;
        $pdo->prepare("INSERT INTO supplier_loans (supplier_id,batch_id,type,amount,notes,is_deferred,payment_method,account_id,created_by) VALUES (?,NULL,'repayment',?,?,1,?,?,?)")
            ->execute([$supplier_id,$total_paid,$notes,$pm_label,$pm_acct,$_SESSION['user_id']]);
        $newId=$pdo->lastInsertId();
        logAction($pdo,$_SESSION['user_id'],'CREATE','supplier_loans',$newId,"Paid deferred $total_paid FRW to supplier#$supplier_id via $pm_label");
        $pdo->commit();
        $bs=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' AND is_deferred=1 THEN amount WHEN type='repayment' AND is_deferred=1 THEN -amount ELSE 0 END),0) FROM supplier_loans WHERE supplier_id=?");
        $bs->execute([$supplier_id]); $new_bal=(float)$bs->fetchColumn();
        $rs=$pdo->prepare("SELECT sl.*,u.username AS created_by_name FROM supplier_loans sl LEFT JOIN users u ON sl.created_by=u.id WHERE sl.id=?");
        $rs->execute([$newId]); $row=$rs->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'message'=>'Payment of '.number_format($total_paid,2).' FRW recorded.','row'=>$row,'new_balance'=>$new_bal,'supplier_id'=>$supplier_id]);
    } catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ── AJAX: delete supplier deferred ─────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_POST['delete_supplier'])){
    header('Content-Type: application/json');
    try {
        $id = intval($_POST['id'] ?? 0);
        if(!$id) throw new Exception('Invalid record.');
        $row=$pdo->prepare("SELECT * FROM supplier_loans WHERE id=?"); $row->execute([$id]); $rec=$row->fetch();
        if(!$rec) throw new Exception('Record not found.');
        $pdo->prepare("DELETE FROM supplier_loans WHERE id=?")->execute([$id]);
        logAction($pdo,$_SESSION['user_id'],'DELETE','supplier_loans',$id,"Deleted deferred {$rec['amount']} FRW supplier#{$rec['supplier_id']}");
        echo json_encode(['success'=>true,'message'=>'Record deleted.','supplier_id'=>(int)$rec['supplier_id']]);
    } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ── AJAX: record buyer credit / payment ────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    try {
        $buyer_id = intval($_POST['buyer_id'] ?? 0);
        $type     = $_POST['type'] ?? '';
        $notes    = trim($_POST['notes'] ?? '');
        if(!$buyer_id)                            throw new Exception('Please select a buyer.');
        if(!in_array($type,['loan','repayment'])) throw new Exception('Invalid type.');
        $pdo->beginTransaction();
        if($type==='repayment'){
            /* Detect if this is an advance: buyer has no current outstanding balance */
            $bal_chk=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' THEN amount ELSE -amount END),0) FROM buyer_loans WHERE buyer_id=?");
            $bal_chk->execute([$buyer_id]); $cur_bal=(float)$bal_chk->fetchColumn();
            $is_advance=($cur_bal<=0.005)?1:0;
            [$valid_payments,$amount] = parsePaymentRows($_POST['payments'] ?? []);
            if(empty($valid_payments)) throw new Exception('Please add at least one payment row.');
            applyPaymentRows($pdo,$valid_payments,'credit','buyer_loan','Payment/advance from buyer#'.$buyer_id,$_SESSION['user_id']);
            $pm_label=count($valid_payments)>1?'mixed':$valid_payments[0]['method'];
            $pm_acct =count($valid_payments)===1?($valid_payments[0]['account_id']?:null):null;
        } else {
            $amount=floatval($_POST['amount']??0);
            if($amount<=0) throw new Exception('Amount must be greater than 0.');
            $pm_label=null; $pm_acct=null; $is_advance=0;
        }
        $pdo->prepare("INSERT INTO buyer_loans (buyer_id,sale_id,type,amount,notes,payment_method,account_id,is_advance,created_by) VALUES (?,NULL,?,?,?,?,?,?,?)")
            ->execute([$buyer_id,$type,$amount,$notes,$pm_label,$pm_acct,$is_advance,$_SESSION['user_id']]);
        $newId=$pdo->lastInsertId();
        logAction($pdo,$_SESSION['user_id'],'CREATE','buyer_loans',$newId,($type==='loan'?'Credit':'Payment')." buyer#$buyer_id: $amount FRW".($pm_label?" via $pm_label":''));
        $pdo->commit();
        $bs=$pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' THEN amount ELSE -amount END),0) FROM buyer_loans WHERE buyer_id=?");
        $bs->execute([$buyer_id]); $new_bal=(float)$bs->fetchColumn();
        $rs=$pdo->prepare("SELECT bl.*,b.name AS buyer_name,u.username AS created_by_name FROM buyer_loans bl JOIN buyers b ON bl.buyer_id=b.id LEFT JOIN users u ON bl.created_by=u.id WHERE bl.id=?");
        $rs->execute([$newId]); $row=$rs->fetch(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true,'message'=>($type==='loan'?'Credit':'Payment').' of '.number_format($amount,2).' FRW recorded.','row'=>$row,'new_balance'=>$new_bal,'buyer_id'=>$buyer_id]);
    } catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
    exit;
}

/* ══════════════════════════════════════════════════════════════
   Page data
══════════════════════════════════════════════════════════════ */
$page_title = 'Loan Payable';


$buyers    = $pdo->query("SELECT * FROM buyers    ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
$company_accounts = $pdo->query("SELECT id,account_type,account_name,balance FROM company_accounts WHERE is_active=1 ORDER BY account_type,account_name")->fetchAll(PDO::FETCH_ASSOC);

/* ── Buyer credit balances ────────────────────────────────────── */
$buyer_bal_raw = $pdo->query("
    SELECT b.id, b.name,
           COALESCE(SUM(CASE WHEN bl.type='loan'      THEN bl.amount ELSE 0 END),0) AS total_loaned,
           COALESCE(SUM(CASE WHEN bl.type='repayment' THEN bl.amount ELSE 0 END),0) AS total_repaid,
           COALESCE(SUM(CASE WHEN bl.type='loan'      THEN bl.amount ELSE -bl.amount END),0) AS balance,
           COUNT(bl.id) AS entries
    FROM buyers b
    LEFT JOIN buyer_loans bl ON bl.buyer_id=b.id
    GROUP BY b.id, b.name ORDER BY balance DESC, b.name
")->fetchAll(PDO::FETCH_ASSOC);
$buyer_bal_map=[];
foreach($buyer_bal_raw as $r) $buyer_bal_map[(int)$r['id']]=$r;

/* ── Supplier deferred balances ──────────────────────────────── */
$sup_def_raw = $pdo->query("
    SELECT s.id, s.name,
           COALESCE(SUM(CASE WHEN sl.type='loan'      AND sl.is_deferred=1 THEN sl.amount ELSE 0 END),0) AS total_deferred,
           COALESCE(SUM(CASE WHEN sl.type='repayment' AND sl.is_deferred=1 THEN sl.amount ELSE 0 END),0) AS total_paid,
           COALESCE(SUM(CASE WHEN sl.type='loan'      AND sl.is_deferred=1 THEN  sl.amount
                             WHEN sl.type='repayment' AND sl.is_deferred=1 THEN -sl.amount
                             ELSE 0 END),0) AS balance,
           COUNT(CASE WHEN sl.is_deferred=1 THEN 1 END) AS entries
    FROM suppliers s
    LEFT JOIN supplier_loans sl ON sl.supplier_id=s.id
    GROUP BY s.id, s.name ORDER BY balance DESC, s.name
")->fetchAll(PDO::FETCH_ASSOC);
$sup_def_map=[];
foreach($sup_def_raw as $r) $sup_def_map[(int)$r['id']]=$r;

/* ── Global stats ─────────────────────────────────────────────── */
$stat_buyer  = array_sum(array_column(array_filter($buyer_bal_raw, fn($r)=>$r['balance']>0),'balance'));
$stat_def    = array_sum(array_column(array_filter($sup_def_raw,   fn($r)=>$r['balance']>0),'balance'));
$cnt_buyers  = count(array_filter($buyer_bal_raw, fn($r)=>$r['balance']>0));
$cnt_sup_def = count(array_filter($sup_def_raw,   fn($r)=>$r['balance']>0));

/* ── Detail routing ──────────────────────────────────────────── */
$view_buyer_id    = intval($_GET['buyer_id']    ?? 0);
$view_supplier_id = intval($_GET['supplier_id'] ?? 0);

$view_buyer   = $view_buyer_id    ? ($buyer_bal_map[$view_buyer_id]  ?? null) : null;
$view_sup_def = $view_supplier_id ? ($sup_def_map[$view_supplier_id] ?? null) : null;

if($view_buyer_id && !$view_buyer){
    $q=$pdo->prepare("SELECT id,name FROM buyers WHERE id=?"); $q->execute([$view_buyer_id]);
    if($r=$q->fetch()) $view_buyer=['id'=>$r['id'],'name'=>$r['name'],'total_loaned'=>0,'total_repaid'=>0,'balance'=>0,'entries'=>0];
}
if($view_supplier_id && !$view_sup_def){
    $q=$pdo->prepare("SELECT id,name FROM suppliers WHERE id=?"); $q->execute([$view_supplier_id]);
    if($r=$q->fetch()) $view_sup_def=['id'=>$r['id'],'name'=>$r['name'],'total_deferred'=>0,'total_paid'=>0,'balance'=>0,'entries'=>0];
}

/* ── History for detail views ────────────────────────────────── */
$loans=[]; $total=0; $total_pages=1; $page=1; $offset=0; $per_page=25;
$date_from=$_GET['date_from']??''; $date_to=$_GET['date_to']??'';

if($view_buyer_id && $view_buyer){
    $page=max(1,intval($_GET['page']??1));
    $wh=['bl.buyer_id=?']; $pa=[$view_buyer_id];
    if($date_from){$wh[]='DATE(bl.created_at)>=?';$pa[]=$date_from;}
    if($date_to)  {$wh[]='DATE(bl.created_at)<=?';$pa[]=$date_to;}
    $wsql='WHERE '.implode(' AND ',$wh);
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM buyer_loans bl $wsql"); $cnt->execute($pa);
    $total=(int)$cnt->fetchColumn(); $total_pages=max(1,(int)ceil($total/$per_page));
    $page=min($page,$total_pages); $offset=($page-1)*$per_page;
    $s=$pdo->prepare("SELECT bl.*,u.username AS created_by_name FROM buyer_loans bl LEFT JOIN users u ON bl.created_by=u.id $wsql ORDER BY bl.created_at DESC LIMIT $per_page OFFSET $offset");
    $s->execute($pa); $loans=$s->fetchAll();
} elseif($view_supplier_id && $view_sup_def){
    $page=max(1,intval($_GET['page']??1));
    $wh=["sl.supplier_id=?","sl.is_deferred=1"]; $pa=[$view_supplier_id];
    if($date_from){$wh[]='DATE(sl.created_at)>=?';$pa[]=$date_from;}
    if($date_to)  {$wh[]='DATE(sl.created_at)<=?';$pa[]=$date_to;}
    $wsql='WHERE '.implode(' AND ',$wh);
    $cnt=$pdo->prepare("SELECT COUNT(*) FROM supplier_loans sl $wsql"); $cnt->execute($pa);
    $total=(int)$cnt->fetchColumn(); $total_pages=max(1,(int)ceil($total/$per_page));
    $page=min($page,$total_pages); $offset=($page-1)*$per_page;
    $s=$pdo->prepare("SELECT sl.*,b.batch_id AS batch_code,u.username AS created_by_name FROM supplier_loans sl LEFT JOIN batches b ON sl.batch_id=b.id LEFT JOIN users u ON sl.created_by=u.id $wsql ORDER BY sl.created_at DESC LIMIT $per_page OFFSET $offset");
    $s->execute($pa); $loans=$s->fetchAll();
}

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<?php
/* ════ BUYER DETAIL ════════════════════════════════════════════ */
if($view_buyer_id && $view_buyer):
    $bal=(float)$view_buyer['balance']; $col=$bal>0?'#dc2626':'#16a34a';
?>
<div class="page-header">
    <div style="display:flex;align-items:center;gap:.75rem">
        <a href="loans-payable.php" class="btn btn-secondary" style="padding:.4rem .75rem"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h2 style="margin:0"><i class="fas fa-user" style="margin-right:.4rem;color:var(--text-muted)"></i><?= htmlspecialchars($view_buyer['name']) ?></h2>
            <div style="font-size:.8rem;color:var(--text-muted);margin-top:.1rem">Buyer Credit History</div>
        </div>
    </div>
    <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Record Credit / Payment</button>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem">
    <div class="stat-card">
        <div class="stat-icon si-red"><i class="fas fa-file-invoice-dollar"></i></div>
        <div class="stat-info"><div class="stat-label">Total Credit Given</div>
            <div class="stat-value"><?= number_format($view_buyer['total_loaned'],0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub">Credit extended to buyer</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-green"><i class="fas fa-money-bill-wave"></i></div>
        <div class="stat-info"><div class="stat-label">Payments Received</div>
            <div class="stat-value"><?= number_format($view_buyer['total_repaid'],0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub">Collected from buyer</div></div>
    </div>
    <div class="stat-card" style="border-left:4px solid <?= $col ?>">
        <div class="stat-icon" style="background:<?= $bal>0?'#fef2f2':'#f0fdf4' ?>;color:<?= $col ?>"><i class="fas fa-scale-balanced"></i></div>
        <div class="stat-info"><div class="stat-label">Outstanding Balance</div>
            <div class="stat-value" style="color:<?= $col ?>"><?= number_format(abs($bal),0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub" style="color:<?= $col ?>"><?= $bal>0?'Buyer owes us':($bal<0?'Overpaid':'Settled') ?></div></div>
    </div>
</div>

<form method="GET" action="loans-payable.php" class="filter-bar" style="margin-bottom:1rem">
    <input type="hidden" name="buyer_id" value="<?= $view_buyer_id ?>">
    <div class="filter-group"><label>From</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
    <div class="filter-group"><label>To</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-filter"></i> Filter</button>
        <?php if($date_from||$date_to): ?><a href="loans-payable.php?buyer_id=<?= $view_buyer_id ?>" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-xmark"></i> Clear</a><?php endif; ?>
    </div>
</form>

<div class="table-wrap">
    <table>
        <thead><tr><th>#</th><th>Date</th><th>Type</th><th style="text-align:right">Amount (FRW)</th><th>Notes</th><th>By</th><th></th></tr></thead>
        <tbody id="loans-tbody">
        <?php $i=0; foreach($loans as $ln): ?>
        <tr id="loan-row-<?= $ln['id'] ?>">
            <td class="text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="text-muted" style="font-size:.82rem;white-space:nowrap"><?= date('d M Y H:i',strtotime($ln['created_at'])) ?></td>
            <td><?= $ln['type']==='loan'
                ? '<span class="badge badge-danger"><i class="fas fa-arrow-up" style="font-size:.65rem"></i> Credit</span>'
                : (($ln['is_advance']??0)
                   ? '<span class="badge badge-primary"><i class="fas fa-hand-holding-dollar" style="font-size:.65rem"></i> Advance</span>'
                   : '<span class="badge badge-success"><i class="fas fa-arrow-down" style="font-size:.65rem"></i> Payment</span>') ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $ln['type']==='loan'?'#dc2626':'#16a34a' ?>">
                <?= ($ln['type']==='loan'?'+':'−').number_format($ln['amount'],2) ?></td>
            <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($ln['notes']??'') ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($ln['created_by_name']??'') ?></td>
            <td><button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteLoan(<?= $ln['id'] ?>,this)"><i class="fas fa-trash"></i></button></td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$loans): ?><tr id="empty-row"><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No records found.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if($total_pages>1): ?><?= paginate($page,$total_pages,['buyer_id'=>$view_buyer_id,'date_from'=>$date_from,'date_to'=>$date_to],'loans-payable.php') ?><?php endif; ?>
</div>

<?php
/* ════ SUPPLIER DEFERRED DETAIL ════════════════════════════════ */
elseif($view_supplier_id && $view_sup_def):
    $def=(float)$view_sup_def['balance'];
    $def_col=$def>0?'#ea580c':'#16a34a';
?>
<div class="page-header">
    <div style="display:flex;align-items:center;gap:.75rem">
        <a href="loans-payable.php" class="btn btn-secondary" style="padding:.4rem .75rem"><i class="fas fa-arrow-left"></i></a>
        <div>
            <h2 style="margin:0"><i class="fas fa-industry" style="margin-right:.4rem;color:var(--text-muted)"></i><?= htmlspecialchars($view_sup_def['name']) ?></h2>
            <div style="font-size:.8rem;color:var(--text-muted);margin-top:.1rem">Deferred Mineral Payments — We Owe This Supplier</div>
        </div>
    </div>
    <div style="display:flex;gap:.5rem">
        <?php if($def>0): ?>
        <button class="btn btn-success" onclick="openPayModal()"><i class="fas fa-money-bill-wave"></i> Pay Supplier</button>
        <?php endif; ?>
        <a href="loans-receivable.php?supplier_id=<?= $view_supplier_id ?>" class="btn btn-secondary">
            <i class="fas fa-arrow-right-arrow-left"></i> View Advances
        </a>
    </div>
</div>

<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem">
    <div class="stat-card">
        <div class="stat-icon" style="background:#fff7ed;color:#ea580c"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-info"><div class="stat-label">Total Deferred</div>
            <div class="stat-value" style="color:#ea580c"><?= number_format($view_sup_def['total_deferred'],0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub">Minerals received, not yet fully paid</div></div>
    </div>
    <div class="stat-card">
        <div class="stat-icon si-green"><i class="fas fa-money-bill-wave"></i></div>
        <div class="stat-info"><div class="stat-label">Total Paid</div>
            <div class="stat-value" style="color:#16a34a"><?= number_format($view_sup_def['total_paid'],0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub">Payments made to supplier</div></div>
    </div>
    <div class="stat-card" style="border-left:4px solid <?= $def_col ?>">
        <div class="stat-icon" style="background:<?= $def>0?'#fff7ed':'#f0fdf4' ?>;color:<?= $def_col ?>"><i class="fas fa-scale-balanced"></i></div>
        <div class="stat-info"><div class="stat-label">Outstanding Balance</div>
            <div class="stat-value" style="color:<?= $def_col ?>"><?= number_format($def,0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub" style="color:<?= $def_col ?>"><?= $def>0?'We still owe this supplier':'Fully settled' ?></div></div>
    </div>
</div>

<form method="GET" action="loans-payable.php" class="filter-bar" style="margin-bottom:1rem">
    <input type="hidden" name="supplier_id" value="<?= $view_supplier_id ?>">
    <div class="filter-group"><label>From</label><input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>"></div>
    <div class="filter-group"><label>To</label><input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>"></div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-filter"></i> Filter</button>
        <?php if($date_from||$date_to): ?><a href="loans-payable.php?supplier_id=<?= $view_supplier_id ?>" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-xmark"></i> Clear</a><?php endif; ?>
    </div>
</form>

<div class="table-wrap">
    <table>
        <thead><tr><th>#</th><th>Date</th><th>Type</th><th style="text-align:right">Amount (FRW)</th><th>Linked Batch</th><th>Notes</th><th>By</th><th></th></tr></thead>
        <tbody id="loans-tbody">
        <?php $i=0; foreach($loans as $ln): $isLoan=$ln['type']==='loan'; ?>
        <tr id="loan-row-<?= $ln['id'] ?>">
            <td class="text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="text-muted" style="font-size:.82rem;white-space:nowrap"><?= date('d M Y H:i',strtotime($ln['created_at'])) ?></td>
            <td><?= $isLoan
                ? '<span class="badge badge-danger"><i class="fas fa-arrow-up" style="font-size:.65rem"></i> Deferred</span>'
                : '<span class="badge badge-success"><i class="fas fa-arrow-down" style="font-size:.65rem"></i> Payment</span>' ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $isLoan?'#ea580c':'#16a34a' ?>">
                <?= ($isLoan?'+':'−').number_format($ln['amount'],2) ?></td>
            <td class="font-mono" style="font-size:.78rem">
                <?php if(!empty($ln['batch_id'])&&!empty($ln['batch_code'])): ?>
                <a href="batches.php?bid=<?= (int)$ln['batch_id'] ?>" style="color:var(--primary);text-decoration:none;font-weight:600">
                    <i class="fas fa-arrow-up-right-from-square" style="font-size:.65rem"></i> <?= htmlspecialchars($ln['batch_code']) ?>
                </a>
                <?php else: ?><span class="text-muted">—</span><?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($ln['notes']??'') ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($ln['created_by_name']??'') ?></td>
            <td><button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteSupplierDeferred(<?= $ln['id'] ?>,this)"><i class="fas fa-trash"></i></button></td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$loans): ?><tr id="empty-row"><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">No records found.</td></tr><?php endif; ?>
        </tbody>
    </table>
    <?php if($total_pages>1): ?><?= paginate($page,$total_pages,['supplier_id'=>$view_supplier_id,'date_from'=>$date_from,'date_to'=>$date_to],'loans-payable.php') ?><?php endif; ?>
</div>

<?php
/* ════ LIST VIEW ═══════════════════════════════════════════════ */
else:
?>
<div class="page-header">
    <h2><i class="fas fa-arrow-up" style="margin-right:.4rem;color:var(--text-muted)"></i>Loan Payable</h2>
    <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Record Buyer Credit</button>
</div>

<?php $stat_net = $stat_buyer - $stat_def; $net_col = $stat_net>=0?'#2563eb':'#dc2626'; ?>
<div class="stats-grid" style="grid-template-columns:repeat(3,1fr);margin-bottom:1.25rem">
    <div class="stat-card" style="border-left:4px solid #dc2626">
        <div class="stat-icon si-red"><i class="fas fa-user-clock"></i></div>
        <div class="stat-info"><div class="stat-label">Buyers Owe Us</div>
            <div class="stat-value" style="color:#dc2626"><?= number_format($stat_buyer,0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub"><?= $cnt_buyers ?> buyer<?= $cnt_buyers!=1?'s':'' ?> with outstanding credit</div></div>
    </div>
    <div class="stat-card" style="border-left:4px solid #f97316">
        <div class="stat-icon" style="background:#fff7ed;color:#ea580c"><i class="fas fa-hourglass-half"></i></div>
        <div class="stat-info"><div class="stat-label">We Owe Suppliers</div>
            <div class="stat-value" style="color:#ea580c"><?= number_format($stat_def,0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub"><?= $cnt_sup_def ?> supplier<?= $cnt_sup_def!=1?'s':'' ?> with deferred payments</div></div>
    </div>
    <div class="stat-card" style="border-left:4px solid <?= $net_col ?>">
        <div class="stat-icon" style="background:<?= $stat_net>=0?'#eff6ff':'#fef2f2' ?>;color:<?= $net_col ?>"><i class="fas fa-scale-balanced"></i></div>
        <div class="stat-info"><div class="stat-label">Net Position</div>
            <div class="stat-value" style="color:<?= $net_col ?>"><?= number_format(abs($stat_net),0) ?> <small style="font-size:.6rem;color:var(--text-muted)">FRW</small></div>
            <div class="stat-sub" style="color:<?= $net_col ?>"><?= $stat_net>0?'Net receivable':($stat_net<0?'Net payable':'Balanced') ?></div></div>
    </div>
</div>

<!-- Tab bar + search -->
<div style="display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid var(--border);margin-bottom:1.25rem">
    <div style="display:flex;gap:0">
        <button id="tab-buyers" onclick="switchTab('buyers')"
                style="display:flex;align-items:center;gap:.45rem;padding:.65rem 1.25rem;font-size:.88rem;font-weight:600;border:none;border-bottom:2px solid var(--primary);margin-bottom:-2px;background:none;cursor:pointer;color:var(--primary)">
            <i class="fas fa-user"></i> Buyers
            <span style="background:#fee2e2;color:#dc2626;font-size:.7rem;font-weight:700;padding:.1rem .45rem;border-radius:999px"><?= count(array_filter($buyer_bal_raw,fn($r)=>(int)$r['entries']>0)) ?></span>
        </button>
        <button id="tab-suppliers" onclick="switchTab('suppliers')"
                style="display:flex;align-items:center;gap:.45rem;padding:.65rem 1.25rem;font-size:.88rem;font-weight:600;border:none;border-bottom:2px solid transparent;margin-bottom:-2px;background:none;cursor:pointer;color:var(--text-muted)">
            <i class="fas fa-industry"></i> Suppliers
            <span style="background:#ffedd5;color:#ea580c;font-size:.7rem;font-weight:700;padding:.1rem .45rem;border-radius:999px"><?= count($def_list ?? array_filter($sup_def_raw,fn($r)=>(float)$r['balance']>0)) ?></span>
        </button>
    </div>
    <div style="position:relative;margin-bottom:.125rem">
        <i class="fas fa-search" style="position:absolute;left:.65rem;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.78rem;pointer-events:none"></i>
        <input type="text" id="list-search" placeholder="Search…"
               style="padding:.4rem .75rem .4rem 2rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.83rem;width:200px"
               oninput="filterLists(this.value)">
    </div>
</div>

<!-- Buyers panel -->
<?php $buyer_list=array_filter($buyer_bal_raw,fn($r)=>(int)$r['entries']>0); ?>
<div id="panel-buyers">
    <?php if($buyer_list): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Buyer</th><th style="text-align:right">Total Credit</th><th style="text-align:right">Total Paid</th><th style="text-align:right">Outstanding</th><th style="text-align:center">Records</th><th style="text-align:center">Status</th><th></th></tr></thead>
            <tbody id="buyer-list-tbody">
            <?php foreach($buyer_list as $b):
                $bal=$b['balance']; $col=$bal>0?'#dc2626':($bal<0?'#2563eb':'#16a34a');
                $lbl=$bal>0?'Owes Us':($bal<0?'Overpaid':'Settled');
                $bdg=$bal>0?'badge-danger':($bal<0?'badge-primary':'badge-success');
            ?>
            <tr data-name="<?= htmlspecialchars(strtolower($b['name'])) ?>" style="cursor:pointer" onclick="location.href='loans-payable.php?buyer_id=<?= $b['id'] ?>'"
                onmouseover="this.style.background='var(--border)22'" onmouseout="this.style.background=''">
                <td><strong><?= htmlspecialchars($b['name']) ?></strong></td>
                <td style="text-align:right;font-family:monospace;color:#dc2626;font-weight:600"><?= number_format($b['total_loaned'],2) ?></td>
                <td style="text-align:right;font-family:monospace;color:#16a34a;font-weight:600"><?= number_format($b['total_repaid'],2) ?></td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $col ?>"><?= number_format(abs($bal),2) ?> <span style="font-size:.7rem;font-weight:500">FRW</span></td>
                <td style="text-align:center"><span class="badge" style="background:var(--border);color:var(--text-muted)"><?= (int)$b['entries'] ?></span></td>
                <td style="text-align:center"><span class="badge <?= $bdg ?>"><?= $lbl ?></span></td>
                <td onclick="event.stopPropagation()"><a href="loans-payable.php?buyer_id=<?= $b['id'] ?>" class="btn btn-secondary" style="padding:.3rem .65rem;font-size:.78rem"><i class="fas fa-eye"></i> View</a></td>
            </tr>
            <?php endforeach; ?>
            <tr class="list-empty" style="display:none"><td colspan="7" style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem">No buyers match your search.</td></tr>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="border:1px dashed var(--border);border-radius:8px;padding:1.5rem;text-align:center;color:var(--text-muted);font-size:.85rem">
        No buyer credit records yet. Use "Record Buyer Credit" to add one.
    </div>
    <?php endif; ?>
</div>

<!-- Suppliers panel -->
<?php $def_list=array_filter($sup_def_raw,fn($r)=>(float)$r['balance']>0); ?>
<div id="panel-suppliers" style="display:none">
    <?php if($def_list): ?>
    <div class="table-wrap">
        <table>
            <thead><tr><th>Supplier</th><th style="text-align:right">Deferred Amount</th><th style="text-align:center">Records</th><th></th></tr></thead>
            <tbody id="supplier-list-tbody">
            <?php foreach($def_list as $s): ?>
            <tr data-name="<?= htmlspecialchars(strtolower($s['name'])) ?>" style="cursor:pointer" onclick="location.href='loans-payable.php?supplier_id=<?= $s['id'] ?>'"
                onmouseover="this.style.background='var(--border)22'" onmouseout="this.style.background=''">
                <td><strong><?= htmlspecialchars($s['name']) ?></strong></td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#ea580c"><?= number_format($s['balance'],2) ?> <span style="font-size:.7rem;font-weight:500">FRW</span></td>
                <td style="text-align:center"><span class="badge" style="background:var(--border);color:var(--text-muted)"><?= (int)$s['entries'] ?></span></td>
                <td onclick="event.stopPropagation()"><a href="loans-payable.php?supplier_id=<?= $s['id'] ?>" class="btn btn-secondary" style="padding:.3rem .65rem;font-size:.78rem"><i class="fas fa-eye"></i> View</a></td>
            </tr>
            <?php endforeach; ?>
            <tr class="list-empty" style="display:none"><td colspan="4" style="text-align:center;padding:1.5rem;color:var(--text-muted);font-size:.85rem">No suppliers match your search.</td></tr>
            </tbody>
        </table>
    </div>
    <?php else: ?>
    <div style="border:1px dashed var(--border);border-radius:8px;padding:1.5rem;text-align:center;color:var(--text-muted);font-size:.85rem">
        No deferred payments. These are auto-created when a purchase is recorded with partial payment.
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<!-- ── Buyer Credit Modal ─────────────────────────────────────── -->
<div class="modal-backdrop" id="loan-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <h3><i class="fas fa-user" style="margin-right:.4rem;color:var(--primary)"></i>Record Buyer Credit / Payment</h3>
            <button class="modal-close" onclick="closeModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="loan-form">
                <div class="form-grid form-grid-2">
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Buyer</label>
                        <select name="buyer_id" id="m-buyer" required onchange="updateBalance(this.value)" <?= $view_buyer_id?'disabled':'' ?>>
                            <option value="">— Select buyer —</option>
                            <?php foreach($buyers as $b): ?><option value="<?= $b['id'] ?>" <?= $view_buyer_id==$b['id']?'selected':'' ?>><?= htmlspecialchars($b['name']) ?></option><?php endforeach; ?>
                        </select>
                        <?php if($view_buyer_id): ?><input type="hidden" name="buyer_id" value="<?= $view_buyer_id ?>"><?php endif; ?>
                        <div id="m-balance" style="margin-top:.4rem;font-size:.82rem;display:none"></div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Type</label>
                        <select name="type" id="m-type" required onchange="onBuyerTypeChange()">
                            <option value="">— Choose —</option>
                            <option value="loan">Credit Given (Buyer Owes Us)</option>
                            <option value="repayment">Payment / Advance Received from Buyer</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1" id="m-amount-group">
                        <label>Amount (FRW)</label>
                        <input type="text" name="amount" id="m-amount" placeholder="0.00" oninput="checkLimit()">
                        <div id="m-warning" style="display:none;margin-top:.35rem;font-size:.8rem;color:#dc2626">
                            <i class="fas fa-triangle-exclamation"></i> <span id="m-warning-text"></span>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1;display:none" id="m-pay-rows-group">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
                            <label style="margin:0">Payment Breakdown</label>
                            <button type="button" class="btn btn-secondary" style="padding:.25rem .6rem;font-size:.78rem" onclick="addBuyerPayRow()"><i class="fas fa-plus"></i> Add Row</button>
                        </div>
                        <div id="buyer-pay-rows" style="display:flex;flex-direction:column;gap:.5rem"></div>
                        <div style="margin-top:.45rem;padding:.4rem .75rem;background:var(--bg);border-radius:6px;font-size:.85rem;display:flex;justify-content:space-between">
                            <span style="color:var(--text-muted)">Total:</span>
                            <strong id="buyer-pay-total-val" style="font-family:monospace;color:var(--text)">0.00 FRW</strong>
                        </div>
                        <div id="m-warning-pay" style="display:none;margin-top:.35rem;font-size:.8rem;color:#dc2626">
                            <i class="fas fa-triangle-exclamation"></i> <span id="m-warning-pay-text"></span>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Optional…" style="min-height:60px"></textarea>
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

<!-- ── Supplier Pay Modal ─────────────────────────────────────── -->
<div class="modal-backdrop" id="pay-modal" onclick="if(event.target===this)closePayModal()">
    <div class="modal" style="max-width:420px">
        <div class="modal-header">
            <h3><i class="fas fa-money-bill-wave" style="margin-right:.4rem;color:#16a34a"></i>Pay Supplier</h3>
            <button class="modal-close" onclick="closePayModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="pay-form">
                <input type="hidden" name="supplier_id" value="<?= $view_supplier_id ?>">
                <input type="hidden" name="pay_supplier" value="1">
                <div class="form-grid form-grid-2">
                    <div class="form-group" style="grid-column:1/-1">
                        <div style="padding:.6rem .75rem;background:var(--bg);border-radius:6px;font-size:.85rem;color:var(--text-muted)">
                            Outstanding: <strong id="pay-balance-val" style="color:#ea580c"><?= number_format($view_supplier_id && $view_sup_def ? (float)($view_sup_def['balance']??0) : 0, 2) ?></strong> FRW
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.5rem">
                            <label style="margin:0">Payment Breakdown</label>
                            <button type="button" class="btn btn-secondary" style="padding:.25rem .6rem;font-size:.78rem" onclick="addPayRow()"><i class="fas fa-plus"></i> Add Row</button>
                        </div>
                        <div id="pay-rows" style="display:flex;flex-direction:column;gap:.5rem"></div>
                        <div style="margin-top:.45rem;padding:.4rem .75rem;background:var(--bg);border-radius:6px;font-size:.85rem;display:flex;justify-content:space-between">
                            <span style="color:var(--text-muted)">Total:</span>
                            <strong id="pay-total-val" style="font-family:monospace;color:var(--text)">0.00 FRW</strong>
                        </div>
                        <div id="pay-warning" style="display:none;margin-top:.35rem;font-size:.8rem;color:#dc2626">
                            <i class="fas fa-triangle-exclamation"></i> <span id="pay-warning-text"></span>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Notes</label>
                        <textarea name="notes" placeholder="Optional…" style="min-height:52px"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closePayModal()">Cancel</button>
            <button type="submit" form="pay-form" id="pay-save-btn" class="btn btn-success"><i class="fas fa-money-bill-wave"></i> Pay</button>
        </div>
    </div>
</div>

<script>
const buyerBalances   = <?= json_encode(array_column($buyer_bal_raw,'balance','id')) ?>;
const viewBuyerId     = <?= $view_buyer_id ?: 'null' ?>;
const companyAccounts = <?= json_encode(array_values($company_accounts)) ?>;
let currentDeferredBal = <?= ($view_supplier_id && $view_sup_def) ? (float)($view_sup_def['balance']??0) : 0 ?>;

/* ── Modals ── */
function openModal(){ document.getElementById('loan-modal').classList.add('open'); document.body.style.overflow='hidden'; if(viewBuyerId) updateBalance(viewBuyerId); }
function closeModal(){ document.getElementById('loan-modal').classList.remove('open'); document.body.style.overflow=''; }

let payRowN=0;
function openPayModal(){
    const cont=document.getElementById('pay-rows');
    if(cont){ cont.innerHTML=''; payRowN=0; addPayRow(); }
    const w=document.getElementById('pay-warning'); if(w) w.style.display='none';
    document.getElementById('pay-modal').classList.add('open'); document.body.style.overflow='hidden';
}
function closePayModal(){ document.getElementById('pay-modal').classList.remove('open'); document.body.style.overflow=''; }
document.addEventListener('keydown',e=>{ if(e.key==='Escape'){ closeModal(); closePayModal(); } });

/* ── Supplier pay-row helpers ── */
function buildAcctOpts(method){
    const filtered=companyAccounts.filter(a=>a.account_type===method);
    return filtered.length
        ?'<option value="">Select…</option>'+filtered.map(a=>`<option value="${a.id}">${a.account_name} (${parseFloat(a.balance).toLocaleString('en-US',{minimumFractionDigits:2})})</option>`).join('')
        :'<option value="">No '+method+' accounts</option>';
}
function addPayRow(){
    const n=++payRowN;
    const div=document.createElement('div'); div.id='pay-row-'+n;
    div.style.cssText='display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.4rem;align-items:end';
    div.innerHTML=
        '<select name="payments['+n+'][method]" onchange="onPayRowMethod('+n+')" style="padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem">'+
        '<option value="">Method</option><option value="cash">Cash</option><option value="bank">Bank</option><option value="momo">MoMo</option></select>'+
        '<select name="payments['+n+'][account_id]" id="pay-row-acct-'+n+'" style="padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem">'+
        '<option value="">Account</option></select>'+
        '<input name="payments['+n+'][amount]" type="text" placeholder="0.00" oninput="recalcPayTotal()" style="padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;font-family:monospace">'+
        '<button type="button" onclick="removePayRow('+n+')" style="padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:#dc2626;cursor:pointer;line-height:1;display:'+(payRowN>1?'block':'none')+'"><i class="fas fa-trash" style="font-size:.75rem"></i></button>';
    document.getElementById('pay-rows').appendChild(div);
    recalcPayTotal();
}
function removePayRow(n){ document.getElementById('pay-row-'+n)?.remove(); recalcPayTotal(); }
function onPayRowMethod(n){
    const method=document.querySelector('#pay-rows [name="payments['+n+'][method]"]')?.value;
    const sel=document.getElementById('pay-row-acct-'+n); if(!sel||!method) return;
    sel.innerHTML=buildAcctOpts(method); recalcPayTotal();
}
function recalcPayTotal(){
    let total=0;
    document.querySelectorAll('#pay-rows input[type="text"]').forEach(inp=>{ total+=parseFloat(inp.value)||0; });
    const tv=document.getElementById('pay-total-val'); if(tv) tv.textContent=total.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW';
    const warn=document.getElementById('pay-warning'); const wt=document.getElementById('pay-warning-text'); if(!warn) return;
    if(currentDeferredBal<=0){wt.textContent='No outstanding deferred balance.';warn.style.display='';return;}
    if(total>currentDeferredBal){wt.textContent='Total ('+total.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW) exceeds outstanding balance of '+currentDeferredBal.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW.';warn.style.display='';}
    else{warn.style.display='none';}
}

/* ── Buyer balance / warning ── */
function updateBalance(id){
    const line=document.getElementById('m-balance');
    if(!id){line.style.display='none';return;}
    const bal=parseFloat(buyerBalances[id]||0);
    line.style.display=''; line.style.color=bal>0?'#dc2626':'#16a34a';
    line.innerHTML=bal>0
        ?'<i class="fas fa-circle-exclamation"></i> Outstanding: <strong>'+bal.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW</strong>'
        :'<i class="fas fa-circle-check"></i> '+(bal<0?'Overpaid: <strong>'+Math.abs(bal).toLocaleString('en-US',{minimumFractionDigits:2})+' FRW</strong>':'No outstanding credit');
    const type=document.getElementById('m-type')?.value;
    if(type==='repayment') recalcBuyerPayTotal(); else checkLimit();
}
function checkLimit(){
    const id=document.getElementById('m-buyer')?.value||(viewBuyerId||'');
    const type=document.getElementById('m-type').value;
    const amount=parseFloat(document.getElementById('m-amount')?.value||0)||0;
    const warn=document.getElementById('m-warning'); const wt=document.getElementById('m-warning-text');
    if(type!=='repayment'||!id){if(warn) warn.style.display='none';return;}
    const bal=parseFloat(buyerBalances[id]||0);
    if(bal<=0){wt.textContent='This buyer has no outstanding credit.';warn.style.display='';}
    else if(amount>bal){wt.textContent='Exceeds outstanding balance of '+bal.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW.';warn.style.display='';}
    else{warn.style.display='none';}
}

/* ── Buyer repayment pay-row helpers ── */
let buyerPayRowN=0;
function onBuyerTypeChange(){
    const type=document.getElementById('m-type').value;
    const ag=document.getElementById('m-amount-group'); if(ag) ag.style.display=type==='repayment'?'none':'';
    const pg=document.getElementById('m-pay-rows-group'); if(pg) pg.style.display=type==='repayment'?'':'none';
    if(type==='repayment' && !document.getElementById('buyer-pay-rows')?.children.length) addBuyerPayRow();
}
function addBuyerPayRow(){
    const n=++buyerPayRowN;
    const div=document.createElement('div'); div.id='buyer-pay-row-'+n;
    div.style.cssText='display:grid;grid-template-columns:1fr 1fr 1fr auto;gap:.4rem;align-items:end';
    div.innerHTML=
        '<select name="payments['+n+'][method]" onchange="onBuyerPayRowMethod('+n+')" style="padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem">'+
        '<option value="">Method</option><option value="cash">Cash</option><option value="bank">Bank</option><option value="momo">MoMo</option></select>'+
        '<select name="payments['+n+'][account_id]" id="buyer-pay-row-acct-'+n+'" style="padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem">'+
        '<option value="">Account</option></select>'+
        '<input name="payments['+n+'][amount]" type="text" placeholder="0.00" oninput="recalcBuyerPayTotal()" style="padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-size:.82rem;font-family:monospace">'+
        '<button type="button" onclick="removeBuyerPayRow('+n+')" style="padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:#dc2626;cursor:pointer;line-height:1;display:'+(buyerPayRowN>1?'block':'none')+'"><i class="fas fa-trash" style="font-size:.75rem"></i></button>';
    document.getElementById('buyer-pay-rows').appendChild(div);
    recalcBuyerPayTotal();
}
function removeBuyerPayRow(n){ document.getElementById('buyer-pay-row-'+n)?.remove(); recalcBuyerPayTotal(); }
function onBuyerPayRowMethod(n){
    const method=document.querySelector('#buyer-pay-rows [name="payments['+n+'][method]"]')?.value;
    const sel=document.getElementById('buyer-pay-row-acct-'+n); if(!sel||!method) return;
    sel.innerHTML=buildAcctOpts(method); recalcBuyerPayTotal();
}
function recalcBuyerPayTotal(){
    const id=document.getElementById('m-buyer')?.value||(viewBuyerId||'');
    let total=0;
    document.querySelectorAll('#buyer-pay-rows input[type="text"]').forEach(inp=>{ total+=parseFloat(inp.value)||0; });
    const tv=document.getElementById('buyer-pay-total-val'); if(tv) tv.textContent=total.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW';
    const warn=document.getElementById('m-warning-pay'); const wt=document.getElementById('m-warning-pay-text'); if(!warn) return;
    const bal=parseFloat(buyerBalances[id]||0);
    // bal<=0 means buyer has no debt — payment is recorded as an advance (credit balance); allowed
    if(bal>0 && total>bal){wt.textContent='Total ('+total.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW) exceeds outstanding balance of '+bal.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW.';warn.style.display='';}
    else{warn.style.display='none';}
}

/* ── Utilities ── */
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function showAlert(type,msg){
    const el=document.getElementById('page-alert');
    el.className='alert alert-'+type+' mb-15';
    el.innerHTML='<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display='flex'; clearTimeout(el._t); el._t=setTimeout(()=>{el.style.display='none'},5000);
}

/* ── Buyer credit form submit ── */
document.getElementById('loan-form').addEventListener('submit',function(e){
    e.preventDefault();
    const type=document.getElementById('m-type').value;
    if(!type){showAlert('error','Please select a type.');return;}
    if(type==='loan'){
        const amt=parseFloat(document.getElementById('m-amount')?.value||0);
        if(!amt||amt<=0){showAlert('error','Please enter an amount.');return;}
        const warn=document.getElementById('m-warning');
        if(warn&&warn.style.display!=='none'){showAlert('error',document.getElementById('m-warning-text')?.textContent||'Validation error.');return;}
    } else {
        const warn=document.getElementById('m-warning-pay');
        if(warn&&warn.style.display!=='none'){showAlert('error',document.getElementById('m-warning-pay-text')?.textContent||'Validation error.');return;}
        let hasAmt=false;
        document.querySelectorAll('#buyer-pay-rows input[type="text"]').forEach(inp=>{ if(parseFloat(inp.value)>0) hasAmt=true; });
        if(!hasAmt){showAlert('error','Please add at least one payment row with an amount.');return;}
    }
    const btn=document.getElementById('m-save-btn'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
    fetch('loans-payable.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new FormData(this)})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showAlert('success',d.message);
            buyerBalances[d.buyer_id]=d.new_balance;
            if(viewBuyerId) prependRow(d.row);
            closeModal(); this.reset();
            const bpr=document.getElementById('buyer-pay-rows'); if(bpr) bpr.innerHTML=''; buyerPayRowN=0;
            const ag=document.getElementById('m-amount-group'); if(ag) ag.style.display='';
            const pg=document.getElementById('m-pay-rows-group'); if(pg) pg.style.display='none';
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
    tr.innerHTML='<td class="text-muted" style="font-size:.78rem">'+esc(r.id)+'</td>'+
        '<td class="text-muted" style="font-size:.82rem;white-space:nowrap">'+esc(d)+'</td>'+
        '<td>'+(isLoan?'<span class="badge badge-danger"><i class="fas fa-arrow-up" style="font-size:.65rem"></i> Credit</span>':'<span class="badge badge-success"><i class="fas fa-arrow-down" style="font-size:.65rem"></i> Payment</span>')+'</td>'+
        '<td style="text-align:right;font-family:monospace;font-weight:700;color:'+(isLoan?'#dc2626':'#16a34a')+'">'+(isLoan?'+':'−')+parseFloat(r.amount).toLocaleString('en-US',{minimumFractionDigits:2})+'</td>'+
        '<td class="text-muted" style="font-size:.82rem">'+esc(r.notes||'')+'</td>'+
        '<td class="text-muted" style="font-size:.78rem">'+esc(r.created_by_name||'')+'</td>'+
        '<td><button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteLoan('+r.id+',this)"><i class="fas fa-trash"></i></button></td>';
    tbody.insertBefore(tr,tbody.firstChild);
}

function deleteLoan(id,btn){
    if(!confirm('Delete this record? This cannot be undone.')) return;
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    const fd=new FormData(); fd.append('delete_buyer','1'); fd.append('id',id);
    fetch('loans-payable.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){ fadeRemove(id,'loans-tbody',7); buyerBalances[d.buyer_id]=d.new_balance; showAlert('success',d.message); }
        else{showAlert('error',d.message);btn.disabled=false;btn.innerHTML='<i class="fas fa-trash"></i>';}
    }).catch(()=>{showAlert('error','Network error.');btn.disabled=false;btn.innerHTML='<i class="fas fa-trash"></i>';});
}

function deleteSupplierDeferred(id,btn){
    if(!confirm('Delete this deferred payment record? This cannot be undone.')) return;
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    const fd=new FormData(); fd.append('delete_supplier','1'); fd.append('id',id);
    fetch('loans-payable.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){ fadeRemove(id,'loans-tbody',7); showAlert('success',d.message); }
        else{showAlert('error',d.message);btn.disabled=false;btn.innerHTML='<i class="fas fa-trash"></i>';}
    }).catch(()=>{showAlert('error','Network error.');btn.disabled=false;btn.innerHTML='<i class="fas fa-trash"></i>';});
}

let activeTab='buyers';
function switchTab(tab){
    activeTab=tab;
    document.getElementById('panel-buyers').style.display   = tab==='buyers'    ? '' : 'none';
    document.getElementById('panel-suppliers').style.display= tab==='suppliers' ? '' : 'none';
    const bBtn=document.getElementById('tab-buyers'), sBtn=document.getElementById('tab-suppliers');
    bBtn.style.borderBottom = tab==='buyers'    ? '2px solid var(--primary)' : '2px solid transparent';
    bBtn.style.color        = tab==='buyers'    ? 'var(--primary)' : 'var(--text-muted)';
    sBtn.style.borderBottom = tab==='suppliers' ? '2px solid var(--primary)' : '2px solid transparent';
    sBtn.style.color        = tab==='suppliers' ? 'var(--primary)' : 'var(--text-muted)';
    const s=document.getElementById('list-search'); if(s){s.value='';} filterLists('');
}
function filterLists(q){
    q=q.trim().toLowerCase();
    const tbodyId=activeTab==='buyers'?'buyer-list-tbody':'supplier-list-tbody';
    const tbody=document.getElementById(tbodyId); if(!tbody) return;
    let any=false;
    tbody.querySelectorAll('tr[data-name]').forEach(function(tr){
        const match=!q||tr.dataset.name.includes(q);
        tr.style.display=match?'':'none';
        if(match) any=true;
    });
    const empty=tbody.querySelector('.list-empty');
    if(empty) empty.style.display=(q&&!any)?'':'none';
}

/* ── Supplier pay form submit ── */
const payForm=document.getElementById('pay-form');
if(payForm) payForm.addEventListener('submit',function(e){
    e.preventDefault();
    const warn=document.getElementById('pay-warning');
    if(warn&&warn.style.display!=='none'){showAlert('error',document.getElementById('pay-warning-text')?.textContent||'Validation error.');return;}
    let hasAmt=false;
    document.querySelectorAll('#pay-rows input[type="text"]').forEach(inp=>{ if(parseFloat(inp.value)>0) hasAmt=true; });
    if(!hasAmt){showAlert('error','Please add at least one payment row with an amount.');return;}
    const btn=document.getElementById('pay-save-btn'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
    fetch('loans-payable.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new FormData(this)})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showAlert('success',d.message);
            currentDeferredBal=d.new_balance;
            document.getElementById('pay-balance-val').textContent=d.new_balance.toLocaleString('en-US',{minimumFractionDigits:2});
            prependSupRow(d.row);
            closePayModal();
            const cont=document.getElementById('pay-rows'); if(cont) cont.innerHTML=''; payRowN=0;
            setTimeout(()=>location.reload(),1000);
        } else showAlert('error',d.message);
        btn.disabled=false; btn.innerHTML='<i class="fas fa-money-bill-wave"></i> Pay';
    }).catch(()=>{showAlert('error','Network error.');btn.disabled=false;btn.innerHTML='<i class="fas fa-money-bill-wave"></i> Pay';});
});

function prependSupRow(r){
    const tbody=document.getElementById('loans-tbody'); if(!tbody) return;
    document.getElementById('empty-row')?.remove();
    const isLoan=r.type==='loan';
    const d=new Date(r.created_at.replace(' ','T')).toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'})+' '+r.created_at.slice(11,16);
    const tr=document.createElement('tr'); tr.id='loan-row-'+r.id;
    tr.innerHTML='<td class="text-muted" style="font-size:.78rem">'+esc(r.id)+'</td>'+
        '<td class="text-muted" style="font-size:.82rem;white-space:nowrap">'+esc(d)+'</td>'+
        '<td>'+(isLoan?'<span class="badge badge-danger"><i class="fas fa-arrow-up" style="font-size:.65rem"></i> Deferred</span>':'<span class="badge badge-success"><i class="fas fa-arrow-down" style="font-size:.65rem"></i> Payment</span>')+'</td>'+
        '<td style="text-align:right;font-family:monospace;font-weight:700;color:'+(isLoan?'#ea580c':'#16a34a')+'">'+(isLoan?'+':'−')+parseFloat(r.amount).toLocaleString('en-US',{minimumFractionDigits:2})+'</td>'+
        '<td class="text-muted" style="font-size:.82rem">—</td>'+
        '<td class="text-muted" style="font-size:.82rem">'+esc(r.notes||'')+'</td>'+
        '<td class="text-muted" style="font-size:.78rem">'+esc(r.created_by_name||'')+'</td>'+
        '<td><button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteSupplierDeferred('+r.id+',this)"><i class="fas fa-trash"></i></button></td>';
    tbody.insertBefore(tr,tbody.firstChild);
}

function fadeRemove(id,tbodyId,cols){
    const row=document.getElementById('loan-row-'+id);
    if(!row) return;
    row.style.transition='opacity .3s'; row.style.opacity='0';
    setTimeout(()=>{ row.remove();
        if(!document.querySelector('#'+tbodyId+' tr:not(#empty-row)'))
            document.getElementById(tbodyId).innerHTML='<tr id="empty-row"><td colspan="'+cols+'" style="text-align:center;padding:2rem;color:var(--text-muted)">No records found.</td></tr>';
    },300);
}
</script>

<?php include 'includes/footer.php'; ?>
