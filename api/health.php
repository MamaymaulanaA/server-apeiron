<?php
/**
 * Health Check Endpoint
 * 
 * Returns system health status for monitoring
 * Endpoint: GET /api/health.php
 */

header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('Y-m-d H:i:s'),
    'version' => defined('APP_VERSION') ? APP_VERSION : '2.0.0',
    'checks' => []
];

// Check database connection
try {
    require_once __DIR__ . '/../config.php';
    $db = get_db_connection();
    $db->query('SELECT 1');
    $health['checks']['database'] = [
        'status' => 'ok',
        'response_time_ms' => 0
    ];
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['database'] = [
        'status' => 'error',
        'error' => 'Database connection failed'
    ];
}

// Check cache directory
$cache_dir = __DIR__ . '/../cache';
if (is_dir($cache_dir) && is_writable($cache_dir)) {
    $health['checks']['cache'] = [
        'status' => 'ok',
        'writable' => true
    ];
} else {
    $health['checks']['cache'] = [
        'status' => 'warning',
        'writable' => false
    ];
}

// Check rate limit directory
$rate_limit_dir = __DIR__ . '/../cache/rate_limit';
if (is_dir($rate_limit_dir) || mkdir($rate_limit_dir, 0755, true)) {
    $health['checks']['rate_limit'] = [
        'status' => 'ok',
        'writable' => is_writable($rate_limit_dir)
    ];
} else {
    $health['checks']['rate_limit'] = [
        'status' => 'warning',
        'writable' => false
    ];
}

// Check PHP version
$php_version = phpversion();
$health['checks']['php'] = [
    'status' => version_compare($php_version, '7.4', '>=') ? 'ok' : 'warning',
    'version' => $php_version
];

// Check required extensions
$required_extensions = ['pdo', 'pdo_mysql', 'openssl', 'json'];
$missing_extensions = [];
foreach ($required_extensions as $ext) {
    if (!extension_loaded($ext)) {
        $missing_extensions[] = $ext;
    }
}

if (empty($missing_extensions)) {
    $health['checks']['extensions'] = [
        'status' => 'ok',
        'loaded' => $required_extensions
    ];
} else {
    $health['status'] = 'unhealthy';
    $health['checks']['extensions'] = [
        'status' => 'error',
        'missing' => $missing_extensions
    ];
}

// Check disk space (if function available)
if (function_exists('disk_free_space')) {
    $free_space = disk_free_space(__DIR__);
    $total_space = disk_total_space(__DIR__);
    $free_percent = ($free_space / $total_space) * 100;
    
    $health['checks']['disk'] = [
        'status' => $free_percent > 10 ? 'ok' : 'warning',
        'free_percent' => round($free_percent, 2),
        'free_space_mb' => round($free_space / 1024 / 1024, 2)
    ];
}

// Determine overall status
$has_errors = false;
foreach ($health['checks'] as $check) {
    if (isset($check['status']) && $check['status'] === 'error') {
        $has_errors = true;
        break;
    }
}

if ($has_errors) {
    $health['status'] = 'unhealthy';
    http_response_code(503);
} elseif ($health['status'] !== 'unhealthy') {
    $health['status'] = 'healthy';
    http_response_code(200);
}

echo json_encode($health, JSON_PRETTY_PRINT);

