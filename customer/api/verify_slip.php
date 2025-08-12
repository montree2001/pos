<?php
/**
 * API ตรวจสอบสลิปการโอนเงิน
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
    
    $paymentId = trim($_POST['payment_id'] ?? '');
    $slipImage = $_POST['slip_image'] ?? '';
    
    if (empty($paymentId)) {
        throw new Exception('ไม่ได้ระบุรหัสการชำระเงิน');
    }
    
    if (empty($slipImage)) {
        throw new Exception('ไม่พบรูปสลิป');
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงข้อมูลการชำระเงิน
    $stmt = $conn->prepare("
        SELECT p.*, o.order_number, o.total_price as order_amount
        FROM payments p
        JOIN orders o ON p.order_id = o.order_id
        WHERE p.payment_id = ? AND p.status = 'pending'
    ");
    $stmt->execute([intval($paymentId)]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        throw new Exception('ไม่พบการชำระเงินหรือชำระแล้ว');
    }
    
    // ตรวจสอบว่าหมดอายุหรือไม่
    $now = new DateTime();
    $expiresAt = new DateTime($payment['expires_at']);
    if ($now > $expiresAt) {
        throw new Exception('การชำระเงินหมดอายุแล้ว');
    }
    
    // บันทึกรูปสลิป
    $uploadDir = '../../uploads/slips/';
    if (!file_exists($uploadDir)) {
        if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
            // ถ้าสร้างโฟลเดอร์ไม่ได้ ใช้โฟลเดอร์ temp แทน
            $uploadDir = '../../uploads/temp/';
        }
    }
    
    $slipFileName = $paymentId . '_' . time() . '.jpg';
    $slipFilePath = $uploadDir . $slipFileName;
    
    // แปลง base64 เป็นไฟล์รูป
    $imageData = base64_decode($slipImage);
    if (file_put_contents($slipFilePath, $imageData) === false) {
        throw new Exception('ไม่สามารถบันทึกรูปสลิปได้');
    }
    
    // ตรวจสอบสลิป (จำลอง - ในระบบจริงควรใช้ AI หรือ API ของธนาคาร)
    $slipVerification = verifySlipImage($slipFilePath, $payment['amount']);
    
    if ($slipVerification['verified']) {
        $conn->beginTransaction();
        
        try {
            // อัปเดตสถานะการชำระเงิน
            $stmt = $conn->prepare("
                UPDATE payments 
                SET status = 'completed', 
                    slip_image = ?
                WHERE payment_id = ?
            ");
            
            $stmt->execute([
                $slipFileName,
                $payment['payment_id']
            ]);
            
            // อัปเดตสถานะออเดอร์
            $stmt = $conn->prepare("
                UPDATE orders 
                SET payment_status = 'paid', 
                    status = 'confirmed'
                WHERE order_id = ?
            ");
            $stmt->execute([$payment['order_id']]);
            
            $conn->commit();
            
            // บันทึก log
            writeLog("Payment verified via slip: Payment ID {$paymentId}, Order ID {$payment['order_id']}");
            
            echo json_encode([
                'success' => true,
                'verified' => true,
                'message' => 'ตรวจสอบสลิปสำเร็จ',
                'payment_id' => $paymentId,
                'amount_verified' => $slipVerification['amount'],
                'confidence' => $slipVerification['confidence']
            ], JSON_UNESCAPED_UNICODE);
            
        } catch (Exception $e) {
            $conn->rollBack();
            throw $e;
        }
        
    } else {
        // บันทึกสลิปที่ไม่ผ่านการตรวจสอบ
        $stmt = $conn->prepare("
            UPDATE payments 
            SET slip_image = ?
            WHERE payment_id = ?
        ");
        
        $stmt->execute([
            $slipFileName,
            $payment['payment_id']
        ]);
        
        echo json_encode([
            'success' => true,
            'verified' => false,
            'message' => $slipVerification['error'] ?? 'สลิปไม่ตรงกับข้อมูลการชำระเงิน',
            'details' => $slipVerification
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * ตรวจสอบสลิปการโอนเงิน (จำลอง)
 * ในระบบจริงควรใช้ OCR หรือ API ของธนาคาร
 */
function verifySlipImage($imagePath, $expectedAmount) {
    // จำลองการตรวจสอบสลิป
    // ในระบบจริงควรใช้:
    // 1. OCR เพื่ออ่านข้อความในสลิป
    // 2. API ของธนาคารเพื่อตรวจสอบ
    // 3. Machine Learning สำหรับการจดจำรูปแบบสลิป
    
    $verification = [
        'verified' => false,
        'amount' => 0,
        'confidence' => 0,
        'bank' => '',
        'transaction_ref' => '',
        'timestamp' => '',
        'error' => ''
    ];
    
    // ตรวจสอบว่าไฟล์รูปถูกต้องหรือไม่
    if (!file_exists($imagePath)) {
        $verification['error'] = 'ไม่พบไฟล์รูป';
        return $verification;
    }
    
    $imageSize = getimagesize($imagePath);
    if (!$imageSize) {
        $verification['error'] = 'ไฟล์รูปเสียหาย';
        return $verification;
    }
    
    // จำลองการตรวจสอบ - สำหรับ demo จะให้ผ่านในบางกรณี
    $random = rand(1, 100);
    
    if ($random <= 70) { // 70% โอกาสที่จะผ่าน
        $verification['verified'] = true;
        $verification['amount'] = $expectedAmount;
        $verification['confidence'] = rand(80, 95);
        $verification['bank'] = 'ธนาคารจำลอง';
        $verification['transaction_ref'] = 'T' . rand(100000, 999999);
        $verification['timestamp'] = date('Y-m-d H:i:s');
    } else {
        $verification['error'] = 'จำนวนเงินไม่ตรงกัน หรือสลิปไม่ชัดเจน';
        $verification['confidence'] = rand(10, 50);
    }
    
    return $verification;
}
?>