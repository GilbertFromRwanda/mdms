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
        $source  = $_POST['source'] ?? '';

        $CREDIT_SOURCES = ['boss','selling_minerals','buyer_loan','supplier_loan_repayment'];
        $DEBIT_SOURCES  = ['expense','purchase_minerals','supplier_loan'];

        if(!$comment) throw new Exception('Comment is required.');
        if($amount <= 0) throw new Exception('Amount must be greater than 0.');
        if(!$type) throw new Exception('Please choose Credit or Debit.');
        $allowedSources = $type==='credit' ? $CREDIT_SOURCES : $DEBIT_SOURCES;
        if(!in_array($source,$allowedSources,true)) throw new Exception('Please choose a valid source.');

        $source_ref_id = null; $source_details = null;

        $pdo->beginTransaction();

        if($source === 'expense'){
            $cat = trim($_POST['source_expense_category'] ?? '') ?: 'Other';
            $pdo->prepare("INSERT INTO expenses (expense_date,category,description,amount,payment_method,account_id,notes,created_by) VALUES (?,?,?,?,'cash',NULL,NULL,?)")
                ->execute([$date,$cat,$comment,$amount,$_SESSION['user_id']]);
            $source_ref_id  = (int)$pdo->lastInsertId();
            $source_details = json_encode(['category'=>$cat]);

        } elseif($source === 'supplier_loan'){
            $supplierId = intval($_POST['source_supplier_id'] ?? 0);
            if(!$supplierId) throw new Exception('Please select a supplier.');
            $sn = $pdo->prepare("SELECT name FROM suppliers WHERE id=?"); $sn->execute([$supplierId]);
            $supplierName = $sn->fetchColumn();
            if(!$supplierName) throw new Exception('Supplier not found.');
            $pdo->prepare("INSERT INTO supplier_loans (supplier_id,batch_id,type,amount,notes,is_deferred,created_by) VALUES (?,NULL,'loan',?,?,0,?)")
                ->execute([$supplierId,$amount,$comment,$_SESSION['user_id']]);
            $source_ref_id  = (int)$pdo->lastInsertId();
            $source_details = json_encode(['supplier_id'=>$supplierId,'supplier_name'=>$supplierName]);

        } elseif($source === 'supplier_loan_repayment'){
            $supplierId = intval($_POST['source_supplier_id'] ?? 0);
            if(!$supplierId) throw new Exception('Please select a supplier.');
            $sn = $pdo->prepare("SELECT name FROM suppliers WHERE id=?"); $sn->execute([$supplierId]);
            $supplierName = $sn->fetchColumn();
            if(!$supplierName) throw new Exception('Supplier not found.');
            $pdo->prepare("INSERT INTO supplier_loans (supplier_id,batch_id,type,amount,notes,is_deferred,created_by) VALUES (?,NULL,'repayment',?,?,0,?)")
                ->execute([$supplierId,$amount,$comment,$_SESSION['user_id']]);
            $source_ref_id  = (int)$pdo->lastInsertId();
            $source_details = json_encode(['supplier_id'=>$supplierId,'supplier_name'=>$supplierName]);

        } elseif($source === 'buyer_loan'){
            $buyerId = intval($_POST['source_buyer_id'] ?? 0);
            if(!$buyerId) throw new Exception('Please select a buyer.');
            $bn = $pdo->prepare("SELECT name FROM buyers WHERE id=?"); $bn->execute([$buyerId]);
            $buyerName = $bn->fetchColumn();
            if(!$buyerName) throw new Exception('Buyer not found.');
            $pdo->prepare("INSERT INTO buyer_loans (buyer_id,sale_id,type,amount,notes,created_by,is_advance) VALUES (?,NULL,'repayment',?,?,?,0)")
                ->execute([$buyerId,$amount,$comment,$_SESSION['user_id']]);
            $source_ref_id  = (int)$pdo->lastInsertId();
            $source_details = json_encode(['buyer_id'=>$buyerId,'buyer_name'=>$buyerName]);

        } elseif($source === 'purchase_minerals'){
            $ids  = $_POST['pm_minerals'] ?? [];
            $qtys = $_POST['pm_qty'] ?? [];
            $minerals_used = [];
            $upsert = $pdo->prepare("INSERT INTO inventory (mineral_type_id,current_stock) VALUES (?,?) ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)");
            $nameStmt = $pdo->prepare("SELECT name FROM mineral_types WHERE id=?");
            foreach($ids as $mid){
                $mid = intval($mid);
                $qty = round(floatval($qtys[$mid] ?? 0), 3);
                if($mid<=0 || $qty<=0) continue;
                $nameStmt->execute([$mid]); $name = $nameStmt->fetchColumn();
                if(!$name) continue;
                $upsert->execute([$mid,$qty]);
                $minerals_used[] = ['mineral_id'=>$mid,'name'=>$name,'qty'=>$qty];
            }
            if(!$minerals_used) throw new Exception('Select at least one mineral with a quantity greater than 0.');
            $source_details = json_encode(['minerals'=>$minerals_used]);

        } elseif($source === 'selling_minerals'){
            $ids  = $_POST['pm_minerals'] ?? [];
            $qtys = $_POST['pm_qty'] ?? [];
            $minerals_used = [];
            $stockStmt = $pdo->prepare("SELECT current_stock FROM inventory WHERE mineral_type_id=? FOR UPDATE");
            $deduct    = $pdo->prepare("UPDATE inventory SET current_stock = current_stock - ? WHERE mineral_type_id=?");
            $nameStmt  = $pdo->prepare("SELECT name FROM mineral_types WHERE id=?");
            foreach($ids as $mid){
                $mid = intval($mid);
                $qty = round(floatval($qtys[$mid] ?? 0), 3);
                if($mid<=0 || $qty<=0) continue;
                $nameStmt->execute([$mid]); $name = $nameStmt->fetchColumn();
                if(!$name) continue;
                $stockStmt->execute([$mid]); $available = (float)$stockStmt->fetchColumn();
                if($qty > $available) throw new Exception("Not enough $name in stock (available: ".number_format($available,3)."kg).");
                $deduct->execute([$qty,$mid]);
                $minerals_used[] = ['mineral_id'=>$mid,'name'=>$name,'qty'=>$qty];
            }
            if(!$minerals_used) throw new Exception('Select at least one mineral with a quantity greater than 0.');
            $source_details = json_encode(['minerals'=>$minerals_used]);
        }
        /* boss: descriptive only, no linked record */

        $pdo->prepare("INSERT INTO manual_journal (entry_date,amount,`comment`,entry_type,source,source_ref_id,source_details,created_by) VALUES (?,?,?,?,?,?,?,?)")
            ->execute([$date,$amount,$comment,$type,$source,$source_ref_id,$source_details,$_SESSION['user_id']]);
        $newId = $pdo->lastInsertId();
        logAction($pdo,$_SESSION['user_id'],'CREATE','manual_journal',$newId,"Manual $type ($source): $comment — $amount FRW");

        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Journal entry recorded.']);
    } catch(Exception $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
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

        $pdo->beginTransaction();

        if($rec['source']==='expense' && $rec['source_ref_id']){
            $pdo->prepare("DELETE FROM expenses WHERE id=?")->execute([$rec['source_ref_id']]);
        } elseif(($rec['source']==='supplier_loan' || $rec['source']==='supplier_loan_repayment') && $rec['source_ref_id']){
            $pdo->prepare("DELETE FROM supplier_loans WHERE id=?")->execute([$rec['source_ref_id']]);
        } elseif($rec['source']==='buyer_loan' && $rec['source_ref_id']){
            $pdo->prepare("DELETE FROM buyer_loans WHERE id=?")->execute([$rec['source_ref_id']]);
        } elseif($rec['source']==='purchase_minerals' && $rec['source_details']){
            $details = json_decode($rec['source_details'], true);
            $revert = $pdo->prepare("UPDATE inventory SET current_stock = GREATEST(0, current_stock - ?) WHERE mineral_type_id=?");
            foreach(($details['minerals'] ?? []) as $m){
                $revert->execute([$m['qty'], $m['mineral_id']]);
            }
        } elseif($rec['source']==='selling_minerals' && $rec['source_details']){
            $details = json_decode($rec['source_details'], true);
            $restore = $pdo->prepare("INSERT INTO inventory (mineral_type_id,current_stock) VALUES (?,?) ON DUPLICATE KEY UPDATE current_stock = current_stock + VALUES(current_stock)");
            foreach(($details['minerals'] ?? []) as $m){
                $restore->execute([$m['mineral_id'], $m['qty']]);
            }
        }

        $pdo->prepare("DELETE FROM manual_journal WHERE id=?")->execute([$id]);
        logAction($pdo,$_SESSION['user_id'],'DELETE','manual_journal',$id,"Deleted manual {$rec['entry_type']} {$rec['amount']} FRW: {$rec['comment']}");

        $pdo->commit();
        echo json_encode(['success'=>true,'message'=>'Entry deleted.']);
    } catch(Exception $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit;
}

/* ══════════════════════════════════════════════════════════════
   Fragment renderers — shared by the full-page render below and
   the AJAX JSON endpoint, so both stay in sync from one source.
══════════════════════════════════════════════════════════════ */
function mj_render_stats_cards(array $stats, float $net, float $overall_balance): string {
    ob_start(); ?>
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
    <?php return ob_get_clean();
}

function mj_render_period(array $f): string {
    ob_start(); ?>
    Period: <?= $f['date_from'] ? date('d M Y',strtotime($f['date_from'])) : 'All time' ?>
    &ndash;
    <?= $f['date_to'] ? date('d M Y',strtotime($f['date_to'])) : 'Today' ?>
    <?php if($f['entry_type']): ?> &middot; Type: <?= ucfirst($f['entry_type']) ?><?php endif; ?>
    &middot; Printed <?= date('d M Y H:i') ?>
    <?php return ob_get_clean();
}

function mj_render_rows(array $entries, array $balance_after, float $opening_balance, bool $show_opening_row, array $f): array {
    ob_start();
    if(!$entries && !$show_opening_row): ?>
        <tr id="empty-row"><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No manual journal entries for this period.</td></tr>
    <?php endif;
    if($show_opening_row): ?>
        <tr style="background:var(--bg)">
            <td class="text-muted" style="font-size:.8rem;white-space:nowrap"><?= $f['date_from'] ? date('d M Y',strtotime($f['date_from'])) : '—' ?></td>
            <td style="font-size:.83rem;font-style:italic;color:var(--text-muted)">Balance brought forward</td>
            <td></td>
            <td></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $opening_balance>=0?'#2563eb':'#dc2626' ?>"><?= number_format($opening_balance,2) ?></td>
            <td></td>
            <td></td>
        </tr>
    <?php endif;
    $page_debit=0; $page_credit=0; $last_balance=$opening_balance;
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
            <td style="font-size:.85rem;font-weight:500">
                <?= htmlspecialchars($en['comment']) ?>
                <?php $srcLabel = mj_source_label($en); if($srcLabel): ?>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:.15rem;font-weight:400"><i class="fas fa-tag" style="margin-right:.25rem"></i><?= htmlspecialchars($srcLabel) ?></div>
                <?php endif; ?>
            </td>
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
    <?php endforeach;
    $html = ob_get_clean();
    return ['html'=>$html,'page_debit'=>$page_debit,'page_credit'=>$page_credit,'last_balance'=>$last_balance];
}

function mj_render_tfoot_row(array $entries, float $page_debit, float $page_credit, float $last_balance): string {
    if(!$entries) return '';
    ob_start(); ?>
    <tr style="border-top:2px solid var(--border);background:var(--surface,var(--bg))">
        <td colspan="2" style="font-size:.82rem;font-weight:600;padding:.6rem 1rem;color:var(--text-muted)">Page total</td>
        <td style="text-align:right;font-family:monospace;font-weight:700;color:#dc2626;padding:.6rem 1rem"><?= number_format($page_debit,2) ?></td>
        <td style="text-align:right;font-family:monospace;font-weight:700;color:#16a34a;padding:.6rem 1rem"><?= number_format($page_credit,2) ?></td>
        <td style="text-align:right;font-family:monospace;font-weight:700;padding:.6rem 1rem">Bal: <?= number_format($last_balance,2) ?></td>
        <td colspan="2"></td>
    </tr>
    <?php return ob_get_clean();
}

function mj_render_print_rows(array $print_entries, array $balance_after, float $opening_balance, array $f): array {
    ob_start();
    if(!$print_entries && !$f['date_from']): ?>
        <tr><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No manual journal entries for this period.</td></tr>
    <?php endif;
    if($f['date_from']): ?>
        <tr style="background:var(--bg)">
            <td class="text-muted" style="font-size:.8rem;white-space:nowrap"><?= date('d M Y',strtotime($f['date_from'])) ?></td>
            <td style="font-size:.83rem;font-style:italic;color:var(--text-muted)">Balance brought forward</td>
            <td></td>
            <td></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $opening_balance>=0?'#2563eb':'#dc2626' ?>"><?= number_format($opening_balance,2) ?></td>
            <td></td>
            <td></td>
        </tr>
    <?php endif;
    $last_balance = $opening_balance;
    foreach($print_entries as $en): $isCredit = $en['entry_type']==='credit';
        $rowBal = $balance_after[$en['id']] ?? $last_balance;
        $last_balance = $rowBal;
    ?>
        <tr>
            <td class="text-muted" style="font-size:.82rem;white-space:nowrap">
                <?= date('d M Y',strtotime($en['entry_date'])) ?>
                <div style="font-size:.7rem">Processed: <?= date('H:i',strtotime($en['created_at'])) ?></div>
            </td>
            <td style="font-size:.85rem;font-weight:500">
                <?= htmlspecialchars($en['comment']) ?>
                <?php $srcLabel = mj_source_label($en); if($srcLabel): ?>
                <div style="font-size:.72rem;color:var(--text-muted);margin-top:.15rem;font-weight:400"><i class="fas fa-tag" style="margin-right:.25rem"></i><?= htmlspecialchars($srcLabel) ?></div>
                <?php endif; ?>
            </td>
            <td style="text-align:right;font-family:monospace;font-weight:600;color:#dc2626"><?= $isCredit ? '' : number_format($en['amount'],2) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:600;color:#16a34a"><?= $isCredit ? number_format($en['amount'],2) : '' ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $rowBal>=0?'var(--text)':'#dc2626' ?>"><?= number_format($rowBal,2) ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($en['created_by_name'] ?? '—') ?></td>
            <td></td>
        </tr>
    <?php endforeach;
    $html = ob_get_clean();
    return ['html'=>$html,'last_balance'=>$last_balance];
}

function mj_render_print_tfoot_row(array $print_entries, array $stats, float $last_balance): string {
    if(!$print_entries) return '';
    ob_start(); ?>
    <tr style="border-top:2px solid var(--border);background:var(--surface,var(--bg))">
        <td colspan="2" style="font-size:.82rem;font-weight:600;padding:.6rem 1rem;color:var(--text-muted)">Period total</td>
        <td style="text-align:right;font-family:monospace;font-weight:700;color:#dc2626;padding:.6rem 1rem"><?= number_format($stats['total_debit'],2) ?></td>
        <td style="text-align:right;font-family:monospace;font-weight:700;color:#16a34a;padding:.6rem 1rem"><?= number_format($stats['total_credit'],2) ?></td>
        <td style="text-align:right;font-family:monospace;font-weight:700;padding:.6rem 1rem">Bal: <?= number_format($last_balance,2) ?></td>
        <td colspan="2"></td>
    </tr>
    <?php return ob_get_clean();
}

/* ══════════════════════════════════════════════════════════════
   Page data
══════════════════════════════════════════════════════════════ */
$page_title = 'Manual Journal';

$mj_minerals  = $pdo->query("SELECT id,name,unit FROM mineral_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$mj_suppliers = $pdo->query("SELECT id,name,phone FROM suppliers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$mj_buyers    = $pdo->query("SELECT id,name,phone FROM buyers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$MJ_EXPENSE_CATEGORIES = ['Electricity','Water','Internet','Airtime','Gas','Waste Management','Security',
    'Transport','Salaries','Office Supplies','Equipment','Maintenance','Government Fees','Restaurant','Other'];

$MJ_CREDIT_SOURCES = ['boss'=>'From boss','selling_minerals'=>'From selling minerals','buyer_loan'=>'From Buyers loan','supplier_loan_repayment'=>'Loan repayment from supplier'];
$MJ_DEBIT_SOURCES  = ['expense'=>'Expense','purchase_minerals'=>'Purchase minerals','supplier_loan'=>'Supplier Loans'];

function mj_source_label(array $en): string {
    global $MJ_CREDIT_SOURCES, $MJ_DEBIT_SOURCES;
    $src = $en['source'] ?? '';
    if(!$src) return '';
    $label = $MJ_CREDIT_SOURCES[$src] ?? $MJ_DEBIT_SOURCES[$src] ?? '';
    if(!$label) return '';
    $details = !empty($en['source_details']) ? json_decode($en['source_details'], true) : null;
    if($src==='expense' && $details)        $label .= ' — '.$details['category'];
    if(($src==='supplier_loan' || $src==='supplier_loan_repayment') && $details) $label .= ' — '.$details['supplier_name'];
    if($src==='buyer_loan' && $details)     $label .= ' — '.$details['buyer_name'];
    if(($src==='purchase_minerals' || $src==='selling_minerals') && $details && !empty($details['minerals'])){
        $parts = array_map(function($m){
            return $m['name'].' '.rtrim(rtrim(number_format($m['qty'],3),'0'),'.').'kg';
        }, $details['minerals']);
        $label .= ' — '.implode(', ',$parts);
    }
    return $label;
}

$f = [
    'date_from'  => $_GET['date_from']  ?? date('Y-m-d'),
    'date_to'    => $_GET['date_to']    ?? date('Y-m-d'),
    'entry_type' => $_GET['entry_type'] ?? '',
    'search'     => trim($_GET['search'] ?? ''),
];

$wh=[]; $wp=[];
if($f['date_from'])  { $wh[]="entry_date>=?"; $wp[]=$f['date_from']; }
if($f['date_to'])    { $wh[]="entry_date<=?"; $wp[]=$f['date_to'];   }
if($f['entry_type']) { $wh[]="entry_type=?";  $wp[]=$f['entry_type']; }
if($f['search'])     { $wh[]="`comment` LIKE ?"; $wp[]='%'.$f['search'].'%'; }
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
$per_page = 100;
$page     = max(1, intval($_GET['page'] ?? 1));
$cnt_s    = $pdo->prepare("SELECT COUNT(*) FROM manual_journal $wsql"); $cnt_s->execute($wp);
$total_rows  = (int)$cnt_s->fetchColumn();
$total_pages = max(1,(int)ceil($total_rows/$per_page));
$page        = min($page,$total_pages);
$offset      = ($page-1)*$per_page;

$data_s = $pdo->prepare("SELECT mj.*,u.username AS created_by_name FROM manual_journal mj LEFT JOIN users u ON u.id=mj.created_by $wsql ORDER BY mj.entry_date ASC, mj.created_at ASC, mj.id ASC LIMIT $per_page OFFSET $offset");
$data_s->execute($wp); $entries=$data_s->fetchAll(PDO::FETCH_ASSOC);

/* ── Print: full filtered set, no pagination ─────────────────── */
$print_s = $pdo->prepare("SELECT mj.*,u.username AS created_by_name FROM manual_journal mj LEFT JOIN users u ON u.id=mj.created_by $wsql ORDER BY mj.entry_date ASC, mj.created_at ASC, mj.id ASC");
$print_s->execute($wp); $print_entries = $print_s->fetchAll(PDO::FETCH_ASSOC);

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

/* ── Build all fragments once, shared by the AJAX branch and the full render ── */
$rows              = mj_render_rows($entries,$balance_after,$opening_balance,$show_opening_row,$f);
$tfoot_row_html     = mj_render_tfoot_row($entries,$rows['page_debit'],$rows['page_credit'],$rows['last_balance']);
$print_rows         = mj_render_print_rows($print_entries,$balance_after,$opening_balance,$f);
$print_tfoot_row_html = mj_render_print_tfoot_row($print_entries,$stats,$print_rows['last_balance']);
$stats_cards_html   = mj_render_stats_cards($stats,$net,$overall_balance);
$period_html        = mj_render_period($f);
$pagination_html    = $total_pages>1 ? paginate($page,$total_pages,array_filter($f),'manual_journal.php') : '';

/* ── AJAX: filtered/paginated data, no full-page reload ──────── */
if($_SERVER['REQUEST_METHOD']==='GET' && isset($_SERVER['HTTP_X_REQUESTED_WITH']) && isset($_GET['ajax'])){
    header('Content-Type: application/json');
    echo json_encode([
        'success'        => true,
        'statsHtml'      => $stats_cards_html,
        'rowsHtml'       => $rows['html'],
        'tfootHtml'      => $tfoot_row_html,
        'paginationHtml' => $pagination_html,
        'printRowsHtml'  => $print_rows['html'],
        'printTfootHtml' => $print_tfoot_row_html,
        'periodHtml'     => $period_html,
        'totalRows'      => $total_rows,
        'page'           => $page,
        'totalPages'     => $total_pages,
    ]);
    exit;
}

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
    <div id="mj-print-period" style="font-size:.85rem;color:#333"><?= $period_html ?></div>
</div>

<!-- Stats -->
<div class="mj-stats" id="mj-stats" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:.75rem;margin-bottom:1rem">
<?= $stats_cards_html ?>
</div>

<!-- Filters -->
<form method="GET" action="manual_journal.php" class="filter-bar" id="mj-filter-form" style="margin-bottom:1rem">
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
    <div class="filter-group" style="position:relative">
        <label>Search</label>
        <input type="text" name="search" id="mj-search" value="<?= htmlspecialchars($f['search']) ?>" placeholder="Search particulars…" oninput="onSearchInput()">
        <i class="fas fa-spinner fa-spin" id="mj-search-spinner" style="display:none;position:absolute;right:.6rem;bottom:.55rem;color:var(--text-muted);font-size:.8rem"></i>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-filter"></i> Filter</button>
        <button type="button" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem" onclick="clearFilters()"><i class="fas fa-xmark"></i> Clear</button>
    </div>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> <span id="mj-badge-count"><?= $total_rows ?> record<?= $total_rows!=1?'s':'' ?></span></span>
</form>

<div id="mj-type-note" style="font-size:.78rem;color:var(--text-muted);margin:-.5rem 0 .75rem;display:<?= $f['entry_type']?'flex':'none' ?>;align-items:center;gap:.35rem">
    <i class="fas fa-circle-info"></i> Balance reflects the full ledger — the Type filter only limits which rows are shown.
</div>

<style>
/* White page background, scoped to this page */
body, .page-content { background:#fff; }

/* Excel-style grid borders, scoped to this page's ledger table */
.ledger-table { border-collapse:collapse; }
.ledger-table th, .ledger-table td { border:1px solid var(--border); }
.ledger-table tbody tr:last-child td { border-bottom:1px solid var(--border); }
.ledger-table tfoot td { border:1px solid var(--border); }

.table-wrap.mj-loading { opacity:.5; pointer-events:none; transition:opacity .15s; }

/* Print */
@media print {
    .topnav, .quickbar, .topbar, .filter-bar, .page-header button,
    #page-alert, .modal-backdrop, .pagination-wrap, .pagination-info,
    .mj-stats { display:none !important; }
    body, .page-content { background:#fff; padding:0; }
    .print-only { display:block !important; }
    .table-wrap { display:none !important; }
    .print-full-table { display:block !important; border:none; box-shadow:none; overflow:visible; }
    .print-full-table .ledger-table { font-size:.8rem; }
    .print-full-table .ledger-table th, .print-full-table .ledger-table td { border-color:#000; }
    .print-full-table .ledger-table th:last-child, .print-full-table .ledger-table td:last-child { display:none; }
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
        <tbody id="mj-tbody"><?= $rows['html'] ?></tbody>
        <tfoot id="mj-tfoot"><?= $tfoot_row_html ?></tfoot>
    </table>
    <div id="mj-pagination-wrap"><?= $pagination_html ?></div>
</div>

<!-- Print-only: full filtered set, ignoring pagination -->
<div class="print-full-table" style="display:none">
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
        <tbody id="mj-print-tbody"><?= $print_rows['html'] ?></tbody>
        <tfoot id="mj-print-tfoot"><?= $print_tfoot_row_html ?></tfoot>
    </table>
</div>

<!-- ── Add Entry Modal ─────────────────────────────────────────── -->
<div class="modal-backdrop" id="mj-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" style="max-width:460px">
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
                        <label>Entry Type</label>
                        <select name="entry_type" required onchange="onMjTypeChange()">
                            <option value="" disabled selected>Select Type</option>
                            <option value="credit">Credit</option>
                            <option value="debit">Debit</option>
                        </select>
                    </div>
                    <div class="form-group" id="mj-source-group" style="grid-column:1/-1;display:none">
                        <label>Source | reason</label>
                        <select name="source" id="mj-source" onchange="onMjSourceChange()">
                            <option value="" disabled selected>Select Source</option>
                        </select>
                    </div>
                    <div class="form-group" id="mj-src-buyer-group" style="grid-column:1/-1;display:none">
                        <label>Buyer</label>
                        <select name="source_buyer_id" id="mj-src-buyer" onchange="updateMjAutoComment()">
                            <option value="">— Select buyer —</option>
                            <?php foreach($mj_buyers as $b): ?>
                            <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name'].($b['phone']?' — '.$b['phone']:'')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="mj-src-supplier-group" style="grid-column:1/-1;display:none">
                        <label>Supplier</label>
                        <select name="source_supplier_id" id="mj-src-supplier" onchange="updateMjAutoComment()">
                            <option value="">— Select supplier —</option>
                            <?php foreach($mj_suppliers as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name'].($s['phone']?' — '.$s['phone']:'')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="mj-src-expense-group" style="grid-column:1/-1;display:none">
                        <label>Expense Category</label>
                        <select name="source_expense_category" id="mj-src-expense-cat" onchange="updateMjAutoComment()">
                            <?php foreach($MJ_EXPENSE_CATEGORIES as $c): ?>
                            <option value="<?= htmlspecialchars($c) ?>"<?= $c==='Other'?' selected':'' ?>><?= htmlspecialchars($c) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group" id="mj-src-minerals-group" style="grid-column:1/-1;display:none">
                        <label id="mj-src-minerals-label">Minerals</label>
                        <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:.3rem">
                            <?php foreach($mj_minerals as $m): ?>
                            <div style="display:flex;align-items:center;gap:.6rem">
                                <label style="display:flex;align-items:center;gap:.4rem;font-weight:500;font-size:.85rem;min-width:110px;cursor:pointer">
                                    <input type="checkbox" name="pm_minerals[]" value="<?= $m['id'] ?>" onchange="onMjMineralToggle(<?= $m['id'] ?>)">
                                    <?= htmlspecialchars($m['name']) ?>
                                </label>
                                <input type="number" step="0.001" min="0" name="pm_qty[<?= $m['id'] ?>]" id="mj-pm-qty-<?= $m['id'] ?>"
                                    placeholder="Qty (<?= htmlspecialchars($m['unit']?:'kg') ?>)" disabled style="max-width:140px" oninput="updateMjAutoComment()">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Currency</label>
                        <div style="display:flex;gap:1.25rem;margin-top:.3rem">
                            <label style="display:flex;align-items:center;gap:.35rem;font-weight:500;cursor:pointer;font-size:.85rem">
                                <input type="radio" name="mj_currency" value="FRW" checked onchange="onMjCurrencyChange()"> FRW
                            </label>
                            <label style="display:flex;align-items:center;gap:.35rem;font-weight:500;cursor:pointer;font-size:.85rem">
                                <input type="radio" name="mj_currency" value="USD" onchange="onMjCurrencyChange()"> USD
                            </label>
                        </div>
                    </div>
                    <div class="form-group" id="mj-rate-group" style="grid-column:1/-1;display:none">
                        <label>Exchange Rate (1 USD = FRW)</label>
                        <input type="text" id="mj-rate" value="1,460" style="font-family:monospace" inputmode="decimal" oninput="formatAmountInput(this);updateMjAmount();saveMjRate()">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label id="mj-amount-label">Amount (FRW)</label>
                        <input type="text" id="mj-amount-native" placeholder="0.00" required style="font-family:monospace" inputmode="decimal" oninput="formatAmountInput(this);updateMjAmount()">
                        <input type="hidden" name="amount" id="mj-amount">
                        <div id="mj-frw-note" style="display:none;font-size:.78rem;color:var(--text-muted);margin-top:.3rem"></div>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Particulars <span style="font-weight:400;color:var(--text-muted);font-size:.78rem">(auto-filled from source — edit if you want)</span></label>
                        <textarea name="comment" id="mj-comment" placeholder="Reason for this entry…" style="min-height:70px" oninput="mjCommentTouched=true"></textarea>
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
let currentPage = <?= (int)$page ?>;

/* ── Source: depends on Entry Type ─────────────────────────────── */
const MJ_SOURCES = {
    credit: [['boss','From boss'],['selling_minerals','From selling minerals'],['buyer_loan','From Buyers loan'],['supplier_loan_repayment','Loan repayment from supplier']],
    debit:  [['expense','Expense'],['purchase_minerals','Purchase minerals'],['supplier_loan','Supplier Loans']],
};

function onMjTypeChange(){
    const type       = document.querySelector('#mj-form [name="entry_type"]').value;
    const sourceGrp  = document.getElementById('mj-source-group');
    const sourceSel  = document.getElementById('mj-source');
    sourceSel.innerHTML = '<option value="" disabled selected>Select Source</option>' +
        (MJ_SOURCES[type]||[]).map(([v,l]) => `<option value="${v}">${l}</option>`).join('');
    sourceGrp.style.display = type ? '' : 'none';
    sourceSel.required = !!type;
    onMjSourceChange();
}

function onMjSourceChange(){
    const source = document.getElementById('mj-source').value;
    const needsMinerals = source === 'purchase_minerals' || source === 'selling_minerals';
    const needsSupplier = source === 'supplier_loan' || source === 'supplier_loan_repayment';
    const groups = {
        buyer_loan: 'mj-src-buyer-group',
        expense:    'mj-src-expense-group',
    };
    Object.entries(groups).forEach(([key,groupId]) => {
        document.getElementById(groupId).style.display = (source === key) ? '' : 'none';
    });
    document.getElementById('mj-src-supplier-group').style.display = needsSupplier ? '' : 'none';
    document.getElementById('mj-src-minerals-group').style.display = needsMinerals ? '' : 'none';
    document.getElementById('mj-src-minerals-label').textContent =
        source === 'selling_minerals' ? 'Minerals Sold' : 'Minerals Purchased';
    document.getElementById('mj-src-buyer').required    = source === 'buyer_loan';
    document.getElementById('mj-src-supplier').required = needsSupplier;
    if(!needsMinerals){
        document.querySelectorAll('#mj-src-minerals-group input[type="checkbox"]').forEach(cb => cb.checked=false);
        document.querySelectorAll('#mj-src-minerals-group input[type="number"]').forEach(q => { q.disabled=true; q.value=''; });
    }
    updateMjAutoComment();
}

function onMjMineralToggle(id){
    const cb  = document.querySelector('input[name="pm_minerals[]"][value="'+id+'"]');
    const qty = document.getElementById('mj-pm-qty-'+id);
    qty.disabled = !cb.checked;
    if(!cb.checked) qty.value = ''; else qty.focus();
    updateMjAutoComment();
}

/* ── Build a short "[Source: …]" summary of what was collected, to append to Particulars ── */
function mjSourceSummary(source){
    const sel   = document.getElementById('mj-source');
    const label = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : '';
    if(source === 'supplier_loan' || source === 'supplier_loan_repayment'){
        const s = document.getElementById('mj-src-supplier');
        const name = s.options[s.selectedIndex] ? s.options[s.selectedIndex].text : '';
        return name ? `${label}: ${name}` : label;
    }
    if(source === 'buyer_loan'){
        const b = document.getElementById('mj-src-buyer');
        const name = b.options[b.selectedIndex] ? b.options[b.selectedIndex].text : '';
        return name ? `${label}: ${name}` : label;
    }
    if(source === 'expense'){
        const cat = document.getElementById('mj-src-expense-cat').value;
        return cat ? `${label} (${cat})` : label;
    }
    if(source === 'purchase_minerals' || source === 'selling_minerals'){
        const parts = Array.from(document.querySelectorAll('#mj-src-minerals-group input[type="checkbox"]:checked')).map(cb => {
            const name = cb.parentElement.textContent.trim();
            const qty  = document.getElementById('mj-pm-qty-'+cb.value).value;
            return qty ? `${name} ${qty}kg` : name;
        });
        return parts.length ? `${label}: ${parts.join(', ')}` : label;
    }
    return label; // boss
}

/* ── Auto-fill Particulars from the source, so the user can skip typing a reason ── */
let mjCommentTouched = false;
function updateMjAutoComment(){
    const source = document.getElementById('mj-source').value;
    if(!source || mjCommentTouched) return;
    const summary = mjSourceSummary(source);
    if(summary) document.getElementById('mj-comment').value = summary;
}

/* ── Modal open / close ─────────────────────────────────────── */
function openModal(){
    document.getElementById('mj-modal').classList.add('open');
    document.body.style.overflow='hidden';
    clearModalAlert();
    document.querySelector('input[name="mj_currency"][value="FRW"]').checked=true;
    restoreMjRate();
    mjCommentTouched = false;
    document.getElementById('mj-comment').value = '';
    onMjCurrencyChange();
    onMjTypeChange();
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

/* ── Currency: FRW / USD toggle with live conversion ──────────── */
function mjParseNum(v){ return parseFloat(String(v||'').replace(/,/g,'')) || 0; }

function saveMjRate(){
    localStorage.setItem('mj_exchange_rate', document.getElementById('mj-rate').value);
}
function restoreMjRate(){
    const saved = localStorage.getItem('mj_exchange_rate');
    if(saved) document.getElementById('mj-rate').value = saved;
}

function onMjCurrencyChange(){
    const currency = document.querySelector('input[name="mj_currency"]:checked').value;
    document.getElementById('mj-rate-group').style.display = currency==='USD' ? 'block' : 'none';
    document.getElementById('mj-amount-label').textContent = currency==='USD' ? 'Amount (USD)' : 'Amount (FRW)';
    updateMjAmount();
}

function updateMjAmount(){
    const currency = document.querySelector('input[name="mj_currency"]:checked').value;
    const native   = mjParseNum(document.getElementById('mj-amount-native').value);
    const note     = document.getElementById('mj-frw-note');
    if(currency==='USD'){
        const rate = mjParseNum(document.getElementById('mj-rate').value);
        const frw  = native * rate;
        document.getElementById('mj-amount').value = frw.toFixed(2);
        note.style.display = 'block';
        note.textContent = '= ' + frw.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' FRW';
    } else {
        document.getElementById('mj-amount').value = native.toFixed(2);
        note.style.display = 'none';
    }
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

/* ── AJAX data load: filters + pagination, no page reload ─────── */
let mjLoadSeq=0;
function loadData(page){
    currentPage = page;
    const seq = ++mjLoadSeq;
    const form = document.getElementById('mj-filter-form');
    const fd = new FormData(form);
    const params = new URLSearchParams();
    for(const [k,v] of fd.entries()) if(v!=='') params.append(k,v);
    params.set('page', page);
    params.set('ajax','1');

    document.getElementById('mj-search-spinner').style.display='inline-block';
    document.querySelector('.table-wrap').classList.add('mj-loading');

    fetch('manual_journal.php?'+params.toString(), {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(seq !== mjLoadSeq) return; // a newer request has since superseded this one
        if(!d.success){ showAlert('error', d.message || 'Failed to load data.'); return; }
        document.getElementById('mj-stats').innerHTML = d.statsHtml;
        document.getElementById('mj-tbody').innerHTML = d.rowsHtml;
        document.getElementById('mj-tfoot').innerHTML = d.tfootHtml;
        document.getElementById('mj-pagination-wrap').innerHTML = d.paginationHtml;
        document.getElementById('mj-print-tbody').innerHTML = d.printRowsHtml;
        document.getElementById('mj-print-tfoot').innerHTML = d.printTfootHtml;
        document.getElementById('mj-print-period').innerHTML = d.periodHtml;
        document.getElementById('mj-badge-count').textContent = d.totalRows+' record'+(d.totalRows!=1?'s':'');
        currentPage = d.page;
    })
    .catch(()=>{ if(seq === mjLoadSeq) showAlert('error','Network error while loading data.'); })
    .finally(()=>{
        if(seq !== mjLoadSeq) return;
        document.getElementById('mj-search-spinner').style.display='none';
        document.querySelector('.table-wrap').classList.remove('mj-loading');
    });
}

document.getElementById('mj-filter-form').addEventListener('submit', function(e){
    e.preventDefault();
    loadData(1);
});

/* ── Live search: filters the ledger by comment as you type ───── */
let mjSearchTimer=null;
function onSearchInput(){
    clearTimeout(mjSearchTimer);
    mjSearchTimer=setTimeout(()=>loadData(1),350);
}

function clearFilters(){
    const form = document.getElementById('mj-filter-form');
    const today = new Date().toLocaleDateString('en-CA');
    form.date_from.value = today;
    form.date_to.value = today;
    form.entry_type.value = '';
    form.search.value = '';
    updateTypeNote();
    loadData(1);
}

function updateTypeNote(){
    document.getElementById('mj-type-note').style.display =
        document.querySelector('#mj-filter-form select[name="entry_type"]').value ? 'flex' : 'none';
}
document.querySelector('#mj-filter-form select[name="entry_type"]').addEventListener('change', updateTypeNote);

document.getElementById('mj-pagination-wrap').addEventListener('click', function(e){
    const a = e.target.closest('a');
    if(!a) return;
    e.preventDefault();
    if(a.classList.contains('pg-disabled')) return;
    const url = new URL(a.getAttribute('href'), location.href);
    loadData(parseInt(url.searchParams.get('page') || '1', 10));
});

/* ── Form submit ────────────────────────────────────────────── */
document.getElementById('mj-form').addEventListener('submit',function(e){
    e.preventDefault();
    const source = document.getElementById('mj-source').value;
    if(!source){
        showModalAlert('Please choose a source.');
        return;
    }
    if(source === 'purchase_minerals' || source === 'selling_minerals'){
        const anyQty = Array.from(document.querySelectorAll('#mj-src-minerals-group input[type="checkbox"]:checked'))
            .some(cb => mjParseNum(document.getElementById('mj-pm-qty-'+cb.value).value) > 0);
        if(!anyQty){
            showModalAlert('Select at least one mineral with a quantity greater than 0.');
            return;
        }
    }
    const commentEl = document.getElementById('mj-comment');
    if(!commentEl.value.trim()){
        commentEl.value = mjSourceSummary(source); // fallback: user skipped typing a reason
    }
    const currency = document.querySelector('input[name="mj_currency"]:checked').value;
    if(currency==='USD' && mjParseNum(document.getElementById('mj-rate').value)<=0){
        showModalAlert('Please enter a valid exchange rate.');
        return;
    }
    updateMjAmount();
    if(currency==='USD'){
        const usdAmt = mjParseNum(document.getElementById('mj-amount-native').value);
        commentEl.value = commentEl.value.replace(/\s*\(\$[\d,.]+\s*USD\)\s*$/,'').trim()
            + ' ($' + usdAmt.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' USD)';
    }
    const btn=document.getElementById('m-save-btn');
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
    fetch('manual_journal.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:new FormData(this)})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            closeModal();
            this.reset();
            showAlert('success',d.message);
            loadData(currentPage);
        } else {
            showModalAlert(d.message);
        }
        btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Entry';
    }).catch(()=>{showModalAlert('Network error. Please try again.');btn.disabled=false;btn.innerHTML='<i class="fas fa-save"></i> Save Entry';});
});

/* ── Delete ─────────────────────────────────────────────────── */
function deleteEntry(id,btn){
    if(!confirm('Delete this journal entry? This cannot be undone.')) return;
    btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
    const fd=new FormData(); fd.append('delete_entry','1'); fd.append('id',id);
    fetch('manual_journal.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){ showAlert('success',d.message); loadData(currentPage); }
        else { showAlert('error',d.message); btn.disabled=false; btn.innerHTML='<i class="fas fa-trash"></i>'; }
    }).catch(()=>{ showAlert('error','Network error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-trash"></i>'; });
}
</script>

<?php include 'includes/footer.php'; ?>
