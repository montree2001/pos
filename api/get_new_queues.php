<?php
/**
 * Get New Queues API
 * Returns newly created queues that haven't been announced yet
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../includes/functions.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // รับพารามิเตอร์
    $lastCheck = $_GET['last_check'] ?? null;
    $minutes = (int)($_GET['minutes'] ?? 5); // ค่าเริ่มต้น 5 นาที
    
    // สร้าง condition สำหรับเวลา
    if ($lastCheck) {
        $timeCondition = "o.created_at > ?";
        $statsTimeCondition = "created_at > ?";
        $timeParam = $lastCheck;
    } else {
        $timeCondition = "o.created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)";
        $statsTimeCondition = "created_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)";
        $timeParam = $minutes;
    }
    
    // ดึงคิวใหม่ที่ยังไม่ได้ประกาศ
    $stmt = $conn->prepare("
        SELECT 
            o.order_id,
            o.queue_number,
            o.status,
            o.total_price,
            o.created_at,
            u.fullname as customer_name,
            CASE 
                WHEN qc.queue_number IS NULL THEN 0
                ELSE 1
            END as has_been_called
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        LEFT JOIN queue_calls qc ON o.queue_number = qc.queue_number
        WHERE o.status IN ('confirmed', 'preparing', 'ready')
        AND $timeCondition
        ORDER BY o.created_at DESC
    ");
    
    $stmt->execute([$timeParam]);
    $orders = $stmt->fetchAll();
    
    $newQueues = [];
    $readyQueues = [];
    
    foreach ($orders as $order) {
        $queueItem = [
            'order_id' => (int)$order['order_id'],
            'queue_number' => $order['queue_number'],
            'order_number' => $order['queue_number'],
            'status' => $order['status'],
            'customer_name' => $order['customer_name'] ?: 'ลูกค้าทั่วไป',
            'total_price' => (float)$order['total_price'],
            'created_at' => $order['created_at'],
            'has_been_called' => (bool)$order['has_been_called'],
            'is_new' => true
        ];
        
        // แยกคิวใหม่และคิวที่พร้อมเสิร์ฟ
        if ($order['status'] === 'ready' && !$order['has_been_called']) {
            $readyQueues[] = $queueItem;
        } elseif (in_array($order['status'], ['confirmed', 'preparing'])) {
            $newQueues[] = $queueItem;
        }
    }
    
    // ดึงข้อมูลสถิติรวม
    $statsStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_new,
            COUNT(CASE WHEN status = 'ready' THEN 1 END) as ready_count,
            COUNT(CASE WHEN status = 'preparing' THEN 1 END) as preparing_count,
            COUNT(CASE WHEN status = 'confirmed' THEN 1 END) as waiting_count
        FROM orders 
        WHERE status IN ('confirmed', 'preparing', 'ready')
        AND $statsTimeCondition
    ");
    
    $statsStmt->execute([$timeParam]);
    $stats = $statsStmt->fetch();
    
    echo json_encode([
        'success' => true,
        'newQueues' => array_merge($readyQueues, $newQueues),
        'readyQueues' => $readyQueues,
        'waitingQueues' => $newQueues,
        'stats' => [
            'total_new' => (int)$stats['total_new'],
            'ready_count' => (int)$stats['ready_count'],
            'preparing_count' => (int)$stats['preparing_count'],
            'waiting_count' => (int)$stats['waiting_count']
        ],
        'last_check' => date('Y-m-d H:i:s'),
        'checked_since' => $lastCheck ?: "$minutes minutes ago"
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    writeLog("Get new queues error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลคิวใหม่',
        'error' => $e->getMessage(),
        'newQueues' => []
    ], JSON_UNESCAPED_UNICODE);
}
?>