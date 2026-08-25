<?php
require_once '../config.php';
require_once '../includes/functions.php';
require_once '../includes/api_keys.php';

require_admin_login();

// Enable error reporting for debugging
if (ENVIRONMENT === 'development') {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
}

$page_title = 'API Keys Management';
$show_header = true;

$message = '';
$message_type = '';

// Generate single CSRF token for the page
$page_csrf_token = generate_csrf_token();

// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Debug logging before CSRF check
    error_log("POST request received. Action: " . ($_POST['action'] ?? 'none') . ", Key ID: " . ($_POST['key_id'] ?? 'none'));
    error_log("CSRF Token in POST: " . ($_POST['csrf_token'] ?? 'missing'));
    error_log("CSRF Token in Session: " . ($_SESSION['csrf_token'] ?? 'missing'));
    
    // Validate CSRF token manually with better error handling
    if (isset($_POST['csrf_token'])) {
        if (!validate_csrf_token($_POST['csrf_token'])) {
            error_log("CSRF token validation failed!");
            $_SESSION['error_message'] = 'Invalid security token. Please refresh the page and try again.';
            if (ob_get_level()) ob_end_clean();
            header('Location: api-keys.php');
            exit;
        }
    } else {
        error_log("CSRF token missing in POST!");
        $_SESSION['error_message'] = 'Security token missing. Please refresh the page and try again.';
        if (ob_get_level()) ob_end_clean();
        header('Location: api-keys.php');
        exit;
    }
    
    $action = $_POST['action'] ?? '';

    // Debug logging
    if (!empty($_POST)) {
        error_log("API Keys Action: " . $action . ", POST data: " . json_encode($_POST));
    }

    if ($action === 'generate') {
        $key_name = sanitize_input($_POST['key_name'] ?? '');
        $product_id = sanitize_input($_POST['product_id'] ?? 'apeiron-kit');
        
        $allowed_domains = [];
        if (!empty($_POST['allowed_domains'])) {
            $domains = array_map('trim', explode(',', $_POST['allowed_domains']));
            $domains = array_filter($domains);
            $allowed_domains = $domains;
        }
        
        $allowed_ips = [];
        if (!empty($_POST['allowed_ips'])) {
            $ips = array_map('trim', explode(',', $_POST['allowed_ips']));
            $ips = array_filter($ips);
            $allowed_ips = $ips;
        }
        
        $options = [
            'allowed_domains' => $allowed_domains,
            'allowed_ips' => $allowed_ips,
            'rate_limit_requests' => intval($_POST['rate_limit_requests'] ?? 100),
            'rate_limit_window' => intval($_POST['rate_limit_window'] ?? 3600),
        ];
        
        if (!empty($_POST['expires_at'])) {
            $options['expires_at'] = $_POST['expires_at'];
        }
        
        $result = generate_api_key($key_name, $product_id, $options);
        
        if ($result) {
            $_SESSION['new_api_key'] = $result; // Store for display
            $_SESSION['success_message'] = 'API Key berhasil dibuat! Simpan key ini dengan aman - hanya ditampilkan sekali.';
            if (ob_get_level()) ob_end_clean();
            header('Location: api-keys.php');
            exit;
        } else {
            $_SESSION['error_message'] = 'Gagal membuat API Key.';
        }
    } elseif ($action === 'update') {
        $key_id = intval($_POST['key_id'] ?? 0);
        $data = [];
        
        if (isset($_POST['key_name'])) {
            $data['key_name'] = sanitize_input($_POST['key_name']);
        }
        if (isset($_POST['allowed_domains'])) {
            $domains = array_map('trim', explode(',', $_POST['allowed_domains']));
            $data['allowed_domains'] = array_filter($domains);
        }
        if (isset($_POST['allowed_ips'])) {
            $ips = array_map('trim', explode(',', $_POST['allowed_ips']));
            $data['allowed_ips'] = array_filter($ips);
        }
        if (isset($_POST['rate_limit_requests'])) {
            $data['rate_limit_requests'] = intval($_POST['rate_limit_requests']);
        }
        if (isset($_POST['rate_limit_window'])) {
            $data['rate_limit_window'] = intval($_POST['rate_limit_window']);
        }
        if (isset($_POST['is_active'])) {
            $data['is_active'] = intval($_POST['is_active']);
        }
        if (isset($_POST['expires_at'])) {
            $data['expires_at'] = $_POST['expires_at'] ?: null;
        }
        
        if (update_api_key($key_id, $data)) {
            $_SESSION['success_message'] = 'API Key berhasil diupdate.';
        } else {
            $_SESSION['error_message'] = 'Gagal mengupdate API Key.';
        }
        if (ob_get_level()) ob_end_clean();
        header('Location: api-keys.php');
        exit;
    } elseif ($action === 'delete') {
        $key_id = intval($_POST['key_id'] ?? 0);
        error_log("=== DELETE API KEY ===");
        error_log("Key ID: " . $key_id);
        
        if ($key_id <= 0) {
            error_log("Invalid key ID!");
            $_SESSION['error_message'] = 'Invalid API Key ID.';
            if (ob_get_level()) ob_end_clean();
            header('Location: api-keys.php');
            exit;
        }
        
        try {
            $result = delete_api_key($key_id);
            error_log("Delete result: " . ($result ? 'SUCCESS' : 'FAILED'));
            if ($result) {
                $_SESSION['success_message'] = 'API Key berhasil dihapus.';
                error_log("Success message set");
            } else {
                $_SESSION['error_message'] = 'Gagal menghapus API Key.';
                error_log("Error message set");
            }
        } catch (Exception $e) {
            error_log("Delete exception: " . $e->getMessage());
            error_log("Exception trace: " . $e->getTraceAsString());
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        }
        
        error_log("Redirecting to api-keys.php");
        if (ob_get_level()) ob_end_clean();
        header('Location: api-keys.php');
        exit;
    } elseif ($action === 'revoke') {
        $key_id = intval($_POST['key_id'] ?? 0);
        error_log("=== REVOKE API KEY ===");
        error_log("Key ID: " . $key_id);
        
        if ($key_id <= 0) {
            error_log("Invalid key ID!");
            $_SESSION['error_message'] = 'Invalid API Key ID.';
            if (ob_get_level()) ob_end_clean();
            header('Location: api-keys.php');
            exit;
        }
        
        try {
            $result = revoke_api_key($key_id);
            error_log("Revoke result: " . ($result ? 'SUCCESS' : 'FAILED'));
            if ($result) {
                $_SESSION['success_message'] = 'API Key berhasil di-revoke.';
                error_log("Success message set");
            } else {
                $_SESSION['error_message'] = 'Gagal me-revoke API Key.';
                error_log("Error message set");
            }
        } catch (Exception $e) {
            error_log("Revoke exception: " . $e->getMessage());
            error_log("Exception trace: " . $e->getTraceAsString());
            $_SESSION['error_message'] = 'Error: ' . $e->getMessage();
        }
        
        error_log("Redirecting to api-keys.php");
        if (ob_get_level()) ob_end_clean();
        header('Location: api-keys.php');
        exit;
    }
}

// Get all API keys
$api_keys = get_api_keys();

// Check for new key to display
$new_key = null;
if (isset($_SESSION['new_api_key'])) {
    $new_key = $_SESSION['new_api_key'];
    unset($_SESSION['new_api_key']);
}

include '../includes/header.php';
?>

<div class="card">
    <div class="card-header">
        <h1 class="card-title">
            <i class="fas fa-key"></i> API Keys Management
        </h1>
        <button type="button" class="btn btn-primary" onclick="showGenerateModal()">
            <i class="fas fa-plus"></i> Generate New API Key
        </button>
    </div>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i>
            <?= htmlspecialchars($_SESSION['success_message']) ?>
            <?php unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-error">
            <i class="fas fa-exclamation-circle"></i>
            <?= htmlspecialchars($_SESSION['error_message']) ?>
            <?php unset($_SESSION['error_message']); ?>
        </div>
    <?php endif; ?>
    
    <?php if ($new_key): ?>
        <div class="alert alert-warning" style="background: #fff3cd; border: 2px solid #ffc107; padding: 20px; margin: 20px 0;">
            <h3 style="margin-top: 0;">
                <i class="fas fa-exclamation-triangle"></i> API Key Created!
            </h3>
            <p><strong>Simpan API Key ini dengan aman - hanya ditampilkan sekali!</strong></p>
            <div style="background: #f8f9fa; padding: 15px; border-radius: 6px; margin: 15px 0;">
                <strong>API Key:</strong>
                <code style="display: block; margin-top: 10px; padding: 10px; background: white; border: 1px solid #ddd; border-radius: 4px; word-break: break-all; font-size: 14px;">
                    <?= htmlspecialchars($new_key['api_key']) ?>
                </code>
            </div>
            <button type="button" class="btn btn-secondary" onclick="copyApiKey('<?= htmlspecialchars($new_key['api_key']) ?>')">
                <i class="fas fa-copy"></i> Copy API Key
            </button>
        </div>
    <?php endif; ?>
    
    <div class="table-responsive">
        <table class="table">
            <thead>
                <tr>
                    <th>Key Name</th>
                    <th>Prefix</th>
                    <th>Product</th>
                    <th>Domains</th>
                    <th>Rate Limit</th>
                    <th>Status</th>
                    <th>Last Used</th>
                    <th>Usage</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($api_keys)): ?>
                    <tr>
                        <td colspan="9" class="text-center" style="padding: 40px;">
                            <i class="fas fa-key" style="font-size: 48px; color: var(--text-muted); margin-bottom: 15px;"></i>
                            <p>No API keys found. Generate your first API key to get started.</p>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($api_keys as $key): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($key['key_name']) ?></strong></td>
                            <td><code><?= htmlspecialchars($key['api_key_prefix']) ?></code></td>
                            <td><?= htmlspecialchars($key['product_id']) ?></td>
                            <td>
                                <?php
                                $domains = json_decode($key['allowed_domains'] ?? '[]', true);
                                if (empty($domains)) {
                                    echo '<span class="badge badge-secondary">Any</span>';
                                } else {
                                    echo '<span class="badge badge-info">' . count($domains) . ' domain(s)</span>';
                                }
                                ?>
                            </td>
                            <td>
                                <?= $key['rate_limit_requests'] ?> / <?= $key['rate_limit_window'] ?>s
                            </td>
                            <td>
                                <?php if ($key['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-danger">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($key['last_used_at']): ?>
                                    <?= format_date($key['last_used_at']) ?>
                                    <br><small><?= htmlspecialchars($key['last_used_ip'] ?? '') ?></small>
                                <?php else: ?>
                                    <span class="text-muted">Never</span>
                                <?php endif; ?>
                            </td>
                            <td><?= number_format($key['usage_count'] ?? 0) ?></td>
                            <td>
                                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                                    <button type="button" class="btn btn-sm btn-secondary"
                                            onclick="editKey(
                                                <?= $key['id'] ?>,
                                                '<?= htmlspecialchars($key['key_name']) ?>',
                                                '<?php
                                                $domains = json_decode($key['allowed_domains'] ?? '[]', true);
                                                echo htmlspecialchars(implode(', ', $domains));
                                                ?>',
                                                '<?php
                                                $ips = json_decode($key['allowed_ips'] ?? '[]', true);
                                                echo htmlspecialchars(implode(', ', $ips));
                                                ?>',
                                                '<?= $key['rate_limit_requests'] ?>',
                                                '<?= $key['rate_limit_window'] ?>',
                                                '<?= $key['expires_at'] ? date('Y-m-d\TH:i', strtotime($key['expires_at'])) : '' ?>',
                                                '<?= $key['is_active'] ? '1' : '0' ?>'
                                            )">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($key['is_active']): ?>
                                        <form method="POST" action="api-keys.php" id="revoke-form-<?= $key['id'] ?>" style="display: inline-block; margin: 0; padding: 0;" onsubmit="console.log('Revoke form submitting for key <?= $key['id'] ?>'); return confirm('Apakah Anda yakin ingin menonaktifkan API key ini?');">
                                            <input type="hidden" name="csrf_token" value="<?= $page_csrf_token ?>">
                                            <input type="hidden" name="action" value="revoke">
                                            <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                            <button type="submit" name="revoke_submit" class="btn btn-sm btn-warning" title="Revoke API Key" style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; pointer-events: auto;">
                                                <i class="fas fa-ban"></i> <span>Revoke</span>
                                            </button>
                                        </form>
                                    <?php endif; ?>
                                    <form method="POST" action="api-keys.php" id="delete-form-<?= $key['id'] ?>" style="display: inline-block; margin: 0; padding: 0;" onsubmit="console.log('Delete form submitting for key <?= $key['id'] ?>'); return confirm('Apakah Anda yakin ingin menghapus API key ini? Tindakan ini tidak dapat dibatalkan!');">
                                        <input type="hidden" name="csrf_token" value="<?= $page_csrf_token ?>">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="key_id" value="<?= $key['id'] ?>">
                                        <button type="submit" name="delete_submit" class="btn btn-sm btn-danger" title="Delete API Key" style="display: inline-flex; align-items: center; gap: 4px; cursor: pointer; pointer-events: auto;">
                                            <i class="fas fa-trash"></i> <span>Delete</span>
                                        </button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Generate Modal -->
<div id="generateModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2><i class="fas fa-key"></i> Generate New API Key</h2>
            <button type="button" class="modal-close" onclick="hideModal('generateModal')">&times;</button>
        </div>
        <form method="POST" id="generateForm">
            <input type="hidden" name="csrf_token" value="<?= $page_csrf_token ?>">
            <input type="hidden" name="action" value="generate">
            
            <div class="form-group">
                <label class="form-label">Key Name *</label>
                <input type="text" name="key_name" class="form-control" required placeholder="e.g., Production Key, Development Key">
            </div>
            
            <div class="form-group">
                <label class="form-label">Product ID</label>
                <input type="text" name="product_id" class="form-control" value="apeiron-kit" required>
            </div>
            
            <div class="form-group">
                <label class="form-label">Allowed Domains (comma-separated, optional)</label>
                <input type="text" name="allowed_domains" class="form-control" placeholder="example.com, *.example.com">
                <div class="form-text">Leave empty to allow all domains. Use * for wildcard subdomains.</div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Allowed IPs (comma-separated, optional)</label>
                <input type="text" name="allowed_ips" class="form-control" placeholder="192.168.1.1, 10.0.0.1">
                <div class="form-text">Leave empty to allow all IPs.</div>
            </div>
            
            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">Rate Limit (requests)</label>
                    <input type="number" name="rate_limit_requests" class="form-control" value="100" min="1" required>
                </div>
                
                <div class="form-group">
                    <label class="form-label">Rate Limit Window (seconds)</label>
                    <input type="number" name="rate_limit_window" class="form-control" value="3600" min="1" required>
                </div>
            </div>
            
            <div class="form-group">
                <label class="form-label">Expires At (optional)</label>
                <input type="datetime-local" name="expires_at" class="form-control">
            </div>
            
            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="hideModal('generateModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-key"></i> Generate API Key
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Edit Modal -->
<div id="editModal" class="modal">
    <div class="modal-content" style="max-width: 600px;">
        <div class="modal-header">
            <h2><i class="fas fa-edit"></i> Edit API Key</h2>
            <button type="button" class="modal-close" onclick="hideModal('editModal')">&times;</button>
        </div>
        <form method="POST" id="editForm">
            <input type="hidden" name="csrf_token" value="<?= $page_csrf_token ?>">
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="key_id" id="edit_key_id">

            <div class="form-group">
                <label class="form-label">Key Name *</label>
                <input type="text" name="key_name" id="edit_key_name" class="form-control" required>
            </div>

            <div class="form-group">
                <label class="form-label">Allowed Domains (comma-separated)</label>
                <input type="text" name="allowed_domains" id="edit_allowed_domains" class="form-control" placeholder="example.com, *.example.com">
                <div class="form-text">Leave empty to allow all domains. Use * for wildcard subdomains.</div>
            </div>

            <div class="form-group">
                <label class="form-label">Allowed IPs (comma-separated)</label>
                <input type="text" name="allowed_ips" id="edit_allowed_ips" class="form-control" placeholder="192.168.1.1, 10.0.0.1">
                <div class="form-text">Leave empty to allow all IPs.</div>
            </div>

            <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
                <div class="form-group">
                    <label class="form-label">Rate Limit (requests)</label>
                    <input type="number" name="rate_limit_requests" id="edit_rate_limit_requests" class="form-control" min="1" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Rate Limit Window (seconds)</label>
                    <input type="number" name="rate_limit_window" id="edit_rate_limit_window" class="form-control" min="1" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Expires At</label>
                <input type="datetime-local" name="expires_at" id="edit_expires_at" class="form-control">
                <div class="form-text">Leave empty for no expiration.</div>
            </div>

            <div class="form-group">
                <label class="form-control" style="display: flex; align-items: center; gap: 10px;">
                    <input type="checkbox" name="is_active" id="edit_is_active" value="1" style="margin: 0;">
                    <span>Active</span>
                </label>
            </div>

            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="hideModal('editModal')">Cancel</button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update API Key
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showGenerateModal() {
    document.getElementById('generateModal').classList.add('show');
}

function hideModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

// Close modals when clicking outside
document.addEventListener('click', function(e) {
    const generateModal = document.getElementById('generateModal');
    const editModal = document.getElementById('editModal');

    if (e.target === generateModal) {
        generateModal.classList.remove('show');
    }
    if (e.target === editModal) {
        editModal.classList.remove('show');
    }
});

function copyApiKey(key) {
    navigator.clipboard.writeText(key).then(() => {
        alert('API Key copied to clipboard!');
    });
}

function editKey(keyId, keyName, allowedDomains, allowedIps, rateLimitRequests, rateLimitWindow, expiresAt, isActive) {
    // Populate the edit modal
    document.getElementById('edit_key_id').value = keyId;
    document.getElementById('edit_key_name').value = keyName;
    document.getElementById('edit_allowed_domains').value = allowedDomains;
    document.getElementById('edit_allowed_ips').value = allowedIps;
    document.getElementById('edit_rate_limit_requests').value = rateLimitRequests;
    document.getElementById('edit_rate_limit_window').value = rateLimitWindow;
    document.getElementById('edit_expires_at').value = expiresAt;
    document.getElementById('edit_is_active').checked = (isActive === '1');

    // Show the modal
    document.getElementById('editModal').classList.add('show');
}

// Ensure all forms can submit properly
document.addEventListener('DOMContentLoaded', function() {
    // Add event listeners to all revoke and delete forms
    document.querySelectorAll('form[id^="revoke-form-"], form[id^="delete-form-"]').forEach(function(form) {
        form.addEventListener('submit', function(e) {
            console.log('Form submitting:', form.id, form.action);
            // Let the form submit naturally
        });
    });

    // Debug: Log all buttons
    document.querySelectorAll('button[type="submit"]').forEach(function(btn) {
        btn.addEventListener('click', function(e) {
            console.log('Submit button clicked:', btn.name, btn.form ? btn.form.id : 'no form');
        });
    });
});
</script>

<?php include '../includes/footer.php'; ?>

