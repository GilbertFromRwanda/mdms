<?php
include("config.php");
try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch(PDOException $e) {
    die("Connection failed: " . $e->getMessage());
}

session_start();

function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

function hasRole($role) {
    return isset($_SESSION['role']) && $_SESSION['role'] == $role;
}

function logAction($pdo, $user_id, $action, $table_name, $record_id, $details = null) {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$user_id, $action, $table_name, $record_id, $details, $ip]);
}

function generateLicenseKey(string $period_from, string $period_to, string $plan, float $amount): string {
    $payload = json_encode(['f'=>$period_from,'t'=>$period_to,'p'=>$plan,'a'=>round($amount,2)]);
    $b64     = rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    $sig     = substr(hash_hmac('sha256', $payload, LICENSE_SECRET), 0, 16);
    return 'MDMS.'.$b64.'.'.$sig;
}

function validateLicenseKey(string $key): array|false {
    $parts = explode('.', trim($key));
    if(count($parts) !== 3 || $parts[0] !== 'MDMS') return false;
    $payload = base64_decode(strtr($parts[1], '-_', '+/'));
    if(!$payload) return false;
    $data = json_decode($payload, true);
    if(!$data || !isset($data['f'],$data['t'],$data['p'])) return false;
    $expected = substr(hash_hmac('sha256', $payload, LICENSE_SECRET), 0, 16);
    if(!hash_equals($expected, $parts[2])) return false;
    if($data['t'] < $data['f']) return false;
    return $data;
}

function checkSubscription(PDO $pdo): void {
    $skip = ['login.php', 'logout.php', 'subscription_expired.php', 'subscriptions.php', 'activate.php'];
    if (in_array(basename($_SERVER['PHP_SELF']), $skip)) return;
    if (!isset($_SESSION['user_id'])) return;
    try {
        $sub = $pdo->query("SELECT expiry_date, grace_days FROM subscription WHERE is_active=1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) { return; }
    if (!$sub) return;
    $grace_until = date('Y-m-d', strtotime($sub['expiry_date'] . ' +' . max(0, (int)$sub['grace_days']) . ' days'));
    if (date('Y-m-d') > $grace_until) {
        if (($_SESSION['role'] ?? '') === 'superadmin') {
            header('Location: subscriptions.php');
        } else {
            header('Location: subscription_expired.php');
        }
        exit;
    }
}
checkSubscription($pdo);

function paginate(int $page, int $total_pages, array $params, string $script): string {
    if($total_pages <= 1) return '';
    $url = function(int $p) use ($params, $script): string {
        $q = array_filter(array_merge($params, ['page' => $p]), fn($v) => $v !== '' && $v !== null);
        return $script . '?' . http_build_query($q);
    };
    $html = '<div class="pagination-wrap"><nav class="pagination">';
    $html .= '<a href="'.($page > 1 ? $url($page - 1) : '#').'" class="'.($page <= 1 ? 'pg-disabled' : '').'"><i class="fas fa-chevron-left" style="font-size:.65rem"></i></a>';
    $prev = null;
    for($i = 1; $i <= $total_pages; $i++){
        if($i !== 1 && $i !== $total_pages && abs($i - $page) > 2) continue;
        if($prev !== null && $i - $prev > 1) $html .= '<span class="pg-dots">…</span>';
        $html .= '<a href="'.$url($i).'" class="'.($i === $page ? 'pg-active' : '').'">'.$i.'</a>';
        $prev = $i;
    }
    $html .= '<a href="'.($page < $total_pages ? $url($page + 1) : '#').'" class="'.($page >= $total_pages ? 'pg-disabled' : '').'"><i class="fas fa-chevron-right" style="font-size:.65rem"></i></a>';
    $html .= '</nav></div>';
    return $html;
}
?>