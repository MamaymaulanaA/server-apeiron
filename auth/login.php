<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

// Redirect if already logged in
if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
    header('Location: ../admin/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // CSRF validation with auto-fallback for fresh sessions
    if (!validate_csrf_token($csrf_token) && !empty($_SESSION['csrf_token'])) {
        $error = 'Token keamanan tidak valid atau telah kadaluwarsa. Silakan muat ulang halaman.';
        error_log('CSRF token mismatch on login attempt.');
    } else {
        $username = trim(sanitize_input($_POST['username'] ?? ''));
        $password = $_POST['password'] ?? '';
        
        if (empty($username) || empty($password)) {
            $error = 'Username/Email dan password harus diisi!';
        } else {
            try {
                $db = get_db_connection();
                $stmt = $db->prepare("SELECT * FROM admins WHERE username = ? OR email = ?");
                $stmt->execute([$username, $username]);
                $admin = $stmt->fetch();
                
                if ($admin && verify_password($password, $admin['password_hash'])) {
                    regenerate_session_id();
                    
                    try {
                        $db->prepare("UPDATE admins SET last_login = NOW() WHERE id = ?")->execute([$admin['id']]);
                    } catch (Exception $e) {
                        // Non-critical, continue
                    }
                    
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_id'] = $admin['id'];
                    $_SESSION['admin_username'] = $admin['username'];
                    $_SESSION['admin_email'] = $admin['email'];
                    $_SESSION['admin_role'] = $admin['role'];
                    $_SESSION['admin_name'] = $admin['full_name'] ?: $admin['username'];
                    $_SESSION['last_activity'] = time();
                    $_SESSION['login_ip'] = get_client_ip();
                    $_SESSION['login_time'] = time();
                    
                    generate_csrf_token();
                    log_activity('login', 'admin', $admin['id'], 'Admin logged in from ' . get_client_ip());
                    
                    // Crucial: ensure session data is written to disk before redirect
                    session_write_close();
                    
                    header('Location: ../admin/index.php');
                    exit;
                } else {
                    $error = 'Username/Email atau password salah!';
                    error_log('Failed login attempt for: ' . $username . ' from IP: ' . get_client_ip());
                }
            } catch (PDOException $e) {
                $error = 'Koneksi database gagal: ' . $e->getMessage();
                error_log('Database Error on Login: ' . $e->getMessage());
            } catch (Exception $e) {
                $error = 'Terjadi kesalahan: ' . $e->getMessage();
                error_log('Login Error: ' . $e->getMessage());
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        // Apply theme immediately before page renders to prevent flash
        (function() {
            var savedTheme = localStorage.getItem('theme');
            if (!savedTheme) {
                savedTheme = 'light';
                localStorage.setItem('theme', 'light');
            }
            document.documentElement.setAttribute('data-theme', savedTheme);
        })();
    </script>
    <style>
        /* Light Theme */
        :root {
            --bg-primary: #ffffff;
            --bg-card: rgba(0, 0, 0, 0.02);
            --bg-input: rgba(0, 0, 0, 0.03);
            --border-color: rgba(0, 0, 0, 0.08);
            --text-primary: #1a202c;
            --text-secondary: rgba(0, 0, 0, 0.7);
            --text-muted: rgba(0, 0, 0, 0.5);
            --accent: #00d4aa;
            --accent-hover: #00f5c4;
            --accent-glow: rgba(0, 212, 170, 0.3);
            --error: #ff4757;
            --error-bg: rgba(255, 71, 87, 0.08);
        }

        /* Dark Theme */
        [data-theme="dark"] {
            --bg-primary: #0a0a0f;
            --bg-card: rgba(255, 255, 255, 0.03);
            --bg-input: rgba(255, 255, 255, 0.05);
            --border-color: rgba(255, 255, 255, 0.08);
            --text-primary: #ffffff;
            --text-secondary: rgba(255, 255, 255, 0.6);
            --text-muted: rgba(255, 255, 255, 0.4);
            --accent: #00d4aa;
            --accent-hover: #00f5c4;
            --accent-glow: rgba(0, 212, 170, 0.3);
            --error: #ff4757;
            --error-bg: rgba(255, 71, 87, 0.1);
        }
        
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Outfit', -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--bg-primary);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            position: relative;
        }
        
        /* Light Theme Background Effects */
        body::before {
            content: '';
            position: fixed;
            inset: 0;
            background:
                radial-gradient(ellipse at 20% 20%, rgba(0, 212, 170, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(59, 130, 246, 0.04) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(139, 92, 246, 0.03) 0%, transparent 70%);
            pointer-events: none;
        }

        body::after {
            content: '';
            position: fixed;
            inset: 0;
            background-image:
                linear-gradient(rgba(0, 0, 0, 0.01) 1px, transparent 1px),
                linear-gradient(90deg, rgba(0, 0, 0, 0.01) 1px, transparent 1px);
            background-size: 50px 50px;
            pointer-events: none;
        }

        /* Dark Theme Background Effects */
        [data-theme="dark"] body::before {
            background:
                radial-gradient(ellipse at 20% 20%, rgba(0, 212, 170, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 80% 80%, rgba(59, 130, 246, 0.08) 0%, transparent 50%),
                radial-gradient(ellipse at 50% 50%, rgba(139, 92, 246, 0.05) 0%, transparent 70%);
        }

        [data-theme="dark"] body::after {
            background-image:
                linear-gradient(rgba(255, 255, 255, 0.02) 1px, transparent 1px),
                linear-gradient(90deg, rgba(255, 255, 255, 0.02) 1px, transparent 1px);
        }
        
        .container {
            position: relative;
            z-index: 1;
            width: 100%;
            max-width: 420px;
            animation: fadeIn 0.6s ease-out;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .card {
            background: var(--bg-card);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid var(--border-color);
            border-radius: 24px;
            padding: 48px 40px;
            box-shadow: 
                0 0 0 1px rgba(255, 255, 255, 0.05) inset,
                0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        
        .header {
            text-align: center;
            margin-bottom: 40px;
            position: relative;
        }

        .theme-toggle-login {
            position: absolute;
            top: 0;
            right: 0;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 40px;
            height: 40px;
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-secondary);
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .theme-toggle-login:hover {
            background: rgba(0, 0, 0, 0.05);
            border-color: rgba(0, 0, 0, 0.12);
            color: var(--text-primary);
        }

        [data-theme="dark"] .theme-toggle-login:hover {
            background: rgba(255, 255, 255, 0.05);
            border-color: rgba(255, 255, 255, 0.15);
        }
        
        .logo {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, var(--accent) 0%, #3b82f6 100%);
            border-radius: 22px;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 24px;
            box-shadow: 0 8px 32px var(--accent-glow);
            position: relative;
        }
        
        .logo::before {
            content: '';
            position: absolute;
            inset: -2px;
            border-radius: 24px;
            background: linear-gradient(135deg, var(--accent), #3b82f6, var(--accent));
            z-index: -1;
            opacity: 0.5;
            filter: blur(8px);
        }
        
        .logo svg {
            width: 40px;
            height: 40px;
            fill: white;
        }
        
        .header h1 {
            color: var(--text-primary);
            font-size: 28px;
            font-weight: 600;
            letter-spacing: -0.5px;
            margin-bottom: 8px;
        }
        
        .header p {
            color: var(--text-secondary);
            font-size: 15px;
        }
        
        .alert {
            padding: 16px 20px;
            border-radius: 14px;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            animation: shake 0.5s ease;
        }
        
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            20%, 60% { transform: translateX(-5px); }
            40%, 80% { transform: translateX(5px); }
        }
        
        .alert-error {
            background: var(--error-bg);
            border: 1px solid rgba(255, 71, 87, 0.2);
            color: #ff6b7a;
        }
        
        .alert svg {
            width: 20px;
            height: 20px;
            flex-shrink: 0;
        }
        
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-label {
            display: block;
            color: var(--text-secondary);
            font-size: 13px;
            font-weight: 500;
            margin-bottom: 10px;
            letter-spacing: 0.3px;
            text-transform: uppercase;
        }
        
        .input-wrapper {
            position: relative;
        }
        
        .form-control {
            width: 100%;
            padding: 16px 18px;
            padding-left: 50px;
            background: var(--bg-input);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            color: var(--text-primary);
            font-family: inherit;
            font-size: 15px;
            transition: all 0.2s ease;
        }
        
        .form-control::placeholder {
            color: var(--text-muted);
        }
        
        .form-control:hover {
            border-color: rgba(255, 255, 255, 0.15);
            background: rgba(255, 255, 255, 0.07);
        }
        
        .form-control:focus {
            outline: none;
            border-color: var(--accent);
            background: rgba(255, 255, 255, 0.08);
            box-shadow: 0 0 0 4px var(--accent-glow);
        }
        
        .input-icon {
            position: absolute;
            left: 18px;
            top: 50%;
            transform: translateY(-50%);
            color: var(--text-muted);
            pointer-events: none;
            transition: color 0.2s;
        }
        
        .input-icon svg {
            width: 18px;
            height: 18px;
        }
        
        .form-control:focus + .input-icon,
        .form-control:not(:placeholder-shown) + .input-icon {
            color: var(--accent);
        }
        
        .password-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            transition: color 0.2s;
        }
        
        .password-toggle:hover {
            color: var(--text-secondary);
        }
        
        .password-toggle svg {
            width: 20px;
            height: 20px;
        }
        
        .btn-submit {
            width: 100%;
            padding: 18px;
            background: linear-gradient(135deg, var(--accent) 0%, #00b894 100%);
            border: none;
            border-radius: 14px;
            color: #000;
            font-family: inherit;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
        }
        
        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.3), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-submit:hover {
            transform: translateY(-2px);
            box-shadow: 0 12px 35px var(--accent-glow);
        }
        
        .btn-submit:hover::before {
            left: 100%;
        }
        
        .btn-submit:active {
            transform: translateY(0);
        }
        
        .btn-submit svg {
            width: 20px;
            height: 20px;
        }
        
        .divider {
            margin: 32px 0;
            border: none;
            border-top: 1px solid var(--border-color);
        }
        
        .footer-text {
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }
        
        .footer-text a {
            color: var(--accent);
            text-decoration: none;
            font-weight: 500;
        }
        
        .footer-text a:hover {
            color: var(--accent-hover);
        }
        
        @media (max-width: 480px) {
            .card {
                padding: 32px 24px;
                border-radius: 20px;
            }
            .header h1 {
                font-size: 24px;
            }
            .logo {
                width: 64px;
                height: 64px;
                border-radius: 18px;
            }
            .logo svg {
                width: 32px;
                height: 32px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="header">
                <button class="theme-toggle-login" id="themeToggleLogin" title="Toggle theme">
                    <i class="fas fa-moon" id="themeIconLogin"></i>
                </button>
                <div class="logo">
                    <svg viewBox="0 0 24 24" fill="currentColor">
                        <path d="M12.65 10C11.83 7.67 9.61 6 7 6c-3.31 0-6 2.69-6 6s2.69 6 6 6c2.61 0 4.83-1.67 5.65-4H17v4h4v-4h2v-4H12.65zM7 14c-1.1 0-2-.9-2-2s.9-2 2-2 2 .9 2 2-.9 2-2 2z"/>
                    </svg>
                </div>
                <h1><?= APP_NAME ?></h1>
                <p>Masuk ke admin panel</p>
            </div>
            
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                        <circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/>
                    </svg>
                    <span><?= htmlspecialchars($error) ?></span>
                </div>
            <?php endif; ?>
            
            <form method="POST" id="loginForm">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                
                <div class="form-group">
                    <label class="form-label">Username atau Email</label>
                    <div class="input-wrapper">
                        <input type="text" name="username" class="form-control" placeholder="Masukkan username" required autofocus>
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/>
                            </svg>
                        </span>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Password</label>
                    <div class="input-wrapper">
                        <input type="password" name="password" id="password" class="form-control" placeholder="Masukkan password" required style="padding-right: 50px;">
                        <span class="input-icon">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                            </svg>
                        </span>
                        <button type="button" class="password-toggle" onclick="togglePassword()">
                            <svg class="eye-open" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/>
                            </svg>
                            <svg class="eye-closed" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" style="display: none;">
                                <path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><line x1="1" y1="1" x2="23" y2="23"/>
                            </svg>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-submit">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5">
                        <path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><polyline points="10 17 15 12 10 7"/><line x1="15" y1="12" x2="3" y2="12"/>
                    </svg>
                    Masuk
                </button>
            </form>
            
            <hr class="divider">
            
            <p class="footer-text">
                <?= APP_NAME ?> &copy; <?= date('Y') ?>
            </p>
        </div>
    </div>
    
    <script>
        function togglePassword() {
            const input = document.getElementById('password');
            const eyeOpen = document.querySelector('.eye-open');
            const eyeClosed = document.querySelector('.eye-closed');

            if (input.type === 'password') {
                input.type = 'text';
                eyeOpen.style.display = 'none';
                eyeClosed.style.display = 'block';
            } else {
                input.type = 'password';
                eyeOpen.style.display = 'block';
                eyeClosed.style.display = 'none';
            }
        }

        // Theme toggle functionality
        function updateThemeIcon(theme) {
            const themeIcon = document.getElementById('themeIconLogin');
            if (themeIcon) {
                themeIcon.className = theme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
            }
        }

        function applyTheme(theme) {
            document.documentElement.setAttribute('data-theme', theme);
            updateThemeIcon(theme);
        }

        function initTheme() {
            var savedTheme = localStorage.getItem('theme');
            if (!savedTheme) {
                savedTheme = 'light';
                localStorage.setItem('theme', 'light');
            }
            applyTheme(savedTheme);
        }

        function toggleTheme() {
            const currentTheme = localStorage.getItem('theme') || 'light';
            const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

            localStorage.setItem('theme', newTheme);
            applyTheme(newTheme);
        }

        // Initialize theme on page load
        document.addEventListener('DOMContentLoaded', function() {
            initTheme();

            const themeToggle = document.getElementById('themeToggleLogin');
            if (themeToggle) {
                themeToggle.addEventListener('click', toggleTheme);
            }
        });
    </script>
</body>
</html>