<?php
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/functions.php';

if (isset($_SESSION['admin_id'])) {
    log_activity('logout', 'admin', $_SESSION['admin_id'], 'Admin logged out');
}

session_destroy();
header('Location: login.php');
exit;

