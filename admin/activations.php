<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Activations';
$show_header = true;

// Get filter parameters
$filter_status = $_GET['status'] ?? '';
$search = $_GET['search'] ?? '';

try {
    $db = get_db_connection();
    
    $where = [];
    $params = [];
    
    if ($filter_status) {
        $where[] = "a.status = ?";
        $params[] = $filter_status;
    }
    
    if ($search) {
        $where[] = "(a.site_url LIKE ? OR l.license_key LIKE ?)";
        $search_term = "%{$search}%";
        $params[] = $search_term;
        $params[] = $search_term;
    }
    
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    $activations = $db->prepare("
        SELECT a.*, l.license_key, l.product_id, l.status as license_status
        FROM activations a
        LEFT JOIN licenses l ON a.license_id = l.id
        {$where_sql}
        ORDER BY a.activated_at DESC
    ");
    $activations->execute($params);
    $activations = $activations->fetchAll();
    
} catch (Exception $e) {
    $activations = [];
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
}

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-link"></i> Activations (<?= count($activations) ?>)
        </h1>
    </div>
    
    <div style="margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
        <form method="GET" style="display: flex; gap: 10px; flex: 1; min-width: 300px;">
            <input type="text" name="search" class="form-control" placeholder="Search site URL or license key..." 
                   value="<?= htmlspecialchars($search) ?>">
            <button type="submit" class="btn btn-secondary">
                <i class="fas fa-search"></i> Search
            </button>
        </form>
        
        <select class="form-control" style="width: 150px;" 
                onchange="window.location.href='?status='+this.value+'&search=<?= urlencode($search) ?>'">
            <option value="">All Status</option>
            <option value="active" <?= $filter_status === 'active' ? 'selected' : '' ?>>Active</option>
            <option value="deactivated" <?= $filter_status === 'deactivated' ? 'selected' : '' ?>>Deactivated</option>
        </select>
    </div>
    
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>License Key</th>
                    <th>Site URL</th>
                    <th>Site Name</th>
                    <th>IP Address</th>
                    <th>License Status</th>
                    <th>Activation Status</th>
                    <th>Activated</th>
                    <th>Last Check</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($activations)): ?>
                    <tr>
                        <td colspan="8" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            No activations found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($activations as $activation): ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($activation['license_key']) ?></code>
                            <br><small style="color: var(--text-muted);"><?= htmlspecialchars($activation['product_id']) ?></small>
                        </td>
                        <td><code><?= htmlspecialchars($activation['site_url']) ?></code></td>
                        <td><?= htmlspecialchars($activation['site_name'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($activation['ip_address'] ?: '-') ?></td>
                        <td>
                            <span class="badge badge-<?= $activation['license_status'] ?>">
                                <?= ucfirst($activation['license_status']) ?>
                            </span>
                        </td>
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

<?php include '../includes/footer.php'; ?>

