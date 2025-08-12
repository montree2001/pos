<?php
/**
 * API ตรวจสอบสถานะการชำระเงิน
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
    if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        throw new Exception('Method not allowed');
    }
    
    $paymentId = trim($_GET['payment_id'] ?? '');
    
    if (empty($paymentId)) {
        throw new Exception('ไม่ได้ระบุรหัสการชำระเงิน');
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงข้อมูลการชำระเงิน
    $stmt = $conn->prepare("
        SELECT p.*, o.order_number, o.payment_status as order_payment_status
        FROM payments p
        JOIN orders o ON p.order_id = o.order_id
        WHERE p.payment_id = ?
    ");
    $stmt->execute([intval($paymentId)]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment) {
        throw new Exception('ไม่พบข้อมูลการชำระเงิน');
    }
    
    // ตรวจสอบว่าหมดอายุหรือไม่
    $now = new DateTime();
    $expiresAt = new DateTime($payment['expires_at']);
    $isExpired = $now > $expiresAt;
    
    // ถ้าหมดอายุและยังไม่ได้ชำระ ให้อัปเดตสถานะ
    if ($isExpired && $payment['status'] === 'pending') {
        $stmt = $conn->prepare("
            UPDATE payments 
            SET status = 'failed'
            WHERE payment_id = ?
        ");
        $stmt->execute([$payment['payment_id']]);
        $payment['status'] = 'failed';
    }
    
    // ส่งข้อมูลสถานะกลับ
    echo json_encode([
        'success' => true,
        'payment_id' => $payment['payment_id'],
        'order_id' => $payment['order_id'],
        'order_number' => $payment['order_number'],
        'amount' => floatval($payment['amount']),
        'status' => $payment['status'],
        'is_expired' => $isExpired,
        'created_at' => $payment['payment_date'],
        'expires_at' => $payment['expires_at'],
        'reference_number' => $payment['reference_number']
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>