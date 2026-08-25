<?php
/**
 * Helper Functions
 */

require_once __DIR__ . '/../config.php';

/**
 * Log activity (async/non-blocking for better performance)
 * 
 * @param string $action Action name
 * @param string|null $entity_type Entity type
 * @param int|null $entity_id Entity ID
 * @param string|null $description Description
 * @return void
 */
function log_activity($action, $entity_type = null, $entity_id = null, $description = null) {
    // Skip logging if disabled
    if (function_exists('get_setting') && !get_setting('enable_activity_logging', true)) {
        return;
    }
    
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            INSERT INTO activity_logs (admin_id, action, entity_type, entity_id, description, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $_SESSION['admin_id'] ?? null,
            $action,
            $entity_type,
            $entity_id,
            $description,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        // Fallback to admin_logs table if activity_logs table does not exist
        try {
            $db = get_db_connection();
            $stmt = $db->prepare("
                INSERT INTO admin_logs (admin_id, action, target_type, target_id, description, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $_SESSION['admin_id'] ?? 0,
                $action,
                $entity_type ?: 'system',
                $entity_id,
                $description,
                $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0',
                $_SERVER['HTTP_USER_AGENT'] ?? ''
            ]);
        } catch (Exception $e2) {
            error_log('Activity log error: ' . $e2->getMessage());
        }
    }
}

/**
 * Log API request
 */
function log_api_request($endpoint, $method, $license_key = null, $site_url = null, $request_data = null, $response_data = null, $status_code = 200) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            INSERT INTO api_logs (endpoint, method, license_key, site_url, request_data, response_data, status_code, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $endpoint,
            $method,
            $license_key,
            $site_url,
            json_encode($request_data),
            json_encode($response_data),
            $status_code,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null
        ]);
    } catch (Exception $e) {
        // Silent fail for logging
    }
}

/**
 * Get setting value (with caching)
 * 
 * @param string $key Setting key
 * @param mixed $default Default value
 * @return mixed Setting value
 */
function get_setting(string $key, $default = null) {
    // Static cache for current request (fastest - no file I/O)
    static $settings_cache = [];
    static $in_progress = []; // Prevent infinite recursion
    
    if (isset($settings_cache[$key])) {
        return $settings_cache[$key];
    }
    
    // If already in progress, return default to break recursion
    if (isset($in_progress[$key])) {
        return $default;
    }
    
    $in_progress[$key] = true;
    
    try {
        // Try file cache (second fastest) - but only if cache.php is already loaded
        if (function_exists('get_setting_cached') && !isset($in_progress["cache_{$key}"])) {
            $in_progress["cache_{$key}"] = true;
            $cached = get_setting_cached($key, null);
            unset($in_progress["cache_{$key}"]);
            
            if ($cached !== null) {
                $settings_cache[$key] = $cached;
                unset($in_progress[$key]);
                return $cached;
            }
        }
        
        // Direct database query
        $db = get_db_connection();
        $stmt = $db->prepare("SELECT setting_value, setting_type FROM settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $setting = $stmt->fetch();
        
        if (!$setting) {
            $settings_cache[$key] = $default;
            unset($in_progress[$key]);
            return $default;
        }
        
        // Convert based on type
        $value = null;
        switch ($setting['setting_type']) {
            case 'integer':
                $value = (int) $setting['setting_value'];
                break;
            case 'boolean':
                $value = (bool) $setting['setting_value'];
                break;
            case 'json':
                $value = json_decode($setting['setting_value'], true);
                break;
            default:
                $value = $setting['setting_value'];
        }
        
        // Store in static cache for this request
        $settings_cache[$key] = $value;
        
        // Cache the result to file for next request (only if cache_set exists and not in recursion)
        if (function_exists('cache_set') && !isset($in_progress["cache_{$key}"])) {
            cache_set("setting_{$key}", $value, 3600);
        }
        
        unset($in_progress[$key]);
        return $value;
    } catch (Exception $e) {
        $settings_cache[$key] = $default;
        unset($in_progress[$key]);
        return $default;
    }
}

/**
 * Update setting (with cache invalidation)
 * 
 * @param string $key Setting key
 * @param mixed $value Setting value
 * @param string $type Setting type
 * @return bool Success
 */
function update_setting(string $key, $value, string $type = 'string'): bool {
    try {
        $db = get_db_connection();
        
        // Convert value based on type
        if ($type === 'json' && is_array($value)) {
            $value = json_encode($value);
        } elseif ($type === 'boolean') {
            $value = $value ? '1' : '0';
        }
        
        $stmt = $db->prepare("
            INSERT INTO settings (setting_key, setting_value, setting_type)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE setting_value = ?, setting_type = ?
        ");
        $stmt->execute([$key, $value, $type, $value, $type]);
        
        // FIX: Invalidate cache properly (always try, handle errors gracefully)
        try {
            if (file_exists(__DIR__ . '/cache.php')) {
                require_once __DIR__ . '/cache.php';
                if (function_exists('invalidate_setting_cache')) {
                    invalidate_setting_cache($key);
                }
            }
        } catch (Exception $cache_error) {
            // Log but don't fail the update
            error_log('Cache invalidation error: ' . $cache_error->getMessage());
        }
        
        return true;
    } catch (Exception $e) {
        error_log('Update setting error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get license statistics
 * PERFORMANCE: Optimized with single query instead of N+1 queries
 */
function get_license_stats() {
    try {
        $db = get_db_connection();
        
        // PERFORMANCE: Single query dengan aggregation instead of multiple queries
        $stmt = $db->query("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
                SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) as inactive,
                SUM(CASE WHEN status = 'expired' THEN 1 ELSE 0 END) as expired,
                SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) as suspended
            FROM licenses
            WHERE deleted_at IS NULL
        ");
        $license_stats = $stmt->fetch();
        
        // Get activation stats (separate query needed for different table)
        $stmt = $db->prepare("SELECT COUNT(*) as activations FROM activations WHERE status = 'active'");
        $stmt->execute();
        $activation_stats = $stmt->fetch();
        
        // Get expiring soon (within 30 days)
        $stmt = $db->prepare("
            SELECT COUNT(*) as expiring_soon
            FROM licenses 
            WHERE expires IS NOT NULL 
            AND expires BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 30 DAY)
            AND status = 'active'
            AND deleted_at IS NULL
        ");
        $stmt->execute();
        $expiring_stats = $stmt->fetch();
        
        return [
            'total' => (int)($license_stats['total'] ?? 0),
            'active' => (int)($license_stats['active'] ?? 0),
            'inactive' => (int)($license_stats['inactive'] ?? 0),
            'expired' => (int)($license_stats['expired'] ?? 0),
            'suspended' => (int)($license_stats['suspended'] ?? 0),
            'activations' => (int)($activation_stats['activations'] ?? 0),
            'expiring_soon' => (int)($expiring_stats['expiring_soon'] ?? 0),
        ];
    } catch (Exception $e) {
        error_log('Get license stats error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get recent activity
 */
function get_recent_activity($limit = 20) {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            SELECT al.*, a.username, a.full_name
            FROM activity_logs al
            LEFT JOIN admins a ON al.admin_id = a.id
            ORDER BY al.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (Exception $e) {
        return [];
    }
}

/**
 * Check if license is expired
 */
function is_license_expired($expires) {
    if (empty($expires)) return false;
    return strtotime($expires) < time();
}

/**
 * Get license expiration status
 */
function get_license_expiration_status($expires) {
    if (empty($expires)) return 'never';
    
    $days_left = (strtotime($expires) - time()) / 86400;
    
    if ($days_left < 0) return 'expired';
    if ($days_left <= 7) return 'critical';
    if ($days_left <= 30) return 'warning';
    return 'ok';
}

