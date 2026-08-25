<?php
/**
 * License Server Configuration
 * 
 * SECURITY: For production, use environment variables or config.env file
 * Never commit sensitive credentials to version control!
 * 
 * Load environment variables from config.env if exists
 */
if (file_exists(__DIR__ . '/config.env')) {
    $env_lines = file(__DIR__ . '/config.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($env_lines as $line) {
        if (strpos(trim($line), '#') === 0) continue; // Skip comments
        if (strpos($line, '=') === false) continue;
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if (!defined($key)) {
            define($key, $value);
        }
    }
}

// Environment detection
if (!defined('ENVIRONMENT')) {
    define('ENVIRONMENT', 'production'); // Default to production for security
}

// Database Configuration
// SECURITY: Use environment variables in production!
if (!defined('DB_HOST')) define('DB_HOST', 'localhost');
if (!defined('DB_NAME')) define('DB_NAME', 'u364155471_portal');
if (!defined('DB_USER')) define('DB_USER', 'u364155471_portalapeiron');
if (!defined('DB_PASS')) define('DB_PASS', '@PortalApr21');
if (!defined('DB_CHARSET')) define('DB_CHARSET', 'utf8mb4');

// Security Keys
// SECURITY: In production, secrets MUST come from config.env or environment variables.
// Never rely on auto-generated keys outside development.
if (!defined('API_KEY') || API_KEY === '') {
    if (ENVIRONMENT === 'production') {
        throw new Exception('API_KEY is not configured. Set it via config.env or environment variables.');
    }
    define('API_KEY', bin2hex(random_bytes(32)));
    error_log('WARNING: API_KEY auto-generated for development. Configure a stable key in config.env.');
}

if (!defined('SESSION_NAME')) define('SESSION_NAME', 'apeiron_license_admin');

if (!defined('ENCRYPTION_KEY') || ENCRYPTION_KEY === '') {
    if (ENVIRONMENT === 'production') {
        throw new Exception('ENCRYPTION_KEY is not configured. Set it via config.env or environment variables.');
    }
    define('ENCRYPTION_KEY', bin2hex(random_bytes(32)));
    error_log('WARNING: ENCRYPTION_KEY auto-generated for development. Configure a stable key in config.env.');
}

// Security check: Fail fast in production if keys are identical; warn in development.
if (API_KEY === ENCRYPTION_KEY) {
    $msg = 'SECURITY: API_KEY and ENCRYPTION_KEY must be different. Update config.env.';
    if (ENVIRONMENT === 'production') {
        throw new Exception($msg);
    }
    error_log('WARNING: ' . $msg . ' (dev only warning)');
}

// CORS Configuration
// SECURITY: Never allow wildcard (*) in production!
// Parse CORS_ALLOWED_ORIGINS from env file (comma-separated) and store as serialized array
if (!defined('CORS_ALLOWED_ORIGINS_PARSED')) {
    if (defined('CORS_ALLOWED_ORIGINS') && !empty(CORS_ALLOWED_ORIGINS)) {
        // Parse comma-separated origins from config.env
        $cors_raw = CORS_ALLOWED_ORIGINS;
        $cors_origins = array_filter(array_map('trim', explode(',', $cors_raw)));
    } else {
        // Default: empty array (no CORS allowed) - most secure
        $cors_origins = [];
    }
    define('CORS_ALLOWED_ORIGINS_PARSED', serialize($cors_origins));
}
if (!defined('CORS_ALLOW_CREDENTIALS')) define('CORS_ALLOW_CREDENTIALS', true);

// Rate Limiting
if (!defined('RATE_LIMIT_ENABLED')) define('RATE_LIMIT_ENABLED', true);
if (!defined('RATE_LIMIT_REQUESTS')) define('RATE_LIMIT_REQUESTS', 100); // Requests per window
if (!defined('RATE_LIMIT_WINDOW')) define('RATE_LIMIT_WINDOW', 3600); // Window in seconds (1 hour)

// License Settings
if (!defined('DEFAULT_EXPIRATION_DAYS')) define('DEFAULT_EXPIRATION_DAYS', 365); // Default license expiration (hari)
if (!defined('DEFAULT_ACTIVATION_LIMIT')) define('DEFAULT_ACTIVATION_LIMIT', 1); // Default maksimal aktivasi per license (1 = single domain)

// Application Settings
if (!defined('APP_NAME')) define('APP_NAME', 'Apeiron License Server');
if (!defined('APP_VERSION')) define('APP_VERSION', '2.0.0');
if (!defined('TIMEZONE')) define('TIMEZONE', 'Asia/Jakarta');

// Timezone - Use UTC for storage, convert on display
date_default_timezone_set('UTC');

// Include security utilities (lazy load)
require_once __DIR__ . '/includes/security.php';

// Include exception classes for standardized error handling
require_once __DIR__ . '/includes/exceptions.php';

// Include validation functions
require_once __DIR__ . '/includes/validation.php';

// Configure secure session (only once)
if (!defined('SESSION_CONFIGURED')) {
    configure_secure_session();
    define('SESSION_CONFIGURED', true);
}

/**
 * Get correct login page URL relative to current script
 */
function get_login_page_url(): string {
    $script = $_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '';
    if (strpos($script, '/admin/') !== false) {
        return '../auth/login.php';
    }
    if (strpos($script, '/auth/') !== false) {
        return 'login.php';
    }
    return 'auth/login.php';
}

// Start session
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_start();
    
    // Only check expiration if session exists and has data
    if (isset($_SESSION['admin_logged_in'])) {
        // Check session expiration (lazy check - only if logged in)
        if (is_session_expired()) {
            session_destroy();
            if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') === false) {
                header('Location: ' . get_login_page_url());
                exit;
            }
        } else {
            // Update session activity (only if not expired)
            update_session_activity();
        }
    }
}

// Database Connection (with connection pooling)
function get_db_connection() {
    // Reuse existing connection if available
    static $db = null;
    if ($db !== null) {
        return $db;
    }
    
    try {
        $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_PERSISTENT         => false, // Set to true for persistent connections if needed
        ];
        $db = new PDO($dsn, DB_USER, DB_PASS, $options);
        return $db;
    } catch (PDOException $e) {
        // Always log detailed error for administrators
        error_log('Database connection failed: ' . $e->getMessage());

        // Check if this is an API request
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode([
                'success' => false,
                'message' => 'Database connection failed. Please contact the administrator.'
            ]);
            exit;
        }
        // For non-API requests, throw generic exception
        throw new Exception('Database connection failed. Please contact the administrator.');
    }
}

/**
 * Generate cryptographically secure license key
 * 
 * @return string Generated license key
 */
function generate_license_key(): string {
    // Use cryptographically secure random bytes
    $bytes1 = random_bytes(5);
    $bytes2 = random_bytes(5);
    $bytes3 = random_bytes(5);
    $bytes4 = random_bytes(5);
    $bytes5 = random_bytes(5);
    
    return 'APEIRON-' . strtoupper(
        bin2hex($bytes1) . '-' .
        bin2hex($bytes2) . '-' .
        bin2hex($bytes3) . '-' .
        bin2hex($bytes4) . '-' .
        bin2hex($bytes5)
    );
}

/**
 * Send JSON response with security headers
 * 
 * @param array $data Response data
 * @param int $status_code HTTP status code
 * @return void
 */
function json_response(array $data, int $status_code = 200): void {
    // Set security headers
    header('Content-Type: application/json; charset=utf-8');
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('X-XSS-Protection: 1; mode=block');
    
    // Set CORS headers if needed
    set_cors_headers();
    
    http_response_code($status_code);
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Set CORS headers based on configuration
 * 
 * @return void
 */
function set_cors_headers(): void {
    // Use CORS_ALLOWED_ORIGINS_PARSED (serialized array)
    $allowed_origins = [];
    if (defined('CORS_ALLOWED_ORIGINS_PARSED')) {
        $unserialized = @unserialize(CORS_ALLOWED_ORIGINS_PARSED);
        if (is_array($unserialized)) {
            $allowed_origins = $unserialized;
        }
    }
    
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    
    // SECURITY: Never allow wildcard in production!
    if (in_array('*', $allowed_origins)) {
        if (ENVIRONMENT === 'production') {
            // Reject in production - log security violation
            error_log('SECURITY WARNING: CORS wildcard (*) detected in production! Request rejected.');
            return; // Don't set CORS headers
        }
        // Only allow in development/staging
        header('Access-Control-Allow-Origin: *');
        return;
    }
    
    // Check if origin is allowed
    if (!empty($origin) && in_array($origin, $allowed_origins)) {
        header("Access-Control-Allow-Origin: $origin");
        if (CORS_ALLOW_CREDENTIALS) {
            header('Access-Control-Allow-Credentials: true');
        }
    } elseif (empty($allowed_origins)) {
        // No CORS allowed - most secure default
        // Don't set any CORS headers
        return;
    }
    // If origin not in allowed list, don't set CORS headers (reject)
    
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, X-API-Key, X-Signature, Authorization, X-CSRF-Token');
    header('Access-Control-Max-Age: 86400');
    
    // Handle preflight requests
    if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
        http_response_code(200);
        exit;
    }
}

/**
 * Require admin login with session validation
 * 
 * @return void Exits if not logged in
 */
function require_admin_login(): void {
    // Check if session is expired
    if (is_session_expired()) {
        session_destroy();
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            json_response([
                'success' => false,
                'message' => 'Session expired',
                'error_code' => 'SESSION_EXPIRED'
            ], 401);
        } else {
            header('Location: ' . get_login_page_url());
            exit;
        }
    }
    
    // Check login status
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/api/') !== false) {
            json_response([
                'success' => false,
                'message' => 'Unauthorized',
                'error_code' => 'UNAUTHORIZED'
            ], 401);
        } else {
            header('Location: ' . get_login_page_url());
            exit;
        }
    }
    
    // Update session activity
    update_session_activity();
}

// Helper function untuk hash password
function hash_password($password) {
    return password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]);
}

// Helper function untuk verify password
function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

/**
 * Sanitize input data
 * 
 * @param mixed $data Data to sanitize
 * @param bool $allow_html Whether to allow HTML
 * @return mixed Sanitized data
 */
function sanitize_input($data, bool $allow_html = false) {
    if (is_array($data)) {
        return array_map(function($item) use ($allow_html) {
            return sanitize_input($item, $allow_html);
        }, $data);
    }
    
    if (is_object($data)) {
        $result = new stdClass();
        foreach ($data as $key => $value) {
            $result->$key = sanitize_input($value, $allow_html);
        }
        return $result;
    }
    
    $data = trim((string)$data);
    
    if ($allow_html) {
        // Allow safe HTML tags only
        $allowed_tags = '<p><br><strong><em><ul><ol><li><a><code><pre>';
        return strip_tags($data, $allowed_tags);
    }
    
    return htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
}

// Helper function untuk format date
// FIX: Timezone consistency - convert UTC to display timezone
function format_date($date, $format = 'Y-m-d H:i:s') {
    if (empty($date)) return '-';
    
    // FIX: Handle timezone conversion properly
    // Dates are stored in UTC, convert to display timezone
    $timezone = defined('TIMEZONE') ? TIMEZONE : 'UTC';
    try {
        $utc_date = new DateTime($date, new DateTimeZone('UTC'));
        $utc_date->setTimezone(new DateTimeZone($timezone));
        return $utc_date->format($format);
    } catch (Exception $e) {
        // Fallback to simple format if timezone conversion fails
        return date($format, strtotime($date));
    }
}

// Helper function untuk get time ago
function time_ago($datetime) {
    $time = time() - strtotime($datetime);
    
    if ($time < 60) return 'just now';
    if ($time < 3600) return floor($time/60) . ' minutes ago';
    if ($time < 86400) return floor($time/3600) . ' hours ago';
    if ($time < 2592000) return floor($time/86400) . ' days ago';
    if ($time < 31536000) return floor($time/2592000) . ' months ago';
    return floor($time/31536000) . ' years ago';
}
