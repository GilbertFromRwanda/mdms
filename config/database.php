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
    // $stmt = $pdo->prepare("INSERT INTO audit_log (user_id, action, table_name, record_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
    // $stmt->execute([$user_id, $action, $table_name, $record_id, $details, $ip]);
}

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