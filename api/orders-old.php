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

// Set content type
header('Content-Type: application/json; charset=utf-8');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-TOKEN, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ตรวจสอบการเข้าถึง
if (!isAjaxRequest()) {
    sendJsonResponse(['error' => 'Invalid request method'], 400);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    switch ($action) {
        case 'pending_count':
            echo json_encode(getPendingOrderCount($conn));
            break;
            
        case 'recent':
            echo json_encode(getRecentOrders($conn));
            break;
            
        case 'get':
            $orderId = $_GET['id'] ?? 0;
            echo json_encode(getOrderDetails($conn, $orderId));
            break;
            
        case 'update_status':
            if ($method === 'POST') {
                $orderId = $_POST['order_id'] ?? 0;
                $status = $_POST['status'] ?? '';
                echo json_encode(updateOrderStatus($conn, $orderId, $status));
            } else {
                sendJsonResponse(['error' => 'Method not allowed'], 405);
            }
            break;
            
        case 'today_stats':
            echo json_encode(getTodayStats($conn));
            break;
            
        default:
            sendJsonResponse(['error' => 'Invalid action'], 400);
    }
    
} catch (Exception $e) {
    writeLog("Orders API error: " . $e->getMessage());
    sendJsonResponse(['error' => 'Internal server error'], 500);
}

/**
 * นับจำนวนออเดอร์ที่รอดำเนินการ
 */
function getPendingOrderCount($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT COUNT(*) as count 
            FROM orders 
            WHERE status IN ('pending', 'confirmed', 'preparing')
        ");
        $stmt->execute();
        $result = $stmt->fetch();
        
        return [
            'success' => true,
            'count' => (int)$result['count']
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * ดึงออเดอร์ล่าสุด
 */
function getRecentOrders($conn, $limit = 10) {
    try {
        $stmt = $conn->prepare("
            SELECT o.*, u.fullname as customer_name 
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.user_id
            ORDER BY o.created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$limit]);
        $orders = $stmt->fetchAll();
        
        return [
            'success' => true,
            'orders' => $orders
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * ดึงรายละเอียดออเดอร์
 */
function getOrderDetails($conn, $orderId) {
    try {
        // ดึงข้อมูลออเดอร์
        $stmt = $conn->prepare("
            SELECT o.*, u.fullname as customer_name, u.phone, u.email
            FROM orders o
            LEFT JOIN users u ON o.user_id = u.user_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            return [
                'success' => false,
                'error' => 'Order not found'
            ];
        }
        
        // ดึงรายการสินค้า
        $stmt = $conn->prepare("
            SELECT oi.*, p.name as product_name, p.image
            FROM order_items oi
            JOIN products p ON oi.product_id = p.product_id
            WHERE oi.order_id = ?
        ");
        $stmt->execute([$orderId]);
        $items = $stmt->fetchAll();
        
        $order['items'] = $items;
        
        return [
            'success' => true,
            'order' => $order
        ];
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * อัปเดตสถานะออเดอร์
 */
function updateOrderStatus($conn, $orderId, $status) {
    try {
        // ตรวจสอบสิทธิ์
        if (!isLoggedIn()) {
            return [
                'success' => false,
                'error' => 'Unauthorized'
            ];
        }
        
        $validStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
        if (!in_array($status, $validStatuses)) {
            return [
                'success' => false,
                'error' => 'Invalid status'
            ];
        }
        
        $conn->beginTransaction();
        
        // อัปเดตสถานะออเดอร์
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = ?, updated_at = NOW() 
            WHERE order_id = ?
        ");
        $stmt->execute([$status, $orderId]);
        
        // บันทึกประวัติการเปลี่ยนสถานะ
        $stmt = $conn->prepare("
            INSERT INTO order_status_history (order_id, status, changed_by, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmt->execute([$orderId, $status, getCurrentUserId()]);
        
        // อัปเดตสถานะรายการสินค้า
        if ($status === 'completed') {
            $stmt = $conn->prepare("
                UPDATE order_items 
                SET status = 'completed' 
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
        }
        
        $conn->commit();
        
        // ส่งการแจ้งเตือน (ถ้าต้องการ)
        // sendOrderStatusNotification($orderId, $status);
        
        return [
            'success' => true,
            'message' => 'Order status updated successfully'
        ];
        
    } catch (Exception $e) {
        $conn->rollback();
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * สถิติวันนี้
 */
function getTodayStats($conn) {
    try {
        // ยอดขายวันนี้
        $stmt = $conn->prepare("
            SELECT 
                COALESCE(SUM(total_price), 0) as today_sales,
                COUNT(*) as today_orders
            FROM orders 
            WHERE DATE(created_at) = CURDATE() 
            AND payment_status = 'paid'
        ");
        $stmt->execute();
        $salesData = $stmt->fetch();
        
        // ออเดอร์ที่รอดำเนินการ
        $stmt = $conn->prepare("
            SELECT COUNT(*) as pending_count
            FROM orders 
            WHERE status IN ('pending', 'confirmed', 'preparing')
        ");
        $stmt->execute();
        $pendingData = $stmt->fetch();
        
        // ออเดอร์เสร็จสิ้นวันนี้
        $stmt = $conn->prepare("
            SELECT COUNT(*) as completed_count
            FROM orders 
            WHERE DATE(created_at) = CURDATE() 
            AND status = 'completed'
        ");
        $stmt->execute();
        $completedData = $stmt->fetch();
        
        return [
            'success' => true,
            'stats' => [
                'today_sales' => (float)$salesData['today_sales'],
                'today_orders' => (int)$salesData['today_orders'],
                'pending_orders' => (int)$pendingData['pending_count'],
                'completed_orders' => (int)$completedData['completed_count']
            ]
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}
?>