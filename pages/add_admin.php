<?php
session_start();
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit(); }
include('../config/config.php');
require_once('../includes/migrate.php');
lts_run_migrations($conn);

$error = $success = '';

// --- Register new admin ---
if (isset($_POST['register_admin'])) {
    $username = trim($_POST['username'] ?? '');
    $password_raw = $_POST['password'] ?? '';
    if ($username === '' || $password_raw === '') {
        $error = "Username and password are required.";
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
        $stmt->bind_param("s", $username);
        $stmt->execute();
        $stmt->bind_result($cnt); $stmt->fetch(); $stmt->close();
        if ($cnt > 0) {
            $error = "Username already exists.";
        } else {
            $hashed = password_hash($password_raw, PASSWORD_DEFAULT);
            $stmt = $conn->prepare("INSERT INTO users (username, password) VALUES (?, ?)");
            $stmt->bind_param("ss", $username, $hashed);
            if ($stmt->execute()) $success = "Admin '{$username}' registered successfully.";
            else $error = "Failed to register admin: " . $stmt->error;
            $stmt->close();
        }
    }
}

// --- Change credentials ---
if (isset($_POST['change_credentials'])) {
    $user_id = intval($_POST['user_id'] ?? 0);
    $new_username = trim($_POST['new_username'] ?? '');
    $new_password_raw = $_POST['new_password'] ?? '';
    if ($user_id <= 0 || $new_username === '') {
        $error = "Please select an admin and provide a new username.";
    } else {
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id != ?");
        $stmt->bind_param("si", $new_username, $user_id);
        $stmt->execute();
        $stmt->bind_result($cnt2); $stmt->fetch(); $stmt->close();
        if ($cnt2 > 0) {
            $error = "That username is already taken.";
        } else {
            if ($new_password_raw !== '') {
                $h = password_hash($new_password_raw, PASSWORD_DEFAULT);
                $stmt = $conn->prepare("UPDATE users SET username=?, password=? WHERE user_id=?");
                $stmt->bind_param("ssi", $new_username, $h, $user_id);
            } else {
                $stmt = $conn->prepare("UPDATE users SET username=? WHERE user_id=?");
                $stmt->bind_param("si", $new_username, $user_id);
            }
            if ($stmt->execute()) $success = "Credentials updated successfully.";
            else $error = "Failed to update credentials: " . $stmt->error;
            $stmt->close();
        }
    }
}

// --- Delete admin (cannot delete yourself) ---
if (isset($_GET['delete'])) {
    $del = intval($_GET['delete']);
    if ($del > 0 && $del !== intval($_SESSION['user_id'])) {
        $stmt = $conn->prepare("DELETE FROM users WHERE user_id=?");
        $stmt->bind_param("i", $del);
        if ($stmt->execute()) $success = "Admin removed.";
        $stmt->close();
    } else {
        $error = "You cannot remove your own account.";
    }
}

// Fetch admins
$users = [];
$res = $conn->query("SELECT user_id, username, role, created_at FROM users ORDER BY username ASC");
if ($res) while ($r = $res->fetch_assoc()) $users[] = $r;

$page_title = 'Admin Management';
include('../includes/header.php');
?>
<style>
.admin-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
@media (max-width: 992px) { .admin-grid { grid-template-columns: 1fr; } }
.adm-card {
    background: var(--bg-elev); border: 1px solid var(--border);
    border-radius: 14px; padding: 20px;
    box-shadow: 0 4px 12px -6px rgba(15,23,42,.08);
}
.adm-card h3 {
    font-size: 15px; font-weight: 700; margin: 0 0 14px;
    color: var(--text); letter-spacing: -.01em;
    display: flex; align-items: center; gap: 8px;
}
.adm-card h3 i { color: var(--primary); }
.adm-card .form-group { margin-bottom: 12px; }
.adm-card .form-label { font-size: 12.5px; font-weight: 600; color: var(--text-muted); margin-bottom: 4px; display: block; }
.adm-card .form-control, .adm-card .form-select {
    background: var(--bg-soft); border: 1.5px solid var(--border); color: var(--text);
    border-radius: 10px; padding: 10px 12px; font-size: 13.5px; width: 100%;
    transition: border-color .15s ease, background .15s ease;
}
.adm-card .form-control:focus, .adm-card .form-select:focus {
    outline: 0; border-color: var(--primary); background: var(--bg-elev);
}
.adm-btn {
    background: linear-gradient(135deg, #4f46e5, #6366f1); color: #fff; border: 0;
    padding: 10px 18px; border-radius: 10px; font-weight: 700; font-size: 13px;
    display: inline-flex; align-items: center; gap: 8px; cursor: pointer;
    box-shadow: 0 4px 14px -4px rgba(79,70,229,.5); transition: transform .12s, filter .12s;
}
.adm-btn:hover { transform: translateY(-1px); filter: brightness(1.05); color: #fff; }
.adm-btn.green { background: linear-gradient(135deg, #34d399, #10b981); box-shadow: 0 4px 14px -4px rgba(16,185,129,.5); }
.alert-row { padding: 10px 14px; border-radius: 10px; margin-bottom: 14px; font-size: 13.5px; display: flex; align-items: center; gap: 8px; }
.alert-row.err { background: rgba(239,68,68,.12); color: var(--danger); border: 1px solid rgba(239,68,68,.25); }
.alert-row.ok  { background: rgba(16,185,129,.12); color: var(--success); border: 1px solid rgba(16,185,129,.25); }
</style>

<div class="page-header">
    <div>
        <h2 class="title">Admin Management</h2>
        <p class="subtitle">Add new admins, change credentials, or remove accounts.</p>
    </div>
    <span class="badge-pill badge-issued"><?= count($users) ?> admin<?= count($users) === 1 ? '' : 's' ?></span>
</div>

<?php if ($error): ?>
    <div class="alert-row err"><i class="fas fa-circle-exclamation"></i> <?= htmlspecialchars($error) ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert-row ok"><i class="fas fa-circle-check"></i> <?= htmlspecialchars($success) ?></div>
<?php endif; ?>

<div class="admin-grid">
    <!-- Register -->
    <div class="adm-card">
        <h3><i class="fas fa-user-plus"></i> Add new admin</h3>
        <form method="POST" onsubmit="return confirm('Register new admin?');">
            <div class="form-group">
                <label class="form-label">Username</label>
                <input class="form-control" name="username" type="text" required autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label">Password</label>
                <input class="form-control" name="password" type="password" required autocomplete="new-password">
            </div>
            <button class="adm-btn green" name="register_admin" type="submit">
                <i class="fas fa-user-plus"></i> Register Admin
            </button>
        </form>
    </div>

    <!-- Change credentials -->
    <div class="adm-card">
        <h3><i class="fas fa-key"></i> Change credentials</h3>
        <form method="POST" onsubmit="return confirm('Apply credential changes?');">
            <div class="form-group">
                <label class="form-label">Select admin</label>
                <select class="form-select" name="user_id" required>
                    <option value="">— Select admin —</option>
                    <?php foreach ($users as $u): ?>
                        <option value="<?= intval($u['user_id']) ?>"><?= htmlspecialchars($u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label">New username</label>
                <input class="form-control" name="new_username" type="text" required autocomplete="off">
            </div>
            <div class="form-group">
                <label class="form-label">New password <span style="color:var(--text-soft); font-weight:400;">(leave blank to keep current)</span></label>
                <input class="form-control" name="new_password" type="password" autocomplete="new-password" placeholder="••••••••">
            </div>
            <button class="adm-btn" name="change_credentials" type="submit">
                <i class="fas fa-rotate"></i> Update Credentials
            </button>
        </form>
    </div>
</div>

<!-- Existing admins -->
<div class="adm-card" style="margin-top:16px;">
    <h3><i class="fas fa-users-gear"></i> Existing admins</h3>
    <div class="table-wrapper">
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th style="width:60px;">#</th>
                        <th>Username</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th style="width:90px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (count($users) > 0): $i = 1; foreach ($users as $u): ?>
                    <tr>
                        <td><?= $i++ ?></td>
                        <td><strong><?= htmlspecialchars($u['username']) ?></strong>
                            <?php if (intval($u['user_id']) === intval($_SESSION['user_id'])): ?>
                                <span class="badge-pill badge-back_to_school" style="font-size:10px; margin-left:6px;">you</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($u['role'] ?? 'admin') ?></td>
                        <td><?= !empty($u['created_at']) ? date('d M Y', strtotime($u['created_at'])) : '—' ?></td>
                        <td>
                            <?php if (intval($u['user_id']) !== intval($_SESSION['user_id'])): ?>
                                <a href="?delete=<?= intval($u['user_id']) ?>"
                                   class="btn btn-sm btn-danger"
                                   title="Remove"
                                   onclick="return confirm('Remove this admin? This cannot be undone.');">
                                    <i class="fa fa-trash"></i>
                                </a>
                            <?php else: ?>
                                <span class="text-soft">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; else: ?>
                    <tr><td colspan="5" class="text-center" style="padding:24px; color:var(--text-muted);">No admins found.</td></tr>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include('../includes/footer.php'); ?>
