<?php
/**
 * System Monitoring Dashboard
 * 
 * Shows system health, performance metrics, and monitoring data
 */

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/monitoring.php';

require_admin_login();

$page_title = 'System Monitoring';
$show_header = true;

// Get performance stats
$performance_stats = get_performance_stats(null, 24); // Last 24 hours

// Get system metrics
$system_metrics = get_system_metrics();

// Get database health
try {
    $db = get_db_connection();
    $db_status = 'healthy';
    $db_connections = $system_metrics['db_connections'] ?? 0;
    
    // Test query
    $start = microtime(true);
    $db->query('SELECT 1');
    $db_response_time = (microtime(true) - $start) * 1000;
} catch (Exception $e) {
    $db_status = 'error';
    $db_connections = 0;
    $db_response_time = 0;
}

// Get average response times
$avg_activate = get_average_response_time('activate', 24);
$avg_check = get_average_response_time('check', 24);
$avg_deactivate = get_average_response_time('deactivate', 24);

// Get recent errors from logs
$error_log_file = __DIR__ . '/../logs/error_' . date('Y-m-d') . '.log';
$recent_errors = [];
if (file_exists($error_log_file)) {
    $lines = file($error_log_file);
    $recent_errors = array_slice(array_reverse($lines), 0, 10); // Last 10 errors
}

include '../includes/header.php';
?>

<div class="stats-grid">
    <div class="stat-card <?= $db_status === 'healthy' ? 'success' : 'danger' ?>">
        <div class="stat-icon">
            <i class="fas fa-database"></i>
        </div>
        <div class="stat-label">Database Status</div>
        <div class="stat-value"><?= ucfirst($db_status) ?></div>
        <div class="stat-change">
            <i class="fas fa-clock"></i> Response: <?= number_format($db_response_time, 2) ?>ms
        </div>
    </div>
    
    <div class="stat-card info">
        <div class="stat-icon">
            <i class="fas fa-tachometer-alt"></i>
        </div>
        <div class="stat-label">API Avg Response</div>
        <div class="stat-value"><?= number_format(($avg_activate + $avg_check + $avg_deactivate) / 3, 2) ?>ms</div>
        <div class="stat-change">
            <i class="fas fa-chart-line"></i> Last 24 hours
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-memory"></i>
        </div>
        <div class="stat-label">Memory Usage</div>
        <div class="stat-value"><?= number_format($system_metrics['memory_usage'] / 1024 / 1024, 2) ?> MB</div>
        <div class="stat-change">
            <i class="fas fa-chart-bar"></i> Peak: <?= number_format($system_metrics['memory_peak'] / 1024 / 1024, 2) ?> MB
        </div>
    </div>
    
    <div class="stat-card">
        <div class="stat-icon">
            <i class="fas fa-plug"></i>
        </div>
        <div class="stat-label">DB Connections</div>
        <div class="stat-value"><?= $db_connections ?></div>
        <div class="stat-change">
            <i class="fas fa-network-wired"></i> Active connections
        </div>
    </div>
</div>

<div class="dashboard-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
    <!-- Performance Stats -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-chart-line"></i> API Performance (24h)
            </h2>
        </div>
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Endpoint</th>
                        <th>Avg Response</th>
                        <th>Min</th>
                        <th>Max</th>
                        <th>Requests</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($performance_stats)): ?>
                        <tr>
                            <td colspan="5" style="text-align: center; padding: 40px; color: var(--text-muted);">
                                No performance data yet
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($performance_stats as $stat): ?>
                        <tr>
                            <td><code><?= htmlspecialchars($stat['endpoint']) ?></code></td>
                            <td><?= number_format($stat['avg_response_time'], 2) ?>ms</td>
                            <td><?= number_format($stat['min_response_time'], 2) ?>ms</td>
                            <td><?= number_format($stat['max_response_time'], 2) ?>ms</td>
                            <td><?= number_format($stat['request_count']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
    
    <!-- System Metrics -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-server"></i> System Metrics
            </h2>
        </div>
        <div style="padding: 20px;">
            <div style="margin-bottom: 15px;">
                <strong>PHP Version:</strong> <?= phpversion() ?><br>
                <strong>Memory Limit:</strong> <?= ini_get('memory_limit') ?><br>
                <strong>Max Execution Time:</strong> <?= ini_get('max_execution_time') ?>s<br>
                <?php if (isset($system_metrics['cpu_load'])): ?>
                <strong>CPU Load:</strong> <?= number_format($system_metrics['cpu_load'], 2) ?><br>
                <?php endif; ?>
            </div>
            
            <div style="margin-top: 20px;">
                <a href="api/health.php" target="_blank" class="btn btn-secondary">
                    <i class="fas fa-heartbeat"></i> View Health Check
                </a>
            </div>
        </div>
    </div>
</div>

<!-- Recent Errors -->
<?php if (!empty($recent_errors)): ?>
<div class="card">
    <div class="card-header">
        <h2 class="card-title">
            <i class="fas fa-exclamation-triangle"></i> Recent Errors
        </h2>
    </div>
    <div style="padding: 20px;">
        <div style="max-height: 400px; overflow-y: auto;">
            <?php foreach ($recent_errors as $error_line): ?>
                <?php 
                $error_data = json_decode(trim($error_line), true);
                if ($error_data):
                ?>
                <div style="padding: 10px; margin-bottom: 10px; background: #f8d7da; border-left: 4px solid #dc3232; border-radius: 4px;">
                    <div style="font-weight: 600; color: #721c24;">
                        <?= htmlspecialchars($error_data['message'] ?? 'Unknown error') ?>
                    </div>
                    <div style="font-size: 12px; color: #856404; margin-top: 5px;">
                        <i class="fas fa-clock"></i> <?= htmlspecialchars($error_data['timestamp'] ?? '') ?>
                        <?php if (isset($error_data['file'])): ?>
                        | <i class="fas fa-file"></i> <?= htmlspecialchars($error_data['file']) ?>
                        <?php endif; ?>
                    </div>
                    <?php if (!empty($error_data['context'])): ?>
                    <div style="font-size: 11px; color: #666; margin-top: 5px; font-family: monospace;">
                        <?= htmlspecialchars(json_encode($error_data['context'], JSON_PRETTY_PRINT)) ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include '../includes/footer.php'; ?>

