<?php
if (!isset($page_title)) $page_title = 'Dashboard';
if (!isset($show_header)) $show_header = true;

$is_admin = strpos($_SERVER['SCRIPT_NAME'] ?? '', '/admin/') !== false;
$base_path = $is_admin ? '../' : '';
$admin_path = $is_admin ? '' : 'admin/';
$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title><?= htmlspecialchars($page_title) ?> — <?= APP_NAME ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= $base_path ?>assets/css/style.css">
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
</head>
<body>
    <?php if ($show_header): ?>
    <nav class="navbar">
        <div class="nav-container">
            <a href="<?= $admin_path ?>index.php" class="nav-brand">
                <i class="fas fa-bolt"></i>
                <span><?= APP_NAME ?></span>
            </a>
            
            <button class="nav-burger" id="navBurger" aria-label="Toggle menu">
                <span></span>
                <span></span>
                <span></span>
            </button>
            
            <div class="nav-menu" id="navMenu">
                <div class="nav-menu-main">
                    <a href="<?= $admin_path ?>index.php" class="nav-link <?= $current_page === 'index.php' ? 'active' : '' ?>">
                        <i class="fas fa-th-large"></i> <span>Dashboard</span>
                    </a>
                    <a href="<?= $admin_path ?>licenses.php" class="nav-link <?= $current_page === 'licenses.php' ? 'active' : '' ?>">
                        <i class="fas fa-id-card"></i> <span>Licenses</span>
                    </a>
                    <a href="<?= $admin_path ?>generate.php" class="nav-link <?= $current_page === 'generate.php' ? 'active' : '' ?>">
                        <i class="fas fa-plus-circle"></i> <span>Generate</span>
                    </a>
                    <a href="<?= $admin_path ?>analytics.php" class="nav-link <?= $current_page === 'analytics.php' ? 'active' : '' ?>">
                        <i class="fas fa-chart-bar"></i> <span>Analytics</span>
                    </a>
                    <a href="<?= $admin_path ?>activations.php" class="nav-link <?= $current_page === 'activations.php' ? 'active' : '' ?>">
                        <i class="fas fa-plug"></i> <span>Activations</span>
                    </a>
                    <a href="<?= $admin_path ?>api-keys.php" class="nav-link <?= $current_page === 'api-keys.php' ? 'active' : '' ?>">
                        <i class="fas fa-key"></i> <span>API Keys</span>
                    </a>
                </div>
                
                <div class="nav-more-dropdown" id="navMoreDropdown">
                    <button class="nav-link nav-more-btn" id="navMoreBtn" type="button">
                        <i class="fas fa-ellipsis-h"></i> <span>More</span>
                        <i class="fas fa-chevron-down nav-more-arrow"></i>
                    </button>
                    <div class="nav-more-content" id="navMoreContent">
                        <a href="<?= $admin_path ?>remote-deactivate.php" class="nav-link <?= $current_page === 'remote-deactivate.php' ? 'active' : '' ?>">
                            <i class="fas fa-power-off"></i> <span>Remote Deactivate</span>
                        </a>
                        <a href="<?= $admin_path ?>monitoring.php" class="nav-link <?= $current_page === 'monitoring.php' ? 'active' : '' ?>">
                            <i class="fas fa-heartbeat"></i> <span>Monitoring</span>
                        </a>
                        <a href="<?= $admin_path ?>logs.php" class="nav-link <?= $current_page === 'logs.php' ? 'active' : '' ?>">
                            <i class="fas fa-history"></i> <span>Activity Logs</span>
                        </a>
                        <a href="<?= $admin_path ?>settings.php" class="nav-link <?= $current_page === 'settings.php' ? 'active' : '' ?>">
                            <i class="fas fa-cog"></i> <span>Settings</span>
                        </a>
                        <?php if (isset($_SESSION['admin_role']) && $_SESSION['admin_role'] === 'super_admin'): ?>
                        <a href="<?= $admin_path ?>admins.php" class="nav-link <?= $current_page === 'admins.php' ? 'active' : '' ?>">
                            <i class="fas fa-users-cog"></i> <span>Manage Admins</span>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <div class="nav-user">
                <button class="btn-theme-toggle" id="themeToggle" title="Toggle theme" type="button" onclick="window.toggleThemeHandler && window.toggleThemeHandler(event)">
                    <i class="fas fa-moon" id="themeIcon"></i>
                </button>
                <script>
                    // Update icon immediately based on saved theme (same as login)
                    (function() {
                        const icon = document.getElementById('themeIcon');
                        if (icon) {
                            var savedTheme = localStorage.getItem('theme');
                            if (!savedTheme) {
                                savedTheme = 'light';
                            }
                            // Same icon logic as login: moon for light, sun for dark
                            icon.className = savedTheme === 'dark' ? 'fas fa-sun' : 'fas fa-moon';
                        }
                    })();
                </script>
                <span class="nav-username">
                    <i class="fas fa-user-circle" style="margin-right: 6px; opacity: 0.6;"></i>
                    <?= htmlspecialchars($_SESSION['admin_username'] ?? 'Admin') ?>
                </span>
                <a href="<?= $base_path ?>auth/logout.php" class="btn-logout">
                    <i class="fas fa-sign-out-alt"></i>
                    <span class="logout-text">Logout</span>
                </a>
            </div>
        </div>
    </nav>
    
    <script>
    // Theme toggle functionality - EXACTLY same as login page
    function updateThemeIcon(theme) {
        const themeIcon = document.getElementById('themeIcon');
        if (themeIcon) {
            // Same icon logic as login: moon for light mode, sun for dark mode
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

    function toggleTheme(e) {
        if (e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        const currentTheme = localStorage.getItem('theme') || 'light';
        const newTheme = currentTheme === 'dark' ? 'light' : 'dark';

        localStorage.setItem('theme', newTheme);
        applyTheme(newTheme);
        
        return false;
    }

    // Make toggleTheme available globally for inline onclick
    window.toggleThemeHandler = toggleTheme;

    // Initialize theme immediately (before DOM ready) to prevent flash
    initTheme();

    // Initialize theme on page load - EXACTLY same as login page
    document.addEventListener('DOMContentLoaded', function() {
        initTheme();

        // Multiple ways to attach event listener for reliability
        const themeToggle = document.getElementById('themeToggle');
        if (themeToggle) {
            // Method 1: addEventListener
            themeToggle.addEventListener('click', toggleTheme, true);
            
            // Method 2: onclick as backup
            themeToggle.onclick = function(e) {
                e.preventDefault();
                e.stopPropagation();
                toggleTheme(e);
                return false;
            };
            
            // Method 3: Event delegation from parent
            const navUser = themeToggle.closest('.nav-user');
            if (navUser) {
                navUser.addEventListener('click', function(e) {
                    if (e.target === themeToggle || e.target.closest('#themeToggle')) {
                        e.preventDefault();
                        e.stopPropagation();
                        toggleTheme(e);
                    }
                }, true);
            }
        }
        const burger = document.getElementById('navBurger');
        const menu = document.getElementById('navMenu');
        const moreBtn = document.getElementById('navMoreBtn');
        const moreContent = document.getElementById('navMoreContent');
        const moreDropdown = document.getElementById('navMoreDropdown');

        // More dropdown toggle
        if (moreBtn) {
            moreBtn.addEventListener('click', function(e) {
                e.stopPropagation();
                moreBtn.classList.toggle('active');
                moreContent.classList.toggle('show');
            });
        }

        // Close dropdown when clicking outside
        document.addEventListener('click', function(e) {
            if (moreDropdown && !moreDropdown.contains(e.target)) {
                moreBtn?.classList.remove('active');
                moreContent?.classList.remove('show');
            }
        });

        // Burger menu toggle
        if (burger && menu) {
            burger.addEventListener('click', function() {
                burger.classList.toggle('active');
                menu.classList.toggle('active');
                document.body.classList.toggle('nav-open');
            });

            document.addEventListener('click', function(e) {
                if (!burger.contains(e.target) && !menu.contains(e.target)) {
                    burger.classList.remove('active');
                    menu.classList.remove('active');
                    document.body.classList.remove('nav-open');
                }
            });

            // Close menu on link click (mobile)
            if (window.innerWidth <= 768) {
                menu.querySelectorAll('a.nav-link').forEach(link => {
                    link.addEventListener('click', function() {
                        burger.classList.remove('active');
                        menu.classList.remove('active');
                        document.body.classList.remove('nav-open');
                    });
                });
            }
        }
    });
    </script>
    <?php endif; ?>
    
    <div class="container">
        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i>
                <span><?= htmlspecialchars($_SESSION['success_message']) ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-circle"></i>
                <span><?= htmlspecialchars($_SESSION['error_message']) ?></span>
                <button class="alert-close" onclick="this.parentElement.remove()">&times;</button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>