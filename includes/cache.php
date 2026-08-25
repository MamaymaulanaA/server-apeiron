<?php
/**
 * Caching Utilities
 * 
 * Provides simple file-based caching (can be upgraded to Redis/Memcached)
 * 
 * NOTE: This file does NOT require config.php to avoid circular dependencies.
 * Functions that need database connection will check if get_db_connection() exists.
 */

/**
 * Get cached value
 * 
 * @param string $key Cache key
 * @param mixed $default Default value if not found
 * @return mixed Cached value or default
 */
function cache_get(string $key, $default = null) {
    $cache_file = get_cache_file($key);
    
    if (!file_exists($cache_file)) {
        return $default;
    }
    
    $data = json_decode(file_get_contents($cache_file), true);
    
    if ($data === null) {
        return $default;
    }
    
    // Check expiration
    if (isset($data['expires']) && $data['expires'] < time()) {
        @unlink($cache_file);
        return $default;
    }
    
    return $data['value'] ?? $default;
}

/**
 * Set cached value
 * 
 * @param string $key Cache key
 * @param mixed $value Value to cache
 * @param int $ttl Time to live in seconds (default: 3600 = 1 hour)
 * @return bool Success
 */
function cache_set(string $key, $value, int $ttl = 3600): bool {
    $cache_dir = __DIR__ . '/../cache';
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    
    $cache_file = get_cache_file($key);
    
    $data = [
        'value' => $value,
        'expires' => time() + $ttl,
        'created' => time()
    ];
    
    return file_put_contents($cache_file, json_encode($data), LOCK_EX) !== false;
}

/**
 * Delete cached value
 * 
 * @param string $key Cache key
 * @return bool Success
 */
function cache_delete(string $key): bool {
    $cache_file = get_cache_file($key);
    if (file_exists($cache_file)) {
        return @unlink($cache_file);
    }
    return true;
}

/**
 * Clear all cache
 * 
 * @return bool Success
 */
function cache_clear(): bool {
    $cache_dir = __DIR__ . '/../cache';
    if (!is_dir($cache_dir)) {
        return true;
    }
    
    $files = glob($cache_dir . '/*.json');
    foreach ($files as $file) {
        @unlink($file);
    }
    
    return true;
}

/**
 * Get cache file path
 * 
 * @param string $key Cache key
 * @return string File path
 */
function get_cache_file(string $key): string {
    $cache_dir = __DIR__ . '/../cache';
    return $cache_dir . '/' . md5($key) . '.json';
}

/**
 * Get cached setting with fallback
 * 
 * @param string $key Setting key
 * @param mixed $default Default value
 * @return mixed Setting value
 */
function get_setting_cached(string $key, $default = null) {
    static $in_progress = []; // Prevent infinite recursion
    
    // If already in progress, return default to break recursion
    if (isset($in_progress[$key])) {
        return $default;
    }
    
    $in_progress[$key] = true;
    
    try {
        $cache_key = "setting_{$key}";
        $cached = cache_get($cache_key);
        
        if ($cached !== null) {
            unset($in_progress[$key]);
            return $cached;
        }
        
        // Direct database query to avoid recursion
        // Only if get_db_connection() is available (config.php already loaded)
        if (!function_exists('get_db_connection')) {
            unset($in_progress[$key]);
            return $default;
        }
        
        try {
            $db = get_db_connection();
            if (!$db) {
                unset($in_progress[$key]);
                return $default;
            }
            
            $stmt = $db->prepare("SELECT setting_value, setting_type FROM settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $setting = $stmt->fetch();
            
            if ($setting) {
                $value = $setting['setting_value'];
                // Convert type
                switch ($setting['setting_type']) {
                    case 'integer':
                        $value = (int) $value;
                        break;
                    case 'boolean':
                        $value = (bool) $value;
                        break;
                    case 'json':
                        $value = json_decode($value, true);
                        break;
                }
            } else {
                $value = $default;
            }
        } catch (Exception $e) {
            $value = $default;
        }
        
        // Cache the value
        if ($value !== null) {
            cache_set($cache_key, $value, 3600); // Cache for 1 hour
        }
        
        unset($in_progress[$key]);
        return $value;
    } catch (Exception $e) {
        unset($in_progress[$key]);
        return $default;
    }
}

/**
 * Invalidate setting cache
 * 
 * @param string $key Setting key
 * @return void
 */
function invalidate_setting_cache(string $key): void {
    cache_delete("setting_{$key}");
}

