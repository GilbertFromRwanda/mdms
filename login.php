<?php
require_once 'config/database.php';
if(isLoggedIn()){ header('Location: dashboard.php'); exit; }

if($_SERVER['REQUEST_METHOD']==='POST' && isset($_SERVER['HTTP_X_REQUESTED_WITH'])){
    header('Content-Type: application/json');
    $username = trim($_POST['username']??'');
    $password = $_POST['password']??'';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if($user && password_verify($password,$user['password'])){
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        echo json_encode(['success'=>true,'redirect'=>'dashboard.php']);
    } else {
        echo json_encode(['success'=>false,'message'=>'Invalid username or password.']);
    }
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Login — Minerals Depot</title>
<link rel="stylesheet" href="css/style.css">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
</head>
<body>

<div class="login-wrap">

    <!-- Brand panel -->
    <div class="login-brand">
        <div class="lb-inner">
            <div class="lb-icon"><i class="fas fa-mountain"></i></div>
            <h1>Minerals Depot<br>Management System</h1>
            <p>Full traceability from supplier to dispatch — compliance-ready from day one.</p>
            <div class="lb-features">
                <div class="lb-feat">
                    <div class="lb-feat-icon"><i class="fas fa-boxes-stacked"></i></div>
                    Batch &amp; lot tracking with certificates
                </div>
                <div class="lb-feat">
                    <div class="lb-feat-icon"><i class="fas fa-right-left"></i></div>
                    Real-time IN / OUT stock validation
                </div>
                <div class="lb-feat">
                    <div class="lb-feat-icon"><i class="fas fa-scroll"></i></div>
                    Full audit log with IP traceability
                </div>
                <div class="lb-feat">
                    <div class="lb-feat-icon"><i class="fas fa-chart-bar"></i></div>
                    Operational reports &amp; analytics
                </div>
            </div>
        </div>
    </div>

    <!-- Form panel -->
    <div class="login-form-side">
        <div class="lf-inner">
            <h2>Welcome back</h2>
            <p class="lf-sub">Sign in to your account to continue</p>

            <div id="err-msg" class="alert alert-error" style="display:none;"></div>

            <form id="login-form">
                <div class="form-group">
                    <label>Username</label>
                    <input type="text" name="username" id="username" placeholder="Enter username" required autofocus>
                </div>
                <div class="form-group" style="margin-top:.75rem">
                    <label>Password</label>
                    <input type="password" name="password" id="password" placeholder="Enter password" required>
                </div>
                <button type="submit" class="login-btn" id="login-btn">
                    Sign In
                </button>
            </form>

            <div class="login-demo">
                <i class="fas fa-circle-info" style="margin-right:.35rem"></i>
                Demo credentials: <strong>admin</strong> / <strong>admin123</strong>
            </div>
        </div>
    </div>

</div>

<script>
document.getElementById('login-form').addEventListener('submit',function(e){
    e.preventDefault();
    const btn=document.getElementById('login-btn');
    const err=document.getElementById('err-msg');
    btn.disabled=true;
    btn.textContent='Signing in…';
    err.style.display='none';

    fetch('login.php',{
        method:'POST',
        headers:{'X-Requested-With':'XMLHttpRequest'},
        body:new FormData(this)
    })
    .then(r=>r.json())
    .then(d=>{
        if(d.success){ window.location.href=d.redirect; }
        else{
            err.innerHTML='<i class="fas fa-circle-xmark"></i> '+d.message;
            err.style.display='flex';
            btn.disabled=false;
            btn.textContent='Sign In';
        }
    })
    .catch(()=>{
        err.innerHTML='<i class="fas fa-wifi"></i> Network error — please try again.';
        err.style.display='flex';
        btn.disabled=false;
        btn.textContent='Sign In';
    });
});
</script>
</body>
</html>
