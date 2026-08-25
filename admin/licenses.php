<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/pagination.php';

require_admin_login();

$page_title = 'Manage Licenses';
$show_header = true;

$message = '';

// FEATURE: Export functionality
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    require_csrf_token(); // Validate via GET token or session
    
    try {
        $db = get_db_connection();
        
        // Get all licenses (or filtered)
        $filter_status = $_GET['status'] ?? '';
        $filter_product = $_GET['product'] ?? '';
        $search = $_GET['search'] ?? '';
        
        $where = [];
        $params = [];
        
        if ($filter_status) {
            $where[] = "l.status = ?";
            $params[] = $filter_status;
        }
        
        if ($filter_product) {
            $where[] = "l.product_id = ?";
            $params[] = $filter_product;
        }
        
        if ($search) {
            $where[] = "(l.license_key LIKE ? OR l.customer_name LIKE ? OR l.customer_email LIKE ?)";
            $search_term = "%{$search}%";
            $params[] = $search_term;
            $params[] = $search_term;
            $params[] = $search_term;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        $stmt = $db->prepare("
            SELECT l.*, COUNT(DISTINCT a.id) as activation_count,
                   ad.username as created_by_name
            FROM licenses l 
            LEFT JOIN activations a ON l.id = a.license_id AND a.status = 'active'
            LEFT JOIN admins ad ON l.created_by = ad.id
            {$where_sql}
            GROUP BY l.id 
            ORDER BY l.created_at DESC
        ");
        $stmt->execute($params);
        $licenses = $stmt->fetchAll();
        
        // Set headers for CSV download
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="licenses_' . date('Y-m-d_His') . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');
        
        // Output CSV
        $output = fopen('php://output', 'w');
        
        // BOM for UTF-8 Excel compatibility
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        
        // Headers
        fputcsv($output, [
            'License Key',
            'Product ID',
            'Status',
            'Customer Name',
            'Customer Email',
            'Expires',
            'Activation Limit',
            'Activations',
            'Created At',
            'Created By',
            'Notes'
        ]);
        
        // Data rows
        foreach ($licenses as $license) {
            fputcsv($output, [
                $license['license_key'],
                $license['product_id'],
                $license['status'],
                $license['customer_name'] ?? '',
                $license['customer_email'] ?? '',
                $license['expires'] ?? '',
                $license['activation_limit'],
                $license['activation_count'],
                $license['created_at'],
                $license['created_by_name'] ?? '',
                $license['notes'] ?? ''
            ]);
        }
        
        fclose($output);
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Export error: ' . $e->getMessage();
        header('Location: licenses.php');
        exit;
    }
}

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // SECURITY: Require CSRF token for all POST requests
    require_csrf_token();
    
    $action = $_POST['action'] ?? '';
    $license_id = intval($_POST['license_id'] ?? 0);
    
    try {
        $db = get_db_connection();
        
        // FEATURE: Bulk operations
        if ($action === 'bulk_delete' || $action === 'bulk_update_status') {
            $selected_ids = $_POST['selected_ids'] ?? [];
            if (empty($selected_ids) || !is_array($selected_ids)) {
                $_SESSION['error_message'] = 'Tidak ada license yang dipilih!';
                header('Location: licenses.php');
                exit;
            }
            
            // Validate all IDs are integers
            $selected_ids = array_map('intval', $selected_ids);
            $selected_ids = array_filter($selected_ids, function($id) { return $id > 0; });
            
            if (empty($selected_ids)) {
                $_SESSION['error_message'] = 'ID license tidak valid!';
                header('Location: licenses.php');
                exit;
            }
            
            $placeholders = implode(',', array_fill(0, count($selected_ids), '?'));
            
            if ($action === 'bulk_delete') {
                // Get license keys for logging
                $stmt = $db->prepare("SELECT license_key FROM licenses WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                $deleted_licenses = $stmt->fetchAll(PDO::FETCH_COLUMN);
                
                // Delete licenses
                $stmt = $db->prepare("DELETE FROM licenses WHERE id IN ($placeholders)");
                $stmt->execute($selected_ids);
                
                log_activity('bulk_delete', 'license', null, "Deleted " . count($selected_ids) . " license(s)");
                $_SESSION['success_message'] = count($selected_ids) . ' license berhasil dihapus!';
            } elseif ($action === 'bulk_update_status') {
                $new_status = sanitize_input($_POST['new_status'] ?? '');
                if (!validate_status($new_status)) {
                    $_SESSION['error_message'] = 'Status tidak valid!';
                    header('Location: licenses.php');
                    exit;
                }
                
                $stmt = $db->prepare("UPDATE licenses SET status = ? WHERE id IN ($placeholders)");
                $stmt->execute(array_merge([$new_status], $selected_ids));
                
                log_activity('bulk_update', 'license', null, "Updated status to {$new_status} for " . count($selected_ids) . " license(s)");
                $_SESSION['success_message'] = count($selected_ids) . ' license berhasil diupdate status ke ' . ucfirst($new_status) . '!';
            }
            
            header('Location: licenses.php');
            exit;
        }
        
        if ($action === 'delete') {
            $stmt = $db->prepare("SELECT license_key FROM licenses WHERE id = ?");
            $stmt->execute([$license_id]);
            $license = $stmt->fetch();
            
            if ($license) {
                $db->prepare("DELETE FROM licenses WHERE id = ?")->execute([$license_id]);
                log_activity('delete', 'license', $license_id, "Deleted license: {$license['license_key']}");
                $_SESSION['success_message'] = 'License berhasil dihapus!';
            }
        } elseif ($action === 'update') {
            require_once __DIR__ . '/../includes/validation.php';
            
            // FIX: Validate all inputs properly
            $status = sanitize_input($_POST['status'] ?? 'inactive');
            if (!validate_status($status)) {
                $_SESSION['error_message'] = 'Invalid status value';
                header('Location: licenses.php');
                exit;
            }
            
            $expires = !empty($_POST['expires']) ? $_POST['expires'] : null;
            if ($expires && !validate_date($expires, 'Y-m-d')) {
                $_SESSION['error_message'] = 'Invalid expiration date format';
                header('Location: licenses.php');
                exit;
            }
            
            $activation_limit = validate_activation_limit($_POST['activation_limit'] ?? 5);
            $customer_name = sanitize_input($_POST['customer_name'] ?? '');
            $customer_name = validate_site_name($customer_name, 255) ?: '';
            
            $customer_email = sanitize_input($_POST['customer_email'] ?? '');
            if (!empty($customer_email)) {
                $validated_email = validate_and_sanitize_email($customer_email);
                if ($validated_email === false) {
                    $_SESSION['error_message'] = 'Invalid email format';
                    header('Location: licenses.php');
                    exit;
                }
                $customer_email = $validated_email;
            }
            
            $notes = sanitize_input($_POST['notes'] ?? '');
            
            $stmt = $db->prepare("
                UPDATE licenses 
                SET status = ?, expires = ?, activation_limit = ?, 
                    customer_name = ?, customer_email = ?, notes = ?
                WHERE id = ?
            ");
            $stmt->execute([$status, $expires, $activation_limit, $customer_name, $customer_email, $notes, $license_id]);
            
            log_activity('update', 'license', $license_id, "Updated license #{$license_id}");
            $_SESSION['success_message'] = 'License berhasil diupdate!';
        } elseif ($action === 'suspend') {
            $db->prepare("UPDATE licenses SET status = 'suspended' WHERE id = ?")->execute([$license_id]);
            log_activity('suspend', 'license', $license_id, "Suspended license #{$license_id}");
            $_SESSION['success_message'] = 'License berhasil di-suspend!';
        } elseif ($action === 'activate') {
            $db->prepare("UPDATE licenses SET status = 'active' WHERE id = ?")->execute([$license_id]);
            log_activity('activate', 'license', $license_id, "Activated license #{$license_id}");
            $_SESSION['success_message'] = 'License berhasil diaktifkan!';
        }
        
        header('Location: licenses.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
}

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$filter_product = $_GET['product'] ?? '';
$search = $_GET['search'] ?? '';

// Build query
try {
    $db = get_db_connection();
    
    $where = [];
    $params = [];
    
    if ($filter_status) {
        $where[] = "l.status = ?";
        $params[] = $filter_status;
    }
    
    if ($filter_product) {
        $where[] = "l.product_id = ?";
        $params[] = $filter_product;
    }
    
    if ($search) {
        $where[] = "(l.license_key LIKE ? OR l.customer_name LIKE ? OR l.customer_email LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get pagination parameters (already validated in get_pagination_params)
    $pagination = get_pagination_params();
    $page = $pagination['page'];
    $per_page = $pagination['per_page'];
    $offset = $pagination['offset'];
    
    // Ensure integers for SQL safety
    $per_page = (int)$per_page;
    $offset = (int)$offset;
    
    // Get total count
    $count_sql = "
        SELECT COUNT(DISTINCT l.id)
        FROM licenses l 
        LEFT JOIN activations a ON l.id = a.license_id AND a.status = 'active'
        LEFT JOIN admins ad ON l.created_by = ad.id
        {$where_sql}
    ";
    $count_stmt = $db->prepare($count_sql);
    $count_stmt->execute($params);
    $total_licenses = $count_stmt->fetchColumn();
    $total_pages = ceil($total_licenses / $per_page);
    
    // Get paginated licenses
    $licenses = $db->prepare("
        SELECT l.*, COUNT(DISTINCT a.id) as activation_count,
               ad.username as created_by_name
        FROM licenses l 
        LEFT JOIN activations a ON l.id = a.license_id AND a.status = 'active'
        LEFT JOIN admins ad ON l.created_by = ad.id
        {$where_sql}
        GROUP BY l.id 
        ORDER BY l.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $per_page;
    $params[] = $offset;
    $licenses->execute($params);
    $licenses = $licenses->fetchAll();
    
    // Get products for filter
    $products = $db->query("SELECT DISTINCT product_id FROM licenses ORDER BY product_id")->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    $licenses = [];
    $products = [];
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
}

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-certificate"></i> Manage Licenses
        </h1>
        <div style="display: flex; gap: 10px; align-items: center;">
            <a href="generate.php" class="btn btn-primary">
                <i class="fas fa-plus"></i> Generate New
            </a>
            <a href="licenses.php?export=csv<?= !empty($filter_status) ? '&status=' . urlencode($filter_status) : '' ?><?= !empty($filter_product) ? '&product=' . urlencode($filter_product) : '' ?><?= !empty($search) ? '&search=' . urlencode($search) : '' ?>" class="btn btn-secondary" id="exportBtn">
                <i class="fas fa-download"></i> Export CSV
            </a>
        </div>
    </div>
    
    <!-- FEATURE: Bulk Operations Bar -->
    <div id="bulkActionsBar" style="display: none; padding: 15px; background: var(--bg); border-bottom: 1px solid var(--border); margin-bottom: 20px;">
        <div style="display: flex; align-items: center; gap: 15px; flex-wrap: wrap;">
            <span id="selectedCount" style="font-weight: 600; color: var(--primary);">0 selected</span>
            <select id="bulkStatusSelect" class="form-control" style="width: 150px;">
                <option value="">Select Status</option>
                <option value="active">Active</option>
                <option value="inactive">Inactive</option>
                <option value="expired">Expired</option>
                <option value="suspended">Suspended</option>
            </select>
            <form method="POST" style="display: inline;" id="bulkStatusForm" onsubmit="return confirm('Update status untuk license yang dipilih?');">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="bulk_update_status">
                <input type="hidden" name="new_status" id="bulkNewStatus">
                <input type="hidden" name="selected_ids" id="bulkSelectedIds">
                <button type="submit" class="btn btn-sm btn-primary" id="bulkUpdateBtn" disabled>
                    <i class="fas fa-sync"></i> Update Status
                </button>
            </form>
            <form method="POST" style="display: inline;" id="bulkDeleteForm" onsubmit="return confirm('Hapus license yang dipilih? Tindakan ini tidak dapat dibatalkan!');">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                <input type="hidden" name="action" value="bulk_delete">
                <input type="hidden" name="selected_ids" id="bulkDeleteIds">
                <button type="submit" class="btn btn-sm btn-danger" id="bulkDeleteBtn" disabled>
                    <i class="fas fa-trash"></i> Delete Selected
                </button>
            </form>
            <button type="button" class="btn btn-sm btn-secondary" onclick="clearSelection()">
                <i class="fas fa-times"></i> Clear Selection
            </button>
        </div>
    </div>
    
    <div style="margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
        <form method="GET" style="display: flex; gap: 10px; flex: 1; min-width: 300px;">
            <input type="text" name="search" class="form-control" placeholder="Search license key, customer..." 
                   value="<?= htmlspecialchars($search) ?>" style="flex: 1;">
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-search"></i> Search
            </button>
        </form>
        
        <select class="form-control" style="width: 150px;" onchange="window.location.href='?status='+this.value+'&product=<?= $filter_product ?>&search=<?= urlencode($search) ?>'">
            <option value="">All Status</option>
            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="inactive" <?= $filter_status === 'inactive' ? 'selected' : '' ?>>Inactive</option>
            <option value="expired" <?= $filter_status === 'expired' ? 'selected' : '' ?>>Expired</option>
            <option value="suspended" <?= $filter_status === 'suspended' ? 'selected' : '' ?>>Suspended</option>
        </select>
        
        <select class="form-control" style="width: 150px;" onchange="window.location.href='?status=<?= $filter_status ?>&product='+this.value+'&search=<?= urlencode($search) ?>'">
            <option value="">All Products</option>
            <?php foreach ($products as $product): ?>
                <option value="<?= htmlspecialchars($product) ?>" <?= $filter_product === $product ? 'selected' : '' ?>>
                    <?= htmlspecialchars($product) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 40px;">
                        <input type="checkbox" id="selectAll" onchange="toggleSelectAll(this)">
                    </th>
                    <th>License Key</th>
                    <th>Product</th>
                    <th>Status</th>
                    <th>Customer</th>
                    <th>Expires</th>
                    <th>Activations</th>
                    <th>Created</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($licenses)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No licenses found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($licenses as $license): ?>
                    <tr>
                        <td>
                            <input type="checkbox" class="license-checkbox" value="<?= $license['id'] ?>" onchange="updateBulkActions()">
                        </td>
                        <td>
                            <?php
                            // SECURITY: Mask license key in UI to prevent exposure via screenshots
                            $license_key = $license['license_key'];
                            $key_length = strlen($license_key);
                            if ($key_length > 16) {
                                $masked = substr($license_key, 0, 8) . '...' . substr($license_key, -8);
                            } else {
                                $masked = substr($license_key, 0, 4) . '...' . substr($license_key, -4);
                            }
                            ?>
                            <code><?= htmlspecialchars($masked) ?></code>
                            <button class="btn btn-sm btn-secondary" data-copy="<?= htmlspecialchars($license_key) ?>"
                                    style="margin-left: 8px; padding: 4px 8px;" title="Copy full license key">
                                <i class="fas fa-copy"></i>
                            </button>
                        </td>
                        <td><?= htmlspecialchars($license['product_id']) ?></td>
                        <td>
                            <span class="badge badge-<?= $license['status'] ?>">
                                <?= ucfirst($license['status']) ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($license['customer_name']): ?>
                                <div><?= htmlspecialchars($license['customer_name']) ?></div>
                                <small style="color: var(--text-muted);"><?= htmlspecialchars($license['customer_email'] ?: '') ?></small>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($license['expires']): ?>
                                <?php 
                                    $days_left = (strtotime($license['expires']) - time()) / 86400;
                                    $status_class = $days_left < 0 ? 'danger' : ($days_left <= 7 ? 'warning' : '');
                                ?>
                                <div><?= format_date($license['expires'], 'Y-m-d') ?></div>
                                <?php if ($days_left >= 0): ?>
                                    <small class="badge badge-<?= $status_class ?>">
                                        <?= floor($days_left) ?> days left
                                    </small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">Never</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?= $license['activation_count'] ?> / <?= $license['activation_limit'] ?: '∞' ?>
                        </td>
                        <td>
                            <div><?= format_date($license['created_at'], 'Y-m-d') ?></div>
                            <small style="color: var(--text-muted);">by <?= htmlspecialchars($license['created_by_name'] ?: 'System') ?></small>
                        </td>
                        <td>
                            <div style="display: flex; gap: 5px;">
                                <button class="btn btn-sm btn-primary" onclick="showEditModal(<?= htmlspecialchars(json_encode($license)) ?>)">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <a href="license-details.php?id=<?= $license['id'] ?>" class="btn btn-sm btn-secondary">
                                    <i class="fas fa-eye"></i>
                                </a>
                                <?php if ($license['status'] === 'active'): ?>
                                    <form method="POST" style="display: inline;" onsubmit="return confirm('Suspend this license?');">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="action" value="suspend">
                                        <input type="hidden" name="license_id" value="<?= $license['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-warning">
                                            <i class="fas fa-ban"></i>
                                        </button>
                                    </form>
                                <?php elseif ($license['status'] === 'suspended'): ?>
                                    <form method="POST" style="display: inline;">
                                        <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                        <input type="hidden" name="action" value="activate">
                                        <input type="hidden" name="license_id" value="<?= $license['id'] ?>">
                                        <button type="submit" class="btn btn-sm btn-success">
                                            <i class="fas fa-check"></i>
                                        </button>
                                    </form>
                                <?php endif; ?>
                                <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this license? This cannot be undone!');">
                                    <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="license_id" value="<?= $license['id'] ?>">
                                    <button type="submit" class="btn btn-sm btn-danger">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if (isset($total_pages) && $total_pages > 1): ?>
        <?= generate_pagination($page, $total_pages, 'licenses.php', [
            'status' => $filter_status,
            'product' => $filter_product,
            'search' => $search
        ]) ?>
        <div style="text-align: center; color: var(--text-muted); margin-top: 10px;">
            <?= get_pagination_info($page, $per_page, $total_licenses) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">
            <h2>Edit License</h2>
            <button class="modal-close">&times;</button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="license_id" id="edit_license_id">
            
            <div class="form-group">
                <label class="form-label">Status</label>
                <select name="status" class="form-control" id="edit_status" required>
                    <option value="active">Active</option>
                    <option value="inactive">Inactive</option>
                    <option value="expired">Expired</option>
                    <option value="suspended">Suspended</option>
                </select>
            </div>

            <div class="form-group">
                <label class="form-label">Expiration Date</label>
                <input type="date" name="expires" class="form-control" id="edit_expires">
                <div class="form-text">Leave empty for no expiration</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Activation Limit</label>
                <input type="number" name="activation_limit" class="form-control" id="edit_activation_limit" min="0" required>
                <div class="form-text">0 = unlimited</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Name</label>
                <input type="text" name="customer_name" class="form-control" id="edit_customer_name">
            </div>
            
            <div class="form-group">
                <label class="form-label">Customer Email</label>
                <input type="email" name="customer_email" class="form-control" id="edit_customer_email">
            </div>
            
            <div class="form-group">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" id="edit_notes" rows="3"></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end; margin-top: 20px;">
                <button type="button" class="btn btn-secondary" onclick="hideModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">Update License</button>
            </div>
        </form>
    </div>
</div>

<script>
function showEditModal(license) {
    document.getElementById('edit_license_id').value = license.id;
    document.getElementById('edit_status').value = license.status;
    document.getElementById('edit_expires').value = license.expires || '';
    document.getElementById('edit_activation_limit').value = license.activation_limit;
    document.getElementById('edit_customer_name').value = license.customer_name || '';
    document.getElementById('edit_customer_email').value = license.customer_email || '';
    document.getElementById('edit_notes').value = license.notes || '';
    showModal('editModal');
}

// FEATURE: Bulk Operations JavaScript
function toggleSelectAll(checkbox) {
    const checkboxes = document.querySelectorAll('.license-checkbox');
    checkboxes.forEach(cb => cb.checked = checkbox.checked);
    updateBulkActions();
}

function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.license-checkbox:checked');
    const selectedIds = Array.from(checkboxes).map(cb => cb.value);
    const count = selectedIds.length;
    
    const bulkBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    const bulkUpdateBtn = document.getElementById('bulkUpdateBtn');
    const bulkDeleteBtn = document.getElementById('bulkDeleteBtn');
    const bulkStatusSelect = document.getElementById('bulkStatusSelect');
    
    if (!bulkBar || !selectedCount || !bulkUpdateBtn || !bulkDeleteBtn) {
        return; // Elements not found
    }
    
    if (count > 0) {
        bulkBar.style.display = 'block';
        selectedCount.textContent = count + ' selected';
        bulkDeleteBtn.disabled = false;
        const bulkDeleteIds = document.getElementById('bulkDeleteIds');
        if (bulkDeleteIds) bulkDeleteIds.value = JSON.stringify(selectedIds);
    } else {
        bulkBar.style.display = 'none';
        bulkUpdateBtn.disabled = true;
        bulkDeleteBtn.disabled = true;
    }
    
    // Update status button
    if (bulkStatusSelect) {
        bulkStatusSelect.onchange = function() {
            if (this.value && count > 0) {
                bulkUpdateBtn.disabled = false;
                const bulkNewStatus = document.getElementById('bulkNewStatus');
                const bulkSelectedIds = document.getElementById('bulkSelectedIds');
                if (bulkNewStatus) bulkNewStatus.value = this.value;
                if (bulkSelectedIds) bulkSelectedIds.value = JSON.stringify(selectedIds);
            } else {
                bulkUpdateBtn.disabled = true;
            }
        };
    }
}

function clearSelection() {
    document.querySelectorAll('.license-checkbox').forEach(cb => cb.checked = false);
    const selectAll = document.getElementById('selectAll');
    if (selectAll) selectAll.checked = false;
    updateBulkActions();
}

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    updateBulkActions();
});
</script>

<?php include '../includes/footer.php'; ?>