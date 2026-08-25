<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

// Only super_admin can manage admins
if ($_SESSION['admin_role'] !== 'super_admin') {
    $_SESSION['error_message'] = 'Anda tidak memiliki akses untuk mengelola admin!';
    header('Location: index.php');
    exit;
}

$page_title = 'Manage Admins';
$show_header = true;

$action = $_GET['action'] ?? 'list';
$admin_id = $_GET['id'] ?? null;

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $db = get_db_connection();
    
    if (isset($_POST['action'])) {
        switch ($_POST['action']) {
            case 'create':
                $username = sanitize_input($_POST['username'] ?? '');
                $email = sanitize_input($_POST['email'] ?? '');
                $password = $_POST['password'] ?? '';
                $full_name = sanitize_input($_POST['full_name'] ?? '');
                $role = sanitize_input($_POST['role'] ?? 'admin');
                
                if (empty($username) || empty($email) || empty($password)) {
                    $_SESSION['error_message'] = 'Username, email, dan password harus diisi!';
                } else {
                    try {
                        // Check if username or email already exists
                        $stmt = $db->prepare("SELECT id FROM admins WHERE username = ? OR email = ?");
                        $stmt->execute([$username, $email]);
                        if ($stmt->fetch()) {
                            $_SESSION['error_message'] = 'Username atau email sudah digunakan!';
                        } else {
                            $password_hash = hash_password($password);
                            $stmt = $db->prepare("
                                INSERT INTO admins (username, email, password_hash, full_name, role)
                                VALUES (?, ?, ?, ?, ?)
                            ");
                            $stmt->execute([$username, $email, $password_hash, $full_name, $role]);
                            
                            $new_admin_id = $db->lastInsertId();
                            log_activity('create', 'admin', $new_admin_id, "Created admin: {$username}");
                            $_SESSION['success_message'] = "Admin '{$username}' berhasil dibuat!";
                            header('Location: admins.php');
                            exit;
                        }
                    } catch (Exception $e) {
                        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'update':
                $admin_id = intval($_POST['admin_id'] ?? 0);
                $username = sanitize_input($_POST['username'] ?? '');
                $email = sanitize_input($_POST['email'] ?? '');
                $full_name = sanitize_input($_POST['full_name'] ?? '');
                $role = sanitize_input($_POST['role'] ?? 'admin');
                
                if (empty($username) || empty($email)) {
                    $_SESSION['error_message'] = 'Username dan email harus diisi!';
                } else {
                    try {
                        // Check if username or email already exists (excluding current admin)
                        $stmt = $db->prepare("SELECT id FROM admins WHERE (username = ? OR email = ?) AND id != ?");
                        $stmt->execute([$username, $email, $admin_id]);
                        if ($stmt->fetch()) {
                            $_SESSION['error_message'] = 'Username atau email sudah digunakan!';
                        } else {
                            $stmt = $db->prepare("
                                UPDATE admins 
                                SET username = ?, email = ?, full_name = ?, role = ?
                                WHERE id = ?
                            ");
                            $stmt->execute([$username, $email, $full_name, $role, $admin_id]);
                            
                            log_activity('update', 'admin', $admin_id, "Updated admin: {$username}");
                            $_SESSION['success_message'] = "Admin '{$username}' berhasil diperbarui!";
                            header('Location: admins.php');
                            exit;
                        }
                    } catch (Exception $e) {
                        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'delete':
                $admin_id = intval($_POST['admin_id'] ?? 0);
                
                // Prevent deleting yourself
                if ($admin_id == $_SESSION['admin_id']) {
                    $_SESSION['error_message'] = 'Anda tidak dapat menghapus akun sendiri!';
                } else {
                    try {
                        $stmt = $db->prepare("SELECT username FROM admins WHERE id = ?");
                        $stmt->execute([$admin_id]);
                        $admin = $stmt->fetch();
                        
                        if ($admin) {
                            $stmt = $db->prepare("DELETE FROM admins WHERE id = ?");
                            $stmt->execute([$admin_id]);
                            
                            log_activity('delete', 'admin', $admin_id, "Deleted admin: {$admin['username']}");
                            $_SESSION['success_message'] = "Admin '{$admin['username']}' berhasil dihapus!";
                        }
                        header('Location: admins.php');
                        exit;
                    } catch (Exception $e) {
                        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
                    }
                }
                break;
                
            case 'reset_password':
                $admin_id = intval($_POST['admin_id'] ?? 0);
                $new_password = $_POST['new_password'] ?? '';
                $confirm_password = $_POST['confirm_password'] ?? '';
                
                if (empty($new_password)) {
                    $_SESSION['error_message'] = 'Password baru harus diisi!';
                } elseif ($new_password !== $confirm_password) {
                    $_SESSION['error_message'] = 'Password dan konfirmasi password tidak cocok!';
                } elseif (strlen($new_password) < 6) {
                    $_SESSION['error_message'] = 'Password minimal 6 karakter!';
                } else {
                    try {
                        $password_hash = hash_password($new_password);
                        $stmt = $db->prepare("UPDATE admins SET password_hash = ? WHERE id = ?");
                        $stmt->execute([$password_hash, $admin_id]);
                        
                        $stmt = $db->prepare("SELECT username FROM admins WHERE id = ?");
                        $stmt->execute([$admin_id]);
                        $admin = $stmt->fetch();
                        
                        log_activity('update', 'admin', $admin_id, "Reset password for admin: {$admin['username']}");
                        $_SESSION['success_message'] = "Password admin '{$admin['username']}' berhasil direset!";
                        header('Location: admins.php');
                        exit;
                    } catch (Exception $e) {
                        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
                    }
                }
                break;
        }
    }
}

// Get admin data for edit
$admin_data = null;
if ($action === 'edit' && $admin_id) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
        $stmt->execute([$admin_id]);
        $admin_data = $stmt->fetch();
        
        if (!$admin_data) {
            $_SESSION['error_message'] = 'Admin tidak ditemukan!';
            header('Location: admins.php');
            exit;
        }
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        header('Location: admins.php');
        exit;
    }
}

// Get all admins for list
$admins = [];
if ($action === 'list') {
    try {
        $db = get_db_connection();
        $stmt = $db->query("SELECT * FROM admins ORDER BY created_at DESC");
        $admins = $stmt->fetchAll();
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
}

include '../includes/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-users-cog"></i> Manage Admins
        </h1>
        <a href="admins.php?action=create" class="btn btn-primary">
            <i class="fas fa-plus-circle"></i> Add Admin
        </a>
    </div>
    
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th><i class="fas fa-hashtag"></i> ID</th>
                    <th><i class="fas fa-user"></i> Username</th>
                    <th><i class="fas fa-envelope"></i> Email</th>
                    <th><i class="fas fa-id-card"></i> Full Name</th>
                    <th><i class="fas fa-shield-alt"></i> Role</th>
                    <th><i class="fas fa-clock"></i> Last Login</th>
                    <th><i class="fas fa-calendar"></i> Created</th>
                    <th><i class="fas fa-cog"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($admins)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-users" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px; display: block;"></i>
                            <div style="font-size: 14px;">No admins found</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($admins as $admin): ?>
                        <tr>
                            <td><?= $admin['id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($admin['username']) ?></strong>
                                <?php if ($admin['id'] == $_SESSION['admin_id']): ?>
                                    <span class="badge badge-info" style="margin-left: 8px;">
                                        <i class="fas fa-user"></i> You
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($admin['email']) ?></td>
                            <td><?= htmlspecialchars($admin['full_name'] ?: '-') ?></td>
                            <td>
                                <?php if ($admin['role'] === 'super_admin'): ?>
                                    <span class="badge badge-warning">
                                        <i class="fas fa-crown"></i> Super Admin
                                    </span>
                                <?php else: ?>
                                    <span class="badge badge-info">
                                        <i class="fas fa-user-shield"></i> Admin
                                    </span>
                                <?php endif; ?>
                            </td>
                            <td><?= $admin['last_login'] ? format_date($admin['last_login'], 'Y-m-d H:i') : '-' ?></td>
                            <td><?= format_date($admin['created_at'], 'Y-m-d H:i') ?></td>
                            <td>
                                <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                                    <a href="admins.php?action=edit&id=<?= $admin['id'] ?>" class="btn btn-sm btn-secondary">
                                        <i class="fas fa-edit"></i> Edit
                                    </a>
                                    <button type="button" class="btn btn-sm btn-secondary" onclick="showResetPasswordModal(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>')">
                                        <i class="fas fa-key"></i> Reset Password
                                    </button>
                                    <?php if ($admin['id'] != $_SESSION['admin_id']): ?>
                                        <button type="button" class="btn btn-sm btn-danger" onclick="confirmDelete(<?= $admin['id'] ?>, '<?= htmlspecialchars($admin['username']) ?>')">
                                            <i class="fas fa-trash"></i> Delete
                                        </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Reset Password Modal -->
<div id="resetPasswordModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-key"></i> Reset Password</h2>
            <button class="modal-close" onclick="closeResetPasswordModal()">&times;</button>
        </div>
        <form method="POST" id="resetPasswordForm">
            <input type="hidden" name="action" value="reset_password">
            <input type="hidden" name="admin_id" id="reset_admin_id">
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-user"></i> Admin
                </label>
                <input type="text" class="form-control" id="reset_admin_username" readonly>
            </div>
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-lock"></i> New Password
                </label>
                <input type="password" name="new_password" class="form-control" required minlength="6">
                <div class="form-text">Minimum 6 characters</div>
            </div>
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-lock"></i> Confirm Password
                </label>
                <input type="password" name="confirm_password" class="form-control" required minlength="6">
            </div>
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Reset Password
                </button>
                <button type="button" class="btn btn-secondary" onclick="closeResetPasswordModal()">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Delete Confirmation Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="admin_id" id="delete_admin_id">
</form>

<script>
function showResetPasswordModal(adminId, username) {
    document.getElementById('reset_admin_id').value = adminId;
    document.getElementById('reset_admin_username').value = username;
    document.getElementById('resetPasswordModal').classList.add('show');
}

function closeResetPasswordModal() {
    document.getElementById('resetPasswordModal').classList.remove('show');
    document.getElementById('resetPasswordForm').reset();
}

function confirmDelete(adminId, username) {
    if (confirm(`Apakah Anda yakin ingin menghapus admin "${username}"?`)) {
        document.getElementById('delete_admin_id').value = adminId;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php elseif ($action === 'create' || $action === 'edit'): ?>
<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-<?= $action === 'create' ? 'plus-circle' : 'edit' ?>"></i> 
            <?= $action === 'create' ? 'Add' : 'Edit' ?> Admin
        </h1>
        <a href="admins.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Admins
        </a>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;">
        <div>
            <h3 style="margin-bottom: 20px; color: var(--text-muted); font-size: 16px; text-transform: uppercase; border-bottom: 2px solid var(--bg); padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-user-circle"></i> Account Information
            </h3>
            
            <form method="POST" id="adminForm">
                <input type="hidden" name="action" value="<?= $action === 'create' ? 'create' : 'update' ?>">
                <?php if ($action === 'edit'): ?>
                    <input type="hidden" name="admin_id" value="<?= $admin_data['id'] ?>">
                <?php endif; ?>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-user"></i> Username
                    </label>
                    <input type="text" name="username" class="form-control" 
                           value="<?= htmlspecialchars($admin_data['username'] ?? '') ?>" required>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Unique username for login
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-envelope"></i> Email
                    </label>
                    <input type="email" name="email" class="form-control" 
                           value="<?= htmlspecialchars($admin_data['email'] ?? '') ?>" required>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Email address for notifications
                    </div>
                </div>
                
                <?php if ($action === 'create'): ?>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-lock"></i> Password
                        </label>
                        <input type="password" name="password" class="form-control" required minlength="6" id="password">
                        <div class="form-text">
                            <i class="fas fa-shield-alt"></i> Minimum 6 characters
                        </div>
                    </div>
                <?php else: ?>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-lock"></i> Password
                        </label>
                        <div style="padding: 12px 15px; background: var(--bg); border-radius: 6px; border: 1px solid var(--border); color: var(--text-muted); display: flex; align-items: center; gap: 8px;">
                            <i class="fas fa-info-circle"></i> 
                            <span>Use "Reset Password" button to change password</span>
                        </div>
                    </div>
                <?php endif; ?>
        </div>
        
        <div>
            <h3 style="margin-bottom: 20px; color: var(--text-muted); font-size: 16px; text-transform: uppercase; border-bottom: 2px solid var(--bg); padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-cog"></i> Account Settings
            </h3>
            
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-id-card"></i> Full Name
                    </label>
                    <input type="text" name="full_name" class="form-control" 
                           value="<?= htmlspecialchars($admin_data['full_name'] ?? '') ?>" 
                           placeholder="Enter full name (optional)">
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Display name for this admin
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-shield-alt"></i> Role
                    </label>
                    <select name="role" class="form-control" required>
                        <option value="admin" <?= ($admin_data['role'] ?? 'admin') === 'admin' ? 'selected' : '' ?>>
                            <i class="fas fa-user-shield"></i> Admin
                        </option>
                        <option value="super_admin" <?= ($admin_data['role'] ?? '') === 'super_admin' ? 'selected' : '' ?>>
                            <i class="fas fa-crown"></i> Super Admin
                        </option>
                    </select>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Super Admin memiliki akses penuh termasuk mengelola admin lain
                    </div>
                </div>
                
                <?php if ($action === 'edit' && $admin_data): ?>
                    <div class="form-group">
                        <label class="form-label">
                            <i class="fas fa-calendar"></i> Account Information
                        </label>
                        <div style="display: grid; gap: 10px;">
                            <div style="padding: 12px 15px; background: var(--bg); border-radius: 6px; border: 1px solid var(--border);">
                                <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Created At</div>
                                <div style="font-weight: 600; color: var(--text);">
                                    <i class="fas fa-calendar-plus"></i> <?= format_date($admin_data['created_at'], 'Y-m-d H:i') ?>
                                </div>
                            </div>
                            <?php if ($admin_data['last_login']): ?>
                                <div style="padding: 12px 15px; background: var(--bg); border-radius: 6px; border: 1px solid var(--border);">
                                    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Last Login</div>
                                    <div style="font-weight: 600; color: var(--text);">
                                        <i class="fas fa-clock"></i> <?= format_date($admin_data['last_login'], 'Y-m-d H:i') ?>
                                    </div>
                                </div>
                            <?php else: ?>
                                <div style="padding: 12px 15px; background: var(--bg); border-radius: 6px; border: 1px solid var(--border);">
                                    <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 4px;">Last Login</div>
                                    <div style="font-weight: 600; color: var(--text-muted);">
                                        <i class="fas fa-minus-circle"></i> Never logged in
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid var(--bg);">
                    <div style="display: flex; gap: 10px;">
                        <button type="submit" class="btn btn-primary btn-lg" style="flex: 1;">
                            <i class="fas fa-save"></i> <?= $action === 'create' ? 'Create' : 'Update' ?> Admin
                        </button>
                        <a href="admins.php" class="btn btn-secondary btn-lg">
                            <i class="fas fa-times"></i> Cancel
                        </a>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

