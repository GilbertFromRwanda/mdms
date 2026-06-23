<?php
require_once 'config/database.php';
if (!isLoggedIn() || !in_array($_SESSION['role'], ['admin','system'])) {
    header('Location: dashboard.php');
    exit;
}
$is_system = ($_SESSION['role'] === 'system');

/* ── AJAX handlers ───────────────────────────────────────────── */
if (isset($_SERVER['HTTP_X_REQUESTED_WITH'])) {
    header('Content-Type: application/json');

    /* Add user */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add'])) {
        try {
            if ($_POST['role'] === 'system' && !$is_system) {
                echo json_encode(['success'=>false,'message'=>'Only the system owner can create system accounts.']);
                exit;
            }
            $existing = $pdo->prepare("SELECT id FROM users WHERE username = ?");
            $existing->execute([$_POST['username']]);
            if ($existing->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Username already exists.']);
                exit;
            }
            $hashed = password_hash($_POST['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password, full_name, email, role) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([
                $_POST['username'], $hashed,
                $_POST['full_name'], $_POST['email'] ?? '', $_POST['role']
            ]);
            $id = $pdo->lastInsertId();
            logAction($pdo, $_SESSION['user_id'], 'CREATE', 'users', $id, "Added user: {$_POST['username']}");
            echo json_encode([
                'success' => true,
                'message' => "User <strong>" . htmlspecialchars($_POST['username']) . "</strong> created.",
                'user'    => [
                    'id'         => $id,
                    'username'   => $_POST['username'],
                    'full_name'  => $_POST['full_name'],
                    'email'      => $_POST['email'] ?? '',
                    'role'       => $_POST['role'],
                    'created_at' => date('Y-m-d H:i:s'),
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    /* Edit user */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit'])) {
        try {
            $uid = (int)$_POST['id'];
            $target = $pdo->prepare("SELECT role FROM users WHERE id=?"); $target->execute([$uid]); $tr=$target->fetch();
            if ($tr && $tr['role']==='system' && !$is_system) {
                echo json_encode(['success'=>false,'message'=>'Only the system owner can edit system accounts.']);
                exit;
            }
            if ($_POST['role']==='system' && !$is_system) {
                echo json_encode(['success'=>false,'message'=>'Only the system owner can assign the system role.']);
                exit;
            }
            /* Check username uniqueness (excluding self) */
            $existing = $pdo->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $existing->execute([$_POST['username'], $uid]);
            if ($existing->fetch()) {
                echo json_encode(['success' => false, 'message' => 'Username already taken.']);
                exit;
            }
            if (!empty($_POST['password'])) {
                $hashed = password_hash($_POST['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, password=?, full_name=?, email=?, role=? WHERE id=?");
                $stmt->execute([$_POST['username'], $hashed, $_POST['full_name'], $_POST['email'] ?? '', $_POST['role'], $uid]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username=?, full_name=?, email=?, role=? WHERE id=?");
                $stmt->execute([$_POST['username'], $_POST['full_name'], $_POST['email'] ?? '', $_POST['role'], $uid]);
            }
            logAction($pdo, $_SESSION['user_id'], 'UPDATE', 'users', $uid, "Edited user: {$_POST['username']}");
            echo json_encode([
                'success' => true,
                'message' => "User <strong>" . htmlspecialchars($_POST['username']) . "</strong> updated.",
                'user'    => [
                    'id'        => $uid,
                    'username'  => $_POST['username'],
                    'full_name' => $_POST['full_name'],
                    'email'     => $_POST['email'] ?? '',
                    'role'      => $_POST['role'],
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }

    /* Delete user */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete'])) {
        try {
            $uid = (int)$_POST['id'];
            $target = $pdo->prepare("SELECT role FROM users WHERE id=?"); $target->execute([$uid]); $tr=$target->fetch();
            if ($tr && $tr['role']==='system' && !$is_system) {
                echo json_encode(['success'=>false,'message'=>'Only the system owner can delete system accounts.']);
                exit;
            }
            if ($uid === (int)$_SESSION['user_id']) {
                echo json_encode(['success' => false, 'message' => 'You cannot delete your own account.']);
                exit;
            }
            $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
            logAction($pdo, $_SESSION['user_id'], 'DELETE', 'users', $uid, "Deleted user ID: $uid");
            echo json_encode(['success' => true, 'message' => 'User removed.']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
        }
        exit;
    }
}

/* ── Page data ───────────────────────────────────────────────── */
$page_title = 'User Management';

$search = trim($_GET['search'] ?? '');
$where_parts = [];
$params = [];
if (!$is_system) {
    $where_parts[] = "role != 'system'";
}
if ($search) {
    $where_parts[] = '(username LIKE ? OR full_name LIKE ? OR email LIKE ?)';
    $s = '%' . $search . '%';
    $params = [$s, $s, $s];
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';
$stmt = $pdo->prepare("SELECT * FROM users $where_sql ORDER BY created_at DESC");
$stmt->execute($params);
$users = $stmt->fetchAll();

$has_filters = (bool)$search;

include 'includes/header.php';
?>

<div id="page-alert" class="alert mb-15" style="display:none"></div>

<div class="page-header">
    <h2><i class="fas fa-users" style="margin-right:.4rem;color:var(--text-muted)"></i>User Management</h2>
    <button class="btn btn-primary" onclick="openModal()">
        <i class="fas fa-plus"></i> Add User
    </button>
</div>

<!-- Add / Edit User Modal -->
<div class="modal-backdrop" id="usr-modal" onclick="if(event.target===this)closeModal()">
    <div class="modal" style="max-width:520px">
        <div class="modal-header">
            <h3 id="usr-modal-title"><i class="fas fa-user-plus" style="margin-right:.4rem;color:var(--primary)"></i>Add New User</h3>
            <button class="modal-close" onclick="closeModal()" type="button"><i class="fas fa-xmark"></i></button>
        </div>
        <div class="modal-body">
            <form id="usr-form">
                <input type="hidden" name="id" id="usr-id">
                <div class="form-grid form-grid-2">
                    <div class="form-group">
                        <label>Username</label>
                        <input type="text" name="username" id="usr-username" placeholder="john_doe" required>
                    </div>
                    <div class="form-group">
                        <label>Full Name</label>
                        <input type="text" name="full_name" id="usr-full-name" placeholder="John Doe" required>
                    </div>
                    <div class="form-group">
                        <label>Email</label>
                        <input type="email" name="email" id="usr-email" placeholder="john@example.com">
                    </div>
                    <div class="form-group">
                        <label>Role</label>
                        <select name="role" id="usr-role">
                            <?php if($is_system): ?>
                            <option value="system">System</option>
                            <?php endif; ?>
                            <option value="admin">Admin</option>
                            <option value="manager">Manager</option>
                            <option value="storekeeper">Storekeeper</option>
                        </select>
                    </div>
                    <div class="form-group" style="grid-column:1/-1">
                        <label id="usr-pw-label">Password</label>
                        <input type="password" name="password" id="usr-password" placeholder="Min. 6 characters">
                        <small id="usr-pw-hint" style="color:var(--text-muted);display:none">Leave blank to keep current password</small>
                    </div>
                </div>
            </form>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
            <button type="submit" form="usr-form" id="usr-save-btn" class="btn btn-primary">
                <i class="fas fa-save"></i> Save User
            </button>
        </div>
    </div>
</div>

<!-- Filter bar -->
<form method="GET" action="users.php" class="filter-bar">
    <div class="filter-group">
        <label>Search</label>
        <input type="search" name="search" placeholder="Username, name, email…"
               value="<?= htmlspecialchars($search) ?>">
    </div>
    <div class="filter-actions">
        <button type="submit" class="btn btn-primary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-filter"></i> Filter
        </button>
        <?php if ($has_filters): ?>
        <a href="users.php" class="btn btn-secondary" style="height:2rem;padding:0 .75rem;font-size:.82rem">
            <i class="fas fa-xmark"></i> Clear
        </a>
        <?php endif; ?>
    </div>
    <?php if ($has_filters): ?>
    <span class="filter-active-badge"><i class="fas fa-circle-dot" style="font-size:.6rem"></i> <?= count($users) ?> result<?= count($users) !== 1 ? 's' : '' ?></span>
    <?php endif; ?>
</form>

<!-- Table -->
<div class="table-wrap">
    <table>
        <thead>
            <tr>
                <th>#</th>
                <th>Username</th>
                <th>Full Name</th>
                <th>Email</th>
                <th>Role</th>
                <th>Created</th>
                <th style="text-align:center">Actions</th>
            </tr>
        </thead>
        <tbody id="usr-tbody">
        <?php $i = 0; foreach ($users as $u): ?>
        <tr id="usr-row-<?= $u['id'] ?>"
            data-username="<?= htmlspecialchars($u['username'], ENT_QUOTES) ?>"
            data-full-name="<?= htmlspecialchars($u['full_name'], ENT_QUOTES) ?>"
            data-email="<?= htmlspecialchars($u['email'] ?? '', ENT_QUOTES) ?>"
            data-role="<?= htmlspecialchars($u['role'], ENT_QUOTES) ?>">
            <td class="font-mono text-muted" style="font-size:.78rem"><?= ++$i ?></td>
            <td class="fw-600"><?= htmlspecialchars($u['username']) ?></td>
            <td><?= htmlspecialchars($u['full_name']) ?></td>
            <td class="text-muted" style="font-size:.82rem"><?= htmlspecialchars($u['email'] ?? '') ?></td>
            <td><?php
                $role_colors = ['system'=>'#7c3aed','admin'=>'#dc2626','manager'=>'#d97706','storekeeper'=>'#16a34a'];
                $rc = $role_colors[$u['role']] ?? '#6b7280';
            ?><span style="color:<?= $rc ?>;font-weight:700;font-size:.82rem"><?= ucfirst($u['role']) ?></span></td>
            <td class="text-muted" style="font-size:.82rem"><?= date('d M Y', strtotime($u['created_at'])) ?></td>
            <td style="text-align:center;white-space:nowrap">
                <button class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;margin-right:.3rem"
                        onclick="editUser(<?= $u['id'] ?>, this)" title="Edit">
                    <i class="fas fa-pencil"></i>
                </button>
                <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
                <button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem"
                        onclick="deleteUser(<?= $u['id'] ?>, this)" title="Delete">
                    <i class="fas fa-trash"></i>
                </button>
                <?php else: ?>
                <button class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;opacity:.4;cursor:default"
                        title="Cannot delete own account" disabled>
                    <i class="fas fa-trash"></i>
                </button>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$users): ?>
        <tr id="empty-row"><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">
            <?= $has_filters ? 'No users match the current filters.' : 'No users yet.' ?>
        </td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
const SELF_ID = <?= (int)$_SESSION['user_id'] ?>;
let editMode = false;

function openModal(isEdit = false) {
    editMode = isEdit;
    const title   = document.getElementById('usr-modal-title');
    const pwInput = document.getElementById('usr-password');
    const pwHint  = document.getElementById('usr-pw-hint');
    const pwLabel = document.getElementById('usr-pw-label');

    if (isEdit) {
        title.innerHTML = '<i class="fas fa-user-pen" style="margin-right:.4rem;color:var(--primary)"></i>Edit User';
        pwInput.required = false;
        pwHint.style.display  = 'block';
        pwLabel.textContent   = 'New Password';
    } else {
        title.innerHTML = '<i class="fas fa-user-plus" style="margin-right:.4rem;color:var(--primary)"></i>Add New User';
        pwInput.required = true;
        pwHint.style.display  = 'none';
        pwLabel.textContent   = 'Password';
    }

    document.getElementById('usr-modal').classList.add('open');
    document.body.style.overflow = 'hidden';
}

function closeModal() {
    document.getElementById('usr-modal').classList.remove('open');
    document.body.style.overflow = '';
    document.getElementById('usr-form').reset();
    document.getElementById('usr-id').value = '';
    editMode = false;
}

document.addEventListener('keydown', e => { if (e.key === 'Escape') closeModal(); });

function esc(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function roleColor(role) {
    return { system:'#7c3aed', admin:'#dc2626', manager:'#d97706', storekeeper:'#16a34a' }[role] || '#6b7280';
}

function showAlert(type, msg) {
    const el = document.getElementById('page-alert');
    el.className = 'alert alert-' + type + ' mb-15';
    el.innerHTML = '<i class="fas fa-' + (type === 'success' ? 'circle-check' : 'circle-xmark') + '"></i> ' + msg;
    el.style.display = 'flex';
    clearTimeout(el._t);
    el._t = setTimeout(() => { el.style.display = 'none'; }, 5000);
}

/* ── Form submit (add or edit) ─────────────────────────────── */
document.getElementById('usr-form').addEventListener('submit', function(e) {
    e.preventDefault();
    const btn = document.getElementById('usr-save-btn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving…';

    const fd = new FormData(this);
    fd.append(editMode ? 'edit' : 'add', '1');

    fetch('users.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            showAlert('success', d.message);
            if (editMode) {
                updateRow(d.user);
            } else {
                prependRow(d.user);
            }
            closeModal();
        } else {
            showAlert('error', d.message);
        }
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save User';
    })
    .catch(() => {
        showAlert('error', 'Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-save"></i> Save User';
    });
});

/* ── Edit: open modal pre-filled ───────────────────────────── */
function editUser(id, btn) {
    const row = document.getElementById('usr-row-' + id);
    document.getElementById('usr-id').value        = id;
    document.getElementById('usr-username').value  = row.dataset.username;
    document.getElementById('usr-full-name').value = row.dataset.fullName;
    document.getElementById('usr-email').value     = row.dataset.email;
    document.getElementById('usr-role').value      = row.dataset.role;
    document.getElementById('usr-password').value  = '';
    openModal(true);
}

function updateRow(u) {
    const row = document.getElementById('usr-row-' + u.id);
    if (!row) return;
    row.dataset.username  = u.username;
    row.dataset.fullName  = u.full_name;
    row.dataset.email     = u.email;
    row.dataset.role      = u.role;

    const cells = row.querySelectorAll('td');
    cells[1].textContent = u.username;
    cells[1].className   = 'fw-600';
    cells[2].textContent = u.full_name;
    cells[3].textContent = u.email;
    cells[4].innerHTML   = '<span style="color:' + roleColor(u.role) + ';font-weight:700;font-size:.82rem">' + u.role.charAt(0).toUpperCase() + u.role.slice(1) + '</span>';
}

function prependRow(u) {
    const tbody = document.getElementById('usr-tbody');
    const empty = document.getElementById('empty-row');
    if (empty) empty.remove();

    const isSelf = u.id === SELF_ID;
    const deleteBtn = isSelf
        ? '<button class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;opacity:.4;cursor:default" title="Cannot delete own account" disabled><i class="fas fa-trash"></i></button>'
        : '<button class="btn btn-danger" style="padding:.3rem .6rem;font-size:.75rem" onclick="deleteUser(' + u.id + ', this)"><i class="fas fa-trash"></i></button>';

    const tr = document.createElement('tr');
    tr.id = 'usr-row-' + u.id;
    tr.dataset.username  = u.username;
    tr.dataset.fullName  = u.full_name;
    tr.dataset.email     = u.email;
    tr.dataset.role      = u.role;
    tr.innerHTML =
        '<td class="font-mono text-muted" style="font-size:.78rem">—</td>' +
        '<td class="fw-600">' + esc(u.username) + '</td>' +
        '<td>' + esc(u.full_name) + '</td>' +
        '<td class="text-muted" style="font-size:.82rem">' + esc(u.email) + '</td>' +
        '<td><span style="color:' + roleColor(u.role) + ';font-weight:700;font-size:.82rem">' + u.role.charAt(0).toUpperCase() + u.role.slice(1) + '</span></td>' +
        '<td class="text-muted" style="font-size:.82rem">Just now</td>' +
        '<td style="text-align:center;white-space:nowrap">' +
            '<button class="btn btn-secondary" style="padding:.3rem .6rem;font-size:.75rem;margin-right:.3rem" onclick="editUser(' + u.id + ', this)"><i class="fas fa-pencil"></i></button>' +
            deleteBtn +
        '</td>';
    tbody.insertBefore(tr, tbody.firstChild);
}

/* ── Delete user ───────────────────────────────────────────── */
function deleteUser(id, btn) {
    if (!confirm('Delete this user? This cannot be undone.')) return;
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('delete', '1');
    fd.append('id', id);

    fetch('users.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' },
        body: fd
    })
    .then(r => r.json())
    .then(d => {
        if (d.success) {
            const row = document.getElementById('usr-row-' + id);
            row.style.transition = 'opacity .3s';
            row.style.opacity = '0';
            setTimeout(() => {
                row.remove();
                if (!document.querySelector('#usr-tbody tr:not(#empty-row)')) {
                    document.getElementById('usr-tbody').innerHTML =
                        '<tr id="empty-row"><td colspan="7" style="text-align:center;padding:2rem;color:var(--text-muted)">No users yet.</td></tr>';
                }
            }, 300);
            showAlert('success', d.message);
        } else {
            showAlert('error', d.message);
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash"></i>';
        }
    })
    .catch(() => {
        showAlert('error', 'Network error.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fas fa-trash"></i>';
    });
}
</script>

<?php include 'includes/footer.php'; ?>
