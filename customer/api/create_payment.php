<?php
/**
 * API สร้างการชำระเงิน
 * Smart Order Management System
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

define('SYSTEM_INIT', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

// เริ่มต้น Session
SessionManager::start();

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Method not allowed');
    }
    
    $orderId = intval($_POST['order_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    
    if (!$orderId || !$amount) {
        throw new Exception('ข้อมูลไม่ครบถ้วน');
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // ตรวจสอบออเดอร์
    $stmt = $conn->prepare("
        SELECT order_id, order_number, total_price, payment_status 
        FROM orders 
        WHERE order_id = ? AND payment_status = 'unpaid'
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        throw new Exception('ไม่พบออเดอร์หรือชำระเงินแล้ว');
    }
    
    if (abs($order['total_price'] - $amount) > 0.01) {
        throw new Exception('จำนวนเงินไม่ตรงกับออเดอร์');
    }
    
    // สร้างการชำระเงิน
    $transactionId = 'PAY' . date('YmdHis') . sprintf('%04d', rand(1, 9999));
    $referenceNumber = 'REF' . date('Ymd') . sprintf('%06d', rand(1, 999999));
    
    $stmt = $conn->prepare("
        INSERT INTO payments (
            order_id, amount, payment_method, reference_number, 
            status, expires_at, qr_data
        ) VALUES (?, ?, 'promptpay', ?, 'pending', DATE_ADD(NOW(), INTERVAL 5 MINUTE), ?)
    ");
    
    $stmt->execute([
        $orderId,
        $amount,
        $referenceNumber,
        $description
    ]);
    
    $paymentDbId = $conn->lastInsertId();
    
    // สร้าง QR Code URL สำหรับ PromptPay
    $promptpayId = '0891234567890'; // เปลี่ยนเป็น PromptPay ID จริง
    $qrCodeUrl = generatePromptPayQR($promptpayId, $amount, $order['order_number']);
    
    // อัปเดต QR Code data ในฐานข้อมูล
    $stmt = $conn->prepare("UPDATE payments SET qr_data = ? WHERE payment_id = ?");
    $stmt->execute([$qrCodeUrl, $paymentDbId]);
    
    echo json_encode([
        'success' => true,
        'payment_id' => $paymentDbId,
        'transaction_id' => $transactionId,
        'qr_code_url' => $qrCodeUrl,
        'amount' => $amount,
        'expires_in' => 300, // 5 minutes
        'expires_at' => date('Y-m-d H:i:s', strtotime('+5 minutes')),
        'order_number' => $order['order_number'],
        'reference_number' => $referenceNumber
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * สร้าง PromptPay QR Code URL
 */
function generatePromptPayQR($promptpayId, $amount, $ref = '') {
    // สำหรับการใช้งานจริง ควรใช้ API ของธนาคารหรือ QR Code generator
    // ตอนนี้จะใช้ Google Charts API เป็นตัวอย่าง
    
    $qrData = "00020101021129370016A000000677010111011300{$promptpayId}5802TH5303764540{$amount}6304";
    
    // สร้าง checksum (simplified)
    $checksum = sprintf('%04X', crc16($qrData));
    $qrData .= $checksum;
    
    // สร้าง URL สำหรับ QR Code image
    $qrCodeUrl = 'https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl=' . urlencode($qrData);
    
    return $qrCodeUrl;
}

/**
 * คำนวณ CRC16 สำหรับ PromptPay
 */
function crc16($data) {
    $crc = 0xFFFF;
    for ($i = 0; $i < strlen($data); $i++) {
        $crc ^= ord($data[$i]) << 8;
        for ($j = 0; $j < 8; $j++) {
            if ($crc & 0x8000) {
                $crc = ($crc << 1) ^ 0x1021;
            } else {
                $crc = $crc << 1;
            }
        }
    }
    return $crc & 0xFFFF;
}
?>