<?php
/**
 * Test Script untuk Portal API Endpoint
 * Jalankan script ini untuk mengetes /api/create_license.php endpoint
 */

error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>TEST PORTAL API ENDPOINT</h2>";
echo "<p>Endpoint: <code>/api/create_license.php</code></p>";

try {
    require_once '../config.php';

    // Ambil API key portal untuk testing
    $db = get_db_connection();
    $stmt = $db->prepare("SELECT api_key FROM api_keys WHERE key_name LIKE '%Portal%' AND is_active = 1 LIMIT 1");
    $stmt->execute();
    $portal_key = $stmt->fetchColumn();

    if (!$portal_key) {
        die("<p style='color: red;'>❌ Tidak ada Portal API Key yang aktif. Silakan buat manual di Management API Key.</p>");
    }

    // Untuk testing, ambil salah satu key full dari DB (HANYA UNTUK TESTING!)
    // Di production, ini harusnya full key tersimpan di tempat aman
    echo "<p>✅ Portal API Key ditemukan: <code>" . substr($portal_key, 0, 16) . "...</code> (hashed)</p>";

    // Siapkan test data
    $test_data = [
        'client_name' => 'Test Portal User',
        'client_email' => 'test-portal-' . time() . '@example.com',
        'product_id' => 'apeiron-kit',
        'transaction_ref' => 'TEST-' . time()
    ];

    echo "<h3>Test Data:</h3>";
    echo "<pre>" . json_encode($test_data, JSON_PRETTY_PRINT) . "</pre>";

    // Call API endpoint
    $api_url = 'http://localhost/SERVER/api/create_license.php';

    echo "<p>Memanggil API endpoint: <code>{$api_url}</code></p>";

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $api_url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($test_data),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'X-API-Key: ' . $portal_key  // Ini perlu full key untuk production
        ],
        CURLOPT_TIMEOUT => 30
    ]);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curl_error = curl_error($ch);
    curl_close($ch);

    echo "<hr><h3>RESPONSE:</h3>";
    echo "<p>HTTP Status: <strong>{$http_code}</strong></p>";

    if ($curl_error) {
        echo "<p style='color: red;'>cURL Error: " . htmlspecialchars($curl_error) . "</p>";
    }

    echo "<pre>";
    $json_response = json_decode($response, true);
    print_r($json_response);
    echo "</pre>";

    if ($json_response['success'] ?? false) {
        echo "<hr>";
        echo "<p style='color: green; font-weight: bold;'>✅ LICENSE BERHASIL DIBUAT VIA PORTAL!</p>";

        if (!empty($json_response['data']['license_key'])) {
            echo "<p>License Key: <code>" . htmlspecialchars($json_response['data']['license_key']) . "</code></p>";
        }
        if (!empty($json_response['data']['api_key'])) {
            echo "<p>API Key: <code>" . substr($json_response['data']['api_key'], 0, 16) . "...</code></p>";
        }

        // Cek apakah API key muncul di api_keys table
        $api_key_prefix = substr($json_response['data']['api_key'], 0, 8);
        $stmt_check = $db->prepare("SELECT * FROM api_keys WHERE api_key_prefix = ? ORDER BY id DESC LIMIT 1");
        $stmt_check->execute([$api_key_prefix]);
        $api_key_data = $stmt_check->fetch(PDO::FETCH_ASSOC);

        if ($api_key_data) {
            echo "<p style='color: green;'>✅ API Key ditemukan di tabel api_keys!</p>";
            echo "<pre>" . print_r($api_key_data, true) . "</pre>";
        } else {
            echo "<p style='color: red;'>❌ API Key TIDAK ditemukan di tabel api_keys!</p>";
        }
    } else {
        echo "<p style='color: red; font-weight: bold;'>❌ API GAGAL!</p>";
        if (!empty($json_response['message'])) {
            echo "<p>Error Message: " . htmlspecialchars($json_response['message']) . "</p>";
        }
    }

} catch (Exception $e) {
    echo "<p style='color: red; font-weight: bold;'>❌ ERROR: " . htmlspecialchars($e->getMessage()) . "</p>";
    echo "<pre>" . htmlspecialchars($e->getTraceAsString()) . "</pre>";
}
?>
