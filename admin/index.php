<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Dashboard';
$show_header = true;

// Get statistics
$stats = get_license_stats();
$recent_activity = get_recent_activity(10);

// Get recent licenses
// FIX: Optimize query to avoid N+1 problem - use proper JOIN
try {
    $db = get_db_connection();
    $recent_licenses = $db->query("
        SELECT l.*, 
               COUNT(DISTINCT a.id) as activation_count, 
               ad.username as created_by_name
        FROM licenses l 
        LEFT JOIN activations a ON l.id = a.license_id AND a.status = 'active'
        LEFT JOIN admins ad ON l.created_by = ad.id
        GROUP BY l.id 
        ORDER BY l.created_at DESC 
        LIMIT 5
    ")->fetchAll();
    
    // Get expiring soon
    $expiring_soon = $db->query("
        SELECT * FROM licenses 
        WHERE expires IS NOT NULL 
        AND expires BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) 
        AND status = 'active'
        ORDER BY expires ASC
        LIMIT 5
    ")->fetchAll();
} catch (Exception $e) {
    $recent_licenses = [];
    $expiring_soon = [];
}

include '../includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-certificate"></i>
        </div>
        <div class="stat-label">
            <i class="fas fa-layer-group"></i> Total Licenses
        </div>
        <div class="stat-value"><?= number_format($stats['total']) ?></div>
        <div class="stat-change">
            <i class="fas fa-clock"></i> All time
        </div>
    </div>
    
    <div class="stat-card success">
        <div class="stat-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        <div class="stat-label">
            <i class="fas fa-toggle-on"></i> Active
        </div>
        <div class="stat-value"><?= number_format($stats['active']) ?></div>
        <div class="stat-change">
            <i class="fas fa-chart-line"></i> Currently active
        </div>
    </div>
    
    <div class="stat-card danger">
        <div class="stat-icon">
            <i class="fas fa-times-circle"></i>
        </div>
        <div class="stat-label">
            <i class="fas fa-toggle-off"></i> Inactive
        </div>
        <div class="stat-value"><?= number_format($stats['inactive']) ?></div>
        <div class="stat-change">
            <i class="fas fa-exclamation-circle"></i> Not activated
        </div>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-icon">
            <i class="fas fa-calendar-times"></i>
        </div>
        <div class="stat-label">
            <i class="fas fa-hourglass-end"></i> Expired
        </div>
        <div class="stat-value"><?= number_format($stats['expired']) ?></div>
        <div class="stat-change">
            <i class="fas fa-ban"></i> Expired licenses
        </div>
    </div>
    
    <div class="stat-card info">
        <div class="stat-icon">
            <i class="fas fa-link"></i>
        </div>
        <div class="stat-label">
            <i class="fas fa-network-wired"></i> Total Activations
        </div>
        <div class="stat-value"><?= number_format($stats['activations']) ?></div>
        <div class="stat-change">
            <i class="fas fa-globe"></i> Active sites
        </div>
    </div>
    
    <div class="stat-card warning">
        <div class="stat-icon">
            <i class="fas fa-exclamation-triangle"></i>
        </div>
        <div class="stat-label">
            <i class="fas fa-calendar-alt"></i> Expiring Soon
        </div>
        <div class="stat-value"><?= number_format($stats['expiring_soon']) ?></div>
        <div class="stat-change">
            <i class="fas fa-calendar-check"></i> Next 30 days
        </div>
    </div>
</div>

<div class="dashboard-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-clock"></i> Recent Licenses
            </h2>
            <a href="licenses.php" class="btn btn-sm btn-secondary">View All</a>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>License Key</th>
                        <th>Status</th>
                        <th>Activations</th>
                        <th>Created</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($recent_licenses)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <i class="fas fa-inbox" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px; display: block;"></i>
                                <div style="font-size: 14px;">No licenses yet</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($recent_licenses as $license): ?>
                        <tr>
                            <td>
                                <?php
                                // SECURITY: Mask license key in UI
                                $license_key = $license['license_key'];
                                $key_length = strlen($license_key);
                                if ($key_length > 16) {
                                    $masked = substr($license_key, 0, 8) . '...' . substr($license_key, -8);
                                } else {
                                    $masked = substr($license_key, 0, 4) . '...' . substr($license_key, -4);
                                }
                                ?>
                                <i class="fas fa-key" style="color: var(--primary); margin-right: 6px;"></i>
                                <code><?= htmlspecialchars($masked) ?></code>
                            </td>
                            <td>
                                <span class="badge badge-<?= $license['status'] ?>">
                                    <i class="fas fa-<?= 
                                        $license['status'] === 'active' ? 'check-circle' : 
                                        ($license['status'] === 'inactive' ? 'times-circle' : 
                                        ($license['status'] === 'expired' ? 'calendar-times' : 'ban')) 
                                    ?>"></i>
                                    <?= ucfirst($license['status']) ?>
                                </span>
                            </td>
                            <td>
                                <i class="fas fa-link" style="color: var(--text-muted); margin-right: 6px;"></i>
                                <?= $license['activation_count'] ?> / <?= $license['activation_limit'] ?: '∞' ?>
                            </td>
                            <td>
                                <i class="fas fa-calendar" style="color: var(--text-muted); margin-right: 6px;"></i>
                                <?= time_ago($license['created_at']) ?>
                            </td>
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
                <i class="fas fa-exclamation-triangle"></i> Expiring Soon
            </h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>License Key</th>
                        <th>Expires</th>
                        <th>Days Left</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($expiring_soon)): ?>
                        <tr>
                            <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                <i class="fas fa-check-circle" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px; display: block; color: var(--success);"></i>
                                <div style="font-size: 14px;">No licenses expiring soon</div>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($expiring_soon as $license): ?>
                        <?php 
                            $days_left = (strtotime($license['expires']) - time()) / 86400;
                            $status_class = $days_left <= 7 ? 'danger' : 'warning';
                        ?>
                        <tr>
                            <td>
                                <?php
                                // SECURITY: Mask license key in UI
                                $license_key = $license['license_key'];
                                $key_length = strlen($license_key);
                                if ($key_length > 16) {
                                    $masked = substr($license_key, 0, 8) . '...' . substr($license_key, -8);
                                } else {
                                    $masked = substr($license_key, 0, 4) . '...' . substr($license_key, -4);
                                }
                                ?>
                                <i class="fas fa-key" style="color: var(--primary); margin-right: 6px;"></i>
                                <code><?= htmlspecialchars($masked) ?></code>
                            </td>
                            <td>
                                <i class="fas fa-calendar-alt" style="color: var(--text-muted); margin-right: 6px;"></i>
                                <?= format_date($license['expires'], 'Y-m-d') ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $status_class ?>">
                                    <i class="fas fa-hourglass-half"></i>
                                    <?= floor($days_left) ?> days
                                </span>
                            </td>
                            <td>
                                <span class="badge badge-active">
                                    <i class="fas fa-check-circle"></i> Active
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fas fa-history"></i> Recent Activity
        </h2>
        <a href="logs.php" class="btn btn-sm btn-secondary">View All Logs</a>
    </div>
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Description</th>
                    <th>User</th>
                    <th>Time</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_activity)): ?>
                    <tr>
                        <td colspan="4" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-history" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px; display: block;"></i>
                            <div style="font-size: 14px;">No activity yet</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_activity as $activity): ?>
                        <tr>
                            <td>
                                <span class="badge badge-info">
                                    <i class="fas fa-<?= 
                                        $activity['action'] === 'create' ? 'plus-circle' : 
                                        ($activity['action'] === 'update' ? 'edit' : 
                                        ($activity['action'] === 'delete' ? 'trash' : 
                                        ($activity['action'] === 'login' ? 'sign-in-alt' : 
                                        ($activity['action'] === 'logout' ? 'sign-out-alt' : 'circle')))) 
                                    ?>"></i>
                                    <?= htmlspecialchars($activity['action']) ?>
                                </span>
                            </td>
                            <td>
                                <i class="fas fa-info-circle" style="color: var(--text-muted); margin-right: 6px;"></i>
                                <?= htmlspecialchars($activity['description'] ?: '-') ?>
                            </td>
                            <td>
                                <i class="fas fa-user" style="color: var(--text-muted); margin-right: 6px;"></i>
                                <?= htmlspecialchars($activity['username'] ?: 'System') ?>
                            </td>
                            <td>
                                <i class="fas fa-clock" style="color: var(--text-muted); margin-right: 6px;"></i>
                                <?= time_ago($activity['created_at']) ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include '../includes/footer.php'; ?>

