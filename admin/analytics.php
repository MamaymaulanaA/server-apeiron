<?php
/**
 * License Analytics Dashboard
 * 
 * Shows charts and statistics for license usage
 */

require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Analytics';
$show_header = true;

// Get date range
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');

try {
    $db = get_db_connection();
    
    // License status distribution
    $status_dist = $db->query("
        SELECT status, COUNT(*) as count 
        FROM licenses 
        GROUP BY status
    ")->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // Licenses created over time (last 30 days)
    $created_over_time = $db->prepare("
        SELECT DATE(created_at) as date, COUNT(*) as count
        FROM licenses
        WHERE created_at >= ? AND created_at <= ?
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $created_over_time->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
    $created_data = $created_over_time->fetchAll();
    
    // Activations over time
    $activations_over_time = $db->prepare("
        SELECT DATE(activated_at) as date, COUNT(*) as count
        FROM activations
        WHERE activated_at >= ? AND activated_at <= ?
        GROUP BY DATE(activated_at)
        ORDER BY date ASC
    ");
    $activations_over_time->execute([$date_from . ' 00:00:00', $date_to . ' 23:59:59']);
    $activation_data = $activations_over_time->fetchAll();
    
    // Top products
    $top_products = $db->query("
        SELECT product_id, COUNT(*) as count
        FROM licenses
        GROUP BY product_id
        ORDER BY count DESC
        LIMIT 10
    ")->fetchAll();
    
    // Expiration timeline
    $expiration_timeline = $db->query("
        SELECT 
            CASE 
                WHEN expires IS NULL THEN 'Never'
                WHEN expires < CURDATE() THEN 'Expired'
                WHEN expires BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY) THEN 'Expires in 7 days'
                WHEN expires BETWEEN DATE_ADD(CURDATE(), INTERVAL 7 DAY) AND DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expires in 30 days'
                WHEN expires > DATE_ADD(CURDATE(), INTERVAL 30 DAY) THEN 'Expires later'
            END as period,
            COUNT(*) as count
        FROM licenses
        WHERE status = 'active'
        GROUP BY period
    ")->fetchAll();
    
} catch (Exception $e) {
    $status_dist = [];
    $created_data = [];
    $activation_data = [];
    $top_products = [];
    $expiration_timeline = [];
    $_SESSION['error_message'] = 'Error loading analytics: ' . $e->getMessage();
}

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-chart-line"></i> License Analytics
        </h1>
        <form method="GET" style="display: flex; gap: 10px; align-items: center;">
            <input type="date" name="date_from" value="<?= htmlspecialchars($date_from) ?>" class="form-control">
            <span>to</span>
            <input type="date" name="date_to" value="<?= htmlspecialchars($date_to) ?>" class="form-control">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-filter"></i> Filter
            </button>
        </form>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
        <!-- Status Distribution Chart -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-pie-chart"></i> License Status Distribution
                </h2>
            </div>
            <div style="padding: 20px;">
                <canvas id="statusChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
        
        <!-- Top Products -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-box"></i> Top Products
                </h2>
            </div>
            <div style="padding: 20px;">
                <canvas id="productsChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>
    
    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 24px; margin-bottom: 24px;">
        <!-- Licenses Created Over Time -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-chart-area"></i> Licenses Created Over Time
                </h2>
            </div>
            <div style="padding: 20px;">
                <canvas id="createdChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
        
        <!-- Activations Over Time -->
        <div class="card">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-chart-line"></i> Activations Over Time
                </h2>
            </div>
            <div style="padding: 20px;">
                <canvas id="activationsChart" style="max-height: 300px;"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Expiration Timeline -->
    <div class="card">
        <div class="card-header">
            <h2 class="card-title">
                <i class="fas fa-calendar-alt"></i> Expiration Timeline
            </h2>
        </div>
        <div style="padding: 20px;">
            <canvas id="expirationChart" style="max-height: 300px;"></canvas>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
<script>
// Status Distribution Pie Chart
const statusCtx = document.getElementById('statusChart').getContext('2d');
const statusData = <?= json_encode($status_dist) ?>;
new Chart(statusCtx, {
    type: 'pie',
    data: {
        labels: Object.keys(statusData),
        datasets: [{
            data: Object.values(statusData),
            backgroundColor: [
                '#46b450', // active - green
                '#dc3232', // inactive - red
                '#f0b849', // expired - yellow
                '#2271b1'  // suspended - blue
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true
    }
});

// Top Products Bar Chart
const productsCtx = document.getElementById('productsChart').getContext('2d');
const productsData = <?= json_encode($top_products) ?>;
new Chart(productsCtx, {
    type: 'bar',
    data: {
        labels: productsData.map(p => p.product_id),
        datasets: [{
            label: 'Licenses',
            data: productsData.map(p => p.count),
            backgroundColor: '#2271b1'
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Licenses Created Over Time
const createdCtx = document.getElementById('createdChart').getContext('2d');
const createdData = <?= json_encode($created_data) ?>;
new Chart(createdCtx, {
    type: 'line',
    data: {
        labels: createdData.map(d => d.date),
        datasets: [{
            label: 'Licenses Created',
            data: createdData.map(d => d.count),
            borderColor: '#2271b1',
            backgroundColor: 'rgba(34, 113, 177, 0.1)',
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Activations Over Time
const activationsCtx = document.getElementById('activationsChart').getContext('2d');
const activationData = <?= json_encode($activation_data) ?>;
new Chart(activationsCtx, {
    type: 'line',
    data: {
        labels: activationData.map(d => d.date),
        datasets: [{
            label: 'Activations',
            data: activationData.map(d => d.count),
            borderColor: '#46b450',
            backgroundColor: 'rgba(70, 180, 80, 0.1)',
            fill: true
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        scales: {
            y: { beginAtZero: true }
        }
    }
});

// Expiration Timeline
const expirationCtx = document.getElementById('expirationChart').getContext('2d');
const expirationData = <?= json_encode($expiration_timeline) ?>;
new Chart(expirationCtx, {
    type: 'doughnut',
    data: {
        labels: expirationData.map(e => e.period),
        datasets: [{
            data: expirationData.map(e => e.count),
            backgroundColor: [
                '#dc3232', // Expired
                '#f0b849', // Expires in 7 days
                '#ffa500', // Expires in 30 days
                '#46b450', // Expires later
                '#646970'  // Never
            ]
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true
    }
});
</script>

<?php include '../includes/footer.php'; ?>

