<?php
/**
 * API การชำระเงิน
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';
require_once '../../classes/PromptPayQRGenerator.php';

// ตั้งค่า Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// จัดการ OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($action) {
        
        // สร้างการชำระเงิน
        case 'create_payment':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $orderId = intval($_POST['order_id'] ?? 0);
            $amount = floatval($_POST['amount'] ?? 0);
            $description = trim($_POST['description'] ?? '');
            
            if (!$orderId || $amount <= 0) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            
            // ตรวจสอบออเดอร์
            $stmt = $conn->prepare("
                SELECT order_id, total_price, payment_status, status 
                FROM orders 
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception('ไม่พบออเดอร์');
            }
            
            if ($order['payment_status'] === 'paid') {
                throw new Exception('ออเดอร์นี้ชำระเงินแล้ว');
            }
            
            if (abs($order['total_price'] - $amount) > 0.01) {
                throw new Exception('จำนวนเงินไม่ตรงกับออเดอร์');
            }
            
            // ดึงการตั้งค่าการชำระเงิน
            $stmt = $conn->prepare("
                SELECT setting_key, setting_value 
                FROM payment_settings 
                WHERE setting_key IN ('promptpay_id', 'promptpay_name')
            ");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            if (empty($settings['promptpay_id'])) {
                throw new Exception('ระบบการชำระเงินไม่พร้อมใช้งาน');
            }
            
            // สร้าง payment record
            $stmt = $conn->prepare("
                INSERT INTO payments (
                    order_id, amount, payment_method, status, 
                    expires_at, created_at
                ) VALUES (?, ?, 'promptpay', 'pending', 
                         DATE_ADD(NOW(), INTERVAL 5 MINUTE), NOW())
            ");
            $stmt->execute([$orderId, $amount]);
            $paymentId = $conn->lastInsertId();
            
            // สร้าง QR Code PromptPay
            $qrCodeUrl = PromptPayQRGenerator::generateQRImageUrl(
                $settings['promptpay_id'],
                $amount,
                $settings['promptpay_name'] ?? '',
                300 // 5 นาที
            );
            
            if (!$qrCodeUrl) {
                // Fallback QR Code
                $qrCodeUrl = "https://promptpay.io/" . $settings['promptpay_id'] . "/" . $amount;
            }
            
            sendJsonResponse([
                'success' => true,
                'payment_id' => $paymentId,
                'order_id' => $orderId,
                'amount' => $amount,
                'qr_code_url' => $qrCodeUrl,
                'expires_in' => 300, // 5 นาที
                'expires_at' => date('c', strtotime('+5 minutes')),
                'promptpay_id' => $settings['promptpay_id'],
                'promptpay_name' => $settings['promptpay_name'] ?? ''
            ]);
            break;
            
        // ตรวจสอบสถานะการชำระเงิน
        case 'check_payment_status':
            $paymentId = intval($_GET['payment_id'] ?? 0);
            
            if (!$paymentId) {
                throw new Exception('กรุณาระบุ Payment ID');
            }
            
            $stmt = $conn->prepare("
                SELECT p.*, o.queue_number, o.total_price as order_total
                FROM payments p
                JOIN orders o ON p.order_id = o.order_id
                WHERE p.payment_id = ?
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            
            if (!$payment) {
                throw new Exception('ไม่พบข้อมูลการชำระเงิน');
            }
            
            // ตรวจสอบว่าหมดอายุหรือไม่
            $isExpired = false;
            if ($payment['expires_at'] && strtotime($payment['expires_at']) < time()) {
                $isExpired = true;
                
                // อัปเดตสถานะเป็น expired
                if ($payment['status'] === 'pending') {
                    $stmt = $conn->prepare("
                        UPDATE payments 
                        SET status = 'expired' 
                        WHERE payment_id = ?
                    ");
                    $stmt->execute([$paymentId]);
                    $payment['status'] = 'expired';
                }
            }
            
            sendJsonResponse([
                'success' => true,
                'payment_id' => $payment['payment_id'],
                'order_id' => $payment['order_id'],
                'queue_number' => $payment['queue_number'],
                'amount' => $payment['amount'],
                'status' => $payment['status'],
                'payment_method' => $payment['payment_method'],
                'reference_number' => $payment['reference_number'],
                'payment_date' => $payment['payment_date'],
                'is_expired' => $isExpired,
                'expires_at' => $payment['expires_at']
            ]);
            break;
            
        // ตรวจสอบสลิปธนาคาร
        case 'verify_slip':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $paymentId = intval($_POST['payment_id'] ?? 0);
            $slipImage = $_POST['slip_image'] ?? '';
            
            if (!$paymentId || empty($slipImage)) {
                throw new Exception('ข้อมูลไม่ครบถ้วน');
            }
            
            // ตรวจสอบ payment
            $stmt = $conn->prepare("
                SELECT p.*, o.queue_number
                FROM payments p
                JOIN orders o ON p.order_id = o.order_id
                WHERE p.payment_id = ?
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            
            if (!$payment) {
                throw new Exception('ไม่พบข้อมูลการชำระเงิน');
            }
            
            if ($payment['status'] === 'completed') {
                throw new Exception('การชำระเงินนี้เสร็จสิ้นแล้ว');
            }
            
            // บันทึกรูปสลิป
            $slipFileName = 'slip_' . $paymentId . '_' . time() . '.jpg';
            $slipPath = UPLOAD_PATH . 'slips/' . $slipFileName;
            
            // สร้างโฟลเดอร์ถ้าไม่มี
            if (!is_dir(dirname($slipPath))) {
                mkdir(dirname($slipPath), 0777, true);
            }
            
            // บันทึกไฟล์
            $imageData = base64_decode($slipImage);
            if (file_put_contents($slipPath, $imageData)) {
                
                // อัปเดต payment record
                $stmt = $conn->prepare("
                    UPDATE payments 
                    SET slip_image = ?, status = 'pending_verification'
                    WHERE payment_id = ?
                ");
                $stmt->execute([$slipFileName, $paymentId]);
                
                // TODO: ใช้ SlipOK API หรือ AI อื่นๆ ในการตรวจสอบสลิป
                // สำหรับตอนนี้จะจำลองการตรวจสอบ
                $isValidSlip = $this->verifySlipImage($slipPath, $payment['amount']);
                
                if ($isValidSlip) {
                    // อัปเดตสถานะเป็นชำระแล้ว
                    $stmt = $conn->prepare("
                        UPDATE payments 
                        SET status = 'completed', payment_date = NOW(),
                            reference_number = CONCAT('SLIP_', ?)
                        WHERE payment_id = ?
                    ");
                    $stmt->execute([time(), $paymentId]);
                    
                    // อัปเดตสถานะออเดอร์
                    $stmt = $conn->prepare("
                        UPDATE orders 
                        SET payment_status = 'paid', status = 'confirmed'
                        WHERE order_id = ?
                    ");
                    $stmt->execute([$payment['order_id']]);
                    
                    sendJsonResponse([
                        'success' => true,
                        'verified' => true,
                        'message' => 'ตรวจสอบสลิปสำเร็จ',
                        'payment_id' => $paymentId,
                        'queue_number' => $payment['queue_number']
                    ]);
                } else {
                    sendJsonResponse([
                        'success' => true,
                        'verified' => false,
                        'message' => 'สลิปไม่ถูกต้องหรือจำนวนเงินไม่ตรงกัน'
                    ]);
                }
            } else {
                throw new Exception('ไม่สามารถบันทึกรูปสลิปได้');
            }
            break;
            
        // ยืนยันการชำระเงินสด
        case 'confirm_cash_payment':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $paymentId = intval($_POST['payment_id'] ?? 0);
            $receivedAmount = floatval($_POST['received_amount'] ?? 0);
            
            if (!$paymentId || $receivedAmount <= 0) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            
            $stmt = $conn->prepare("
                SELECT p.*, o.queue_number
                FROM payments p
                JOIN orders o ON p.order_id = o.order_id
                WHERE p.payment_id = ?
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            
            if (!$payment) {
                throw new Exception('ไม่พบข้อมูลการชำระเงิน');
            }
            
            if ($receivedAmount < $payment['amount']) {
                throw new Exception('จำนวนเงินที่รับไม่เพียงพอ');
            }
            
            $changeAmount = $receivedAmount - $payment['amount'];
            
            // อัปเดตสถานะ
            $stmt = $conn->prepare("
                UPDATE payments 
                SET status = 'completed', payment_date = NOW(),
                    reference_number = CONCAT('CASH_', ?),
                    admin_note = CONCAT('เงินสด: ', ?, ' บาท, เงินทอน: ', ?, ' บาท')
                WHERE payment_id = ?
            ");
            $stmt->execute([time(), $receivedAmount, $changeAmount, $paymentId]);
            
            // อัปเดตสถานะออเดอร์
            $stmt = $conn->prepare("
                UPDATE orders 
                SET payment_status = 'paid', status = 'confirmed'
                WHERE order_id = ?
            ");
            $stmt->execute([$payment['order_id']]);
            
            sendJsonResponse([
                'success' => true,
                'message' => 'บันทึกการชำระเงินสดสำเร็จ',
                'payment_id' => $paymentId,
                'received_amount' => $receivedAmount,
                'change_amount' => $changeAmount,
                'queue_number' => $payment['queue_number']
            ]);
            break;
            
        // ดึงประวัติการชำระเงิน
        case 'payment_history':
            if (!isLoggedIn()) {
                throw new Exception('กรุณาเข้าสู่ระบบ');
            }
            
            $userId = getCurrentUserId();
            $limit = intval($_GET['limit'] ?? 10);
            $offset = intval($_GET['offset'] ?? 0);
            
            $stmt = $conn->prepare("
                SELECT p.*, o.queue_number, o.total_price
                FROM payments p
                JOIN orders o ON p.order_id = o.order_id
                WHERE o.user_id = ?
                ORDER BY p.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $limit, $offset]);
            $payments = $stmt->fetchAll();
            
            sendJsonResponse([
                'success' => true,
                'payments' => $payments
            ]);
            break;
            
        // ยกเลิกการชำระเงิน
        case 'cancel_payment':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $paymentId = intval($_POST['payment_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            
            if (!$paymentId) {
                throw new Exception('กรุณาระบุ Payment ID');
            }
            
            $stmt = $conn->prepare("
                SELECT payment_id, status, order_id 
                FROM payments 
                WHERE payment_id = ?
            ");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            
            if (!$payment) {
                throw new Exception('ไม่พบข้อมูลการชำระเงิน');
            }
            
            if ($payment['status'] === 'completed') {
                throw new Exception('ไม่สามารถยกเลิกการชำระเงินที่เสร็จสิ้นแล้ว');
            }
            
            // อัปเดตสถานะ
            $stmt = $conn->prepare("
                UPDATE payments 
                SET status = 'cancelled', admin_note = ?
                WHERE payment_id = ?
            ");
            $stmt->execute([$reason, $paymentId]);
            
            sendJsonResponse([
                'success' => true,
                'message' => 'ยกเลิกการชำระเงินแล้ว'
            ]);
            break;
            
        default:
            throw new Exception('Action not found');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    sendJsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
    
    writeLog("Payments API Error: " . $e->getMessage() . " | Action: " . ($action ?? 'unknown'));
}

/**
 * ตรวจสอบสลิปธนาคาร (จำลอง)
 */
function verifySlipImage($imagePath, $expectedAmount) {
    // TODO: ใช้ SlipOK API หรือ AI อื่นๆ ในการตรวจสอบจริง
    // สำหรับตอนนี้จะจำลองการตรวจสอบ
    
    // ตรวจสอบว่าไฟล์มีอยู่และเป็นรูปภาพ
    if (!file_exists($imagePath)) {
        return false;
    }
    
    $imageInfo = getimagesize($imagePath);
    if (!$imageInfo) {
        return false;
    }
    
    // จำลองการตรวจสอบ (90% ผ่าน)
    return (rand(1, 10) <= 9);
}

/**
 * คลาส PromptPay QR Generator (ถ้ายังไม่มีไฟล์)
 */
if (!class_exists('PromptPayQRGenerator')) {
    class PromptPayQRGenerator {
        
        public static function generateQRImageUrl($promptPayId, $amount, $name = '', $expiresIn = 300) {
            // ใช้ API ของ promptpay.io
            $url = "https://promptpay.io/{$promptPayId}";
            
            if ($amount > 0) {
                $url .= "/{$amount}";
            }
            
            // เพิ่ม QR Code generator
            $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?" . http_build_query([
                'size' => '300x300',
                'data' => $url,
                'format' => 'png',
                'margin' => 10
            ]);
            
            return $qrUrl;
        }
        
        public static function generateQRData($promptPayId, $amount, $name = '') {
            // สร้างข้อมูล QR ตามมาตรฐาน EMVCo
            $data = "00020101021129370016A000000677010111";
            $data .= sprintf("%02d%s", strlen($promptPayId), $promptPayId);
            
            if ($amount > 0) {
                $amountStr = sprintf("%.2f", $amount);
                $data .= sprintf("54%02d%s", strlen($amountStr), $amountStr);
            }
            
            $data .= "5802TH";
            
            if (!empty($name)) {
                $data .= sprintf("59%02d%s", strlen($name), $name);
            }
            
            $data .= "6304";
            
            // คำนวณ CRC16
            $crc = $this->calculateCRC16($data);
            $data .= strtoupper($crc);
            
            return $data;
        }
        
        private static function calculateCRC16($data) {
            // CRC16 calculation for QR Code
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
            
            return sprintf("%04X", $crc);
        }
    }
}
?>