<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

$page_title = 'Journal';

/* ── Filters ─────────────────────────────────────────────────── */
$f = [
    'date_from'  => $_GET['date_from']  ?? date('Y-m-d'),
    'date_to'    => $_GET['date_to']    ?? date('Y-m-d'),
    'entry_type' => $_GET['entry_type'] ?? '',
    'supplier_id'=> intval($_GET['supplier_id'] ?? 0),
];
$has_filters = ($f['date_from'] && $f['date_from'] !== date('Y-m-01'))
            || ($f['date_to']   && $f['date_to']   !== date('Y-m-t'))
            || $f['entry_type'] || $f['supplier_id'];

$suppliers = $pdo->query("SELECT id,name FROM suppliers ORDER BY name")->fetchAll();

$per_page = 50;
$page     = max(1, intval($_GET['page'] ?? 1));

/* ── UNION helper ────────────────────────────────────────────── */
function jdc(string $col, string $from, string $to): array {
    $sql = ''; $p = [];
    if($from){ $sql .= " AND $col>=?"; $p[] = $from; }
    if($to)  { $sql .= " AND $col<?";  $p[] = date('Y-m-d', strtotime($to.' +1 day')); }
    return [$sql, $p];
}

[$dc_pp, $dp_pp] = jdc('pp.created_at', $f['date_from'], $f['date_to']);
[$dc_sl, $dp_sl] = jdc('sl.created_at', $f['date_from'], $f['date_to']);
[$dc_at, $dp_at] = jdc('at.created_at', $f['date_from'], $f['date_to']);
[$dc_bl, $dp_bl] = jdc('bl.created_at', $f['date_from'], $f['date_to']);
[$dc_ex, $dp_ex] = jdc('e.created_at', $f['date_from'], $f['date_to']);

/* ── Main UNION (all journal sources) ────────────────────────── */
$union_sql = "
    SELECT pp.created_at AS entry_date,
           'purchase' AS entry_type,
           CONCAT(mt.name, ' from ', s.name) AS description,
           s.name AS party,
           pp.payment_method AS sub_info,
           pp.amount AS amount,
           b.batch_id AS reference,
           b.id AS batch_db_id,
           COALESCE(u.username,'—') AS created_by_name,
           pp.supplier_id AS supplier_id
    FROM purchase_payments pp
    JOIN batches b      ON b.id  = pp.batch_id
    JOIN suppliers s    ON s.id  = pp.supplier_id
    JOIN mineral_types mt ON mt.id = b.mineral_type_id
    LEFT JOIN users u   ON u.id  = pp.created_by
    WHERE 1=1 $dc_pp

    UNION ALL

    SELECT sl.created_at, 'advance',
           CONCAT('Advance to ', s.name),
           s.name, IF(sl.batch_id IS NOT NULL,'With purchase','Manual'),
           sl.amount, b.batch_id, sl.batch_id,
           COALESCE(u.username,'—'), sl.supplier_id
    FROM supplier_loans sl
    JOIN suppliers s    ON s.id = sl.supplier_id
    LEFT JOIN batches b ON b.id = sl.batch_id
    LEFT JOIN users u   ON u.id = sl.created_by
    WHERE sl.type='loan' AND sl.is_deferred=0 $dc_sl

    UNION ALL

    SELECT sl.created_at, 'repayment',
           CONCAT('Repayment from ', s.name),
           s.name, IF(sl.batch_id IS NOT NULL,'With purchase','Manual'),
           sl.amount, b.batch_id, sl.batch_id,
           COALESCE(u.username,'—'), sl.supplier_id
    FROM supplier_loans sl
    JOIN suppliers s    ON s.id = sl.supplier_id
    LEFT JOIN batches b ON b.id = sl.batch_id
    LEFT JOIN users u   ON u.id = sl.created_by
    WHERE sl.type='repayment' AND sl.is_deferred=0 $dc_sl

    UNION ALL

    SELECT sl.created_at, 'deferred',
           CONCAT('Deferred owed to ', s.name),
           s.name, 'Unpaid mineral amount',
           sl.amount, b.batch_id, sl.batch_id,
           COALESCE(u.username,'—'), sl.supplier_id
    FROM supplier_loans sl
    JOIN suppliers s    ON s.id = sl.supplier_id
    LEFT JOIN batches b ON b.id = sl.batch_id
    LEFT JOIN users u   ON u.id = sl.created_by
    WHERE sl.type='loan' AND sl.is_deferred=1 $dc_sl

    UNION ALL

    SELECT sl.created_at, 'deferred_payment',
           CONCAT('Settled deferred to ', s.name),
           s.name, sl.payment_method,
           sl.amount, NULL, NULL,
           COALESCE(u.username,'—'), sl.supplier_id
    FROM supplier_loans sl
    JOIN suppliers s    ON s.id = sl.supplier_id
    LEFT JOIN users u   ON u.id = sl.created_by
    WHERE sl.type='repayment' AND sl.is_deferred=1 $dc_sl

    UNION ALL

    SELECT at.created_at, CONCAT('acct_', at.txn_type),
           COALESCE(at.description,'Manual adjustment'),
           ca.account_name, ca.account_type,
           at.amount, NULL, NULL,
           COALESCE(u.username,'—'), NULL
    FROM account_transactions at
    JOIN company_accounts ca ON ca.id = at.account_id
    LEFT JOIN users u        ON u.id  = at.created_by
    WHERE at.reference_type='manual' $dc_at

    UNION ALL

    SELECT bl.created_at, 'buyer_credit',
           CONCAT('Credit to ', b.name),
           b.name, NULL,
           bl.amount, NULL, NULL,
           COALESCE(u.username,'—'), NULL
    FROM buyer_loans bl
    JOIN buyers b    ON b.id = bl.buyer_id
    LEFT JOIN users u ON u.id = bl.created_by
    WHERE bl.type='loan' $dc_bl

    UNION ALL

    SELECT bl.created_at, IF(COALESCE(bl.is_advance,0)=1,'buyer_advance','buyer_payment'),
           CONCAT(IF(COALESCE(bl.is_advance,0)=1,'Advance from ','Payment from '), b.name),
           b.name, bl.payment_method,
           bl.amount, NULL, NULL,
           COALESCE(u.username,'—'), NULL
    FROM buyer_loans bl
    JOIN buyers b    ON b.id = bl.buyer_id
    LEFT JOIN users u ON u.id = bl.created_by
    WHERE bl.type='repayment' $dc_bl

    UNION ALL

    SELECT e.created_at, 'expense',
           CONCAT('[', e.category, '] ', e.description),
           e.category, e.payment_method,
           e.amount, NULL, NULL,
           COALESCE(u.username,'—'), NULL
    FROM expenses e
    LEFT JOIN users u ON u.id = e.created_by
    WHERE 1=1 $dc_ex
";


$union_params = array_merge($dp_pp, $dp_sl, $dp_sl, $dp_sl, $dp_sl, $dp_at, $dp_bl, $dp_bl, $dp_ex);

/* ── Outer filters ───────────────────────────────────────────── */
$outer_where = []; $outer_params = [];
if($f['entry_type'])  { $outer_where[] = 'j.entry_type=?';  $outer_params[] = $f['entry_type']; }
if($f['supplier_id']) { $outer_where[] = 'j.supplier_id=?'; $outer_params[] = $f['supplier_id']; }
$outer_sql  = $outer_where ? 'WHERE '.implode(' AND ',$outer_where) : '';
$all_params = array_merge($union_params, $outer_params);

/* ── Pagination ──────────────────────────────────────────────── */
$cnt_s = $pdo->prepare("SELECT COUNT(*) FROM ($union_sql) j $outer_sql");
$cnt_s->execute($all_params);
$total       = (int)$cnt_s->fetchColumn();
$total_pages = max(1,(int)ceil($total/$per_page));
$page        = min($page,$total_pages);
$offset      = ($page-1)*$per_page;

$data_s = $pdo->prepare("SELECT * FROM ($union_sql) j $outer_sql ORDER BY j.entry_date DESC LIMIT $per_page OFFSET $offset");
$data_s->execute($all_params);
$entries = $data_s->fetchAll(PDO::FETCH_ASSOC);

/* ── Period stats ────────────────────────────────────────────── */
$stat_s = $pdo->prepare("
    SELECT
        COALESCE(SUM(CASE WHEN j.entry_type='purchase'   THEN j.amount END),0) AS total_purchases,
        COALESCE(SUM(CASE WHEN j.entry_type='advance'    THEN j.amount END),0) AS total_advances,
        COALESCE(SUM(CASE WHEN j.entry_type='repayment'  THEN j.amount END),0) AS total_repayments,
        COALESCE(SUM(CASE WHEN j.entry_type='deferred'         THEN j.amount END),0) AS total_deferred,
        COALESCE(SUM(CASE WHEN j.entry_type='deferred_payment' THEN j.amount END),0) AS total_deferred_paid,
        COALESCE(SUM(CASE WHEN j.entry_type='buyer_credit'  THEN j.amount END),0) AS total_buyer_credit,
        COALESCE(SUM(CASE WHEN j.entry_type='buyer_payment' THEN j.amount END),0) AS total_buyer_payments,
        COALESCE(SUM(CASE WHEN j.entry_type='buyer_advance' THEN j.amount END),0) AS total_buyer_advances,
        COALESCE(SUM(CASE WHEN j.entry_type='expense'       THEN j.amount END),0) AS total_expenses,
        COUNT(*) AS total_entries,
        COALESCE(SUM(j.amount),0) AS grand_total
    FROM ($union_sql) j
");
$stat_s->execute($union_params);
$totals = $stat_s->fetch(PDO::FETCH_ASSOC);

/* ── Entry type display config ───────────────────────────────── */
$type_cfg = [
    'purchase'    => ['Purchase Payment',  '#2563eb','#eff6ff','fa-money-bill-wave'],
    'advance'     => ['Supplier Advance',  '#ea580c','#fff7ed','fa-hand-holding-dollar'],
    'repayment'   => ['Advance Repayment', '#16a34a','#f0fdf4','fa-rotate-left'],
    'deferred'         => ['Deferred Payment',     '#d97706','#fffbeb','fa-hourglass-half'],
    'deferred_payment' => ['Deferred Settlement',  '#7c3aed','#f5f3ff','fa-money-bill-transfer'],
    'acct_credit'      => ['Account Credit',       '#16a34a','#f0fdf4','fa-circle-plus'],
    'acct_debit'       => ['Account Debit',        '#dc2626','#fef2f2','fa-circle-minus'],
    'buyer_credit'  => ['Buyer Credit',    '#7c3aed','#f5f3ff','fa-file-invoice-dollar'],
    'buyer_payment' => ['Buyer Payment',   '#16a34a','#f0fdf4','fa-money-bill-wave'],
    'buyer_advance' => ['Buyer Advance',   '#0891b2','#ecfeff','fa-hand-holding-dollar'],
    'expense'       => ['Expense',         '#dc2626','#fef2f2','fa-receipt'],
];
$method_labels = ['cash'=>'Cash','bank'=>'Bank','momo'=>'MoMo'];
$acct_labels   = ['cash'=>'Cash','bank'=>'Bank','momo'=>'MoMo'];

/* ── Double-entry account mapper ─────────────────────────────── */
function get_dr_cr(string $etype, string $party, string $sub): array {
    static $m = ['cash'=>'Cash','bank'=>'Bank Account','momo'=>'Mobile Money'];
    $acct = $m[$sub] ?? ($sub ?: 'Cash');
    return match($etype){
        'purchase'         => ['Mineral Purchases',   $acct],
        'advance'          => ['Supplier Advances',   $acct],
        'repayment'        => [$acct,                 'Supplier Advances'],
        'deferred'         => ['Mineral Purchases',   'Supplier Payable'],
        'deferred_payment' => ['Supplier Payable',    $acct],
        'acct_credit'      => ['Manual Adjustment',   $party],
        'acct_debit'       => [$party,                'Manual Adjustment'],
        'buyer_credit'     => ['Buyer Receivable',    'Minerals Sales'],
        'buyer_payment'    => [$acct,                 'Buyer Receivable'],
        'buyer_advance'    => [$acct,                 'Buyer Advances'],
        'expense'          => ['Expenses',            $acct],
        default            => ['—',                   '—'],
    };
}

include 'includes/header.php';
?>

<div class="page-header">
    <h2><i class="fas fa-book-open" style="margin-right:.4rem;color:var(--text-muted)"></i>Journal</h2>
    <span style="font-size:.82rem;color:var(--text-muted)">All financial movements</span>
</div>

<!-- Stats -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:.75rem;margin-bottom:1rem">
    <?php
    $stat_cards = [
        ['fa-money-bill-wave',      'Purchase Payments', $totals['total_purchases'],     '#2563eb', 'FRW out to suppliers'],
        ['fa-hand-holding-dollar',  'Advances Given',    $totals['total_advances'],      '#ea580c', 'Cash to suppliers'],
        ['fa-rotate-left',          'Advance Repayments',$totals['total_repayments'],    '#16a34a', 'Cash from suppliers'],
        ['fa-hourglass-half',       'Deferred Created',  $totals['total_deferred'],      '#d97706', 'Amounts still owed'],
        ['fa-money-bill-transfer',  'Deferred Settled',  $totals['total_deferred_paid'], '#7c3aed', 'Paid to suppliers'],
        ['fa-file-invoice-dollar',  'Buyer Credit',      $totals['total_buyer_credit'],  '#7c3aed', 'Credit extended to buyers'],
        ['fa-money-bill-wave',      'Buyer Payments',    $totals['total_buyer_payments'],'#16a34a', 'Cash received from buyers'],
        ['fa-hand-holding-dollar',  'Buyer Advances',    $totals['total_buyer_advances'],'#0891b2', 'Prepayments from buyers'],
        ['fa-receipt',              'Expenses',          $totals['total_expenses'],      '#dc2626', 'All operating expenses'],
    ];
    foreach($stat_cards as [$icon,$label,$value,$color,$sub]): ?>
    <div style="border:1px solid var(--border);border-left:4px solid <?= $color ?>;border-radius:8px;padding:.75rem 1rem;background:var(--surface,var(--bg))">
        <div style="font-size:.75rem;color:var(--text-muted);margin-bottom:.25rem;display:flex;align-items:center;gap:.4rem">
            <i class="fas <?= $icon ?>" style="color:<?= $color ?>"></i> <?= $label ?>
        </div>
        <div style="font-size:1rem;font-weight:700;color:<?= $color ?>">
            <?= number_format($value,2) ?> <span style="font-size:.72rem;font-weight:400;color:var(--text-muted)">FRW</span>
        </div>
        <div style="font-size:.72rem;color:var(--text-muted);margin-top:.1rem"><?= $sub ?></div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Filters -->
<form method="GET" action="journal.php" class="filter-bar" style="margin-bottom:1rem">
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
            <?php foreach($type_cfg as $key => [$lbl]): ?>
            <option value="<?= $key ?>" <?= $f['entry_type']===$key?'selected':'' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Supplier</label>
        <select name="supplier_id">
            <option value="">All suppliers</option>
            <?php foreach($suppliers as $s): ?>
            <option value="<?= $s['id'] ?>" <?= $f['supplier_id']==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['name']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-filter"></i> Filter
        </button>
        <a href="journal.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-xmark"></i> Clear
        </a>
    </div>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> <?= $total ?> entr<?= $total===1?'y':'ies' ?></span>
</form>

<!-- Journal table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Date & Time</th>
                <th>Type</th>
                <th>Description</th>
                <th>Dr. Account</th>
                <th>Cr. Account</th>
                <th style="text-align:right">Debit (FRW)</th>
                <th style="text-align:right">Credit (FRW)</th>
                <th>Reference</th>
                <th>By</th>
            </tr>
        </thead>
        <tbody>
        <?php if(!$entries): ?>
        <tr><td colspan="10" style="text-align:center;padding:2rem;color:var(--text-muted)">No journal entries for this period.</td></tr>
        <?php endif; ?>
        <?php
        $i = $offset;
        $page_total = 0;
        foreach($entries as $e):
            $etype = $e['entry_type'];
            [$tlabel,$tclr,$tbg,$ticon] = $type_cfg[$etype] ?? [$etype,'#6b7280','#f3f4f6','fa-circle'];
            [$dr_acct, $cr_acct] = get_dr_cr($etype, $e['party'] ?? '', $e['sub_info'] ?? '');
            $page_total += $e['amount'];
        ?>
        <tr>
            <td class="text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="text-muted" style="font-size:.8rem;white-space:nowrap">
                <?= date('d M Y', strtotime($e['entry_date'])) ?>
                <div style="font-size:.72rem"><?= date('H:i', strtotime($e['entry_date'])) ?></div>
            </td>
            <td>
                <span style="display:inline-flex;align-items:center;gap:.3rem;font-size:.76rem;font-weight:600;padding:.2rem .55rem;border-radius:4px;background:<?= $tbg ?>;color:<?= $tclr ?>;white-space:nowrap">
                    <i class="fas <?= $ticon ?>"></i> <?= $tlabel ?>
                </span>
            </td>
            <td style="font-size:.85rem;font-weight:500">
                <?= htmlspecialchars($e['description']) ?>
                <?php if(!empty($e['party'])): ?>
                <div style="font-size:.72rem;color:var(--text-muted)"><?= htmlspecialchars($e['party']) ?></div>
                <?php endif; ?>
            </td>
            <td style="font-size:.8rem;font-weight:500;color:#2563eb">
                <span style="font-size:.68rem;font-weight:700;color:var(--text-muted);margin-right:.2rem">Dr.</span><?= htmlspecialchars($dr_acct) ?>
            </td>
            <td style="font-size:.8rem;font-weight:500;color:#16a34a">
                <span style="font-size:.68rem;font-weight:700;color:var(--text-muted);margin-right:.2rem">Cr.</span><?= htmlspecialchars($cr_acct) ?>
            </td>
            <td style="text-align:right;font-family:monospace;font-weight:600;color:#2563eb;white-space:nowrap">
                <?= number_format($e['amount'],2) ?>
            </td>
            <td style="text-align:right;font-family:monospace;font-weight:600;color:#16a34a;white-space:nowrap">
                <?= number_format($e['amount'],2) ?>
            </td>
            <td style="font-size:.78rem;font-family:monospace">
                <?php if(!empty($e['reference']) && !empty($e['batch_db_id'])): ?>
                <a href="batches.php?bid=<?= (int)$e['batch_db_id'] ?>"
                   style="color:var(--primary);text-decoration:none;font-weight:600">
                    <i class="fas fa-arrow-up-right-from-square" style="font-size:.65rem"></i>
                    <?= htmlspecialchars($e['reference']) ?>
                </a>
                <?php else: ?>
                <span class="text-muted">—</span>
                <?php endif; ?>
            </td>
            <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($e['created_by_name']) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr style="border-top:2px solid var(--border);background:var(--surface,var(--bg))">
                <td colspan="6" style="font-size:.82rem;font-weight:600;padding:.6rem 1rem;color:var(--text-muted)">
                    Page total <span style="font-weight:400">(<?= count($entries) ?> <?= count($entries)===1?'entry':'entries' ?>)</span>
                </td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#2563eb;padding:.6rem 1rem">
                    <?= number_format($page_total,2) ?>
                </td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#16a34a;padding:.6rem 1rem">
                    <?= number_format($page_total,2) ?>
                </td>
                <td colspan="2"></td>
            </tr>
            <?php if($total_pages > 1): ?>
            <tr style="background:var(--surface,var(--bg))">
                <td colspan="6" style="font-size:.82rem;font-weight:600;padding:.4rem 1rem;color:var(--text-muted)">
                    Period total <span style="font-weight:400">(<?= $total ?> entries)</span>
                </td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#2563eb;padding:.4rem 1rem">
                    <?= number_format($totals['grand_total'],2) ?>
                </td>
                <td style="text-align:right;font-family:monospace;font-weight:700;color:#16a34a;padding:.4rem 1rem">
                    <?= number_format($totals['grand_total'],2) ?>
                </td>
                <td colspan="2" style="font-size:.78rem;font-weight:600;color:#16a34a;padding:.4rem 1rem">
                    <i class="fas fa-check-circle"></i> Balanced
                </td>
            </tr>
            <?php endif; ?>
        </tfoot>
    </table>

    <?php if($total_pages > 1): ?>
    <?= paginate($page, $total_pages, array_filter($f), 'journal.php') ?>
    <p class="pagination-info" style="padding-bottom:.5rem">
        Showing <?= $offset + 1 ?>–<?= min($offset + $per_page, $total) ?> of <?= $total ?> entries
    </p>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
