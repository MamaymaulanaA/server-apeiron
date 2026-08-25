<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Settings';
$show_header = true;

$success_message = '';
$error_message = '';

// Get current tab
$current_tab = $_GET['tab'] ?? 'general';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save_settings';
    
    if ($action === 'save_settings') {
        $settings = $_POST['settings'] ?? [];
        
        try {
            foreach ($settings as $key => $value) {
                $type = 'string';
                
                // Determine type
                if (in_array($key, ['default_expiration_days', 'default_activation_limit', 'max_login_attempts', 'session_timeout', 'rate_limit_requests', 'rate_limit_window', 'smtp_port', 'notify_license_expiring_days'])) {
                    $type = 'integer';
                } elseif (in_array($key, ['enable_api_logging', 'enable_activity_logging', 'encryption_enabled', 'domain_lock_enabled', 'rate_limit_enabled', 'notification_enabled', 'notify_admin_on_activation'])) {
                    $type = 'boolean';
                }
                
                update_setting($key, $value, $type);
            }
            
            log_activity('update', 'settings', null, 'Updated settings');
            $_SESSION['success_message'] = 'Settings berhasil disimpan!';
            
            header('Location: settings.php?tab=' . $current_tab);
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        }
    }
    
    if ($action === 'save_email_settings') {
        try {
            $db = get_db_connection();
            
            $email_settings = [
                'smtp_host' => $_POST['smtp_host'] ?? '',
                'smtp_port' => $_POST['smtp_port'] ?? '587',
                'smtp_username' => $_POST['smtp_username'] ?? '',
                'smtp_password' => $_POST['smtp_password'] ?? '',
                'smtp_encryption' => $_POST['smtp_encryption'] ?? 'tls',
                'from_email' => $_POST['from_email'] ?? '',
                'from_name' => $_POST['from_name'] ?? '',
                'notification_enabled' => isset($_POST['notification_enabled']) ? 'true' : 'false',
                'notify_license_expiring_days' => $_POST['notify_license_expiring_days'] ?? '7',
                'notify_admin_on_activation' => isset($_POST['notify_admin_on_activation']) ? 'true' : 'false',
            ];
            
            foreach ($email_settings as $key => $value) {
                $stmt = $db->prepare("
                    INSERT INTO email_settings (setting_key, setting_value) 
                    VALUES (?, ?)
                    ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()
                ");
                $stmt->execute([$key, $value]);
            }
            
            log_activity('update', 'email_settings', null, 'Updated email settings');
            $_SESSION['success_message'] = 'Email settings berhasil disimpan!';
            
            header('Location: settings.php?tab=email');
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        }
    }
    
    if ($action === 'test_email') {
        try {
            $test_email = $_POST['test_email'] ?? '';
            
            if (!filter_var($test_email, FILTER_VALIDATE_EMAIL)) {
                throw new Exception('Email tidak valid');
            }
            
            // Simple test using mail()
            $subject = 'Test Email - ' . APP_NAME;
            $body = '
            <!DOCTYPE html>
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
                    .container { max-width: 500px; margin: 0 auto; background: #fff; border-radius: 10px; overflow: hidden; }
                    .header { background: linear-gradient(135deg, #00d4aa 0%, #00b894 100%); color: white; padding: 30px; text-align: center; }
                    .content { padding: 30px; }
                </style>
            </head>
            <body>
                <div class="container">
                    <div class="header">
                        <h1>✅ Test Email Berhasil!</h1>
                    </div>
                    <div class="content">
                        <p>Jika Anda menerima email ini, berarti konfigurasi email sudah benar.</p>
                        <p><strong>Server:</strong> ' . APP_NAME . '</p>
                        <p><strong>Waktu:</strong> ' . date('Y-m-d H:i:s') . '</p>
                    </div>
                </div>
            </body>
            </html>';
            
            $headers = [
                'MIME-Version: 1.0',
                'Content-type: text/html; charset=utf-8',
                'From: ' . APP_NAME . ' <noreply@localhost>'
            ];
            
            $sent = mail($test_email, $subject, $body, implode("\r\n", $headers));
            
            if ($sent) {
                $_SESSION['success_message'] = 'Test email berhasil dikirim ke ' . $test_email;
            } else {
                $_SESSION['error_message'] = 'Gagal mengirim email. Cek konfigurasi mail server.';
            }
            
            header('Location: settings.php?tab=email');
            exit;
        } catch (Exception $e) {
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
            header('Location: settings.php?tab=email');
            exit;
        }
    }
}

// Get all settings
$all_settings = [
    'app_name' => get_setting('app_name', APP_NAME),
    'default_expiration_days' => get_setting('default_expiration_days', 365),
    'default_activation_limit' => get_setting('default_activation_limit', 5),
    'enable_api_logging' => get_setting('enable_api_logging', true),
    'enable_activity_logging' => get_setting('enable_activity_logging', true),
    'max_login_attempts' => get_setting('max_login_attempts', 5),
    'session_timeout' => get_setting('session_timeout', 3600),
    'encryption_enabled' => get_setting('encryption_enabled', false),
    'domain_lock_enabled' => get_setting('domain_lock_enabled', true),
    'rate_limit_enabled' => get_setting('rate_limit_enabled', true),
    'rate_limit_requests' => get_setting('rate_limit_requests', 100),
    'rate_limit_window' => get_setting('rate_limit_window', 3600),
];

// Get email settings
$email_settings = [];
try {
    $db = get_db_connection();
    $stmt = $db->query("SELECT setting_key, setting_value FROM email_settings");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $email_settings[$row['setting_key']] = $row['setting_value'];
    }
} catch (Exception $e) {
    // Table might not exist yet
}

// Default email settings
$email_settings = array_merge([
    'smtp_host' => 'smtp.gmail.com',
    'smtp_port' => '587',
    'smtp_username' => '',
    'smtp_password' => '',
    'smtp_encryption' => 'tls',
    'from_email' => '',
    'from_name' => APP_NAME,
    'notification_enabled' => 'false',
    'notify_license_expiring_days' => '7',
    'notify_admin_on_activation' => 'true',
], $email_settings);

include '../includes/header.php';
?>

<style>
/* Settings Tabs */
.settings-tabs {
    display: flex;
    gap: 4px;
    margin-bottom: 24px;
    background: var(--bg);
    padding: 6px;
    border-radius: 12px;
    overflow-x: auto;
}

.settings-tab {
    padding: 12px 24px;
    background: transparent;
    border: none;
    border-radius: 8px;
    color: var(--text-muted);
    cursor: pointer;
    font-size: 14px;
    font-weight: 500;
    display: flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
    white-space: nowrap;
    text-decoration: none;
}

.settings-tab:hover {
    color: var(--text);
    background: rgba(255,255,255,0.05);
}

.settings-tab.active {
    background: var(--primary);
    color: #000;
}

.settings-tab i {
    font-size: 16px;
}

/* Settings Section */
.settings-section {
    background: var(--card-bg);
    border-radius: 16px;
    padding: 30px;
    margin-bottom: 24px;
    border: 1px solid var(--border);
}

.settings-section-title {
    font-size: 18px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 24px;
    padding-bottom: 16px;
    border-bottom: 1px solid var(--border);
    display: flex;
    align-items: center;
    gap: 10px;
}

.settings-section-title i {
    color: var(--primary);
}

/* Form Grid */
.settings-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
}

.settings-group {
    margin-bottom: 20px;
}

.settings-group label {
    display: block;
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    margin-bottom: 8px;
}

.settings-group .form-control {
    width: 100%;
}

.settings-group .form-text {
    font-size: 12px;
    color: var(--text-muted);
    margin-top: 6px;
}

/* Toggle Switch */
.toggle-group {
    display: flex;
    align-items: center;
    justify-content: space-between;
    padding: 16px;
    background: var(--bg);
    border-radius: 10px;
    margin-bottom: 12px;
}

.toggle-info {
    flex: 1;
}

.toggle-label {
    font-size: 14px;
    font-weight: 500;
    color: var(--text);
    margin-bottom: 4px;
}

.toggle-desc {
    font-size: 12px;
    color: var(--text-muted);
}

.toggle-switch {
    position: relative;
    width: 50px;
    height: 28px;
    flex-shrink: 0;
}

.toggle-switch input {
    opacity: 0;
    width: 0;
    height: 0;
}

.toggle-slider {
    position: absolute;
    cursor: pointer;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background-color: #333;
    transition: .3s;
    border-radius: 28px;
}

.toggle-slider:before {
    position: absolute;
    content: "";
    height: 22px;
    width: 22px;
    left: 3px;
    bottom: 3px;
    background-color: white;
    transition: .3s;
    border-radius: 50%;
}

.toggle-switch input:checked + .toggle-slider {
    background-color: var(--primary);
}

.toggle-switch input:checked + .toggle-slider:before {
    transform: translateX(22px);
}

/* Test Email Box */
.test-email-box {
    background: linear-gradient(135deg, rgba(0,212,170,0.1) 0%, rgba(0,184,148,0.1) 100%);
    border: 1px solid rgba(0,212,170,0.3);
    border-radius: 12px;
    padding: 20px;
    margin-top: 24px;
}

.test-email-box h4 {
    color: var(--primary);
    margin-bottom: 12px;
    font-size: 14px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.test-email-form {
    display: flex;
    gap: 12px;
}

.test-email-form input {
    flex: 1;
}

/* Info Box */
.info-box {
    background: rgba(102,126,234,0.1);
    border: 1px solid rgba(102,126,234,0.3);
    border-radius: 10px;
    padding: 16px;
    margin-bottom: 20px;
    display: flex;
    gap: 12px;
    align-items: flex-start;
}

.info-box i {
    color: #667eea;
    font-size: 18px;
    margin-top: 2px;
}

.info-box-content {
    flex: 1;
}

.info-box-content h4 {
    font-size: 14px;
    font-weight: 600;
    color: var(--text);
    margin-bottom: 4px;
}

.info-box-content p {
    font-size: 13px;
    color: var(--text-muted);
    margin: 0;
}

/* Responsive */
@media (max-width: 768px) {
    .settings-tabs {
        flex-wrap: nowrap;
        padding: 4px;
    }
    
    .settings-tab {
        padding: 10px 16px;
        font-size: 13px;
    }
    
    .settings-section {
        padding: 20px;
    }
    
    .settings-grid {
        grid-template-columns: 1fr;
    }
    
    .test-email-form {
        flex-direction: column;
    }
}
</style>

<div class="card" style="background: transparent; border: none; box-shadow: none; padding: 0;">
    <div class="card-header" style="background: transparent; border: none; padding: 0 0 20px 0;">
        <h1 class="card-title" style="font-size: 28px;">
            <i class="fas fa-cog"></i> Settings
        </h1>
    </div>
    
    <!-- Tabs -->
    <div class="settings-tabs">
        <a href="?tab=general" class="settings-tab <?= $current_tab === 'general' ? 'active' : '' ?>">
            <i class="fas fa-sliders-h"></i> General
        </a>
        <a href="?tab=license" class="settings-tab <?= $current_tab === 'license' ? 'active' : '' ?>">
            <i class="fas fa-id-card"></i> License
        </a>
        <a href="?tab=security" class="settings-tab <?= $current_tab === 'security' ? 'active' : '' ?>">
            <i class="fas fa-shield-alt"></i> Security
        </a>
        <a href="?tab=email" class="settings-tab <?= $current_tab === 'email' ? 'active' : '' ?>">
            <i class="fas fa-envelope"></i> Email
        </a>
        <a href="?tab=api" class="settings-tab <?= $current_tab === 'api' ? 'active' : '' ?>">
            <i class="fas fa-code"></i> API
        </a>
    </div>
    
    <!-- General Settings -->
    <?php if ($current_tab === 'general'): ?>
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        
        <div class="settings-section">
            <h3 class="settings-section-title">
                <i class="fas fa-cog"></i> General Settings
            </h3>
            
            <div class="settings-grid">
                <div class="settings-group">
                    <label>Application Name</label>
                    <input type="text" name="settings[app_name]" class="form-control" 
                           value="<?= htmlspecialchars($all_settings['app_name']) ?>" required>
                    <div class="form-text">Nama yang ditampilkan di header dan email</div>
                </div>
            </div>
            
            <div class="toggle-group">
                <div class="toggle-info">
                    <div class="toggle-label">Enable API Logging</div>
                    <div class="toggle-desc">Log semua request API untuk monitoring dan debugging</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="settings[enable_api_logging]" value="1" 
                           <?= $all_settings['enable_api_logging'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            
            <div class="toggle-group">
                <div class="toggle-info">
                    <div class="toggle-label">Enable Activity Logging</div>
                    <div class="toggle-desc">Log semua aktivitas admin (create, update, delete)</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="settings[enable_activity_logging]" value="1" 
                           <?= $all_settings['enable_activity_logging'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Save Settings
        </button>
    </form>
    <?php endif; ?>
    
    <!-- License Settings -->
    <?php if ($current_tab === 'license'): ?>
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        
        <div class="settings-section">
            <h3 class="settings-section-title">
                <i class="fas fa-id-card"></i> License Defaults
            </h3>
            
            <div class="settings-grid">
                <div class="settings-group">
                    <label>Default Expiration (Days)</label>
                    <input type="number" name="settings[default_expiration_days]" class="form-control" 
                           value="<?= $all_settings['default_expiration_days'] ?>" min="0" required>
                    <div class="form-text">Masa berlaku default untuk lisensi baru (0 = tanpa batas)</div>
                </div>
                
                <div class="settings-group">
                    <label>Default Activation Limit</label>
                    <input type="number" name="settings[default_activation_limit]" class="form-control" 
                           value="<?= $all_settings['default_activation_limit'] ?>" min="0" required>
                    <div class="form-text">Batas aktivasi default untuk lisensi baru (0 = unlimited)</div>
                </div>
            </div>
            
            <div class="toggle-group">
                <div class="toggle-info">
                    <div class="toggle-label">Domain Lock</div>
                    <div class="toggle-desc">Kunci lisensi ke domain tertentu saat aktivasi</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="settings[domain_lock_enabled]" value="1" 
                           <?= $all_settings['domain_lock_enabled'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            
            <div class="toggle-group">
                <div class="toggle-info">
                    <div class="toggle-label">Encryption</div>
                    <div class="toggle-desc">Enkripsi data sensitif di database</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="settings[encryption_enabled]" value="1" 
                           <?= $all_settings['encryption_enabled'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Save Settings
        </button>
    </form>
    <?php endif; ?>
    
    <!-- Security Settings -->
    <?php if ($current_tab === 'security'): ?>
    <form method="POST">
        <input type="hidden" name="action" value="save_settings">
        
        <div class="settings-section">
            <h3 class="settings-section-title">
                <i class="fas fa-shield-alt"></i> Security Settings
            </h3>
            
            <div class="settings-grid">
                <div class="settings-group">
                    <label>Max Login Attempts</label>
                    <input type="number" name="settings[max_login_attempts]" class="form-control" 
                           value="<?= $all_settings['max_login_attempts'] ?>" min="1" max="10" required>
                    <div class="form-text">Jumlah percobaan login sebelum akun dikunci</div>
                </div>
                
                <div class="settings-group">
                    <label>Session Timeout (Detik)</label>
                    <input type="number" name="settings[session_timeout]" class="form-control" 
                           value="<?= $all_settings['session_timeout'] ?>" min="300" required>
                    <div class="form-text">Waktu timeout session (300 = 5 menit, 3600 = 1 jam)</div>
                </div>
            </div>
            
            <div class="toggle-group">
                <div class="toggle-info">
                    <div class="toggle-label">Rate Limiting</div>
                    <div class="toggle-desc">Batasi jumlah request API per IP address</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="settings[rate_limit_enabled]" value="1" 
                           <?= $all_settings['rate_limit_enabled'] ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            
            <div class="settings-grid" style="margin-top: 20px;">
                <div class="settings-group">
                    <label>Rate Limit (Requests)</label>
                    <input type="number" name="settings[rate_limit_requests]" class="form-control" 
                           value="<?= $all_settings['rate_limit_requests'] ?>" min="10" required>
                    <div class="form-text">Maksimum request per window</div>
                </div>
                
                <div class="settings-group">
                    <label>Rate Limit Window (Detik)</label>
                    <input type="number" name="settings[rate_limit_window]" class="form-control" 
                           value="<?= $all_settings['rate_limit_window'] ?>" min="60" required>
                    <div class="form-text">Durasi window rate limit</div>
                </div>
            </div>
        </div>
        
        <button type="submit" class="btn btn-primary btn-lg">
            <i class="fas fa-save"></i> Save Settings
        </button>
    </form>
    <?php endif; ?>
    
    <!-- Email Settings -->
    <?php if ($current_tab === 'email'): ?>
    <form method="POST">
        <input type="hidden" name="action" value="save_email_settings">
        
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div class="info-box-content">
                <h4>Konfigurasi Email</h4>
                <p>Setting SMTP diperlukan untuk mengirim notifikasi email. Jika menggunakan Gmail, aktifkan "App Password" di pengaturan Google Account.</p>
            </div>
        </div>
        
        <div class="settings-section">
            <h3 class="settings-section-title">
                <i class="fas fa-server"></i> SMTP Configuration
            </h3>
            
            <div class="settings-grid">
                <div class="settings-group">
                    <label>SMTP Host</label>
                    <input type="text" name="smtp_host" class="form-control" 
                           value="<?= htmlspecialchars($email_settings['smtp_host']) ?>" 
                           placeholder="smtp.gmail.com">
                    <div class="form-text">Contoh: smtp.gmail.com, smtp.mailgun.org</div>
                </div>
                
                <div class="settings-group">
                    <label>SMTP Port</label>
                    <input type="number" name="smtp_port" class="form-control" 
                           value="<?= htmlspecialchars($email_settings['smtp_port']) ?>" 
                           placeholder="587">
                    <div class="form-text">Port umum: 587 (TLS), 465 (SSL), 25</div>
                </div>
                
                <div class="settings-group">
                    <label>SMTP Username</label>
                    <input type="text" name="smtp_username" class="form-control" 
                           value="<?= htmlspecialchars($email_settings['smtp_username']) ?>" 
                           placeholder="your-email@gmail.com">
                </div>
                
                <div class="settings-group">
                    <label>SMTP Password</label>
                    <input type="password" name="smtp_password" class="form-control" 
                           value="<?= htmlspecialchars($email_settings['smtp_password']) ?>" 
                           placeholder="••••••••">
                    <div class="form-text">Untuk Gmail, gunakan App Password</div>
                </div>
                
                <div class="settings-group">
                    <label>Encryption</label>
                    <select name="smtp_encryption" class="form-control">
                        <option value="tls" <?= $email_settings['smtp_encryption'] === 'tls' ? 'selected' : '' ?>>TLS</option>
                        <option value="ssl" <?= $email_settings['smtp_encryption'] === 'ssl' ? 'selected' : '' ?>>SSL</option>
                        <option value="none" <?= $email_settings['smtp_encryption'] === 'none' ? 'selected' : '' ?>>None</option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="settings-section">
            <h3 class="settings-section-title">
                <i class="fas fa-user-circle"></i> Sender Information
            </h3>
            
            <div class="settings-grid">
                <div class="settings-group">
                    <label>From Email</label>
                    <input type="email" name="from_email" class="form-control" 
                           value="<?= htmlspecialchars($email_settings['from_email']) ?>" 
                           placeholder="noreply@yourdomain.com">
                    <div class="form-text">Alamat email pengirim</div>
                </div>
                
                <div class="settings-group">
                    <label>From Name</label>
                    <input type="text" name="from_name" class="form-control" 
                           value="<?= htmlspecialchars($email_settings['from_name']) ?>" 
                           placeholder="<?= APP_NAME ?>">
                    <div class="form-text">Nama yang muncul sebagai pengirim</div>
                </div>
            </div>
        </div>
        
        <div class="settings-section">
            <h3 class="settings-section-title">
                <i class="fas fa-bell"></i> Notification Settings
            </h3>
            
            <div class="toggle-group">
                <div class="toggle-info">
                    <div class="toggle-label">Enable Email Notifications</div>
                    <div class="toggle-desc">Aktifkan sistem notifikasi email</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="notification_enabled" value="1" 
                           <?= $email_settings['notification_enabled'] === 'true' ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            
            <div class="toggle-group">
                <div class="toggle-info">
                    <div class="toggle-label">Notify Admin on Activation</div>
                    <div class="toggle-desc">Kirim notifikasi ke admin saat ada lisensi baru diaktifkan</div>
                </div>
                <label class="toggle-switch">
                    <input type="checkbox" name="notify_admin_on_activation" value="1" 
                           <?= $email_settings['notify_admin_on_activation'] === 'true' ? 'checked' : '' ?>>
                    <span class="toggle-slider"></span>
                </label>
            </div>
            
            <div class="settings-grid" style="margin-top: 20px;">
                <div class="settings-group">
                    <label>License Expiry Warning (Days)</label>
                    <input type="number" name="notify_license_expiring_days" class="form-control" 
                           value="<?= htmlspecialchars($email_settings['notify_license_expiring_days']) ?>" 
                           min="1" max="30">
                    <div class="form-text">Kirim notifikasi X hari sebelum lisensi expired</div>
                </div>
            </div>
        </div>
        
        <div class="test-email-box">
            <h4><i class="fas fa-paper-plane"></i> Test Email</h4>
            <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 12px;">
                Kirim test email untuk memastikan konfigurasi sudah benar
            </p>
            <div class="test-email-form">
                <input type="email" name="test_email" class="form-control" 
                       placeholder="masukkan-email@test.com" required>
                <button type="submit" name="action" value="test_email" class="btn btn-secondary">
                    <i class="fas fa-paper-plane"></i> Send Test
                </button>
            </div>
        </div>
        
        <div style="margin-top: 24px;">
            <button type="submit" class="btn btn-primary btn-lg">
                <i class="fas fa-save"></i> Save Email Settings
            </button>
        </div>
    </form>
    <?php endif; ?>
    
    <!-- API Settings -->
    <?php if ($current_tab === 'api'): ?>
    <div class="settings-section">
        <h3 class="settings-section-title">
            <i class="fas fa-code"></i> API Information
        </h3>
        
        <div class="info-box">
            <i class="fas fa-info-circle"></i>
            <div class="info-box-content">
                <h4>API Endpoints</h4>
                <p>Berikut adalah daftar endpoint API yang tersedia</p>
            </div>
        </div>
        
        <div style="background: var(--bg); border-radius: 10px; padding: 20px; margin-bottom: 20px;">
            <h4 style="color: var(--primary); margin-bottom: 15px; font-size: 14px;">
                <i class="fas fa-plug"></i> Activate License
            </h4>
            <code style="display: block; background: #1a1a2e; padding: 15px; border-radius: 8px; color: #00d4aa; font-size: 13px;">
                POST <?= rtrim(BASE_URL, '/') ?>/api/activate.php
            </code>
            <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">
                Parameters: license_key, domain, product_id
            </div>
        </div>
        
        <div style="background: var(--bg); border-radius: 10px; padding: 20px; margin-bottom: 20px;">
            <h4 style="color: var(--primary); margin-bottom: 15px; font-size: 14px;">
                <i class="fas fa-unlink"></i> Deactivate License
            </h4>
            <code style="display: block; background: #1a1a2e; padding: 15px; border-radius: 8px; color: #00d4aa; font-size: 13px;">
                POST <?= rtrim(BASE_URL, '/') ?>/api/deactivate.php
            </code>
            <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">
                Parameters: license_key, domain
            </div>
        </div>
        
        <div style="background: var(--bg); border-radius: 10px; padding: 20px; margin-bottom: 20px;">
            <h4 style="color: var(--primary); margin-bottom: 15px; font-size: 14px;">
                <i class="fas fa-search"></i> Check License
            </h4>
            <code style="display: block; background: #1a1a2e; padding: 15px; border-radius: 8px; color: #00d4aa; font-size: 13px;">
                POST <?= rtrim(BASE_URL, '/') ?>/api/check.php
            </code>
            <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">
                Parameters: license_key, domain
            </div>
        </div>
        
        <div style="background: var(--bg); border-radius: 10px; padding: 20px;">
            <h4 style="color: var(--primary); margin-bottom: 15px; font-size: 14px;">
                <i class="fas fa-heartbeat"></i> Health Check
            </h4>
            <code style="display: block; background: #1a1a2e; padding: 15px; border-radius: 8px; color: #00d4aa; font-size: 13px;">
                GET <?= rtrim(BASE_URL, '/') ?>/api/health.php
            </code>
            <div style="margin-top: 10px; font-size: 12px; color: var(--text-muted);">
                No parameters required
            </div>
        </div>
    </div>
    
    <div class="settings-section">
        <h3 class="settings-section-title">
            <i class="fas fa-key"></i> API Keys
        </h3>
        
        <p style="color: var(--text-muted); margin-bottom: 20px;">
            Kelola API keys untuk autentikasi dari aplikasi eksternal.
        </p>
        
        <a href="api-keys.php" class="btn btn-primary">
            <i class="fas fa-key"></i> Manage API Keys
        </a>
    </div>
    <?php endif; ?>
</div>

<?php include '../includes/footer.php'; ?>
