<?php
/**
 * API Endpoints สำหรับระบบชำระเงิน PromptPay
 * Smart Order Management System
 */

// ไฟล์: api/create_payment.php
/**
 * สร้างการชำระเงิน
 */
if (basename(__FILE__) == 'create_payment.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/functions.php';
    require_once '../classes/PromptPayQRGenerator.php';
    
    $orderId = intval($_POST['order_id'] ?? 0);
    $amount = floatval($_POST['amount'] ?? 0);
    $description = trim($_POST['description'] ?? '');
    
    if (!$orderId || !$amount) {
        sendJsonResponse(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
    }
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // ตรวจสอบออเดอร์
        $stmt = $conn->prepare("SELECT * FROM orders WHERE order_id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            sendJsonResponse(['success' => false, 'message' => 'ไม่พบออเดอร์']);
        }
        
        // ดึงการตั้งค่า PromptPay
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM payment_settings WHERE setting_key IN ('promptpay_id', 'promptpay_name')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $promptPayId = $settings['promptpay_id'] ?? '';
        $merchantName = $settings['promptpay_name'] ?? '';
        
        if (empty($promptPayId)) {
            sendJsonResponse(['success' => false, 'message' => 'ยังไม่ได้ตั้งค่า PromptPay']);
        }
        
        // สร้าง payment record
        $stmt = $conn->prepare("
            INSERT INTO payments (order_id, amount, payment_method, status, created_at, expires_at) 
            VALUES (?, ?, 'promptpay', 'pending', NOW(), DATE_ADD(NOW(), INTERVAL 5 MINUTE))
        ");
        $stmt->execute([$orderId, $amount]);
        $paymentId = $conn->lastInsertId();
        
        // สร้าง QR Code
        $qrData = PromptPayQRGenerator::generateQRData($promptPayId, $amount, $merchantName);
        $qrImageUrl = PromptPayQRGenerator::generateQRImageUrl($promptPayId, $amount, $merchantName, 300);
        
        // บันทึก QR data สำหรับการตรวจสอบ
        $stmt = $conn->prepare("
            UPDATE payments 
            SET reference_number = ?, qr_data = ? 
            WHERE payment_id = ?
        ");
        $stmt->execute(['QR_' . $paymentId . '_' . time(), $qrData, $paymentId]);
        
        sendJsonResponse([
            'success' => true,
            'payment_id' => $paymentId,
            'order_id' => $orderId,
            'amount' => $amount,
            'qr_code_url' => $qrImageUrl,
            'qr_data' => $qrData,
            'expires_at' => date('c', strtotime('+5 minutes')),
            'expires_in' => 300
        ]);
        
    } catch (Exception $e) {
        writeLog("Create payment error: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการสร้างการชำระเงิน']);
    }
}

// ไฟล์: api/check_payment_status.php
/**
 * ตรวจสอบสถานะการชำระเงิน
 */
if (basename(__FILE__) == 'check_payment_status.php' && $_SERVER['REQUEST_METHOD'] === 'GET') {
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/functions.php';
    
    $paymentId = intval($_GET['payment_id'] ?? 0);
    
    if (!$paymentId) {
        sendJsonResponse(['success' => false, 'message' => 'ไม่พบรหัสการชำระเงิน']);
    }
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT p.*, o.order_id, o.total_price 
            FROM payments p 
            JOIN orders o ON p.order_id = o.order_id 
            WHERE p.payment_id = ?
        ");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            sendJsonResponse(['success' => false, 'message' => 'ไม่พบข้อมูลการชำระเงิน']);
        }
        
        // ตรวจสอบการหมดอายุ
        $isExpired = strtotime($payment['expires_at']) < time();
        
        sendJsonResponse([
            'success' => true,
            'payment_id' => $payment['payment_id'],
            'order_id' => $payment['order_id'],
            'status' => $payment['status'],
            'amount' => floatval($payment['amount']),
            'payment_method' => $payment['payment_method'],
            'created_at' => $payment['created_at'],
            'payment_date' => $payment['payment_date'],
            'expires_at' => $payment['expires_at'],
            'is_expired' => $isExpired,
            'reference_number' => $payment['reference_number']
        ]);
        
    } catch (Exception $e) {
        writeLog("Check payment status error: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการตรวจสอบสถานะ']);
    }
}

// ไฟล์: api/verify_slip.php
/**
 * ตรวจสอบสลิปที่อัปโหลด
 */
if (basename(__FILE__) == 'verify_slip.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/functions.php';
    
    $paymentId = intval($_POST['payment_id'] ?? 0);
    $slipImageBase64 = $_POST['slip_image'] ?? '';
    
    if (!$paymentId || !$slipImageBase64) {
        sendJsonResponse(['success' => false, 'message' => 'ข้อมูลไม่ครบถ้วน']);
    }
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // ดึงข้อมูล payment
        $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_id = ?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            sendJsonResponse(['success' => false, 'message' => 'ไม่พบข้อมูลการชำระเงิน']);
        }
        
        if ($payment['status'] === 'completed') {
            sendJsonResponse(['success' => true, 'verified' => true, 'message' => 'ชำระเงินเรียบร้อยแล้ว']);
        }
        
        // ตรวจสอบการหมดอายุ
        if (strtotime($payment['expires_at']) < time()) {
            sendJsonResponse(['success' => false, 'message' => 'การชำระเงินหมดอายุแล้ว']);
        }
        
        // บันทึกรูปสลิป (optional)
        $slipPath = UPLOAD_PATH . 'slips/';
        if (!is_dir($slipPath)) {
            mkdir($slipPath, 0755, true);
        }
        
        $slipFileName = 'slip_' . $paymentId . '_' . time() . '.jpg';
        $slipFullPath = $slipPath . $slipFileName;
        
        // แปลง base64 เป็นไฟล์
        $imageData = base64_decode($slipImageBase64);
        if (file_put_contents($slipFullPath, $imageData)) {
            
            // *** ที่นี่คุณสามารถเพิ่ม API ตรวจสอบสลิป ***
            // เช่น SlipOK, Bank API, หรือ Manual verification
            
            // ตัวอย่าง: การตรวจสอบแบบง่าย (ในความเป็นจริงควรใช้ API)
            $isValidSlip = verifySlipBasic($slipFullPath, $payment['amount']);
            
            if ($isValidSlip) {
                // อัปเดตสถานะเป็นชำระเงินสำเร็จ
                $stmt = $conn->prepare("
                    UPDATE payments 
                    SET status = 'completed', payment_date = NOW(), slip_image = ? 
                    WHERE payment_id = ?
                ");
                $stmt->execute([$slipFileName, $paymentId]);
                
                // อัปเดตสถานะออเดอร์
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET payment_status = 'paid', status = 'confirmed' 
                    WHERE order_id = ?
                ");
                $stmt->execute([$payment['order_id']]);
                
                // ส่งการแจ้งเตือน
                sendPaymentNotification($payment['order_id'], 'completed');
                
                sendJsonResponse([
                    'success' => true,
                    'verified' => true,
                    'payment_id' => $paymentId,
                    'message' => 'ตรวจสอบสลิปสำเร็จ การชำระเงินเสร็จสิ้น'
                ]);
            } else {
                sendJsonResponse([
                    'success' => true,
                    'verified' => false,
                    'message' => 'สลิปไม่ถูกต้อง กรุณาตรวจสอบจำนวนเงินและหมายเลขบัญชี'
                ]);
            }
        } else {
            sendJsonResponse(['success' => false, 'message' => 'ไม่สามารถบันทึกรูปสลิปได้']);
        }
        
    } catch (Exception $e) {
        writeLog("Verify slip error: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการตรวจสอบสลิป']);
    }
}

// ไฟล์: api/manual_confirm_payment.php
/**
 * ยืนยันการชำระเงินแบบ Manual (สำหรับ Admin)
 */
if (basename(__FILE__) == 'manual_confirm_payment.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../config/session.php';
    require_once '../includes/functions.php';
    require_once '../includes/auth.php';
    
    // ตรวจสอบสิทธิ์ Admin
    requireAuthAjax('admin');
    
    $paymentId = intval($_POST['payment_id'] ?? 0);
    $action = $_POST['action'] ?? ''; // 'confirm' หรือ 'reject'
    $note = trim($_POST['note'] ?? '');
    
    if (!$paymentId || !in_array($action, ['confirm', 'reject'])) {
        sendJsonResponse(['success' => false, 'message' => 'ข้อมูลไม่ถูกต้อง']);
    }
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_id = ?");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();
        
        if (!$payment) {
            sendJsonResponse(['success' => false, 'message' => 'ไม่พบข้อมูลการชำระเงิน']);
        }
        
        $newStatus = ($action === 'confirm') ? 'completed' : 'failed';
        $currentUser = getCurrentUser();
        
        // อัปเดตสถานะ payment
        $stmt = $conn->prepare("
            UPDATE payments 
            SET status = ?, payment_date = NOW(), admin_note = ?, confirmed_by = ? 
            WHERE payment_id = ?
        ");
        $stmt->execute([$newStatus, $note, $currentUser['user_id'], $paymentId]);
        
        if ($action === 'confirm') {
            // อัปเดตสถานะออเดอร์
            $stmt = $conn->prepare("
                UPDATE orders 
                SET payment_status = 'paid', status = 'confirmed' 
                WHERE order_id = ?
            ");
            $stmt->execute([$payment['order_id']]);
            
            // ส่งการแจ้งเตือน
            sendPaymentNotification($payment['order_id'], 'completed');
        }
        
        // บันทึก log
        writeLog("Payment manually " . ($action === 'confirm' ? 'confirmed' : 'rejected') . " by " . $currentUser['username'] . " - Payment ID: $paymentId");
        
        sendJsonResponse([
            'success' => true,
            'payment_id' => $paymentId,
            'action' => $action,
            'new_status' => $newStatus,
            'message' => $action === 'confirm' ? 'ยืนยันการชำระเงินสำเร็จ' : 'ปฏิเสธการชำระเงินแล้ว'
        ]);
        
    } catch (Exception $e) {
        writeLog("Manual confirm payment error: " . $e->getMessage());
        sendJsonResponse(['success' => false, 'message' => 'เกิดข้อผิดพลาดในการดำเนินการ']);
    }
}

// ไฟล์: api/webhook_omise.php
/**
 * Webhook handler สำหรับ Omise
 */
if (basename(__FILE__) == 'webhook_omise.php' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_once '../config/config.php';
    require_once '../config/database.php';
    require_once '../includes/functions.php';
    
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_OMISE_SIGNATURE'] ?? '';
    
    // ตรวจสอบ signature (ต้องตั้งค่า OMISE_WEBHOOK_SECRET)
    if (defined('OMISE_WEBHOOK_SECRET')) {
        $computedSignature = base64_encode(hash_hmac('sha256', $payload, OMISE_WEBHOOK_SECRET, true));
        
        if (!hash_equals($signature, $computedSignature)) {
            http_response_code(401);
            echo json_encode(['error' => 'Invalid signature']);
            exit;
        }
    }
    
    try {
        $data = json_decode($payload, true);
        
        if ($data['key'] === 'charge.complete') {
            $charge = $data['data'];
            $orderId = $charge['metadata']['order_id'] ?? null;
            
            if ($orderId && $charge['paid']) {
                $db = new Database();
                $conn = $db->getConnection();
                
                // อัปเดตสถานะ payment
                $stmt = $conn->prepare("
                    UPDATE payments 
                    SET status = 'completed', payment_date = NOW(), reference_number = ? 
                    WHERE order_id = ? AND payment_method = 'promptpay'
                ");
                $stmt->execute([$charge['id'], $orderId]);
                
                // อัปเดตสถานะออเดอร์
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET payment_status = 'paid', status = 'confirmed' 
                    WHERE order_id = ?
                ");
                $stmt->execute([$orderId]);
                
                // ส่งการแจ้งเตือน
                sendPaymentNotification($orderId, 'completed');
                
                writeLog("Omise webhook: Payment completed for Order #$orderId");
            }
        }
        
        http_response_code(200);
        echo json_encode(['received' => true]);
        
    } catch (Exception $e) {
        writeLog("Omise webhook error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['error' => 'Internal server error']);
    }
}

/**
 * ฟังก์ชันช่วยเหลือ
 */

/**
 * ตรวจสอบสลิปแบบพื้นฐาน (ตัวอย่าง)
 */
function verifySlipBasic($slipImagePath, $expectedAmount) {
    // ในความเป็นจริงควรใช้ OCR API หรือ Bank API
    // ที่นี่เป็นเพียงตัวอย่าง
    
    // ตรวจสอบว่าไฟล์มีอยู่จริง
    if (!file_exists($slipImagePath)) {
        return false;
    }
    
    // ตรวจสอบขนาดไฟล์ (ไม่ควรเล็กเกินไป)
    if (filesize($slipImagePath) < 10000) { // น้อยกว่า 10KB
        return false;
    }
    
    // ตรวจสอบว่าเป็นรูปภาพจริง
    $imageInfo = getimagesize($slipImagePath);
    if (!$imageInfo) {
        return false;
    }
    
    // *** ในที่นี่คุณสามารถเพิ่ม OCR หรือ API ตรวจสอบสลิป ***
    // เช่น Google Vision API, Azure Computer Vision, หรือ SlipOK API
    
    // สำหรับตัวอย่าง ให้ return true (ควรเปลี่ยนเป็น logic จริง)
    return true;
}

/**
 * ส่งการแจ้งเตือนการชำระเงิน
 */
function sendPaymentNotification($orderId, $status) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // ดึงข้อมูลออเดอร์และผู้ใช้
        $stmt = $conn->prepare("
            SELECT o.*, u.fullname, u.line_user_id 
            FROM orders o 
            LEFT JOIN users u ON o.user_id = u.user_id 
            WHERE o.order_id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if (!$order) return false;
        
        $message = '';
        switch ($status) {
            case 'completed':
                $message = "✅ การชำระเงินสำเร็จ!\n\n";
                $message .= "หมายเลขออเดอร์: #{$order['order_id']}\n";
                $message .= "จำนวนเงิน: " . number_format($order['total_price'], 2) . " บาท\n";
                $message .= "สถานะ: ยืนยันแล้ว\n\n";
                $message .= "ขอบคุณที่ใช้บริการครับ 🙏";
                break;
                
            case 'failed':
                $message = "❌ การชำระเงินไม่สำเร็จ\n\n";
                $message .= "หมายเลขออเดอร์: #{$order['order_id']}\n";
                $message .= "กรุณาชำระเงินใหม่หรือติดต่อเจ้าหน้าที่";
                break;
        }
        
        // ส่งการแจ้งเตือนผ่าน LINE (ถ้ามี)
        if (!empty($order['line_user_id']) && function_exists('sendLineNotification')) {
            sendLineNotification($order['line_user_id'], $message);
        }
        
        // บันทึกการแจ้งเตือนในระบบ
        if (function_exists('sendNotification')) {
            sendNotification(
                $order['user_id'],
                'payment',
                'การชำระเงิน',
                $message,
                $order['order_id']
            );
        }
        
        return true;
        
    } catch (Exception $e) {
        writeLog("Send payment notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * ตรวจสอบการชำระเงินที่หมดอายุ
 * ควรรันเป็น Cron Job
 */
function checkExpiredPayments() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // หาการชำระเงินที่หมดอายุ
        $stmt = $conn->prepare("
            SELECT payment_id, order_id 
            FROM payments 
            WHERE status = 'pending' 
            AND expires_at < NOW()
        ");
        $stmt->execute();
        $expiredPayments = $stmt->fetchAll();
        
        foreach ($expiredPayments as $payment) {
            // อัปเดตสถานะเป็น expired
            $stmt = $conn->prepare("
                UPDATE payments 
                SET status = 'expired' 
                WHERE payment_id = ?
            ");
            $stmt->execute([$payment['payment_id']]);
            
            // อัปเดตสถานะออเดอร์
            $stmt = $conn->prepare("
                UPDATE orders 
                SET status = 'cancelled' 
                WHERE order_id = ? AND payment_status = 'unpaid'
            ");
            $stmt->execute([$payment['order_id']]);
            
            writeLog("Payment expired: Payment ID " . $payment['payment_id']);
        }
        
        return count($expiredPayments);
        
    } catch (Exception $e) {
        writeLog("Check expired payments error: " . $e->getMessage());
        return 0;
    }
}

/**
 * Integration กับ SlipOK API (ตัวอย่าง)
 */
function verifySlipWithSlipOK($imageBase64, $expectedAmount, $apiToken) {
    $postData = [
        'data' => $imageBase64,
        'log' => true
    ];
    
    $curl = curl_init();
    curl_setopt_array($curl, [
        CURLOPT_URL => "https://api.slipok.com/api/line/apikey/$apiToken",
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($postData),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT => 30
    ]);
    
    $response = curl_exec($curl);
    $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
    curl_close($curl);
    
    if ($httpCode === 200) {
        $data = json_decode($response, true);
        
        if ($data['success']) {
            $slip = $data['data'];
            $slipAmount = floatval($slip['amount'] ?? 0);
            
            // ตรวจสอบจำนวนเงิน (ให้ผิดพลาดได้ 1 บาท)
            if (abs($slipAmount - $expectedAmount) <= 1.0) {
                return [
                    'success' => true,
                    'valid' => true,
                    'slip_data' => $slip
                ];
            }
        }
    }
    
    return [
        'success' => false,
        'valid' => false,
        'error' => 'ไม่สามารถตรวจสอบสลิปได้หรือจำนวนเงินไม่ตรงกัน'
    ];
}

?>

<!-- 
ไฟล์ .htaccess สำหรับ API folder
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^(.*)$ index.php [QSA,L]

Header always set Access-Control-Allow-Origin "*"
Header always set Access-Control-Allow-Methods "GET, POST, PUT, DELETE, OPTIONS"
Header always set Access-Control-Allow-Headers "Content-Type, Authorization, X-Requested-With"
-->

<!-- 
Cron Job สำหรับตรวจสอบการชำระเงินที่หมดอายุ
เพิ่มใน crontab:
*/5 * * * * /usr/bin/php /path/to/your/site/api/cron_check_expired_payments.php

ไฟล์: api/cron_check_expired_payments.php
<?php
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

$expiredCount = checkExpiredPayments();
echo "Checked expired payments: $expiredCount payments expired\n";
?>
-->