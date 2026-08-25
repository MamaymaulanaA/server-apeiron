<?php
/**
 * API Key Management System
 * 
 * Handles generation, storage, validation, and management of API keys
 * for plugin authentication
 */

/**
 * Generate a new API key
 * 
 * @param string $key_name Human-readable name
 * @param string $product_id Product ID (default: 'apeiron-kit')
 * @param array $options Additional options (domains, IPs, rate limits, etc.)
 * @return array|false Generated key info or false on failure
 */
function generate_api_key(string $key_name, string $product_id = 'apeiron-kit', array $options = []): array|false {
    try {
        $db = get_db_connection();
        
        // Generate random API key (64 characters)
        $raw_key = bin2hex(random_bytes(32)); // 64 hex characters
        $api_key_prefix = substr($raw_key, 0, 8); // First 8 chars for identification
        
        // Hash the full key for storage (SHA-256)
        $hashed_key = hash('sha256', $raw_key);
        
        // Check if prefix already exists (very unlikely but check anyway)
        $stmt = $db->prepare("SELECT id FROM api_keys WHERE api_key_prefix = ?");
        $stmt->execute([$api_key_prefix]);
        if ($stmt->fetch()) {
            // Retry with new key
            return generate_api_key($key_name, $product_id, $options);
        }
        
        // Prepare data
        $allowed_domains = isset($options['allowed_domains']) 
            ? json_encode($options['allowed_domains']) 
            : null;
        $allowed_ips = isset($options['allowed_ips']) 
            ? json_encode($options['allowed_ips']) 
            : null;
        $rate_limit_requests = $options['rate_limit_requests'] ?? 100;
        $rate_limit_window = $options['rate_limit_window'] ?? 3600;
        $expires_at = isset($options['expires_at']) 
            ? date('Y-m-d H:i:s', strtotime($options['expires_at'])) 
            : null;
        
        // Insert into database
        $stmt = $db->prepare("
            INSERT INTO api_keys (
                key_name, api_key, api_key_prefix, product_id, 
                allowed_domains, allowed_ips, 
                rate_limit_requests, rate_limit_window,
                is_active, created_by, expires_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1, ?, ?)
        ");
        
        $stmt->execute([
            $key_name,
            $hashed_key,
            $api_key_prefix,
            $product_id,
            $allowed_domains,
            $allowed_ips,
            $rate_limit_requests,
            $rate_limit_window,
            $_SESSION['admin_id'] ?? null,
            $expires_at
        ]);
        
        $key_id = $db->lastInsertId();
        
        // Return key info (raw key only shown once!)
        return [
            'id' => $key_id,
            'key_name' => $key_name,
            'api_key' => $raw_key, // Full key - only shown once!
            'api_key_prefix' => $api_key_prefix,
            'product_id' => $product_id,
            'created_at' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        error_log('Generate API Key Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Validate API key from request
 * 
 * @param string|null $api_key API key to validate
 * @param string|null $site_url Site URL for domain validation
 * @param string|null $product_id Product ID
 * @return array|false Key info if valid, false otherwise
 */
function validate_api_key_request(?string $api_key, ?string $site_url = null, ?string $product_id = 'apeiron-kit'): array|false {
    // Trim whitespace/newlines that some servers may add
    if ($api_key !== null) {
        $api_key = trim($api_key);
    }
    
    if (empty($api_key)) {
        // Log failed attempt - missing API key with diagnostic info
        $request_uri = $_SERVER['REQUEST_URI'] ?? 'unknown';
        $user_agent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';
        error_log("[API-KEY] Missing API key for {$request_uri} | site_url={$site_url} | product={$product_id} | UA={$user_agent}");
        
        if (function_exists('log_api_request')) {
            log_api_request('api_key_validation', 'POST', null, $site_url ?? '', [
                'api_key' => 'missing',
                'product_id' => $product_id
            ], [
                'success' => false,
                'message' => 'API key missing'
            ], 401);
        }
        return false;
    }
    
    try {
        $db = get_db_connection();
        $client_ip = get_client_ip();
        
        // Extract prefix (first 8 chars)
        $prefix = substr($api_key, 0, 8);
        
        // Find key by prefix
        $stmt = $db->prepare("
            SELECT * FROM api_keys 
            WHERE api_key_prefix = ? 
            AND product_id = ? 
            AND is_active = 1
        ");
        $stmt->execute([$prefix, $product_id]);
        $key_data = $stmt->fetch();
        
        if (!$key_data) {
            // Log failed attempt - API key not found
            if (function_exists('log_api_request')) {
                log_api_request('api_key_validation', 'POST', null, $site_url ?? '', [
                    'api_key_prefix' => $prefix,
                    'product_id' => $product_id
                ], [
                    'success' => false,
                    'message' => 'API key not found'
                ], 401);
            }
            return false;
        }
        
        // Check expiration
        if (!empty($key_data['expires_at'])) {
            $expires = strtotime($key_data['expires_at']);
            if ($expires < time()) {
                // Log failed attempt - API key expired
                if (function_exists('log_api_request')) {
                    log_api_request('api_key_validation', 'POST', null, $site_url ?? '', [
                        'api_key_prefix' => $prefix,
                        'product_id' => $product_id,
                        'key_id' => $key_data['id']
                    ], [
                        'success' => false,
                        'message' => 'API key expired'
                    ], 401);
                }
                return false;
            }
        }
        
        // Hash the provided key and compare
        $hashed_provided = hash('sha256', $api_key);
        if (!hash_equals($key_data['api_key'], $hashed_provided)) {
            // Log failed attempt - invalid API key
            if (function_exists('log_api_request')) {
                log_api_request('api_key_validation', 'POST', null, $site_url ?? '', [
                    'api_key_prefix' => $prefix,
                    'product_id' => $product_id,
                    'key_id' => $key_data['id']
                ], [
                    'success' => false,
                    'message' => 'Invalid API key'
                ], 401);
            }
            return false;
        }
        
        // Validate domain if restrictions exist
        if (!empty($key_data['allowed_domains']) && !empty($site_url)) {
            $allowed_domains = json_decode($key_data['allowed_domains'], true);
            if (is_array($allowed_domains) && !empty($allowed_domains)) {
                $parsed = parse_url($site_url);
                $domain = $parsed['host'] ?? $site_url;
                $domain = preg_replace('/^www\./i', '', $domain);
                
                $allowed = false;
                foreach ($allowed_domains as $allowed_domain) {
                    $allowed_clean = preg_replace('/^www\./i', '', $allowed_domain);
                    if ($domain === $allowed_clean) {
                        $allowed = true;
                        break;
                    }
                    // Wildcard support
                    if (strpos($allowed_clean, '*') === 0) {
                        $pattern = '/^' . str_replace(['*', '.'], ['.*', '\.'], substr($allowed_clean, 2)) . '$/i';
                        if (preg_match($pattern, $domain)) {
                            $allowed = true;
                            break;
                        }
                    }
                }
                
                if (!$allowed) {
                    // Log failed attempt - domain not allowed
                    if (function_exists('log_api_request')) {
                        log_api_request('api_key_validation', 'POST', null, $site_url, [
                            'api_key_prefix' => $prefix,
                            'product_id' => $product_id,
                            'key_id' => $key_data['id'],
                            'domain' => $domain
                        ], [
                            'success' => false,
                            'message' => 'Domain not allowed for this API key'
                        ], 403);
                    }
                    return false;
                }
            }
        }
        
        // Validate IP if restrictions exist
        if (!empty($key_data['allowed_ips'])) {
            $allowed_ips = json_decode($key_data['allowed_ips'], true);
            if (is_array($allowed_ips) && !empty($allowed_ips)) {
                if (!in_array($client_ip, $allowed_ips)) {
                    // Log failed attempt - IP not allowed
                    if (function_exists('log_api_request')) {
                        log_api_request('api_key_validation', 'POST', null, $site_url ?? '', [
                            'api_key_prefix' => $prefix,
                            'product_id' => $product_id,
                            'key_id' => $key_data['id'],
                            'ip_address' => $client_ip
                        ], [
                            'success' => false,
                            'message' => 'IP address not allowed for this API key'
                        ], 403);
                    }
                    return false;
                }
            }
        }
        
        // Update usage stats
        $stmt = $db->prepare("
            UPDATE api_keys 
            SET last_used_at = NOW(), 
                last_used_ip = ?, 
                usage_count = usage_count + 1 
            WHERE id = ?
        ");
        $stmt->execute([$client_ip, $key_data['id']]);
        
        return [
            'id' => $key_data['id'],
            'key_name' => $key_data['key_name'],
            'product_id' => $key_data['product_id'],
            'rate_limit_requests' => $key_data['rate_limit_requests'],
            'rate_limit_window' => $key_data['rate_limit_window'],
        ];
        
    } catch (Exception $e) {
        error_log('Validate API Key Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Get all API keys
 * 
 * @param string|null $product_id Filter by product ID
 * @return array List of API keys (without full keys)
 */
function get_api_keys(?string $product_id = null): array {
    try {
        $db = get_db_connection();
        
        if ($product_id) {
            $stmt = $db->prepare("
                SELECT id, key_name, api_key_prefix, product_id, 
                       allowed_domains, allowed_ips,
                       rate_limit_requests, rate_limit_window,
                       is_active, last_used_at, last_used_ip, 
                       usage_count, created_by, created_at, expires_at
                FROM api_keys 
                WHERE product_id = ?
                ORDER BY created_at DESC
            ");
            $stmt->execute([$product_id]);
        } else {
            $stmt = $db->query("
                SELECT id, key_name, api_key_prefix, product_id, 
                       allowed_domains, allowed_ips,
                       rate_limit_requests, rate_limit_window,
                       is_active, last_used_at, last_used_ip, 
                       usage_count, created_by, created_at, expires_at
                FROM api_keys 
                ORDER BY created_at DESC
            ");
        }
        
        return $stmt->fetchAll();
        
    } catch (Exception $e) {
        error_log('Get API Keys Error: ' . $e->getMessage());
        return [];
    }
}

/**
 * Get API key by ID
 * 
 * @param int $key_id API key ID
 * @return array|false Key data or false
 */
function get_api_key(int $key_id): array|false {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("
            SELECT id, key_name, api_key_prefix, product_id, 
                   allowed_domains, allowed_ips,
                   rate_limit_requests, rate_limit_window,
                   is_active, last_used_at, last_used_ip, 
                   usage_count, created_by, created_at, expires_at
            FROM api_keys 
            WHERE id = ?
        ");
        $stmt->execute([$key_id]);
        return $stmt->fetch() ?: false;
        
    } catch (Exception $e) {
        error_log('Get API Key Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Update API key
 * 
 * @param int $key_id API key ID
 * @param array $data Data to update
 * @return bool Success
 */
function update_api_key(int $key_id, array $data): bool {
    try {
        $db = get_db_connection();
        
        $updates = [];
        $params = [];
        
        if (isset($data['key_name'])) {
            $updates[] = "key_name = ?";
            $params[] = $data['key_name'];
        }
        
        if (isset($data['allowed_domains'])) {
            $updates[] = "allowed_domains = ?";
            $params[] = is_array($data['allowed_domains']) 
                ? json_encode($data['allowed_domains']) 
                : $data['allowed_domains'];
        }
        
        if (isset($data['allowed_ips'])) {
            $updates[] = "allowed_ips = ?";
            $params[] = is_array($data['allowed_ips']) 
                ? json_encode($data['allowed_ips']) 
                : $data['allowed_ips'];
        }
        
        if (isset($data['rate_limit_requests'])) {
            $updates[] = "rate_limit_requests = ?";
            $params[] = (int)$data['rate_limit_requests'];
        }
        
        if (isset($data['rate_limit_window'])) {
            $updates[] = "rate_limit_window = ?";
            $params[] = (int)$data['rate_limit_window'];
        }
        
        if (isset($data['is_active'])) {
            $updates[] = "is_active = ?";
            $params[] = (int)$data['is_active'];
        }
        
        if (isset($data['expires_at'])) {
            $updates[] = "expires_at = ?";
            $params[] = !empty($data['expires_at']) 
                ? date('Y-m-d H:i:s', strtotime($data['expires_at'])) 
                : null;
        }
        
        if (empty($updates)) {
            return false;
        }
        
        $params[] = $key_id;
        
        $sql = "UPDATE api_keys SET " . implode(', ', $updates) . " WHERE id = ?";
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        
        return true;
        
    } catch (Exception $e) {
        error_log('Update API Key Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Delete API key
 * 
 * @param int $key_id API key ID
 * @return bool Success
 */
function delete_api_key(int $key_id): bool {
    try {
        $db = get_db_connection();
        $stmt = $db->prepare("DELETE FROM api_keys WHERE id = ?");
        $stmt->execute([$key_id]);
        return true;
        
    } catch (Exception $e) {
        error_log('Delete API Key Error: ' . $e->getMessage());
        return false;
    }
}

/**
 * Revoke API key (deactivate)
 * 
 * @param int $key_id API key ID
 * @return bool Success
 */
function revoke_api_key(int $key_id): bool {
    return update_api_key($key_id, ['is_active' => 0]);
}

