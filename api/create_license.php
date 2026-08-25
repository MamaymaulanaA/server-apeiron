<?php
/**
 * Create License API Endpoint
 * 
 * Endpoint: POST /api/create_license.php
 * 
 * Requires: API key authentication
 * Action: Generates a new license key and returns it
 */

require_once '../config.php';
require_once '../includes/security.php';

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

// Get input
$raw_input = file_get_contents('php://input');
$input = json_decode($raw_input, true) ?? $_POST;

$client_name = $input['client_name'] ?? 'Unknown User';
$client_email = $input['client_email'] ?? '';
$product_id = $input['product_id'] ?? 'apeiron-kit';
$transaction_ref = $input['transaction_ref'] ?? '';
$reference = $input['reference'] ?? $transaction_ref; // Support both field names

// Validate API Key using database-based system (like other endpoints)
$api_key_info = require_api_auth(false, null, $product_id);
if ($api_key_info === false) {
    exit; // Error response already sent by require_api_auth
}

// Generate License
$license_key = generate_license_key();
$license_key_hash = hash('sha256', $license_key);
$client_api_key = bin2hex(random_bytes(32)); // Generate user API key

try {
    $db = get_db_connection();

    // Check if client_email already has a license for this product (ENFORCE 1 USER = 1 LICENSE)
    if (!empty($client_email)) {
        $stmt = $db->prepare("
            SELECT id, license_key, status, expires
            FROM licenses
            WHERE customer_email = ?
            AND product_id = ?
            AND status != 'suspended'
            ORDER BY created_at DESC
            LIMIT 1
        ");
        $stmt->execute([$client_email, $product_id]);
        $existing = $stmt->fetch();

        if ($existing) {
            // License already exists for this email and product
            $existing_status = $existing['status'];
            $is_expired = ($existing['expires'] && strtotime($existing['expires']) < time());

            if ($existing_status === 'active' && !$is_expired) {
                // Fetch full details since we need to sync back to portal
                $stmtFull = $db->prepare("SELECT license_key, client_api_key, expires, activation_limit FROM licenses WHERE id = ?");
                $stmtFull->execute([$existing['id']]);
                $full = $stmtFull->fetch();

                // FIX: If existing license has no API key, generate one and update
                $apiKey = $full['client_api_key'];
                if (empty($apiKey)) {
                    $apiKey = bin2hex(random_bytes(32));
                    $stmtUpdate = $db->prepare("UPDATE licenses SET client_api_key = ?, updated_at = NOW() WHERE id = ?");
                    $stmtUpdate->execute([$apiKey, $existing['id']]);
                    error_log("[LICENSE-SERVER] Generated NEW API key for existing license #{$existing['id']}");

                    // NEW: Also insert into api_keys table
                    $api_key_prefix = substr($apiKey, 0, 8);
                    $hashed_api_key = hash('sha256', $apiKey);
                    $key_name = 'License: ' . $client_email;

                    $stmtApiInsert = $db->prepare("
                        INSERT INTO api_keys
                        (key_name, api_key, api_key_prefix, product_id, is_active, created_by, created_at, updated_at)
                        VALUES (?, ?, ?, ?, 1, NULL, NOW(), NOW())
                    ");
                    $stmtApiInsert->execute([
                        $key_name,
                        $hashed_api_key,
                        $api_key_prefix,
                        $product_id
                    ]);
                    $new_api_key_id = $db->lastInsertId();
                    error_log("[TRACE] API KEY SAVED TO DB (existing license): prefix={$api_key_prefix}, id={$new_api_key_id}, license_id={$existing['id']}");
                }

                // Active license exists - allow syncing back to portal by returning the keys
                json_response([
                    'success' => true, // Treat as success for syncing
                    'message' => 'Email ini sudah memiliki lisensi aktif. Sinkronisasi data berhasil.',
                    'data' => [
                        'license_key' => $full['license_key'],
                        'api_key' => $apiKey,
                        'expires' => $full['expires'],
                        'activation_limit' => $full['activation_limit'],
                        'product_id' => $product_id,
                        'is_existing' => true
                    ]
                ]);
            } elseif ($existing_status === 'expired' || $is_expired) {
                // Previous license expired - allow new license creation
                // But first deactivate the old one
                $db->prepare("UPDATE licenses SET status = 'expired_replaced' WHERE id = ?")
                    ->execute([$existing['id']]);
            }
        }
    }

    // Validate email is not empty
    if (empty($client_email)) {
        json_response([
            'success' => false,
            'message' => 'Client email is required',
            'error_code' => 'MISSING_EMAIL'
        ], 400);
    }

    // Default values — all timestamps in UTC (server timezone is UTC)
    $expires = date('Y-m-d H:i:s', strtotime('+1 year')); // 1 year from now (UTC)
    $activation_limit = defined('DEFAULT_ACTIVATION_LIMIT') ? DEFAULT_ACTIVATION_LIMIT : 1; // Use config constant
    $status = 'active'; // License langsung aktif setelah dibuat via portal

    $stmt = $db->prepare("
        INSERT INTO licenses
        (license_key, license_key_hash, client_api_key, product_id, customer_name, customer_email, status, expires, activation_limit, single_domain_only, created_at, updated_at)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
    ");

    $stmt->execute([
        $license_key,
        $license_key_hash,
        $client_api_key,
        $product_id,
        $client_name,
        $client_email,
        $status,
        $expires,
        $activation_limit
    ]);

    $license_id = $db->lastInsertId();

    // Debug logging
    error_log("[LICENSE-SERVER] Created license: key={$license_key}, email={$client_email}, status={$status}, expires={$expires}, product={$product_id}");

    // NEW: Also insert into api_keys table for Management API Key page
    $api_key_prefix = substr($client_api_key, 0, 8);
    $hashed_api_key = hash('sha256', $client_api_key);
    $key_name = 'License: ' . (!empty($client_email) ? $client_email : $client_name);

    $stmt_api = $db->prepare("
        INSERT INTO api_keys
        (key_name, api_key, api_key_prefix, product_id, is_active, created_by, created_at, updated_at)
        VALUES
        (?, ?, ?, ?, 1, NULL, NOW(), NOW())
    ");

    $stmt_api->execute([
        $key_name,
        $hashed_api_key,
        $api_key_prefix,
        $product_id
    ]);

    $api_key_id = $db->lastInsertId();
    error_log("[TRACE] API KEY SAVED TO DB: prefix={$api_key_prefix}, id={$api_key_id}, license_id={$license_id}");
    
    json_response([
        'success' => true,
        'message' => 'License created successfully',
        'data' => [
            'license_key' => $license_key,
            'api_key' => $client_api_key,
            'expires' => $expires,
            'activation_limit' => $activation_limit,
            'product_id' => $product_id
        ]
    ]);

} catch (PDOException $e) {
    error_log('Error creating license: ' . $e->getMessage());
    json_response([
        'success' => false,
        'message' => 'Database error creating license',
        'error_code' => 'DB_ERROR'
    ], 500);
}
