<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/pagination.php';

require_admin_login();

$page_title = 'Activity Logs';
$show_header = true;

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_log'])) {
    $log_id = intval($_POST['log_id'] ?? 0);
    
    if ($log_id > 0) {
        try {
            $db = get_db_connection();
            $stmt = $db->prepare("DELETE FROM activity_logs WHERE id = ?");
            $stmt->execute([$log_id]);
            
            log_activity('delete', 'activity_log', $log_id, "Deleted activity log #{$log_id}");
            $_SESSION['success_message'] = 'Activity log berhasil dihapus!';
            header('Location: logs.php' . (!empty($_GET) ? '?' . http_build_query($_GET) : ''));
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        }
    }
}

// Handle delete all action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_logs'])) {
    try {
        $db = get_db_connection();
        
        // Get filter parameters for delete all
        $filter_action = $_POST['filter_action'] ?? '';
        $filter_type = $_POST['filter_type'] ?? '';
        
        $where = [];
        $params = [];
        
        if ($filter_action) {
            $where[] = "action = ?";
            $params[] = $filter_action;
        }
        
        if ($filter_type) {
            $where[] = "entity_type = ?";
            $params[] = $filter_type;
        }
        
        $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        
        // Count logs to be deleted
        $count_stmt = $db->prepare("SELECT COUNT(*) FROM activity_logs {$where_sql}");
        $count_stmt->execute($params);
        $count = $count_stmt->fetchColumn();
        
        // Delete logs
        $delete_stmt = $db->prepare("DELETE FROM activity_logs {$where_sql}");
        $delete_stmt->execute($params);
        $deleted_count = $delete_stmt->rowCount();
        
        log_activity('delete_all', 'activity_log', null, "Deleted {$deleted_count} activity logs" . ($where_sql ? " (filtered)" : " (all)"));
        $_SESSION['success_message'] = "Berhasil menghapus {$deleted_count} activity log!";
        header('Location: logs.php');
        exit;
    } catch (Exception $e) {
        $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
    }
}

// Get filter parameters
$filter_action = $_GET['action'] ?? '';
$filter_type = $_GET['type'] ?? '';

try {
    $db = get_db_connection();
    
    $where = [];
    $params = [];
    
    if ($filter_action) {
        $where[] = "al.action = ?";
        $params[] = $filter_action;
    }
    
    if ($filter_type) {
        $where[] = "al.entity_type = ?";
        $params[] = $filter_type;
    }
    
    $where_sql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
    
    // Get pagination parameters
    $pagination = get_pagination_params();
    $page = $pagination['page'];
    $per_page = $pagination['per_page'];
    $offset = $pagination['offset'];
    
    // Get total count
    $count_stmt = $db->prepare("
        SELECT COUNT(*) 
        FROM activity_logs al
        LEFT JOIN admins ad ON al.admin_id = ad.id
        {$where_sql}
    ");
    $count_stmt->execute($params);
    $total_logs = $count_stmt->fetchColumn();
    $total_pages = ceil($total_logs / $per_page);
    
    // Get paginated logs
    $logs = $db->prepare("
        SELECT al.*, ad.username, ad.full_name
        FROM activity_logs al
        LEFT JOIN admins ad ON al.admin_id = ad.id
        {$where_sql}
        ORDER BY al.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $params[] = $per_page;
    $params[] = $offset;
    $logs->execute($params);
    $logs = $logs->fetchAll();
    
    // Get unique actions and types for filter
    $actions = $db->query("SELECT DISTINCT action FROM activity_logs ORDER BY action")->fetchAll(PDO::FETCH_COLUMN);
    $types = $db->query("SELECT DISTINCT entity_type FROM activity_logs WHERE entity_type IS NOT NULL ORDER BY entity_type")->fetchAll(PDO::FETCH_COLUMN);
    
    // Count total logs (for delete all confirmation)
    $count_where = [];
    $count_params = [];
    if ($filter_action) {
        $count_where[] = "action = ?";
        $count_params[] = $filter_action;
    }
    if ($filter_type) {
        $count_where[] = "entity_type = ?";
        $count_params[] = $filter_type;
    }
    $count_where_sql = !empty($count_where) ? 'WHERE ' . implode(' AND ', $count_where) : '';
    $total_count_stmt = $db->prepare("SELECT COUNT(*) FROM activity_logs {$count_where_sql}");
    $total_count_stmt->execute($count_params);
    $total_logs_count = $total_count_stmt->fetchColumn();
    
} catch (Exception $e) {
    $logs = [];
    $actions = [];
    $types = [];
    $total_logs_count = 0;
    $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
}

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-history"></i> Activity Logs
            <?php if ($total_logs_count > 0): ?>
                <span style="font-size: 14px; font-weight: normal; color: var(--text-muted); margin-left: 10px;">
                    (<?= number_format($total_logs_count) ?> logs)
                </span>
            <?php endif; ?>
        </h1>
        <?php if ($total_logs_count > 0): ?>
            <button type="button" class="btn btn-danger" onclick="confirmDeleteAll(<?= $total_logs_count ?>, '<?= htmlspecialchars($filter_action, ENT_QUOTES) ?>', '<?= htmlspecialchars($filter_type, ENT_QUOTES) ?>')">
                <i class="fas fa-trash-alt"></i> Delete All
            </button>
        <?php endif; ?>
    </div>
    
    <div style="margin-bottom: 20px; display: flex; gap: 15px; flex-wrap: wrap; align-items: center;">
        <select class="form-control" style="width: 200px;" 
                onchange="window.location.href='?action='+this.value+'&type=<?= $filter_type ?>'">
            <option value="">All Actions</option>
            <?php foreach ($actions as $action): ?>
                <option value="<?= htmlspecialchars($action) ?>" <?= $filter_action === $action ? 'selected' : '' ?>>
                    <?= ucfirst(htmlspecialchars($action)) ?>
                </option>
            <?php endforeach; ?>
        </select>
        
        <select class="form-control" style="width: 200px;" 
                onchange="window.location.href='?action=<?= $filter_action ?>&type='+this.value">
            <option value="">All Types</option>
            <?php foreach ($types as $type): ?>
                <option value="<?= htmlspecialchars($type) ?>" <?= $filter_type === $type ? 'selected' : '' ?>>
                    <?= ucfirst(htmlspecialchars($type)) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
    
    <div class="table-wrapper">
        <table class="table">
            <thead>
                <tr>
                    <th>Action</th>
                    <th>Entity</th>
                    <th>Description</th>
                    <th>User</th>
                    <th>IP Address</th>
                    <th>Time</th>
                    <th><i class="fas fa-cog"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="7" style="text-align: center; padding: 40px; color: var(--text-muted);">
                            <i class="fas fa-history" style="font-size: 48px; opacity: 0.3; margin-bottom: 15px; display: block;"></i>
                            <div style="font-size: 14px;">No logs found</div>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                    <tr>
                        <td>
                            <span class="badge badge-info"><?= htmlspecialchars($log['action']) ?></span>
                        </td>
                        <td>
                            <?php if ($log['entity_type']): ?>
                                <span class="badge badge-secondary"><?= htmlspecialchars($log['entity_type']) ?></span>
                                <?php if ($log['entity_id']): ?>
                                    <small style="color: var(--text-muted);">#<?= $log['entity_id'] ?></small>
                                <?php endif; ?>
                            <?php else: ?>
                                <span style="color: var(--text-muted);">-</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($log['description'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($log['username'] ?: ($log['full_name'] ?: 'System')) ?></td>
                        <td><?= htmlspecialchars($log['ip_address'] ?: '-') ?></td>
                        <td>
                            <div><?= format_date($log['created_at']) ?></div>
                            <small style="color: var(--text-muted);"><?= time_ago($log['created_at']) ?></small>
                        </td>
                        <td>
                            <button type="button" class="btn btn-sm btn-danger" 
                                    onclick="confirmDeleteLog(<?= $log['id'] ?>, '<?= htmlspecialchars($log['action'], ENT_QUOTES) ?>')">
                                <i class="fas fa-trash"></i> Delete
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <?php if (isset($total_pages) && $total_pages > 1): ?>
        <?= generate_pagination($page, $total_pages, 'logs.php', [
            'action' => $filter_action,
            'type' => $filter_type
        ]) ?>
        <div style="text-align: center; color: var(--text-muted); margin-top: 10px;">
            <?= get_pagination_info($page, $per_page, $total_logs) ?>
        </div>
    <?php endif; ?>
</div>

<!-- Delete Confirmation Form -->
<form id="deleteLogForm" method="POST" style="display: none;">
    <input type="hidden" name="delete_log" value="1">
    <input type="hidden" name="log_id" id="delete_log_id">
</form>

<!-- Delete All Confirmation Form -->
<form id="deleteAllLogsForm" method="POST" style="display: none;">
    <input type="hidden" name="delete_all_logs" value="1">
    <input type="hidden" name="filter_action" id="delete_all_filter_action" value="<?= htmlspecialchars($filter_action) ?>">
    <input type="hidden" name="filter_type" id="delete_all_filter_type" value="<?= htmlspecialchars($filter_type) ?>">
</form>

<script>
function confirmDeleteLog(logId, action) {
    if (confirm(`Apakah Anda yakin ingin menghapus activity log "${action}" (ID: ${logId})?`)) {
        document.getElementById('delete_log_id').value = logId;
        document.getElementById('deleteLogForm').submit();
    }
}

function confirmDeleteAll(count, filterAction, filterType) {
    let message = `PERINGATAN: Anda akan menghapus ${count} activity log`;
    
    if (filterAction || filterType) {
        message += ' yang sesuai dengan filter saat ini';
        if (filterAction) message += ` (Action: ${filterAction})`;
        if (filterType) message += ` (Type: ${filterType})`;
    } else {
        message += ' SEMUA';
    }
    
    message += '.\n\nTindakan ini TIDAK DAPAT DIBATALKAN!\n\nApakah Anda yakin ingin melanjutkan?';
    
    if (confirm(message)) {
        // Double confirmation for delete all
        if (confirm('Konfirmasi terakhir: Hapus semua activity log yang dipilih?')) {
            document.getElementById('deleteAllLogsForm').submit();
        }
    }
}
</script>

<?php include '../includes/footer.php'; ?>

