<?php
/**
 * Custom Exception Classes for Standardized Error Handling
 * 
 * Provides consistent error handling across the application
 */

/**
 * Base exception for license server
 */
class LicenseServerException extends Exception {
    protected $error_code;
    protected $context;
    
    public function __construct(string $message, string $error_code = 'GENERAL_ERROR', array $context = [], int $code = 0, Throwable $previous = null) {
        parent::__construct($message, $code, $previous);
        $this->error_code = $error_code;
        $this->context = $context;
    }
    
    public function getErrorCode(): string {
        return $this->error_code;
    }
    
    public function getContext(): array {
        return $this->context;
    }
    
    public function toArray(): array {
        return [
            'success' => false,
            'message' => $this->getMessage(),
            'error_code' => $this->error_code,
            'context' => $this->context
        ];
    }
}

/**
 * Database related exceptions
 */
class DatabaseException extends LicenseServerException {
    public function __construct(string $message, array $context = [], Throwable $previous = null) {
        parent::__construct($message, 'DATABASE_ERROR', $context, 500, $previous);
    }
}

/**
 * Validation exceptions
 */
class ValidationException extends LicenseServerException {
    public function __construct(string $message, string $field = '', array $context = []) {
        if (!empty($field)) {
            $context['field'] = $field;
        }
        parent::__construct($message, 'VALIDATION_ERROR', $context, 400);
    }
}

/**
 * Authentication/Authorization exceptions
 */
class AuthenticationException extends LicenseServerException {
    public function __construct(string $message, array $context = []) {
        parent::__construct($message, 'AUTHENTICATION_ERROR', $context, 401);
    }
}

class AuthorizationException extends LicenseServerException {
    public function __construct(string $message, array $context = []) {
        parent::__construct($message, 'AUTHORIZATION_ERROR', $context, 403);
    }
}

/**
 * License related exceptions
 */
class LicenseNotFoundException extends LicenseServerException {
    public function __construct(string $license_key = '', array $context = []) {
        $message = 'License key tidak ditemukan';
        if (!empty($license_key)) {
            $context['license_key'] = substr($license_key, 0, 8) . '...'; // Masked
        }
        parent::__construct($message, 'LICENSE_NOT_FOUND', $context, 404);
    }
}

class LicenseExpiredException extends LicenseServerException {
    public function __construct(string $expires = '', array $context = []) {
        $message = 'License telah kedaluwarsa';
        if (!empty($expires)) {
            $context['expires'] = $expires;
        }
        parent::__construct($message, 'LICENSE_EXPIRED', $context, 400);
    }
}

class LicenseSuspendedException extends LicenseServerException {
    public function __construct(array $context = []) {
        parent::__construct('License telah di-suspend', 'LICENSE_SUSPENDED', $context, 403);
    }
}

class LicenseInactiveException extends LicenseServerException {
    public function __construct(array $context = []) {
        parent::__construct('License telah dinonaktifkan', 'LICENSE_INACTIVE', $context, 403);
    }
}

class ActivationLimitReachedException extends LicenseServerException {
    public function __construct(int $limit = 0, array $context = []) {
        $message = 'Batas aktivasi telah tercapai';
        if ($limit > 0) {
            $context['limit'] = $limit;
            $message .= " ({$limit})";
        }
        parent::__construct($message, 'ACTIVATION_LIMIT_REACHED', $context, 400);
    }
}

/**
 * Rate limiting exception
 */
class RateLimitException extends LicenseServerException {
    public function __construct(int $retry_after = 3600, int $remaining = 0, array $context = []) {
        $context['retry_after'] = $retry_after;
        $context['remaining'] = $remaining;
        parent::__construct('Rate limit exceeded. Please try again later.', 'RATE_LIMIT_EXCEEDED', $context, 429);
    }
}

/**
 * Configuration exception
 */
class ConfigurationException extends LicenseServerException {
    public function __construct(string $message, array $context = []) {
        parent::__construct($message, 'CONFIGURATION_ERROR', $context, 500);
    }
}

