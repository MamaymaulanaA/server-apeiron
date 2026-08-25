<?php
require_once '../config.php';
require_once '../includes/functions.php';

require_admin_login();

$page_title = 'Generate License Keys';
$show_header = true;

$generated_keys = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Validate CSRF token
    require_csrf_token();
    
    $count = intval($_POST['count'] ?? 1);
    $expires = $_POST['expires'] ?? '';
    $activation_limit = intval($_POST['activation_limit'] ?? get_setting('default_activation_limit', 1));
    $product_id = sanitize_input($_POST['product_id'] ?? 'apeiron-kit');
    $customer_name = sanitize_input($_POST['customer_name'] ?? '');
    $customer_email = sanitize_input($_POST['customer_email'] ?? '');
    
    // VALIDATION: Validate email if provided
    if (!empty($customer_email) && !validate_email($customer_email)) {
        $_SESSION['error_message'] = 'Invalid email format for customer email';
        header('Location: generate.php');
        exit;
    }
    $status = sanitize_input($_POST['status'] ?? 'inactive');
    $notes = sanitize_input($_POST['notes'] ?? '');
    
    // Domain lock (comma-separated domains)
    $allowed_domains_raw = trim($_POST['allowed_domains'] ?? '');
    $allowed_domains = null;
    if (!empty($allowed_domains_raw)) {
        $domains = array_map('trim', explode(',', $allowed_domains_raw));
        $domains = array_filter($domains, function($domain) {
            if (empty($domain)) return false;
            // Simple domain validation (more lenient)
            $domain_clean = preg_replace('#^https?://#i', '', $domain);
            return preg_match('/^([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,}$/i', $domain_clean);
        });
        if (!empty($domains)) {
            $allowed_domains = json_encode($domains);
        }
    }
    
    // Single domain only flag
    $single_domain_only = isset($_POST['single_domain_only']) ? 1 : 0;
    
    // Max domains (only if single_domain_only = 0)
    $max_domains = null;
    if ($single_domain_only == 0 && !empty($_POST['max_domains'])) {
        $max_domains = intval($_POST['max_domains']);
        if ($max_domains < 1) {
            $max_domains = null;
        }
    }
    
    if ($count < 1 || $count > 100) {
        $_SESSION['error_message'] = 'Count must be between 1 and 100';
    } else {
        try {
            $db = get_db_connection();
            $generated_keys = [];
            
            $db->beginTransaction();
            
            try {
                // Pre-calculate values outside loop for performance
                $expires_date = null;
                if ($expires === 'custom' && !empty($_POST['expires_date'])) {
                    $expires_date = $_POST['expires_date'];
                } elseif ($expires && $expires !== 'never') {
                    $expires_date = date('Y-m-d', strtotime("+{$expires} days"));
                }
                
                // Check encryption setting once (cache it)
                $is_encrypted = get_setting('encryption_enabled', false) ? 1 : 0;
                
                // Pre-check: Get existing keys to avoid collisions (batch check) - only for large batches
                $existing_keys = [];
                if ($count > 10) {
                    $stmt = $db->query("SELECT license_key FROM licenses WHERE license_key LIKE 'APEIRON-%' LIMIT 1000");
                    $existing_keys = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));
                }
                
                // Prepare insert statement once (reuse for all inserts)
                // PERFORMANCE: Include license_key_hash for fast lookup
                $insert_stmt = $db->prepare("
                    INSERT INTO licenses (license_key, license_key_hash, client_api_key, is_encrypted, product_id, status, expires, activation_limit, single_domain_only, max_domains, customer_name, customer_email, notes, allowed_domains, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                // NEW: Prepare api_keys insert statement
                $insert_api_stmt = $db->prepare("
                    INSERT INTO api_keys (key_name, api_key, api_key_prefix, product_id, is_active, created_by, created_at, updated_at)
                    VALUES (?, ?, ?, ?, 1, ?, NOW(), NOW())
                ");

                for ($i = 0; $i < $count; $i++) {
                    $max_retries = 10;
                    $retry_count = 0;
                    $license_key = null;
                    
                    // Generate unique license key with retry
                    while ($retry_count < $max_retries) {
                        $license_key = generate_license_key();
                        
                        // Quick check in memory first (for batch generation)
                        if (!empty($existing_keys) && isset($existing_keys[$license_key])) {
                            $retry_count++;
                            continue;
                        }
                        
                        // Check if key already exists in database (only if not in memory cache)
                        if (empty($existing_keys) || $count <= 10) {
                            $check_stmt = $db->prepare("SELECT id FROM licenses WHERE license_key = ? OR license_key_hash = ? LIMIT 1");
                            $license_key_hash = hash('sha256', $license_key);
                            $check_stmt->execute([$license_key, $license_key_hash]);
                            if ($check_stmt->fetch()) {
                                $retry_count++;
                                continue;
                            }
                        }
                        
                        // Add to existing keys array to avoid duplicates in same batch
                        $existing_keys[$license_key] = true;
                        break; // Key is unique
                    }
                    
                    if ($retry_count >= $max_retries) {
                        throw new Exception('Failed to generate unique license key after ' . $max_retries . ' attempts');
                    }
                    
                    // PERFORMANCE: Calculate hash for fast lookup (always, even if encrypted)
                    $license_key_hash = hash('sha256', $license_key);

                    // FIX: Generate client API key for each license
                    $client_api_key = bin2hex(random_bytes(32));

                    // Encrypt license key if encryption is enabled
                    $stored_key = $license_key;
                    if ($is_encrypted) {
                        $stored_key = encrypt_data($license_key);
                    }

                    // Insert license using prepared statement (reused for performance)
                    $insert_stmt->execute([
                        $stored_key,
                        $license_key_hash, // PERFORMANCE: Add hash for fast lookup
                        $client_api_key, // FIX: Now properly initialized
                        $is_encrypted,
                        $product_id,
                        $status,
                        $expires_date,
                        $activation_limit,
                        $single_domain_only,
                        $max_domains,
                        $customer_name ?: null,
                        $customer_email ?: null,
                        $notes ?: null,
                        $allowed_domains,
                        $_SESSION['admin_id']
                    ]);

                $license_id = $db->lastInsertId();

                // Skip if insert failed (duplicate key - should not happen with UNIQUE constraint)
                if (!$license_id) {
                    $i--; // Retry this iteration
                    continue;
                }

                // NEW: Also insert into api_keys table for Management API Key page
                $api_key_prefix = substr($client_api_key, 0, 8);
                $hashed_api_key = hash('sha256', $client_api_key);
                $key_name = 'License: ' . (!empty($customer_email) ? $customer_email : (!empty($customer_name) ? $customer_name : '#' . ($i + 1)));

                $insert_api_stmt->execute([
                    $key_name,
                    $hashed_api_key,
                    $api_key_prefix,
                    $product_id,
                    $_SESSION['admin_id']
                ]);

                $api_key_id = $db->lastInsertId();
                error_log("[TRACE] API KEY SAVED TO DB: prefix={$api_key_prefix}, id={$api_key_id}, license_id={$license_id}");

                $generated_keys[] = [
                    'id' => $license_id,
                    'key' => $license_key,
                    'expires' => $expires_date,
                    'limit' => $activation_limit,
                    'product' => $product_id
                ];
                }
                
                $db->commit();
                
                // Log activity once after all licenses are generated (outside loop for performance)
                if (!empty($generated_keys)) {
                    log_activity('create', 'license', null, "Generated {$count} license key(s)");
                }
                
                $_SESSION['success_message'] = "Berhasil generate {$count} license key(s)!";
            } catch (Exception $e) {
                if (isset($db) && $db->inTransaction()) {
                    $db->rollBack();
                }
                throw $e; // Re-throw untuk outer catch
            }
        } catch (PDOException $e) {
            error_log('Generate License Error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Database error occurred. Please try again or contact administrator.';
        } catch (Exception $e) {
            error_log('Generate License Error: ' . $e->getMessage());
            $_SESSION['error_message'] = 'Error: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Get products
try {
    $db = get_db_connection();
    $products = $db->query("SELECT DISTINCT product_id FROM licenses ORDER BY product_id")->fetchAll(PDO::FETCH_COLUMN);
    if (empty($products)) {
        $products = ['apeiron-kit'];
    }
} catch (Exception $e) {
    $products = ['apeiron-kit'];
}

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-magic"></i> Generate License Keys
        </h1>
        <a href="licenses.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Licenses
        </a>
    </div>
    
    <div class="generate-form-grid" style="display: grid; grid-template-columns: 1fr 1fr; gap: 30px; margin-top: 20px;">
        <div>
            <h3 style="margin-bottom: 20px; color: var(--text-muted); font-size: 16px; text-transform: uppercase; border-bottom: 2px solid var(--bg); padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-cog"></i> License Configuration
            </h3>
            
            <form method="POST" id="generateForm" onsubmit="return showLoading(event)">
                <input type="hidden" name="csrf_token" value="<?= generate_csrf_token() ?>">
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-hashtag"></i> Jumlah License Key
                    </label>
                    <input type="number" name="count" class="form-control" value="1" min="1" max="100" required>
                    <div class="form-text">
                        <i class="fas fa-info-circle"></i> Generate 1-100 license keys at once
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-box"></i> Product ID
                    </label>
                    <input type="text" name="product_id" class="form-control" value="apeiron-kit" required list="products">
                    <datalist id="products">
                        <?php foreach ($products as $product): ?>
                            <option value="<?= htmlspecialchars($product) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-toggle-on"></i> Initial Status
                    </label>
                    <select name="status" class="form-control">
                        <option value="inactive">Inactive</option>
                        <option value="active">Active</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-calendar-alt"></i> Expiration
                    </label>
                    <select name="expires" id="expires" class="form-control">
                        <option value="never">Never Expire</option>
                        <option value="30">30 Days</option>
                        <option value="90">90 Days</option>
                        <option value="180">6 Months</option>
                        <option value="365">1 Year</option>
                        <option value="730">2 Years</option>
                        <option value="custom">Custom Date</option>
                    </select>
                </div>
                
                <div class="form-group" id="custom-date-group" style="display: none;">
                    <label class="form-label">
                        <i class="fas fa-calendar-day"></i> Custom Expiration Date
                    </label>
                    <input type="date" name="expires_date" class="form-control">
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-link"></i> Activation Limit
                    </label>
                    <input type="number" name="activation_limit" class="form-control" 
                           value="<?= get_setting('default_activation_limit', 1) ?>" min="0" required>
                    <div class="form-text">
                        <i class="fas fa-infinity"></i> 0 = unlimited activations
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-lock"></i> Domain Restrictions
                    </label>
                    <div style="display: flex; flex-direction: column; gap: 10px;">
                        <label style="display: flex; align-items: center; gap: 8px; cursor: pointer;">
                            <input type="checkbox" name="single_domain_only" value="1" id="single_domain_only" 
                                   onchange="toggleMaxDomains()">
                            <span><strong>Single Domain Only</strong> - License hanya bisa diaktifkan di 1 domain</span>
                        </label>
                        <div class="form-text" style="margin-left: 28px; margin-top: -5px;">
                            <i class="fas fa-info-circle"></i> Jika dicentang, license hanya bisa diaktifkan di satu domain. Aktivasi di domain lain akan ditolak.
                        </div>
                        
                        <div id="max_domains_group" style="display: none; margin-top: 5px;">
                            <label class="form-label" style="font-size: 13px;">
                                <i class="fas fa-globe"></i> Max Domains (jika Single Domain = OFF)
                            </label>
                            <input type="number" name="max_domains" class="form-control" 
                                   min="1" placeholder="Kosongkan untuk unlimited">
                            <div class="form-text">
                                <i class="fas fa-infinity"></i> Maksimal jumlah domain berbeda yang diizinkan. Kosongkan = unlimited.
                            </div>
                        </div>
                    </div>
                </div>
        </div>
        
        <div>
            <h3 style="margin-bottom: 20px; color: var(--text-muted); font-size: 16px; text-transform: uppercase; border-bottom: 2px solid var(--bg); padding-bottom: 10px; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-user"></i> Customer Information (Optional)
            </h3>
            
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-user-circle"></i> Customer Name
                    </label>
                    <input type="text" name="customer_name" class="form-control" placeholder="Enter customer name">
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-envelope"></i> Customer Email
                    </label>
                    <input type="email" name="customer_email" class="form-control" placeholder="customer@example.com">
                </div>
                
                <div class="form-group">
                    <label class="form-label">
                        <i class="fas fa-sticky-note"></i> Notes
                    </label>
                    <textarea name="notes" class="form-control" rows="5" placeholder="Additional notes or comments..."></textarea>
                </div>
                
                <div style="margin-top: 30px; padding-top: 20px; border-top: 2px solid var(--bg);">
                    <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                        <i class="fas fa-magic"></i> Generate License Keys
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <?php if (!empty($generated_keys)): ?>
        <div class="card" style="margin-top: 30px; background: linear-gradient(135deg, #f8f9fa 0%, #ffffff 100%);">
            <div class="card-header">
                <h2 class="card-title">
                    <i class="fas fa-check-circle" style="color: var(--success);"></i> Generated License Keys
                    <span class="badge badge-success" style="margin-left: 10px;"><?= count($generated_keys) ?> Keys</span>
                </h2>
                <button class="btn btn-primary" onclick="copyAllKeys()">
                    <i class="fas fa-copy"></i> Copy All
                </button>
            </div>
            <div class="generated-keys-list" style="display: grid; gap: 12px;">
                <?php foreach ($generated_keys as $index => $key_data): ?>
                    <div class="generated-key-item" style="padding: 20px; background: white; border-radius: 12px; border: 2px solid var(--bg); box-shadow: 0 1px 3px rgba(0,0,0,0.04); transition: all 0.2s;" 
                         onmouseover="this.style.borderColor='var(--success)'; this.style.boxShadow='0 2px 6px rgba(70, 180, 80, 0.1)'"
                         onmouseout="this.style.borderColor='var(--bg)'; this.style.boxShadow='0 1px 3px rgba(0,0,0,0.04)'">
                        <div class="generated-key-content" style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                            <div class="generated-key-main" style="flex: 1; min-width: 250px;">
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <span style="background: var(--primary); color: white; width: 28px; height: 28px; border-radius: 6px; display: flex; align-items: center; justify-content: center; font-size: 12px; font-weight: 600;">
                                        #<?= $index + 1 ?>
                                    </span>
                                    <code style="font-size: 18px; font-weight: 700; color: var(--primary); letter-spacing: 1px;">
                                        <?= htmlspecialchars($key_data['key']) ?>
                                    </code>
                                </div>
                                <button class="btn btn-sm btn-secondary" data-copy="<?= htmlspecialchars($key_data['key']) ?>" 
                                        style="margin-top: 8px;"
                                        onclick="copyToClipboard('<?= htmlspecialchars($key_data['key']) ?>', this)">
                                    <i class="fas fa-copy"></i> Copy Key
                                </button>
                            </div>
                            <div class="generated-key-info" style="display: flex; gap: 20px; flex-wrap: wrap; font-size: 13px;">
                                <div style="display: flex; align-items: center; gap: 6px; color: var(--text-muted);">
                                    <i class="fas fa-box" style="color: var(--primary);"></i>
                                    <span><strong>Product:</strong> <?= htmlspecialchars($key_data['product']) ?></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px; color: var(--text-muted);">
                                    <i class="fas fa-calendar-alt" style="color: var(--warning);"></i>
                                    <span><strong>Expires:</strong> <?= $key_data['expires'] ? format_date($key_data['expires'], 'Y-m-d') : 'Never' ?></span>
                                </div>
                                <div style="display: flex; align-items: center; gap: 6px; color: var(--text-muted);">
                                    <i class="fas fa-link" style="color: var(--info);"></i>
                                    <span><strong>Limit:</strong> <?= $key_data['limit'] ?: 'Unlimited' ?></span>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
// Show loading indicator on form submit
function showLoading(event) {
    const form = document.getElementById('generateForm');
    if (!form) {
        console.error('Form not found');
        return true;
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        // Disable button immediately
        submitBtn.disabled = true;
        submitBtn.style.opacity = '0.7';
        submitBtn.style.cursor = 'not-allowed';
        
        const originalHTML = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating... Please wait';
        
        // Show overlay loading
        const loadingOverlay = document.createElement('div');
        loadingOverlay.id = 'loadingOverlay';
        loadingOverlay.style.cssText = 'position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999; display: flex; align-items: center; justify-content: center;';
        loadingOverlay.innerHTML = '<div style="background: white; padding: 30px; border-radius: 10px; text-align: center;"><i class="fas fa-spinner fa-spin fa-3x" style="color: var(--primary);"></i><p style="margin-top: 15px; font-size: 16px;">Generating license keys... Please wait</p></div>';
        document.body.appendChild(loadingOverlay);
        
        // Re-enable after 60 seconds as fallback (in case of error)
        setTimeout(() => {
            const overlay = document.getElementById('loadingOverlay');
            if (overlay) overlay.remove();
            
            if (submitBtn.disabled) {
                submitBtn.disabled = false;
                submitBtn.style.opacity = '';
                submitBtn.style.cursor = '';
                submitBtn.innerHTML = originalHTML;
                alert('Request is taking longer than expected. Please check if the page is still loading.');
            }
        }, 60000);
    }
    
    return true; // Allow form submission
}

// Remove loading overlay when page loads (after form submission)
document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('loadingOverlay');
    if (overlay) {
        overlay.remove();
    }
    
    // Handle expires dropdown
    const expiresSelect = document.getElementById('expires');
    if (expiresSelect) {
        expiresSelect.addEventListener('change', function() {
            const customDateGroup = document.getElementById('custom-date-group');
            if (customDateGroup) {
                customDateGroup.style.display = this.value === 'custom' ? 'block' : 'none';
            }
        });
    }
    
    // Initialize max_domains group visibility
    toggleMaxDomains();
});

// Toggle max_domains field based on single_domain_only checkbox
function toggleMaxDomains() {
    const singleDomainCheckbox = document.getElementById('single_domain_only');
    const maxDomainsGroup = document.getElementById('max_domains_group');
    if (singleDomainCheckbox && maxDomainsGroup) {
        if (singleDomainCheckbox.checked) {
            maxDomainsGroup.style.display = 'none';
            // Clear max_domains value if single domain is enabled
            const maxDomainsInput = document.querySelector('input[name="max_domains"]');
            if (maxDomainsInput) {
                maxDomainsInput.value = '';
            }
        } else {
            maxDomainsGroup.style.display = 'block';
        }
    }
}

function copyToClipboard(text, button) {
    navigator.clipboard.writeText(text).then(() => {
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-check"></i> Copied!';
        button.style.background = 'var(--success)';
        setTimeout(() => {
            button.innerHTML = originalHTML;
            button.style.background = '';
        }, 2000);
    });
}

function copyAllKeys() {
    const keys = <?= json_encode(array_column($generated_keys ?? [], 'key')) ?>;
    if (keys.length === 0) return;
    
    navigator.clipboard.writeText(keys.join('\n')).then(() => {
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="fas fa-check"></i> All Copied!';
        btn.style.background = 'var(--success)';
        setTimeout(() => {
            btn.innerHTML = originalHTML;
            btn.style.background = '';
        }, 2000);
    });
}

// Auto copy on click for all copy buttons
document.addEventListener('DOMContentLoaded', function() {
    document.querySelectorAll('[data-copy]').forEach(button => {
        button.addEventListener('click', function() {
            copyToClipboard(this.getAttribute('data-copy'), this);
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>