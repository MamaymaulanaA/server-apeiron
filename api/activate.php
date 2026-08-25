<?php
/**
 * License Activation API Endpoint
 * 
 * Endpoint: POST /api/activate.php
 * 
 * Requires: API key authentication
 * Rate Limit: 100 requests per hour per IP
 */

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limit.php';
require_once '../includes/monitoring.php';

// MONITORING: Start performance timer
$start_time = start_timer('api_activate');

// Set CORS headers (handled by config.php set_cors_headers())
set_cors_headers();

// Only accept POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response([
        'success' => false,
        'message' => 'Method not allowed',
        'error_code' => 'METHOD_NOT_ALLOWED'
    ], 405);
}

// Get request data first for API key validation
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true);

// Fallback to POST if JSON decode fails
if ($input === null && !empty($_POST)) {
    $input = $_POST;
}

$site_url = trim($input['site_url'] ?? '');
$product_id = trim($input['product_id'] ?? 'apeiron-kit');

// Require API authentication with domain validation
$api_key_info = require_api_auth(false, $site_url, $product_id);
if ($api_key_info === false) {
    // require_api_auth already sent error response
    exit;
}

// Rate limiting - use API key specific limits if available
// PERFORMANCE: Support per-API-key rate limiting
$client_ip = get_client_ip();
$rate_limit_requests = $api_key_info['rate_limit_requests'] ?? 100;
$rate_limit_window = $api_key_info['rate_limit_window'] ?? 3600;
$api_key_id = $api_key_info['id'] ?? null;
require_rate_limit($client_ip, $rate_limit_requests, $rate_limit_window, $api_key_id);

// Validate and sanitize input (already loaded above)
$license_key = trim($input['license_key'] ?? '');
$site_url = trim($input['site_url'] ?? '');
$product_id = trim($input['product_id'] ?? 'apeiron-kit');
$site_name = trim($input['site_name'] ?? '');

// Validate required fields
if (empty($license_key)) {
    json_response([
        'success' => false,
        'message' => 'License key is required',
        'error_code' => 'MISSING_LICENSE_KEY'
    ], 400);
}

if (empty($site_url)) {
    json_response([
        'success' => false,
        'message' => 'Site URL is required',
        'error_code' => 'MISSING_SITE_URL'
    ], 400);
}

// Validate and sanitize URL
$site_url = validate_url($site_url);
if ($site_url === false) {
    json_response([
        'success' => false,
        'message' => 'Invalid site URL format',
        'error_code' => 'INVALID_URL'
    ], 400);
}

// Validate request origin matches claimed site URL
if (!validate_request_origin($site_url)) {
    // Log failed attempt
    if (get_setting('enable_api_logging', true)) {
        log_api_request('activate', 'POST', $license_key ?? '', $site_url, $input ?? [], [
            'success' => false,
            'message' => 'Request origin mismatch'
        ], 403);
    }
    
    json_response([
        'success' => false,
        'message' => 'Request origin tidak sesuai dengan site URL yang diklaim',
        'error_code' => 'ORIGIN_MISMATCH'
    ], 403);
}

// Sanitize other inputs
$license_key = sanitize_input($license_key);
$product_id = sanitize_input($product_id);
$site_name = sanitize_input($site_name, true); // Allow HTML in site name

try {
    $db = get_db_connection();
    
    // Check if encryption is enabled
    $encryption_enabled = get_setting('encryption_enabled', false);
    
    // Find license - PERFORMANCE: Use hash for fast lookup
    $license = null;
    
    // Calculate hash for fast lookup
    $license_key_hash = hash('sha256', $license_key);
    
    // PERFORMANCE: First, try hash lookup (fastest - works for both encrypted and non-encrypted)
    $stmt = $db->prepare("
        SELECT * FROM licenses 
        WHERE license_key_hash = ? 
        AND product_id = ? 
        AND (is_encrypted = 0 OR is_encrypted IS NULL)
    ");
    $stmt->execute([$license_key_hash, $product_id]);
    $license = $stmt->fetch();
    
    // If not found, try plain text lookup (backward compatibility)
    if (!$license) {
        $stmt = $db->prepare("
            SELECT * FROM licenses 
            WHERE license_key = ? 
            AND product_id = ? 
            AND (is_encrypted = 0 OR is_encrypted IS NULL)
        ");
        $stmt->execute([$license_key, $product_id]);
        $license = $stmt->fetch();
    }
    
    // If not found and encryption enabled, try encrypted lookup
    if (!$license && $encryption_enabled) {
        $encrypted_key = encrypt_data($license_key);
        $stmt = $db->prepare("
            SELECT * FROM licenses 
            WHERE license_key = ? 
            AND product_id = ? 
            AND is_encrypted = 1
        ");
        $stmt->execute([$encrypted_key, $product_id]);
        $license = $stmt->fetch();
    }
    
    if (!$license) {
        // Log failed attempt
        if (get_setting('enable_api_logging', true)) {
            log_api_request('activate', 'POST', $license_key, $site_url, $input, [
                'success' => false,
                'message' => 'License key not found'
            ], 404);
        }
        
        json_response([
            'success' => false,
            'message' => 'License key tidak ditemukan',
            'error_code' => 'LICENSE_NOT_FOUND'
        ], 404);
    }
    
    // Check license status
    if ($license['status'] === 'suspended') {
        json_response([
            'success' => false,
            'message' => 'License telah di-suspend',
            'error_code' => 'LICENSE_SUSPENDED'
        ], 403);
    }
    
    // Check if license is inactive (deactivated)
    // Allow reactivation if user has previously activated this license on the same site
    if ($license['status'] === 'inactive') {
        // Check if there's a previous activation record for this license and site
        $stmt = $db->prepare("
            SELECT id, status 
            FROM activations 
            WHERE license_id = ? 
            AND site_url = ?
            LIMIT 1
        ");
        $stmt->execute([$license['id'], $site_url]);
        $previous_activation = $stmt->fetch();
        
        // If user has never activated on this site before, block activation
        if (!$previous_activation) {
            json_response([
                'success' => false,
                'message' => 'License telah dinonaktifkan. Silakan hubungi administrator untuk mengaktifkan kembali.',
                'error_code' => 'LICENSE_INACTIVE'
            ], 403);
        }
        // If user has previous activation record, allow reactivation (user can reactivate their own deactivated license)
    }
    
    // Check if license is expired
    if ($license['expires'] && strtotime($license['expires']) < time()) {
        // Update status to expired
        $db->prepare("UPDATE licenses SET status = 'expired' WHERE id = ?")->execute([$license['id']]);
        
        json_response([
            'success' => false,
            'message' => 'License telah kedaluwarsa',
            'error_code' => 'LICENSE_EXPIRED'
        ], 400);
    }
    
    // Check domain lock (if enabled)
    if (!empty($license['allowed_domains'])) {
        $allowed_domains = json_decode($license['allowed_domains'], true);
        if (!empty($allowed_domains) && !validate_domain_whitelist($site_url, $allowed_domains)) {
            json_response([
                'success' => false,
                'message' => 'Domain tidak diizinkan untuk license ini',
                'error_code' => 'DOMAIN_NOT_ALLOWED'
            ], 403);
        }
    }
    
    // Check single domain only enforcement
    if (!empty($license['single_domain_only']) && $license['single_domain_only'] == 1) {
        // First, check if domain_registered is already set and different
        if (!empty($license['domain_registered']) && $license['domain_registered'] !== $site_url) {
            json_response([
                'success' => false,
                'message' => 'License ini hanya dapat diaktifkan di satu domain. Domain yang sudah terdaftar: ' . $license['domain_registered'],
                'error_code' => 'SINGLE_DOMAIN_VIOLATION',
                'existing_domain' => $license['domain_registered']
            ], 403);
        }
        
        // Also check activations table for additional security
        $stmt = $db->prepare("
            SELECT site_url 
            FROM activations 
            WHERE license_id = ? 
            AND status = 'active' 
            AND site_url != ?
            LIMIT 1
        ");
        $stmt->execute([$license['id'], $site_url]);
        $existing_activation = $stmt->fetch();
        
        if ($existing_activation) {
            // License already activated on different domain
            json_response([
                'success' => false,
                'message' => 'License ini hanya dapat diaktifkan di satu domain. Domain yang sudah terdaftar: ' . $existing_activation['site_url'],
                'error_code' => 'SINGLE_DOMAIN_VIOLATION',
                'existing_domain' => $existing_activation['site_url']
            ], 403);
        }
        
        // Set domain_registered if first activation
        if (empty($license['domain_registered'])) {
            $db->prepare("UPDATE licenses SET domain_registered = ? WHERE id = ?")
               ->execute([$site_url, $license['id']]);
        }
    }
    
    // Check max_domains (if single_domain_only = 0)
    if (empty($license['single_domain_only']) && !empty($license['max_domains']) && $license['max_domains'] > 0) {
        // Count distinct domains
        $stmt = $db->prepare("
            SELECT COUNT(DISTINCT site_url) as domain_count
            FROM activations 
            WHERE license_id = ? 
            AND status = 'active'
        ");
        $stmt->execute([$license['id']]);
        $domain_count = $stmt->fetch()['domain_count'];
        
        // Check if current domain is new
        $stmt = $db->prepare("
            SELECT COUNT(*) as count
            FROM activations 
            WHERE license_id = ? 
            AND site_url = ?
            AND status = 'active'
        ");
        $stmt->execute([$license['id'], $site_url]);
        $is_existing = $stmt->fetch()['count'] > 0;
        
        if (!$is_existing && $domain_count >= $license['max_domains']) {
            json_response([
                'success' => false,
                'message' => 'Batas maksimal domain telah tercapai (' . $license['max_domains'] . ' domain)',
                'error_code' => 'MAX_DOMAINS_REACHED'
            ], 403);
        }
    }
    
    // Check activation limit
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM activations WHERE license_id = ? AND status = 'active'");
    $stmt->execute([$license['id']]);
    $activation_count = $stmt->fetch()['count'];
    
    // Check if site already activated
    $stmt = $db->prepare("SELECT * FROM activations WHERE license_id = ? AND site_url = ?");
    $stmt->execute([$license['id'], $site_url]);
    $existing = $stmt->fetch();
    
    if ($existing) {
        // If existing activation is active, just return success
        if ($existing['status'] === 'active') {
            json_response([
                'success' => true,
                'data' => [
                    'status' => $license['status'],
                    'expires' => $license['expires'] ?: '',
                    'activations' => $activation_count,
                    'activation_limit' => $license['activation_limit']
                ],
                'message' => 'License sudah diaktifkan untuk site ini'
            ]);
        }
        // If existing activation is deactivated, allow reactivation (continue to activation process below)
    }
    
    // Check if activation limit reached
    if ($license['activation_limit'] > 0 && $activation_count >= $license['activation_limit']) {
        json_response([
            'success' => false,
            'message' => 'Batas aktivasi telah tercapai',
            'error_code' => 'ACTIVATION_LIMIT_REACHED'
        ], 400);
    }
    
    // Add activation with transaction for data integrity
    // FIX: Include license status update in transaction to prevent race conditions
    $db->beginTransaction();
    
    try {
        // FIX: Use SELECT FOR UPDATE to prevent race conditions on concurrent activations
        $stmt = $db->prepare("SELECT * FROM licenses WHERE id = ? FOR UPDATE");
        $stmt->execute([$license['id']]);
        $license = $stmt->fetch(); // Get fresh license data with lock
        
        // Re-check activation limit with locked license
        $stmt = $db->prepare("SELECT COUNT(*) as count FROM activations WHERE license_id = ? AND status = 'active'");
        $stmt->execute([$license['id']]);
        $activation_count = $stmt->fetch()['count'];
        
        if ($license['activation_limit'] > 0 && $activation_count >= $license['activation_limit']) {
            $db->rollBack();
            json_response([
                'success' => false,
                'message' => 'Batas aktivasi telah tercapai',
                'error_code' => 'ACTIVATION_LIMIT_REACHED'
            ], 400);
        }
        
        $ip_address = get_client_ip();
        
        // Check if this is a reactivation (existing activation with deactivated status)
        if ($existing && $existing['status'] === 'deactivated') {
            // Reactivate existing activation
            $stmt = $db->prepare("
                UPDATE activations 
                SET status = 'active', 
                    site_name = ?,
                    ip_address = ?,
                    last_check = NOW(),
                    deactivated_at = NULL
                WHERE license_id = ? 
                AND site_url = ?
            ");
            $stmt->execute([$site_name ?: null, $ip_address, $license['id'], $site_url]);
        } else {
            // New activation
            $stmt = $db->prepare("INSERT INTO activations (license_id, site_url, site_name, ip_address, last_check) VALUES (?, ?, ?, ?, NOW())");
            $stmt->execute([$license['id'], $site_url, $site_name ?: null, $ip_address]);
        }
        
        // Update license status to active if not already (within same transaction)
        if ($license['status'] !== 'active') {
            $db->prepare("UPDATE licenses SET status = 'active' WHERE id = ?")->execute([$license['id']]);
            $license['status'] = 'active'; // Update local variable
        }
        
        $db->commit();
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
    // Get updated activation count (after transaction)
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM activations WHERE license_id = ? AND status = 'active'");
    $stmt->execute([$license['id']]);
    $activation_count = $stmt->fetch()['count'];
    
    $response = [
        'success' => true,
        'data' => [
            'status' => 'active',
            'expires' => $license['expires'] ?: '',
            'activations' => $activation_count,
            'activation_limit' => $license['activation_limit']
        ],
        'message' => 'License berhasil diaktifkan'
    ];
    
    // Log API request
    if (get_setting('enable_api_logging', true)) {
        log_api_request('activate', 'POST', $license_key, $site_url, $input, $response, 200);
    }
    
    // MONITORING: Track response time
    $response_time = end_timer('api_activate', $start_time);
    track_api_response_time('activate', $response_time);
    
    json_response($response);
    
} catch (PDOException $e) {
    // Log detailed error
    $error_details = [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'endpoint' => 'activate',
        'trace' => $e->getTraceAsString()
    ];
    
    error_log('Database error in activate.php: ' . json_encode($error_details));
    
    // Try to log via monitoring if available (but don't fail if it doesn't exist)
    if (function_exists('log_error_with_context')) {
        try {
            log_error_with_context('Database error in activate.php', $error_details, E_ERROR);
        } catch (Exception $log_error) {
            error_log('Failed to log error: ' . $log_error->getMessage());
        }
    }
    
    // Try to log API request (but don't fail if database is down)
    try {
        if (function_exists('get_setting')) {
            $enable_logging = get_setting('enable_api_logging', true);
            if ($enable_logging && function_exists('log_api_request')) {
                log_api_request('activate', 'POST', $license_key ?? '', $site_url ?? '', $input ?? [], [
                    'success' => false,
                    'message' => 'Database error: ' . $e->getMessage()
                ], 500);
            }
        }
    } catch (Exception $log_error) {
        // Silent fail - database might be down
    }
    
    // MONITORING: Track response time even on error
    if (isset($start_time) && function_exists('end_timer') && function_exists('track_api_response_time')) {
        try {
            $response_time = end_timer('api_activate', $start_time);
            track_api_response_time('activate', $response_time);
        } catch (Exception $monitor_error) {
            // Silent fail
        }
    }
    
    // Build error message based on environment
    $is_production = (defined('ENVIRONMENT') && ENVIRONMENT === 'production');
    
    // Check specific database errors
    $error_code = $e->getCode();
    $error_message = $e->getMessage();
    
    $user_message = 'Server error occurred. Please try again later.';
    $debug_message = 'Database error: ' . $error_message;
    
    // Provide more specific error messages for common issues
    if (strpos($error_message, 'Access denied') !== false) {
        $user_message = 'Database access denied. Please check database credentials.';
        $debug_message = 'Database authentication failed: ' . $error_message;
    } elseif (strpos($error_message, 'Unknown database') !== false) {
        $user_message = 'Database not found. Please ensure database "' . (defined('DB_NAME') ? DB_NAME : 'unknown') . '" exists.';
        $debug_message = 'Database not found: ' . $error_message;
    } elseif (strpos($error_message, 'Connection refused') !== false || strpos($error_message, 'Can\'t connect') !== false) {
        $user_message = 'Cannot connect to database server. Please check database server is running.';
        $debug_message = 'Database connection failed: ' . $error_message;
    } elseif (strpos($error_message, 'Table') !== false && strpos($error_message, 'doesn\'t exist') !== false) {
        $user_message = 'Database tables missing. Please run database migration.';
        $debug_message = 'Missing database table: ' . $error_message;
    }
    
    json_response([
        'success' => false,
        'message' => $is_production ? $user_message : $debug_message,
        'error_code' => 'DATABASE_ERROR',
        'debug' => $is_production ? null : [
            'code' => $error_code,
            'message' => $error_message,
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ], 500);
} catch (Exception $e) {
    // Log detailed error
    $error_details = [
        'error' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
        'endpoint' => 'activate',
        'trace' => $e->getTraceAsString()
    ];
    
    error_log('Error in activate.php: ' . json_encode($error_details));
    
    // Try to log via monitoring if available
    if (function_exists('log_error_with_context')) {
        try {
            log_error_with_context('Error in activate.php', $error_details, E_ERROR);
        } catch (Exception $log_error) {
            error_log('Failed to log error: ' . $log_error->getMessage());
        }
    }
    
    // Try to log API request (but don't fail if database is down)
    try {
        if (function_exists('get_setting')) {
            $enable_logging = get_setting('enable_api_logging', true);
            if ($enable_logging && function_exists('log_api_request')) {
                log_api_request('activate', 'POST', $license_key ?? '', $site_url ?? '', $input ?? [], [
                    'success' => false,
                    'message' => 'Server error: ' . $e->getMessage()
                ], 500);
            }
        }
    } catch (Exception $log_error) {
        // Silent fail
    }
    
    // MONITORING: Track response time even on error
    if (isset($start_time) && function_exists('end_timer') && function_exists('track_api_response_time')) {
        try {
            $response_time = end_timer('api_activate', $start_time);
            track_api_response_time('activate', $response_time);
        } catch (Exception $monitor_error) {
            // Silent fail
        }
    }
    
    // Build error message
    $is_production = (defined('ENVIRONMENT') && ENVIRONMENT === 'production');
    
    json_response([
        'success' => false,
        'message' => $is_production 
            ? 'Server error occurred. Please try again later.' 
            : 'Error: ' . $e->getMessage(),
        'error_code' => 'SERVER_ERROR',
        'debug' => $is_production ? null : [
            'code' => $e->getCode(),
            'message' => $e->getMessage(),
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ], 500);
}

