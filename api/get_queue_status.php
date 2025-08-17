<?php
/**
 * Get Queue Status API
 * Returns current queue status for all orders
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
    
    // ดึงข้อมูลคิวทั้งหมดที่ยังไม่เสร็จสิ้น
    $stmt = $conn->prepare("
        SELECT 
            o.order_id,
            o.queue_number,
            o.status,
            o.total_price,
            o.created_at,
            o.updated_at,
            u.fullname as customer_name,
            u.user_id
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE o.status IN ('confirmed', 'preparing', 'ready')
        ORDER BY 
            FIELD(o.status, 'ready', 'preparing', 'confirmed'),
            o.created_at ASC
    ");
    
    $stmt->execute();
    $orders = $stmt->fetchAll();
    
    // จัดกลุ่มตามสถานะ
    $queueData = [
        'waiting' => [],
        'preparing' => [],
        'ready' => []
    ];
    
    foreach ($orders as $order) {
        $queueItem = [
            'order_id' => (int)$order['order_id'],
            'queue_number' => $order['queue_number'],
            'order_number' => $order['queue_number'], // alias for compatibility
            'status' => $order['status'],
            'customer_name' => $order['customer_name'] ?: 'ลูกค้าทั่วไป',
            'total_price' => (float)$order['total_price'],
            'created_at' => $order['created_at'],
            'updated_at' => $order['updated_at'],
            'time_elapsed' => calculateTimeElapsed($order['created_at']),
            'status_text' => getOrderStatusText($order['status']),
            'status_class' => getOrderStatusClass($order['status'])
        ];
        
        // จัดกลุ่มตามสถานะ
        switch ($order['status']) {
            case 'confirmed':
                $queueData['waiting'][] = $queueItem;
                break;
            case 'preparing':
                $queueData['preparing'][] = $queueItem;
                break;
            case 'ready':
                $queueData['ready'][] = $queueItem;
                break;
        }
    }
    
    // สถิติคิว
    $stats = [
        'total_active' => count($orders),
        'waiting_count' => count($queueData['waiting']),
        'preparing_count' => count($queueData['preparing']),
        'ready_count' => count($queueData['ready'])
    ];
    
    // ส่งข้อมูลกลับ
    echo json_encode([
        'success' => true,
        'queues' => array_merge(
            $queueData['ready'],
            $queueData['preparing'], 
            $queueData['waiting']
        ),
        'grouped_queues' => $queueData,
        'stats' => $stats,
        'timestamp' => date('Y-m-d H:i:s')
    ], JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    writeLog("Get queue status error: " . $e->getMessage());
    
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการดึงข้อมูลคิว',
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * คำนวณเวลาที่ผ่านไปตั้งแต่สร้างออเดอร์
 */
function calculateTimeElapsed($createdAt) {
    $created = new DateTime($createdAt);
    $now = new DateTime();
    $diff = $now->diff($created);
    
    if ($diff->h > 0) {
        return $diff->h . ' ชั่วโมง ' . $diff->i . ' นาที';
    } else {
        return $diff->i . ' นาที';
    }
}
?>