<?php
/**
 * ตั้งค่าการชำระเงิน
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

/**
 * PromptPay QR Code Generator Class
 */
class PromptPayQRGenerator {
    
    public static function generateQRData($promptPayId, $amount = null, $merchantName = '') {
        $formattedId = self::formatPromptPayId($promptPayId);
        if (!$formattedId) {
            throw new Exception('รูปแบบหมายเลข PromptPay ไม่ถูกต้อง');
        }
        
        $qrData = '';
        $qrData .= self::createTLV('00', '01');
        $qrData .= self::createTLV('01', $amount ? '12' : '11');
        
        $merchantInfo = '';
        $merchantInfo .= self::createTLV('00', 'A000000677010111');
        $merchantInfo .= self::createTLV('01', $formattedId);
        $qrData .= self::createTLV('29', $merchantInfo);
        
        $qrData .= self::createTLV('53', '764');
        
        if ($amount && $amount > 0) {
            $qrData .= self::createTLV('54', number_format($amount, 2, '.', ''));
        }
        
        $qrData .= self::createTLV('58', 'TH');
        
        if ($merchantName) {
            $merchantName = substr(strtoupper($merchantName), 0, 25);
            $qrData .= self::createTLV('59', $merchantName);
        }
        
        $qrData .= '6304';
        $crc = self::calculateCRC16($qrData);
        $qrData .= strtoupper(str_pad(dechex($crc), 4, '0', STR_PAD_LEFT));
        
        return $qrData;
    }
    
    public static function generateQRImageUrl($promptPayId, $amount = null, $merchantName = '', $size = 300) {
        $qrData = self::generateQRData($promptPayId, $amount, $merchantName);
        $encodedData = urlencode($qrData);
        return "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data={$encodedData}";
    }
    
    private static function formatPromptPayId($id) {
        $id = preg_replace('/[^0-9]/', '', $id);
        
        if (strlen($id) == 10) {
            return '0066' . substr($id, 1);
        } elseif (strlen($id) == 13) {
            return $id;
        }
        
        return false;
    }
    
    private static function createTLV($tag, $value) {
        $length = strlen($value);
        return $tag . str_pad($length, 2, '0', STR_PAD_LEFT) . $value;
    }
    
    private static function calculateCRC16($data) {
        $crc = 0xFFFF;
        
        for ($i = 0; $i < strlen($data); $i++) {
            $crc ^= ord($data[$i]) << 8;
            
            for ($j = 0; $j < 8; $j++) {
                if ($crc & 0x8000) {
                    $crc = ($crc << 1) ^ 0x1021;
                } else {
                    $crc = $crc << 1;
                }
                $crc &= 0xFFFF;
            }
        }
        
        return $crc;
    }
}

// ตรวจสอบสิทธิ์
requireAuth('admin');

$pageTitle = 'ตั้งค่าการชำระเงิน';

// สร้างตาราง payment_methods ถ้าไม่มี
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `payment_methods` (
            `method_id` int(11) NOT NULL AUTO_INCREMENT,
            `method_code` varchar(20) NOT NULL,
            `method_name` varchar(100) NOT NULL,
            `description` text DEFAULT NULL,
            `is_enabled` tinyint(1) DEFAULT 1,
            `settings` json DEFAULT NULL,
            `display_order` int(11) DEFAULT 0,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`method_id`),
            UNIQUE KEY `method_code` (`method_code`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    
    // สร้างตาราง payment_settings ถ้าไม่มี
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `payment_settings` (
            `setting_id` int(11) NOT NULL AUTO_INCREMENT,
            `setting_key` varchar(50) NOT NULL,
            `setting_value` text DEFAULT NULL,
            `description` text DEFAULT NULL,
            `category` varchar(30) DEFAULT 'general',
            `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`setting_id`),
            UNIQUE KEY `setting_key` (`setting_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    
} catch (Exception $e) {
    writeLog("Error creating payment tables: " . $e->getMessage());
}

// ข้อมูลวิธีการชำระเงินเริ่มต้น
$defaultPaymentMethods = [
    [
        'method_code' => 'cash',
        'method_name' => 'เงินสด',
        'description' => 'การชำระเงินด้วยเงินสด',
        'is_enabled' => 1,
        'display_order' => 1
    ],
    [
        'method_code' => 'promptpay',
        'method_name' => 'PromptPay QR',
        'description' => 'การชำระเงินผ่าน PromptPay QR Code',
        'is_enabled' => 1,
        'display_order' => 2
    ],
    [
        'method_code' => 'credit_card',
        'method_name' => 'บัตรเครดิต/เดบิต',
        'description' => 'การชำระเงินด้วยบัตรเครดิตหรือเดบิต',
        'is_enabled' => 0,
        'display_order' => 3
    ],
    [
        'method_code' => 'line_pay',
        'method_name' => 'LINE Pay',
        'description' => 'การชำระเงินผ่าน LINE Pay',
        'is_enabled' => 0,
        'display_order' => 4
    ]
];

// ตั้งค่าการชำระเงินเริ่มต้น
$defaultPaymentSettings = [
    // PromptPay Settings
    'promptpay_id' => [
        'value' => '',
        'description' => 'หมายเลขโทรศัพท์หรือเลขประจำตัวประชาชนสำหรับ PromptPay',
        'category' => 'promptpay'
    ],
    'promptpay_name' => [
        'value' => '',
        'description' => 'ชื่อบัญชี PromptPay',
        'category' => 'promptpay'
    ],
    
    // Tax & Service
    'enable_tax' => [
        'value' => '1',
        'description' => 'เปิดใช้งานการคิดภาษี',
        'category' => 'tax'
    ],
    'tax_rate' => [
        'value' => '7.00',
        'description' => 'อัตราภาษี (%)',
        'category' => 'tax'
    ],
    'tax_included' => [
        'value' => '0',
        'description' => 'ราคาสินค้ารวมภาษีแล้ว',
        'category' => 'tax'
    ],
    'enable_service_charge' => [
        'value' => '0',
        'description' => 'เปิดใช้งานค่าบริการ',
        'category' => 'service'
    ],
    'service_charge_rate' => [
        'value' => '10.00',
        'description' => 'อัตราค่าบริการ (%)',
        'category' => 'service'
    ],
    
    // Receipt Settings
    'receipt_header' => [
        'value' => 'ใบเสร็จรับเงิน',
        'description' => 'หัวข้อใบเสร็จ',
        'category' => 'receipt'
    ],
    'receipt_footer' => [
        'value' => 'ขอบคุณที่ใช้บริการ',
        'description' => 'ข้อความท้ายใบเสร็จ',
        'category' => 'receipt'
    ],
    'company_name' => [
        'value' => '',
        'description' => 'ชื่อบริษัท/ร้านค้า',
        'category' => 'receipt'
    ],
    'company_address' => [
        'value' => '',
        'description' => 'ที่อยู่บริษัท/ร้านค้า',
        'category' => 'receipt'
    ],
    'company_phone' => [
        'value' => '',
        'description' => 'เบอร์โทรศัพท์',
        'category' => 'receipt'
    ],
    'tax_id' => [
        'value' => '',
        'description' => 'เลขประจำตัวผู้เสียภาษี',
        'category' => 'receipt'
    ],
    
    // Printer Settings
    'auto_print_receipt' => [
        'value' => '1',
        'description' => 'พิมพ์ใบเสร็จอัตโนมัติ',
        'category' => 'printer'
    ],
    'printer_enabled' => [
        'value' => '0',
        'description' => 'เปิดใช้งานเครื่องพิมพ์',
        'category' => 'printer'
    ],
    'printer_ip' => [
        'value' => '',
        'description' => 'IP Address เครื่องพิมพ์',
        'category' => 'printer'
    ],
    'printer_port' => [
        'value' => '9100',
        'description' => 'Port เครื่องพิมพ์',
        'category' => 'printer'
    ]
];

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: payment_settings.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        if ($action === 'generate_qr_preview') {
            // สร้าง QR Code Preview (AJAX)
            $promptPayId = trim($_POST['promptpay_id'] ?? '');
            $amount = floatval($_POST['amount'] ?? 0);
            $merchantName = trim($_POST['merchant_name'] ?? '');
            
            if (empty($promptPayId)) {
                sendJsonResponse(['success' => false, 'message' => 'กรุณากรอกหมายเลข PromptPay']);
            }
            
            try {
                $qrUrl = PromptPayQRGenerator::generateQRImageUrl($promptPayId, $amount > 0 ? $amount : null, $merchantName, 120);
                $qrData = PromptPayQRGenerator::generateQRData($promptPayId, $amount > 0 ? $amount : null, $merchantName);
                
                sendJsonResponse([
                    'success' => true,
                    'qr_url' => $qrUrl,
                    'qr_data' => $qrData,
                    'amount' => $amount,
                    'type' => $amount > 0 ? 'fixed' : 'open'
                ]);
            } catch (Exception $e) {
                sendJsonResponse(['success' => false, 'message' => $e->getMessage()]);
            }
            
        } elseif ($action === 'update_settings') {
            // อัปเดตการตั้งค่า
            foreach ($defaultPaymentSettings as $key => $setting) {
                $value = $_POST[$key] ?? $setting['value'];
                
                // Validation for boolean values
                if (in_array($key, ['enable_tax', 'tax_included', 'enable_service_charge', 'auto_print_receipt', 'printer_enabled'])) {
                    $value = isset($_POST[$key]) ? '1' : '0';
                }
                
                // Validation for numeric values
                if (in_array($key, ['tax_rate', 'service_charge_rate', 'printer_port'])) {
                    $value = floatval($value);
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO payment_settings (setting_key, setting_value, description, category, updated_at) 
                    VALUES (?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE 
                    setting_value = VALUES(setting_value), 
                    updated_at = NOW()
                ");
                $stmt->execute([$key, $value, $setting['description'], $setting['category']]);
            }
            
            setFlashMessage('success', 'บันทึกการตั้งค่าสำเร็จ');
            writeLog("Payment settings updated by " . getCurrentUser()['username']);
            
        } elseif ($action === 'update_methods') {
            // อัปเดตสถานะวิธีการชำระเงิน
            foreach ($defaultPaymentMethods as $method) {
                $isEnabled = isset($_POST['method_' . $method['method_code']]) ? 1 : 0;
                
                $stmt = $conn->prepare("
                    INSERT INTO payment_methods (method_code, method_name, description, is_enabled, display_order) 
                    VALUES (?, ?, ?, ?, ?)
                    ON DUPLICATE KEY UPDATE 
                    is_enabled = VALUES(is_enabled),
                    updated_at = NOW()
                ");
                $stmt->execute([
                    $method['method_code'],
                    $method['method_name'],
                    $method['description'],
                    $isEnabled,
                    $method['display_order']
                ]);
            }
            
            setFlashMessage('success', 'อัปเดตวิธีการชำระเงินสำเร็จ');
            writeLog("Payment methods updated by " . getCurrentUser()['username']);
            
        } elseif ($action === 'test_printer') {
            // ทดสอบเครื่องพิมพ์
            $printerIp = $_POST['test_printer_ip'] ?? '';
            $printerPort = intval($_POST['test_printer_port'] ?? 9100);
            
            if ($printerIp) {
                $socket = @fsockopen($printerIp, $printerPort, $errno, $errstr, 5);
                if ($socket) {
                    fclose($socket);
                    setFlashMessage('success', 'เชื่อมต่อเครื่องพิมพ์สำเร็จ');
                } else {
                    setFlashMessage('error', "ไม่สามารถเชื่อมต่อเครื่องพิมพ์ได้: $errstr");
                }
            } else {
                setFlashMessage('error', 'กรุณากรอก IP Address เครื่องพิมพ์');
            }
        }
        
    } catch (Exception $e) {
        setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
        writeLog("Error updating payment settings: " . $e->getMessage());
    }
    
    header('Location: payment_settings.php');
    exit();
}

// ดึงการตั้งค่าปัจจุบัน
$currentSettings = [];
$currentMethods = [];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงการตั้งค่า
    $stmt = $conn->prepare("SELECT setting_key, setting_value FROM payment_settings");
    $stmt->execute();
    $settings = $stmt->fetchAll();
    
    foreach ($settings as $setting) {
        $currentSettings[$setting['setting_key']] = $setting['setting_value'];
    }
    
    // ดึงวิธีการชำระเงิน
    $stmt = $conn->prepare("SELECT * FROM payment_methods ORDER BY display_order ASC");
    $stmt->execute();
    $methods = $stmt->fetchAll();
    
    foreach ($methods as $method) {
        $currentMethods[$method['method_code']] = $method;
    }
    
} catch (Exception $e) {
    writeLog("Error loading payment settings: " . $e->getMessage());
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">ตั้งค่าการชำระเงิน</h1>
        <p class="text-muted mb-0">จัดการวิธีการชำระเงิน ภาษี และการพิมพ์ใบเสร็จ</p>
    </div>
    <div>
        <button type="button" class="btn btn-secondary me-2" onclick="testPrinterConnection()">
            <i class="fas fa-print me-2"></i>ทดสอบเครื่องพิมพ์
        </button>
        <button type="submit" form="paymentSettingsForm" class="btn btn-primary">
            <i class="fas fa-save me-2"></i>บันทึกการตั้งค่า
        </button>
    </div>
</div>

<div class="row">
    <!-- Payment Methods -->
    <div class="col-lg-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-credit-card me-2"></i>วิธีการชำระเงิน
                </h5>
            </div>
            <div class="card-body">
                <form method="POST" id="paymentMethodsForm">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="update_methods">
                    
                    <?php foreach ($defaultPaymentMethods as $method): ?>
                        <?php 
                        $isEnabled = isset($currentMethods[$method['method_code']]) 
                                   ? $currentMethods[$method['method_code']]['is_enabled'] 
                                   : $method['is_enabled'];
                        ?>
                        <div class="mb-3">
                            <div class="form-check form-switch">
                                <input class="form-check-input" type="checkbox" 
                                       id="method_<?php echo $method['method_code']; ?>" 
                                       name="method_<?php echo $method['method_code']; ?>"
                                       <?php echo $isEnabled ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="method_<?php echo $method['method_code']; ?>">
                                    <strong><?php echo clean($method['method_name']); ?></strong>
                                </label>
                            </div>
                            <small class="text-muted d-block ms-3"><?php echo clean($method['description']); ?></small>
                        </div>
                    <?php endforeach; ?>
                    
                    <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-save me-2"></i>อัปเดตวิธีการชำระเงิน
                    </button>
                </form>
            </div>
        </div>
    </div>
    
    <!-- Main Settings Form -->
    <div class="col-lg-8">
        <form method="POST" id="paymentSettingsForm">
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="update_settings">
            
            <!-- PromptPay Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-qrcode me-2"></i>การตั้งค่า PromptPay
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="promptpay_id" class="form-label">หมายเลข PromptPay</label>
                                <input type="text" class="form-control" id="promptpay_id" name="promptpay_id" 
                                       value="<?php echo clean($currentSettings['promptpay_id'] ?? ''); ?>"
                                       placeholder="0812345678 หรือ 1234567890123">
                                <small class="text-muted">หมายเลขโทรศัพท์หรือเลขประจำตัวประชาชน</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="promptpay_name" class="form-label">ชื่อบัญชี</label>
                                <input type="text" class="form-control" id="promptpay_name" name="promptpay_name" 
                                       value="<?php echo clean($currentSettings['promptpay_name'] ?? ''); ?>"
                                       placeholder="นายสมชาย ใจดี">
                                <small class="text-muted">ชื่อที่แสดงในใบเสร็จ</small>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-12">
                            <div class="bg-light p-3 rounded">
                                <div class="d-flex align-items-center">
                                    <div class="me-3">
                                        <div id="qrCodePreview" style="width: 120px; height: 120px; background: #f8f9fa; border: 2px dashed #dee2e6; display: flex; align-items: center; justify-content: center; border-radius: 8px;">
                                            <i class="fas fa-qrcode fa-2x text-muted"></i>
                                        </div>
                                    </div>
                                    <div class="flex-grow-1">
                                        <h6 class="mb-1">ตัวอย่าง QR Code PromptPay</h6>
                                        <p class="text-muted mb-2">QR Code มาตรฐานที่สามารถระบุจำนวนเงินได้</p>
                                        <div class="row">
                                            <div class="col-md-8">
                                                <div class="input-group input-group-sm mb-2">
                                                    <span class="input-group-text">จำนวนเงิน</span>
                                                    <input type="number" class="form-control" id="testAmount" placeholder="100.00" step="0.01" min="0">
                                                    <span class="input-group-text">บาท</span>
                                                </div>
                                            </div>
                                            <div class="col-md-4">
                                                <button type="button" class="btn btn-sm btn-outline-primary w-100" onclick="generateQRPreview()">
                                                    <i class="fas fa-sync me-1"></i>สร้างตัวอย่าง
                                                </button>
                                            </div>
                                        </div>
                                        <small class="text-info">
                                            <i class="fas fa-info-circle me-1"></i>
                                            ถ้าไม่ระบุจำนวนเงิน จะเป็น QR Code แบบเปิด
                                        </small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Tax & Service Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-calculator me-2"></i>ภาษีและค่าบริการ
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6 class="text-primary mb-3">การตั้งค่าภาษี</h6>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="enable_tax" name="enable_tax" 
                                           <?php echo ($currentSettings['enable_tax'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="enable_tax">
                                        เปิดใช้งานการคิดภาษี
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="tax_rate" class="form-label">อัตราภาษี (%)</label>
                                <input type="number" class="form-control" id="tax_rate" name="tax_rate" 
                                       min="0" max="100" step="0.01"
                                       value="<?php echo clean($currentSettings['tax_rate'] ?? '7.00'); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="tax_included" name="tax_included" 
                                           <?php echo ($currentSettings['tax_included'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="tax_included">
                                        ราคาสินค้ารวมภาษีแล้ว
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <h6 class="text-success mb-3">การตั้งค่าค่าบริการ</h6>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="enable_service_charge" name="enable_service_charge" 
                                           <?php echo ($currentSettings['enable_service_charge'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="enable_service_charge">
                                        เปิดใช้งานค่าบริการ
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="service_charge_rate" class="form-label">อัตราค่าบริการ (%)</label>
                                <input type="number" class="form-control" id="service_charge_rate" name="service_charge_rate" 
                                       min="0" max="100" step="0.01"
                                       value="<?php echo clean($currentSettings['service_charge_rate'] ?? '10.00'); ?>">
                            </div>
                        </div>
                    </div>
                    
                    <!-- Calculation Preview -->
                    <div class="bg-light p-3 rounded mt-3">
                        <h6 class="mb-2">ตัวอย่างการคิดราคา</h6>
                        <div class="row text-sm" id="calculationPreview">
                            <div class="col-md-6">
                                <div>ราคาสินค้า: <span class="float-end">100.00 บาท</span></div>
                                <div class="text-success">ค่าบริการ (0%): <span class="float-end">0.00 บาท</span></div>
                                <div class="text-primary">ภาษี (7%): <span class="float-end">7.00 บาท</span></div>
                            </div>
                            <div class="col-md-6">
                                <div class="fw-bold border-top pt-2">รวมทั้งสิ้น: <span class="float-end">107.00 บาท</span></div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Receipt Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-receipt me-2"></i>การตั้งค่าใบเสร็จ
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="receipt_header" class="form-label">หัวข้อใบเสร็จ</label>
                                <input type="text" class="form-control" id="receipt_header" name="receipt_header" 
                                       value="<?php echo clean($currentSettings['receipt_header'] ?? 'ใบเสร็จรับเงิน'); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="company_name" class="form-label">ชื่อบริษัท/ร้านค้า</label>
                                <input type="text" class="form-control" id="company_name" name="company_name" 
                                       value="<?php echo clean($currentSettings['company_name'] ?? ''); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="company_phone" class="form-label">เบอร์โทรศัพท์</label>
                                <input type="tel" class="form-control" id="company_phone" name="company_phone" 
                                       value="<?php echo clean($currentSettings['company_phone'] ?? ''); ?>">
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="receipt_footer" class="form-label">ข้อความท้ายใบเสร็จ</label>
                                <input type="text" class="form-control" id="receipt_footer" name="receipt_footer" 
                                       value="<?php echo clean($currentSettings['receipt_footer'] ?? 'ขอบคุณที่ใช้บริการ'); ?>">
                            </div>
                            
                            <div class="mb-3">
                                <label for="company_address" class="form-label">ที่อยู่</label>
                                <textarea class="form-control" id="company_address" name="company_address" rows="2"><?php echo clean($currentSettings['company_address'] ?? ''); ?></textarea>
                            </div>
                            
                            <div class="mb-3">
                                <label for="tax_id" class="form-label">เลขประจำตัวผู้เสียภาษี</label>
                                <input type="text" class="form-control" id="tax_id" name="tax_id" 
                                       value="<?php echo clean($currentSettings['tax_id'] ?? ''); ?>"
                                       placeholder="0123456789012">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Printer Settings -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="fas fa-print me-2"></i>การตั้งค่าเครื่องพิมพ์
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="printer_enabled" name="printer_enabled" 
                                           <?php echo ($currentSettings['printer_enabled'] ?? '0') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="printer_enabled">
                                        เปิดใช้งานเครื่องพิมพ์
                                    </label>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" id="auto_print_receipt" name="auto_print_receipt" 
                                           <?php echo ($currentSettings['auto_print_receipt'] ?? '1') === '1' ? 'checked' : ''; ?>>
                                    <label class="form-check-label" for="auto_print_receipt">
                                        พิมพ์ใบเสร็จอัตโนมัติ
                                    </label>
                                </div>
                            </div>
                        </div>
                        
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="printer_ip" class="form-label">IP Address เครื่องพิมพ์</label>
                                <input type="text" class="form-control" id="printer_ip" name="printer_ip" 
                                       value="<?php echo clean($currentSettings['printer_ip'] ?? ''); ?>"
                                       placeholder="192.168.1.100">
                            </div>
                            
                            <div class="mb-3">
                                <label for="printer_port" class="form-label">Port</label>
                                <input type="number" class="form-control" id="printer_port" name="printer_port" 
                                       min="1" max="65535"
                                       value="<?php echo clean($currentSettings['printer_port'] ?? '9100'); ?>">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Test Printer Modal -->
<div class="modal fade" id="testPrinterModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="testPrinterForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="test_printer">
                
                <div class="modal-header">
                    <h5 class="modal-title">ทดสอบการเชื่อมต่อเครื่องพิมพ์</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="test_printer_ip" class="form-label">IP Address เครื่องพิมพ์</label>
                        <input type="text" class="form-control" id="test_printer_ip" name="test_printer_ip" 
                               value="<?php echo clean($currentSettings['printer_ip'] ?? ''); ?>"
                               placeholder="192.168.1.100" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="test_printer_port" class="form-label">Port</label>
                        <input type="number" class="form-control" id="test_printer_port" name="test_printer_port" 
                               min="1" max="65535"
                               value="<?php echo clean($currentSettings['printer_port'] ?? '9100'); ?>" required>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-plug me-2"></i>ทดสอบการเชื่อมต่อ
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$inlineJS = "
// Update calculation preview when settings change
function updateCalculationPreview() {
    const basePrice = 100;
    const enableTax = $('#enable_tax').is(':checked');
    const enableService = $('#enable_service_charge').is(':checked');
    const taxRate = parseFloat($('#tax_rate').val()) || 0;
    const serviceRate = parseFloat($('#service_charge_rate').val()) || 0;
    const taxIncluded = $('#tax_included').is(':checked');
    
    let subtotal = basePrice;
    let serviceCharge = enableService ? (subtotal * serviceRate / 100) : 0;
    let tax = 0;
    
    if (enableTax) {
        if (taxIncluded) {
            tax = (subtotal + serviceCharge) * taxRate / (100 + taxRate);
        } else {
            tax = (subtotal + serviceCharge) * taxRate / 100;
        }
    }
    
    const total = subtotal + serviceCharge + (taxIncluded ? 0 : tax);
    
    $('#calculationPreview').html(
        '<div class=\"col-md-6\">' +
            '<div>ราคาสินค้า: <span class=\"float-end\">' + subtotal.toFixed(2) + ' บาท</span></div>' +
            '<div class=\"text-success\">ค่าบริการ (' + serviceRate + '%): <span class=\"float-end\">' + serviceCharge.toFixed(2) + ' บาท</span></div>' +
            '<div class=\"text-primary\">ภาษี (' + taxRate + '%): <span class=\"float-end\">' + tax.toFixed(2) + ' บาท</span></div>' +
        '</div>' +
        '<div class=\"col-md-6\">' +
            '<div class=\"fw-bold border-top pt-2\">รวมทั้งสิ้น: <span class=\"float-end\">' + total.toFixed(2) + ' บาท</span></div>' +
        '</div>'
    );
}

// Generate QR Code preview
function generateQRPreview() {
    const promptpayId = $('#promptpay_id').val().trim();
    const merchantName = $('#promptpay_name').val().trim();
    const testAmount = parseFloat($('#testAmount').val()) || 0;
    
    if (!promptpayId) {
        alert('กรุณากรอกหมายเลข PromptPay');
        $('#promptpay_id').focus();
        return;
    }
    
    // Show loading
    $('#qrCodePreview').html('<div class=\"d-flex justify-content-center align-items-center h-100\"><div class=\"spinner-border text-primary\" role=\"status\"></div></div>');
    
    // AJAX request to generate QR
    $.post(window.location.href, {
        action: 'generate_qr_preview',
        promptpay_id: promptpayId,
        merchant_name: merchantName,
        amount: testAmount,
        csrf_token: CSRF_TOKEN
    }, function(response) {
        if (response.success) {
            const qrHtml = '<div class=\"text-center\">' +
                          '<img src=\"' + response.qr_url + '\" alt=\"PromptPay QR Code\" class=\"img-fluid\" style=\"max-width: 120px; max-height: 120px; border-radius: 8px;\">' +
                          '<div class=\"mt-1\"><small class=\"text-muted\">' + (response.type === 'fixed' ? 'จำนวน ' + response.amount + ' บาท' : 'QR แบบเปิด') + '</small></div>' +
                          '</div>';
            $('#qrCodePreview').html(qrHtml);
        } else {
            $('#qrCodePreview').html('<div class=\"text-center text-danger\"><i class=\"fas fa-exclamation-triangle fa-2x mb-2\"></i><br><small>' + response.message + '</small></div>');
        }
    }).fail(function() {
        $('#qrCodePreview').html('<div class=\"text-center text-danger\"><i class=\"fas fa-times fa-2x mb-2\"></i><br><small>เกิดข้อผิดพลาด</small></div>');
    });
}

// Test printer connection
function testPrinterConnection() {
    $('#test_printer_ip').val($('#printer_ip').val());
    $('#test_printer_port').val($('#printer_port').val());
    $('#testPrinterModal').modal('show');
}

// Event listeners
$(document).ready(function() {
    // Update calculation preview when values change
    $('#enable_tax, #enable_service_charge, #tax_rate, #service_charge_rate, #tax_included').on('change input', updateCalculationPreview);
    
    // Initial calculation preview
    updateCalculationPreview();
    
    // Auto-generate QR preview when PromptPay ID changes
    $('#promptpay_id, #promptpay_name').on('blur', function() {
        if ($('#promptpay_id').val().trim()) {
            generateQRPreview();
        }
    });
    
    // Auto-generate QR when test amount changes
    $('#testAmount').on('input', function() {
        if ($('#promptpay_id').val().trim()) {
            // Debounce the input
            clearTimeout(window.qrTimeout);
            window.qrTimeout = setTimeout(generateQRPreview, 1000);
        }
    });
    
    // Form validation
    $('#paymentSettingsForm').on('submit', function(e) {
        const promptpayId = $('#promptpay_id').val().trim();
        const printerIp = $('#printer_ip').val().trim();
        const printerEnabled = $('#printer_enabled').is(':checked');
        
        // Validate PromptPay ID format (basic validation)
        if (promptpayId && !/^[0-9]{10,13}$/.test(promptpayId.replace(/[-\\s]/g, ''))) {
            e.preventDefault();
            alert('รูปแบบหมายเลข PromptPay ไม่ถูกต้อง');
            $('#promptpay_id').focus();
            return false;
        }
        
        // Validate printer IP when printer is enabled
        if (printerEnabled && !printerIp) {
            e.preventDefault();
            alert('กรุณากรอก IP Address เครื่องพิมพ์');
            $('#printer_ip').focus();
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
});

console.log('Payment Settings loaded successfully');
";

require_once '../includes/footer.php';
?>