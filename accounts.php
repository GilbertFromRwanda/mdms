<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX handlers ───────────────────────────────────────────── */
if(isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');

    /* Add account */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add'])){
        try {
            $type    = in_array($_POST['type']??'', ['cash','bank','momo']) ? $_POST['type'] : null;
            $name    = trim($_POST['name'] ?? '');
            $opening = floatval($_POST['opening_balance'] ?? 0);
            $notes   = trim($_POST['notes'] ?? '');
            if(!$type) throw new Exception('Account type is required.');
            if(!$name) throw new Exception('Account name is required.');

            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO company_accounts (account_type,account_name,balance,notes,created_by)
                VALUES (?,?,?,?,?)
            ")->execute([$type,$name,$opening,$notes,$_SESSION['user_id']]);
            $id = $pdo->lastInsertId();

            if($opening != 0){
                $txnType = $opening > 0 ? 'credit' : 'debit';
                $pdo->prepare("
                    INSERT INTO account_transactions
                      (account_id,txn_type,amount,balance_after,reference_type,description,created_by)
                    VALUES (?,?,?,?,'manual','Opening balance',?)
                ")->execute([$id,$txnType,abs($opening),$opening,$_SESSION['user_id']]);
            }
            $pdo->commit();
            logAction($pdo,$_SESSION['user_id'],'CREATE','company_accounts',$id,"Added account: $name");
            echo json_encode(['success'=>true,'message'=>"Account <strong>".htmlspecialchars($name)."</strong> created.",'id'=>$id,'type'=>$type,'name'=>$name,'balance'=>$opening,'notes'=>$notes]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* Edit account name/notes/status */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['edit'])){
        try {
            $id     = intval($_POST['id'] ?? 0);
            $name   = trim($_POST['name'] ?? '');
            $notes  = trim($_POST['notes'] ?? '');
            $active = isset($_POST['is_active']) ? 1 : 0;
            if(!$id)   throw new Exception('Invalid account.');
            if(!$name) throw new Exception('Name is required.');
            $pdo->prepare("UPDATE company_accounts SET account_name=?,notes=?,is_active=? WHERE id=?")
                ->execute([$name,$notes,$active,$id]);
            logAction($pdo,$_SESSION['user_id'],'UPDATE','company_accounts',$id,"Edited account: $name");
            echo json_encode(['success'=>true,'message'=>"Account updated.",'name'=>$name,'notes'=>$notes,'is_active'=>$active]);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* Manual adjustment (credit / debit) */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['adjust'])){
        try {
            $id     = intval($_POST['id'] ?? 0);
            $txnType= in_array($_POST['txn_type']??'', ['credit','debit']) ? $_POST['txn_type'] : null;
            $amount = floatval($_POST['amount'] ?? 0);
            $desc   = trim($_POST['description'] ?? 'Manual adjustment');
            if(!$id || !$txnType || $amount <= 0) throw new Exception('Invalid input.');

            $pdo->beginTransaction();
            $row = $pdo->prepare("SELECT balance FROM company_accounts WHERE id=? FOR UPDATE");
            $row->execute([$id]);
            $acct = $row->fetch();
            if(!$acct) throw new Exception('Account not found.');

            $delta      = $txnType === 'credit' ? $amount : -$amount;
            $newBalance = round($acct['balance'] + $delta, 2);
            $pdo->prepare("UPDATE company_accounts SET balance=? WHERE id=?")->execute([$newBalance,$id]);
            $pdo->prepare("
                INSERT INTO account_transactions
                  (account_id,txn_type,amount,balance_after,reference_type,description,created_by)
                VALUES (?,?,?,?,'manual',?,?)
            ")->execute([$id,$txnType,$amount,$newBalance,$desc,$_SESSION['user_id']]);
            $pdo->commit();
            logAction($pdo,$_SESSION['user_id'],'UPDATE','company_accounts',$id,"$txnType $amount FRW: $desc");
            echo json_encode(['success'=>true,'message'=>'Adjustment recorded.','new_balance'=>$newBalance]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* Delete account */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete'])){
        try {
            $id = intval($_POST['id'] ?? 0);
            if(!$id) throw new Exception('Invalid account.');
            $pdo->prepare("DELETE FROM company_accounts WHERE id=?")->execute([$id]);
            $pdo->prepare("DELETE FROM account_transactions WHERE account_id=?")->execute([$id]);
            logAction($pdo,$_SESSION['user_id'],'DELETE','company_accounts',$id,"Deleted account #$id");
            echo json_encode(['success'=>true,'message'=>'Account deleted.']);
        } catch(Exception $e){
            echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
        }
        exit;
    }

    /* Transaction history */
    if($_SERVER['REQUEST_METHOD']==='GET' && isset($_GET['history'])){
        $id = intval($_GET['history']);
        $rows = $pdo->prepare("
            SELECT at.*, u.username
            FROM account_transactions at
            LEFT JOIN users u ON u.id = at.created_by
            WHERE at.account_id=?
            ORDER BY at.created_at DESC LIMIT 100
        ");
        $rows->execute([$id]);
        echo json_encode(['success'=>true,'rows'=>$rows->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }
}

/* ── Page data ───────────────────────────────────────────────── */
$page_title = 'Company Accounts';

$accounts = $pdo->query("
    SELECT ca.*,
           COALESCE((SELECT COUNT(*) FROM account_transactions WHERE account_id=ca.id),0) AS txn_count
    FROM company_accounts ca
    ORDER BY ca.account_type, ca.account_name
")->fetchAll();

$totals = ['cash'=>0, 'bank'=>0, 'momo'=>0];
foreach($accounts as $a) $totals[$a['account_type']] += $a['balance'];
$grand_total = array_sum($totals);

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-building-columns" style="margin-right:.4rem;color:var(--text-muted)"></i>Company Accounts</h2>
    <button class="btn btn-primary" onclick="openAddPanel()">
        <i class="fas fa-plus"></i> Add Account
    </button>
</div>

<!-- Summary cards -->
<div style="display:grid;grid-template-columns:repeat(4,1fr);gap:.75rem;margin-bottom:1.25rem">
    <?php
    $cards = [
        ['cash',  'fas fa-money-bill-wave', 'Cash',          '#16a34a', $totals['cash']],
        ['bank',  'fas fa-building-columns','Bank Transfer',  '#2563eb', $totals['bank']],
        ['momo',  'fas fa-mobile-screen',   'Mobile Money',   '#7c3aed', $totals['momo']],
        ['total', 'fas fa-wallet',           'Grand Total',    'var(--primary)', $grand_total],
    ];
    foreach($cards as [$type,$icon,$label,$color,$bal]):
        $neg = $bal < 0;
    ?>
    <div style="border:1px solid <?= $color ?>33;border-left:4px solid <?= $color ?>;border-radius:8px;padding:1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;font-weight:600;color:<?= $color ?>;text-transform:uppercase;letter-spacing:.05em;margin-bottom:.35rem">
            <i class="<?= $icon ?>"></i> <?= $label ?>
        </div>
        <div id="card-total-<?= $type ?>" style="font-size:1.25rem;font-weight:700;font-family:monospace;color:<?= $neg?'#dc2626':$color ?>">
            <?= number_format(abs($bal),2) ?> FRW
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Add / Edit slide panel -->
<div class="slide-panel" id="acct-panel">
    <h3 id="panel-title"><i class="fas fa-plus-circle" style="margin-right:.4rem"></i>Add Account</h3>
    <input type="hidden" id="panel-mode" value="add">
    <input type="hidden" id="panel-id"   value="">
    <div class="form-grid form-grid-2">
        <div class="form-group" id="type-group">
            <label>Account Type</label>
            <select id="f-type">
                <option value="">— Select type —</option>
                <option value="cash">Cash</option>
                <option value="bank">Bank Transfer</option>
                <option value="momo">Mobile Money (MoMo)</option>
            </select>
        </div>
        <div class="form-group">
            <label>Account Name</label>
            <input type="text" id="f-name" placeholder="e.g. Main Cash Box, BPR Account…">
        </div>
        <div class="form-group" id="opening-group">
            <label>Opening Balance (FRW)</label>
            <input type="text" id="f-opening" placeholder="0.00">
        </div>
        <div class="form-group">
            <label>Notes</label>
            <input type="text" id="f-notes" placeholder="Optional…">
        </div>
        <div class="form-group" id="active-group" style="display:none">
            <label>Status</label>
            <label style="display:flex;align-items:center;gap:.5rem;cursor:pointer">
                <input type="checkbox" id="f-active" checked> Active
            </label>
        </div>
    </div>
    <div class="slide-panel-btns">
        <button type="button" id="panel-save-btn" class="btn btn-primary" onclick="savePanel()">
            <i class="fas fa-save"></i> Save
        </button>
        <button type="button" class="btn btn-secondary" onclick="closePanel()">Cancel</button>
    </div>
</div>

<!-- Adjust modal -->
<div id="adjust-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;align-items:center;justify-content:center">
    <div style="background:var(--bg);border-radius:12px;padding:1.5rem;width:100%;max-width:420px;box-shadow:0 8px 32px rgba(0,0,0,.25)">
        <h3 style="margin:0 0 1rem;font-size:1rem"><i class="fas fa-sliders" style="margin-right:.4rem"></i>Adjust Balance — <span id="adj-name"></span></h3>
        <input type="hidden" id="adj-id">
        <div class="form-grid form-grid-2">
            <div class="form-group">
                <label>Type</label>
                <select id="adj-type">
                    <option value="credit">Credit (add money)</option>
                    <option value="debit">Debit (remove money)</option>
                </select>
            </div>
            <div class="form-group">
                <label>Amount (FRW)</label>
                <input type="text" id="adj-amount" placeholder="0.00">
            </div>
        </div>
        <div class="form-group">
            <label>Description</label>
            <input type="text" id="adj-desc" placeholder="Reason for adjustment…">
        </div>
        <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem">
            <button class="btn btn-secondary" onclick="closeAdjust()">Cancel</button>
            <button class="btn btn-primary" id="adj-save-btn" onclick="saveAdjust()"><i class="fas fa-check"></i> Apply</button>
        </div>
    </div>
</div>

<!-- History modal -->
<div id="history-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:300;align-items:center;justify-content:center">
    <div style="background:var(--bg);border-radius:12px;padding:1.5rem;width:100%;max-width:680px;max-height:80vh;display:flex;flex-direction:column;box-shadow:0 8px 32px rgba(0,0,0,.25)">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1rem">
            <h3 style="margin:0;font-size:1rem"><i class="fas fa-clock-rotate-left" style="margin-right:.4rem"></i>Transaction History — <span id="hist-name"></span></h3>
            <button onclick="closeHistory()" style="background:none;border:none;cursor:pointer;font-size:1.1rem;color:var(--text-muted)"><i class="fas fa-xmark"></i></button>
        </div>
        <div style="overflow-y:auto;flex:1">
            <table style="width:100%;border-collapse:collapse;font-size:.83rem">
                <thead>
                    <tr style="border-bottom:2px solid var(--border)">
                        <th style="padding:.4rem .6rem;text-align:left">Date</th>
                        <th style="padding:.4rem .6rem;text-align:left">Type</th>
                        <th style="padding:.4rem .6rem;text-align:right">Amount</th>
                        <th style="padding:.4rem .6rem;text-align:right">Balance After</th>
                        <th style="padding:.4rem .6rem;text-align:left">Description</th>
                        <th style="padding:.4rem .6rem;text-align:left">By</th>
                    </tr>
                </thead>
                <tbody id="hist-body"></tbody>
            </table>
            <div id="hist-empty" style="display:none;text-align:center;padding:2rem;color:var(--text-muted)">No transactions yet.</div>
        </div>
    </div>
</div>

<!-- Accounts table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Type</th>
                <th>Account Name</th>
                <th style="text-align:right">Balance (FRW)</th>
                <th>Notes</th>
                <th style="text-align:center">Status</th>
                <th style="text-align:center">Transactions</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="acct-tbody">
        <?php if(!$accounts): ?>
        <tr id="empty-row"><td colspan="8" style="text-align:center;padding:2rem;color:var(--text-muted)">
            No accounts yet. Add your first account above.
        </td></tr>
        <?php else: ?>
        <?php $i=0; foreach($accounts as $a):
            $typeLabels = ['cash'=>['Cash','#16a34a','fas fa-money-bill-wave'],
                           'bank'=>['Bank','#2563eb','fas fa-building-columns'],
                           'momo'=>['MoMo','#7c3aed','fas fa-mobile-screen']];
            [$tlabel,$tcolor,$ticon] = $typeLabels[$a['account_type']];
            $neg = (float)$a['balance'] < 0;
        ?>
        <tr id="acct-row-<?= $a['id'] ?>">
            <td class="font-mono text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td>
                <span style="display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:<?= $tcolor ?>18;color:<?= $tcolor ?>">
                    <i class="<?= $ticon ?>"></i> <?= $tlabel ?>
                </span>
            </td>
            <td class="fw-600" id="acct-name-<?= $a['id'] ?>"><?= htmlspecialchars($a['account_name']) ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:<?= $neg?'#dc2626':'var(--text)' ?>" id="acct-bal-<?= $a['id'] ?>">
                <?= ($neg?'−':'') . number_format(abs((float)$a['balance']),2) ?>
            </td>
            <td class="text-muted" style="font-size:.83rem" id="acct-notes-<?= $a['id'] ?>"><?= htmlspecialchars($a['notes']??'') ?></td>
            <td style="text-align:center">
                <span id="acct-status-<?= $a['id'] ?>" style="padding:.15rem .5rem;border-radius:10px;font-size:.75rem;font-weight:600;<?= $a['is_active']?'background:#f0fdf4;color:#16a34a':'background:#fef2f2;color:#dc2626' ?>">
                    <?= $a['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
            </td>
            <td style="text-align:center;font-size:.83rem;color:var(--text-muted)"><?= number_format($a['txn_count']) ?></td>
            <td style="text-align:center;white-space:nowrap">
                <button class="btn btn-secondary" style="padding:.3rem .55rem;font-size:.75rem;margin-right:.25rem"
                        onclick="openHistory(<?= $a['id'] ?>, <?= htmlspecialchars(json_encode($a['account_name'])) ?>)"
                        title="Transaction history">
                    <i class="fas fa-clock-rotate-left"></i>
                </button>
                <button class="btn btn-secondary" style="padding:.3rem .55rem;font-size:.75rem;margin-right:.25rem"
                        onclick="openAdjust(<?= $a['id'] ?>, <?= htmlspecialchars(json_encode($a['account_name'])) ?>)"
                        title="Adjust balance">
                    <i class="fas fa-sliders"></i>
                </button>
                <button class="btn btn-secondary" style="padding:.3rem .55rem;font-size:.75rem;margin-right:.25rem"
                        onclick="openEditPanel(<?= htmlspecialchars(json_encode($a)) ?>)"
                        title="Edit">
                    <i class="fas fa-pen"></i>
                </button>
                <button class="btn btn-danger" style="padding:.3rem .55rem;font-size:.75rem"
                        onclick="deleteAccount(<?= $a['id'] ?>, this)"
                        title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<script>
const TYPE_META = {
    cash: { label:'Cash',          color:'#16a34a', icon:'fas fa-money-bill-wave' },
    bank: { label:'Bank',          color:'#2563eb', icon:'fas fa-building-columns' },
    momo: { label:'Mobile Money',  color:'#7c3aed', icon:'fas fa-mobile-screen'   },
};
let cardTotals = <?= json_encode($totals) ?>;

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showAlert(type, msg){
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-'+type+' mb-15';
    el.innerHTML = '<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(()=>{ el.style.display='none'; }, 5000);
}

/* ── Panel ──────────────────────────────────────────────────── */
function openAddPanel(){
    document.getElementById('panel-mode').value = 'add';
    document.getElementById('panel-id').value   = '';
    document.getElementById('panel-title').innerHTML = '<i class="fas fa-plus-circle" style="margin-right:.4rem"></i>Add Account';
    document.getElementById('f-type').value    = '';
    document.getElementById('f-name').value    = '';
    document.getElementById('f-opening').value = '';
    document.getElementById('f-notes').value   = '';
    document.getElementById('f-active').checked = true;
    document.getElementById('type-group').style.display    = '';
    document.getElementById('opening-group').style.display = '';
    document.getElementById('active-group').style.display  = 'none';
    document.getElementById('acct-panel').classList.add('open');
}

function openEditPanel(a){
    document.getElementById('panel-mode').value = 'edit';
    document.getElementById('panel-id').value   = a.id;
    document.getElementById('panel-title').innerHTML = '<i class="fas fa-pen" style="margin-right:.4rem"></i>Edit Account';
    document.getElementById('f-name').value    = a.account_name;
    document.getElementById('f-notes').value   = a.notes || '';
    document.getElementById('f-active').checked = a.is_active == 1;
    document.getElementById('type-group').style.display    = 'none';
    document.getElementById('opening-group').style.display = 'none';
    document.getElementById('active-group').style.display  = '';
    document.getElementById('acct-panel').classList.add('open');
}

function closePanel(){ document.getElementById('acct-panel').classList.remove('open'); }

function savePanel(){
    const mode = document.getElementById('panel-mode').value;
    const btn  = document.getElementById('panel-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData();
    if(mode === 'add'){
        fd.append('add','1');
        fd.append('type', document.getElementById('f-type').value);
        fd.append('opening_balance', document.getElementById('f-opening').value);
    } else {
        fd.append('edit','1');
        fd.append('id', document.getElementById('panel-id').value);
        if(document.getElementById('f-active').checked) fd.append('is_active','1');
    }
    fd.append('name',  document.getElementById('f-name').value);
    fd.append('notes', document.getElementById('f-notes').value);

    fetch('accounts.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showAlert('success', d.message);
            if(mode === 'add') prependRow(d);
            else               updateRow(d);
            closePanel();
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save';
    }).catch(()=>{
        showAlert('error','Network error.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save';
    });
}

function prependRow(d){
    const m   = TYPE_META[d.type] || TYPE_META.cash;
    const neg = d.balance < 0;
    const tbody = document.getElementById('acct-tbody');
    document.getElementById('empty-row')?.remove();
    const tr = document.createElement('tr');
    tr.id = 'acct-row-'+d.id;
    tr.innerHTML = `
        <td class="font-mono text-muted" style="font-size:.78rem">—</td>
        <td><span style="display:inline-flex;align-items:center;gap:.35rem;padding:.2rem .6rem;border-radius:12px;font-size:.78rem;font-weight:600;background:${m.color}18;color:${m.color}"><i class="${m.icon}"></i> ${m.label}</span></td>
        <td class="fw-600" id="acct-name-${d.id}">${esc(d.name)}</td>
        <td style="text-align:right;font-family:monospace;font-weight:700;color:${neg?'#dc2626':'var(--text)'}" id="acct-bal-${d.id}">${(neg?'−':'')+Math.abs(d.balance).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
        <td class="text-muted" style="font-size:.83rem" id="acct-notes-${d.id}">${esc(d.notes||'')}</td>
        <td style="text-align:center"><span id="acct-status-${d.id}" style="padding:.15rem .5rem;border-radius:10px;font-size:.75rem;font-weight:600;background:#f0fdf4;color:#16a34a">Active</span></td>
        <td style="text-align:center;font-size:.83rem;color:var(--text-muted)">0</td>
        <td style="text-align:center;white-space:nowrap">
            <button class="btn btn-secondary" style="padding:.3rem .55rem;font-size:.75rem;margin-right:.25rem" onclick="openHistory(${d.id},${JSON.stringify(d.name)})" title="History"><i class="fas fa-clock-rotate-left"></i></button>
            <button class="btn btn-secondary" style="padding:.3rem .55rem;font-size:.75rem;margin-right:.25rem" onclick="openAdjust(${d.id},${JSON.stringify(d.name)})" title="Adjust"><i class="fas fa-sliders"></i></button>
            <button class="btn btn-secondary" style="padding:.3rem .55rem;font-size:.75rem;margin-right:.25rem" onclick="openEditPanel(${JSON.stringify({id:d.id,account_name:d.name,notes:d.notes,is_active:1})})" title="Edit"><i class="fas fa-pen"></i></button>
            <button class="btn btn-danger"    style="padding:.3rem .55rem;font-size:.75rem" onclick="deleteAccount(${d.id},this)" title="Delete"><i class="fas fa-trash"></i></button>
        </td>`;
    tbody.prepend(tr);
    updateCardTotal(d.type, d.balance);
}

function updateRow(d){
    const id = document.getElementById('panel-id').value;
    const nameEl   = document.getElementById('acct-name-'+id);
    const notesEl  = document.getElementById('acct-notes-'+id);
    const statusEl = document.getElementById('acct-status-'+id);
    if(nameEl)   nameEl.textContent  = d.name;
    if(notesEl)  notesEl.textContent = d.notes || '';
    if(statusEl){
        statusEl.textContent = d.is_active ? 'Active' : 'Inactive';
        statusEl.style.cssText = d.is_active
            ? 'padding:.15rem .5rem;border-radius:10px;font-size:.75rem;font-weight:600;background:#f0fdf4;color:#16a34a'
            : 'padding:.15rem .5rem;border-radius:10px;font-size:.75rem;font-weight:600;background:#fef2f2;color:#dc2626';
    }
}

function updateCardTotal(type, delta){
    cardTotals[type] = (cardTotals[type] || 0) + delta;
    const el = document.getElementById('card-total-'+type);
    if(el){
        const v = cardTotals[type];
        el.textContent = Math.abs(v).toLocaleString('en-US',{minimumFractionDigits:2}) + ' FRW';
        el.style.color = v < 0 ? '#dc2626' : (type==='cash'?'#16a34a':type==='bank'?'#2563eb':'#7c3aed');
    }
    // grand total
    const grand = Object.values(cardTotals).reduce((a,b)=>a+b,0);
    const gel = document.getElementById('card-total-total');
    if(gel){
        gel.textContent = Math.abs(grand).toLocaleString('en-US',{minimumFractionDigits:2}) + ' FRW';
        gel.style.color = grand < 0 ? '#dc2626' : 'var(--primary)';
    }
}

/* ── Delete ────────────────────────────────────────────────── */
function deleteAccount(id, btn){
    if(!confirm('Delete this account and all its transaction history? This cannot be undone.')) return;
    btn.disabled = true;
    const fd = new FormData();
    fd.append('delete','1'); fd.append('id',id);
    fetch('accounts.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            document.getElementById('acct-row-'+id)?.remove();
            showAlert('success', d.message);
        } else {
            showAlert('error', d.message);
            btn.disabled = false;
        }
    });
}

/* ── Adjust modal ──────────────────────────────────────────── */
function openAdjust(id, name){
    document.getElementById('adj-id').value    = id;
    document.getElementById('adj-name').textContent = name;
    document.getElementById('adj-type').value  = 'credit';
    document.getElementById('adj-amount').value = '';
    document.getElementById('adj-desc').value   = '';
    document.getElementById('adjust-modal').style.display = 'flex';
}
function closeAdjust(){ document.getElementById('adjust-modal').style.display = 'none'; }

function saveAdjust(){
    const btn = document.getElementById('adj-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    const fd = new FormData();
    fd.append('adjust','1');
    fd.append('id',      document.getElementById('adj-id').value);
    fd.append('txn_type',document.getElementById('adj-type').value);
    fd.append('amount',  document.getElementById('adj-amount').value);
    fd.append('description', document.getElementById('adj-desc').value);
    fetch('accounts.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            const id  = document.getElementById('adj-id').value;
            const el  = document.getElementById('acct-bal-'+id);
            if(el){
                const v = d.new_balance;
                el.textContent = (v<0?'−':'')+Math.abs(v).toLocaleString('en-US',{minimumFractionDigits:2});
                el.style.color = v<0?'#dc2626':'var(--text)';
            }
            showAlert('success', d.message);
            closeAdjust();
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-check"></i> Apply';
    });
}

/* ── History modal ─────────────────────────────────────────── */
function openHistory(id, name){
    document.getElementById('hist-name').textContent = name;
    document.getElementById('hist-body').innerHTML   = '<tr><td colspan="6" style="text-align:center;padding:1.5rem;color:var(--text-muted)"><i class="fas fa-spinner fa-spin"></i> Loading…</td></tr>';
    document.getElementById('hist-empty').style.display = 'none';
    document.getElementById('history-modal').style.display = 'flex';

    fetch('accounts.php?history='+id, {headers:{'X-Requested-With':'XMLHttpRequest'}})
    .then(r=>r.json()).then(d=>{
        if(!d.success || !d.rows.length){
            document.getElementById('hist-body').innerHTML = '';
            document.getElementById('hist-empty').style.display = '';
            return;
        }
        document.getElementById('hist-body').innerHTML = d.rows.map(r=>{
            const isCredit = r.txn_type === 'credit';
            const clr = isCredit ? '#16a34a' : '#dc2626';
            const sign = isCredit ? '+' : '−';
            const refBadge = r.reference_type ? `<span style="font-size:.7rem;padding:.1rem .4rem;border-radius:8px;background:var(--border);color:var(--text-muted)">${esc(r.reference_type)}</span> ` : '';
            return `<tr style="border-bottom:1px solid var(--border)">
                <td style="padding:.35rem .6rem;color:var(--text-muted);white-space:nowrap">${r.created_at.slice(0,16)}</td>
                <td style="padding:.35rem .6rem"><span style="font-weight:600;color:${clr}">${isCredit?'Credit':'Debit'}</span></td>
                <td style="padding:.35rem .6rem;text-align:right;font-family:monospace;color:${clr};font-weight:700">${sign}${parseFloat(r.amount).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                <td style="padding:.35rem .6rem;text-align:right;font-family:monospace">${parseFloat(r.balance_after).toLocaleString('en-US',{minimumFractionDigits:2})}</td>
                <td style="padding:.35rem .6rem">${refBadge}${esc(r.description||'')}</td>
                <td style="padding:.35rem .6rem;color:var(--text-muted);font-size:.78rem">${esc(r.username||'—')}</td>
            </tr>`;
        }).join('');
    });
}
function closeHistory(){ document.getElementById('history-modal').style.display = 'none'; }

document.getElementById('adjust-modal').addEventListener('click', function(e){ if(e.target===this) closeAdjust(); });
document.getElementById('history-modal').addEventListener('click', function(e){ if(e.target===this) closeHistory(); });
</script>

<?php include 'includes/footer.php'; ?>
