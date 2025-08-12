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

// ตั้งค่า Content-Type และ CORS
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// รับข้อมูล action จาก GET, POST หรือ JSON
$action = $_GET['action'] ?? $_POST['action'] ?? '';

// ถ้าไม่พบ action ใน GET/POST ให้ลองดึงจาก JSON body
if (empty($action)) {
    $input = json_decode(file_get_contents('php://input'), true);
    $action = $input['action'] ?? '';
}

// Debug logging
error_log("API Request - Method: " . $_SERVER['REQUEST_METHOD'] . ", Action: " . $action);
if (!empty($input)) {
    error_log("API Request - JSON Input: " . json_encode($input));
}

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
            
        case 'get_details':
            getOrderDetails($conn);
            break;
            
        case 'update_status':
            updateOrderStatus($conn, $input ?? null);
            break;
            
        case 'update_details':
            updateOrderDetails($conn, $input ?? null);
            break;
            
        default:
            throw new Exception('การกระทำไม่ถูกต้อง');
    }

} catch (Exception $e) {
    error_log("API Error: " . $e->getMessage() . " in " . $e->getFile() . " on line " . $e->getLine());
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'debug' => [
            'action' => $action,
            'method' => $_SERVER['REQUEST_METHOD'],
            'file' => basename($e->getFile()),
            'line' => $e->getLine()
        ]
    ], JSON_UNESCAPED_UNICODE);
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
                o.customer_name
            FROM orders o
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
        
        if (empty($orderId) || !is_numeric($orderId)) {
            throw new Exception('รหัสออเดอร์ไม่ถูกต้อง');
        }
        
        $stmt = $conn->prepare("
            SELECT * FROM orders
            WHERE order_id = ?
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

// ฟังก์ชันช่วยเหลือจะถูกโหลดจาก functions.php อัตโนมัติ

/**
 * ดึงรายละเอียดออเดอร์
 */
function getOrderDetails($conn) {
    try {
        $orderId = $_GET['order_id'] ?? '';
        
        if (empty($orderId) || !is_numeric($orderId)) {
            throw new Exception('รหัสออเดอร์ไม่ถูกต้อง');
        }
        
        // ดึงข้อมูลออเดอร์
        $stmt = $conn->prepare("
            SELECT * FROM orders
            WHERE order_id = ?
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
            LEFT JOIN products p ON oi.product_id = p.product_id
            WHERE oi.order_id = ?
            ORDER BY oi.item_id
        ");
        $itemStmt->execute([$orderId]);
        $items = $itemStmt->fetchAll();
        
        // Format data
        $order['total_price'] = floatval($order['total_price']);
        foreach ($items as &$item) {
            $item['unit_price'] = floatval($item['unit_price']);
            $item['subtotal'] = floatval($item['subtotal']);
        }
        
        echo json_encode([
            'success' => true,
            'order' => $order,
            'items' => $items
        ]);
        
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }
}

/**
 * อัปเดตสถานะออเดอร์
 */
function updateOrderStatus($conn, $inputData = null) {
    try {
        // รับข้อมูลจาก parameter หรือ JSON input
        if ($inputData === null) {
            $inputData = json_decode(file_get_contents('php://input'), true);
        }
        
        $orderId = $inputData['order_id'] ?? '';
        $newStatus = $inputData['status'] ?? '';
        
        error_log("UpdateOrderStatus - OrderID: $orderId, Status: $newStatus");
        
        if (empty($orderId) || !is_numeric($orderId)) {
            throw new Exception('รหัสออเดอร์ไม่ถูกต้อง');
        }
        
        if (empty($newStatus)) {
            throw new Exception('ไม่ได้ระบุสถานะใหม่');
        }
        
        // Validate status
        $validStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
        if (!in_array($newStatus, $validStatuses)) {
            throw new Exception('สถานะไม่ถูกต้อง');
        }
        
        $stmt = $conn->prepare("
            UPDATE orders 
            SET status = ?
            WHERE order_id = ?
        ");
        
        $result = $stmt->execute([$newStatus, $orderId]);
        
        if (!$result) {
            throw new Exception('ไม่สามารถอัปเดตสถานะได้');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'อัปเดตสถานะเรียบร้อยแล้ว'
        ]);
        
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }
}

/**
 * อัปเดตรายละเอียดออเดอร์
 */
function updateOrderDetails($conn, $inputData = null) {
    try {
        // รับข้อมูลจาก parameter หรือ JSON input
        if ($inputData === null) {
            $inputData = json_decode(file_get_contents('php://input'), true);
        }
        
        $orderId = $inputData['order_id'] ?? '';
        $customerName = trim($inputData['customer_name'] ?? '');
        $tableNumber = trim($inputData['table_number'] ?? '');
        $orderType = $inputData['order_type'] ?? '';
        $notes = trim($inputData['notes'] ?? '');
        
        error_log("UpdateOrderDetails - OrderID: $orderId");
        
        if (empty($orderId) || !is_numeric($orderId)) {
            throw new Exception('รหัสออเดอร์ไม่ถูกต้อง');
        }
        
        // Validate inputs
        if (strlen($customerName) > 100) {
            throw new Exception('ชื่อลูกค้าต้องไม่เกิน 100 ตัวอักษร');
        }
        
        if (strlen($tableNumber) > 10) {
            throw new Exception('หมายเลขโต๊ะต้องไม่เกิน 10 ตัวอักษร');
        }
        
        if (strlen($notes) > 500) {
            throw new Exception('หมายเหตุต้องไม่เกิน 500 ตัวอักษร');
        }
        
        // Validate order type
        $validTypes = ['dine_in', 'takeaway', 'delivery'];
        if (!empty($orderType) && !in_array($orderType, $validTypes)) {
            throw new Exception('ประเภทออเดอร์ไม่ถูกต้อง');
        }
        
        $stmt = $conn->prepare("
            UPDATE orders 
            SET customer_name = ?, 
                table_number = ?, 
                order_type = ?, 
                notes = ?
            WHERE order_id = ?
        ");
        
        $result = $stmt->execute([
            $customerName ?: null,
            $tableNumber ?: null,
            $orderType ?: 'dine_in',
            $notes ?: null,
            $orderId
        ]);
        
        if (!$result) {
            throw new Exception('ไม่สามารถอัปเดตข้อมูลได้');
        }
        
        echo json_encode([
            'success' => true,
            'message' => 'อัปเดตข้อมูลเรียบร้อยแล้ว'
        ]);
        
    } catch (Exception $e) {
        throw new Exception($e->getMessage());
    }
}
?>