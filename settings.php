<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }
if(!hasRole('admin')){ header('Location: dashboard.php'); exit; }

/* ── AJAX handlers ─────────────────────────────────────────────── */
if(isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');

    /* Add mineral type */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_mineral'])){
        try {
            $name = trim($_POST['name']);
            $unit = trim($_POST['unit']) ?: 'kg';
            $desc = trim($_POST['description'] ?? '');
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO mineral_types (name,unit,description) VALUES (?,?,?)");
            $stmt->execute([$name, $unit, $desc]);
            $id = $pdo->lastInsertId();
            logAction($pdo,$_SESSION['user_id'],'CREATE','mineral_types',$id,"Added mineral type: {$name}");
            $pdo->commit();
            echo json_encode(['success'=>true,
                'message'=>"Mineral <strong>".htmlspecialchars($name)."</strong> added.",
                'mineral'=>['id'=>$id,'name'=>$name,'unit'=>$unit,'description'=>$desc]]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    /* Delete mineral type */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_mineral'])){
        try {
            $id = (int)$_POST['id'];
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM mineral_types WHERE id=?")->execute([$id]);
            logAction($pdo,$_SESSION['user_id'],'DELETE','mineral_types',$id,"Deleted mineral type ID:{$id}");
            $pdo->commit();
            echo json_encode(['success'=>true,'message'=>'Mineral type removed.']);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    /* Add price setting */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['add_price'])){
        try {
            $mid   = (int)$_POST['mineral_type_id'];
            $grade = trim($_POST['quality_grade']);
            $buy   = (float)$_POST['purchase_price'];
            $sell  = (float)$_POST['selling_price'];
            /* Validate mineral exists before opening transaction */
            $mt = $pdo->prepare("SELECT name,unit FROM mineral_types WHERE id=?");
            $mt->execute([$mid]);
            $m = $mt->fetch();
            if(!$m) throw new Exception("Mineral type not found.");
            $pdo->beginTransaction();
            $stmt = $pdo->prepare("INSERT INTO mineral_price_settings (mineral_type_id,quality_grade,purchase_price,selling_price) VALUES (?,?,?,?)");
            $stmt->execute([$mid, $grade, $buy, $sell]);
            $id = $pdo->lastInsertId();
            logAction($pdo,$_SESSION['user_id'],'CREATE','mineral_price_settings',$id,
                "Price: {$m['name']} Grade:{$grade} Buy:{$buy} Sell:{$sell}");
            $pdo->commit();
            $margin = $sell - $buy;
            echo json_encode(['success'=>true,
                'message'=>"Price set for <strong>".htmlspecialchars($m['name'])." — {$grade}</strong>.",
                'row'=>[
                    'id'             => $id,
                    'mineral_type_id'=> $mid,
                    'mineral_name'   => $m['name'],
                    'unit'           => $m['unit'],
                    'quality_grade'  => $grade,
                    'purchase_price' => number_format($buy, 2),
                    'selling_price'  => number_format($sell, 2),
                    'margin'         => number_format(abs($margin), 2),
                    'margin_up'      => $margin >= 0,
                    'raw_buy'        => $buy,
                    'raw_sell'       => $sell,
                ]]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    /* Update price setting */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['update_price'])){
        try {
            $id   = (int)$_POST['id'];
            $buy  = (float)$_POST['purchase_price'];
            $sell = (float)$_POST['selling_price'];
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE mineral_price_settings SET purchase_price=?,selling_price=? WHERE id=?")
                ->execute([$buy, $sell, $id]);
            logAction($pdo,$_SESSION['user_id'],'UPDATE','mineral_price_settings',$id,"Updated Buy:{$buy} Sell:{$sell}");
            $pdo->commit();
            $margin = $sell - $buy;
            echo json_encode(['success'=>true,'message'=>'Price setting updated.',
                'purchase_price' => number_format($buy, 2),
                'selling_price'  => number_format($sell, 2),
                'margin'         => number_format(abs($margin), 2),
                'margin_up'      => $margin >= 0,
                'raw_buy'        => $buy,
                'raw_sell'       => $sell,
            ]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    /* Delete price setting */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['delete_price'])){
        try {
            $id = (int)$_POST['id'];
            $pdo->beginTransaction();
            $pdo->prepare("DELETE FROM mineral_price_settings WHERE id=?")->execute([$id]);
            logAction($pdo,$_SESSION['user_id'],'DELETE','mineral_price_settings',$id,"Deleted price setting ID:{$id}");
            $pdo->commit();
            echo json_encode(['success'=>true,'message'=>'Price setting removed.']);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    /* Clear database */
    if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['clear_database'])){
        $scope = $_POST['scope'] ?? '';
        $transactional = [
            'account_transactions','audit_log','inventory','purchase_details','purchase_payments',
            'sale_details','sales','supplier_loans','buyer_loans','transactions','batches','expenses',
        ];
        $all_except_users = array_merge($transactional, ['buyers','suppliers','company_accounts']);
        $full_reset = array_merge($all_except_users, ['mineral_price_settings','mineral_types']);

        $tables = match($scope) {
            'transactional' => $transactional,
            'all'           => $all_except_users,
            'full'          => $full_reset,
            default         => null,
        };

        if(!$tables){
            echo json_encode(['success'=>false,'message'=>'Invalid scope.']);
            exit;
        }

        try {
            $pdo->exec("SET FOREIGN_KEY_CHECKS=0");
            foreach($tables as $t){
                $pdo->exec("TRUNCATE TABLE `".preg_replace('/[^a-z_]/','',$t)."`");
            }
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            echo json_encode(['success'=>true,'message'=>count($tables).' table(s) cleared successfully.']);
        } catch(Exception $e){
            $pdo->exec("SET FOREIGN_KEY_CHECKS=1");
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }
}

/* ── Page data ─────────────────────────────────────────────────── */
$page_title    = 'Global Settings';
$mineral_types = $pdo->query("SELECT * FROM mineral_types ORDER BY name")->fetchAll();

$price_settings = $pdo->query("
    SELECT mps.*, mt.name AS mineral_name, mt.unit
    FROM mineral_price_settings mps
    JOIN mineral_types mt ON mt.id = mps.mineral_type_id
    ORDER BY mt.name, mps.quality_grade
")->fetchAll();

$grade_counts = [];
foreach($price_settings as $ps){
    $grade_counts[$ps['mineral_type_id']] = ($grade_counts[$ps['mineral_type_id']] ?? 0) + 1;
}

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<!-- ═══════════════════════════════════════════════════════════════
     SECTION 1 — MINERAL TYPES
═══════════════════════════════════════════════════════════════ -->
<div class="page-header" style="margin-bottom:0">
    <h2><i class="fas fa-gem" style="margin-right:.4rem;color:var(--text-muted)"></i>Mineral Types</h2>
    <button class="btn btn-primary" onclick="togglePanel('mineral-panel')">
        <i class="fas fa-plus"></i> Add Mineral Type
    </button>
</div>

<!-- Add mineral type slide panel -->
<div class="slide-panel" id="mineral-panel">
    <h3><i class="fas fa-plus-circle" style="margin-right:.4rem"></i>Add Mineral Type</h3>
    <form id="mineral-form">
        <div class="form-grid form-grid-2">
            <div class="form-group">
                <label>Mineral Name <span style="color:var(--danger)">*</span></label>
                <input type="text" name="name" placeholder="e.g. Gold" required>
            </div>
            <div class="form-group">
                <label>Unit of Measure</label>
                <select name="unit">
                    <option value="kg">kg</option>
                    <option value="g">g</option>
                    <option value="ton">ton</option>
                    <option value="oz">oz</option>
                    <option value="lb">lb</option>
                </select>
            </div>
            <div class="form-group" style="grid-column:1/-1">
                <label>Description</label>
                <textarea name="description" placeholder="Optional description…"></textarea>
            </div>
        </div>
        <div class="slide-panel-btns">
            <button type="submit" id="mineral-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Mineral Type
            </button>
            <button type="button" class="btn btn-secondary" onclick="togglePanel('mineral-panel')">Cancel</button>
        </div>
    </form>
</div>

<!-- Mineral types table -->
<div class="table-wrap" style="margin-top:.75rem;margin-bottom:2rem">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Mineral Name</th>
                <th>Unit</th>
                <th>Description</th>
                <th style="text-align:center">Price Grades</th>
                <th style="text-align:right">Actions</th>
            </tr>
        </thead>
        <tbody id="mineral-tbody">
            <?php foreach($mineral_types as $i => $mt): ?>
            <tr id="mrow-<?= $mt['id'] ?>">
                <td class="text-muted"><?= $i + 1 ?></td>
                <td class="fw-700"><?= htmlspecialchars($mt['name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($mt['unit']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($mt['description'] ?? '—') ?></td>
                <td style="text-align:center">
                    <?php $cnt = $grade_counts[$mt['id']] ?? 0; ?>
                    <span class="badge <?= $cnt > 0 ? 'badge-success' : 'badge-warning' ?>"
                          id="mps-count-<?= $mt['id'] ?>">
                        <?= $cnt ?> grade<?= $cnt !== 1 ? 's' : '' ?>
                    </span>
                </td>
                <td style="text-align:right">
                    <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.78rem"
                            onclick="deleteMineral(<?= $mt['id'] ?>, this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$mineral_types): ?>
            <tr id="mineral-empty">
                <td colspan="6" style="text-align:center;padding:2.5rem;color:var(--text-muted)">
                    <i class="fas fa-gem" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>
                    No mineral types defined yet.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- ═══════════════════════════════════════════════════════════════
     SECTION 2 — PRICE SETTINGS
═══════════════════════════════════════════════════════════════ -->
<div class="page-header" style="margin-bottom:0">
    <h2><i class="fas fa-tags" style="margin-right:.4rem;color:var(--text-muted)"></i>Price Settings</h2>
    <button class="btn btn-primary" onclick="togglePanel('price-panel')">
        <i class="fas fa-plus"></i> Add Price Setting
    </button>
</div>

<!-- Add price setting slide panel -->
<div class="slide-panel" id="price-panel">
    <h3><i class="fas fa-tag" style="margin-right:.4rem"></i>Set Price by Grade</h3>
    <form id="price-form">
        <div class="form-grid form-grid-2">
            <div class="form-group">
                <label>Mineral Type <span style="color:var(--danger)">*</span></label>
                <select name="mineral_type_id" id="price-mineral-select" required>
                    <option value="">— Select Mineral —</option>
                    <?php foreach($mineral_types as $mt): ?>
                    <option value="<?= $mt['id'] ?>"><?= htmlspecialchars($mt['name']) ?> (<?= $mt['unit'] ?>)</option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label>Quality Grade <span style="color:var(--danger)">*</span></label>
                <input type="text" name="quality_grade" placeholder="e.g. A, AA, Premium" required maxlength="20">
            </div>
            <div class="form-group">
                <label>Purchase Price / unit <span style="color:var(--danger)">*</span></label>
                <input type="number" name="purchase_price" placeholder="0.00" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label>Selling Price / unit <span style="color:var(--danger)">*</span></label>
                <input type="number" name="selling_price" placeholder="0.00" step="0.01" min="0" required>
            </div>
        </div>
        <div class="slide-panel-btns">
            <button type="submit" id="price-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Price Setting
            </button>
            <button type="button" class="btn btn-secondary" onclick="togglePanel('price-panel')">Cancel</button>
        </div>
    </form>
</div>

<!-- Edit price setting slide panel -->
<div class="slide-panel" id="edit-price-panel">
    <h3><i class="fas fa-pen" style="margin-right:.4rem"></i>Edit Price Setting</h3>
    <form id="edit-price-form">
        <input type="hidden" name="id" id="edit-price-id">
        <div class="form-grid form-grid-2">
            <div class="form-group">
                <label>Mineral Type</label>
                <input type="text" id="edit-mineral-name" readonly
                       style="background:var(--surface);color:var(--text-muted);cursor:default">
            </div>
            <div class="form-group">
                <label>Quality Grade</label>
                <input type="text" id="edit-grade-name" readonly
                       style="background:var(--surface);color:var(--text-muted);cursor:default">
            </div>
            <div class="form-group">
                <label>Purchase Price / unit <span style="color:var(--danger)">*</span></label>
                <input type="number" name="purchase_price" id="edit-buy-price" step="0.01" min="0" required>
            </div>
            <div class="form-group">
                <label>Selling Price / unit <span style="color:var(--danger)">*</span></label>
                <input type="number" name="selling_price" id="edit-sell-price" step="0.01" min="0" required>
            </div>
        </div>
        <div class="slide-panel-btns">
            <button type="submit" id="edit-price-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Update Price
            </button>
            <button type="button" class="btn btn-secondary" onclick="togglePanel('edit-price-panel')">Cancel</button>
        </div>
    </form>
</div>

<!-- Price settings table -->
<div class="table-wrap" style="margin-top:.75rem">
    <table>
        <thead>
            <tr>
                <th>Mineral Type</th>
                <th>Unit</th>
                <th>Quality Grade</th>
                <th style="text-align:right">Purchase Price</th>
                <th style="text-align:right">Selling Price</th>
                <th style="text-align:right">Margin</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="price-tbody">
            <?php foreach($price_settings as $ps):
                $margin = $ps['selling_price'] - $ps['purchase_price'];
            ?>
            <tr id="prow-<?= $ps['id'] ?>"
                data-mineral-id="<?= $ps['mineral_type_id'] ?>"
                data-buy="<?= $ps['purchase_price'] ?>"
                data-sell="<?= $ps['selling_price'] ?>"
                data-mineral="<?= htmlspecialchars($ps['mineral_name'], ENT_QUOTES) ?>"
                data-grade="<?= htmlspecialchars($ps['quality_grade'], ENT_QUOTES) ?>">
                <td class="fw-700"><?= htmlspecialchars($ps['mineral_name']) ?></td>
                <td class="text-muted"><?= htmlspecialchars($ps['unit']) ?></td>
                <td><span class="badge badge-warning"><?= htmlspecialchars($ps['quality_grade']) ?></span></td>
                <td style="text-align:right" class="p-buy fw-600">
                    $<?= number_format($ps['purchase_price'], 2) ?>
                </td>
                <td style="text-align:right" class="p-sell fw-600">
                    $<?= number_format($ps['selling_price'], 2) ?>
                </td>
                <td style="text-align:right" class="p-margin">
                    <span class="badge <?= $margin >= 0 ? 'badge-success' : 'badge-danger' ?>">
                        $<?= number_format(abs($margin), 2) ?> <?= $margin >= 0 ? '▲' : '▼' ?>
                    </span>
                </td>
                <td style="text-align:center">
                    <button class="btn btn-secondary"
                            style="padding:.3rem .6rem;font-size:.78rem;margin-right:.3rem"
                            onclick="openEditPrice(<?= $ps['id'] ?>)">
                        <i class="fas fa-pen"></i>
                    </button>
                    <button class="btn btn-danger"
                            style="padding:.3rem .6rem;font-size:.78rem"
                            onclick="deletePrice(<?= $ps['id'] ?>, this)">
                        <i class="fas fa-trash"></i>
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(!$price_settings): ?>
            <tr id="price-empty">
                <td colspan="7" style="text-align:center;padding:2.5rem;color:var(--text-muted)">
                    <i class="fas fa-tags" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>
                    No price settings yet. Add your first price setting above.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
/* ── Utilities ──────────────────────────────────────────────────── */
function togglePanel(id){
    const target = document.getElementById(id);
    const isOpen = target.classList.contains('open');
    document.querySelectorAll('.slide-panel').forEach(p => p.classList.remove('open'));
    if(!isOpen) target.classList.add('open');
}

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

function showAlert(type, msg){
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-'+type+' mb-15';
    el.innerHTML = '<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(()=>{ el.style.display='none'; }, 5000);
    el.scrollIntoView({behavior:'smooth',block:'nearest'});
}

function ajaxPost(data, cb){
    const fd = new FormData();
    Object.entries(data).forEach(([k,v]) => fd.append(k, v));
    fetch('settings.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
        .then(r => r.json()).then(cb)
        .catch(() => showAlert('error','Network error. Please try again.'));
}

/* ── Mineral Types ──────────────────────────────────────────────── */
document.getElementById('mineral-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('mineral-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData(this);
    fd.append('add_mineral','1');
    fetch('settings.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            showAlert('success', d.message);
            appendMineralRow(d.mineral);
            const sel = document.getElementById('price-mineral-select');
            const opt = new Option(esc(d.mineral.name)+' ('+esc(d.mineral.unit)+')', d.mineral.id);
            sel.appendChild(opt);
            togglePanel('mineral-panel');
            this.reset();
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Mineral Type';
    });
});

function appendMineralRow(m){
    const tbody = document.getElementById('mineral-tbody');
    const empty = document.getElementById('mineral-empty');
    if(empty) empty.remove();
    const num = tbody.querySelectorAll('tr').length + 1;
    const tr = document.createElement('tr');
    tr.id = 'mrow-'+m.id;
    tr.innerHTML =
        `<td class="text-muted">${num}</td>
        <td class="fw-700">${esc(m.name)}</td>
        <td class="text-muted">${esc(m.unit)}</td>
        <td class="text-muted">${m.description ? esc(m.description) : '—'}</td>
        <td style="text-align:center">
            <span class="badge badge-warning" id="mps-count-${m.id}">0 grades</span>
        </td>
        <td style="text-align:right">
            <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.78rem"
                    onclick="deleteMineral(${m.id}, this)">
                <i class="fas fa-trash"></i>
            </button>
        </td>`;
    tbody.appendChild(tr);
}

function deleteMineral(id, btn){
    if(!confirm('Delete this mineral type? All its price settings will also be removed.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    ajaxPost({delete_mineral:1, id:id}, d => {
        if(d.success){
            document.getElementById('mrow-'+id)?.remove();
            document.querySelectorAll('#price-tbody tr[data-mineral-id="'+id+'"]').forEach(r => r.remove());
            checkPriceEmpty();
            const sel = document.getElementById('price-mineral-select');
            sel.querySelector('option[value="'+id+'"]')?.remove();
            showAlert('success', d.message);
        } else {
            showAlert('error', d.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i>';
        }
    });
}

/* ── Price Settings ─────────────────────────────────────────────── */
document.getElementById('price-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('price-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData(this);
    fd.append('add_price','1');
    fetch('settings.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            showAlert('success', d.message);
            prependPriceRow(d.row);
            updateGradeCount(d.row.mineral_type_id, 1);
            togglePanel('price-panel');
            this.reset();
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Price Setting';
    });
});

function prependPriceRow(r){
    const tbody = document.getElementById('price-tbody');
    document.getElementById('price-empty')?.remove();
    const margClass = r.margin_up ? 'badge-success' : 'badge-danger';
    const margSign  = r.margin_up ? '▲' : '▼';
    const tr = document.createElement('tr');
    tr.id = 'prow-'+r.id;
    tr.setAttribute('data-mineral-id', r.mineral_type_id);
    tr.setAttribute('data-buy',  r.raw_buy);
    tr.setAttribute('data-sell', r.raw_sell);
    tr.setAttribute('data-mineral', r.mineral_name);
    tr.setAttribute('data-grade', r.quality_grade);
    tr.innerHTML =
        `<td class="fw-700">${esc(r.mineral_name)}</td>
        <td class="text-muted">${esc(r.unit)}</td>
        <td><span class="badge badge-warning">${esc(r.quality_grade)}</span></td>
        <td style="text-align:right" class="p-buy fw-600">$${esc(r.purchase_price)}</td>
        <td style="text-align:right" class="p-sell fw-600">$${esc(r.selling_price)}</td>
        <td style="text-align:right" class="p-margin">
            <span class="badge ${margClass}">$${esc(r.margin)} ${margSign}</span>
        </td>
        <td style="text-align:center">
            <button class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.78rem;margin-right:.3rem"
                    onclick="openEditPrice(${r.id})">
                <i class="fas fa-pen"></i>
            </button>
            <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.78rem"
                    onclick="deletePrice(${r.id}, this)">
                <i class="fas fa-trash"></i>
            </button>
        </td>`;
    tbody.insertBefore(tr, tbody.firstChild);
}

function openEditPrice(id){
    const row = document.getElementById('prow-'+id);
    document.getElementById('edit-price-id').value    = id;
    document.getElementById('edit-mineral-name').value = row.dataset.mineral;
    document.getElementById('edit-grade-name').value   = row.dataset.grade;
    document.getElementById('edit-buy-price').value    = row.dataset.buy;
    document.getElementById('edit-sell-price').value   = row.dataset.sell;
    document.querySelectorAll('.slide-panel').forEach(p => p.classList.remove('open'));
    document.getElementById('edit-price-panel').classList.add('open');
}

document.getElementById('edit-price-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('edit-price-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating…';

    const fd = new FormData(this);
    fd.append('update_price','1');
    fetch('settings.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
    .then(r => r.json())
    .then(d => {
        if(d.success){
            const id  = document.getElementById('edit-price-id').value;
            const row = document.getElementById('prow-'+id);
            row.setAttribute('data-buy',  d.raw_buy);
            row.setAttribute('data-sell', d.raw_sell);
            row.querySelector('.p-buy').textContent  = '$'+d.purchase_price;
            row.querySelector('.p-sell').textContent = '$'+d.selling_price;
            const badge = row.querySelector('.p-margin span');
            badge.className   = 'badge '+(d.margin_up ? 'badge-success' : 'badge-danger');
            badge.textContent = '$'+d.margin+' '+(d.margin_up ? '▲' : '▼');
            showAlert('success', d.message);
            togglePanel('edit-price-panel');
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Update Price';
    });
});

function deletePrice(id, btn){
    if(!confirm('Remove this price setting?')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    ajaxPost({delete_price:1, id:id}, d => {
        if(d.success){
            const row = document.getElementById('prow-'+id);
            const mid = row?.dataset.mineralId;
            row.style.transition = 'opacity .3s';
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
                updateGradeCount(mid, -1);
                checkPriceEmpty();
            }, 300);
            showAlert('success', d.message);
        } else {
            showAlert('error', d.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i>';
        }
    });
}

/* ── Helpers ───────────────────────────────────────────────────── */
function updateGradeCount(mineralId, delta){
    const el = document.getElementById('mps-count-'+mineralId);
    if(!el) return;
    const n = Math.max(0, (parseInt(el.textContent) || 0) + delta);
    el.textContent = n + ' grade' + (n !== 1 ? 's' : '');
    el.className = 'badge ' + (n > 0 ? 'badge-success' : 'badge-warning');
}

function checkPriceEmpty(){
    const tbody = document.getElementById('price-tbody');
    if(!tbody.querySelector('tr')) {
        tbody.innerHTML =
            '<tr id="price-empty"><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--text-muted)">'+
            '<i class="fas fa-tags" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>'+
            'No price settings yet.</td></tr>';
    }
}
</script>

<!-- ═══════════════════════════════════════════════════════════════
     SECTION 3 — DANGER ZONE
═══════════════════════════════════════════════════════════════ -->
<div style="margin-top:2.5rem;border:2px solid var(--danger);border-radius:var(--radius);padding:1.5rem">
    <h2 style="color:var(--danger);margin-bottom:.4rem">
        <i class="fas fa-triangle-exclamation" style="margin-right:.4rem"></i>Danger Zone
    </h2>
    <p style="color:var(--text-muted);margin-bottom:1.25rem;font-size:.9rem">
        These actions are <strong>irreversible</strong>. User accounts are always preserved.
    </p>

    <div style="display:flex;flex-direction:column;gap:.75rem">
        <div style="display:flex;align-items:center;justify-content:space-between;padding:.85rem 1rem;background:var(--surface);border-radius:var(--radius);flex-wrap:wrap;gap:.5rem">
            <div>
                <div class="fw-700">Clear Transactional Data</div>
                <div style="font-size:.82rem;color:var(--text-muted)">Removes batches, sales, purchases, inventory movements, transactions, loans &amp; audit log. Keeps buyers, suppliers, mineral types &amp; prices.</div>
            </div>
            <button class="btn btn-danger" style="white-space:nowrap" onclick="openClearModal('transactional','Clear Transactional Data')">
                <i class="fas fa-eraser"></i> Clear
            </button>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;padding:.85rem 1rem;background:var(--surface);border-radius:var(--radius);flex-wrap:wrap;gap:.5rem">
            <div>
                <div class="fw-700">Clear All Business Data</div>
                <div style="font-size:.82rem;color:var(--text-muted)">Everything above <em>plus</em> buyers, suppliers &amp; company accounts. Keeps mineral types &amp; prices.</div>
            </div>
            <button class="btn btn-danger" style="white-space:nowrap" onclick="openClearModal('all','Clear All Business Data')">
                <i class="fas fa-trash-can"></i> Clear
            </button>
        </div>

        <div style="display:flex;align-items:center;justify-content:space-between;padding:.85rem 1rem;background:var(--surface);border-radius:var(--radius);flex-wrap:wrap;gap:.5rem">
            <div>
                <div class="fw-700">Full Reset</div>
                <div style="font-size:.82rem;color:var(--text-muted)">Everything above <em>plus</em> mineral types &amp; price settings. Only user accounts survive.</div>
            </div>
            <button class="btn btn-danger" style="white-space:nowrap" onclick="openClearModal('full','Full Database Reset')">
                <i class="fas fa-bomb"></i> Reset
            </button>
        </div>
    </div>
</div>

<!-- Confirmation modal -->
<div id="clear-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:9999;align-items:center;justify-content:center">
    <div style="background:var(--card-bg);border-radius:var(--radius);padding:2rem;max-width:420px;width:90%;box-shadow:0 8px 32px rgba(0,0,0,.35)">
        <h3 style="color:var(--danger);margin-bottom:.5rem" id="clear-modal-title">Confirm</h3>
        <p style="color:var(--text-muted);font-size:.88rem;margin-bottom:1rem">
            Type <strong>CLEAR</strong> in the box below to confirm. This cannot be undone.
        </p>
        <input type="text" id="clear-confirm-input" placeholder="Type CLEAR here"
               style="width:100%;box-sizing:border-box;margin-bottom:1rem;text-transform:uppercase"
               oninput="document.getElementById('clear-confirm-btn').disabled = this.value.toUpperCase() !== 'CLEAR'">
        <div style="display:flex;gap:.75rem;justify-content:flex-end">
            <button class="btn btn-secondary" onclick="closeClearModal()">Cancel</button>
            <button id="clear-confirm-btn" class="btn btn-danger" disabled onclick="executeClear()">
                <i class="fas fa-triangle-exclamation"></i> Confirm &amp; Clear
            </button>
        </div>
    </div>
</div>

<script>
let _clearScope = '';

function openClearModal(scope, title){
    _clearScope = scope;
    document.getElementById('clear-modal-title').textContent = title;
    document.getElementById('clear-confirm-input').value = '';
    document.getElementById('clear-confirm-btn').disabled = true;
    document.getElementById('clear-modal').style.display = 'flex';
}

function closeClearModal(){
    document.getElementById('clear-modal').style.display = 'none';
    _clearScope = '';
}

function executeClear(){
    const btn = document.getElementById('clear-confirm-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Clearing…';

    const fd = new FormData();
    fd.append('clear_database','1');
    fd.append('scope', _clearScope);
    fetch('settings.php', { method:'POST', headers:{'X-Requested-With':'XMLHttpRequest'}, body:fd })
    .then(r => r.json())
    .then(d => {
        closeClearModal();
        showAlert(d.success ? 'success' : 'error', d.message);
        if(d.success && (_clearScope === 'full')){
            document.getElementById('mineral-tbody').innerHTML =
                '<tr id="mineral-empty"><td colspan="6" style="text-align:center;padding:2.5rem;color:var(--text-muted)">'+
                '<i class="fas fa-gem" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>No mineral types defined yet.</td></tr>';
            document.getElementById('price-tbody').innerHTML =
                '<tr id="price-empty"><td colspan="7" style="text-align:center;padding:2.5rem;color:var(--text-muted)">'+
                '<i class="fas fa-tags" style="font-size:1.5rem;display:block;margin-bottom:.5rem"></i>No price settings yet.</td></tr>';
            document.getElementById('price-mineral-select').innerHTML = '<option value="">— Select Mineral —</option>';
        }
    })
    .catch(() => { closeClearModal(); showAlert('error','Network error.'); });
}

document.getElementById('clear-modal').addEventListener('click', function(e){
    if(e.target === this) closeClearModal();
});
</script>

<?php include 'includes/footer.php'; ?>
