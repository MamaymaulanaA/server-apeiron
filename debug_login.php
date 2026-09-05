<?php
/**
 * DEBUG LOGIN - HAPUS FILE INI SETELAH SELESAI DEBUG!
 */
error_reporting(E_ALL);
ini_set("display_errors", 1);

// Credentials come from config.env or the environment, never from this file.
$env = [];
if (is_readable(__DIR__ . "/config.env")) {
    foreach (file(__DIR__ . "/config.env", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), "#") === 0 || strpos($line, "=") === false) {
            continue;
        }
        list($key, $value) = explode("=", $line, 2);
        $env[trim($key)] = trim($value);
    }
}

function debug_env(array $env, string $key, string $fallback = "")
{
    if (!empty($env[$key])) {
        return $env[$key];
    }
    $value = getenv($key);
    return ($value !== false && $value !== "") ? $value : $fallback;
}

$DB_HOST    = debug_env($env, "DB_HOST", "localhost");
$DB_NAME    = debug_env($env, "DB_NAME");
$DB_USER    = debug_env($env, "DB_USER");
$DB_PASS    = debug_env($env, "DB_PASS");
$DB_CHARSET = debug_env($env, "DB_CHARSET", "utf8mb4");

if ($DB_NAME === "" || $DB_USER === "" || $DB_PASS === "") {
    exit("Database credentials are not configured. Set DB_NAME, DB_USER and DB_PASS in config.env.");
}

$msg = "";
$reset_done = false;
$new_pass_plain = "";
$admins = [];

if ($_SERVER["REQUEST_METHOD"] === "POST" && isset($_POST["reset_password"])) {
    $new_pass_plain = trim($_POST["new_password"] ?? "Admin@1234");
    if (!empty($new_pass_plain)) {
        $new_hash = password_hash($new_pass_plain, PASSWORD_BCRYPT, ["cost" => 12]);
        try {
            $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
            $db2 = new PDO($dsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $st  = $db2->prepare("UPDATE admins SET password_hash = ? WHERE id = 1");
            $st->execute([$new_hash]);
            $reset_done = true;
            $msg = "Password berhasil direset ke: " . htmlspecialchars($new_pass_plain);
        } catch (PDOException $e) {
            $msg = "Error reset: " . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<title>Debug Login - Portal Apeiron</title>
<style>
body{font-family:monospace;background:#0a0a0f;color:#e0e0e0;padding:30px;max-width:900px;margin:auto}
h2{color:#00d4aa;border-bottom:1px solid #333;padding-bottom:8px;margin-top:0}
.ok{color:#00d4aa;font-weight:bold}
.err{color:#ff4757;font-weight:bold}
.warn{color:#ffa502;font-weight:bold}
.box{background:#111;border:1px solid #333;border-radius:8px;padding:20px;margin-bottom:20px}
.box-danger{border-color:#ff4757}
.box-warn{border-color:#ffa502;background:#1a1200}
table{border-collapse:collapse;width:100%}
td,th{border:1px solid #333;padding:8px 12px;text-align:left;font-size:13px}
th{background:#1a1a2e;color:#00d4aa}
input[type=text]{background:#1a1a2e;border:1px solid #555;color:#fff;padding:8px 12px;border-radius:5px;width:250px;font-family:monospace}
button{background:#00d4aa;color:#000;border:none;padding:10px 22px;border-radius:5px;cursor:pointer;font-weight:bold;margin-left:8px}
.success-box{background:#0d2d1a;border:1px solid #00d4aa;border-radius:6px;padding:14px;margin-bottom:14px}
</style>
</head>
<body>

<div class="box box-warn">
    <b>PERINGATAN KEAMANAN:</b> HAPUS file <code>debug_login.php</code> dari server segera setelah debugging selesai!
</div>

<h2>Diagnosis Masalah Login — Portal Apeiron</h2>

<?php
// ==================================================
// 1. CEK KONEKSI DB
// ==================================================
echo "<div class='box'>";
echo "<h2>1. Koneksi Database</h2>";
echo "Host: <b>{$DB_HOST}</b> | DB: <b>{$DB_NAME}</b> | User: <b>{$DB_USER}</b><br>";
echo "Password: " . (empty($DB_PASS) ? "<span class='err'>KOSONG!</span>" : "<span class='ok'>Ada (tidak ditampilkan)</span>") . "<br><br>";

$db = null;
try {
    $dsn = "mysql:host={$DB_HOST};dbname={$DB_NAME};charset={$DB_CHARSET}";
    $db  = new PDO($dsn, $DB_USER, $DB_PASS, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    echo "<span class='ok'>✅ Koneksi Database: BERHASIL</span>";
    echo "</div>";

    // ==================================================
    // 2. CEK DATA ADMINS
    // ==================================================
    echo "<div class='box'>";
    echo "<h2>2. Data Tabel Admins</h2>";
    $stmt  = $db->query("SELECT id, username, email, password_hash, full_name, role, last_login FROM admins");
    $admins = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($admins)) {
        echo "<span class='err'>❌ Tabel admins KOSONG! Tidak ada akun. Import file SQL dulu ke phpMyAdmin.</span>";
    } else {
        echo "<span class='ok'>✅ " . count($admins) . " admin ditemukan:</span><br><br>";
        echo "<table><thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Role</th><th>Hash Format</th><th>Last Login</th></tr></thead><tbody>";
        foreach ($admins as $a) {
            $is_bcrypt = (substr($a["password_hash"], 0, 4) === '$2y$' || substr($a["password_hash"], 0, 4) === '$2b$');
            $hash_st   = $is_bcrypt ? "<span class='ok'>bcrypt ✅</span>" : "<span class='err'>BUKAN bcrypt ❌</span>";
            echo "<tr>
                <td>{$a["id"]}</td>
                <td><b>{$a["username"]}</b></td>
                <td>{$a["email"]}</td>
                <td>{$a["role"]}</td>
                <td>{$hash_st}</td>
                <td>" . ($a["last_login"] ?: "<span style='color:#555'>-</span>") . "</td>
            </tr>";
        }
        echo "</tbody></table>";
    }
    echo "</div>";

    // ==================================================
    // 3. TEST PASSWORD OTOMATIS
    // ==================================================
    echo "<div class='box'>";
    echo "<h2>3. Test Password Otomatis</h2>";
    $first = $admins[0] ?? null;
    if ($first) {
        $hash  = $first["password_hash"];
        $tests = ["admin","Admin","password","password123","admin123","Admin123","Admin@123","Admin@1234","Admin@123!","123456","portal","apeiron","Portal123","Apeiron123"];
        echo "Mencoba " . count($tests) . " password umum untuk akun <b>{$first["username"]}</b> (email: <b>{$first["email"]}</b>):<br><br>";
        $found = false;
        foreach ($tests as $p) {
            if (password_verify($p, $hash)) {
                echo "<span class='ok'>✅ PASSWORD KETEMU! → <b>" . htmlspecialchars($p) . "</b></span><br>";
                $found = true;
            }
        }
        if (!$found) {
            echo "<span class='warn'>⚠️ Tidak ditemukan dari daftar umum. Gunakan form reset di bawah untuk set password baru.</span>";
        }
    } else {
        echo "<span class='warn'>Tidak ada admin untuk di-test.</span>";
    }
    echo "</div>";

    // ==================================================
    // 4. FORM RESET PASSWORD
    // ==================================================
    echo "<div class='box'>";
    echo "<h2>4. Reset Password Admin</h2>";

    if ($reset_done) {
        echo "<div class='success-box'>";
        echo "<b>✅ {$msg}</b><br><br>";
        echo "Silakan login sekarang dengan:<br>";
        echo "• Email: <b>" . htmlspecialchars($admins[0]["email"] ?? "admin@portal.com") . "</b><br>";
        echo "• Username: <b>" . htmlspecialchars($admins[0]["username"] ?? "admin") . "</b><br>";
        echo "• Password: <b>" . htmlspecialchars($new_pass_plain) . "</b><br><br>";
        echo "<a href='/auth/login.php' style='color:#00d4aa;'>→ Ke Halaman Login</a>";
        echo "</div>";
    } elseif ($msg) {
        echo "<span class='err'>{$msg}</span><br><br>";
    }

    $admin_name = htmlspecialchars($admins[0]["username"] ?? "admin");
    echo "<p>Set password baru untuk admin: <b>{$admin_name}</b> (ID=1)</p>";
    echo "<form method='POST'>";
    echo "<input type='text' name='new_password' value='Admin@1234' required>";
    echo "<button type='submit' name='reset_password'>🔄 Reset Password</button>";
    echo "<br><small style='color:#888;margin-top:6px;display:block;'>Ubah nilai di atas sesuai password yang kamu inginkan, lalu klik Reset.</small>";
    echo "</form>";
    echo "</div>";

} catch (PDOException $e) {
    $err = $e->getMessage();
    echo "<span class='err'>❌ Koneksi GAGAL!</span><br><br>";
    echo "<b>Pesan Error:</b> " . htmlspecialchars($err) . "<br><br>";
    if (strpos($err, "Access denied") !== false)
        echo "<span class='warn'>→ Penyebab: Username/Password database salah, atau user tidak punya privilege di Hostinger.</span><br>";
    elseif (strpos($err, "Unknown database") !== false)
        echo "<span class='warn'>→ Penyebab: Database '{$DB_NAME}' belum ada atau nama salah. Cek di Hostinger cPanel → MySQL Databases.</span><br>";
    elseif (strpos($err, "refused") !== false || strpos($err, "network") !== false)
        echo "<span class='warn'>→ Penyebab: Host database tidak bisa dijangkau.</span><br>";
    echo "</div>";
}

// ==================================================
// 5. CEK config.env
// ==================================================
echo "<div class='box'>";
echo "<h2>5. Isi config.env yang Terbaca Server</h2>";
$env_path = __DIR__ . "/config.env";
if (file_exists($env_path)) {
    $lines = file($env_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    echo "<span class='ok'>✅ config.env ditemukan di: " . htmlspecialchars($env_path) . "</span><br><br>";
    echo "<table><thead><tr><th>Key</th><th>Value</th></tr></thead><tbody>";
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === "" || $line[0] === "#") continue;
        if (strpos($line, "=") === false) continue;
        list($k, $v) = explode("=", $line, 2);
        $k = trim($k); $v = trim($v);
        if (in_array($k, ["DB_PASS", "API_KEY", "ENCRYPTION_KEY"])) {
            $display = empty($v) ? "<span class='err'>❌ KOSONG!</span>" : "<span class='ok'>✅ Ada (disembunyikan)</span>";
        } else {
            $display = "<code>" . htmlspecialchars($v) . "</code>";
        }
        echo "<tr><td><b>{$k}</b></td><td>{$display}</td></tr>";
    }
    echo "</tbody></table>";
} else {
    echo "<span class='err'>❌ config.env TIDAK ADA di root folder server!</span><br>";
    echo "<span class='warn'>→ config.env harus di-upload manual ke server karena tidak termasuk dalam git (ada di .gitignore).</span>";
}
echo "</div>";
?>

<div class="box box-danger">
    <h2>⚠️ HAPUS FILE INI SETELAH SELESAI!</h2>
    <p>File ini membuka informasi sensitif. Hapus <code>debug_login.php</code> dari server segera setelah kamu berhasil login!</p>
</div>

</body>
</html>
