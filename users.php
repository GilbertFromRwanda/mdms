<?php
require_once 'config/database.php';
if (!isLoggedIn() || $_SESSION['role'] != 'admin') {
    header('Location: dashboard.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['add'])) {
        $hashed = password_hash($_POST['password'], PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([$_POST['username'], $hashed, $_POST['full_name'], $_POST['email'], $_POST['role']]);
        logAction($pdo, $_SESSION['user_id'], 'CREATE', 'users', $pdo->lastInsertId(), "Added user: {$_POST['username']}");
        $success = "User created!";
    }
}

$users = $pdo->query("SELECT * FROM users ORDER BY created_at DESC")->fetchAll();
?>
<!DOCTYPE html>
<html>
<head>
    <title>User Management - Minerals Depot</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>
    <?php include 'includes/header.php'; ?>
    <div class="container">
        <h1>👥 User Management</h1>
        
        <button onclick="toggleForm()" class="btn">+ Add User</button>
        
        <div id="userForm" style="display:none; margin: 20px 0; background: white; padding: 20px; border-radius: 8px;">
            <form method="POST">
                <input type="hidden" name="add" value="1">
                <div class="form-group"><label>Username</label><input type="text" name="username" required></div>
                <div class="form-group"><label>Password</label><input type="password" name="password" required></div>
                <div class="form-group"><label>Full Name</label><input type="text" name="full_name" required></div>
                <div class="form-group"><label>Email</label><input type="email" name="email"></div>
                <div class="form-group">
                    <label>Role</label>
                    <select name="role">
                        <option value="admin">Admin</option>
                        <option value="manager">Manager</option>
                        <option value="storekeeper">Storekeeper</option>
                    </select>
                </div>
                <button type="submit">Create User</button>
            </form>
        </div>
        
        <table class="data-table">
            <thead><tr><th>Username</th><th>Full Name</th><th>Email</th><th>Role</th><th>Created</th></tr></thead>
            <tbody>
                <?php foreach($users as $user): ?>
                <tr>
                    <td><?= htmlspecialchars($user['username']) ?></td>
                    <td><?= htmlspecialchars($user['full_name']) ?></td>
                    <td><?= $user['email'] ?></td>
                    <td><span class="role-<?= $user['role'] ?>"><?= ucfirst($user['role']) ?></span></td>
                    <td><?= $user['created_at'] ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <style>
        .role-admin { color: #dc3545; font-weight: bold; }
        .role-manager { color: #ffc107; font-weight: bold; }
        .role-storekeeper { color: #28a745; font-weight: bold; }
    </style>
    <script>
        function toggleForm() {
            var form = document.getElementById('userForm');
            form.style.display = form.style.display === 'none' ? 'block' : 'none';
        }
    </script>
    <?php include 'includes/footer.php'; ?>
</body>
</html>