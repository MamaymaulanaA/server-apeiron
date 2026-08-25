<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h2>Database Connection Test</h2>";

// Cek apakah config.php ada
if (!file_exists('config.php')) {
    die("Error: config.php tidak ditemukan.");
}

require_once 'config.php';

echo "<b>Konfigurasi yang dibaca:</b><br>";
echo "DB_HOST: " . (defined('DB_HOST') ? DB_HOST : 'Tidak terdefinisi') . "<br>";
echo "DB_NAME: " . (defined('DB_NAME') ? DB_NAME : 'Tidak terdefinisi') . "<br>";
echo "DB_USER: " . (defined('DB_USER') ? DB_USER : 'Tidak terdefinisi') . "<br>";
// Password disembunyikan untuk keamanan
echo "DB_PASS: " . (defined('DB_PASS') && !empty(DB_PASS) ? '(Ada passwordnya)' : '(Kosong)') . "<br><br>";

echo "<b>Mencoba koneksi PDO...</b><br>";
try {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $db = new PDO($dsn, DB_USER, DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "<span style='color:green;'>Koneksi berhasil! Database ditemukan.</span><br><br>";
    
    // Cek semua tabel di database
    $stmt = $db->query("SHOW TABLES");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    if (count($tables) > 0) {
        echo "<span style='color:green;'>Daftar Tabel yang ada di database ini (" . count($tables) . " tabel):</span><br>";
        
        // Cek spesifik tabel admins
        if (in_array('admins', $tables)) {
            echo "<span style='color:green;'>Tabel 'admins' ADA.</span><br>";
        } else {
            echo "<span style='color:red;'>Tabel 'admins' TIDAK DITEMUKAN. Memulai pembuatan tabel otomatis...</span><br>";
            
            // Buat tabel admins
            $createTableQuery = "
                CREATE TABLE `admins` (
                  `id` int(11) NOT NULL AUTO_INCREMENT,
                  `username` varchar(50) NOT NULL,
                  `email` varchar(100) NOT NULL,
                  `password_hash` varchar(255) NOT NULL,
                  `full_name` varchar(100) DEFAULT NULL,
                  `role` enum('admin','super_admin') NOT NULL DEFAULT 'admin',
                  `last_login` datetime DEFAULT NULL,
                  `created_at` timestamp DEFAULT current_timestamp(),
                  PRIMARY KEY (`id`),
                  UNIQUE KEY `username` (`username`),
                  UNIQUE KEY `email` (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ";
            
            $db->exec($createTableQuery);
            echo "<span style='color:green;'>Tabel 'admins' berhasil dibuat!</span><br>";
            
            // Masukkan data admin default
            require_once 'includes/functions.php'; // untuk hash_password
            $defaultUsername = 'admin';
            $defaultEmail = 'admin@portal.com';
            $defaultPassword = 'password123'; // Password default
            $defaultHash = password_hash($defaultPassword, PASSWORD_DEFAULT);
            
            $insertQuery = $db->prepare("INSERT INTO admins (username, email, password_hash, full_name, role) VALUES (?, ?, ?, ?, ?)");
            $insertQuery->execute([$defaultUsername, $defaultEmail, $defaultHash, 'Super Admin', 'super_admin']);
            
            echo "<span style='color:blue;'><b>AKUN ADMIN DEFAULT TELAH DIBUAT!</b></span><br>";
            echo "Username: <b>$defaultUsername</b><br>";
            echo "Password: <b>$defaultPassword</b><br>";
            echo "Silakan kembali ke halaman login dan gunakan akun di atas!<br>";
        }
    } else {
        echo "<span style='color:red;'>Error: DATABASE INI KOSONG (0 Tabel). Kamu harus meng-import file .sql ke phpMyAdmin!</span><br>";
    }
    
} catch (PDOException $e) {
    echo "<span style='color:red;'>Koneksi Gagal! Alasan/Error dari MySQL:</span><br>";
    echo "<b>" . $e->getMessage() . "</b><br><br>";
    echo "Tips perbaikan:<br>";
    echo "- Jika Access denied: Pastikan password benar dan user memiliki hak akses (Privileges).<br>";
    echo "- Jika Unknown database: Pastikan nama database benar-benar sesuai dan sudah dibuat di cPanel/Hostinger.<br>";
}
?>
