<?php
/**
 * Email Notification System
 * 
 * Sends email notifications for license events
 */

/**
 * Send email notification
 * 
 * @param string $to Recipient email
 * @param string $subject Email subject
 * @param string $message Email body (HTML)
 * @param string $from From email address
 * @return bool Success
 */
function send_email_notification(string $to, string $subject, string $message, string $from = null): bool {
    if (empty($to) || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        error_log('Email notification: Invalid recipient email: ' . $to);
        return false;
    }
    
    // Get from email from settings or use default
    if ($from === null) {
        $from = get_setting('notification_from_email', 'noreply@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
    }
    
    $from_name = get_setting('notification_from_name', APP_NAME);
    
    // Headers
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . $from_name . ' <' . $from . '>',
        'Reply-To: ' . $from,
        'X-Mailer: PHP/' . phpversion()
    ];
    
    // Send email
    $result = mail($to, $subject, $message, implode("\r\n", $headers));
    
    if (!$result) {
        error_log('Email notification failed: ' . error_get_last()['message'] ?? 'Unknown error');
    }
    
    return $result;
}

/**
 * Send license expiration warning email
 * 
 * @param array $license License data
 * @param int $days_left Days until expiration
 * @return bool Success
 */
function send_expiration_warning(array $license, int $days_left): bool {
    if (empty($license['customer_email'])) {
        return false; // No email to send to
    }
    
    $subject = "License Expiration Warning - {$days_left} days remaining";
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #2271b1; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .footer { padding: 20px; text-align: center; color: #666; font-size: 12px; }
            .button { display: inline-block; padding: 10px 20px; background: #2271b1; color: white; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>" . htmlspecialchars(APP_NAME) . "</h1>
            </div>
            <div class='content'>
                <h2>License Expiration Warning</h2>
                <p>Dear " . htmlspecialchars($license['customer_name'] ?? 'Customer') . ",</p>
                <p>Your license key will expire in <strong>{$days_left} days</strong>.</p>
                <p><strong>License Key:</strong> " . htmlspecialchars(substr($license['license_key'], 0, 20)) . "...</p>
                <p><strong>Expiration Date:</strong> " . htmlspecialchars($license['expires']) . "</p>
                <p>Please contact us to renew your license before it expires.</p>
                <p><a href='mailto:" . htmlspecialchars(get_setting('support_email', '')) . "' class='button'>Contact Support</a></p>
            </div>
            <div class='footer'>
                <p>This is an automated message from " . htmlspecialchars(APP_NAME) . "</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return send_email_notification($license['customer_email'], $subject, $message);
}

/**
 * Send license activated email
 * 
 * @param array $license License data
 * @param string $site_url Activated site URL
 * @return bool Success
 */
function send_activation_notification(array $license, string $site_url): bool {
    if (empty($license['customer_email'])) {
        return false;
    }
    
    $subject = "License Activated Successfully";
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #46b450; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>License Activated</h1>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($license['customer_name'] ?? 'Customer') . ",</p>
                <p>Your license has been successfully activated on:</p>
                <p><strong>" . htmlspecialchars($site_url) . "</strong></p>
                <p><strong>License Key:</strong> " . htmlspecialchars(substr($license['license_key'], 0, 20)) . "...</p>
                <p>Thank you for using our service!</p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return send_email_notification($license['customer_email'], $subject, $message);
}

/**
 * Send license expired email
 * 
 * @param array $license License data
 * @return bool Success
 */
function send_expired_notification(array $license): bool {
    if (empty($license['customer_email'])) {
        return false;
    }
    
    $subject = "License Expired - Renewal Required";
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; line-height: 1.6; color: #333; }
            .container { max-width: 600px; margin: 0 auto; padding: 20px; }
            .header { background: #dc3232; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; background: #f9f9f9; }
            .button { display: inline-block; padding: 10px 20px; background: #2271b1; color: white; text-decoration: none; border-radius: 5px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>License Expired</h1>
            </div>
            <div class='content'>
                <p>Dear " . htmlspecialchars($license['customer_name'] ?? 'Customer') . ",</p>
                <p>Your license has expired on <strong>" . htmlspecialchars($license['expires']) . "</strong>.</p>
                <p>Please renew your license to continue using the service.</p>
                <p><a href='mailto:" . htmlspecialchars(get_setting('support_email', '')) . "' class='button'>Renew License</a></p>
            </div>
        </div>
    </body>
    </html>
    ";
    
    return send_email_notification($license['customer_email'], $subject, $message);
}

/**
 * Check and send expiration warnings (cron job)
 * 
 * @return int Number of emails sent
 */
function check_and_send_expiration_warnings(): int {
    if (!get_setting('enable_email_notifications', false)) {
        return 0;
    }
    
    try {
        $db = get_db_connection();
        
        // Get licenses expiring in 7 days
        $stmt = $db->prepare("
            SELECT * FROM licenses 
            WHERE expires IS NOT NULL 
            AND expires BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND status = 'active'
            AND customer_email IS NOT NULL
            AND customer_email != ''
        ");
        $stmt->execute();
        $licenses = $stmt->fetchAll();
        
        $sent = 0;
        foreach ($licenses as $license) {
            $days_left = (strtotime($license['expires']) - time()) / 86400;
            if (send_expiration_warning($license, (int)$days_left)) {
                $sent++;
            }
        }
        
        return $sent;
    } catch (Exception $e) {
        error_log('Expiration warning check error: ' . $e->getMessage());
        return 0;
    }
}

