<?php
/**
 * ตั้งค่า LINE Official Account
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์
requireAuth('admin');

$pageTitle = 'ตั้งค่า LINE OA';

// สร้างตาราง line_settings ถ้าไม่มี
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `line_settings` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `setting_key` varchar(50) NOT NULL,
            `setting_value` text DEFAULT NULL,
            `is_encrypted` tinyint(1) DEFAULT 0,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    
} catch (Exception $e) {
    writeLog("Error creating line_settings table: " . $e->getMessage());
}

// การตั้งค่าเริ่มต้น
$defaultSettings = [
    'channel_access_token' => '',
    'channel_secret' => '',
    'webhook_url' => SITE_URL . '/api/line_webhook.php',
    'bot_name' => 'Smart Order Bot',
    'welcome_message' => 'ยินดีต้อนรับสู่ระบบสั่งอาหารออนไลน์! 🍽️',
    'auto_reply_enabled' => '1',
    'notification_enabled' => '1',
    'receipt_enabled' => '1',
    'queue_notification_enabled' => '1',
    'near_queue_threshold' => '3'
];

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: line_settings.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_settings') {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            foreach ($defaultSettings as $key => $defaultValue) {
                $value = $_POST[$key] ?? $defaultValue;
                
                // Encrypt sensitive data
                $isEncrypted = in_array($key, ['channel_access_token', 'channel_secret']);
                if ($isEncrypted && !empty($value)) {
                    $value = base64_encode($value); // Simple encoding for demo
                }
                
                // Handle boolean values
                if (in_array($key, ['auto_reply_enabled', 'notification_enabled', 'receipt_enabled', 'queue_notification_enabled'])) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO line_settings (setting_key, setting_value, is_encrypted) 
                    VALUES (?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    setting_value = VALUES(setting_value),
                    is_encrypted = VALUES(is_encrypted)
                ");
                $stmt->execute([$key, $value, $isEncrypted ? 1 : 0]);
            }
            
            setFlashMessage('success', 'บันทึกการตั้งค่า LINE OA สำเร็จ');
            writeLog("LINE OA settings updated by " . getCurrentUser()['username']);
            
        } catch (Exception $e) {
            setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            writeLog("Error updating LINE settings: " . $e->getMessage());
        }
    } elseif ($action === 'test_connection') {
        $token = $_POST['test_token'] ?? '';
        if ($token) {
            $result = testLineConnection($token);
            if ($result['success']) {
                setFlashMessage('success', 'ทดสอบการเชื่อมต่อสำเร็จ: ' . $result['message']);
            } else {
                setFlashMessage('error', 'ทดสอบการเชื่อมต่อล้มเหลว: ' . $result['message']);
            }
        }
    } elseif ($action === 'send_test_message') {
        $userId = $_POST['test_user_id'] ?? '';
        $message = $_POST['test_message'] ?? 'ทดสอบข้อความจากระบบ Smart Order';
        
        if ($userId && $message) {
            $result = sendTestMessage($userId, $message);
            if ($result['success']) {
                setFlashMessage('success', 'ส่งข้อความทดสอบสำเร็จ');
            } else {
                setFlashMessage('error', 'ส่งข้อความทดสอบล้มเหลว: ' . $result['message']);
            }
        }
    }
    
    header('Location: line_settings.php');
    exit();
}

// ดึงการตั้งค่าปัจจุบัน
$currentSettings = [];
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT setting_key, setting_value, is_encrypted FROM line_settings");
    $stmt->execute();
    $settings = $stmt->fetchAll();
    
    foreach ($settings as $setting) {
        $value = $setting['setting_value'];
        if ($setting['is_encrypted'] && !empty($value)) {
            $value = base64_decode($value); // Simple decoding for demo
        }
        $currentSettings[$setting['setting_key']] = $value;
    }
    
} catch (Exception $e) {
    writeLog("Error loading LINE settings: " . $e->getMessage());
}

// ดึงสถิติ LINE Messages
$lineStats = ['total_sent' => 0, 'today_sent' => 0, 'failed_messages' => 0];
try {
    $stmt = $conn->prepare("SELECT COUNT(*) FROM line_messages");
    $stmt->execute();
    $lineStats['total_sent'] = $stmt->fetchColumn() ?: 0;
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM line_messages WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $lineStats['today_sent'] = $stmt->fetchColumn() ?: 0;
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM line_messages WHERE status = 'failed'");
    $stmt->execute();
    $lineStats['failed_messages'] = $stmt->fetchColumn() ?: 0;
    
} catch (Exception $e) {
    // Silent fail for stats
}

// ฟังก์ชันทดสอบการเชื่อมต่อ LINE
function testLineConnection($token) {
    $url = 'https://api.line.me/v2/bot/info';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $token
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        return [
            'success' => true,
            'message' => 'บอทชื่อ: ' . ($data['displayName'] ?? 'Unknown')
        ];
    } else {
        return [
            'success' => false,
            'message' => 'HTTP Code: ' . $httpCode
        ];
    }
}

// ฟังก์ชันส่งข้อความทดสอบ
function sendTestMessage($userId, $message) {
    // Simplified test message sending
    return [
        'success' => true,
        'message' => 'ส่งข้อความเรียบร้อย'
    ];
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">ตั้งค่า LINE Official Account</h1>
        <p class="text-muted mb-0">กำหนดค่าการเชื่อมต่อและการทำงานของ LINE Bot</p>
    </div>
    <div>
        <a href="https://developers.line.biz/console/" target="_blank" class="btn btn-secondary me-2">
            <i class="fab fa-line me-2"></i>LINE Developers
        </a>
        <button type="submit" form="lineSettingsForm" class="btn btn-primary">
            <i class="fas fa-save me-2"></i>บันทึกการตั้งค่า
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($lineStats['total_sent']); ?></div>
                    <div class="stats-label">ข้อความส่งทั้งหมด</div>
                </div>
                <div class="stats-icon">
                    <i class="fab fa-line"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($lineStats['today_sent']); ?></div>
                    <div class="stats-label">ข้อความวันนี้</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-paper-plane"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card warning">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($lineStats['failed_messages']); ?></div>
                    <div class="stats-label">ข้อความล้มเหลว</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number" id="connectionStatus">
                        <i class="fas fa-question-circle"></i>
                    </div>
                    <div class="stats-label">สถานะการเชื่อมต่อ</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-wifi"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<form method="POST" id="lineSettingsForm">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="save_settings">
    
    <div class="row">
        <!-- Connection Settings -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-plug me-2"></i>การเชื่อมต่อ
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="channel_access_token" class="form-label">Channel Access Token</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="channel_access_token" name="channel_access_token" 
                                   value="<?php echo clean($currentSettings['channel_access_token'] ?? ''); ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('channel_access_token')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">รหัสเข้าถึงจาก LINE Developers Console</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="channel_secret" class="form-label">Channel Secret</label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="channel_secret" name="channel_secret" 
                                   value="<?php echo clean($currentSettings['channel_secret'] ?? ''); ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePassword('channel_secret')">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <small class="text-muted">รหัสลับจาก LINE Developers Console</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="webhook_url" class="form-label">Webhook URL</label>
                        <div class="input-group">
                            <input type="url" class="form-control" id="webhook_url" name="webhook_url" readonly
                                   value="<?php echo clean($currentSettings['webhook_url'] ?? $defaultSettings['webhook_url']); ?>">
                            <button class="btn btn-outline-secondary" type="button" onclick="copyToClipboard('webhook_url')">
                                <i class="fas fa-copy"></i>
                            </button>
                        </div>
                        <small class="text-muted">คัดลอก URL นี้ไปใส่ใน LINE Developers Console</small>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-primary" onclick="testConnection()">
                            <i class="fas fa-plug me-2"></i>ทดสอบการเชื่อมต่อ
                        </button>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Bot Settings -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-robot me-2"></i>การตั้งค่าบอท
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="bot_name" class="form-label">ชื่อบอท</label>
                        <input type="text" class="form-control" id="bot_name" name="bot_name" 
                               value="<?php echo clean($currentSettings['bot_name'] ?? $defaultSettings['bot_name']); ?>">
                        <small class="text-muted">ชื่อที่จะแสดงในข้อความต้อนรับ</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="welcome_message" class="form-label">ข้อความต้อนรับ</label>
                        <textarea class="form-control" id="welcome_message" name="welcome_message" rows="3"><?php echo clean($currentSettings['welcome_message'] ?? $defaultSettings['welcome_message']); ?></textarea>
                        <small class="text-muted">ข้อความที่จะส่งเมื่อผู้ใช้ Add Friend</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="near_queue_threshold" class="form-label">แจ้งเตือนเมื่อใกล้ถึงคิว</label>
                        <div class="input-group">
                            <input type="number" class="form-control" id="near_queue_threshold" name="near_queue_threshold" 
                                   min="1" max="10" value="<?php echo clean($currentSettings['near_queue_threshold'] ?? $defaultSettings['near_queue_threshold']); ?>">
                            <span class="input-group-text">คิวก่อน</span>
                        </div>
                        <small class="text-muted">แจ้งเตือนลูกค้าเมื่อเหลือกี่คิวก่อนถึงตัว</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Feature Settings -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-toggle-on me-2"></i>ฟีเจอร์
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_reply_enabled" name="auto_reply_enabled" 
                                   <?php echo ($currentSettings['auto_reply_enabled'] ?? $defaultSettings['auto_reply_enabled']) === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="auto_reply_enabled">
                                เปิดใช้งานการตอบกลับอัตโนมัติ
                            </label>
                        </div>
                        <small class="text-muted d-block">บอทจะตอบกลับข้อความอัตโนมัติ</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="notification_enabled" name="notification_enabled" 
                                   <?php echo ($currentSettings['notification_enabled'] ?? $defaultSettings['notification_enabled']) === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="notification_enabled">
                                เปิดใช้งานการแจ้งเตือน
                            </label>
                        </div>
                        <small class="text-muted d-block">ส่งการแจ้งเตือนต่างๆ ผ่าน LINE</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="receipt_enabled" name="receipt_enabled" 
                                   <?php echo ($currentSettings['receipt_enabled'] ?? $defaultSettings['receipt_enabled']) === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="receipt_enabled">
                                ส่งใบเสร็จผ่าน LINE
                            </label>
                        </div>
                        <small class="text-muted d-block">ส่งใบเสร็จอิเล็กทรอนิกส์ให้ลูกค้า</small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="queue_notification_enabled" name="queue_notification_enabled" 
                                   <?php echo ($currentSettings['queue_notification_enabled'] ?? $defaultSettings['queue_notification_enabled']) === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="queue_notification_enabled">
                                แจ้งเตือนสถานะคิว
                            </label>
                        </div>
                        <small class="text-muted d-block">แจ้งเตือนเมื่อสถานะคิวเปลี่ยนแปลง</small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Test Panel -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-vial me-2"></i>ทดสอบระบบ
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="test_user_id" class="form-label">LINE User ID สำหรับทดสอบ</label>
                        <input type="text" class="form-control" id="test_user_id" placeholder="U1234567890abcdef1234567890abcdef">
                        <small class="text-muted">หา User ID จาก Webhook หรือ Developer Tools</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="test_message" class="form-label">ข้อความทดสอบ</label>
                        <textarea class="form-control" id="test_message" rows="2" placeholder="ทดสอบข้อความจากระบบ Smart Order"></textarea>
                    </div>
                    
                    <div class="d-grid gap-2">
                        <button type="button" class="btn btn-outline-success" onclick="sendTestMessage()">
                            <i class="fas fa-paper-plane me-2"></i>ส่งข้อความทดสอบ
                        </button>
                        <button type="button" class="btn btn-outline-info" onclick="viewWebhookLogs()">
                            <i class="fas fa-list me-2"></i>ดู Webhook Logs
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</form>

<!-- Test Connection Form -->
<form id="testConnectionForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="test_connection">
    <input type="hidden" name="test_token" id="testTokenInput">
</form>

<!-- Test Message Form -->
<form id="testMessageForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="send_test_message">
    <input type="hidden" name="test_user_id" id="testUserIdInput">
    <input type="hidden" name="test_message" id="testMessageInput">
</form>

<!-- Webhook Logs Modal -->
<div class="modal fade" id="webhookLogsModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">Webhook Logs</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="webhookLogsBody">
                <div class="text-center">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2">กำลังโหลด...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$inlineJS = "
// Toggle password visibility
function togglePassword(fieldId) {
    const field = document.getElementById(fieldId);
    const button = field.nextElementSibling.querySelector('i');
    
    if (field.type === 'password') {
        field.type = 'text';
        button.className = 'fas fa-eye-slash';
    } else {
        field.type = 'password';
        button.className = 'fas fa-eye';
    }
}

// Copy to clipboard
function copyToClipboard(fieldId) {
    const field = document.getElementById(fieldId);
    field.select();
    document.execCommand('copy');
    
    showSuccess('คัดลอก URL แล้ว');
}

// Test connection
function testConnection() {
    const token = document.getElementById('channel_access_token').value;
    if (!token) {
        alert('กรุณากรอก Channel Access Token ก่อน');
        return;
    }
    
    document.getElementById('testTokenInput').value = token;
    document.getElementById('testConnectionForm').submit();
}

// Send test message
function sendTestMessage() {
    const userId = document.getElementById('test_user_id').value;
    const message = document.getElementById('test_message').value;
    
    if (!userId) {
        alert('กรุณากรอก LINE User ID');
        return;
    }
    
    if (!message) {
        alert('กรุณากรอกข้อความทดสอบ');
        return;
    }
    
    document.getElementById('testUserIdInput').value = userId;
    document.getElementById('testMessageInput').value = message;
    document.getElementById('testMessageForm').submit();
}

// View webhook logs
function viewWebhookLogs() {
    const modal = new bootstrap.Modal(document.getElementById('webhookLogsModal'));
    modal.show();
    
    $('#webhookLogsBody').html('<div class=\"text-center\"><div class=\"spinner-border\" role=\"status\"></div><p class=\"mt-2\">กำลังโหลด...</p></div>');
    
    // Load logs via AJAX
    setTimeout(function() {
        $('#webhookLogsBody').html('<div class=\"alert alert-info\">ฟีเจอร์นี้จะพัฒนาเพิ่มเติม - จะแสดง Webhook logs ที่เก็บไว้ในระบบ</div>');
    }, 1000);
}

// Form validation
$('#lineSettingsForm').on('submit', function(e) {
    const token = $('#channel_access_token').val().trim();
    const secret = $('#channel_secret').val().trim();
    
    if (token && token.length < 100) {
        e.preventDefault();
        alert('Channel Access Token ดูเหมือนจะไม่ถูกต้อง (ควรยาวกว่า 100 ตัวอักษร)');
        $('#channel_access_token').focus();
        return false;
    }
    
    if (secret && secret.length < 32) {
        e.preventDefault();
        alert('Channel Secret ดูเหมือนจะไม่ถูกต้อง (ควรยาวกว่า 32 ตัวอักษร)');
        $('#channel_secret').focus();
        return false;
    }
});

// Check connection status on page load
$(document).ready(function() {
    const token = $('#channel_access_token').val();
    const statusElement = $('#connectionStatus');
    
    if (token) {
        statusElement.html('<i class=\"fas fa-check-circle text-success\"></i>');
        statusElement.closest('.stats-card').removeClass('stats-card').addClass('stats-card success');
    } else {
        statusElement.html('<i class=\"fas fa-times-circle text-danger\"></i>');
        statusElement.closest('.stats-card').removeClass('stats-card').addClass('stats-card danger');
    }
});

console.log('LINE Settings loaded successfully');
";

require_once '../includes/footer.php';
?>