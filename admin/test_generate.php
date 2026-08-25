<?php
/**
 * Test Script untuk Generate License
 * Jalankan script ini untuk mengetes flow generate license
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>TEST GENERATE LICENSE</h2>";
echo "<p>Memeriksa konfigurasi database...</p>";

try {
    require_once '../config.php';
    require_once '../includes/functions.php';

    echo "<p>✅ Config loaded</p>";

    $db = get_db_connection();
    echo "<p>✅ Database connected</p>";

    // Test generate license key
    $license_key = generate_license_key();
    echo "<p>✅ License key generated: <code>{$license_key}</code></p>";

    // Test generate API key
    $client_api_key = bin2hex(random_bytes(32));
    echo "<p>✅ API key generated: <code>" . substr($client_api_key, 0, 16) . "...</code></p>";

    // Test insert ke licenses
    $license_key_hash = hash('sha256', $license_key);
    $expires = date('Y-m-d H:i:s', strtotime('+1 year'));

    $stmt = $db->prepare("
        INSERT INTO licenses
        (license_key, license_key_hash, client_api_key, product_id, customer_name, customer_email, status, expires, activation_limit, created_at, updated_at)
        VALUES
        (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");

    $stmt->execute([
        $license_key,
        $license_key_hash,
        $client_api_key,
        'apeiron-kit',
        'Test User',
        'test@example.com',
        'active',
        $expires,
        3
    ]);

    $license_id = $db->lastInsertId();
    echo "<p>✅ License inserted to database. ID: {$license_id}</p>";

    // Test insert ke api_keys
    $api_key_prefix = substr($client_api_key, 0, 8);
    $hashed_api_key = hash('sha256', $client_api_key);

    $stmt_api = $db->prepare("
        INSERT INTO api_keys
        (key_name, api_key, api_key_prefix, product_id, is_active, created_by, created_at, updated_at)
        VALUES
        (?, ?, ?, ?, 1, NULL, NOW(), NOW())
    ");

    $stmt_api->execute([
        'License: test@example.com',
        $hashed_api_key,
        $api_key_prefix,
        'apeiron-kit'
    ]);

    $api_key_id = $db->lastInsertId();
    echo "<p>✅ API Key inserted to database. ID: {$api_key_id}</p>";

    // Verify data
    echo "<hr><h3>VERIFIKASI DATA:</h3>";

    $stmt_check = $db->prepare("SELECT * FROM licenses WHERE id = ?");
    $stmt_check->execute([$license_id]);
    $license_data = $stmt_check->fetch(PDO::FETCH_ASSOC);

    echo "<pre>";
    print_r($license_data);
    echo "</pre>";

    $stmt_check2 = $db->prepare("SELECT * FROM api_keys WHERE id = ?");
    $stmt_check2->execute([$api_key_id]);
    $api_data = $stmt_check2->fetch(PDO::FETCH_ASSOC);

    echo "<h4>API Keys Table:</h4>";
    echo "<pre>";
    print_r($api_data);
    echo "</pre>";

    // Cleanup test data
    $db->prepare("DELETE FROM licenses WHERE id = ?")->execute([$license_id]);
    $db->prepare("DELETE FROM api_keys WHERE id = ?")->execute([$api_key_id]);

    echo "<hr><p>✅ Test data cleaned up</p>";
    echo "<p style='color: green; font-weight: bold;'>✅ SEMUA TEST BERHASIL!</p>";

} catch (Exception $e) {
    echo "<p style='color: red; font-weight: bold;'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
