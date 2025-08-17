<?php
/**
 * Simple Voice Queue API - Always works
 * ไม่ต้องพึ่งฐานข้อมูล เพื่อให้ทำงานได้แน่นอน
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-TOKEN, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'POST') {
        // Get JSON input
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST; // Fallback to form data
        }
        
        $action = $input['action'] ?? '';
        $queueNumber = $input['queue_number'] ?? '';
        
        if ($action === 'call_queue' && !empty($queueNumber)) {
            // Always success for testing
            echo json_encode([
                'success' => true,
                'message' => "เรียกคิว {$queueNumber} เรียบร้อยแล้ว",
                'queue_number' => $queueNumber,
                'timestamp' => date('Y-m-d H:i:s'),
                'order_id' => rand(1000, 9999),
                'customer_name' => 'ลูกค้าทดสอบ',
                'status' => 'called'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            throw new Exception('ข้อมูลไม่ถูกต้อง');
        }
        
    } else if ($method === 'GET') {
        // Return sample queue data
        echo json_encode([
            'success' => true,
            'message' => 'API ทำงานปกติ',
            'queues' => [
                [
                    'queue_number' => '1',
                    'status' => 'waiting',
                    'customer_name' => 'ลูกค้า A',
                    'created_at' => date('Y-m-d H:i:s')
                ],
                [
                    'queue_number' => '2', 
                    'status' => 'preparing',
                    'customer_name' => 'ลูกค้า B',
                    'created_at' => date('Y-m-d H:i:s')
                ],
                [
                    'queue_number' => '3',
                    'status' => 'ready', 
                    'customer_name' => 'ลูกค้า C',
                    'created_at' => date('Y-m-d H:i:s')
                ]
            ],
            'timestamp' => date('Y-m-d H:i:s')
        ], JSON_UNESCAPED_UNICODE);
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error_code' => 'API_ERROR'
    ], JSON_UNESCAPED_UNICODE);
}
?>