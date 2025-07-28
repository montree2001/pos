<?php
/**
 * API สำหรับระบบครัว
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

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

// ตรวจสอบสิทธิ์ครัว
if (!isLoggedIn() || getCurrentUserRole() !== 'kitchen') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'ไม่มีสิทธิ์เข้าถึง',
        'redirect' => SITE_URL . '/kitchen/login.php'
    ]);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($action) {
        
        // ดึงรายการออเดอร์ที่ต้องทำ
        case 'get_active_orders':
            $stmt = $conn->prepare("
                SELECT o.*, u.fullname as customer_name, u.phone,
                       COUNT(oi.item_id) as total_items,
                       SUM(CASE WHEN oi.status = 'completed' THEN 1 ELSE 0 END) as completed_items,
                       GROUP_CONCAT(
                           CONCAT(p.name, ' (', oi.quantity, ')') 
                           ORDER BY oi.item_id ASC 
                           SEPARATOR ', '
                       ) as items_summary
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.user_id
                LEFT JOIN order_items oi ON o.order_id = oi.order_id
                LEFT JOIN products p ON oi.product_id = p.product_id
                WHERE o.status IN ('confirmed', 'preparing') 
                AND o.payment_status = 'paid'
                GROUP BY o.order_id
                ORDER BY o.created_at ASC
            ");
            $stmt->execute();
            $orders = $stmt->fetchAll();
            
            // เพิ่มข้อมูลเวลาที่ผ่านไป
            foreach ($orders as &$order) {
                $orderTime = new DateTime($order['created_at']);
                $now = new DateTime();
                $diff = $now->diff($orderTime);
                $order['minutes_passed'] = ($diff->h * 60) + $diff->i;
                $order['is_urgent'] = $order['minutes_passed'] > 20;
                $order['progress'] = $order['total_items'] > 0 ? 
                    ($order['completed_items'] / $order['total_items']) * 100 : 0;
            }
            
            echo json_encode([
                'success' => true,
                'orders' => $orders
            ]);
            break;
            
        // ดึงรายละเอียดออเดอร์
        case 'get_order_details':
            $orderId = intval($_GET['order_id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            // ข้อมูลออเดอร์
            $stmt = $conn->prepare("
                SELECT o.*, u.fullname as customer_name, u.phone, u.email
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.user_id
                WHERE o.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception('Order not found');
            }
            
            // รายการสินค้า
            $stmt = $conn->prepare("
                SELECT oi.*, p.name as product_name, p.image, p.preparation_time,
                       c.name as category_name
                FROM order_items oi
                JOIN products p ON oi.product_id = p.product_id
                LEFT JOIN categories c ON p.category_id = c.category_id
                WHERE oi.order_id = ?
                ORDER BY oi.item_id ASC
            ");
            $stmt->execute([$orderId]);
            $order['items'] = $stmt->fetchAll();
            
            // ประวัติการเปลี่ยนสถานะ
            $stmt = $conn->prepare("
                SELECT osh.*, u.fullname as changed_by_name
                FROM order_status_history osh
                LEFT JOIN users u ON osh.changed_by = u.user_id
                WHERE osh.order_id = ?
                ORDER BY osh.created_at DESC
            ");
            $stmt->execute([$orderId]);
            $order['status_history'] = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'order' => $order
            ]);
            break;
            
        // อัปเดตสถานะออเดอร์
        case 'update_order_status':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $orderId = intval($_POST['order_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $validStatuses = ['confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
            
            if (!$orderId || !in_array($status, $validStatuses)) {
                throw new Exception('Invalid parameters');
            }
            
            // เริ่ม transaction
            $conn->beginTransaction();
            
            try {
                // อัปเดตสถานะออเดอร์
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET status = ?, updated_at = NOW() 
                    WHERE order_id = ?
                ");
                $stmt->execute([$status, $orderId]);
                
                // บันทึกประวัติ
                $stmt = $conn->prepare("
                    INSERT INTO order_status_history (order_id, status, changed_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$orderId, $status, getCurrentUserId()]);
                
                // อัปเดตสถานะรายการอาหารถ้าจำเป็น
                if ($status === 'preparing') {
                    $stmt = $conn->prepare("
                        UPDATE order_items 
                        SET status = 'preparing' 
                        WHERE order_id = ? AND status = 'pending'
                    ");
                    $stmt->execute([$orderId]);
                } elseif ($status === 'ready') {
                    $stmt = $conn->prepare("
                        UPDATE order_items 
                        SET status = 'ready' 
                        WHERE order_id = ?
                    ");
                    $stmt->execute([$orderId]);
                }
                
                $conn->commit();
                
                // ส่งการแจ้งเตือน (ถ้ามี)
                if ($status === 'ready') {
                    // แจ้งเตือนลูกค้าว่าอาหารพร้อม
                    $stmt = $conn->prepare("
                        SELECT u.line_user_id, o.queue_number 
                        FROM orders o
                        LEFT JOIN users u ON o.user_id = u.user_id
                        WHERE o.order_id = ?
                    ");
                    $stmt->execute([$orderId]);
                    $orderInfo = $stmt->fetch();
                    
                    if ($orderInfo && $orderInfo['line_user_id']) {
                        // TODO: ส่ง LINE notification
                    }
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'อัปเดตสถานะสำเร็จ',
                    'new_status' => $status
                ]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
            
        // อัปเดตสถานะรายการอาหาร
        case 'update_item_status':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $itemId = intval($_POST['item_id'] ?? 0);
            $status = $_POST['status'] ?? '';
            $validStatuses = ['pending', 'preparing', 'ready', 'completed'];
            
            if (!$itemId || !in_array($status, $validStatuses)) {
                throw new Exception('Invalid parameters');
            }
            
            // อัปเดตสถานะรายการ
            $stmt = $conn->prepare("
                UPDATE order_items 
                SET status = ? 
                WHERE item_id = ?
            ");
            $stmt->execute([$status, $itemId]);
            
            // ตรวจสอบว่าทุกรายการในออเดอร์เสร็จหรือยัง
            $stmt = $conn->prepare("
                SELECT oi.order_id, 
                       COUNT(*) as total_items,
                       SUM(CASE WHEN oi.status IN ('ready', 'completed') THEN 1 ELSE 0 END) as finished_items
                FROM order_items oi 
                WHERE oi.order_id = (
                    SELECT order_id FROM order_items WHERE item_id = ?
                )
                GROUP BY oi.order_id
            ");
            $stmt->execute([$itemId]);
            $result = $stmt->fetch();
            
            $orderReady = false;
            if ($result && $result['total_items'] == $result['finished_items']) {
                // อัปเดตสถานะออเดอร์เป็น ready
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET status = 'ready', updated_at = NOW() 
                    WHERE order_id = ?
                ");
                $stmt->execute([$result['order_id']]);
                
                // บันทึกประวัติ
                $stmt = $conn->prepare("
                    INSERT INTO order_status_history (order_id, status, changed_by, created_at)
                    VALUES (?, 'ready', ?, NOW())
                ");
                $stmt->execute([$result['order_id'], getCurrentUserId()]);
                
                $orderReady = true;
            }
            
            echo json_encode([
                'success' => true,
                'message' => 'อัปเดตสถานะรายการสำเร็จ',
                'order_ready' => $orderReady,
                'order_id' => $result['order_id'] ?? null
            ]);
            break;
            
        // ดึงสถิติครัว
        case 'get_kitchen_stats':
            $date = $_GET['date'] ?? date('Y-m-d');
            
            // สถิติพื้นฐาน
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) as preparing_orders,
                    SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_orders,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                    AVG(TIMESTAMPDIFF(MINUTE, created_at, 
                        CASE WHEN status = 'completed' THEN updated_at ELSE NULL END
                    )) as avg_preparation_time
                FROM orders 
                WHERE DATE(created_at) = ?
                AND payment_status = 'paid'
            ");
            $stmt->execute([$date]);
            $stats = $stmt->fetch();
            
            // รายการอาหารที่กำลังทำ
            $stmt = $conn->prepare("
                SELECT p.name, COUNT(*) as count
                FROM order_items oi
                JOIN products p ON oi.product_id = p.product_id
                JOIN orders o ON oi.order_id = o.order_id
                WHERE DATE(o.created_at) = ?
                AND oi.status = 'preparing'
                AND o.payment_status = 'paid'
                GROUP BY p.product_id
                ORDER BY count DESC
                LIMIT 5
            ");
            $stmt->execute([$date]);
            $popular_items = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'stats' => $stats,
                'popular_items' => $popular_items
            ]);
            break;
            
        // ดึงออเดอร์ที่เสร็จแล้ว
        case 'get_completed_orders':
            $date = $_GET['date'] ?? date('Y-m-d');
            $limit = intval($_GET['limit'] ?? 20);
            $offset = intval($_GET['offset'] ?? 0);
            
            $stmt = $conn->prepare("
                SELECT o.*, u.fullname as customer_name,
                       COUNT(oi.item_id) as total_items,
                       TIMESTAMPDIFF(MINUTE, o.created_at, o.updated_at) as preparation_time
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.user_id
                LEFT JOIN order_items oi ON o.order_id = oi.order_id
                WHERE DATE(o.created_at) = ?
                AND o.status IN ('ready', 'completed')
                AND o.payment_status = 'paid'
                GROUP BY o.order_id
                ORDER BY o.updated_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$date, $limit, $offset]);
            $orders = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'orders' => $orders
            ]);
            break;
            
        // เรียกคิว
        case 'call_queue':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $orderId = intval($_POST['order_id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            // ดึงข้อมูลออเดอร์
            $stmt = $conn->prepare("
                SELECT queue_number, total_price 
                FROM orders 
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception('Order not found');
            }
            
            // บันทึกการเรียกคิว
            $stmt = $conn->prepare("
                INSERT INTO voice_calls (order_id, queue_number, message, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $message = "เรียกคิวหมายเลข " . $order['queue_number'] . " กรุณามารับอาหาร";
            $stmt->execute([$orderId, $order['queue_number'], $message]);
            
            echo json_encode([
                'success' => true,
                'message' => 'เรียกคิวสำเร็จ',
                'queue_number' => $order['queue_number'],
                'voice_message' => $message
            ]);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
    
    // บันทึก error log
    writeLog("Kitchen API Error: " . $e->getMessage() . " | Action: $action");
}
?>