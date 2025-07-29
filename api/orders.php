<?php
/**
 * API จัดการออเดอร์
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

// ตั้งค่า Content-Type
header('Content-Type: application/json; charset=utf-8');

$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    $db = new Database();
    $conn = $db->getConnection();

    switch ($action) {
        case 'pending_count':
            getPendingOrderCount($conn);
            break;
            
        case 'recent_orders':
            getRecentOrders($conn);
            break;
            
        case 'order_status':
            getOrderStatus($conn);
            break;
            
        default:
            throw new Exception('การกระทำไม่ถูกต้อง');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}

/**
 * ดึงจำนวนออเดอร์ที่รอดำเนินการ
 */
function getPendingOrderCount($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE status IN ('pending', 'confirmed', 'preparing') 
            AND DATE(created_at) = CURDATE()
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        
        echo json_encode([
            'success' => true,
            'count' => intval($result['count'])
        ]);
        
    } catch (Exception $e) {
        throw new Exception('ไม่สามารถดึงจำนวนออเดอร์ได้');
    }
}

/**
 * ดึงออเดอร์ล่าสุด
 */
function getRecentOrders($conn) {
    try {
        $limit = $_GET['limit'] ?? 10;
        
        $stmt = $conn->prepare("
            SELECT 
                o.order_id,
                o.queue_number,
                o.total_price,
                o.status,
                o.created_at,
                u.fullname as customer_name
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.user_id
            WHERE DATE(o.created_at) = CURDATE()
            ORDER BY o.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $orders = $stmt->fetchAll();
        
        // Format data
        foreach ($orders as &$order) {
            $order['total_price'] = floatval($order['total_price']);
            $order['status_text'] = getOrderStatusText($order['status']);
            $order['status_class'] = getOrderStatusClass($order['status']);
            $order['created_at_formatted'] = formatDate($order['created_at'], 'H:i');
        }
        
        echo json_encode([
            'success' => true,
            'data' => $orders
        ]);
        
    } catch (Exception $e) {
        throw new Exception('ไม่สามารถดึงข้อมูลออเดอร์ได้');
    }
}

/**
 * ดึงสถานะออเดอร์
 */
function getOrderStatus($conn) {
    try {
        $orderId = $_GET['order_id'] ?? '';
        
        if (empty($orderId)) {
            throw new Exception('ไม่พบรหัสออเดอร์');
        }
        
        $stmt = $conn->prepare("
            SELECT 
                o.*,
                u.fullname as customer_name,
                u.phone as customer_phone
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.user_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            throw new Exception('ไม่พบออเดอร์');
        }
        
        // ดึงรายการสินค้า
        $itemStmt = $conn->prepare("
            SELECT 
                oi.*,
                p.name as product_name
            FROM order_items oi
            JOIN products p ON oi.product_id = p.product_id
            WHERE oi.order_id = ?
        ");
        $itemStmt->execute([$orderId]);
        $items = $itemStmt->fetchAll();
        
        $order['items'] = $items;
        $order['status_text'] = getOrderStatusText($order['status']);
        $order['status_class'] = getOrderStatusClass($order['status']);
        
        echo json_encode([
            'success' => true,
            'data' => $order
        ]);
        
    } catch (Exception $e) {
        throw new Exception('ไม่สามารถดึงสถานะออเดอร์ได้');
    }
}

/**
 * ฟังก์ชันช่วยเหลือ (ถ้ายังไม่มี)
 */
if (!function_exists('getOrderStatusText')) {
    function getOrderStatusText($status) {
        $statusMap = [
            'pending' => 'รอยืนยัน',
            'confirmed' => 'ยืนยันแล้ว',
            'preparing' => 'กำลังเตรียม',
            'ready' => 'พร้อมเสิร์ฟ',
            'completed' => 'เสร็จสิ้น',
            'cancelled' => 'ยกเลิก'
        ];
        
        return $statusMap[$status] ?? 'ไม่ทราบสถานะ';
    }
}

if (!function_exists('getOrderStatusClass')) {
    function getOrderStatusClass($status) {
        $classMap = [
            'pending' => 'bg-warning',
            'confirmed' => 'bg-info',
            'preparing' => 'bg-primary',
            'ready' => 'bg-success',
            'completed' => 'bg-secondary',
            'cancelled' => 'bg-danger'
        ];
        
        return $classMap[$status] ?? 'bg-secondary';
    }
}
?>