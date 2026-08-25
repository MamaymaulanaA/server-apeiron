<?php
/**
 * Email Helper Functions
 * 
 * Fungsi untuk mengirim dan mengelola email notifications
 */

/**
 * Queue email notification untuk dikirim
 * 
 * @param string $type Tipe notifikasi (license_expiring, license_activated, dll)
 * @param string $recipient_email Email penerima
 * @param string $subject Subject email
 * @param string $body Isi email (HTML)
 * @param array $options Opsi tambahan (recipient_name, related_license_id, related_admin_id)
 * @return bool
 */
function queue_email_notification($type, $recipient_email, $subject, $body, $options = []) {
    try {
        $db = get_db_connection();
        
        $stmt = $db->prepare("
            INSERT INTO email_notifications 
            (type, recipient_email, recipient_name, subject, body, related_license_id, related_admin_id)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        return $stmt->execute([
            $type,
            $recipient_email,
            $options['recipient_name'] ?? null,
            $subject,
            $body,
            $options['related_license_id'] ?? null,
            $options['related_admin_id'] ?? null
        ]);
        
    } catch (PDOException $e) {
        error_log("Email queue error: " . $e->getMessage());
        return false;
    }
}

/**
 * Get email settings dari database
 * 
 * @return array
 */
function get_email_settings() {
    try {
        $db = get_db_connection();
        $stmt = $db->query("SELECT setting_key, setting_value FROM email_settings");
        $settings = [];
        
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        return $settings;
        
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * Send email using PHP mail() or SMTP
 * 
 * @param string $to Email penerima
 * @param string $subject Subject
 * @param string $body HTML body
 * @return bool
 */
function send_email($to, $subject, $body) {
    $settings = get_email_settings();
    
    // Jika SMTP username kosong, gunakan PHP mail()
    if (empty($settings['smtp_username'])) {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=utf-8',
            'From: ' . ($settings['from_name'] ?? 'License Server') . ' <' . ($settings['from_email'] ?? 'noreply@localhost') . '>'
        ];
        
        return mail($to, $subject, $body, implode("\r\n", $headers));
    }
    
    // TODO: Implementasi SMTP menggunakan PHPMailer
    // Untuk saat ini, fallback ke mail()
    return mail($to, $subject, $body);
}

/**
 * Process pending email queue
 * Jalankan via cron atau manual
 * 
 * @param int $limit Jumlah email yang diproses per batch
 * @return array Hasil proses
 */
function process_email_queue($limit = 10) {
    $results = ['sent' => 0, 'failed' => 0, 'errors' => []];
    
    try {
        $db = get_db_connection();
        
        // Ambil email pending
        $stmt = $db->prepare("
            SELECT * FROM email_notifications 
            WHERE status = 'pending' AND retry_count < 3
            ORDER BY created_at ASC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $emails = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($emails as $email) {
            $success = send_email($email['recipient_email'], $email['subject'], $email['body']);
            
            if ($success) {
                // Update status ke sent
                $update = $db->prepare("
                    UPDATE email_notifications 
                    SET status = 'sent', sent_at = NOW() 
                    WHERE id = ?
                ");
                $update->execute([$email['id']]);
                $results['sent']++;
            } else {
                // Update retry count
                $update = $db->prepare("
                    UPDATE email_notifications 
                    SET retry_count = retry_count + 1, 
                        error_message = 'Failed to send',
                        status = CASE WHEN retry_count >= 2 THEN 'failed' ELSE 'pending' END
                    WHERE id = ?
                ");
                $update->execute([$email['id']]);
                $results['failed']++;
                $results['errors'][] = "Failed to send email #{$email['id']} to {$email['recipient_email']}";
            }
        }
        
    } catch (PDOException $e) {
        $results['errors'][] = $e->getMessage();
    }
    
    return $results;
}

/**
 * Template email untuk license expiring
 * 
 * @param array $license Data lisensi
 * @param int $days_left Hari tersisa
 * @return string HTML email
 */
function email_template_license_expiring($license, $days_left) {
    $app_name = defined('APP_NAME') ? APP_NAME : 'Apeiron License Server';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 10px; overflow: hidden; }
            .header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; }
            .content { padding: 30px; }
            .license-box { background: #f8f9fa; border-left: 4px solid #667eea; padding: 15px; margin: 20px 0; }
            .btn { display: inline-block; background: #667eea; color: white; padding: 12px 30px; text-decoration: none; border-radius: 5px; margin-top: 20px; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>⚠️ License Expiring Soon</h1>
            </div>
            <div class='content'>
                <p>Halo,</p>
                <p>Lisensi Anda akan <strong>berakhir dalam {$days_left} hari</strong>. Harap perpanjang lisensi untuk terus menggunakan fitur premium.</p>
                
                <div class='license-box'>
                    <strong>License Key:</strong> {$license['license_key']}<br>
                    <strong>Domain:</strong> {$license['domain']}<br>
                    <strong>Expires:</strong> {$license['expires_at']}
                </div>
                
                <p>Segera perpanjang lisensi Anda untuk menghindari gangguan layanan.</p>
                
                <a href='#' class='btn'>Perpanjang Sekarang</a>
            </div>
            <div class='footer'>
                &copy; " . date('Y') . " {$app_name}. All rights reserved.
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Template email untuk license activated
 * 
 * @param array $license Data lisensi
 * @return string HTML email
 */
function email_template_license_activated($license) {
    $app_name = defined('APP_NAME') ? APP_NAME : 'Apeiron License Server';
    
    return "
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset='utf-8'>
        <style>
            body { font-family: Arial, sans-serif; background: #f5f5f5; padding: 20px; }
            .container { max-width: 600px; margin: 0 auto; background: #fff; border-radius: 10px; overflow: hidden; }
            .header { background: linear-gradient(135deg, #00d4aa 0%, #00b894 100%); color: white; padding: 30px; text-align: center; }
            .content { padding: 30px; }
            .license-box { background: #f8f9fa; border-left: 4px solid #00d4aa; padding: 15px; margin: 20px 0; }
            .footer { background: #f8f9fa; padding: 20px; text-align: center; color: #666; font-size: 12px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>✅ License Activated!</h1>
            </div>
            <div class='content'>
                <p>Selamat! Lisensi Anda telah berhasil diaktifkan.</p>
                
                <div class='license-box'>
                    <strong>License Key:</strong> {$license['license_key']}<br>
                    <strong>Domain:</strong> {$license['domain']}<br>
                    <strong>Activated:</strong> {$license['activated_at']}<br>
                    <strong>Expires:</strong> {$license['expires_at']}
                </div>
                
                <p>Anda sekarang dapat menggunakan semua fitur premium. Terima kasih!</p>
            </div>
            <div class='footer'>
                &copy; " . date('Y') . " {$app_name}. All rights reserved.
            </div>
        </div>
    </body>
    </html>
    ";
}

/**
 * Cek lisensi yang akan expired dan kirim notifikasi
 * Jalankan via cron setiap hari
 * 
 * @param int $days_before Berapa hari sebelum expired untuk notifikasi
 * @return int Jumlah notifikasi yang di-queue
 */
function check_and_notify_expiring_licenses($days_before = 7) {
    $count = 0;
    
    try {
        $db = get_db_connection();
        
        // Cari lisensi yang akan expired dalam X hari
        $stmt = $db->prepare("
            SELECT l.*, c.email, c.name as customer_name
            FROM licenses l
            LEFT JOIN customers c ON l.customer_id = c.id
            WHERE l.status = 'active'
            AND l.expires_at IS NOT NULL
            AND l.expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL ? DAY)
            AND NOT EXISTS (
                SELECT 1 FROM email_notifications en 
                WHERE en.related_license_id = l.id 
                AND en.type = 'license_expiring'
                AND en.created_at > DATE_SUB(NOW(), INTERVAL 1 DAY)
            )
        ");
        $stmt->execute([$days_before]);
        $licenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($licenses as $license) {
            if (empty($license['email'])) continue;
            
            $days_left = ceil((strtotime($license['expires_at']) - time()) / 86400);
            $body = email_template_license_expiring($license, $days_left);
            
            $success = queue_email_notification(
                'license_expiring',
                $license['email'],
                "⚠️ Lisensi Anda akan berakhir dalam {$days_left} hari",
                $body,
                [
                    'recipient_name' => $license['customer_name'],
                    'related_license_id' => $license['id']
                ]
            );
            
            if ($success) $count++;
        }
        
    } catch (PDOException $e) {
        error_log("Check expiring licenses error: " . $e->getMessage());
    }
    
    return $count;
}

