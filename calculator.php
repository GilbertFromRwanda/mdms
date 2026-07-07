<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

$page_title = 'Calculator';

$minerals = $pdo->query("SELECT * FROM mineral_types ORDER BY name")->fetchAll();

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

<div class="page-header">
    <h2><i class="fas fa-calculator" style="margin-right:.4rem;color:var(--text-muted)"></i>Price Calculator</h2>
    <button type="button" class="btn btn-secondary" onclick="resetCalculator()">
        <i class="fas fa-rotate-left"></i> Reset
    </button>
</div>

<div style="max-width:960px;margin:0 auto">

    <!-- Currency -->
    <div style="border:1px solid var(--border);border-radius:8px;padding:1.25rem;margin-bottom:1rem;background:var(--surface,var(--bg))">
        <div style="font-weight:600;font-size:.82rem;margin-bottom:.85rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
            <i class="fas fa-sliders"></i> Settings
        </div>
        <div class="form-grid form-grid-2">
            <div class="form-group" style="margin:0">
                <label>Display Currency</label>
                <select id="currency-select" onchange="onCurrencyChange()">
                    <option value="FRW">FRW — Rwandan Franc</option>
                    <option value="USD">USD — US Dollar</option>
                </select>
            </div>
        </div>
    </div>

    <!-- Minerals -->
    <div style="border:1px solid var(--border);border-radius:8px;padding:1.25rem;margin-bottom:1rem;background:var(--surface,var(--bg))">
        <div style="font-weight:600;font-size:.82rem;margin-bottom:.85rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em">
            <i class="fas fa-gem"></i> Minerals to Calculate
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

        <!-- Summary -->
        <div id="global-summary" style="display:none;margin-top:1rem">
            <div style="border:2px solid var(--primary);border-radius:8px;padding:1rem">
                <div style="font-weight:700;font-size:.9rem;margin-bottom:.75rem;color:var(--primary)">
                    <i class="fas fa-receipt"></i> Summary
                </div>
                <div id="global-summary-body"></div>
            </div>
        </div>
    </div>

</div>

<script>
const cardCats        = {};
const cardNames       = {};
const cardSummary     = {};
const cardPriceEdited = {};

const CARD_COLORS = { cassiterite: '#3b82f6', coltan: '#8b5cf6', wolframite: '#10b981' };

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

/* ── Card builder ───────────────────────────────────────────── */
function buildCard(id, name, cat) {
    const color = CARD_COLORS[cat] || 'var(--primary)';
    let fields = '';

    if (cat === 'cassiterite') {
        fields = `
            <div class="form-group"><label>LME Price (RWF)</label><input type="number" id="c${id}-lma" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Quantity (kg)</label><input type="number" id="c${id}-qty" placeholder="0.000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Sample (%)</label><input type="number" id="c${id}-sample" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RWF Rate</label><input type="number" id="c${id}-rwfrate" value="1460" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Company Fees 1 (FRW)</label><input type="number" id="c${id}-fees1" value="2500" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Fees 2 (FRW)</label><input type="number" id="c${id}-fees2" value="3000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Tag (FRW)</label><input type="number" id="c${id}-tag" value="2000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RMA (FRW)</label><input type="number" id="c${id}-rma" value="70" oninput="calcCard(${id})"></div>`;
    } else if (cat === 'coltan') {
        fields = `
            <div class="form-group"><label>TANTAL (USD)</label><input type="number" id="c${id}-tantal" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Quantity (kg)</label><input type="number" id="c${id}-qty" placeholder="0.000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Sample (%)</label><input type="number" id="c${id}-sample" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RWF Rate</label><input type="number" id="c${id}-rwfrate" value="1460" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Tag (FRW)</label><input type="number" id="c${id}-tag" value="2000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RMA (FRW)</label><input type="number" id="c${id}-rma" value="190" oninput="calcCard(${id})"></div>`;
    } else {
        fields = `
            <div class="form-group"><label>TMU Price (USD)</label><input type="number" id="c${id}-tmt" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Quantity (kg)</label><input type="number" id="c${id}-qty" placeholder="0.000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Sample (%)</label><input type="number" id="c${id}-sample" placeholder="0.00" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RWF Rate</label><input type="number" id="c${id}-rwfrate" value="1460" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>Tag (FRW)</label><input type="number" id="c${id}-tag" value="2000" oninput="calcCard(${id})"></div>
            <div class="form-group"><label>RMA (FRW)</label><input type="number" id="c${id}-rma" value="90" oninput="calcCard(${id})"></div>`;
    }

    return `<div id="card-${id}" style="border:1px solid ${color}44;border-left:4px solid ${color};border-radius:8px;padding:1rem;background:var(--surface,var(--bg))">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:.8rem">
            <div style="font-weight:700;font-size:.9rem;color:${color}"><i class="fas fa-gem"></i> ${esc(name)}</div>
            <button type="button" onclick="collapseCard(${id})"
                style="background:none;border:1px solid ${color}55;cursor:pointer;color:${color};font-size:.78rem;padding:.2rem .5rem;border-radius:4px;line-height:1">
                <i class="fas fa-chevron-up" id="c${id}-chevron"></i>
            </button>
        </div>
        <div id="c${id}-body">
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:.6rem .9rem">${fields}</div>
            <div class="form-group" style="margin-top:.6rem">
                <label style="display:flex;justify-content:space-between;align-items:center">
                    <span id="c${id}-price-label">Unit Price / kg</span>
                    <button type="button" onclick="resetPrice(${id})" title="Reset to auto-calculated price"
                        style="background:none;border:none;color:${color};cursor:pointer;font-size:.75rem;padding:0">
                        <i class="fas fa-rotate-left"></i> Auto
                    </button>
                </label>
                <input type="text" id="c${id}-price" placeholder="0.00" inputmode="decimal"
                    oninput="onPriceEdit(${id})" onfocus="unformatPriceField(${id})" onblur="formatPriceField(${id})">
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
        delete cardCats[id]; delete cardNames[id]; delete cardSummary[id]; delete cardPriceEdited[id];
        const card = document.getElementById('card-' + id);
        if (card) card.remove();
        updateGlobalSummary();
    }
}

/* ── Per-card calculation ───────────────────────────────────── */
function fmtRWF(v) { return v.toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2}) + ' FRW'; }
function fmtUSD(v) { return '$' + v.toFixed(4); }

function parseMoney(v) { return parseFloat(String(v ?? '').replace(/,/g, '')) || 0; }
function formatMoneyInput(v) { return v.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

function formatPriceField(id) {
    const el = document.getElementById('c'+id+'-price');
    if (!el) return;
    const v = parseMoney(el.value);
    el.value = v > 0 ? formatMoneyInput(v) : '';
}

function unformatPriceField(id) {
    const el = document.getElementById('c'+id+'-price');
    if (!el) return;
    const v = parseMoney(el.value);
    el.value = v > 0 ? v.toFixed(2) : '';
}

function calcCard(id) {
    const cat      = cardCats[id];
    const qty      = parseFloat(document.getElementById('c'+id+'-qty').value) || 0;
    const currency = document.getElementById('currency-select')?.value || 'FRW';
    let unitPrice  = 0, rows = [], rwfRateCard = 0;

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

    const priceEl     = document.getElementById('c'+id+'-price');
    const priceLabel  = document.getElementById('c'+id+'-price-label');
    const breakdown   = document.getElementById('c'+id+'-breakdown');
    const tbody       = document.getElementById('c'+id+'-rows');

    if (priceLabel) priceLabel.textContent = 'Unit Price / kg (' + currency + ')';

    const storedPrice = (currency === 'USD' && rwfRateCard > 0) ? unitPrice / rwfRateCard : unitPrice;
    if (priceEl && !cardPriceEdited[id]) {
        priceEl.value = storedPrice > 0 ? formatMoneyInput(storedPrice) : '';
    }

    const displayPrice = cardPriceEdited[id] ? parseMoney(priceEl?.value) : storedPrice;

    const effectivePrice = cardPriceEdited[id]
        ? ((currency === 'USD' && rwfRateCard > 0) ? displayPrice * rwfRateCard : displayPrice)
        : unitPrice;
    const effectiveTakeHome = effectivePrice * qty;

    if (cardPriceEdited[id] && rows.length) {
        rows = rows.map(r => {
            if (!r) return r;
            if (r[0].startsWith('= Unit Price')) return [r[0] + ' (edited)', fmtPay(effectivePrice), true];
            if (r[0].startsWith('= Take Home'))  return [r[0], fmtPay(effectiveTakeHome), true];
            return r;
        });
    }

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

    cardSummary[id] = { takeHome_rwf: effectiveTakeHome, rwfRate: rwfRateCard };
    updateGlobalSummary();
    saveCardState(id);
}

function onPriceEdit(id) {
    cardPriceEdited[id] = true;
    calcCard(id);
}

function resetPrice(id) {
    delete cardPriceEdited[id];
    calcCard(id);
}

/* ── Summary ────────────────────────────────────────────────── */
function updateGlobalSummary() {
    const el   = document.getElementById('global-summary');
    const body = document.getElementById('global-summary-body');
    if (!el || !body) return;

    const ids = Object.keys(cardSummary);
    if (ids.length === 0) { el.style.display = 'none'; return; }
    el.style.display = '';

    const currency  = document.getElementById('currency-select')?.value || 'FRW';
    const firstRate = cardSummary[ids[0]]?.rwfRate || 0;

    function fmtAmt(v) {
        if (currency === 'USD' && firstRate > 0)
            return '$' + (v / firstRate).toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 });
        return v.toLocaleString('en-US', { minimumFractionDigits:2, maximumFractionDigits:2 }) + ' FRW';
    }

    let total = 0, html = '';
    ids.forEach(id => {
        const { takeHome_rwf } = cardSummary[id];
        total += takeHome_rwf;
        html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.3rem 0;font-size:.85rem;border-bottom:1px solid var(--border)">
            <span style="color:var(--text-muted)">${esc(cardNames[id] || 'Mineral')}</span>
            <span style="font-family:monospace;font-weight:600">${fmtAmt(takeHome_rwf)}</span>
        </div>`;
    });

    html += `<div style="display:flex;justify-content:space-between;align-items:center;padding:.5rem 0 0;margin-top:.3rem;border-top:2px solid var(--primary)">
        <span style="font-weight:700;font-size:.95rem">TOTAL</span>
        <span style="font-family:monospace;font-weight:700;font-size:1.05rem;color:var(--primary)">${fmtAmt(total)}</span>
    </div>`;

    body.innerHTML = html;
}

function onCurrencyChange() {
    Object.keys(cardCats).forEach(id => calcCard(id));
}

function resetCalculator() {
    document.getElementById('mineral-cards').innerHTML = '';
    document.querySelectorAll('#mineral-checks input[type="checkbox"]').forEach(cb => cb.checked = false);
    for (const k in cardCats)        delete cardCats[k];
    for (const k in cardNames)       delete cardNames[k];
    for (const k in cardSummary)     delete cardSummary[k];
    for (const k in cardPriceEdited) delete cardPriceEdited[k];
    document.getElementById('global-summary').style.display = 'none';
}
</script>

<?php include 'includes/footer.php'; ?>
