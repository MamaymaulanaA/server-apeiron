<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'License Details';
$show_header = true;

$license_id = intval($_GET['id'] ?? 0);

if (!$license_id) {
    header('Location: licenses.php');
    exit;
}

try {
    $db = get_db_connection();
    
    // Get license details
    $stmt = $db->prepare("
        SELECT l.*, ad.username as created_by_name, ad.full_name as created_by_fullname
        FROM licenses l
        LEFT JOIN admins ad ON l.created_by = ad.id
        WHERE l.id = ?
    ");
    $stmt->execute([$license_id]);
    $license = $stmt->fetch();
    
    if (!$license) {
        $_SESSION['error_message'] = 'License not found';
        header('Location: licenses.php');
        exit;
    }
    
    // Get activations
    $activations = $db->prepare("
        SELECT * FROM activations 
        WHERE license_id = ? 
        ORDER BY activated_at DESC
    ");
    $activations->execute([$license_id]);
    $activations = $activations->fetchAll();
    
    // Get activity logs for this license
    $logs = $db->prepare("
        SELECT al.*, ad.username
        FROM activity_logs al
        LEFT JOIN admins ad ON al.admin_id = ad.id
        WHERE al.entity_type = 'license' AND al.entity_id = ?
        ORDER BY al.created_at DESC
        LIMIT 50
    ");
    $logs->execute([$license_id]);
    $logs = $logs->fetchAll();
    
} catch (Exception $e) {
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    header('Location: licenses.php');
    exit;
}

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-certificate"></i> License Details
        </h1>
        <div>
            <a href="licenses.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back
            </a>
            <button class="btn btn-primary" onclick="showEditModal()">
                <i class="fas fa-edit"></i> Edit
            </button>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-bottom: 30px;">
        <div>
            <h3 style="margin-bottom: 15px; color: var(--text-muted); font-size: 14px; text-transform: uppercase;">License Information</h3>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 10px 0; color: var(--text-muted); width: 150px;">License Key:</td>
                    <td style="padding: 10px 0;">
                        <code style="font-size: 16px;"><?= htmlspecialchars($license['license_key']) ?></code>
                        <button class="btn btn-sm btn-secondary" data-copy="<?= htmlspecialchars($license['license_key']) ?>" style="margin-left: 10px;">
                            <i class="fas fa-copy"></i>
                        </button>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: var(--text-muted);">Product ID:</td>
                    <td style="padding: 10px 0;"><?= htmlspecialchars($license['product_id']) ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: var(--text-muted);">Status:</td>
                    <td style="padding: 10px 0;">
                        <span class="badge badge-<?= $license['status'] ?>">
                            <?= ucfirst($license['status']) ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: var(--text-muted);">Expires:</td>
                    <td style="padding: 10px 0;">
                        <?php if ($license['expires']): ?>
                            <?php 
                                $days_left = (strtotime($license['expires']) - time()) / 86400;
                                $status_class = $days_left < 0 ? 'danger' : ($days_left <= 7 ? 'warning' : 'success');
                            ?>
                            <?= format_date($license['expires'], 'Y-m-d') ?>
                            <span class="badge badge-<?= $status_class ?>" style="margin-left: 10px;">
                                <?= floor($days_left) ?> days left
                            </span>
                        <?php else: ?>
                            <span style="color: var(--text-muted);">Never</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: var(--text-muted);">Activation Limit:</td>
                    <td style="padding: 10px 0;"><?= $license['activation_limit'] ?: 'Unlimited' ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: var(--text-muted);">Created:</td>
                    <td style="padding: 10px 0;">
                        <?= format_date($license['created_at']) ?>
                        <?php if ($license['created_by_name']): ?>
                            <br><small style="color: var(--text-muted);">by <?= htmlspecialchars($license['created_by_fullname'] ?: $license['created_by_name']) ?></small>
                        <?php endif; ?>
                    </td>
                </tr>
            </table>
        </div>
        
        <div>
            <h3 style="margin-bottom: 15px; color: var(--text-muted); font-size: 14px; text-transform: uppercase;">Customer Information</h3>
            <table style="width: 100%;">
                <tr>
                    <td style="padding: 10px 0; color: var(--text-muted); width: 150px;">Name:</td>
                    <td style="padding: 10px 0;"><?= htmlspecialchars($license['customer_name'] ?: '-') ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: var(--text-muted);">Email:</td>
                    <td style="padding: 10px 0;"><?= htmlspecialchars($license['customer_email'] ?: '-') ?></td>
                </tr>
                <tr>
                    <td style="padding: 10px 0; color: var(--text-muted); vertical-align: top;">Notes:</td>
                    <td style="padding: 10px 0;"><?= nl2br(htmlspecialchars($license['notes'] ?: '-')) ?></td>
                </tr>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fas fa-link"></i> Activations (<?= count($activations) ?>)
        </h2>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Site URL</th>
                    <th>Site Name</th>
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Activated</th>
                    <th>Last Check</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activations)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No activations yet
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($activations as $activation): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($activation['site_url']) ?></code></td>
                        <td><?= htmlspecialchars($activation['site_name'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($activation['ip_address'] ?: '-') ?></td>
                        <td>
                            <span class="badge badge-<?= $activation['status'] === 'active' ? 'success' : 'inactive' ?>">
                                <?= ucfirst($activation['status']) ?>
                            </span>
                        </td>
                        <td><?= format_date($activation['activated_at']) ?></td>
                        <td><?= $activation['last_check'] ? format_date($activation['last_check']) : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fas fa-history"></i> Activity Logs
        </h2>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Description</th>
                    <th>User</th>
                    <th>IP Address</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No activity logs
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td><span class="badge badge-info"><?= htmlspecialchars($log['action']) ?></span></td>
                        <td><?= htmlspecialchars($log['description'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($log['username'] ?: 'System') ?></td>
                        <td><?= htmlspecialchars($log['ip_address'] ?: '-') ?></td>
                        <td><?= format_date($log['created_at']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit Modal (same as licenses.php) -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit License</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" action="licenses.php">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="license_id" value="<?= $license['id'] ?>">
            
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control" required>
                    <option value="active" <?= $license['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $license['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                    <option value="expired" <?= $license['status'] === 'expired' ? 'selected' : '' ?>>Expired</option>
                    <option value="suspended" <?= $license['status'] === 'suspended' ? 'selected' : '' ?>>Suspended</option>
                </select>
            </div>
            
            <div class="form-group">
                <label class="form-label">Expiration Date</label>
                <input type="date" name="expires" class="form-control" value="<?= $license['expires'] ?>">
                <div class="form-text">Leave empty for no expiration</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Activation Limit</label>
                <input type="number" name="activation_limit" class="form-control" value="<?= $license['activation_limit'] ?>" min="0" required>
                <div class="form-text">0 = unlimited</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Name</label>
                <input type="text" name="customer_name" class="form-control" value="<?= htmlspecialchars($license['customer_name'] ?: '') ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Email</label>
                <input type="email" name="customer_email" class="form-control" value="<?= htmlspecialchars($license['customer_email'] ?: '') ?>">
            </div>
            
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"><?= htmlspecialchars($license['notes'] ?: '') ?></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="hideModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update License</button>
            </div>
        </form>
    </div>
</div>

<script>
function showEditModal() {
    showModal('editModal');
}
</script>

<?php include '../includes/footer.php'; ?>

