<?php
/**
 * Rate Limiting Utilities
 * 
 * Provides rate limiting functionality using file-based storage
 * (Can be upgraded to Redis/Memcached for distributed systems)
 */

require_once __DIR__ . '/../config.php';

/**
 * Check rate limit for an identifier
 * 
 * @param string $identifier Unique identifier (IP, license key, etc.)
 * @param int $max_requests Maximum requests allowed
 * @param int $window Time window in seconds
 * @return bool True if within limit, false if exceeded
 */
function check_rate_limit(string $identifier, int $max_requests = 100, int $window = 3600): bool {
    if (!RATE_LIMIT_ENABLED) {
        return true; // Rate limiting disabled
    }
    
    // Use configured defaults if not specified
    if ($max_requests === 100) {
        $max_requests = RATE_LIMIT_REQUESTS;
    }
    if ($window === 3600) {
        $window = RATE_LIMIT_WINDOW;
    }
    
    $cache_dir = __DIR__ . '/../cache/rate_limit';
    if (!is_dir($cache_dir)) {
        mkdir($cache_dir, 0755, true);
    }
    
    $cache_file = $cache_dir . '/' . md5($identifier) . '.json';
    $now = time();
    
    // SECURITY: Use file locking to prevent race conditions
    $fp = fopen($cache_file, 'c+'); // Open for read/write, create if not exists
    if ($fp === false) {
        // If we can't open file, allow request (fail open for availability)
        error_log('Rate limit: Cannot open cache file: ' . $cache_file);
        return true;
    }
    
    // Acquire exclusive lock (blocking)
    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        // If we can't get lock, allow request (fail open)
        error_log('Rate limit: Cannot acquire lock for: ' . $cache_file);
        return true;
    }
    
    // Read existing data
    $data = [];
    $file_size = filesize($cache_file);
    if ($file_size > 0) {
        $content = fread($fp, $file_size);
        $data = json_decode($content, true) ?: [];
        
        // Clean old entries
        $data = array_filter($data, function($timestamp) use ($now, $window) {
            return ($now - $timestamp) < $window;
        });
    }
    
    // Check if limit exceeded
    if (count($data) >= $max_requests) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return false;
    }
    
    // Add current request
    $data[] = $now;
    
    // Write back (truncate first)
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    
    // Release lock and close
    flock($fp, LOCK_UN);
    fclose($fp);
    
    return true;
}

/**
 * Get remaining requests for an identifier
 * 
 * @param string $identifier Unique identifier
 * @param int $max_requests Maximum requests allowed
 * @param int $window Time window in seconds
 * @return int Remaining requests
 */
function get_rate_limit_remaining(string $identifier, int $max_requests = 100, int $window = 3600): int {
    if (!RATE_LIMIT_ENABLED) {
        return $max_requests;
    }
    
    $cache_dir = __DIR__ . '/../cache/rate_limit';
    $cache_file = $cache_dir . '/' . md5($identifier) . '.json';
    $now = time();
    
    if (!file_exists($cache_file)) {
        return $max_requests;
    }
    
    $content = file_get_contents($cache_file);
    $data = json_decode($content, true) ?: [];
    
    // Clean old entries
    $data = array_filter($data, function($timestamp) use ($now, $window) {
        return ($now - $timestamp) < $window;
    });
    
    return max(0, $max_requests - count($data));
}

/**
 * Require rate limit check
 * 
 * PERFORMANCE: Support both IP and API key based rate limiting
 * 
 * @param string $identifier Unique identifier (IP address or other)
 * @param int $max_requests Maximum requests allowed
 * @param int $window Time window in seconds
 * @param string|null $api_key_id Optional API key ID for per-key rate limiting
 * @return void Exits with error if limit exceeded
 */
function require_rate_limit(string $identifier, int $max_requests = 100, int $window = 3600, ?string $api_key_id = null): void {
    // PERFORMANCE: Check both IP and API key limits if API key provided
    $identifiers_to_check = [$identifier]; // Always check IP
    
    if (!empty($api_key_id)) {
        // Also check API key specific limit
        $identifiers_to_check[] = "api_key_{$api_key_id}";
    }
    
    $limit_exceeded = false;
    $remaining = $max_requests;
    
    foreach ($identifiers_to_check as $id) {
        if (!check_rate_limit($id, $max_requests, $window)) {
            $limit_exceeded = true;
            $remaining = get_rate_limit_remaining($id, $max_requests, $window);
            break; // Stop checking if any limit exceeded
        }
    }
    
    if ($limit_exceeded) {
        json_response([
            'success' => false,
            'message' => 'Rate limit exceeded. Please try again later.',
            'error_code' => 'RATE_LIMIT_EXCEEDED',
            'retry_after' => $window,
            'remaining' => $remaining
        ], 429);
    }
}

/**
 * Clean old rate limit files
 * 
 * @param int $max_age Maximum age in seconds (default: 1 day)
 * @return void
 */
function clean_rate_limit_cache(int $max_age = 86400): void {
    $cache_dir = __DIR__ . '/../cache/rate_limit';
    if (!is_dir($cache_dir)) {
        return;
    }
    
    $files = glob($cache_dir . '/*.json');
    $now = time();
    
    foreach ($files as $file) {
        if (filemtime($file) < ($now - $max_age)) {
            @unlink($file);
        }
    }
}

