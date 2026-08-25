<?php
/**
 * Remote Deactivation Endpoint
 * 
 * Allows admin to remotely deactivate licenses
 */

require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Remote Deactivation';
$show_header = true;

$message = '';
$action = $_GET['action'] ?? 'list';
$license_id = intval($_GET['id'] ?? 0);

// Handle remote deactivation
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();
    
    $action_type = $_POST['action'] ?? '';
    $license_id = intval($_POST['license_id'] ?? 0);
    $activation_id = intval($_POST['activation_id'] ?? 0);
    $reason = sanitize_input($_POST['reason'] ?? 'Remote deactivation by admin');
    
    try {
        $db = get_db_connection();
        
        if ($action_type === 'deactivate_license') {
            // Deactivate all activations for a license
            $stmt = $db->prepare("SELECT license_key FROM licenses WHERE id = ?");
            $stmt->execute([$license_id]);
            $license = $stmt->fetch();
            
            if (!$license) {
                $_SESSION['error_message'] = 'License not found';
            } else {
                $db->beginTransaction();
                
                // Deactivate all activations
                $stmt = $db->prepare("
                    UPDATE activations 
                    SET status = 'deactivated',
                        remote_deactivated = 1,
                        remote_deactivation_reason = ?,
                        remote_deactivated_by = ?,
                        remote_deactivated_at = NOW(),
                        deactivated_at = NOW()
                    WHERE license_id = ? AND status = 'active'
                ");
                $stmt->execute([$reason, $_SESSION['admin_id'], $license_id]);
                $deactivated_count = $stmt->rowCount();
                
                // Update license status
                $db->prepare("UPDATE licenses SET status = 'suspended' WHERE id = ?")->execute([$license_id]);
                
                $db->commit();
                
                log_activity('remote_deactivate', 'license', $license_id, "Remotely deactivated license: {$license['license_key']} ({$deactivated_count} activations)");
                $_SESSION['success_message'] = "Successfully deactivated {$deactivated_count} activation(s) for license #{$license_id}";
            }
        } elseif ($action_type === 'deactivate_activation') {
            // Deactivate specific activation
            $stmt = $db->prepare("
                SELECT a.*, l.license_key 
                FROM activations a
                JOIN licenses l ON a.license_id = l.id
                WHERE a.id = ?
            ");
            $stmt->execute([$activation_id]);
            $activation = $stmt->fetch();
            
            if (!$activation) {
                $_SESSION['error_message'] = 'Activation not found';
            } else {
                $db->beginTransaction();
                
                $stmt = $db->prepare("
                    UPDATE activations 
                    SET status = 'deactivated',
                        remote_deactivated = 1,
                        remote_deactivation_reason = ?,
                        remote_deactivated_by = ?,
                        remote_deactivated_at = NOW(),
                        deactivated_at = NOW()
                    WHERE id = ?
                ");
                $stmt->execute([$reason, $_SESSION['admin_id'], $activation_id]);
                
                // Check if license has any remaining activations
                $stmt = $db->prepare("SELECT COUNT(*) as count FROM activations WHERE license_id = ? AND status = 'active'");
                $stmt->execute([$activation['license_id']]);
                $remaining = $stmt->fetch()['count'];
                
                if ($remaining === 0) {
                    $db->prepare("UPDATE licenses SET status = 'inactive' WHERE id = ?")->execute([$activation['license_id']]);
                }
                
                $db->commit();
                
                log_activity('remote_deactivate', 'activation', $activation_id, "Remotely deactivated activation for license: {$activation['license_key']}");
                $_SESSION['success_message'] = "Successfully deactivated activation #{$activation_id}";
            }
        }
        
        header('Location: remote-deactivate.php');
        exit;
    } catch (Exception $e) {
        if (isset($db) && $db->inTransaction()) {
            $db->rollBack();
        }
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
}

// Get licenses with activations
try {
    $db = get_db_connection();
    
    $licenses = $db->query("
        SELECT l.*, 
               COUNT(DISTINCT a.id) as activation_count,
               COUNT(DISTINCT CASE WHEN a.status = 'active' THEN a.id END) as active_count
        FROM licenses l
        LEFT JOIN activations a ON l.id = a.license_id
        WHERE l.status IN ('active', 'suspended')
        GROUP BY l.id
        HAVING active_count > 0
        ORDER BY l.created_at DESC
    ")->fetchAll();
    
    // Get activations for specific license if requested
    $activations = [];
    if ($action === 'activations' && $license_id > 0) {
        $stmt = $db->prepare("
            SELECT a.*, l.license_key, l.product_id
            FROM activations a
            JOIN licenses l ON a.license_id = l.id
            WHERE a.license_id = ? AND a.status = 'active'
            ORDER BY a.activated_at DESC
        ");
        $stmt->execute([$license_id]);
        $activations = $stmt->fetchAll();
    }
} catch (Exception $e) {
    $licenses = [];
    $activations = [];
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
}

include '../includes/header.php';
?>

<?php if ($action === 'list'): ?>
<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-ban"></i> Remote Deactivation
        </h1>
    </div>
    
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>License Key</th>
                    <th>Product</th>
                    <th>Status</th>
                    <th>Active Activations</th>
                    <th>Total Activations</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($licenses)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-check-circle fa-3x" style="margin-bottom: 15px; color: var(--success); opacity: 0.3;"></i><br>
                            No active licenses with activations found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($licenses as $license): ?>
                    <tr>
                        <td><code><?= sanitize_output($license['license_key']) ?></code></td>
                        <td><?= sanitize_output($license['product_id']) ?></td>
                        <td>
                            <span class="badge badge-<?= $license['status'] ?>">
                                <?= ucfirst($license['status']) ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge badge-success"><?= $license['active_count'] ?></span>
                        </td>
                        <td><?= $license['activation_count'] ?></td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <a href="?action=activations&id=<?= $license['id'] ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-eye"></i> View Activations
                                </a>
                                <button type="button" class="btn btn-sm btn-danger" 
                                        onclick="showDeactivateModal(<?= $license['id'] ?>, '<?= htmlspecialchars($license['license_key'], ENT_QUOTES) ?>', 'license')">
                                    <i class="fas fa-ban"></i> Deactivate All
                                </button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Deactivate License Modal -->
<div id="deactivateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-ban"></i> Remote Deactivation</h2>
            <button class="modal-close" onclick="hideModal('deactivateModal')">&times;</button>
        </div>
        <form method="POST" id="deactivateForm">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" id="deactivate_action">
            <input type="hidden" name="license_id" id="deactivate_license_id">
            <input type="hidden" name="activation_id" id="deactivate_activation_id">
            
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-key"></i> License/Activation
                </label>
                <input type="text" class="form-control" id="deactivate_target" readonly>
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-comment"></i> Reason
                </label>
                <textarea name="reason" class="form-control" rows="3" required>Remote deactivation by admin</textarea>
                <div class="form-text">Reason for deactivation (will be logged)</div>
            </div>
            
            <div class="alert alert-warning" style="margin: 20px 0;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Warning:</strong> This will immediately deactivate the license/activation. This action cannot be undone.
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-ban"></i> Confirm Deactivation
                </button>
                <button type="button" class="btn btn-secondary" onclick="hideModal('deactivateModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showDeactivateModal(id, target, type) {
    document.getElementById('deactivate_license_id').value = type === 'license' ? id : '';
    document.getElementById('deactivate_activation_id').value = type === 'activation' ? id : '';
    document.getElementById('deactivate_action').value = type === 'license' ? 'deactivate_license' : 'deactivate_activation';
    document.getElementById('deactivate_target').value = target;
    showModal('deactivateModal');
}
</script>

<?php elseif ($action === 'activations' && $license_id > 0): ?>
<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-link"></i> Activations for License #<?= $license_id ?>
        </h1>
        <a href="remote-deactivate.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back
        </a>
    </div>
    
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Site URL</th>
                    <th>Site Name</th>
                    <th>IP Address</th>
                    <th>Activated At</th>
                    <th>Last Check</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activations)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No active activations found.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($activations as $activation): ?>
                    <tr>
                        <td><?= sanitize_output($activation['site_url']) ?></td>
                        <td><?= sanitize_output($activation['site_name'] ?: '-') ?></td>
                        <td><?= sanitize_output($activation['ip_address'] ?: '-') ?></td>
                        <td><?= format_date($activation['activated_at']) ?></td>
                        <td><?= $activation['last_check'] ? format_date($activation['last_check']) : 'Never' ?></td>
                        <td>
                            <button type="button" class="btn btn-sm btn-danger" 
                                    onclick="showDeactivateModal(<?= $activation['id'] ?>, '<?= htmlspecialchars($activation['site_url'], ENT_QUOTES) ?>', 'activation')">
                                <i class="fas fa-ban"></i> Deactivate
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Same modal as above -->
<div id="deactivateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2><i class="fas fa-ban"></i> Remote Deactivation</h2>
            <button class="modal-close" onclick="hideModal('deactivateModal')">&times;</button>
        </div>
        <form method="POST" id="deactivateForm">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" id="deactivate_action">
            <input type="hidden" name="license_id" id="deactivate_license_id">
            <input type="hidden" name="activation_id" id="deactivate_activation_id">
            
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-key"></i> License/Activation
                </label>
                <input type="text" class="form-control" id="deactivate_target" readonly>
            </div>
            
            <div class="form-group">
                <label class="form-label">
                    <i class="fas fa-comment"></i> Reason
                </label>
                <textarea name="reason" class="form-control" rows="3" required>Remote deactivation by admin</textarea>
                <div class="form-text">Reason for deactivation (will be logged)</div>
            </div>
            
            <div class="alert alert-warning" style="margin: 20px 0;">
                <i class="fas fa-exclamation-triangle"></i>
                <strong>Warning:</strong> This will immediately deactivate the license/activation. This action cannot be undone.
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="submit" class="btn btn-danger">
                    <i class="fas fa-ban"></i> Confirm Deactivation
                </button>
                <button type="button" class="btn btn-secondary" onclick="hideModal('deactivateModal')">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showDeactivateModal(id, target, type) {
    document.getElementById('deactivate_license_id').value = type === 'license' ? id : '';
    document.getElementById('deactivate_activation_id').value = type === 'activation' ? id : '';
    document.getElementById('deactivate_action').value = type === 'license' ? 'deactivate_license' : 'deactivate_activation';
    document.getElementById('deactivate_target').value = target;
    showModal('deactivateModal');
}
</script>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

