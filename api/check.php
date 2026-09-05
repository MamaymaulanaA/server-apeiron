<?php
/**
 * License Status Check API Endpoint
 * 
 * Endpoint: POST /api/check.php
 * 
 * Requires: API key authentication
 * Rate Limit: 100 requests per hour per IP
 */

require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/rate_limit.php';
require_once '../includes/monitoring.php';

// MONITORING: Start performance timer
$start_time = start_timer('api_check');

// Set CORS headers
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

if ($input === null && !empty($_POST)) {
    $input = $_POST;
}

$site_url = trim($input['site_url'] ?? '');
$product_id = trim($input['product_id'] ?? 'apeiron-kit');

// Require API authentication with domain validation
$api_key_info = require_api_auth(false, $site_url, $product_id);
if ($api_key_info === false) {
    exit;
}

// Rate limiting - use API key specific limits if available
// PERFORMANCE: Support per-API-key rate limiting
$client_ip = get_client_ip();
$rate_limit_requests = $api_key_info['rate_limit_requests'] ?? 100;
$rate_limit_window = $api_key_info['rate_limit_window'] ?? 3600;
$api_key_id = $api_key_info['id'] ?? null;
require_rate_limit($client_ip, $rate_limit_requests, $rate_limit_window, $api_key_id);

// Validate and sanitize input
$license_key = trim($input['license_key'] ?? '');
$site_url = trim($input['site_url'] ?? '');
$product_id = trim($input['product_id'] ?? 'apeiron-kit');

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

// Transport-independent identity used to match legacy and new activations alike.
$site_url_canonical = canonicalize_site_url($site_url);
if ($site_url_canonical === false) {
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
        log_api_request('check', 'POST', $license_key ?? '', $site_url, $input ?? [], [
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
            log_api_request('check', 'POST', $license_key, $site_url, $input, [
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
    
    // Check if license is expired
    $is_expired = false;
    if ($license['expires'] && strtotime($license['expires']) < time()) {
        $is_expired = true;
        // Update status to expired
        $db->prepare("UPDATE licenses SET status = 'expired' WHERE id = ?")->execute([$license['id']]);
        $license['status'] = 'expired';
    }
    
    // Get activation count
    $stmt = $db->prepare("SELECT COUNT(*) as count FROM activations WHERE license_id = ?");
    $stmt->execute([$license['id']]);
    $activation_count = $stmt->fetch()['count'];
    
    // Check if this site is activated (only active activations)
    // Dual-read: legacy exact match first, canonical identity as the fallback,
    // so an activation registered before the canonical column still resolves.
    $stmt = $db->prepare("
        SELECT * FROM activations
        WHERE license_id = ?
        AND (site_url = ? OR site_url_canonical = ?)
        AND status = 'active'
        LIMIT 1
    ");
    $stmt->execute([$license['id'], $site_url, $site_url_canonical]);
    $activation = $stmt->fetch();
    
    // Update last_check if activation exists (rate limit this to prevent DoS)
    if ($activation) {
        // Only update if last check was more than 1 minute ago
        $last_check = $activation['last_check'] ? strtotime($activation['last_check']) : 0;
        if ((time() - $last_check) > 60) {
            $db->prepare("UPDATE activations SET last_check = NOW() WHERE id = ?")->execute([$activation['id']]);
        }
    }
    
    // Determine status
    $status = $license['status'];
    if ($is_expired) {
        $status = 'expired';
    } elseif ($activation && $status === 'active') {
        $status = 'active';
    } elseif (!$activation) {
        $status = 'inactive';
    }
    
    $response = [
        'success' => true,
        'data' => [
            'status' => $status,
            'expires' => $license['expires'] ?: '',
            'activations' => $activation_count,
            'activation_limit' => $license['activation_limit']
        ],
        'message' => 'License status berhasil diperiksa'
    ];
    
    // Log API request
    if (get_setting('enable_api_logging', true)) {
        log_api_request('check', 'POST', $license_key, $site_url, $input, $response, 200);
    }
    
    // MONITORING: Track response time
    $response_time = end_timer('api_check', $start_time);
    track_api_response_time('check', $response_time);
    
    json_response($response);
    
} catch (PDOException $e) {
    // MONITORING: Track error
    if (function_exists('log_error_with_context')) {
        log_error_with_context('Database error in check.php', [
            'error' => $e->getMessage(),
            'endpoint' => 'check'
        ], E_ERROR);
    }
    
    error_log('Database error in check.php: ' . $e->getMessage());
    
    if (get_setting('enable_api_logging', true)) {
        log_api_request('check', 'POST', $license_key ?? '', $site_url ?? '', $input ?? [], [
            'success' => false,
            'message' => 'Database error'
        ], 500);
    }
    
    // MONITORING: Track response time even on error
    if (isset($start_time)) {
        $response_time = end_timer('api_check', $start_time);
        track_api_response_time('check', $response_time);
    }
    
    // SECURITY: Don't expose database details in production
    $message = (defined('ENVIRONMENT') && ENVIRONMENT === 'production')
        ? 'Server error occurred. Please try again later.'
        : 'Database error: ' . $e->getMessage();
    
    json_response([
        'success' => false,
        'message' => $message,
        'error_code' => 'SERVER_ERROR'
    ], 500);
} catch (Exception $e) {
    // MONITORING: Track error
    if (function_exists('log_error_with_context')) {
        log_error_with_context('Error in check.php', [
            'error' => $e->getMessage(),
            'endpoint' => 'check'
        ], E_ERROR);
    }
    
    error_log('Error in check.php: ' . $e->getMessage());
    
    if (get_setting('enable_api_logging', true)) {
        log_api_request('check', 'POST', $license_key ?? '', $site_url ?? '', $input ?? [], [
            'success' => false,
            'message' => 'Server error'
        ], 500);
    }
    
    // MONITORING: Track response time even on error
    if (isset($start_time)) {
        $response_time = end_timer('api_check', $start_time);
        track_api_response_time('check', $response_time);
    }
    
    // SECURITY: Don't expose error details in production
    $message = (defined('ENVIRONMENT') && ENVIRONMENT === 'production')
        ? 'Server error occurred. Please try again later.'
        : 'Error: ' . $e->getMessage();
    
    json_response([
        'success' => false,
        'message' => $message,
        'error_code' => 'SERVER_ERROR'
    ], 500);
}

