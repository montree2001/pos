<?php
/**
 * Voice Queue API
 * Handle queue calling functionality
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

// Only allow POST requests
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit();
}

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new Exception('Invalid JSON input');
    }
    
    $action = $input['action'] ?? '';
    $queueNumber = $input['queue_number'] ?? '';
    
    if ($action !== 'call_queue' || empty($queueNumber)) {
        throw new Exception('Invalid parameters');
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    // Check if queue exists and is valid
    $stmt = $conn->prepare("
        SELECT order_id, status, customer_name, total_price 
        FROM orders 
        WHERE queue_number = ? 
        AND status IN ('confirmed', 'preparing', 'ready')
    ");
    $stmt->execute([$queueNumber]);
    $order = $stmt->fetch();
    
    if (!$order) {
        throw new Exception('Queue not found or invalid status');
    }
    
    // Log the queue call
    $stmt = $conn->prepare("
        INSERT INTO queue_calls (order_id, queue_number, called_at, called_by) 
        VALUES (?, ?, NOW(), ?)
    ");
    
    // Get current user if available
    $calledBy = 'POS System';
    if (function_exists('getCurrentUser')) {
        $user = getCurrentUser();
        if ($user) {
            $calledBy = $user['fullname'];
        }
    }
    
    $stmt->execute([$order['order_id'], $queueNumber, $calledBy]);
    
    // Log system activity
    writeLog("Queue called: {$queueNumber} by {$calledBy}");
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => "เรียกคิว {$queueNumber} เรียบร้อยแล้ว",
        'queue_number' => $queueNumber,
        'order_id' => $order['order_id'],
        'timestamp' => date('Y-m-d H:i:s')
    ]);
    
} catch (Exception $e) {
    writeLog("Voice queue error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>