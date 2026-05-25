<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX handlers ───────────────────────────────────────────── */
if(isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');

    $fetchRec = function(int $id) use ($pdo): array {
        $q = $pdo->prepare("
            SELECT ss.*, s.name AS supplier_name, mt.name AS mineral_name,
                   u.username AS created_by_name,
                   uu.username AS updated_by_name,
                   GROUP_CONCAT(DISTINCT ssp.payment_method ORDER BY ssp.payment_method SEPARATOR ',') AS pay_methods
            FROM supply_stock ss
            JOIN suppliers s   ON s.id  = ss.supplier_id
            JOIN mineral_types mt ON mt.id = ss.mineral_id
            JOIN users u       ON u.id  = ss.created_by
            LEFT JOIN users uu ON uu.id = ss.updated_by
            LEFT JOIN supply_stock_payments ssp ON ssp.supply_stock_id = ss.id
            WHERE ss.id = ?
            GROUP BY ss.id
        ");
        $q->execute([$id]);
        return $q->fetch(PDO::FETCH_ASSOC);
    };

    /* ── Add ─────────────────────────────────────────────────── */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add'])){
        try {
            $payments_raw = $_POST['payments'] ?? [];
            $valid_payments = [];
            $total_advance  = 0;
            foreach($payments_raw as $p){
                $m   = in_array($p['method'] ?? '', ['cash','bank','momo']) ? $p['method'] : null;
                $amt = (float)($p['amount'] ?? 0);
                $aid = (int)($p['account_id'] ?? 0) ?: null;
                if(!$m || $amt <= 0) continue;
                $valid_payments[] = ['method'=>$m, 'account_id'=>$aid, 'amount'=>$amt];
                $total_advance   += $amt;
            }

            $pdo->beginTransaction();

            $pdo->prepare("
                INSERT INTO supply_stock (supplier_id, mineral_id, qty, advance_amount, status, notes, created_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([
                (int)$_POST['supplier_id'],
                (int)$_POST['mineral_id'],
                (float)$_POST['qty'],
                $total_advance,
                $_POST['status'],
                trim($_POST['notes'] ?? ''),
                $_SESSION['user_id']
            ]);
            $id = $pdo->lastInsertId();

            if($valid_payments){
                $stmtPay  = $pdo->prepare("INSERT INTO supply_stock_payments (supply_stock_id,payment_method,account_id,amount,created_by) VALUES (?,?,?,?,?)");
                $stmtDeb  = $pdo->prepare("SELECT balance, account_name FROM company_accounts WHERE id=? AND is_active=1 FOR UPDATE");
                $stmtUpd  = $pdo->prepare("UPDATE company_accounts SET balance=? WHERE id=?");
                $stmtTxn  = $pdo->prepare("INSERT INTO account_transactions (account_id,txn_type,amount,balance_after,reference_type,reference_id,description,created_by) VALUES (?,'DEBIT',?,?,'supply_stock',?,'Advance to supplier (supply stock)',?)");

                foreach($valid_payments as $vp){
                    $stmtPay->execute([$id, $vp['method'], $vp['account_id'], $vp['amount'], $_SESSION['user_id']]);
                    if($vp['account_id']){
                        $stmtDeb->execute([$vp['account_id']]);
                        $acct = $stmtDeb->fetch(PDO::FETCH_ASSOC);
                        if(!$acct) throw new Exception("Account #{$vp['account_id']} not found or inactive.");
                        if($acct['balance'] < $vp['amount'])
                            throw new Exception("Insufficient balance in \"{$acct['account_name']}\". Available: ".number_format($acct['balance'],2)." FRW, needed: ".number_format($vp['amount'],2)." FRW.");
                        $newBal = $acct['balance'] - $vp['amount'];
                        $stmtUpd->execute([$newBal, $vp['account_id']]);
                        $stmtTxn->execute([$vp['account_id'], $vp['amount'], $newBal, $id, $_SESSION['user_id']]);
                    }
                }

                /* single supplier_loans entry for the total advance */
                $pdo->prepare("
                    INSERT INTO supplier_loans (supplier_id, batch_id, type, amount, notes, is_deferred, created_by)
                    VALUES (?, NULL, 'loan', ?, 'Advance via supply stock entry', 0, ?)
                ")->execute([(int)$_POST['supplier_id'], $total_advance, $_SESSION['user_id']]);
            }

            $pdo->commit();
            logAction($pdo,$_SESSION['user_id'],'CREATE','supply_stock',$id,"Added supply stock entry");
            echo json_encode(['success'=>true,'message'=>'Supply stock entry added.','record'=>$fetchRec($id)]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* ── Edit ─────────────────────────────────────────────────── */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit'])){
        try {
            $uid = (int)$_POST['id'];
            $pdo->prepare("
                UPDATE supply_stock
                SET supplier_id=?, mineral_id=?, qty=?, status=?, notes=?, updated_by=?
                WHERE id=?
            ")->execute([
                (int)$_POST['supplier_id'],
                (int)$_POST['mineral_id'],
                (float)$_POST['qty'],
                $_POST['status'],
                trim($_POST['notes'] ?? ''),
                $_SESSION['user_id'],
                $uid
            ]);
            logAction($pdo,$_SESSION['user_id'],'UPDATE','supply_stock',$uid,"Updated supply stock entry");
            echo json_encode(['success'=>true,'message'=>'Entry updated.','record'=>$fetchRec($uid)]);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* ── Quick status change ─────────────────────────────────── */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['change_status'])){
        try {
            $uid    = (int)$_POST['id'];
            $status = $_POST['status'] ?? '';
            if(!in_array($status, ['in','out','sold'])) throw new Exception('Invalid status.');
            $pdo->prepare("UPDATE supply_stock SET status=?, updated_by=? WHERE id=?")
                ->execute([$status, $_SESSION['user_id'], $uid]);
            logAction($pdo,$_SESSION['user_id'],'UPDATE','supply_stock',$uid,"Quick status change to $status");
            echo json_encode(['success'=>true,'message'=>'Status changed to '.ucfirst($status).'.','status'=>$status]);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* ── Delete ───────────────────────────────────────────────── */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete'])){
        try {
            $pdo->prepare("DELETE FROM supply_stock WHERE id=?")->execute([(int)$_POST['id']]);
            logAction($pdo,$_SESSION['user_id'],'DELETE','supply_stock',(int)$_POST['id'],"Deleted supply stock entry");
            echo json_encode(['success'=>true,'message'=>'Entry deleted.']);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }
}

/* ── Page data ───────────────────────────────────────────────── */
$page_title = 'Supply Stock';

$filters = [
    'search'      => trim($_GET['search']      ?? ''),
    'status'      => $_GET['status']           ?? 'in',
    'supplier_id' => (int)($_GET['supplier_id'] ?? 0),
    'mineral_id'  => (int)($_GET['mineral_id']  ?? 0),
];

$where = []; $params = [];
if($filters['search']){
    $where[] = '(s.name LIKE ? OR mt.name LIKE ?)';
    $s = '%'.$filters['search'].'%';
    $params[] = $s; $params[] = $s;
}
if($filters['status'])      { $where[] = 'ss.status = ?';      $params[] = $filters['status']; }
if($filters['supplier_id']) { $where[] = 'ss.supplier_id = ?'; $params[] = $filters['supplier_id']; }
if($filters['mineral_id'])  { $where[] = 'ss.mineral_id = ?';  $params[] = $filters['mineral_id']; }
$where_sql = $where ? 'WHERE '.implode(' AND ',$where) : '';

$stmt = $pdo->prepare("
    SELECT ss.*, s.name AS supplier_name, mt.name AS mineral_name,
           u.username AS created_by_name, uu.username AS updated_by_name
    FROM supply_stock ss
    JOIN suppliers s   ON s.id  = ss.supplier_id
    JOIN mineral_types mt ON mt.id = ss.mineral_id
    JOIN users u       ON u.id  = ss.created_by
    LEFT JOIN users uu ON uu.id = ss.updated_by
    $where_sql
    ORDER BY ss.created_at DESC
");
$stmt->execute($params);
$stocks = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* payments per supply_stock entry */
$payments_by_ss = [];
if($stocks){
    $ids = implode(',', array_map('intval', array_column($stocks,'id')));
    $prows = $pdo->query("
        SELECT ssp.supply_stock_id, ssp.payment_method, ssp.account_id, ssp.amount,
               ca.account_name
        FROM supply_stock_payments ssp
        LEFT JOIN company_accounts ca ON ca.id = ssp.account_id
        WHERE ssp.supply_stock_id IN ($ids)
        ORDER BY ssp.id
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach($prows as $pr) $payments_by_ss[$pr['supply_stock_id']][] = $pr;
}

/* summary totals */
$totals = ['in'=>0,'out'=>0,'sold'=>0,'advance'=>0];
foreach($stocks as $r){
    $totals[$r['status']] += (float)$r['qty'];
    $totals['advance']    += (float)$r['advance_amount'];
}

$suppliers        = $pdo->query("SELECT id, name FROM suppliers ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$minerals         = $pdo->query("SELECT id, name FROM mineral_types ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$company_accounts = $pdo->query("SELECT id, account_type, account_name, balance FROM company_accounts WHERE is_active=1 ORDER BY account_type, account_name")->fetchAll(PDO::FETCH_ASSOC);

$has_filters = (bool)array_filter($filters);
include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-boxes-stacked" style="margin-right:.4rem;color:var(--text-muted)"></i>Supply Stock</h2>
    <button class="btn btn-primary" onclick="openModal()"><i class="fas fa-plus"></i> Add Entry</button>
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:1rem;margin-bottom:1.25rem">
    <div class="stat-card" style="border-left:3px solid var(--success)">
        <div class="stat-label"><i class="fas fa-arrow-down" style="color:var(--success)"></i> Total In</div>
        <div class="stat-value" style="color:var(--success)" id="card-in"><?= number_format($totals['in'],3) ?> <small style="font-size:.55em;font-weight:400">kg</small></div>
    </div>
    <div class="stat-card" style="border-left:3px solid var(--warning)">
        <div class="stat-label"><i class="fas fa-arrow-up" style="color:var(--warning)"></i> Total Out</div>
        <div class="stat-value" style="color:var(--warning)" id="card-out"><?= number_format($totals['out'],3) ?> <small style="font-size:.55em;font-weight:400">kg</small></div>
    </div>
    <div class="stat-card" style="border-left:3px solid var(--primary)">
        <div class="stat-label"><i class="fas fa-tag" style="color:var(--primary)"></i> Total Sold</div>
        <div class="stat-value" style="color:var(--primary)" id="card-sold"><?= number_format($totals['sold'],3) ?> <small style="font-size:.55em;font-weight:400">kg</small></div>
    </div>
    <div class="stat-card" style="border-left:3px solid var(--danger)">
        <div class="stat-label"><i class="fas fa-hand-holding-dollar" style="color:var(--danger)"></i> Advances Given</div>
        <div class="stat-value" style="color:var(--danger)" id="card-advance"><?= number_format($totals['advance'],2) ?> <small style="font-size:.55em;font-weight:400">FRW</small></div>
    </div>
</div>

<!-- Add / Edit Modal -->
<div class="modal-backdrop" id="ss-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" style="max-width:580px">
        <div class="modal-header">
            <h3 id="ss-modal-title"><i class="fas fa-plus-circle" style="margin-right:.4rem;color:var(--primary)"></i>Add Supply Stock Entry</h3>
            <button class="modal-close" onclick="closeModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="ss-form">
                <input type="hidden" name="id" id="ss-id">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Supplier <span style="color:var(--danger)">*</span></label>
                        <select name="supplier_id" id="ss-supplier" required>
                            <option value="">— select supplier —</option>
                            <?php foreach($suppliers as $sup): ?>
                            <option value="<?= $sup['id'] ?>"><?= htmlspecialchars($sup['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Mineral <span style="color:var(--danger)">*</span></label>
                        <select name="mineral_id" id="ss-mineral" required>
                            <option value="">— select mineral —</option>
                            <?php foreach($minerals as $min): ?>
                            <option value="<?= $min['id'] ?>"><?= htmlspecialchars($min['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Quantity (kg) <span style="color:var(--danger)">*</span></label>
                        <input type="number" name="qty" id="ss-qty" step="0.001" min="0.001" placeholder="0.000" required>
                    </div>
                    <div class="form-group">
                        <label>Status <span style="color:var(--danger)">*</span></label>
                        <select name="status" id="ss-status" required>
                            <option value="in" selected>In — received into warehouse</option>
                            <option value="out">Out — taken out of warehouse</option>
                            <option value="sold">Sold — sold to us</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Notes</label>
                        <textarea name="notes" id="ss-notes" placeholder="Optional notes…" style="min-height:55px"></textarea>
                    </div>
                </div>

                <!-- Advance / payment section -->
                <div style="margin-top:.85rem;border:1px solid var(--border);border-radius:8px;padding:.85rem 1rem;background:var(--surface,var(--bg))">
                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.6rem">
                        <span style="font-size:.8rem;font-weight:600;color:var(--text-muted)">
                            <i class="fas fa-money-bill-wave" style="margin-right:.35rem"></i>Advance Payment <span style="font-weight:400">(optional)</span>
                        </span>
                        <button type="button" onclick="addPaymentRow()" id="adv-add-btn"
                                style="background:none;border:1px dashed var(--border);cursor:pointer;color:var(--primary);font-size:.8rem;padding:.22rem .6rem;border-radius:6px;font-weight:600">
                            <i class="fas fa-plus"></i> Add Method
                        </button>
                    </div>
                    <!-- column headers -->
                    <div id="adv-col-labels" style="display:none;grid-template-columns:130px 1fr 110px 110px 28px;gap:.4rem;margin-bottom:.25rem;padding:0 .1rem">
                        <span style="font-size:.72rem;font-weight:600;color:var(--text-muted)">Method</span>
                        <span style="font-size:.72rem;font-weight:600;color:var(--text-muted)">Account</span>
                        <span style="font-size:.72rem;font-weight:600;color:var(--text-muted);text-align:right">Balance</span>
                        <span style="font-size:.72rem;font-weight:600;color:var(--text-muted);text-align:right">Amount</span>
                        <span></span>
                    </div>
                    <div id="adv-rows"></div>
                    <!-- total -->
                    <div id="adv-total-wrap" style="display:none;margin-top:.5rem;padding-top:.5rem;border-top:1px solid var(--border);display:flex;justify-content:flex-end;align-items:center;gap:.5rem">
                        <span style="font-size:.8rem;color:var(--text-muted)">Total Advance:</span>
                        <span id="adv-total" style="font-family:monospace;font-weight:700;font-size:.92rem">0 FRW</span>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="ss-form" id="ss-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Entry
            </button>
        </div>
    </div>
</div>

<!-- Filter bar -->
<form method="GET" action="supply-stock.php" class="filter-bar">
    <div class="filter-group">
        <label>Search</label>
        <input type="search" name="search" placeholder="Supplier or mineral…" value="<?= htmlspecialchars($filters['search']) ?>">
    </div>
    <div class="filter-group">
        <label>Status</label>
        <select name="status">
            <option value="">All statuses</option>
            <option value="in"   <?= $filters['status']==='in'  ?'selected':'' ?>>In</option>
            <option value="out"  <?= $filters['status']==='out' ?'selected':'' ?>>Out</option>
            <option value="sold" <?= $filters['status']==='sold'?'selected':'' ?>>Sold</option>
        </select>
    </div>
    <div class="filter-group">
        <label>Supplier</label>
        <select name="supplier_id">
            <option value="">All suppliers</option>
            <?php foreach($suppliers as $sup): ?>
            <option value="<?= $sup['id'] ?>" <?= $filters['supplier_id']==$sup['id']?'selected':'' ?>><?= htmlspecialchars($sup['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Mineral</label>
        <select name="mineral_id">
            <option value="">All minerals</option>
            <?php foreach($minerals as $min): ?>
            <option value="<?= $min['id'] ?>" <?= $filters['mineral_id']==$min['id']?'selected':'' ?>><?= htmlspecialchars($min['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-filter"></i> Filter</button>
        <?php if($has_filters): ?>
        <a href="supply-stock.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem"><i class="fas fa-xmark"></i> Clear</a>
        <?php endif; ?>
    </div>
    <?php if($has_filters): ?>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> <?= count($stocks) ?> result<?= count($stocks)!==1?'s':'' ?></span>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Supplier</th>
                <th>Mineral</th>
                <th style="text-align:right">Qty (kg)</th>
                <th style="text-align:right">Advance (FRW)</th>
                <th>Paid Via</th>
                <th style="text-align:center">Status</th>
                <th>Notes</th>
                <th>Created By</th>
                <th>Updated By</th>
                <th>Date</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="ss-tbody">
        <?php
        $statusCfg   = [
            'in'   => ['label'=>'In',   'color'=>'var(--success)', 'icon'=>'arrow-down'],
            'out'  => ['label'=>'Out',  'color'=>'var(--warning)', 'icon'=>'arrow-up'],
            'sold' => ['label'=>'Sold', 'color'=>'var(--primary)', 'icon'=>'tag'],
        ];
        $methodIcon  = ['cash'=>'money-bill','bank'=>'building-columns','momo'=>'mobile-screen-button'];
        $methodLabel = ['cash'=>'Cash','bank'=>'Bank','momo'=>'MoMo'];
        $i = 0;
        foreach($stocks as $r):
            $sc   = $statusCfg[$r['status']] ?? ['label'=>$r['status'],'color'=>'#888','icon'=>'circle'];
            $adv  = (float)$r['advance_amount'];
            $pmts = $payments_by_ss[$r['id']] ?? [];
        ?>
        <tr id="ss-row-<?= $r['id'] ?>"
            data-supplier="<?= $r['supplier_id'] ?>"
            data-mineral="<?= $r['mineral_id'] ?>"
            data-qty="<?= $r['qty'] ?>"
            data-advance="<?= $adv ?>"
            data-status="<?= $r['status'] ?>"
            data-notes="<?= htmlspecialchars($r['notes'] ?? '', ENT_QUOTES) ?>"
            data-payments="<?= htmlspecialchars(json_encode($pmts), ENT_QUOTES) ?>">
            <td class="font-mono text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="fw-600"><?= htmlspecialchars($r['supplier_name']) ?></td>
            <td class="text-muted"><?= htmlspecialchars($r['mineral_name']) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700"><?= number_format((float)$r['qty'],3) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:600;color:<?= $adv>0?'var(--danger)':'var(--text-muted)' ?>">
                <?= $adv > 0 ? number_format($adv,2) : '—' ?>
            </td>
            <td style="font-size:.8rem">
                <?php if($pmts): ?>
                    <?php foreach(array_unique(array_column($pmts,'payment_method')) as $m): ?>
                    <span style="display:inline-flex;align-items:center;gap:.25rem;margin-right:.3rem">
                        <i class="fas fa-<?= $methodIcon[$m] ?? 'circle' ?>"></i><?= $methodLabel[$m] ?? $m ?>
                    </span>
                    <?php endforeach; ?>
                <?php else: ?>
                    <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td style="text-align:center">
                <button class="status-btn" onclick="toggleStatusMenu(<?= $r['id'] ?>,event)"
                        style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:999px;font-size:.75rem;font-weight:600;background:<?= $sc['color'] ?>22;color:<?= $sc['color'] ?>;border:1px solid <?= $sc['color'] ?>44;cursor:pointer;white-space:nowrap">
                    <i class="fas fa-<?= $sc['icon'] ?>"></i> <?= $sc['label'] ?> <i class="fas fa-chevron-down" style="font-size:.55rem;opacity:.65"></i>
                </button>
            </td>
            <td class="text-muted" style="font-size:.82rem;max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis" title="<?= htmlspecialchars($r['notes'] ?? '') ?>">
                <?= htmlspecialchars($r['notes'] ?? '—') ?>
            </td>
            <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($r['created_by_name']) ?></td>
            <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($r['updated_by_name'] ?? '—') ?></td>
            <td class="text-muted" style="font-size:.78rem;white-space:nowrap"><?= date('d M Y H:i', strtotime($r['created_at'])) ?></td>
            <td style="text-align:center;white-space:nowrap">
                <button class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;margin-right:.3rem" onclick="editEntry(<?= $r['id'] ?>)" title="Edit"><i class="fas fa-pencil"></i></button>
                <button class="btn btn-danger"     style="padding:.3rem .6rem;font-size:.75rem"               onclick="deleteEntry(<?= $r['id'] ?>,this)" title="Delete"><i class="fas fa-trash"></i></button>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if(!$stocks): ?>
        <tr id="empty-row"><td colspan="12" style="text-align:center;padding:2rem;color:var(--text-muted)">
            <?= $has_filters ? 'No entries match the current filters.' : 'No supply stock entries yet. Add the first one above.' ?>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Shared status dropdown — fixed so it escapes any overflow container -->
<div id="status-dropdown" style="display:none;position:fixed;background:var(--bg,#fff);border:1px solid var(--border);border-radius:8px;box-shadow:0 4px 18px rgba(0,0,0,.15);z-index:9999;min-width:115px;overflow:hidden"></div>

<script>
const companyAccounts =<?= json_encode(array_values($company_accounts)) ?>;
const METHOD_LABELS   = { cash:'Cash', bank:'Bank Transfer', momo:'Mobile Money' };
const METHOD_ICONS    = { cash:'money-bill', bank:'building-columns', momo:'mobile-screen-button' };
const STATUS_CFG      = {
    in:   { label:'In',   color:'var(--success)', icon:'arrow-down' },
    out:  { label:'Out',  color:'var(--warning)', icon:'arrow-up'   },
    sold: { label:'Sold', color:'var(--primary)', icon:'tag'        },
};
let ssEditMode = false;
let payRowCnt  = 0;

/* ── Payment rows ────────────────────────────────────────────── */
function buildAccountOpts(method){
    const list = companyAccounts.filter(a => a.account_type === method);
    if(!list.length) return '<option value="">— No accounts —</option>';
    let h = '<option value="">— select account —</option>';
    list.forEach(a => { h += `<option value="${a.id}" data-balance="${a.balance}">${esc(a.account_name)}</option>`; });
    return h;
}

function addPaymentRow(defaultMethod, defaultAcct, defaultAmt){
    const n   = ++payRowCnt;
    const row = document.createElement('div');
    row.id    = 'prow-' + n;
    row.style.cssText = 'display:grid;grid-template-columns:130px 1fr 110px 110px 28px;gap:.4rem;align-items:center;margin-bottom:.35rem';

    const mOpts = ['cash','bank','momo'].map(m =>
        `<option value="${m}"${m === (defaultMethod||'cash') ? ' selected' : ''}>${METHOD_LABELS[m]}</option>`
    ).join('');
    const initMethod = defaultMethod || 'cash';
    const aOpts = buildAccountOpts(initMethod);

    row.innerHTML = `
        <select name="payments[${n}][method]" id="prow-${n}-method"
                style="padding:.38rem .45rem;border:1px solid var(--border);border-radius:6px;background:var(--surface,var(--bg));color:var(--text);font-size:.82rem;width:100%"
                onchange="onRowMethod(${n})">
            ${mOpts}
        </select>
        <select name="payments[${n}][account_id]" id="prow-${n}-acct"
                style="padding:.38rem .45rem;border:1px solid var(--border);border-radius:6px;background:var(--surface,var(--bg));color:var(--text);font-size:.82rem;width:100%"
                onchange="onRowAcct(${n})">
            ${aOpts}
        </select>
        <div id="prow-${n}-bal"
             style="padding:.38rem .45rem;border:1px solid var(--border);border-radius:6px;font-family:monospace;font-size:.78rem;font-weight:600;color:var(--text-muted);background:var(--border)22;text-align:right">—</div>
        <input type="number" name="payments[${n}][amount]" id="prow-${n}-amt"
               placeholder="0" min="0" step="1"
               style="padding:.38rem .45rem;border:1px solid var(--border);border-radius:6px;background:var(--surface,var(--bg));color:var(--text);font-size:.82rem;width:100%;text-align:right"
               oninput="onRowAmt(${n})">
        <button type="button" onclick="removePaymentRow(${n})"
                style="background:none;border:1px solid var(--border);border-radius:6px;cursor:pointer;color:var(--text-muted);width:28px;height:28px;display:flex;align-items:center;justify-content:center">
            <i class="fas fa-times" style="font-size:.7rem"></i>
        </button>
        <div id="prow-${n}-warn" style="display:none;grid-column:1/-1;font-size:.75rem;color:#dc2626;padding:.05rem 0">
            <i class="fas fa-triangle-exclamation"></i> <span id="prow-${n}-wt"></span>
        </div>`;

    document.getElementById('adv-rows').appendChild(row);

    /* auto-select if only one account */
    const acctSel = document.getElementById('prow-'+n+'-acct');
    const opts = [...acctSel.options].filter(o => o.value);
    if(opts.length === 1) acctSel.value = opts[0].value;
    if(defaultAcct) acctSel.value = defaultAcct;
    onRowAcct(n);
    if(defaultAmt) document.getElementById('prow-'+n+'-amt').value = defaultAmt;

    showColLabels();
    recalcAdvTotal();
}

function removePaymentRow(n){
    document.getElementById('prow-'+n)?.remove();
    showColLabels();
    recalcAdvTotal();
}

function onRowMethod(n){
    const method  = document.getElementById('prow-'+n+'-method').value;
    const acctSel = document.getElementById('prow-'+n+'-acct');
    acctSel.innerHTML = buildAccountOpts(method);
    const opts = [...acctSel.options].filter(o => o.value);
    if(opts.length === 1) acctSel.value = opts[0].value;
    onRowAcct(n);
}

function onRowAcct(n){
    const acctSel = document.getElementById('prow-'+n+'-acct');
    const balEl   = document.getElementById('prow-'+n+'-bal');
    const opt     = acctSel.options[acctSel.selectedIndex];
    if(!acctSel.value){ balEl.textContent = '—'; balEl.style.color='var(--text-muted)'; return; }
    const bal = parseFloat(opt.dataset.balance) || 0;
    balEl.textContent = bal.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});
    balEl.style.color = bal > 0 ? 'var(--success)' : 'var(--danger)';
    onRowAmt(n);
}

function onRowAmt(n){
    const acctSel = document.getElementById('prow-'+n+'-acct');
    const warn    = document.getElementById('prow-'+n+'-warn');
    const warnTxt = document.getElementById('prow-'+n+'-wt');
    const amt     = parseFloat(document.getElementById('prow-'+n+'-amt').value) || 0;
    const opt     = acctSel.options[acctSel.selectedIndex];
    if(opt && acctSel.value && amt > 0){
        const bal = parseFloat(opt.dataset.balance) || 0;
        if(amt > bal){
            warnTxt.textContent = 'Amount exceeds balance ('+bal.toLocaleString('en-US',{minimumFractionDigits:2})+' FRW).';
            warn.style.display = 'block';
        } else {
            warn.style.display = 'none';
        }
    } else {
        warn.style.display = 'none';
    }
    recalcAdvTotal();
}

function recalcAdvTotal(){
    let total = 0;
    document.querySelectorAll('#adv-rows [id^="prow-"]').forEach(row => {
        const n   = row.id.replace('prow-','');
        const amt = parseFloat(document.getElementById('prow-'+n+'-amt')?.value) || 0;
        total += amt;
    });
    const tw  = document.getElementById('adv-total-wrap');
    const tel = document.getElementById('adv-total');
    if(total > 0){
        tw.style.display = 'flex';
        tel.textContent  = total.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' FRW';
    } else {
        tw.style.display = 'none';
    }
}

function showColLabels(){
    const hasRows = document.getElementById('adv-rows').children.length > 0;
    document.getElementById('adv-col-labels').style.display = hasRows ? 'grid' : 'none';
    document.getElementById('adv-total-wrap').style.display = hasRows ? 'flex' : 'none';
}

function clearPaymentRows(){
    document.getElementById('adv-rows').innerHTML = '';
    payRowCnt = 0;
    showColLabels();
    recalcAdvTotal();
}

/* ── Modal open/close ────────────────────────────────────────── */
function openModal(isEdit=false){
    ssEditMode = isEdit;
    const title = document.getElementById('ss-modal-title');
    title.innerHTML = isEdit
        ? '<i class="fas fa-pencil" style="margin-right:.4rem;color:var(--primary)"></i>Edit Supply Stock Entry'
        : '<i class="fas fa-plus-circle" style="margin-right:.4rem;color:var(--primary)"></i>Add Supply Stock Entry';
    if(!isEdit) document.getElementById('ss-status').value = 'in';
    document.getElementById('ss-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}
function closeModal(){
    document.getElementById('ss-modal').classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('ss-form').reset();
    document.getElementById('ss-id').value = '';
    clearPaymentRows();
    ssEditMode = false;
}
document.addEventListener('keydown', e => { if(e.key==='Escape') closeModal(); });

function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showAlert(type, msg){
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-'+type+' mb-15';
    el.innerHTML = '<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(()=>{ el.style.display='none'; }, 5000);
}

function statusCell(id, status){
    const c = STATUS_CFG[status] || {label:status,color:'#888',icon:'circle'};
    return `<button class="status-btn" onclick="toggleStatusMenu(${id},event)"
        style="display:inline-flex;align-items:center;gap:.3rem;padding:.2rem .6rem;border-radius:999px;font-size:.75rem;font-weight:600;background:${c.color}22;color:${c.color};border:1px solid ${c.color}44;cursor:pointer;white-space:nowrap">
        <i class="fas fa-${c.icon}"></i> ${c.label} <i class="fas fa-chevron-down" style="font-size:.55rem;opacity:.65"></i>
    </button>`;
}

let _smenuId = null;
function toggleStatusMenu(id, e){
    e.stopPropagation();
    const dd = document.getElementById('status-dropdown');
    if(_smenuId === id && dd.style.display !== 'none'){ dd.style.display='none'; _smenuId=null; return; }
    _smenuId = id;

    const row    = document.getElementById('ss-row-'+id);
    const status = row ? row.dataset.status : '';
    dd.innerHTML = ['in','out','sold'].map(st => {
        const sc = STATUS_CFG[st], active = st === status;
        return `<button onclick="changeStatus(${id},'${st}')"
            style="display:flex;align-items:center;gap:.4rem;width:100%;padding:.44rem .8rem;border:none;${active?`background:${sc.color}18;font-weight:700`:'background:none;font-weight:500'};cursor:pointer;font-size:.8rem;color:${sc.color}">
            <i class="fas fa-${sc.icon}"></i> ${sc.label}${active?'<i class="fas fa-check" style="margin-left:auto;font-size:.6rem"></i>':''}
        </button>`;
    }).join('');

    /* position with fixed coords so overflow:auto on table-wrap can't clip it */
    dd.style.display = 'block';
    const btn  = e.currentTarget;
    const rect = btn.getBoundingClientRect();
    const ddH  = dd.offsetHeight || 110;
    const top  = (rect.top - ddH - 6 > 0) ? rect.top - ddH - 6 : rect.bottom + 6;
    let   left = rect.left + rect.width / 2 - dd.offsetWidth / 2;
    left = Math.max(6, Math.min(left, window.innerWidth - dd.offsetWidth - 6));
    dd.style.top  = top  + 'px';
    dd.style.left = left + 'px';
}

document.addEventListener('click', () => {
    document.getElementById('status-dropdown').style.display = 'none';
    _smenuId = null;
});

function changeStatus(id, status){
    const dd = document.getElementById('status-dropdown');
    dd.style.display = 'none'; _smenuId = null;
    const fd = new FormData();
    fd.append('change_status','1'); fd.append('id',id); fd.append('status',status);
    fetch('supply-stock.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
        .then(r => r.json())
        .then(d => {
            if(d.success){
                showAlert('success', d.message);
                const row = document.getElementById('ss-row-'+id);
                row.dataset.status = status;
                row.querySelectorAll('td')[6].innerHTML = statusCell(id, status);
                recomputeCards();
            } else { showAlert('error', d.message); }
        })
        .catch(() => showAlert('error','Network error.'));
}

function methodsCell(methods){
    if(!methods) return '<span class="text-muted">—</span>';
    const list = [...new Set(methods.split(','))];
    return list.map(m => `<span style="display:inline-flex;align-items:center;gap:.25rem;margin-right:.3rem"><i class="fas fa-${METHOD_ICONS[m]||'circle'}"></i>${METHOD_LABELS[m]||m}</span>`).join('');
}

/* ── recompute summary cards ─────────────────────────────────── */
function recomputeCards(){
    const totals = {in:0, out:0, sold:0, advance:0};
    document.querySelectorAll('#ss-tbody tr[id^="ss-row-"]').forEach(row => {
        if(totals[row.dataset.status] !== undefined) totals[row.dataset.status] += parseFloat(row.dataset.qty)||0;
        totals.advance += parseFloat(row.dataset.advance)||0;
    });
    ['in','out','sold'].forEach(k => {
        const el = document.getElementById('card-'+k);
        if(el) el.innerHTML = totals[k].toFixed(3)+' <small style="font-size:.55em;font-weight:400">kg</small>';
    });
    const ae = document.getElementById('card-advance');
    if(ae) ae.innerHTML = totals.advance.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2})+' <small style="font-size:.55em;font-weight:400">FRW</small>';
}

/* ── Edit ────────────────────────────────────────────────────── */
function editEntry(id){
    const row = document.getElementById('ss-row-'+id);
    document.getElementById('ss-id').value       = id;
    document.getElementById('ss-supplier').value = row.dataset.supplier;
    document.getElementById('ss-mineral').value  = row.dataset.mineral;
    document.getElementById('ss-qty').value      = row.dataset.qty;
    document.getElementById('ss-status').value   = row.dataset.status;
    document.getElementById('ss-notes').value    = row.dataset.notes;

    clearPaymentRows();
    try {
        const pmts = JSON.parse(row.dataset.payments || '[]');
        pmts.forEach(p => addPaymentRow(p.payment_method, String(p.account_id||''), p.amount));
    } catch(e){}

    openModal(true);
}

/* ── Submit ──────────────────────────────────────────────────── */
document.getElementById('ss-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('ss-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData(this);
    fd.append(ssEditMode ? 'edit' : 'add', '1');

    fetch('supply-stock.php', {
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest'},
        body:fd
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            showAlert('success', d.message);
            if(ssEditMode){ updateRow(d.record); } else { prependRow(d.record); }
            recomputeCards();
            closeModal();
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Entry';
    })
    .catch(()=>{
        showAlert('error','Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Entry';
    });
});

/* ── updateRow / prependRow ──────────────────────────────────── */
function updateRow(r){
    const row = document.getElementById('ss-row-'+r.id);
    if(!row) return;
    const adv = parseFloat(r.advance_amount)||0;
    row.dataset.supplier = r.supplier_id;
    row.dataset.mineral  = r.mineral_id;
    row.dataset.qty      = r.qty;
    row.dataset.advance  = adv;
    row.dataset.status   = r.status;
    row.dataset.notes    = r.notes||'';
    const cells = row.querySelectorAll('td');
    cells[1].textContent = r.supplier_name;
    cells[2].textContent = r.mineral_name;
    cells[3].textContent = parseFloat(r.qty).toFixed(3);
    cells[4].style.color = adv>0?'var(--danger)':'var(--text-muted)';
    cells[4].textContent = adv>0?adv.toFixed(2):'—';
    cells[6].innerHTML   = statusCell(r.id, r.status);
    cells[7].textContent = r.notes||'—';
    cells[7].title       = r.notes||'';
    cells[9].textContent = r.updated_by_name||'—';
}

function prependRow(r){
    const tbody = document.getElementById('ss-tbody');
    const empty = document.getElementById('empty-row');
    if(empty) empty.remove();

    const tr  = document.createElement('tr');
    tr.id     = 'ss-row-'+r.id;
    const adv = parseFloat(r.advance_amount)||0;
    tr.dataset.supplier = r.supplier_id;
    tr.dataset.mineral  = r.mineral_id;
    tr.dataset.qty      = r.qty;
    tr.dataset.advance  = adv;
    tr.dataset.status   = r.status;
    tr.dataset.notes    = r.notes||'';
    tr.dataset.payments = '[]';
    tr.innerHTML =
        `<td class="font-mono text-muted" style="font-size:.78rem">—</td>`+
        `<td class="fw-600">${esc(r.supplier_name)}</td>`+
        `<td class="text-muted">${esc(r.mineral_name)}</td>`+
        `<td style="text-align:right;font-family:monospace;font-weight:700">${parseFloat(r.qty).toFixed(3)}</td>`+
        `<td style="text-align:right;font-family:monospace;font-weight:600;color:${adv>0?'var(--danger)':'var(--text-muted)'}">${adv>0?adv.toFixed(2):'—'}</td>`+
        `<td style="font-size:.8rem">${methodsCell(r.pay_methods||'')}</td>`+
        `<td style="text-align:center">${statusCell(r.id,r.status)}</td>`+
        `<td class="text-muted" style="font-size:.82rem;max-width:150px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis">${esc(r.notes||'—')}</td>`+
        `<td class="text-muted" style="font-size:.8rem">${esc(r.created_by_name)}</td>`+
        `<td class="text-muted" style="font-size:.8rem">—</td>`+
        `<td class="text-muted" style="font-size:.78rem;white-space:nowrap">just now</td>`+
        `<td style="text-align:center;white-space:nowrap">`+
            `<button class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;margin-right:.3rem" onclick="editEntry(${r.id})" title="Edit"><i class="fas fa-pencil"></i></button>`+
            `<button class="btn btn-danger"    style="padding:.3rem .6rem;font-size:.75rem"                  onclick="deleteEntry(${r.id},this)" title="Delete"><i class="fas fa-trash"></i></button>`+
        `</td>`;
    tbody.insertBefore(tr, tbody.firstChild);
}

/* ── Delete ──────────────────────────────────────────────────── */
function deleteEntry(id, btn){
    if(!confirm('Delete this supply stock entry?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('delete','1');
    fd.append('id', id);

    fetch('supply-stock.php', {
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest'},
        body:fd
    })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            const row = document.getElementById('ss-row-'+id);
            row.style.transition = 'opacity .3s';
            row.style.opacity    = '0';
            setTimeout(()=>{
                row.remove();
                recomputeCards();
                if(!document.querySelector('#ss-tbody tr[id^="ss-row-"]')){
                    document.getElementById('ss-tbody').innerHTML =
                        '<tr id="empty-row"><td colspan="12" style="text-align:center;padding:2rem;color:var(--text-muted)">No supply stock entries yet.</td></tr>';
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
        showAlert('error','Network error.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash"></i>';
    });
}
</script>

<?php include 'includes/footer.php'; ?>
