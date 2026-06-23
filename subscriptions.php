<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }
if(($_SESSION['role']??'') !== 'system'){ header('Location: dashboard.php'); exit; }

/* ─────────────────────── AJAX handlers ─────────────────────── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    $act = $_POST['action'] ?? '';

    if($act === 'save_info'){
        try {
            $cn    = trim($_POST['client_name']  ?? '');
            if(!$cn) throw new Exception('Client name is required.');
            $plan  = trim($_POST['plan_name']    ?? 'Monthly') ?: 'Monthly';
            $amt   = round(floatval($_POST['amount'] ?? 0), 2);
            $sd    = trim($_POST['start_date']   ?? '') ?: date('Y-m-d');
            $ed    = trim($_POST['expiry_date']  ?? '');
            if(!$ed) throw new Exception('Expiry date is required.');
            $gd    = max(0, min(30, intval($_POST['grace_days'] ?? 3)));
            $email = trim($_POST['client_email'] ?? '');
            $phone = trim($_POST['client_phone'] ?? '');
            $notes = trim($_POST['notes']        ?? '');

            $existing = $pdo->query("SELECT id FROM subscription LIMIT 1")->fetch();
            if($existing){
                $pdo->prepare("UPDATE subscription SET client_name=?,client_email=?,client_phone=?,plan_name=?,amount=?,start_date=?,expiry_date=?,grace_days=?,notes=?,updated_at=NOW() WHERE id=?")
                    ->execute([$cn,$email,$phone,$plan,$amt,$sd,$ed,$gd,$notes,$existing['id']]);
                $sid = $existing['id'];
            } else {
                $pdo->prepare("INSERT INTO subscription (client_name,client_email,client_phone,plan_name,amount,start_date,expiry_date,grace_days,notes) VALUES (?,?,?,?,?,?,?,?,?)")
                    ->execute([$cn,$email,$phone,$plan,$amt,$sd,$ed,$gd,$notes]);
                $sid = (int)$pdo->lastInsertId();
            }
            logAction($pdo,$_SESSION['user_id'],'UPDATE','subscription',$sid,"Info updated: $cn, expires $ed");
            $sub_new = $pdo->query("SELECT * FROM subscription WHERE id=$sid")->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'message'=>'Subscription info saved.','sub'=>$sub_new]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if($act === 'record_payment'){
        try {
            $amt = round(floatval($_POST['amount'] ?? 0), 2);
            if($amt <= 0) throw new Exception('Amount must be greater than 0.');
            $pd  = trim($_POST['payment_date']  ?? '') ?: date('Y-m-d');
            $pf  = trim($_POST['period_from']   ?? '');
            $pt  = trim($_POST['period_to']     ?? '');
            if(!$pf || !$pt) throw new Exception('Period from and to are required.');
            if($pt < $pf)    throw new Exception('Period end cannot be before period start.');
            $mth = in_array($_POST['method']??'',['cash','bank','momo']) ? $_POST['method'] : 'cash';
            $ref = trim($_POST['reference'] ?? '');
            $nts = trim($_POST['notes']     ?? '');

            $pdo->beginTransaction();
            $pdo->prepare("INSERT INTO subscription_payments (payment_date,amount,period_from,period_to,payment_method,reference,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([$pd,$amt,$pf,$pt,$mth,$ref,$nts,$_SESSION['user_id']]);
            $payId = (int)$pdo->lastInsertId();

            $s2 = $pdo->query("SELECT id,expiry_date FROM subscription WHERE is_active=1 LIMIT 1")->fetch();
            $new_expiry = null;
            if($s2 && $pt > $s2['expiry_date']){
                $pdo->prepare("UPDATE subscription SET expiry_date=?,updated_at=NOW() WHERE id=?")->execute([$pt,$s2['id']]);
                $new_expiry = $pt;
            }
            $pdo->commit();
            logAction($pdo,$_SESSION['user_id'],'CREATE','subscription_payments',$payId,"Payment $amt FRW: $pf to $pt");
            $row = $pdo->query("SELECT sp.*,u.username AS recorded_by_name FROM subscription_payments sp LEFT JOIN users u ON u.id=sp.recorded_by WHERE sp.id=$payId")->fetch(PDO::FETCH_ASSOC);
            echo json_encode(['success'=>true,'message'=>'Payment of '.number_format($amt,2).' FRW recorded.','row'=>$row,'new_expiry'=>$new_expiry]);
        } catch(Exception $e){ if($pdo->inTransaction()) $pdo->rollBack(); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if($act === 'generate_key'){
        try {
            $f = trim($_POST['period_from'] ?? '');
            $t = trim($_POST['period_to']   ?? '');
            $p = trim($_POST['plan_name']   ?? 'Monthly') ?: 'Monthly';
            $a = round(floatval($_POST['amount'] ?? 0), 2);
            if(!$f || !$t) throw new Exception('Period dates are required.');
            if($t < $f)    throw new Exception('Period end cannot be before period start.');
            $key = generateLicenseKey($f, $t, $p, $a);
            echo json_encode(['success'=>true,'key'=>$key]);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    if($act === 'delete_payment'){
        try {
            $id = intval($_POST['id'] ?? 0);
            if(!$id) throw new Exception('Invalid record.');
            $r = $pdo->prepare("SELECT * FROM subscription_payments WHERE id=?"); $r->execute([$id]); $rec=$r->fetch();
            if(!$rec) throw new Exception('Record not found.');
            $pdo->prepare("DELETE FROM subscription_payments WHERE id=?")->execute([$id]);
            logAction($pdo,$_SESSION['user_id'],'DELETE','subscription_payments',$id,"Deleted payment {$rec['amount']} FRW");
            echo json_encode(['success'=>true,'message'=>'Payment deleted.']);
        } catch(Exception $e){ echo json_encode(['success'=>false,'message'=>$e->getMessage()]); }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit;
}

/* ─────────────────────── Page data ─────────────────────── */
$page_title = 'Subscription';

$sub = null;
try { $sub = $pdo->query("SELECT * FROM subscription WHERE is_active=1 ORDER BY id LIMIT 1")->fetch(PDO::FETCH_ASSOC); } catch(PDOException $e){}

$payments   = [];
$total_paid = 0;
try {
    $payments   = $pdo->query("SELECT sp.*,u.username AS recorded_by_name FROM subscription_payments sp LEFT JOIN users u ON u.id=sp.recorded_by ORDER BY sp.payment_date DESC,sp.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);
    $total_paid = array_sum(array_column($payments,'amount'));
} catch(PDOException $e){}

$today = date('Y-m-d');
$status = 'none'; $days_left = null; $grace_until = null;
if($sub){
    $grace_until = date('Y-m-d', strtotime($sub['expiry_date'].' +'.max(0,(int)$sub['grace_days']).' days'));
    if($today <= $sub['expiry_date']){
        $status    = 'active';
        $days_left = (int)round((strtotime($sub['expiry_date']) - strtotime($today)) / 86400);
    } elseif($today <= $grace_until){
        $status    = 'grace';
        $days_left = (int)round((strtotime($grace_until) - strtotime($today)) / 86400);
    } else {
        $status    = 'expired';
        $days_left = (int)round((strtotime($today) - strtotime($sub['expiry_date'])) / 86400);
    }
}

$sm = [
    'active'  => ['color'=>'#16a34a','icon'=>'circle-check',         'label'=>'Active'],
    'grace'   => ['color'=>'#d97706','icon'=>'triangle-exclamation',  'label'=>'Grace Period'],
    'expired' => ['color'=>'#dc2626','icon'=>'lock',                  'label'=>'Expired'],
    'none'    => ['color'=>'#6b7280','icon'=>'circle-info',           'label'=>'Not Configured'],
][$status];

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-key" style="margin-right:.4rem;color:var(--text-muted)"></i>Subscription</h2>
    <div style="display:flex;gap:.5rem">
        <?php if(($_SESSION['role']??'') === 'system'): ?>
        <button class="btn btn-secondary" onclick="openGenModal()"><i class="fas fa-key"></i> Generate Key</button>
        <?php endif; ?>
        <button class="btn btn-primary" onclick="openPayModal()"><i class="fas fa-plus"></i> Record Payment</button>
    </div>
</div>

<!-- Status Banner -->
<?php $sc=$sm['color']; $si=$sm['icon']; $sl=$sm['label']; ?>
<div style="border:1px solid var(--border);border-left:4px solid <?= $sc ?>;border-radius:8px;padding:.9rem 1.25rem;margin-bottom:1rem;background:var(--surface,var(--bg))">
    <div style="display:flex;align-items:center;gap:1.5rem;flex-wrap:wrap">
        <div style="display:flex;align-items:center;gap:.75rem;flex-shrink:0">
            <div style="width:42px;height:42px;border-radius:50%;background:<?= $sc ?>18;display:flex;align-items:center;justify-content:center">
                <i class="fas fa-<?= $si ?>" style="color:<?= $sc ?>;font-size:1.1rem"></i>
            </div>
            <div>
                <div style="font-size:.7rem;text-transform:uppercase;letter-spacing:.06em;color:var(--text-muted);margin-bottom:.1rem">Status</div>
                <div style="font-size:.95rem;font-weight:700;color:<?= $sc ?>"><?= $sl ?></div>
            </div>
        </div>
        <?php if($sub): ?>
        <div style="display:flex;gap:2rem;flex-wrap:wrap">
            <div>
                <div style="font-size:.7rem;color:var(--text-muted)">Client</div>
                <div style="font-size:.86rem;font-weight:600"><?= htmlspecialchars($sub['client_name']) ?></div>
            </div>
            <div>
                <div style="font-size:.7rem;color:var(--text-muted)">Plan</div>
                <div style="font-size:.86rem;font-weight:600"><?= htmlspecialchars($sub['plan_name']) ?></div>
            </div>
            <div>
                <div style="font-size:.7rem;color:var(--text-muted)">Expires</div>
                <div style="font-size:.86rem;font-weight:700;color:<?= $sc ?>"><?= date('d M Y',strtotime($sub['expiry_date'])) ?></div>
            </div>
            <?php if($days_left !== null): ?>
            <div>
                <div style="font-size:.7rem;color:var(--text-muted)"><?= $status==='expired'?'Overdue':($status==='grace'?'Grace ends in':'Days left') ?></div>
                <div style="font-size:.86rem;font-weight:700;color:<?= $sc ?>"><?= $days_left ?> day<?= $days_left!=1?'s':'' ?></div>
            </div>
            <?php endif; ?>
            <div>
                <div style="font-size:.7rem;color:var(--text-muted)">Total Collected</div>
                <div style="font-size:.86rem;font-weight:700;font-family:monospace;color:#16a34a"><?= number_format($total_paid,2) ?> FRW</div>
            </div>
        </div>
        <?php else: ?>
        <span style="font-size:.85rem;color:var(--text-muted)">No subscription configured yet. Fill in the details below to activate the license gate.</span>
        <?php endif; ?>
    </div>
</div>

<!-- Two-column layout -->
<div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:1rem;margin-bottom:1rem">

    <!-- Left: Client Info form -->
    <div style="border:1px solid var(--border);border-radius:8px;background:var(--surface,var(--bg));overflow:hidden">
        <div style="padding:.65rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.45rem">
            <i class="fas fa-id-card" style="color:var(--text-muted);font-size:.85rem"></i>
            <span style="font-size:.84rem;font-weight:600">Client Info</span>
        </div>
        <div style="padding:1rem">
            <div id="info-alert" style="display:none;margin-bottom:.75rem;padding:.5rem .75rem;border-radius:6px;font-size:.82rem;align-items:center;gap:.5rem"></div>
            <form id="sub-info-form">
                <div class="form-grid form-grid-2">
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Client / Company Name</label>
                        <input type="text" name="client_name" value="<?= htmlspecialchars($sub['client_name']??'') ?>" placeholder="e.g. Kigali Minerals Ltd" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="client_email" value="<?= htmlspecialchars($sub['client_email']??'') ?>" placeholder="client@example.com">
                    </div>
                    <div class="form-group">
                        <label>Phone</label>
                        <input type="text" name="client_phone" value="<?= htmlspecialchars($sub['client_phone']??'') ?>" placeholder="+250 ...">
                    </div>
                    <div class="form-group">
                        <label>Plan</label>
                        <select name="plan_name">
                            <?php foreach(['Monthly','Quarterly','Semi-Annual','Annual','Custom'] as $p): ?>
                            <option value="<?= $p ?>" <?= ($sub['plan_name']??'Monthly')===$p?'selected':'' ?>><?= $p ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount / Period (FRW)</label>
                        <input type="number" name="amount" value="<?= htmlspecialchars($sub['amount']??'') ?>" placeholder="0" min="0" step="0.01">
                    </div>
                    <div class="form-group">
                        <label>Start Date</label>
                        <input type="date" name="start_date" value="<?= htmlspecialchars($sub['start_date']??date('Y-m-d')) ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Expiry Date</label>
                        <input type="date" name="expiry_date" id="sub-expiry-inp" value="<?= htmlspecialchars($sub['expiry_date']??'') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Grace Period (days)</label>
                        <input type="number" name="grace_days" value="<?= htmlspecialchars((string)($sub['grace_days']??3)) ?>" min="0" max="30" placeholder="3">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Notes <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                        <textarea name="notes" placeholder="Optional…" style="min-height:52px"><?= htmlspecialchars($sub['notes']??'') ?></textarea>
                    </div>
                </div>
                <div style="margin-top:.75rem;display:flex;justify-content:flex-end">
                    <button type="submit" class="btn btn-primary" id="info-save-btn">
                        <i class="fas fa-save"></i> Save Info
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Right: Quick extend + summary -->
    <div style="display:flex;flex-direction:column;gap:1rem">

        <div style="border:1px solid var(--border);border-radius:8px;background:var(--surface,var(--bg));overflow:hidden">
            <div style="padding:.65rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.45rem">
                <i class="fas fa-calendar-plus" style="color:var(--text-muted);font-size:.85rem"></i>
                <span style="font-size:.84rem;font-weight:600">Quick Extend</span>
            </div>
            <div style="padding:1rem">
                <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:.85rem;line-height:1.5">
                    Pre-fills the payment form starting from the current expiry date.
                </p>
                <div style="display:flex;flex-direction:column;gap:.45rem">
                    <?php
                    $quick = [
                        ['1 Month',  '1 month',  '#2563eb'],
                        ['3 Months', '3 months', '#7c3aed'],
                        ['6 Months', '6 months', '#0891b2'],
                        ['1 Year',   '1 year',   '#16a34a'],
                    ];
                    foreach($quick as [$label,$period,$color]):
                    ?>
                    <button type="button" onclick="quickExtend('<?= $period ?>')"
                        style="padding:.48rem .85rem;border:1px solid <?= $color ?>;border-radius:6px;background:<?= $color ?>0d;color:<?= $color ?>;font-size:.82rem;font-weight:600;cursor:pointer;text-align:left;display:flex;align-items:center;gap:.5rem">
                        <i class="fas fa-arrow-right" style="font-size:.68rem"></i> Extend <?= $label ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <div style="border:1px solid var(--border);border-radius:8px;background:var(--surface,var(--bg));overflow:hidden">
            <div style="padding:.65rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.45rem">
                <i class="fas fa-chart-pie" style="color:var(--text-muted);font-size:.85rem"></i>
                <span style="font-size:.84rem;font-weight:600">Payment Summary</span>
            </div>
            <div style="padding:.75rem 1rem">
                <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--border)">
                    <span style="font-size:.82rem;color:var(--text-muted)">Total Payments</span>
                    <span style="font-size:.82rem;font-weight:600"><?= count($payments) ?></span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:.3rem 0;border-bottom:1px solid var(--border)">
                    <span style="font-size:.82rem;color:var(--text-muted)">Total Collected</span>
                    <span style="font-size:.82rem;font-weight:700;color:#16a34a;font-family:monospace"><?= number_format($total_paid,2) ?> FRW</span>
                </div>
                <div style="display:flex;justify-content:space-between;padding:.3rem 0">
                    <span style="font-size:.82rem;color:var(--text-muted)">Fee / Period</span>
                    <span style="font-size:.82rem;font-weight:600;font-family:monospace"><?= number_format($sub['amount']??0,2) ?> FRW</span>
                </div>
            </div>
        </div>

    </div>
</div>

<!-- Payment History -->
<div class="table-wrap">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:.6rem 1rem;border-bottom:1px solid var(--border)">
        <span style="font-size:.84rem;font-weight:600"><i class="fas fa-clock-rotate-left" style="color:var(--text-muted);margin-right:.4rem"></i>Payment History</span>
        <span style="font-size:.78rem;color:var(--text-muted)"><?= count($payments) ?> record<?= count($payments)!=1?'s':'' ?></span>
    </div>
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Payment Date</th>
                <th>Period From</th>
                <th>Period To</th>
                <th>Method</th>
                <th>Reference</th>
                <th style="text-align:right">Amount (FRW)</th>
                <th>Notes</th>
                <th>By</th>
                <th></th>
            </tr>
        </thead>
        <tbody id="pay-tbody">
        <?php if(!$payments): ?>
        <tr id="pay-empty"><td colspan="10" style="text-align:center;padding:2rem;color:var(--text-muted)">No payments recorded yet.</td></tr>
        <?php endif; ?>
        <?php $i=0; foreach($payments as $py):
            $mclr = ['cash'=>'#16a34a','bank'=>'#2563eb','momo'=>'#7c3aed'][$py['payment_method']] ?? '#6b7280';
            $mlab = ['cash'=>'Cash','bank'=>'Bank','momo'=>'MoMo'][$py['payment_method']] ?? $py['payment_method'];
        ?>
        <tr id="pay-row-<?= $py['id'] ?>">
            <td class="text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td style="font-size:.82rem;white-space:nowrap"><?= date('d M Y',strtotime($py['payment_date'])) ?></td>
            <td class="text-muted" style="font-size:.8rem;white-space:nowrap"><?= date('d M Y',strtotime($py['period_from'])) ?></td>
            <td class="text-muted" style="font-size:.8rem;white-space:nowrap"><?= date('d M Y',strtotime($py['period_to'])) ?></td>
            <td>
                <span style="font-size:.76rem;font-weight:600;padding:.2rem .5rem;border-radius:4px;background:<?= $mclr ?>18;color:<?= $mclr ?>">
                    <?= $mlab ?>
                </span>
            </td>
            <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($py['reference'] ?: '—') ?></td>
            <td style="text-align:right;font-family:monospace;font-weight:700;color:#16a34a"><?= number_format($py['amount'],2) ?></td>
            <td class="text-muted" style="font-size:.8rem"><?= htmlspecialchars($py['notes'] ?? '') ?></td>
            <td class="text-muted" style="font-size:.78rem"><?= htmlspecialchars($py['recorded_by_name'] ?? '—') ?></td>
            <td>
                <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deletePayment(<?= $py['id'] ?>,this)">
                    <i class="fas fa-trash"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<!-- Generate Key Modal -->
<div class="modal-backdrop" id="gen-modal" onclick="if(event.target===this)closeGenModal()">
    <div class="modal" style="max-width:460px">
        <div class="modal-header">
            <h3><i class="fas fa-key" style="margin-right:.4rem;color:#7c3aed"></i>Generate License Key</h3>
            <button class="modal-close" onclick="closeGenModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div id="gen-alert" style="display:none;padding:.55rem .75rem;border-radius:6px;font-size:.83rem;align-items:center;gap:.5rem;margin-bottom:.75rem"></div>
            <form id="gen-form">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Period From</label>
                        <input type="date" name="period_from" id="gen-from" required>
                    </div>
                    <div class="form-group">
                        <label>Period To</label>
                        <input type="date" name="period_to" id="gen-to" required>
                    </div>
                    <div class="form-group">
                        <label>Plan</label>
                        <select name="plan_name">
                            <?php foreach(['Monthly','Quarterly','Semi-Annual','Annual','Custom'] as $p): ?>
                            <option value="<?= $p ?>" <?= ($sub['plan_name']??'Monthly')===$p?'selected':'' ?>><?= $p ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Amount (FRW)</label>
                        <input type="number" name="amount" placeholder="0.00" step="0.01" min="0" value="<?= htmlspecialchars($sub['amount']??'') ?>">
                    </div>
                </div>
            </form>

            <!-- Generated key output -->
            <div id="gen-key-wrap" style="display:none;margin-top:1rem">
                <div style="font-size:.8rem;font-weight:600;color:var(--text-muted);margin-bottom:.4rem;text-transform:uppercase;letter-spacing:.05em">License Key</div>
                <div style="display:flex;gap:.4rem;align-items:stretch">
                    <code id="gen-key-out" style="flex:1;padding:.55rem .75rem;background:var(--bg);border:1px solid var(--border);border-radius:6px;font-size:.76rem;word-break:break-all;line-height:1.5;color:#7c3aed"></code>
                    <button type="button" onclick="copyKey()" id="copy-btn"
                        style="padding:.45rem .75rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);cursor:pointer;color:var(--text);font-size:.8rem;white-space:nowrap;flex-shrink:0">
                        <i class="fas fa-copy"></i> Copy
                    </button>
                </div>
                <p style="font-size:.78rem;color:var(--text-muted);margin-top:.5rem">
                    Send this key to the client. They enter it on <strong>Activate License</strong> to extend their subscription.
                </p>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeGenModal()">Close</button>
            <button type="submit" form="gen-form" id="gen-btn" class="btn btn-primary" style="background:#7c3aed;border-color:#7c3aed">
                <i class="fas fa-key"></i> Generate
            </button>
        </div>
    </div>
</div>

<!-- Record Payment Modal -->
<div class="modal-backdrop" id="pay-modal" onclick="if(event.target===this)closePayModal()">
    <div class="modal" style="max-width:440px">
        <div class="modal-header">
            <h3><i class="fas fa-money-bill-wave" style="margin-right:.4rem;color:#16a34a"></i>Record Payment</h3>
            <button class="modal-close" onclick="closePayModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <div id="pay-modal-alert" style="display:none;padding:.55rem .75rem;border-radius:6px;font-size:.83rem;align-items:center;gap:.5rem;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;margin-bottom:.75rem"></div>
            <form id="pay-form">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Payment Date</label>
                        <input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div class="form-group">
                        <label>Amount (FRW)</label>
                        <input type="number" name="amount" placeholder="0.00" step="0.01" min="0.01" required>
                    </div>
                    <div class="form-group">
                        <label>Period From</label>
                        <input type="date" name="period_from" id="pay-from" required>
                    </div>
                    <div class="form-group">
                        <label>Period To</label>
                        <input type="date" name="period_to" id="pay-to" required>
                    </div>
                    <div class="form-group">
                        <label>Method</label>
                        <select name="method">
                            <option value="cash">Cash</option>
                            <option value="bank">Bank</option>
                            <option value="momo">MoMo</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Reference <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                        <input type="text" name="reference" placeholder="Receipt / ref #">
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label>Notes <span style="font-weight:400;color:var(--text-muted)">(optional)</span></label>
                        <textarea name="notes" placeholder="Optional…" style="min-height:50px"></textarea>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closePayModal()">Cancel</button>
            <button type="submit" form="pay-form" id="pay-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Payment
            </button>
        </div>
    </div>
</div>

<script>
let currentExpiry = <?= json_encode($sub['expiry_date'] ?? null) ?>;

/* ── Utilities ── */
function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function fmt(s){ return new Date(s+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'}); }

function showAlert(type, msg){
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-'+type+' mb-15';
    el.innerHTML = '<i class="fas fa-'+(type==='success'?'circle-check':'circle-xmark')+'"></i> '+msg;
    el.style.display = 'flex';
    clearTimeout(el._t); el._t = setTimeout(()=>el.style.display='none', 6000);
}
function showInfoAlert(ok, msg){
    const el = document.getElementById('info-alert');
    el.style.cssText = 'display:flex;align-items:center;gap:.5rem;padding:.5rem .75rem;border-radius:6px;font-size:.82rem;'
        + (ok ? 'background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0'
               : 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca');
    el.innerHTML = '<i class="fas fa-'+(ok?'circle-check':'circle-xmark')+'" style="flex-shrink:0"></i><span>'+msg+'</span>';
    if(ok){ clearTimeout(el._t); el._t = setTimeout(()=>el.style.display='none', 4000); }
}
function showPayModalAlert(msg){
    const el = document.getElementById('pay-modal-alert');
    el.style.display = 'flex';
    el.innerHTML = '<i class="fas fa-circle-xmark" style="flex-shrink:0"></i><span>'+msg+'</span>';
}

/* ── Subscription info form ── */
document.getElementById('sub-info-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('info-save-btn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    const fd = new FormData(this); fd.append('action','save_info');
    fetch('subscriptions.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showInfoAlert(true, d.message);
            if(d.sub){ currentExpiry = d.sub.expiry_date; }
        } else { showInfoAlert(false, d.message); }
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Info';
    }).catch(()=>{ showInfoAlert(false,'Network error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Info'; });
});

/* ── Generate Key modal ── */
function openGenModal(){
    document.getElementById('gen-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('gen-alert').style.display = 'none';
    document.getElementById('gen-key-wrap').style.display = 'none';
    // Pre-fill from = day after current expiry
    if(currentExpiry){
        const d = new Date(currentExpiry+'T00:00:00');
        d.setDate(d.getDate()+1);
        document.getElementById('gen-from').value = d.toISOString().split('T')[0];
    }
}
function closeGenModal(){
    document.getElementById('gen-modal').classList.remove('open');
    document.body.style.overflow = '';
}

document.getElementById('gen-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('gen-btn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating…';
    document.getElementById('gen-alert').style.display = 'none';
    document.getElementById('gen-key-wrap').style.display = 'none';
    const fd = new FormData(this); fd.append('action','generate_key');
    fetch('subscriptions.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            document.getElementById('gen-key-out').textContent = d.key;
            document.getElementById('gen-key-wrap').style.display = 'block';
            document.getElementById('copy-btn').innerHTML = '<i class="fas fa-copy"></i> Copy';
        } else {
            const el = document.getElementById('gen-alert');
            el.style.cssText = 'display:flex;align-items:center;gap:.5rem;padding:.55rem .75rem;border-radius:6px;font-size:.83rem;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;margin-bottom:.75rem';
            el.innerHTML = '<i class="fas fa-circle-xmark" style="flex-shrink:0"></i><span>'+d.message+'</span>';
        }
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-key"></i> Generate';
    }).catch(()=>{ btn.disabled=false; btn.innerHTML='<i class="fas fa-key"></i> Generate'; });
});

function copyKey(){
    const key = document.getElementById('gen-key-out').textContent;
    navigator.clipboard.writeText(key).then(()=>{
        const btn = document.getElementById('copy-btn');
        btn.innerHTML = '<i class="fas fa-check"></i> Copied';
        setTimeout(()=>btn.innerHTML='<i class="fas fa-copy"></i> Copy', 2000);
    });
}

/* ── Quick extend ── */
function quickExtend(period){
    const base = currentExpiry || new Date().toISOString().split('T')[0];
    const d = new Date(base+'T00:00:00');

    // "from" = day after current expiry (or today if no expiry set)
    const from = new Date(d); from.setDate(from.getDate() + (currentExpiry ? 1 : 0));

    const to = new Date(d);
    if(period === '1 month')       to.setMonth(to.getMonth()+1);
    else if(period === '3 months') to.setMonth(to.getMonth()+3);
    else if(period === '6 months') to.setMonth(to.getMonth()+6);
    else if(period === '1 year')   to.setFullYear(to.getFullYear()+1);

    document.getElementById('pay-from').value = from.toISOString().split('T')[0];
    document.getElementById('pay-to').value   = to.toISOString().split('T')[0];
    openPayModal();
}

/* ── Payment modal ── */
function openPayModal(){
    document.getElementById('pay-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
    document.getElementById('pay-modal-alert').style.display = 'none';
}
function closePayModal(){
    document.getElementById('pay-modal').classList.remove('open');
    document.body.style.overflow = '';
}
document.addEventListener('keydown', e=>{ if(e.key==='Escape') closePayModal(); });

document.getElementById('pay-form').addEventListener('submit', function(e){
    e.preventDefault();
    const btn = document.getElementById('pay-save-btn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';
    document.getElementById('pay-modal-alert').style.display = 'none';
    const fd = new FormData(this); fd.append('action','record_payment');
    fetch('subscriptions.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showAlert('success', d.message);
            prependPayRow(d.row);
            if(d.new_expiry){
                currentExpiry = d.new_expiry;
                document.getElementById('sub-expiry-inp').value = d.new_expiry;
            }
            closePayModal();
            this.reset();
            document.querySelector('#pay-form [name="payment_date"]').value = new Date().toISOString().split('T')[0];
        } else { showPayModalAlert(d.message); }
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-save"></i> Save Payment';
    }).catch(()=>{ showPayModalAlert('Network error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-save"></i> Save Payment'; });
});

/* ── Prepend payment row ── */
function prependPayRow(r){
    document.getElementById('pay-empty')?.remove();
    const mclr = {cash:'#16a34a',bank:'#2563eb',momo:'#7c3aed'}[r.payment_method] || '#6b7280';
    const mlab = {cash:'Cash',bank:'Bank',momo:'MoMo'}[r.payment_method] || r.payment_method;
    const tr = document.createElement('tr'); tr.id = 'pay-row-'+r.id;
    tr.innerHTML =
        '<td class="text-muted" style="font-size:.78rem">—</td>'+
        '<td style="font-size:.82rem;white-space:nowrap">'+fmt(r.payment_date)+'</td>'+
        '<td class="text-muted" style="font-size:.8rem;white-space:nowrap">'+fmt(r.period_from)+'</td>'+
        '<td class="text-muted" style="font-size:.8rem;white-space:nowrap">'+fmt(r.period_to)+'</td>'+
        '<td><span style="font-size:.76rem;font-weight:600;padding:.2rem .5rem;border-radius:4px;background:'+mclr+'18;color:'+mclr+'">'+mlab+'</span></td>'+
        '<td class="text-muted" style="font-size:.8rem">'+esc(r.reference||'—')+'</td>'+
        '<td style="text-align:right;font-family:monospace;font-weight:700;color:#16a34a">'+parseFloat(r.amount).toLocaleString('en-US',{minimumFractionDigits:2})+'</td>'+
        '<td class="text-muted" style="font-size:.8rem">'+esc(r.notes||'')+'</td>'+
        '<td class="text-muted" style="font-size:.78rem">'+esc(r.recorded_by_name||'—')+'</td>'+
        '<td><button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deletePayment('+r.id+',this)"><i class="fas fa-trash"></i></button></td>';
    document.getElementById('pay-tbody').insertBefore(tr, document.getElementById('pay-tbody').firstChild);
}

/* ── Delete payment ── */
function deletePayment(id, btn){
    if(!confirm('Delete this payment? This cannot be undone.')) return;
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    const fd = new FormData(); fd.append('action','delete_payment'); fd.append('id',id);
    fetch('subscriptions.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            const row = document.getElementById('pay-row-'+id);
            if(row){ row.style.transition='opacity .3s'; row.style.opacity='0'; setTimeout(()=>row.remove(),300); }
            showAlert('success', d.message);
        } else { showAlert('error', d.message); btn.disabled=false; btn.innerHTML='<i class="fas fa-trash"></i>'; }
    }).catch(()=>{ showAlert('error','Network error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-trash"></i>'; });
}
</script>

<?php include 'includes/footer.php'; ?>
