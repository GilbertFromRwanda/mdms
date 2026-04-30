<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX: save sale ─────────────────────────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    try {
        $minerals_post = $_POST['mineral'] ?? [];
        if(empty($minerals_post)) throw new Exception('No minerals selected.');

        $buyer_id  = $_POST['buyer_id'];
        $currency  = in_array($_POST['currency'] ?? '', ['USD','FRW']) ? $_POST['currency'] : 'FRW';
        $sale_date = $_POST['sale_date'];
        $notes     = $_POST['notes'] ?? '';

        $pdo->beginTransaction();

        $created = [];
        foreach($minerals_post as $mid => $mdata){
            $sale_id  = 'SALE-'.date('Ymd').'-'.rand(1000,9999);
            $qty      = floatval($mdata['quantity']      ?? 0);

            /* Stock check */
            $ck = $pdo->prepare("SELECT current_stock FROM inventory WHERE mineral_type_id=? FOR UPDATE");
            $ck->execute([$mid]);
            $row_ck = $ck->fetch();
            if(!$row_ck) throw new Exception("No inventory record found for mineral ID $mid.");
            $stock = floatval($row_ck['current_stock']);
            if($qty <= 0) throw new Exception("Quantity must be greater than zero.");
            if($qty > $stock){
                $mn = $pdo->prepare("SELECT name FROM mineral_types WHERE id=?");
                $mn->execute([$mid]);
                $mname = $mn->fetch()['name'] ?? "Mineral $mid";
                throw new Exception("Insufficient stock for <strong>$mname</strong>. Available: <strong>".number_format($stock,3)." kg</strong>, requested: <strong>".number_format($qty,3)." kg</strong>.");
            }
            $sell_pu  = ($mdata['selling_price'] ?? '') !== '' ? floatval($mdata['selling_price']) : null;
            $cost_pu  = ($mdata['cost_price']    ?? '') !== '' ? floatval($mdata['cost_price'])    : null;
            $revenue  = $sell_pu !== null ? round($sell_pu * $qty, 2) : null;
            $cost     = $cost_pu !== null ? round($cost_pu * $qty, 2) : null;
            $benefit  = ($revenue !== null && $cost !== null) ? round($revenue - $cost, 2) : null;

            $pdo->prepare("
                INSERT INTO sales
                  (sale_id,mineral_type_id,buyer_id,quantity,sale_date,notes,created_by)
                VALUES (?,?,?,?,?,?,?)
            ")->execute([$sale_id,$mid,$buyer_id,$qty,$sale_date,$notes,$_SESSION['user_id']]);
            $lastId = $pdo->lastInsertId();

            $pdo->prepare("
                INSERT INTO sale_details
                  (sale_id,mineral_id,buyer_id,sale_date,currency_used,qty,
                   selling_price,cost_price,total_revenue,total_cost,benefit,notes,created_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)
            ")->execute([
                $lastId,$mid,$buyer_id,$sale_date,$currency,$qty,
                $sell_pu,$cost_pu,$revenue,$cost,$benefit,$notes,$_SESSION['user_id']
            ]);

            $tc = 'TRX-'.date('YmdHis').'-'.rand(10,99);
            $pdo->prepare("
                INSERT INTO transactions
                  (transaction_code,transaction_type,batch_id,mineral_type_id,
                   quantity,transaction_date,price_per_unit,total_amount,created_by)
                VALUES (?,'OUT',NULL,?,?,?,?,?,?)
            ")->execute([$tc,$mid,$qty,$sale_date,$sell_pu,$revenue,$_SESSION['user_id']]);

            logAction($pdo,$_SESSION['user_id'],'CREATE','sales',$lastId,"Added sale: $sale_id");

            $row = $pdo->prepare("SELECT mt.name AS mineral_name, b.name AS buyer_name FROM mineral_types mt, buyers b WHERE mt.id=? AND b.id=?");
            $row->execute([$mid,$buyer_id]);
            $names = $row->fetch();

            $created[] = [
                'sale_id'      => $sale_id,
                'mineral_name' => $names['mineral_name'],
                'buyer_name'   => $names['buyer_name'],
                'quantity'     => $qty,
                'benefit'      => $benefit,
                'currency'     => $currency,
            ];
        }

        $pdo->commit();
        echo json_encode(['success' => true, 'count' => count($created)]);
    } catch(Exception $e){
        if($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['success'=>false,'message'=>'Error: '.$e->getMessage()]);
    }
    exit;
}

/* ── Page data ───────────────────────────────────────────────── */
$page_title = 'New Sale';

$minerals = $pdo->query("SELECT * FROM mineral_types ORDER BY name")->fetchAll();
$buyers   = $pdo->query("SELECT * FROM buyers ORDER BY name")->fetchAll();

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

// Auto-fill cost price from most recent purchase per mineral
$cost_defaults = [];
foreach($special_minerals as $sm){
    if(!$sm['id']) continue;
    $r = $pdo->prepare("SELECT unit_price FROM purchase_details WHERE mineral_id=? AND unit_price IS NOT NULL ORDER BY id DESC LIMIT 1");
    $r->execute([$sm['id']]);
    $val = $r->fetchColumn();
    if($val !== false) $cost_defaults[(int)$sm['id']] = round((float)$val, 4);
}

// Available stock per mineral
$stock_available = [];
foreach($special_minerals as $sm){
    if(!$sm['id']) continue;
    $r = $pdo->prepare("SELECT current_stock FROM inventory WHERE mineral_type_id=?");
    $r->execute([$sm['id']]);
    $val = $r->fetchColumn();
    $stock_available[(int)$sm['id']] = $val !== false ? round((float)$val, 3) : 0;
}

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-cart-arrow-down" style="margin-right:.4rem;color:var(--text-muted)"></i>New Sale</h2>
    <a href="sales.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Sales
    </a>
</div>

<form id="sale-form" style="max-width:960px">

    <!-- Buyer info -->
    <div style="border:1px solid var(--border);border-radius:8px;padding:1.25rem;margin-bottom:1rem;background:var(--surface,var(--bg))">
        <div style="font-weight:600;font-size:.82rem;margin-bottom:.85rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
            <i class="fas fa-handshake"></i> Sale Info
        </div>
        <div class="form-grid form-grid-2">
            <div class="form-group">
                <label>Buyer</label>
                <select name="buyer_id" required>
                    <option value="">— Select buyer —</option>
                    <?php foreach($buyers as $b): ?>
                    <option value="<?= $b['id'] ?>"><?= htmlspecialchars($b['name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if(empty($buyers)): ?>
                <small style="color:#f59e0b"><i class="fas fa-triangle-exclamation"></i> No buyers found — add buyers first.</small>
                <?php endif; ?>
            </div>
            <div class="form-group">
                <label>Sale Date</label>
                <input type="date" name="sale_date" required value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label>Currency</label>
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
            <i class="fas fa-gem"></i> Minerals Sold
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

        <!-- Benefit Summary -->
        <div id="global-summary" style="display:none;margin-top:1rem">
            <div style="border:2px solid #10b981;border-radius:8px;padding:1rem">
                <div style="font-weight:700;font-size:.9rem;margin-bottom:.75rem;color:#10b981">
                    <i class="fas fa-chart-line"></i> Benefit Summary
                </div>
                <div id="global-summary-body"></div>
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
        <a href="sales.php" class="btn btn-secondary">
            <i class="fas fa-xmark"></i> Cancel
        </a>
        <button type="submit" id="sale-save-btn" class="btn btn-primary" style="background:#10b981;border-color:#10b981">
            <i class="fas fa-save"></i> Save Sale
        </button>
    </div>

</form>

<script>
const costDefaults    = <?= json_encode($cost_defaults) ?>;
const stockAvailable  = <?= json_encode($stock_available) ?>;

const cardCats    = {};
const cardNames   = {};
const cardSummary = {};
const cardErrors  = {};

const CARD_COLORS = { cassiterite: '#3b82f6', coltan: '#8b5cf6', wolframite: '#10b981' };

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

function fmtRWF(v) { return v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' FRW'; }
function fmtUSD(v) { return '$' + v.toFixed(4); }

/* ── Card builder ───────────────────────────────────────────── */
function buildCard(id, name, cat) {
    const color    = CARD_COLORS[cat] || 'var(--primary)';
    const costDef  = costDefaults[id] || '';
    const avail    = stockAvailable[id] != null ? stockAvailable[id] : '—';
    const availFmt = typeof avail === 'number' ? avail.toLocaleString('en-US',{minimumFractionDigits:3,maximumFractionDigits:3}) + ' kg' : avail;

    return `<div id="card-${id}" style="border:1px solid ${color}44;border-left:4px solid ${color};border-radius:8px;padding:1rem;background:var(--surface,var(--bg))">
        <input type="hidden" name="mineral[${id}][selling_price]" id="c${id}-h-sell">
        <input type="hidden" name="mineral[${id}][cost_price]"    id="c${id}-h-cost">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem">
            <div style="font-weight:700;font-size:.9rem;color:${color}">
                <i class="fas fa-gem"></i> ${esc(name)}
                <span style="font-weight:400;font-size:.78rem;color:var(--text-muted);margin-left:.5rem">
                    <i class="fas fa-warehouse"></i> Available: <strong id="c${id}-avail-label">${availFmt}</strong>
                </span>
            </div>
            <button type="button" onclick="collapseCard(${id})"
                style="background:none;border:1px solid ${color}55;cursor:pointer;color:${color};font-size:.78rem;padding:.2rem .5rem;border-radius:4px;line-height:1">
                <i class="fas fa-chevron-up" id="c${id}-chevron"></i>
            </button>
        </div>
        <div id="c${id}-body">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem .9rem">
                <div class="form-group">
                    <label>Quantity (kg)</label>
                    <input type="text" name="mineral[${id}][quantity]" id="c${id}-qty" placeholder="0.000" oninput="calcCard(${id})">
                </div>
                <div class="form-group">
                    <label>Selling Price (FRW/kg)</label>
                    <input type="text" id="c${id}-sell" placeholder="0.00" oninput="calcCard(${id})">
                </div>
                <div class="form-group">
                    <label>Cost Price (FRW/kg)</label>
                    <input type="text" id="c${id}-cost" value="${costDef}" placeholder="0.00" oninput="calcCard(${id})">
                </div>
            </div>
            <div id="c${id}-qty-err" style="display:none;color:#dc2626;font-size:.82rem;margin-bottom:.4rem">
                <i class="fas fa-triangle-exclamation"></i> <span></span>
            </div>
            <div id="c${id}-breakdown" style="display:none;margin-top:.7rem;border-top:1px solid ${color}33;padding-top:.6rem">
                <table style="width:100%;font-size:.82rem;border-collapse:collapse">
                    <tbody id="c${id}-rows"></tbody>
                </table>
            </div>
        </div>
    </div>`;
}

function collapseCard(id) {
    const body    = document.getElementById('c'+id+'-body');
    const chevron = document.getElementById('c'+id+'-chevron');
    if (!body) return;
    const collapsed = body.style.display === 'none';
    body.style.display  = collapsed ? '' : 'none';
    chevron.className   = collapsed ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
}

function toggleMineralCard(cb) {
    const id = cb.value, name = cb.dataset.name, cat = cb.dataset.cat;
    if (cb.checked) {
        cardCats[id] = cat; cardNames[id] = name;
        document.getElementById('mineral-cards').insertAdjacentHTML('beforeend', buildCard(id, name, cat));
        calcCard(id);
    } else {
        delete cardCats[id]; delete cardNames[id]; delete cardSummary[id]; delete cardErrors[id];
        const card = document.getElementById('card-'+id);
        if (card) card.remove();
        updateGlobalSummary();
    }
}

/* ── Per-card calculation ───────────────────────────────────── */
function calcCard(id) {
    const sellPx = parseFloat(document.getElementById('c'+id+'-sell').value) || 0;
    const costPx = parseFloat(document.getElementById('c'+id+'-cost').value) || 0;
    const qty    = parseFloat(document.getElementById('c'+id+'-qty').value)  || 0;

    // Stock validation
    const avail   = stockAvailable[id] != null ? parseFloat(stockAvailable[id]) : Infinity;
    const errEl   = document.getElementById('c'+id+'-qty-err');
    const qtyInp  = document.getElementById('c'+id+'-qty');
    if (qty > 0 && qty > avail) {
        cardErrors[id] = true;
        if (errEl) {
            errEl.style.display = '';
            errEl.querySelector('span').textContent =
                'Insufficient stock — available: ' + avail.toLocaleString('en-US',{minimumFractionDigits:3,maximumFractionDigits:3}) + ' kg';
        }
        if (qtyInp) qtyInp.style.borderColor = '#dc2626';
    } else {
        delete cardErrors[id];
        if (errEl) errEl.style.display = 'none';
        if (qtyInp) qtyInp.style.borderColor = '';
    }

    const revenue = sellPx * qty;
    const cost    = costPx * qty;
    const benefit = revenue - cost;
    const margin  = revenue > 0 ? (benefit / revenue * 100) : 0;

    // Write hidden fields for submission
    const hSell = document.getElementById('c'+id+'-h-sell');
    const hCost = document.getElementById('c'+id+'-h-cost');
    if (hSell) hSell.value = sellPx || '';
    if (hCost) hCost.value = costPx || '';

    // Breakdown rows
    const rows = [
        ['Selling Price / kg',              fmtRWF(sellPx)],
        ['Cost Price / kg',                 fmtRWF(costPx)],
        ['× Quantity',                      qty.toFixed(3)+' kg'],
        null,
        ['Total Revenue',                   fmtRWF(revenue), true],
        ['Total Cost',                      fmtRWF(cost),    true],
        null,
        ['Benefit',                         fmtRWF(benefit), true],
        ['Margin',                          margin.toFixed(2)+'%'],
    ];

    const breakdown = document.getElementById('c'+id+'-breakdown');
    const tbody     = document.getElementById('c'+id+'-rows');
    if (breakdown && tbody) {
        tbody.innerHTML = rows.map(r => {
            if (!r) return '<tr><td colspan="2"><hr style="border:none;border-top:1px solid var(--border);margin:.2rem 0"></td></tr>';
            const b  = r[2] ? 'font-weight:700;' : '';
            const bg = r[2] ? 'background:rgba(16,185,129,.07);' : '';
            return `<tr style="${bg}">
                <td style="${b}padding:.22rem .5rem;color:var(--text-muted)">${esc(r[0])}</td>
                <td style="${b}padding:.22rem .5rem;text-align:right;font-family:monospace">${esc(String(r[1]||''))}</td>
            </tr>`;
        }).join('');
        breakdown.style.display = '';
    }

    cardSummary[id] = { revenue, cost, benefit, qty };
    updateGlobalSummary();
}

/* ── Benefit summary ────────────────────────────────────────── */
function updateGlobalSummary() {
    const el   = document.getElementById('global-summary');
    const body = document.getElementById('global-summary-body');
    if (!el || !body) return;

    const ids = Object.keys(cardSummary);
    if (ids.length === 0) { el.style.display = 'none'; return; }
    el.style.display = '';

    let totalRev = 0, totalCost = 0, html = '';

    ids.forEach(id => {
        const { revenue, cost, benefit, qty } = cardSummary[id];
        totalRev  += revenue;
        totalCost += cost;
        const margin = revenue > 0 ? (benefit / revenue * 100).toFixed(1) : '0.0';
        html += `<div style="display:grid;grid-template-columns:1fr repeat(3,auto);gap:.3rem .9rem;align-items:center;padding:.3rem 0;font-size:.85rem;border-bottom:1px solid var(--border)">
            <span style="color:var(--text-muted)">${esc(cardNames[id]||'Mineral')} <small>(${qty.toFixed(3)} kg)</small></span>
            <span style="font-family:monospace">${fmtRWF(revenue)}</span>
            <span style="font-family:monospace;color:#dc2626">− ${fmtRWF(cost)}</span>
            <span style="font-family:monospace;font-weight:700;color:#10b981">= ${fmtRWF(benefit)}</span>
        </div>`;
    });

    if (ids.length > 1) {
        html += `<div style="display:grid;grid-template-columns:1fr repeat(3,auto);gap:.3rem .9rem;align-items:center;padding:.35rem 0;font-size:.85rem">
            <span style="font-weight:600">Subtotals</span>
            <span style="font-family:monospace;font-weight:600">${fmtRWF(totalRev)}</span>
            <span style="font-family:monospace;font-weight:600;color:#dc2626">− ${fmtRWF(totalCost)}</span>
            <span></span>
        </div>`;
    }

    const totalBenefit = totalRev - totalCost;
    const totalMargin  = totalRev > 0 ? (totalBenefit / totalRev * 100).toFixed(1) : '0.0';

    html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0 0;margin-top:.3rem;border-top:2px solid #10b981">
        <span style="font-weight:700;font-size:.95rem">NET BENEFIT <small style="font-weight:400;color:var(--text-muted)">(${totalMargin}% margin)</small></span>
        <span style="font-family:monospace;font-weight:700;font-size:1.1rem;color:#10b981">${fmtRWF(totalBenefit)}</span>
    </div>`;

    body.innerHTML = html;
}

function onCurrencyChange() {
    Object.keys(cardCats).forEach(id => calcCard(id));
}

/* ── Submit ─────────────────────────────────────────────────── */
document.getElementById('sale-form').addEventListener('submit', function(e) {
    e.preventDefault();

    if (Object.keys(cardCats).length === 0) {
        showAlert('error', 'Please select at least one mineral.');
        return;
    }

    if (Object.keys(cardErrors).length > 0) {
        showAlert('error', 'Please fix the quantity errors before saving.');
        return;
    }

    const btn = document.getElementById('sale-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    fetch('new-sales.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: new FormData(this)
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            window.location.href = 'sales.php?created=' + d.count;
        } else {
            showAlert('error', d.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save Sale';
        }
    })
    .catch(() => {
        showAlert('error', 'Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save Sale';
    });
});
</script>

<?php include 'includes/footer.php'; ?>
