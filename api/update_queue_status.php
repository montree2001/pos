<?php
/**
 * Update Queue Status API
 * Updates the status of a queue/order
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $orderId = $input['order_id'] ?? null;
    $queueNumber = $input['queue_number'] ?? null;
    $status = $input['status'] ?? null;
    $notes = $input['notes'] ?? null;
    
    // Validate input
    if (!$orderId && !$queueNumber) {
        throw new Exception('order_id หรือ queue_number จำเป็นต้องระบุ');
    }
    
    if (!$status) {
        throw new Exception('status จำเป็นต้องระบุ');
    }
    
    // Validate status
    $validStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
    if (!in_array($status, $validStatuses)) {
        throw new Exception('สถานะไม่ถูกต้อง: ' . implode(', ', $validStatuses));
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Build WHERE condition
    if ($orderId) {
        $whereCondition = "order_id = ?";
        $whereParam = $orderId;
    } else {
        $whereCondition = "queue_number = ?";
        $whereParam = $queueNumber;
    }
    
    // Get current order info
    $stmt = $conn->prepare("
        SELECT order_id, queue_number, status, user_id, total_price
        FROM orders 
        WHERE $whereCondition
    ");
    $stmt->execute([$whereParam]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception('ไม่พบออเดอร์ที่ระบุ');
    }
    
    $oldStatus = $order['status'];
    
    // Update order status
    $updateStmt = $conn->prepare("
        UPDATE orders 
        SET status = ?, updated_at = NOW()
        WHERE $whereCondition
    ");
    $updateStmt->execute([$status, $whereParam]);
    
    // Log status change
    $logStmt = $conn->prepare("
        INSERT INTO order_status_history (order_id, status, changed_by, created_at)
        VALUES (?, ?, ?, NOW())
    ");
    
    $changedBy = null; // Get current user ID if available
    if (function_exists('getCurrentUser')) {
        $user = getCurrentUser();
        if ($user) {
            $changedBy = $user['user_id'];
        }
    }
    
    $logStmt->execute([
        $order['order_id'],
        $status,
        $changedBy
    ]);
    
    // Log system activity
    writeLog("Order status updated: {$order['queue_number']} from {$oldStatus} to {$status} by {$changedBy}");
    
    // Get updated order info
    $stmt->execute([$whereParam]);
    $updatedOrder = $stmt->fetch();
    
    echo json_encode([
        'success' => true,
        'message' => 'อัปเดตสถานะคิวเรียบร้อยแล้ว',
        'order' => [
            'order_id' => (int)$updatedOrder['order_id'],
            'queue_number' => $updatedOrder['queue_number'],
            'old_status' => $oldStatus,
            'new_status' => $status,
            'status_text' => getOrderStatusText($status),
            'changed_by' => $changedBy ?: 'System',
            'timestamp' => date('Y-m-d H:i:s')
        ]
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    writeLog("Update queue status error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>