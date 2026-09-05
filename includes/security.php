<?php
/**
 * Security Utilities
 * 
 * Provides security functions for authentication, encryption, validation, and protection
 * 
 * Note: This file should NOT require config.php to avoid circular dependency.
 * Functions that need database access should be called after config.php is loaded.
 */

/**
 * Validate API request with HMAC signature
 * 
 * @param array $data Request data
 * @param string $signature Provided signature
 * @param string $secret Secret key
 * @return bool True if valid
 */
function validate_hmac_signature(array $data, string $signature, string $secret): bool {
    // Sort data by key for consistent hashing
    ksort($data);
    
    // Create message string
    $message = http_build_query($data);
    
    // Generate expected signature
    $expected = hash_hmac('sha256', $message, $secret);
    
    // Use timing-safe comparison
    return hash_equals($expected, $signature);
}

/**
 * Generate HMAC signature for API request
 * 
 * @param array $data Request data
 * @param string $secret Secret key
 * @return string HMAC signature
 */
function generate_hmac_signature(array $data, string $secret): string {
    ksort($data);
    $message = http_build_query($data);
    return hash_hmac('sha256', $message, $secret);
}

/**
 * Validate API key
 * 
 * @param string|null $api_key API key from request
 * @return bool True if valid
 */
function validate_api_key(?string $api_key): bool {
    if (empty($api_key)) {
        return false;
    }
    
    // Check if API_KEY constant is defined
    if (!defined('API_KEY')) {
        return false;
    }
    
    // Check against configured API key
    if ($api_key === API_KEY) {
        return true;
    }
    
    // Future: Check against database for multiple API keys
    // For now, use single API_KEY from config
    
    return false;
}

/**
 * Get API key from request headers or POST data
 * 
 * @return string|null API key or null
 */
function get_api_key_from_request(): ?string {
    // 1. Check $_SERVER directly (most reliable for custom headers in many PHP setups)
    if (isset($_SERVER['HTTP_X_API_KEY']) && !empty(trim($_SERVER['HTTP_X_API_KEY']))) {
        return trim($_SERVER['HTTP_X_API_KEY']);
    }
    
    // 2. Check X-API-Key header via getallheaders (standard)
    $raw_headers = getallheaders();
    $headers = array_change_key_case($raw_headers ?: [], CASE_UPPER);
    if (isset($headers['X-API-KEY']) && !empty(trim($headers['X-API-KEY']))) {
        return trim($headers['X-API-KEY']);
    }
    
    // 3. Check Authorization header (Bearer token)
    if (isset($headers['AUTHORIZATION'])) {
        $auth = $headers['AUTHORIZATION'];
        if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
            $token = trim($matches[1]);
            if (!empty($token)) {
                return $token;
            }
        }
    }
    
    // 4. Check POST data
    if (isset($_POST['api_key']) && !empty(trim($_POST['api_key']))) {
        return trim($_POST['api_key']);
    }
    
    // 5. Check JSON input (Cache result to allow multiple calls)
    static $json_input = null;
    if ($json_input === null) {
        $raw_input = file_get_contents('php://input');
        if (!empty($raw_input)) {
            $json_input = json_decode($raw_input, true);
        } else {
            $json_input = [];
        }
    }
    
    if (isset($json_input['api_key']) && !empty(trim($json_input['api_key']))) {
        return trim($json_input['api_key']);
    }
    
    // Log diagnostic info when API key not found (only once per request)
    static $logged = false;
    if (!$logged) {
        $logged = true;
        $request_uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $header_keys = implode(', ', array_keys($raw_headers ?: []));
        error_log("[API-AUTH] API key NOT found in request to {$request_uri}. Headers present: [{$header_keys}]");
    }
    
    return null;
}

/**
 * Validate request origin
 * 
 * This function verifies that the request comes from a legitimate source.
 * 
 * UPDATED: Server-to-server API requests (like from WordPress plugins) don't have
 * HTTP_REFERER header. Instead, we validate using:
 * 1. Valid API key (already checked before this function)
 * 2. User-Agent header containing plugin identifier
 * 3. Optional: HTTP Origin/Referer if present
 * 
 * @param string $claimed_site_url Site URL claimed by client
 * @return bool True if origin is valid
 */
function validate_request_origin(string $claimed_site_url): bool {
    // Get various headers for validation
    $referer = $_SERVER['HTTP_REFERER'] ?? '';
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? '';
    
    // Validate the claimed URL is a valid URL first
    $parsed_claimed = parse_url($claimed_site_url);
    if (!$parsed_claimed || empty($parsed_claimed['host'])) {
        error_log('API Request rejected: Invalid claimed site URL - ' . $claimed_site_url);
        return false;
    }
    
    // 1. If User-Agent indicates it's from our plugin, trust it (server-to-server request)
    // WordPress plugins make server-to-server requests without Referer
    if (strpos($user_agent, 'ApeironKit/') !== false || strpos($user_agent, 'WordPress/') !== false) {
        return true;
    }
    
    // 2. If API key was validated (stored in globals by require_api_auth), allow server-to-server
    if (isset($GLOBALS['api_key_info']) && !empty($GLOBALS['api_key_info'])) {
        return true;
    }
    
    // 3. Check HTTP Origin header (for browser-based requests)
    if (!empty($origin)) {
        $parsed_origin = parse_url($origin);
        if ($parsed_origin) {
            $origin_host = strtolower(preg_replace('/^www\./i', '', $parsed_origin['host'] ?? ''));
            $claimed_host = strtolower(preg_replace('/^www\./i', '', $parsed_claimed['host'] ?? ''));
            
            if ($origin_host === $claimed_host) {
                return true;
            }
        }
    }
    
    // 4. Check HTTP Referer header (fallback for browser requests)
    if (!empty($referer)) {
        $parsed_referer = parse_url($referer);
        if ($parsed_referer) {
            $referer_host = strtolower(preg_replace('/^www\./i', '', $parsed_referer['host'] ?? ''));
            $claimed_host = strtolower(preg_replace('/^www\./i', '', $parsed_claimed['host'] ?? ''));
            
            if ($referer_host === $claimed_host) {
                return true;
            }
        }
    }
    
    // If we get here in production without any valid validation, reject
    if (defined('ENVIRONMENT') && ENVIRONMENT === 'production') {
        error_log('API Request rejected: No valid origin validation - ' . $claimed_site_url . ' UA: ' . $user_agent);
        return false;
    }
    
    // Allow in development/staging for testing
    error_log('API Request without origin validation (allowed in dev): ' . $claimed_site_url);
    return true;
}

/**
 * Require API authentication
 * 
 * @param bool $require_hmac Whether to require HMAC signature
 * @param string|null $site_url Site URL for domain validation
 * @param string $product_id Product ID
 * @return array|false API key info if valid, false otherwise
 */
function require_api_auth(bool $require_hmac = false, ?string $site_url = null, string $product_id = 'apeiron-kit'): array|false {
    $api_key = get_api_key_from_request();
    
    // If API key is completely missing, return clear error immediately
    if (empty($api_key)) {
        $request_uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        error_log("[API-AUTH] MISSING API key for {$request_uri} from UA: {$user_agent}");
        
        json_response([
            'success' => false,
            'message' => 'API key is missing. Ensure X-API-Key header is set in the request.',
            'error_code' => 'MISSING_API_KEY'
        ], 401);
        return false;
    }
    
    // Try new API key system first (database-based)
    if (file_exists(__DIR__ . '/api_keys.php')) {
        require_once __DIR__ . '/api_keys.php';
        if (function_exists('validate_api_key_request')) {
            $key_info = validate_api_key_request($api_key, $site_url, $product_id);
            if ($key_info !== false) {
                // Store key info in global for rate limiting
                $GLOBALS['api_key_info'] = $key_info;
                return $key_info;
            }
        }
    }
    
    // Fallback to old system (config-based)
    if (!validate_api_key($api_key)) {
        $prefix = substr($api_key, 0, 8);
        error_log("[API-AUTH] INVALID API key (prefix: {$prefix}) for " . ($_SERVER['REQUEST_URI'] ?? 'unknown'));
        
        json_response([
            'success' => false,
            'message' => 'Invalid API key. The provided key does not match any active key.',
            'error_code' => 'INVALID_API_KEY'
        ], 401);
        return false;
    }
    
    // If HMAC required, validate signature
    if ($require_hmac) {
        $headers = getallheaders();
        $signature = $headers['X-Signature'] ?? $_POST['signature'] ?? null;
        
        if (empty($signature)) {
            json_response([
                'success' => false,
                'message' => 'Missing HMAC signature',
                'error_code' => 'MISSING_SIGNATURE'
            ], 401);
        }
        
        // Get request data
        $input = json_decode(file_get_contents('php://input'), true) ?: $_POST;
        unset($input['signature'], $input['api_key']); // Remove signature and key from data
        
        $secret_key = defined('API_KEY') ? API_KEY : '';
        if (empty($secret_key) || !validate_hmac_signature($input, $signature, $secret_key)) {
            json_response([
                'success' => false,
                'message' => 'Invalid HMAC signature',
                'error_code' => 'INVALID_SIGNATURE'
            ], 401);
        }
    }
    
    // Return default key info for rate limiting (fallback to old system)
    return [
        'id' => 0,
        'rate_limit_requests' => 100,
        'rate_limit_window' => 3600,
    ];
}

/**
 * Encrypt data using AES-256-CBC
 * 
 * @param string $data Data to encrypt
 * @param string|null $key Encryption key (defaults to API_KEY)
 * @return string Base64 encoded encrypted data with IV
 */
function encrypt_data(string $data, ?string $key = null): string {
    if ($key === null) {
        $key = defined('API_KEY') ? API_KEY : 'default-encryption-key-change-this';
    }
    
    // Derive key using PBKDF2
    $salt = hash('sha256', 'apeiron_license_salt', true);
    $derived_key = hash_pbkdf2('sha256', $key, $salt, 10000, 32, true);
    
    // Generate IV
    $iv = openssl_random_pseudo_bytes(16);
    
    // Encrypt
    $encrypted = openssl_encrypt($data, 'AES-256-CBC', $derived_key, OPENSSL_RAW_DATA, $iv);
    
    // Combine IV + encrypted data
    $combined = $iv . $encrypted;
    
    // Return base64 encoded
    return base64_encode($combined);
}

/**
 * Decrypt data using AES-256-CBC
 * 
 * @param string $encrypted_data Base64 encoded encrypted data with IV
 * @param string|null $key Decryption key (defaults to API_KEY)
 * @return string|false Decrypted data or false on failure
 */
function decrypt_data(string $encrypted_data, ?string $key = null) {
    if ($key === null) {
        $key = defined('API_KEY') ? API_KEY : 'default-encryption-key-change-this';
    }
    
    // Decode base64
    $combined = base64_decode($encrypted_data, true);
    if ($combined === false) {
        return false;
    }
    
    // Extract IV (first 16 bytes) and encrypted data
    $iv = substr($combined, 0, 16);
    $encrypted = substr($combined, 16);
    
    // Derive key
    $salt = hash('sha256', 'apeiron_license_salt', true);
    $derived_key = hash_pbkdf2('sha256', $key, $salt, 10000, 32, true);
    
    // Decrypt
    return openssl_decrypt($encrypted, 'AES-256-CBC', $derived_key, OPENSSL_RAW_DATA, $iv);
}

/**
 * Validate and sanitize URL
 * 
 * @param string $url URL to validate
 * @return string|false Validated URL or false if invalid
 */
function validate_url(string $url) {
    // Checked before trim(), which would silently swallow a trailing NUL or CRLF.
    if (preg_match('/[\x00-\x1F\x7F]/', $url)) {
        return false;
    }

    $url = trim($url);

    if ($url === '' || strlen($url) > 255) {
        return false;
    }

    // Percent-encoded control characters are an injection attempt, not a path.
    if (preg_match('/%(?:0[0-9a-f]|7f)/i', $url)) {
        return false;
    }

    // Legacy callers may omit the scheme; default to https as before.
    if (!preg_match('#^[a-z][a-z0-9+.\-]*://#i', $url)) {
        $url = 'https://' . $url;
    }

    $parts = parse_url($url);
    if ($parts === false || empty($parts['host'])) {
        return false;
    }

    // Only real web transports describe a WordPress installation.
    $scheme = strtolower($parts['scheme'] ?? '');
    if ($scheme !== 'http' && $scheme !== 'https') {
        return false;
    }

    // Credentials embedded in the URL are never part of an installation identity.
    if (isset($parts['user']) || isset($parts['pass'])) {
        return false;
    }

    // Hostname labels only: no IP literals, no IPv6 brackets, no wildcards.
    $host = strtolower($parts['host']);
    if (!preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
        return false;
    }

    $port = '';
    if (isset($parts['port'])) {
        $port_number = (int) $parts['port'];
        if ($port_number < 1 || $port_number > 65535) {
            return false;
        }
        $port = ':' . $port_number;
    }

    // Subfolder installs: a plain base path such as /wedding or /client-a.
    $path = $parts['path'] ?? '';
    if ($path !== '') {
        if (!preg_match('#^(/[A-Za-z0-9._~\-]+)*/?$#', $path) || strpos($path, '..') !== false) {
            return false;
        }
        $path = rtrim($path, '/');
    }

    // Query and fragment describe a request, not an installation.
    return $scheme . '://' . $host . $port . $path;
}

/**
 * Canonical installation identity.
 *
 * The same installation must resolve to the same string no matter which
 * transport a request arrived on, so the scheme, a default port and a trailing
 * slash are dropped and the hostname is lowercased. Everything that genuinely
 * separates two installations is preserved: `www`, the subdomain, the
 * installation subfolder and any non-default port.
 *
 * @param string $url Site URL as sent by the client.
 * @return string|false Canonical identity, or false when the URL is invalid.
 */
function canonicalize_site_url(string $url) {
    $validated = validate_url($url);
    if ($validated === false) {
        return false;
    }

    $parts  = parse_url($validated);
    $scheme = strtolower($parts['scheme']);
    $host   = strtolower($parts['host']);

    $port = '';
    if (isset($parts['port'])) {
        $port_number = (int) $parts['port'];
        $is_default  = ($scheme === 'http' && $port_number === 80)
            || ($scheme === 'https' && $port_number === 443);
        if (!$is_default) {
            $port = ':' . $port_number;
        }
    }

    $path = preg_replace('#/{2,}#', '/', $parts['path'] ?? '');
    $path = rtrim($path, '/');

    return $host . $port . $path;
}

/**
 * Validate domain against whitelist
 * 
 * @param string $domain Domain to validate
 * @param array $allowed_domains Array of allowed domains
 * @return bool True if domain is allowed
 */
function validate_domain_whitelist(string $domain, array $allowed_domains): bool {
    if (empty($allowed_domains)) {
        return true; // No restrictions
    }
    
    // Extract domain from URL
    $parsed = parse_url($domain);
    $host = $parsed['host'] ?? $domain;
    
    // Remove www. prefix for comparison
    $host = preg_replace('/^www\./i', '', $host);
    
    foreach ($allowed_domains as $allowed) {
        $allowed_clean = preg_replace('/^www\./i', '', $allowed);
        
        // Exact match
        if ($host === $allowed_clean) {
            return true;
        }
        
        // Wildcard subdomain support (e.g., *.example.com)
        if (strpos($allowed_clean, '*') === 0) {
            $pattern = '/^' . str_replace(['*', '.'], ['.*', '\.'], substr($allowed_clean, 2)) . '$/i';
            if (preg_match($pattern, $host)) {
                return true;
            }
        }
    }
    
    return false;
}

/**
 * Generate CSRF token
 * 
 * @return string CSRF token
 */
function generate_csrf_token(): string {
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validate CSRF token
 * 
 * @param string $token Token to validate
 * @return bool True if valid
 */
function validate_csrf_token(string $token): bool {
    if (!isset($_SESSION['csrf_token'])) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * Require CSRF token for POST requests
 * 
 * @return void Exits with error if token is invalid
 */
function require_csrf_token(): void {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = $_POST['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        
        if (empty($token) || !validate_csrf_token($token)) {
            if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
                json_response([
                    'success' => false,
                    'message' => 'Invalid CSRF token',
                    'error_code' => 'INVALID_CSRF_TOKEN'
                ], 403);
            } else {
                $_SESSION['error_message'] = 'Invalid security token. Please try again.';
                header('Location: ' . $_SERVER['HTTP_REFERER'] ?? 'index.php');
                exit;
            }
        }
    }
}

/**
 * Sanitize output for HTML display
 * 
 * @param mixed $data Data to sanitize
 * @param bool $allow_html Whether to allow HTML (default: false)
 * @return mixed Sanitized data
 */
function sanitize_output($data, bool $allow_html = false) {
    if (is_array($data)) {
        return array_map(function($item) use ($allow_html) {
            return sanitize_output($item, $allow_html);
        }, $data);
    }
    
    if (is_object($data)) {
        $result = new stdClass();
        foreach ($data as $key => $value) {
            $result->$key = sanitize_output($value, $allow_html);
        }
        return $result;
    }
    
    if ($allow_html) {
        // Allow HTML but sanitize dangerous tags
        return strip_tags($data, '<p><br><strong><em><ul><ol><li><a><code><pre>');
    }
    
    return htmlspecialchars((string)$data, ENT_QUOTES, 'UTF-8');
}

/**
 * Get client IP address (handles proxies)
 * 
 * @return string IP address
 */
function get_client_ip(): string {
    $ip_keys = [
        'HTTP_CF_CONNECTING_IP', // Cloudflare
        'HTTP_X_REAL_IP',         // Nginx
        'HTTP_X_FORWARDED_FOR',  // Proxy
        'REMOTE_ADDR'             // Direct
    ];
    
    foreach ($ip_keys as $key) {
        if (!empty($_SERVER[$key])) {
            $ip = $_SERVER[$key];
            
            // Handle comma-separated IPs (X-Forwarded-For)
            if (strpos($ip, ',') !== false) {
                $ips = explode(',', $ip);
                $ip = trim($ips[0]);
            }
            
            // Validate IP
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                return $ip;
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Set secure session parameters
 * 
 * @return void
 */
function configure_secure_session(): void {
    // Only configure once per request
    static $configured = false;
    if ($configured) {
        return;
    }
    $configured = true;

    // Detect HTTPS - also handles reverse proxy (Hostinger, Cloudflare, etc.)
    $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https')
        || (!empty($_SERVER['HTTP_X_FORWARDED_SSL']) && $_SERVER['HTTP_X_FORWARDED_SSL'] === 'on')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443);

    // Timeout (default 1 hour)
    $timeout = 3600;

    // Set secure session cookie parameters (PHP 7.2 compatible)
    // NOTE: session.cookie_samesite via ini_set is only supported in PHP >= 7.3
    // Use session_set_cookie_params() which works on PHP 7.2+
    if (PHP_VERSION_ID >= 70300) {
        // PHP 7.3+ supports SameSite parameter
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'domain'   => '',
            'secure'   => $is_https,
            'httponly' => true,
            'samesite' => 'Lax', // Lax allows cross-site GET but not POST (safer than Strict for login)
        ]);
    } else {
        // PHP 7.2 fallback
        session_set_cookie_params(0, '/', '', $is_https, true);
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.gc_maxlifetime', $timeout);
}


/**
 * Regenerate session ID (call after login)
 * 
 * @return void
 */
function regenerate_session_id(): void {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }
}

/**
 * Check if session is expired
 * 
 * @return bool True if expired
 */
function is_session_expired(): bool {
    if (!isset($_SESSION['last_activity'])) {
        return false;
    }
    
    // Cache timeout value to avoid repeated function calls
    static $timeout = null;
    if ($timeout === null) {
        $timeout = 3600; // Default 1 hour
        if (function_exists('get_setting')) {
            $timeout = get_setting('session_timeout', 3600);
        }
    }
    
    return (time() - $_SESSION['last_activity']) > $timeout;
}

/**
 * Update session activity timestamp
 * 
 * @return void
 */
function update_session_activity(): void {
    $_SESSION['last_activity'] = time();
}

/**
 * Get all HTTP headers
 * 
 * @return array Headers array
 */
if (!function_exists('getallheaders')) {
    function getallheaders(): array {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) === 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }
}

