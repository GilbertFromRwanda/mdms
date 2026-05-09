<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX: save batch(es) ─────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    try {
        $minerals_post = $_POST['mineral'] ?? [];
        if(empty($minerals_post)) throw new Exception('No minerals selected.');

        $supplier_id = $_POST['supplier_id'];
        $currency    = in_array($_POST['currency'] ?? '', ['USD','FRW']) ? $_POST['currency'] : 'FRW';
        $origin      = '';
        $recv_date   = $_POST['received_date'];
        $cert        = '';
        $notes       = $_POST['notes'] ?? '';

        $loan_type   = $_POST['loan_type']   ?? '';
        $loan_amount = floatval($_POST['loan_amount'] ?? 0);
        $loan_notes  = trim($_POST['loan_notes'] ?? '');
        $loan_action_map = ['loan' => 'give', 'repayment' => 'deduct'];
        $loan_action = $loan_action_map[$loan_type] ?? 'none';

        // Pre-sum all payment rows to get total amount paid
        $payments_post  = $_POST['payments'] ?? [];
        $total_amount_paid = 0;
        $valid_payments = [];
        foreach($payments_post as $p){
            $pmethod = in_array($p['method'] ?? '', ['cash','bank','momo']) ? $p['method'] : null;
            $pamount = floatval($p['amount'] ?? 0);
            $pacct   = intval($p['account_id'] ?? 0);
            if(!$pmethod || $pamount <= 0) continue;
            $total_amount_paid += $pamount;
            $valid_payments[] = ['method' => $pmethod, 'account_id' => $pacct, 'amount' => $pamount];
        }

        $pdo->beginTransaction();

        $created           = [];
        $first_batch_db_id = null;
        $total_take_home   = 0;
        foreach($minerals_post as $mid => $mdata){
            $batch_id = 'BATCH-'.date('Ymd').'-'.rand(1000,9999);
            $qty      = floatval($mdata['quantity']     ?? 0);
            $grade    = 'Standard';
            $pu       = isset($mdata['price_per_unit']) && $mdata['price_per_unit']!==''
                        ? floatval($mdata['price_per_unit']) : null;
            $ta       = $pu !== null ? round($pu * $qty, 2) : null;

            $pdo->prepare("
                INSERT INTO batches
                  (batch_id,mineral_type_id,supplier_id,quantity,quality_grade,
                   origin_location,received_date,certificate_number,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?)
            ")->execute([$batch_id,$mid,$supplier_id,$qty,$grade,$origin,$recv_date,$cert,$notes,$_SESSION['user_id']]);
            $lastId = $pdo->lastInsertId();
            if($first_batch_db_id === null) $first_batch_db_id = $lastId;

            $tc = 'TRX-'.date('YmdHis').'-'.rand(10,99);
            $pdo->prepare("
                INSERT INTO transactions
                  (transaction_code,transaction_type,batch_id,mineral_type_id,quantity,transaction_date,price_per_unit,total_amount,created_by)
                VALUES (?,'IN',?,?,?,?,?,?,?)
            ")->execute([$tc,$lastId,$mid,$qty,$recv_date,$pu,$ta,$_SESSION['user_id']]);

            logAction($pdo,$_SESSION['user_id'],'CREATE','batches',$lastId,"Added batch: $batch_id");

            // Store full calculation detail
            $fn = fn($k) => ($mdata[$k] ?? '') !== '' ? floatval($mdata[$k]) : null;
            $mineral_take_home = $fn('take_home') ?? 0;
            $total_take_home  += $mineral_take_home;

            $pdo->prepare("
                INSERT INTO purchase_details
                  (batch_id,mineral_id,supplier_id,purchase_date,currency_used,qty,
                   sample,rwf_rate,fees_1,fees_2,tag,rma,rra,lma,tmt,tantal,
                   unit_price,take_home,loan_action,loan_amount,amount_paid,comment,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $lastId,$mid,$supplier_id,$recv_date,$currency,$qty,
                $fn('sample'),$fn('rwf_rate'),$fn('fees_1'),$fn('fees_2'),
                $fn('tag'),$fn('rma'),$fn('rra'),$fn('lma'),$fn('tmt'),$fn('tantal'),
                $pu,$mineral_take_home,$loan_action,$loan_amount,$total_amount_paid,$notes,$_SESSION['user_id']
            ]);

            $row = $pdo->prepare("SELECT mt.name AS mineral_name, s.name AS supplier_name FROM mineral_types mt, suppliers s WHERE mt.id=? AND s.id=?");
            $row->execute([$mid,$supplier_id]);
            $names = $row->fetch();

            $created[] = [
                'batch_id'      => $batch_id,
                'mineral_name'  => $names['mineral_name'],
                'supplier_name' => $names['supplier_name'],
                'quantity'      => $qty,
                'currency'      => $currency,
            ];
        }

        if(in_array($loan_type, ['loan','repayment']) && $loan_amount > 0){
            if($loan_type === 'repayment'){
                $cb = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' AND is_deferred=0 THEN amount WHEN type='repayment' AND is_deferred=0 THEN -amount ELSE 0 END),0) FROM supplier_loans WHERE supplier_id=?");
                $cb->execute([$supplier_id]);
                $cur_bal = (float)$cb->fetchColumn();
                if($cur_bal <= 0)
                    throw new Exception('This supplier has no outstanding advance to repay.');
                if($loan_amount > $cur_bal)
                    throw new Exception('Repayment of '.number_format($loan_amount,2).' FRW exceeds advance balance of '.number_format($cur_bal,2).' FRW.');
            }
            $pdo->prepare("
                INSERT INTO supplier_loans (supplier_id,batch_id,type,amount,notes,is_deferred,created_by)
                VALUES (?,?,?,?,?,0,?)
            ")->execute([$supplier_id,$first_batch_db_id,$loan_type,$loan_amount,$loan_notes,$_SESSION['user_id']]);
        }

        // Auto-record loan payable when supplier was underpaid
        $net_to_pay   = $total_take_home
                        + ($loan_type === 'loan'      ? $loan_amount : 0)
                        - ($loan_type === 'repayment' ? $loan_amount : 0);
        $loan_payable = round($net_to_pay - $total_amount_paid, 2);
        if($loan_payable > 0.005){
            // Underpaid — record as deferred mineral payment (we owe supplier)
            $pdo->prepare("
                INSERT INTO supplier_loans (supplier_id,batch_id,type,amount,notes,is_deferred,created_by)
                VALUES (?,?,'loan',?,?,1,?)
            ")->execute([$supplier_id,$first_batch_db_id,$loan_payable,$notes,$_SESSION['user_id']]);
        } elseif($loan_payable < -0.005){
            // Overpaid — first reduce existing deferred debt, then record any remainder as advance
            $overpaid = round(-$loan_payable, 2);
            $defStmt  = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN type='loan' AND is_deferred=1 THEN amount WHEN type='repayment' AND is_deferred=1 THEN -amount ELSE 0 END),0) FROM supplier_loans WHERE supplier_id=?");
            $defStmt->execute([$supplier_id]);
            $cur_deferred = (float)$defStmt->fetchColumn();
            if($cur_deferred > 0.005){
                $deferred_repay = min($overpaid, round($cur_deferred, 2));
                $pdo->prepare("
                    INSERT INTO supplier_loans (supplier_id,batch_id,type,amount,notes,is_deferred,created_by)
                    VALUES (?,?,'repayment',?,?,1,?)
                ")->execute([$supplier_id,$first_batch_db_id,$deferred_repay,'Offset from overpayment',$_SESSION['user_id']]);
                $overpaid = round($overpaid - $deferred_repay, 2);
            }
            if($overpaid > 0.005){
                $pdo->prepare("
                    INSERT INTO supplier_loans (supplier_id,batch_id,type,amount,notes,is_deferred,created_by)
                    VALUES (?,?,'loan',?,?,0,?)
                ")->execute([$supplier_id,$first_batch_db_id,$overpaid,'Advance from overpayment',$_SESSION['user_id']]);
            }
        }

        // Debit each payment account and record in purchase_payments
        $stmtDebit = $pdo->prepare("SELECT balance, account_name FROM company_accounts WHERE id=? AND is_active=1 FOR UPDATE");
        $stmtUpdBal = $pdo->prepare("UPDATE company_accounts SET balance=? WHERE id=?");
        $stmtAcctTxn = $pdo->prepare("
            INSERT INTO account_transactions
              (account_id,txn_type,amount,balance_after,reference_type,reference_id,description,created_by)
            VALUES (?,'debit',?,?,'purchase',?,?,?)
        ");
        $stmtPayment = $pdo->prepare("
            INSERT INTO purchase_payments (batch_id,supplier_id,payment_method,account_id,amount,created_by)
            VALUES (?,?,?,?,?,?)
        ");
        foreach($valid_payments as $vp){
            $stmtPayment->execute([
                $first_batch_db_id, $supplier_id,
                $vp['method'], $vp['account_id'] ?: null,
                $vp['amount'], $_SESSION['user_id']
            ]);
            if($vp['account_id']){
                $stmtDebit->execute([$vp['account_id']]);
                $acct = $stmtDebit->fetch();
                if(!$acct) throw new Exception("Payment account #{$vp['account_id']} not found or inactive.");
                if($acct['balance'] < $vp['amount'])
                    throw new Exception("Insufficient balance in \"{$acct['account_name']}\". Available: ".number_format($acct['balance'],2)." FRW, requested: ".number_format($vp['amount'],2)." FRW.");
                $newBal = round($acct['balance'] - $vp['amount'], 2);
                $stmtUpdBal->execute([$newBal, $vp['account_id']]);
                $stmtAcctTxn->execute([
                    $vp['account_id'], $vp['amount'], $newBal,
                    $first_batch_db_id, 'Supplier payment', $_SESSION['user_id']
                ]);
            }
        }

        $pdo->commit();

        $cnt = count($created);
        echo json_encode(['success' => true, 'count' => $cnt]);
    } catch(Exception $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

/* ── Page data ───────────────────────────────────────────────── */
$page_title = 'New Purchase';

$minerals  = $pdo->query("SELECT * FROM mineral_types ORDER BY name")->fetchAll();
$suppliers = $pdo->query("SELECT * FROM suppliers ORDER BY name")->fetchAll();
$company_accounts = $pdo->query("SELECT id,account_type,account_name,balance FROM company_accounts WHERE is_active=1 ORDER BY account_type,account_name")->fetchAll();

/* Advance balance: only non-deferred records (matches what backend allows for repayment) */
$loan_bal_raw  = $pdo->query("SELECT supplier_id, COALESCE(SUM(CASE WHEN type='loan' THEN amount ELSE -amount END),0) AS balance FROM supplier_loans WHERE is_deferred=0 GROUP BY supplier_id")->fetchAll(PDO::FETCH_ASSOC);
$loan_balances = [];
foreach($loan_bal_raw as $lb) $loan_balances[(int)$lb['supplier_id']] = (float)$lb['balance'];

/* Deferred balance: outstanding mineral debt (loans minus payments already made) */
$deferred_bal_raw = $pdo->query("SELECT supplier_id, COALESCE(SUM(CASE WHEN type='loan' AND is_deferred=1 THEN amount WHEN type='repayment' AND is_deferred=1 THEN -amount ELSE 0 END),0) AS deferred FROM supplier_loans GROUP BY supplier_id")->fetchAll(PDO::FETCH_ASSOC);
$deferred_balances = [];
foreach($deferred_bal_raw as $db) $deferred_balances[(int)$db['supplier_id']] = (float)$db['deferred'];

$special_minerals = [
    ['cat' => 'cassiterite', 'name' => 'Cassiterite', 'id' => null],
    ['cat' => 'coltan',      'name' => 'Coltan',       'id' => null],
    ['cat' => 'wolframite',  'name' => 'Wolframite',   'id' => null],
];
$_keys = ['cassiterite' => 'cassiterite', 'coltan' => 'coltan', 'wolframite' => 'wolfram'];
foreach($special_minerals as &$_sm)
    foreach($minerals as $_m)
        if(strpos(strtolower($_m['name']), $_keys[$_sm['cat']]) !== false){ $_sm['id'] = $_m['id']; break; }
unset($_sm);

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-plus-circle" style="margin-right:.4rem;color:var(--text-muted)"></i>New Purchase</h2>
    <a href="batches.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Batches
    </a>
</div>

<form id="batch-form" style="max-width:960px">

    <!-- Delivery info -->
    <div style="border:1px solid var(--border);border-radius:8px;padding:1.25rem;margin-bottom:1rem;background:var(--surface,var(--bg))">
        <div style="font-weight:600;font-size:.82rem;margin-bottom:.85rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
            <i class="fas fa-truck-ramp-box"></i> Delivery Info
        </div>
        <div class="form-grid form-grid-2">
            <div class="form-group">
                <label>Supplier</label>
                <select name="supplier_id" required onchange="onSupplierChange(this.value)">
                    <option value="">— Select supplier —</option>
                    <?php foreach($suppliers as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <div id="deferred-balance-info" style="display:none;margin-top:.45rem;padding:.4rem .65rem;border-radius:6px;font-size:.8rem;font-weight:600;background:#fff7ed;color:#ea580c;border:1px solid #fed7aa">
                    <i class="fas fa-hourglass-half"></i> <span id="deferred-balance-text"></span>
                    &nbsp;·&nbsp;<a href="#" id="deferred-balance-link" style="color:#ea580c;font-size:.75rem">View details</a>
                </div>
            </div>
            <div class="form-group">
                <label>Received Date</label>
                <input type="date" name="received_date" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label>Payment Currency</label>
                <select name="currency" id="currency-select" onchange="onCurrencyChange()">
                    <option value="FRW">FRW — Rwandan Franc</option>
                    <option value="USD">USD — US Dollar</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Minerals -->
    <div style="border:1px solid var(--border);border-radius:8px;padding:1.25rem;margin-bottom:1rem;background:var(--surface,var(--bg))">
        <div style="font-weight:600;font-size:.82rem;margin-bottom:.85rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
            <i class="fas fa-gem"></i> Minerals in this Delivery
        </div>
        <div id="mineral-checks" style="display:flex;gap:.6rem;flex-wrap:wrap;margin-bottom:1rem">
            <?php foreach($special_minerals as $m): ?>
            <label style="display:flex;align-items:center;gap:.4rem;padding:.4rem .85rem;border:1px solid var(--border);border-radius:6px;cursor:pointer;user-select:none;font-weight:500">
                <input type="checkbox" value="<?= $m['id'] ?>" data-cat="<?= $m['cat'] ?>" data-name="<?= htmlspecialchars($m['name']) ?>" onchange="toggleMineralCard(this)">
                <?= htmlspecialchars($m['name']) ?>
            </label>
            <?php endforeach; ?>
        </div>
        <div id="mineral-cards" style="display:flex;flex-direction:column;gap:1rem"></div>

        <!-- Payment Summary -->
        <div id="global-summary" style="display:none;margin-top:1rem">
            <div style="border:2px solid var(--primary);border-radius:8px;padding:1rem">
                <div style="font-weight:700;font-size:.9rem;margin-bottom:.75rem;color:var(--primary)">
                    <i class="fas fa-receipt"></i> Payment Summary
                </div>
                <div id="global-summary-body"></div>
            </div>
        </div>

        <!-- Loan / Repayment -->
        <div id="loan-section" style="border:1px solid var(--border);border-radius:8px;padding:1.25rem;margin-top:.75rem;background:var(--surface,var(--bg));display:none">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
                <span style="font-weight:600;font-size:.88rem"><i class="fas fa-hand-holding-dollar" style="margin-right:.35rem"></i>Loan / Repayment</span>
                <span id="loan-balance-badge" style="font-size:.78rem;padding:.2rem .65rem;border-radius:12px;font-weight:600"></span>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:.6rem .9rem">
                <div class="form-group" style="margin:0">
                    <label>Action</label>
                    <select name="loan_type" id="loan-type-select" onchange="toggleLoanFields()">
                        <option value="">— No action —</option>
                        <option value="loan">Give / Increase Loan</option>
                        <option value="repayment">Deduct Repayment</option>
                    </select>
                </div>
                <div class="form-group" id="loan-amount-group" style="margin:0;display:none">
                    <label>Amount (FRW)</label>
                    <input type="text" name="loan_amount" id="loan-amount" placeholder="0" oninput="checkBatchLoanLimit();updateGlobalSummary()">
                    <div id="batch-loan-warning" style="display:none;margin-top:.35rem;font-size:.8rem;color:#dc2626">
                        <i class="fas fa-triangle-exclamation"></i> <span id="batch-loan-warning-text"></span>
                    </div>
                </div>
                <div class="form-group" id="loan-notes-group" style="margin:0;display:none">
                    <label>Loan Notes</label>
                    <input type="text" name="loan_notes" placeholder="Optional…">
                </div>
            </div>
        </div>

        <!-- Multi-payment section -->
        <div id="payment-tracking" style="display:none;margin-top:.75rem;border:1px solid var(--border);border-radius:8px;padding:1rem;background:var(--surface,var(--bg))">
            <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.75rem">
                <span style="font-weight:600;font-size:.82rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
                    <i class="fas fa-money-bill-wave"></i> Payment Details
                </span>
                <button type="button" onclick="addPaymentRow()" style="background:none;border:1px dashed var(--border);cursor:pointer;color:var(--primary);font-size:.8rem;padding:.25rem .65rem;border-radius:6px;font-weight:600">
                    <i class="fas fa-plus"></i> Add Method
                </button>
            </div>

            <!-- Column headers -->
            <div style="display:grid;grid-template-columns:140px 1fr 130px 130px 32px;gap:.4rem;margin-bottom:.3rem;padding:0 .1rem">
                <span style="font-size:.75rem;font-weight:600;color:var(--text-muted)">Method</span>
                <span style="font-size:.75rem;font-weight:600;color:var(--text-muted)">Account</span>
                <span style="font-size:.75rem;font-weight:600;color:var(--text-muted)">Acct Balance</span>
                <span style="font-size:.75rem;font-weight:600;color:var(--text-muted)">Amount Paid (FRW)</span>
                <span></span>
            </div>

            <div id="payment-rows"></div>

            <!-- Totals -->
            <div style="border-top:1px solid var(--border);margin-top:.6rem;padding-top:.6rem;display:flex;flex-direction:column;gap:.3rem;align-items:flex-end">
                <div style="display:flex;gap:1.5rem;align-items:center;font-size:.88rem">
                    <span style="color:var(--text-muted)">Total Paid</span>
                    <span id="total-paid-display" style="font-family:monospace;font-weight:700;min-width:140px;text-align:right">0.00 FRW</span>
                </div>
                <div style="display:flex;gap:1.5rem;align-items:center;font-size:.88rem">
                    <span id="loan-payable-label" style="color:var(--text-muted)">Loan Payable</span>
                    <span id="loan-payable-display" style="font-family:monospace;font-weight:700;color:var(--text-muted);min-width:140px;text-align:right">—</span>
                </div>
            </div>
            <div id="advance-offset-warn" style="display:none;margin-top:.6rem;padding:.55rem .75rem;border-radius:6px;background:#fff7ed;border:1px solid #fed7aa;font-size:.82rem;color:#92400e">
                <i class="fas fa-triangle-exclamation" style="color:#f59e0b;margin-right:.35rem"></i>
                <span id="advance-offset-warn-text"></span>
                &nbsp;·&nbsp;
                <a href="#" style="color:#ea580c;font-weight:600;text-decoration:none"
                   onclick="event.preventDefault();document.getElementById('loan-type-select').value='repayment';toggleLoanFields();document.getElementById('loan-amount').focus()">
                   Apply it now
                </a>
            </div>
        </div>
    </div>

    <!-- Notes -->
    <div style="border:1px solid var(--border);border-radius:8px;padding:1.25rem;margin-bottom:1.25rem;background:var(--surface,var(--bg))">
        <div class="form-group" style="margin:0">
            <label>Comment</label>
            <textarea name="notes" placeholder="Optional notes…"></textarea>
        </div>
    </div>

    <!-- Actions -->
    <div style="display:flex;gap:.75rem;justify-content:flex-end;padding-bottom:2rem">
        <a href="batches.php" class="btn btn-secondary">
            <i class="fas fa-xmark"></i> Cancel
        </a>
        <button type="submit" id="batch-save-btn" class="btn btn-primary">
            <i class="fas fa-save"></i> Save Purchase
        </button>
    </div>

</form>

<script>
const loanBalances      = <?= json_encode($loan_balances) ?>;
const deferredBalances  = <?= json_encode($deferred_balances) ?>;
const companyAccounts   = <?= json_encode(array_values($company_accounts)) ?>;

const cardCats    = {};
const cardNames   = {};
const cardSummary = {};
let currentNetToPay = 0;

const CARD_COLORS = { cassiterite: '#3b82f6', coltan: '#8b5cf6', wolframite: '#10b981' };

function onSupplierChange(suppId) {
    updateLoanBadge(suppId);
    updateDeferredBadge(suppId);
    checkBatchLoanLimit();
}

function updateDeferredBadge(suppId) {
    const info    = document.getElementById('deferred-balance-info');
    const textEl  = document.getElementById('deferred-balance-text');
    const linkEl  = document.getElementById('deferred-balance-link');
    if (!suppId) { info.style.display = 'none'; return; }
    const def = deferredBalances[suppId] || 0;
    if (def > 0) {
        textEl.textContent = 'Deferred Mineral Payments: ' + def.toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' FRW owed to this supplier';
        linkEl.href = 'loans-payable.php?supplier_id=' + suppId;
        info.style.display = '';
    } else {
        info.style.display = 'none';
    }
}

function updateLoanBadge(suppId) {
    const section = document.getElementById('loan-section');
    const badge   = document.getElementById('loan-balance-badge');
    if (!suppId) { section.style.display = 'none'; return; }
    section.style.display = '';
    const bal = loanBalances[suppId] || 0;
    if (bal > 0) {
        badge.textContent = 'Outstanding: ' + bal.toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' FRW';
        badge.style.cssText = 'background:#fef2f2;color:#dc2626;font-size:.78rem;padding:.2rem .65rem;border-radius:12px;font-weight:600';
    } else {
        badge.textContent = 'No outstanding loan';
        badge.style.cssText = 'background:#f0fdf4;color:#16a34a;font-size:.78rem;padding:.2rem .65rem;border-radius:12px;font-weight:600';
    }
}

function toggleLoanFields() {
    const val = document.getElementById('loan-type-select').value;
    document.getElementById('loan-amount-group').style.display = val ? '' : 'none';
    document.getElementById('loan-notes-group').style.display  = val ? '' : 'none';
    checkBatchLoanLimit();
    updateGlobalSummary();
}

function checkBatchLoanLimit() {
    const suppId  = document.querySelector('[name="supplier_id"]').value;
    const type    = document.getElementById('loan-type-select').value;
    const amount  = parseFloat(document.getElementById('loan-amount').value) || 0;
    const warning = document.getElementById('batch-loan-warning');
    const wtext   = document.getElementById('batch-loan-warning-text');

    if (type !== 'repayment' || !suppId) { warning.style.display = 'none'; return; }

    const bal = parseFloat(loanBalances[suppId] || 0);
    if (bal <= 0) {
        wtext.textContent = 'This supplier has no outstanding loan to repay.';
        warning.style.display = '';
    } else if (amount > bal) {
        wtext.textContent = 'Exceeds outstanding balance of ' + bal.toLocaleString('en-US', { minimumFractionDigits: 2 }) + ' FRW.';
        warning.style.display = '';
    } else {
        warning.style.display = 'none';
    }
}

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function showAlert(type, msg) {
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-' + type + ' mb-15';
    el.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'circle-check' : 'circle-xmark') + '"></i> ' + msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 6000);
}

/* ── Card builder ───────────────────────────────────────────── */
function buildCard(id, name, cat) {
    const color = CARD_COLORS[cat] || 'var(--primary)';
    let fields = '';

    if (cat === 'cassiterite') {
        fields = `
            <div class="form-group"><label>LME Price (RWF)</label><input type="text" id="c${id}-lma" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Quantity (kg)</label><input type="text" name="mineral[${id}][quantity]" id="c${id}-qty" placeholder="0.000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Sample (%)</label><input type="text" id="c${id}-sample" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RWF Rate</label><input type="text" id="c${id}-rwfrate" value="1460" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Company Fees 1 (FRW)</label><input type="text" id="c${id}-fees1" value="2500" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Fees 2 (FRW)</label><input type="text" id="c${id}-fees2" value="3000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Tag (FRW)</label><input type="text" id="c${id}-tag" value="2000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RMA (FRW)</label><input type="text" id="c${id}-rma" value="70" oninput="calcCard(${id})"></div>`;
    } else if (cat === 'coltan') {
        fields = `
            <div class="form-group"><label>TANTAL (USD)</label><input type="text" id="c${id}-tantal" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Quantity (kg)</label><input type="text" name="mineral[${id}][quantity]" id="c${id}-qty" placeholder="0.000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Sample</label><input type="text" id="c${id}-sample" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RWF Rate</label><input type="text" id="c${id}-rwfrate" value="1460" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Tag (FRW)</label><input type="text" id="c${id}-tag" value="2000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RMA (FRW)</label><input type="text" id="c${id}-rma" value="190" oninput="calcCard(${id})"></div>`;
    } else {
        fields = `
            <div class="form-group"><label>TMT Price (USD)</label><input type="text" id="c${id}-tmt" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Quantity (kg)</label><input type="text" name="mineral[${id}][quantity]" id="c${id}-qty" placeholder="0.000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Sample</label><input type="text" id="c${id}-sample" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RWF Rate</label><input type="text" id="c${id}-rwfrate" value="1460" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Tag (FRW)</label><input type="text" id="c${id}-tag" value="2000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RMA (FRW)</label><input type="text" id="c${id}-rma" value="90" oninput="calcCard(${id})"></div>`;
    }

    return `<div id="card-${id}" style="border:1px solid ${color}44;border-left:4px solid ${color};border-radius:8px;padding:1rem;background:var(--surface,var(--bg))">
        <input type="hidden" name="mineral[${id}][price_per_unit]" id="c${id}-price">
        <input type="hidden" name="mineral[${id}][sample]"    id="c${id}-h-sample">
        <input type="hidden" name="mineral[${id}][rwf_rate]"  id="c${id}-h-rwf_rate">
        <input type="hidden" name="mineral[${id}][fees_1]"    id="c${id}-h-fees_1">
        <input type="hidden" name="mineral[${id}][fees_2]"    id="c${id}-h-fees_2">
        <input type="hidden" name="mineral[${id}][tag]"       id="c${id}-h-tag">
        <input type="hidden" name="mineral[${id}][rma]"       id="c${id}-h-rma">
        <input type="hidden" name="mineral[${id}][rra]"       id="c${id}-h-rra">
        <input type="hidden" name="mineral[${id}][lma]"       id="c${id}-h-lma">
        <input type="hidden" name="mineral[${id}][tmt]"       id="c${id}-h-tmt">
        <input type="hidden" name="mineral[${id}][tantal]"    id="c${id}-h-tantal">
        <input type="hidden" name="mineral[${id}][take_home]" id="c${id}-h-take_home">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem">
            <div style="font-weight:700;font-size:.9rem;color:${color}"><i class="fas fa-gem"></i> ${esc(name)}</div>
            <button type="button" onclick="collapseCard(${id})"
                style="background:none;border:1px solid ${color}55;cursor:pointer;color:${color};font-size:.78rem;padding:.2rem .5rem;border-radius:4px;line-height:1">
                <i class="fas fa-chevron-up" id="c${id}-chevron"></i>
            </button>
        </div>
        <div id="c${id}-body">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem .9rem">${fields}</div>
            <div id="c${id}-breakdown" style="display:none;margin-top:.7rem;border-top:1px solid ${color}33;padding-top:.6rem">
                <table style="width:100%;font-size:.82rem;border-collapse:collapse">
                    <tbody id="c${id}-rows"></tbody>
                </table>
            </div>
        </div>
    </div>`;
}

function collapseCard(id) {
    const body    = document.getElementById('c' + id + '-body');
    const chevron = document.getElementById('c' + id + '-chevron');
    if (!body) return;
    const collapsed = body.style.display === 'none';
    body.style.display  = collapsed ? '' : 'none';
    chevron.className   = collapsed ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
}

function saveCardState(id) {
    const cat = cardCats[id];
    if (!cat) return;
    const fields = { cassiterite: ['lma','rwfrate','fees1','fees2','tag','rma'], coltan: ['tantal','rwfrate','tag','rma'], wolframite: ['tmt','rwfrate','tag','rma'] };
    const state  = {};
    (fields[cat] || []).forEach(f => { const el = document.getElementById('c'+id+'-'+f); if(el) state[f] = el.value; });
    localStorage.setItem('batch_card_' + cat, JSON.stringify(state));
}

function restoreCardState(id, cat) {
    const raw = localStorage.getItem('batch_card_' + cat);
    if (!raw) return;
    try {
        const state = JSON.parse(raw);
        Object.keys(state).forEach(f => { const el = document.getElementById('c'+id+'-'+f); if(el) el.value = state[f]; });
        calcCard(id);
    } catch(e) {}
}

function toggleMineralCard(cb) {
    const id = cb.value, name = cb.dataset.name, cat = cb.dataset.cat;
    if (cb.checked) {
        cardCats[id] = cat; cardNames[id] = name;
        document.getElementById('mineral-cards').insertAdjacentHTML('beforeend', buildCard(id, name, cat));
        restoreCardState(id, cat);
    } else {
        delete cardCats[id]; delete cardNames[id]; delete cardSummary[id];
        const card = document.getElementById('card-' + id);
        if (card) card.remove();
        updateGlobalSummary();
    }
}

/* ── Per-card calculation ───────────────────────────────────── */
function fmtRWF(v) { return v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' FRW'; }
function fmtUSD(v) { return '$' + v.toFixed(4); }

function calcCard(id) {
    const cat      = cardCats[id];
    const qty      = parseFloat(document.getElementById('c'+id+'-qty').value) || 0;
    const currency = document.getElementById('currency-select')?.value || 'FRW';
    let unitPrice  = 0, rows = [], rwfRateCard = 0;
    let _s = 0, _rwfr = 0, _f1 = 0, _f2 = 0, _tag = 0, _rma = 0, _rra = 0, _lma = 0, _tmt = 0, _tantal = 0, _th = 0;

    function fmtPay(rwfVal) {
        if (currency === 'USD' && rwfRateCard > 0) return fmtUSD(rwfVal / rwfRateCard);
        return fmtRWF(rwfVal);
    }

    if (cat === 'cassiterite') {
        const lma     = parseFloat(document.getElementById('c'+id+'-lma').value)     || 0;
        const sample  = parseFloat(document.getElementById('c'+id+'-sample').value)  || 0;
        const fees1   = parseFloat(document.getElementById('c'+id+'-fees1').value)   || 0;
        const fees2   = parseFloat(document.getElementById('c'+id+'-fees2').value)   || 0;
        const rwfRate = parseFloat(document.getElementById('c'+id+'-rwfrate').value) || 0;
        const tag     = parseFloat(document.getElementById('c'+id+'-tag').value)     || 0;
        const rma     = parseFloat(document.getElementById('c'+id+'-rma').value)     || 0;
        rwfRateCard   = rwfRate;

        const rra      = (((((lma * sample) / 100) - 800) * 0.03)/1000)*rwfRate;
        const usd      = ((((lma - fees1) * sample) / 100) - fees2) / 1000;
        const rwf      = usd * rwfRate;
        const up       = rwf - tag - rma - rra;
        unitPrice = up;
        const takeHome = up * qty;
        [_s,_rwfr,_f1,_f2,_tag,_rma,_rra,_lma,_th] = [sample,rwfRate,fees1,fees2,tag,rma,rra,lma,takeHome];

        rows = [
            ['(LMA − Fees1) × Sample / 100', fmtRWF(((lma-fees1)*sample)/100)],
            ['− Fees 2',                     fmtRWF(fees2)],
            ['÷ 1,000 → USD',               fmtUSD(usd)],
            ['× RWF Rate',                   rwfRate.toLocaleString()],
            ['= RWF',                        fmtRWF(rwf), true],
            null,
            ['RRA = (((LMA×sample/100 − 800) × 3%)/1000)×rwfRate', fmtRWF(rra)],
            ['− Tag',                        fmtRWF(tag)],
            ['− RMA',                        fmtRWF(rma)],
            ['− RRA',                        fmtRWF(rra)],
            ['= Unit Price / kg ('+currency+')', fmtPay(up), true],
            null,
            ['× Quantity',                   qty.toFixed(3)+' kg'],
            ['= Take Home ('+currency+')',   fmtPay(takeHome), true],
        ];
    } else if (cat === 'coltan') {
        const tantal  = parseFloat(document.getElementById('c'+id+'-tantal').value)  || 0;
        const sample  = parseFloat(document.getElementById('c'+id+'-sample').value)  || 0;
        const rwfRate = parseFloat(document.getElementById('c'+id+'-rwfrate').value) || 0;
        const tag     = parseFloat(document.getElementById('c'+id+'-tag').value)     || 0;
        const rma     = parseFloat(document.getElementById('c'+id+'-rma').value)     || 0;
        rwfRateCard   = rwfRate;

        const rwf_tantal   = tantal * rwfRate;
        const global_total = rwf_tantal * sample;
        const rra_rwf      = (global_total * 0.33) / 10;
        const up           = global_total - tag - rma - rra_rwf;
        unitPrice = up;
        const takeHome = up * qty;
        [_s,_rwfr,_tag,_rma,_rra,_tantal,_th] = [sample,rwfRate,tag,rma,rra_rwf,tantal,takeHome];

        rows = [
            ['TANTAL (USD)',                          fmtUSD(tantal)],
            ['× RWF Rate',                            rwfRate.toLocaleString()],
            ['= RWF/TANTAL',                          fmtRWF(rwf_tantal), true],
            ['× Sample',                              sample],
            ['= Global Total',                        fmtRWF(global_total), true],
            ['RRA = (Global Total × 0.33) ÷ 10',     fmtRWF(rra_rwf)],
            null,
            ['Global Total',                          fmtRWF(global_total)],
            ['− Tag',                                 fmtRWF(tag)],
            ['− RMA',                                 fmtRWF(rma)],
            ['− RRA',                                 fmtRWF(rra_rwf)],
            ['= Unit Price / kg ('+currency+')',       fmtPay(up), true],
            null,
            ['× Quantity',                            qty.toFixed(3)+' kg'],
            ['= Take Home ('+currency+')',             fmtPay(takeHome), true],
        ];
    } else if (cat === 'wolframite') {
        const tmt     = parseFloat(document.getElementById('c'+id+'-tmt').value)     || 0;
        const sample  = parseFloat(document.getElementById('c'+id+'-sample').value)  || 0;
        const rwfRate = parseFloat(document.getElementById('c'+id+'-rwfrate').value) || 0;
        const tag     = parseFloat(document.getElementById('c'+id+'-tag').value)     || 0;
        const rma     = parseFloat(document.getElementById('c'+id+'-rma').value)     || 0;
        rwfRateCard   = rwfRate;

        const rwf_tmt      = tmt * rwfRate;
        const global_total = (rwf_tmt * sample)/1000;
        const rra_rwf      = global_total * 0.03;
        const up           = global_total - tag - rma - rra_rwf;
        unitPrice = up;
        const takeHome = up * qty;
        [_s,_rwfr,_tag,_rma,_rra,_tmt,_th] = [sample,rwfRate,tag,rma,rra_rwf,tmt,takeHome];

        rows = [
            ['TMT (USD)',                            fmtUSD(tmt)],
            ['× RWF Rate',                           rwfRate.toLocaleString()],
            ['= RWF/TMT',                            fmtRWF(rwf_tmt), true],
            ['× Sample',                             sample],
            ['= Global Total',                       fmtRWF(global_total), true],
            ['RRA = Global Total × 0.03',            fmtRWF(rra_rwf)],
            null,
            ['Global Total',                         fmtRWF(global_total)],
            ['− Tag',                                fmtRWF(tag)],
            ['− RMA',                                fmtRWF(rma)],
            ['− RRA',                                fmtRWF(rra_rwf)],
            ['= Unit Price / kg ('+currency+')',      fmtPay(up), true],
            null,
            ['× Quantity',                           qty.toFixed(3)+' kg'],
            ['= Take Home ('+currency+')',            fmtPay(takeHome), true],
        ];
    }

    const priceEl   = document.getElementById('c'+id+'-price');
    const breakdown = document.getElementById('c'+id+'-breakdown');
    const tbody     = document.getElementById('c'+id+'-rows');

    const storedPrice = (currency === 'USD' && rwfRateCard > 0) ? unitPrice / rwfRateCard : unitPrice;
    if (priceEl) priceEl.value = storedPrice > 0 ? storedPrice.toFixed(6) : '';

    if (rows.length && breakdown && tbody) {
        tbody.innerHTML = rows.map(r => {
            if (!r) return '<tr><td colspan="2"><hr style="border:none;border-top:1px solid var(--border);margin:.2rem 0"></td></tr>';
            const b  = r[2] ? 'font-weight:700;' : '';
            const bg = r[2] ? 'background:rgba(var(--primary-rgb,37,99,235),.07);' : '';
            return `<tr style="${bg}">
                <td style="${b}padding:.22rem .5rem;color:var(--text-muted)">${esc(r[0])}</td>
                <td style="${b}padding:.22rem .5rem;text-align:right;font-family:monospace">${esc(String(r[1]||''))}</td>
            </tr>`;
        }).join('');
        breakdown.style.display = '';
    }

    // Write all calculator values to hidden fields for server-side storage
    const _hmap = { sample:_s, rwf_rate:_rwfr, fees_1:_f1, fees_2:_f2, tag:_tag, rma:_rma, rra:_rra, lma:_lma, tmt:_tmt, tantal:_tantal, take_home:_th };
    Object.entries(_hmap).forEach(([f,v]) => { const el = document.getElementById('c'+id+'-h-'+f); if(el) el.value = v || ''; });

    cardSummary[id] = { takeHome_rwf: unitPrice * qty, rwfRate: rwfRateCard };
    updateGlobalSummary();
    saveCardState(id);
}

/* ── Payment summary ────────────────────────────────────────── */
function updateGlobalSummary() {
    const el   = document.getElementById('global-summary');
    const body = document.getElementById('global-summary-body');
    if (!el || !body) return;

    const ids = Object.keys(cardSummary);
    if (ids.length === 0) {
        el.style.display = 'none';
        const pt = document.getElementById('payment-tracking');
        if (pt) pt.style.display = 'none';
        currentNetToPay = 0;
        return;
    }
    const wasHidden = el.style.display === 'none';
    el.style.display = '';

    const currency  = document.getElementById('currency-select')?.value || 'FRW';
    const firstRate = cardSummary[ids[0]]?.rwfRate || 0;

    function fmtAmt(v) {
        if (currency === 'USD' && firstRate > 0)
            return '$' + (v / firstRate).toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
        return v.toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 }) + ' FRW';
    }

    let subtotal = 0, html = '';
    ids.forEach(id => {
        const { takeHome_rwf } = cardSummary[id];
        subtotal += takeHome_rwf;
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;font-size:.85rem;border-bottom:1px solid var(--border)">
            <span style="color:var(--text-muted)">${esc(cardNames[id] || 'Mineral')}</span>
            <span style="font-family:monospace;font-weight:600">${fmtAmt(takeHome_rwf)}</span>
        </div>`;
    });

    if (ids.length > 1) {
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.35rem 0;font-size:.85rem">
            <span style="font-weight:600">Subtotal</span>
            <span style="font-family:monospace;font-weight:600">${fmtAmt(subtotal)}</span>
        </div>`;
    }

    const loanType   = document.getElementById('loan-type-select')?.value || '';
    const loanAmount = parseFloat(document.getElementById('loan-amount')?.value) || 0;
    let net = subtotal;

    if (loanType === 'loan' && loanAmount > 0) {
        net += loanAmount;
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;font-size:.85rem;color:#16a34a">
            <span><i class="fas fa-plus-circle"></i> Loan Given</span>
            <span style="font-family:monospace;font-weight:600">+ ${loanAmount.toLocaleString('en-US',{minimumFractionDigits:2})} FRW</span>
        </div>`;
    } else if (loanType === 'repayment' && loanAmount > 0) {
        net -= loanAmount;
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;font-size:.85rem;color:#dc2626">
            <span><i class="fas fa-minus-circle"></i> Loan Repayment</span>
            <span style="font-family:monospace;font-weight:600">− ${loanAmount.toLocaleString('en-US',{minimumFractionDigits:2})} FRW</span>
        </div>`;
    }

    html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0 0;margin-top:.3rem;border-top:2px solid var(--primary)">
        <span style="font-weight:700;font-size:.95rem">NET TO PAY</span>
        <span style="font-family:monospace;font-weight:700;font-size:1.05rem;color:var(--primary)">${fmtAmt(net)}</span>
    </div>`;

    const suppId2     = document.querySelector('[name="supplier_id"]')?.value || '';
    const curDeferred = parseFloat(deferredBalances[suppId2] || 0);
    const curAdvance  = parseFloat(loanBalances[suppId2] || 0);
    const curNet      = curDeferred - curAdvance;
    if (Math.abs(curNet) > 0.005 || curDeferred > 0.005 || curAdvance > 0.005) {
        const netOwed    = curNet > 0.005;
        const netBg      = netOwed ? '#fff7ed' : '#eff6ff';
        const netBorder  = netOwed ? '#fed7aa' : '#bfdbfe';
        const netColor   = netOwed ? '#92400e'  : '#1d4ed8';
        const netIcon    = netOwed ? 'hourglass-half' : 'hand-holding-dollar';
        const netLabel   = netOwed ? 'Net Owed to Supplier' : 'Net Supplier Owes Us';
        const netAmt     = Math.abs(curNet);
        html += `<div style="margin-top:.65rem;padding:.55rem .75rem;border-radius:6px;background:${netBg};border:1px solid ${netBorder}">
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:.82rem;color:${netColor};margin-bottom:.25rem">
                <span><i class="fas fa-${netIcon}"></i> ${netLabel}</span>
                <span style="font-family:monospace;font-weight:700">${netAmt.toLocaleString('en-US',{minimumFractionDigits:2})} FRW</span>
            </div>
            <div style="display:flex;justify-content:space-between;align-items:center;font-size:.82rem;color:${netColor}">
                <span style="opacity:.85"><i class="fas fa-arrow-right"></i> After This Save</span>
                <span id="projected-net-val" style="font-family:monospace;font-weight:700">—</span>
            </div>
        </div>`;
    }

    body.innerHTML = html;

    currentNetToPay = net;
    const payTrack = document.getElementById('payment-tracking');
    if (payTrack) {
        const firstShow = payTrack.style.display === 'none';
        payTrack.style.display = '';
        if (firstShow && document.getElementById('payment-rows').children.length === 0) {
            addPaymentRow('cash');
        }
    }
    recalcPayments();
}

function onCurrencyChange() {
    Object.keys(cardCats).forEach(id => calcCard(id));
}

/* ── Multi-payment rows ─────────────────────────────────────── */
let payRowCnt = 0;
const METHOD_LABELS = { cash: 'Cash', bank: 'Bank Transfer', momo: 'Mobile Money' };

function buildAccountOptions(method) {
    const filtered = companyAccounts.filter(a => a.account_type === method);
    if (!filtered.length) return '<option value="">— No accounts —</option>';
    let html = '<option value="">— Select account —</option>';
    filtered.forEach(a => {
        html += `<option value="${a.id}" data-balance="${a.balance}">${esc(a.account_name)}</option>`;
    });
    return html;
}

function addPaymentRow(defaultMethod) {
    const n   = ++payRowCnt;
    const row = document.createElement('div');
    row.id    = 'prow-' + n;
    row.style.cssText = 'display:grid;grid-template-columns:140px 1fr 130px 130px 32px;gap:.4rem .4rem;align-items:center;margin-bottom:.4rem';

    const methodOpts = ['cash','bank','momo'].map(m =>
        `<option value="${m}"${m === defaultMethod ? ' selected' : ''}>${METHOD_LABELS[m]}</option>`
    ).join('');

    const initMethod = defaultMethod || 'cash';
    const acctOpts   = buildAccountOptions(initMethod);

    row.innerHTML = `
        <select name="payments[${n}][method]" id="prow-${n}-method"
                style="width:100%;padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface,var(--bg));color:var(--text);font-size:.83rem"
                onchange="onRowMethodChange(${n})">
            ${methodOpts}
        </select>
        <select name="payments[${n}][account_id]" id="prow-${n}-acct"
                style="width:100%;padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface,var(--bg));color:var(--text);font-size:.83rem"
                onchange="onRowAcctChange(${n})">
            ${acctOpts}
        </select>
        <div id="prow-${n}-bal"
             style="padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;font-family:monospace;font-size:.8rem;font-weight:600;color:var(--text-muted);background:var(--border)22;text-align:right">—</div>
        <input type="text" name="payments[${n}][amount]" id="prow-${n}-amt"
               placeholder="0.00"
               style="width:100%;padding:.4rem .5rem;border:1px solid var(--border);border-radius:6px;background:var(--surface,var(--bg));color:var(--text);font-size:.83rem;text-align:right"
               oninput="checkRowBalance(${n});recalcPayments()">
        <button type="button" onclick="removePaymentRow(${n})"
                style="background:none;border:1px solid var(--border);border-radius:6px;cursor:pointer;color:var(--text-muted);width:32px;height:32px;display:flex;align-items:center;justify-content:center">
            <i class="fas fa-times" style="font-size:.75rem"></i>
        </button>
        <div id="prow-${n}-warn" style="display:none;grid-column:1/-1;font-size:.78rem;color:#dc2626;padding:.1rem 0 0">
            <i class="fas fa-triangle-exclamation"></i> <span id="prow-${n}-warn-text"></span>
        </div>`;

    document.getElementById('payment-rows').appendChild(row);

    // Auto-select account if only one available
    const acctSel = document.getElementById('prow-'+n+'-acct');
    const opts = [...acctSel.options].filter(o => o.value);
    if (opts.length === 1) { acctSel.value = opts[0].value; }
    onRowAcctChange(n);
}

function removePaymentRow(n) {
    document.getElementById('prow-'+n)?.remove();
    recalcPayments();
}

function onRowMethodChange(n) {
    const method  = document.getElementById('prow-'+n+'-method').value;
    const acctSel = document.getElementById('prow-'+n+'-acct');
    acctSel.innerHTML = buildAccountOptions(method);
    const opts = [...acctSel.options].filter(o => o.value);
    if (opts.length === 1) { acctSel.value = opts[0].value; }
    onRowAcctChange(n);
}

function onRowAcctChange(n) {
    const acctSel = document.getElementById('prow-'+n+'-acct');
    const balEl   = document.getElementById('prow-'+n+'-bal');
    const opt     = acctSel.options[acctSel.selectedIndex];
    if (!acctSel.value || !opt) { balEl.textContent = '—'; balEl.style.color = 'var(--text-muted)'; return; }
    const bal = parseFloat(opt.dataset.balance || 0);
    balEl.textContent = bal.toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 }) + ' FRW';
    balEl.style.color = bal < 0 ? '#dc2626' : 'var(--text)';
    checkRowBalance(n);
}

function checkRowBalance(n) {
    const acctSel = document.getElementById('prow-'+n+'-acct');
    const amtInp  = document.getElementById('prow-'+n+'-amt');
    const warn    = document.getElementById('prow-'+n+'-warn');
    const wtext   = document.getElementById('prow-'+n+'-warn-text');
    const amtEl   = document.getElementById('prow-'+n+'-amt');
    if (!warn || !acctSel) return;

    if (!acctSel.value) { warn.style.display = 'none'; amtEl && (amtEl.style.borderColor = ''); return; }

    const opt = acctSel.options[acctSel.selectedIndex];
    const bal = parseFloat(opt?.dataset.balance || 0);
    const amt = parseFloat(amtInp?.value.replace(/,/g,'')) || 0;

    if (amt > 0 && amt > bal) {
        wtext.textContent = 'Insufficient balance. Available: ' + bal.toLocaleString('en-US',{minimumFractionDigits:2}) + ' FRW.';
        warn.style.display = '';
        amtEl.style.borderColor = '#dc2626';
    } else {
        warn.style.display = 'none';
        amtEl.style.borderColor = '';
    }
}

function recalcPayments() {
    let total = 0;
    document.querySelectorAll('[id^="prow-"][id$="-amt"]').forEach(inp => {
        total += parseFloat(inp.value.replace(/,/g,'')) || 0;
    });

    const totalEl = document.getElementById('total-paid-display');
    const loanEl  = document.getElementById('loan-payable-display');
    const loanLbl = document.getElementById('loan-payable-label');
    if (totalEl) totalEl.textContent = total.toLocaleString('en-US', { minimumFractionDigits:2 }) + ' FRW';

    const suppIdP = document.querySelector('[name="supplier_id"]')?.value || '';
    const lp      = currentNetToPay - total;

    const projNetEl = document.getElementById('projected-net-val');
    if (projNetEl) {
        const curDef     = parseFloat(deferredBalances[suppIdP] || 0);
        const curAdv     = parseFloat(loanBalances[suppIdP] || 0);
        const loanType   = document.getElementById('loan-type-select')?.value || '';
        const loanAmount = parseFloat(document.getElementById('loan-amount')?.value) || 0;
        const overpaid   = lp < -0.005 ? Math.abs(lp) : 0;
        // deferred after this batch
        let projDef = lp > 0.005 ? curDef + lp : Math.max(0, curDef - overpaid);
        // advance after this batch
        const advFromOver = Math.max(0, overpaid - curDef);
        let projAdv = curAdv + advFromOver;
        if (loanType === 'loan'      && loanAmount > 0) projAdv += loanAmount;
        if (loanType === 'repayment' && loanAmount > 0) projAdv -= loanAmount;
        projAdv = Math.max(0, projAdv);
        const projNet    = projDef - projAdv;
        const curNet     = curDef - curAdv;
        const netOwed    = projNet > 0.005;
        const netLabel   = netOwed ? ' owed to supplier' : ' supplier owes us';
        const projColor  = projNet < curNet - 0.005 ? '#16a34a' : projNet > curNet + 0.005 ? '#dc2626' : (netOwed ? '#92400e' : '#1d4ed8');
        projNetEl.textContent = Math.abs(projNet).toLocaleString('en-US',{minimumFractionDigits:2}) + ' FRW' + netLabel;
        projNetEl.style.color = projColor;
    }

    if (!loanEl) return;
    if (total <= 0) {
        loanEl.textContent = '—'; loanEl.style.color = 'var(--text-muted)';
        if (loanLbl) loanLbl.textContent = 'Loan Payable';
        return;
    }

    const loanPayable = currentNetToPay - total;
    const advWarn     = document.getElementById('advance-offset-warn');
    const advWarnText = document.getElementById('advance-offset-warn-text');
    const suppId      = document.querySelector('[name="supplier_id"]')?.value || '';
    const advBal      = parseFloat(loanBalances[suppId] || 0);
    const loanType    = document.getElementById('loan-type-select')?.value || '';

    if (loanPayable > 0.005) {
        if (loanLbl) loanLbl.textContent = 'Loan Payable';
        loanEl.textContent = loanPayable.toLocaleString('en-US', { minimumFractionDigits:2 }) + ' FRW';
        loanEl.style.color = '#dc2626';
        // Warn if there is an unused advance that could reduce this deferred amount
        if (advWarn && advBal > 0.005 && loanType !== 'repayment') {
            advWarnText.textContent = 'This supplier has a ' + advBal.toLocaleString('en-US', { minimumFractionDigits:2 }) + ' FRW advance that could offset the loan payable.';
            advWarn.style.display = '';
        } else if (advWarn) {
            advWarn.style.display = 'none';
        }
    } else if (loanPayable < -0.005) {
        if (loanLbl) loanLbl.textContent = 'Supplier Advance';
        const over = Math.abs(loanPayable);
        loanEl.textContent = over.toLocaleString('en-US', { minimumFractionDigits:2 }) + ' FRW → recorded as advance';
        loanEl.style.color = '#ea580c';
        if (advWarn) advWarn.style.display = 'none';
    } else {
        if (loanLbl) loanLbl.textContent = 'Loan Payable';
        loanEl.textContent = 'Fully Paid';
        loanEl.style.color = '#16a34a';
        if (advWarn) advWarn.style.display = 'none';
    }
}

/* ── Submit ─────────────────────────────────────────────────── */
document.getElementById('batch-form').addEventListener('submit', function (e) {
    e.preventDefault();

    if (Object.keys(cardCats).length === 0) {
        showAlert('error', 'Please select at least one mineral.');
        return;
    }
    if (document.getElementById('batch-loan-warning').style.display !== 'none') {
        showAlert('error', document.getElementById('batch-loan-warning-text').textContent);
        return;
    }

    const insufficientRows = [...document.querySelectorAll('[id^="prow-"][id$="-warn"]')]
        .filter(w => w.style.display !== 'none');
    if (insufficientRows.length > 0) {
        showAlert('error', 'One or more payment accounts have insufficient balance. Please correct before saving.');
        insufficientRows[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
        return;
    }

    const btn = document.getElementById('batch-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    fetch('new-purchase.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            window.location.href = 'batches.php?created=' + d.count;
        } else {
            showAlert('error', d.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save Purchase';
        }
    })
    .catch(() => {
        showAlert('error', 'Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Purchase';
    });
});
</script>

<?php include 'includes/footer.php'; ?>
