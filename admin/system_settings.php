<?php
/**
 * ตั้งค่าระบบ
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

$pageTitle = 'ตั้งค่าระบบ';

// สร้างตาราง system_settings ถ้าไม่มี
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `system_settings` (
            `setting_id` int(11) NOT NULL AUTO_INCREMENT,
            `setting_key` varchar(50) NOT NULL,
            `setting_value` text DEFAULT NULL,
            `description` text DEFAULT NULL,
            `updated_at` datetime DEFAULT NULL ON UPDATE current_timestamp(),
            PRIMARY KEY (`setting_id`),
            UNIQUE KEY `setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    
} catch (Exception $e) {
    writeLog("Error creating system_settings table: " . $e->getMessage());
}

// ตั้งค่าเริ่มต้น
$defaultSettings = [
    'site_name' => [
        'value' => SITE_NAME,
        'description' => 'ชื่อเว็บไซต์',
        'type' => 'text'
    ],
    'site_description' => [
        'value' => SITE_DESCRIPTION,
        'description' => 'คำอธิบายเว็บไซต์',
        'type' => 'textarea'
    ],
    'currency' => [
        'value' => 'THB',
        'description' => 'สกุลเงิน',
        'type' => 'select',
        'options' => ['THB' => 'บาท (THB)', 'USD' => 'ดอลลาร์ (USD)', 'EUR' => 'ยูโร (EUR)']
    ],
    'timezone' => [
        'value' => 'Asia/Bangkok',
        'description' => 'เขตเวลา',
        'type' => 'select',
        'options' => [
            'Asia/Bangkok' => 'เอเชีย/กรุงเทพ',
            'UTC' => 'UTC',
            'Asia/Tokyo' => 'เอเชีย/โตเกียว'
        ]
    ],
    'enable_registration' => [
        'value' => '1',
        'description' => 'เปิดให้ลูกค้าสมัครสมาชิก',
        'type' => 'boolean'
    ],
    'enable_voice_queue' => [
        'value' => '1',
        'description' => 'เปิดใช้งานระบบเรียกคิวด้วยเสียง',
        'type' => 'boolean'
    ],
    'enable_line_notification' => [
        'value' => '0',
        'description' => 'เปิดใช้งานการแจ้งเตือนผ่าน LINE',
        'type' => 'boolean'
    ],
    'queue_prefix' => [
        'value' => 'Q',
        'description' => 'คำนำหน้าหมายเลขคิว',
        'type' => 'text'
    ],
    'default_preparation_time' => [
        'value' => '15',
        'description' => 'เวลาเตรียมอาหารเริ่มต้น (นาที)',
        'type' => 'number'
    ],
    'max_queue_per_day' => [
        'value' => '999',
        'description' => 'จำนวนคิวสูงสุดต่อวัน',
        'type' => 'number'
    ],
    'auto_print_receipt' => [
        'value' => '1',
        'description' => 'พิมพ์ใบเสร็จอัตโนมัติ',
        'type' => 'boolean'
    ],
    'printer_enabled' => [
        'value' => '0',
        'description' => 'เปิดใช้งานเครื่องพิมพ์',
        'type' => 'boolean'
    ],
    'tax_rate' => [
        'value' => '7',
        'description' => 'อัตราภาษี (%)',
        'type' => 'number'
    ],
    'service_charge' => [
        'value' => '0',
        'description' => 'ค่าบริการ (%)',
        'type' => 'number'
    ],
    'min_order_amount' => [
        'value' => '0',
        'description' => 'ยอดสั่งซื้อขั้นต่ำ (บาท)',
        'type' => 'number'
    ]
];

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: system_settings.php');
        exit();
    }
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        foreach ($defaultSettings as $key => $setting) {
            $value = $_POST[$key] ?? $setting['value'];
            
            // Validation
            if ($setting['type'] === 'boolean') {
                $value = isset($_POST[$key]) ? '1' : '0';
            } elseif ($setting['type'] === 'number') {
                $value = floatval($value);
            }
            
            // Update or insert setting
            $stmt = $conn->prepare("
                INSERT INTO system_settings (setting_key, setting_value, description, updated_at) 
                VALUES (?, ?, ?, NOW())
                ON DUPLICATE KEY UPDATE 
                setting_value = VALUES(setting_value), 
                updated_at = NOW()
            ");
            $stmt->execute([$key, $value, $setting['description']]);
        }
        
        setFlashMessage('success', 'บันทึกการตั้งค่าสำเร็จ');
        writeLog("System settings updated by " . getCurrentUser()['username']);
        
    } catch (Exception $e) {
        setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        writeLog("Error updating system settings: " . $e->getMessage());
    }
    
    header('Location: system_settings.php');
    exit();
}

// ดึงการตั้งค่าปัจจุบัน
$currentSettings = [];
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
    $stmt->execute();
    $settings = $stmt->fetchAll();
    
    foreach ($settings as $setting) {
        $currentSettings[$setting['setting_key']] = $setting['setting_value'];
    }
    
} catch (Exception $e) {
    writeLog("Error loading system settings: " . $e->getMessage());
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">ตั้งค่าระบบ</h1>
        <p class="text-muted mb-0">กำหนดค่าต่างๆ สำหรับการทำงานของระบบ</p>
    </div>
    <div>
        <a href="system_check.php" class="btn btn-secondary me-2">
            <i class="fas fa-stethoscope me-2"></i>ตรวจสอบระบบ
        </a>
        <button type="submit" form="settingsForm" class="btn btn-primary">
            <i class="fas fa-save me-2"></i>บันทึกการตั้งค่า
        </button>
    </div>
</div>

<form method="POST" id="settingsForm">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    
    <div class="row">
        <!-- General Settings -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-cog me-2"></i>การตั้งค่าทั่วไป
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="site_name" class="form-label">ชื่อเว็บไซต์</label>
                        <input type="text" class="form-control" id="site_name" name="site_name" 
                               value="<?php echo clean($currentSettings['site_name'] ?? $defaultSettings['site_name']['value']); ?>" required>
                        <small class="text-muted"><?php echo $defaultSettings['site_name']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="site_description" class="form-label">คำอธิบายเว็บไซต์</label>
                        <textarea class="form-control" id="site_description" name="site_description" rows="3"><?php echo clean($currentSettings['site_description'] ?? $defaultSettings['site_description']['value']); ?></textarea>
                        <small class="text-muted"><?php echo $defaultSettings['site_description']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="currency" class="form-label">สกุลเงิน</label>
                        <select class="form-select" id="currency" name="currency">
                            <?php foreach ($defaultSettings['currency']['options'] as $value => $label): ?>
                                <option value="<?php echo $value; ?>" 
                                        <?php echo ($currentSettings['currency'] ?? $defaultSettings['currency']['value']) === $value ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><?php echo $defaultSettings['currency']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="timezone" class="form-label">เขตเวลา</label>
                        <select class="form-select" id="timezone" name="timezone">
                            <?php foreach ($defaultSettings['timezone']['options'] as $value => $label): ?>
                                <option value="<?php echo $value; ?>" 
                                        <?php echo ($currentSettings['timezone'] ?? $defaultSettings['timezone']['value']) === $value ? 'selected' : ''; ?>>
                                    <?php echo $label; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <small class="text-muted"><?php echo $defaultSettings['timezone']['description']; ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Feature Settings -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-toggle-on me-2"></i>ฟีเจอร์ระบบ
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enable_registration" name="enable_registration" 
                                   <?php echo ($currentSettings['enable_registration'] ?? $defaultSettings['enable_registration']['value']) === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="enable_registration">
                                เปิดให้ลูกค้าสมัครสมาชิก
                            </label>
                        </div>
                        <small class="text-muted d-block"><?php echo $defaultSettings['enable_registration']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enable_voice_queue" name="enable_voice_queue" 
                                   <?php echo ($currentSettings['enable_voice_queue'] ?? $defaultSettings['enable_voice_queue']['value']) === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="enable_voice_queue">
                                เปิดใช้งานระบบเรียกคิวด้วยเสียง
                            </label>
                        </div>
                        <small class="text-muted d-block"><?php echo $defaultSettings['enable_voice_queue']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="enable_line_notification" name="enable_line_notification" 
                                   <?php echo ($currentSettings['enable_line_notification'] ?? $defaultSettings['enable_line_notification']['value']) === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="enable_line_notification">
                                เปิดใช้งานการแจ้งเตือนผ่าน LINE
                            </label>
                        </div>
                        <small class="text-muted d-block"><?php echo $defaultSettings['enable_line_notification']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="auto_print_receipt" name="auto_print_receipt" 
                                   <?php echo ($currentSettings['auto_print_receipt'] ?? $defaultSettings['auto_print_receipt']['value']) === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="auto_print_receipt">
                                พิมพ์ใบเสร็จอัตโนมัติ
                            </label>
                        </div>
                        <small class="text-muted d-block"><?php echo $defaultSettings['auto_print_receipt']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" id="printer_enabled" name="printer_enabled" 
                                   <?php echo ($currentSettings['printer_enabled'] ?? $defaultSettings['printer_enabled']['value']) === '1' ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="printer_enabled">
                                เปิดใช้งานเครื่องพิมพ์
                            </label>
                        </div>
                        <small class="text-muted d-block"><?php echo $defaultSettings['printer_enabled']['description']; ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Queue Settings -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-clock me-2"></i>การตั้งค่าคิว
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="queue_prefix" class="form-label">คำนำหน้าหมายเลขคิว</label>
                        <input type="text" class="form-control" id="queue_prefix" name="queue_prefix" maxlength="3"
                               value="<?php echo clean($currentSettings['queue_prefix'] ?? $defaultSettings['queue_prefix']['value']); ?>" required>
                        <small class="text-muted"><?php echo $defaultSettings['queue_prefix']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="default_preparation_time" class="form-label">เวลาเตรียมอาหารเริ่มต้น (นาที)</label>
                        <input type="number" class="form-control" id="default_preparation_time" name="default_preparation_time" min="1" max="120"
                               value="<?php echo clean($currentSettings['default_preparation_time'] ?? $defaultSettings['default_preparation_time']['value']); ?>" required>
                        <small class="text-muted"><?php echo $defaultSettings['default_preparation_time']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="max_queue_per_day" class="form-label">จำนวนคิวสูงสุดต่อวัน</label>
                        <input type="number" class="form-control" id="max_queue_per_day" name="max_queue_per_day" min="1" max="9999"
                               value="<?php echo clean($currentSettings['max_queue_per_day'] ?? $defaultSettings['max_queue_per_day']['value']); ?>" required>
                        <small class="text-muted"><?php echo $defaultSettings['max_queue_per_day']['description']; ?></small>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Financial Settings -->
        <div class="col-lg-6 mb-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-money-bill-wave me-2"></i>การตั้งค่าทางการเงิน
                    </h5>
                </div>
                <div class="card-body">
                    <div class="mb-3">
                        <label for="tax_rate" class="form-label">อัตราภาษี (%)</label>
                        <input type="number" class="form-control" id="tax_rate" name="tax_rate" min="0" max="100" step="0.01"
                               value="<?php echo clean($currentSettings['tax_rate'] ?? $defaultSettings['tax_rate']['value']); ?>">
                        <small class="text-muted"><?php echo $defaultSettings['tax_rate']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="service_charge" class="form-label">ค่าบริการ (%)</label>
                        <input type="number" class="form-control" id="service_charge" name="service_charge" min="0" max="100" step="0.01"
                               value="<?php echo clean($currentSettings['service_charge'] ?? $defaultSettings['service_charge']['value']); ?>">
                        <small class="text-muted"><?php echo $defaultSettings['service_charge']['description']; ?></small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="min_order_amount" class="form-label">ยอดสั่งซื้อขั้นต่ำ (บาท)</label>
                        <input type="number" class="form-control" id="min_order_amount" name="min_order_amount" min="0" step="0.01"
                               value="<?php echo clean($currentSettings['min_order_amount'] ?? $defaultSettings['min_order_amount']['value']); ?>">
                        <small class="text-muted"><?php echo $defaultSettings['min_order_amount']['description']; ?></small>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Save Button (Sticky) -->
    <div class="sticky-bottom bg-white border-top p-3 mt-4">
        <div class="d-flex justify-content-between align-items-center">
            <small class="text-muted">
                <i class="fas fa-info-circle me-1"></i>
                การเปลี่ยนแปลงบางอย่างอาจต้องรีสตาร์ทระบบ
            </small>
            <div>
                <button type="button" class="btn btn-secondary me-2" onclick="location.reload()">
                    <i class="fas fa-undo me-2"></i>รีเซ็ต
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>บันทึกการตั้งค่า
                </button>
            </div>
        </div>
    </div>
</form>

<?php
$inlineJS = "
// Form validation
$('#settingsForm').on('submit', function(e) {
    const queuePrefix = $('#queue_prefix').val().trim();
    const preparationTime = parseInt($('#default_preparation_time').val());
    const maxQueue = parseInt($('#max_queue_per_day').val());
    
    if (!queuePrefix || queuePrefix.length === 0) {
        e.preventDefault();
        alert('กรุณากรอกคำนำหน้าหมายเลขคิว');
        $('#queue_prefix').focus();
        return false;
    }
    
    if (preparationTime < 1 || preparationTime > 120) {
        e.preventDefault();
        alert('เวลาเตรียมอาหารต้องอยู่ระหว่าง 1-120 นาที');
        $('#default_preparation_time').focus();
        return false;
    }
    
    if (maxQueue < 1 || maxQueue > 9999) {
        e.preventDefault();
        alert('จำนวนคิวสูงสุดต้องอยู่ระหว่าง 1-9999');
        $('#max_queue_per_day').focus();
        return false;
    }
    
    // Show loading
    const submitBtn = $(this).find('button[type=\"submit\"]');
    submitBtn.prop('disabled', true).html('<i class=\"fas fa-spinner fa-spin me-2\"></i>กำลังบันทึก...');
});

// Switch animations
$('.form-check-input').on('change', function() {
    $(this).closest('.form-check').addClass('animate__animated animate__pulse');
    setTimeout(() => {
        $(this).closest('.form-check').removeClass('animate__animated animate__pulse');
    }, 1000);
});

// Real-time preview for queue prefix
$('#queue_prefix').on('input', function() {
    const prefix = $(this).val().toUpperCase();
    $(this).val(prefix);
    
    if (prefix) {
        const preview = prefix + '001';
        $(this).next('.text-muted').html('ตัวอย่าง: <strong>' + preview + '</strong>');
    }
});

// Initialize queue prefix preview
$('#queue_prefix').trigger('input');

console.log('System Settings loaded successfully');
";

require_once '../includes/footer.php';
?>