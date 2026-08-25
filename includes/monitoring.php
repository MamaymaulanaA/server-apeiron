<?php
/**
 * Performance Monitoring and Logging
 * 
 * Tracks performance metrics and system health
 */

/**
 * Start performance timer
 * 
 * @param string $operation Operation name
 * @return float Start time
 */
function start_timer(string $operation): float {
    $GLOBALS['_performance_timers'][$operation] = microtime(true);
    return $GLOBALS['_performance_timers'][$operation];
}

/**
 * End performance timer and log
 * 
 * @param string $operation Operation name
 * @param float|null $start_time Start time (if not using start_timer)
 * @return float Elapsed time in milliseconds
 */
function end_timer(string $operation, ?float $start_time = null): float {
    $end_time = microtime(true);
    $start_time = $start_time ?? ($GLOBALS['_performance_timers'][$operation] ?? $end_time);
    $elapsed_ms = ($end_time - $start_time) * 1000;
    
    // Log slow operations
    if ($elapsed_ms > 1000) { // More than 1 second
        error_log("SLOW OPERATION: {$operation} took {$elapsed_ms}ms");
    }
    
    unset($GLOBALS['_performance_timers'][$operation]);
    return $elapsed_ms;
}

/**
 * Track API response time
 * 
 * @param string $endpoint Endpoint name
 * @param float $response_time_ms Response time in milliseconds
 * @return void
 */
function track_api_response_time(string $endpoint, float $response_time_ms): void {
    try {
        $db = get_db_connection();
        
        // Store in performance_logs table (create if not exists)
        $stmt = $db->prepare("
            INSERT INTO performance_logs (endpoint, response_time_ms, created_at)
            VALUES (?, ?, NOW())
        ");
        $stmt->execute([$endpoint, $response_time_ms]);
        
        // Keep only last 1000 records per endpoint
        $table_exists = true; // Table exists if INSERT succeeded
        if ($table_exists) {
            try {
                $db->prepare("
                    DELETE FROM performance_logs 
                    WHERE endpoint = ? 
                    AND id NOT IN (
                        SELECT id FROM (
                            SELECT id FROM performance_logs 
                            WHERE endpoint = ? 
                            ORDER BY created_at DESC 
                            LIMIT 1000
                        ) AS temp
                    )
                ")->execute([$endpoint, $endpoint]);
            } catch (Exception $e) {
                // Ignore cleanup errors
            }
        }
    } catch (Exception $e) {
        // Silent fail - don't break main flow
        error_log('Performance tracking error: ' . $e->getMessage());
    }
}

/**
 * Get average response time for endpoint
 * 
 * @param string $endpoint Endpoint name
 * @param int $hours Hours to look back (default: 24)
 * @return float Average response time in milliseconds
 */
function get_average_response_time(string $endpoint, int $hours = 24): float {
    try {
        $db = get_db_connection();
        
        // Check if table exists
        $table_exists = $db->query("SHOW TABLES LIKE 'performance_logs'")->fetch();
        if (!$table_exists) {
            return 0;
        }
        
        $stmt = $db->prepare("
            SELECT AVG(response_time_ms) as avg_time
            FROM performance_logs
            WHERE endpoint = ?
            AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
        ");
        $stmt->execute([$endpoint, $hours]);
        $result = $stmt->fetch();
        return (float)($result['avg_time'] ?? 0);
    } catch (Exception $e) {
        return 0;
    }
}

/**
 * Get performance statistics
 * 
 * @param string $endpoint Endpoint name (optional)
 * @param int $hours Hours to look back
 * @return array Performance stats
 */
function get_performance_stats(?string $endpoint = null, int $hours = 24): array {
    try {
        $db = get_db_connection();
        
        // Check if table exists
        $table_exists = $db->query("SHOW TABLES LIKE 'performance_logs'")->fetch();
        if (!$table_exists) {
            return [];
        }
        
        $where = "created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)";
        $params = [$hours];
        
        if ($endpoint) {
            $where .= " AND endpoint = ?";
            $params[] = $endpoint;
        }
        
        // Use simpler query (PERCENTILE_CONT not available in all MySQL versions)
        $stmt = $db->prepare("
            SELECT 
                endpoint,
                COUNT(*) as request_count,
                AVG(response_time_ms) as avg_response_time,
                MIN(response_time_ms) as min_response_time,
                MAX(response_time_ms) as max_response_time
            FROM performance_logs
            WHERE {$where}
            GROUP BY endpoint
        ");
        $stmt->execute($params);
        
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Log error with context
 * 
 * @param string $message Error message
 * @param array $context Additional context
 * @param int $level Error level (E_ERROR, E_WARNING, etc.)
 * @return void
 */
function log_error_with_context(string $message, array $context = [], int $level = E_ERROR): void {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'level' => $level,
        'message' => $message,
        'context' => $context,
        'file' => $_SERVER['SCRIPT_NAME'] ?? 'unknown',
        'ip' => get_client_ip(),
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown'
    ];
    
    // Log to file
    $log_file = __DIR__ . '/../logs/error_' . date('Y-m-d') . '.log';
    $log_dir = dirname($log_file);
    if (!is_dir($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    file_put_contents(
        $log_file,
        json_encode($log_entry) . "\n",
        FILE_APPEND | LOCK_EX
    );
    
    // Also log to PHP error log
    error_log($message . ' | Context: ' . json_encode($context));
}

/**
 * Track database query performance
 * 
 * @param string $query Query string (sanitized)
 * @param float $execution_time_ms Execution time in milliseconds
 * @return void
 */
function track_query_performance(string $query, float $execution_time_ms): void {
    // Log slow queries
    if ($execution_time_ms > 500) { // More than 500ms
        error_log("SLOW QUERY ({$execution_time_ms}ms): " . substr($query, 0, 200));
    }
}

/**
 * Get system metrics
 * 
 * @return array System metrics
 */
function get_system_metrics(): array {
    $metrics = [
        'timestamp' => time(),
        'memory_usage' => memory_get_usage(true),
        'memory_peak' => memory_get_peak_usage(true),
        'memory_limit' => ini_get('memory_limit'),
    ];
    
    // CPU usage (if available)
    if (function_exists('sys_getloadavg')) {
        $load = sys_getloadavg();
        $metrics['cpu_load'] = $load[0] ?? null;
    }
    
    // Database connections
    try {
        $db = get_db_connection();
        $stmt = $db->query("SHOW STATUS LIKE 'Threads_connected'");
        $result = $stmt->fetch();
        $metrics['db_connections'] = (int)($result['Value'] ?? 0);
    } catch (Exception $e) {
        $metrics['db_connections'] = null;
    }
    
    return $metrics;
}

