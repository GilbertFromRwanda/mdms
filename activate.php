<?php
require_once 'config/database.php';
if(!isLoggedIn()){ header('Location: login.php'); exit; }

/* ── AJAX: validate key ── */
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    $act = $_POST['action'] ?? '';

    if($act === 'validate_key'){
        $key  = trim($_POST['key'] ?? '');
        $data = validateLicenseKey($key);
        if(!$data){
            echo json_encode(['success'=>false,'message'=>'Invalid or tampered license key. Please check the key and try again.']);
        } else {
            echo json_encode(['success'=>true,'data'=>$data]);
        }
        exit;
    }

    if($act === 'activate_key'){
        try {
            $key  = trim($_POST['key'] ?? '');
            $data = validateLicenseKey($key);
            if(!$data) throw new Exception('Invalid license key.');

            $pf  = $data['f'];
            $pt  = $data['t'];
            $pln = $data['p'];
            $amt = (float)($data['a'] ?? 0);

            $pdo->beginTransaction();

            // Record the payment
            $pdo->prepare("INSERT INTO subscription_payments (payment_date,amount,period_from,period_to,payment_method,reference,notes,recorded_by) VALUES (?,?,?,?,?,?,?,?)")
                ->execute([date('Y-m-d'), $amt, $pf, $pt, 'bank', 'License Key', 'Activated via license key', $_SESSION['user_id']]);
            $payId = (int)$pdo->lastInsertId();

            // Extend expiry if period_to is later
            $sub = $pdo->query("SELECT id,expiry_date,client_name FROM subscription WHERE is_active=1 LIMIT 1")->fetch();
            $new_expiry = null;
            if($sub){
                if($pt > $sub['expiry_date']){
                    $pdo->prepare("UPDATE subscription SET expiry_date=?,plan_name=?,updated_at=NOW() WHERE id=?")->execute([$pt,$pln,$sub['id']]);
                    $new_expiry = $pt;
                }
            } else {
                // No subscription row yet — create one
                $pdo->prepare("INSERT INTO subscription (client_name,plan_name,amount,start_date,expiry_date,grace_days) VALUES (?,?,?,?,?,3)")
                    ->execute(['Client', $pln, $amt, $pf, $pt]);
                $new_expiry = $pt;
            }

            $pdo->commit();
            logAction($pdo,$_SESSION['user_id'],'CREATE','subscription_payments',$payId,"License key activated: $pf to $pt");
            echo json_encode(['success'=>true,'message'=>'License activated. Subscription extended to '.date('d M Y',strtotime($pt)).'.','new_expiry'=>$new_expiry]);
        } catch(Exception $e){
            if($pdo->inTransaction()) $pdo->rollBack();
            echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
        }
        exit;
    }

    echo json_encode(['success'=>false,'message'=>'Unknown action.']);
    exit;
}

$page_title = 'Activate License';
include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-unlock" style="margin-right:.4rem;color:var(--text-muted)"></i>Activate License</h2>
</div>

<div style="max-width:560px">

    <!-- Step 1: Enter key -->
    <div id="step-enter" style="border:1px solid var(--border);border-radius:8px;background:var(--surface,var(--bg));overflow:hidden;margin-bottom:1rem">
        <div style="padding:.7rem 1rem;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:.45rem">
            <i class="fas fa-key" style="color:#7c3aed;font-size:.85rem"></i>
            <span style="font-size:.84rem;font-weight:600">Enter License Key</span>
        </div>
        <div style="padding:1rem">
            <p style="font-size:.83rem;color:var(--text-muted);margin-bottom:.85rem;line-height:1.5">
                Paste the license key provided by your system administrator to extend your subscription.
            </p>
            <div id="enter-alert" style="display:none;padding:.55rem .75rem;border-radius:6px;font-size:.83rem;align-items:center;gap:.5rem;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;margin-bottom:.75rem"></div>
            <div style="display:flex;flex-direction:column;gap:.65rem">
                <textarea id="key-input" placeholder="MDMS.eyJ…" rows="3"
                    style="padding:.55rem .75rem;border:1px solid var(--border);border-radius:6px;background:var(--surface);color:var(--text);font-family:monospace;font-size:.8rem;resize:vertical;width:100%;box-sizing:border-box"></textarea>
                <div style="display:flex;justify-content:flex-end">
                    <button type="button" class="btn btn-primary" onclick="validateKey()" id="validate-btn">
                        <i class="fas fa-magnifying-glass"></i> Validate Key
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Step 2: Preview + confirm (hidden until valid) -->
    <div id="step-confirm" style="display:none;border:1px solid #bbf7d0;border-radius:8px;background:var(--surface,var(--bg));overflow:hidden">
        <div style="padding:.7rem 1rem;border-bottom:1px solid #bbf7d0;display:flex;align-items:center;gap:.45rem;background:#f0fdf4">
            <i class="fas fa-circle-check" style="color:#16a34a;font-size:.85rem"></i>
            <span style="font-size:.84rem;font-weight:600;color:#16a34a">Key Verified</span>
        </div>
        <div style="padding:1rem">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:.4rem .75rem;margin-bottom:1rem">
                <div>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:.1rem">Period From</div>
                    <div style="font-size:.9rem;font-weight:700" id="prev-from">—</div>
                </div>
                <div>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:.1rem">Period To</div>
                    <div style="font-size:.9rem;font-weight:700;color:#16a34a" id="prev-to">—</div>
                </div>
                <div>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:.1rem">Plan</div>
                    <div style="font-size:.88rem;font-weight:600" id="prev-plan">—</div>
                </div>
                <div>
                    <div style="font-size:.72rem;color:var(--text-muted);margin-bottom:.1rem">Amount</div>
                    <div style="font-size:.88rem;font-weight:600;font-family:monospace" id="prev-amount">—</div>
                </div>
            </div>
            <div id="confirm-alert" style="display:none;padding:.55rem .75rem;border-radius:6px;font-size:.83rem;align-items:center;gap:.5rem;margin-bottom:.75rem"></div>
            <div style="display:flex;gap:.5rem;justify-content:flex-end">
                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                    <i class="fas fa-xmark"></i> Cancel
                </button>
                <button type="button" class="btn btn-primary" onclick="activateKey()" id="activate-btn" style="background:#16a34a;border-color:#16a34a">
                    <i class="fas fa-unlock"></i> Activate
                </button>
            </div>
        </div>
    </div>

</div>

<script>
let validatedKey = null;

function fmtDate(s){
    return new Date(s+'T00:00:00').toLocaleDateString('en-GB',{day:'2-digit',month:'short',year:'numeric'});
}

function showEnterAlert(msg){
    const el = document.getElementById('enter-alert');
    el.style.display = 'flex';
    el.innerHTML = '<i class="fas fa-circle-xmark" style="flex-shrink:0"></i><span>'+msg+'</span>';
}

function showConfirmAlert(ok, msg){
    const el = document.getElementById('confirm-alert');
    el.style.cssText = 'display:flex;align-items:center;gap:.5rem;padding:.55rem .75rem;border-radius:6px;font-size:.83rem;'
        + (ok ? 'background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0'
               : 'background:#fef2f2;color:#dc2626;border:1px solid #fecaca');
    el.innerHTML = '<i class="fas fa-'+(ok?'circle-check':'circle-xmark')+'" style="flex-shrink:0"></i><span>'+msg+'</span>';
}

function validateKey(){
    const key = document.getElementById('key-input').value.trim();
    if(!key){ showEnterAlert('Please paste the license key.'); return; }
    const btn = document.getElementById('validate-btn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Validating…';
    document.getElementById('enter-alert').style.display = 'none';
    document.getElementById('step-confirm').style.display = 'none';

    const fd = new FormData(); fd.append('action','validate_key'); fd.append('key',key);
    fetch('activate.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            validatedKey = key;
            const data = d.data;
            document.getElementById('prev-from').textContent   = fmtDate(data.f);
            document.getElementById('prev-to').textContent     = fmtDate(data.t);
            document.getElementById('prev-plan').textContent   = data.p;
            document.getElementById('prev-amount').textContent = parseFloat(data.a||0).toLocaleString('en-US',{minimumFractionDigits:2})+' FRW';
            document.getElementById('confirm-alert').style.display = 'none';
            document.getElementById('step-confirm').style.display = 'block';
        } else {
            showEnterAlert(d.message);
        }
        btn.disabled = false; btn.innerHTML = '<i class="fas fa-magnifying-glass"></i> Validate Key';
    }).catch(()=>{ showEnterAlert('Network error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-magnifying-glass"></i> Validate Key'; });
}

function activateKey(){
    if(!validatedKey) return;
    const btn = document.getElementById('activate-btn');
    btn.disabled = true; btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Activating…';
    document.getElementById('confirm-alert').style.display = 'none';

    const fd = new FormData(); fd.append('action','activate_key'); fd.append('key',validatedKey);
    fetch('activate.php',{method:'POST',headers:{'X-Requested-With':'XMLHttpRequest'},body:fd})
    .then(r=>r.json()).then(d=>{
        if(d.success){
            showConfirmAlert(true, d.message);
            btn.disabled = true; btn.innerHTML = '<i class="fas fa-check"></i> Activated';
            document.getElementById('key-input').value = '';
            validatedKey = null;
            setTimeout(()=>window.location.href='subscriptions.php', 2000);
        } else {
            showConfirmAlert(false, d.message);
            btn.disabled = false; btn.innerHTML = '<i class="fas fa-unlock"></i> Activate';
        }
    }).catch(()=>{ showConfirmAlert(false,'Network error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-unlock"></i> Activate'; });
}

function resetForm(){
    validatedKey = null;
    document.getElementById('step-confirm').style.display = 'none';
    document.getElementById('enter-alert').style.display  = 'none';
    document.getElementById('key-input').value = '';
}
</script>

<?php include 'includes/footer.php'; ?>
