<?php
/**
 * Input Validation Functions
 * 
 * Provides comprehensive input validation for forms and API requests
 */

/**
 * Validate email address
 * 
 * @param string $email Email to validate
 * @return bool True if valid
 */
function validate_email(string $email): bool {
    if (empty($email)) {
        return false;
    }
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate and sanitize email
 * 
 * @param string $email Email to validate
 * @return string|false Validated email or false
 */
function validate_and_sanitize_email(string $email) {
    $email = trim($email);
    if (empty($email)) {
        return false;
    }
    $email = filter_var($email, FILTER_SANITIZE_EMAIL);
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * Validate integer with range
 * 
 * @param mixed $value Value to validate
 * @param int $min Minimum value
 * @param int $max Maximum value
 * @return int|false Validated integer or false
 */
function validate_integer($value, int $min = PHP_INT_MIN, int $max = PHP_INT_MAX) {
    $value = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => [
            'min_range' => $min,
            'max_range' => $max
        ]
    ]);
    return $value !== false ? $value : false;
}

/**
 * Validate activation limit
 * 
 * @param mixed $value Value to validate
 * @return int Valid activation limit (0 = unlimited)
 */
function validate_activation_limit($value): int {
    $validated = validate_integer($value, 0, 1000); // Max 1000 activations
    return $validated !== false ? $validated : 0;
}

/**
 * Validate expiration days
 * 
 * @param mixed $value Value to validate
 * @return int|false Valid days or false
 */
function validate_expiration_days($value) {
    return validate_integer($value, 0, 36500); // Max 100 years
}

/**
 * Validate date format
 * 
 * @param string $date Date string
 * @param string $format Expected format (default: Y-m-d)
 * @return bool True if valid
 */
function validate_date(string $date, string $format = 'Y-m-d'): bool {
    if (empty($date)) {
        return false;
    }
    $d = DateTime::createFromFormat($format, $date);
    return $d && $d->format($format) === $date;
}

/**
 * Validate date is in the future
 * 
 * @param string $date Date string
 * @return bool True if date is in the future
 */
function validate_future_date(string $date): bool {
    if (empty($date)) {
        return true; // Empty date is allowed (no expiration)
    }
    $date_time = strtotime($date);
    if ($date_time === false) {
        return false;
    }
    return $date_time > time();
}

/**
 * Validate license key format
 * 
 * @param string $license_key License key to validate
 * @return bool True if valid format
 */
function validate_license_key_format(string $license_key): bool {
    if (empty($license_key)) {
        return false;
    }
    // License key format: APEIRON-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX-XXXXXXXX
    return preg_match('/^APEIRON-[A-Z0-9]{10}(-[A-Z0-9]{10}){4}$/', $license_key) === 1;
}

/**
 * Validate product ID
 * 
 * @param string $product_id Product ID to validate
 * @return bool True if valid
 */
function validate_product_id(string $product_id): bool {
    if (empty($product_id)) {
        return false;
    }
    // Product ID: alphanumeric, dash, underscore, max 100 chars
    return preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $product_id) === 1;
}

/**
 * Validate site name
 * 
 * @param string $site_name Site name to validate
 * @param int $max_length Maximum length
 * @return string|false Validated site name or false
 */
function validate_site_name(string $site_name, int $max_length = 255) {
    $site_name = trim($site_name);
    if (strlen($site_name) > $max_length) {
        return false;
    }
    return $site_name;
}

/**
 * Validate pagination parameters
 * 
 * @param mixed $page Page number
 * @param mixed $per_page Items per page
 * @return array|false Validated [page, per_page] or false
 */
function validate_pagination($page, $per_page) {
    $page = validate_integer($page, 1, 10000); // Max 10000 pages
    $per_page = validate_integer($per_page, 1, 1000); // Max 1000 items per page
    
    if ($page === false || $per_page === false) {
        return false;
    }
    
    return [$page, $per_page];
}

/**
 * Validate status value
 * 
 * @param string $status Status to validate
 * @param array $allowed Allowed status values
 * @return bool True if valid
 */
function validate_status(string $status, array $allowed = ['active', 'inactive', 'expired', 'suspended']): bool {
    return in_array($status, $allowed, true);
}

/**
 * Validate CSRF token
 * 
 * @param string $token Token to validate
 * @return bool True if valid
 */
function validate_csrf_token_input(string $token): bool {
    if (empty($token)) {
        return false;
    }
    return validate_csrf_token($token);
}

