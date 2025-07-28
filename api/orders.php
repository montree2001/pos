<?php
/**
 * Orders API
 * Smart Order Management System
 * File: api/orders.php
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

// ตรวจสอบการเข้าสู่ระบบ
if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'ไม่ได้เข้าสู่ระบบ',
        'redirect' => SITE_URL . '/login.php'
    ]);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    $userRole = getCurrentUserRole();
    $userId = getCurrentUserId();
    
    switch ($action) {
        
        // ดึงรายการออเดอร์
        case 'get_orders':
            $page = max(1, intval($_GET['page'] ?? 1));
            $limit = max(1, min(100, intval($_GET['limit'] ?? 20)));
            $offset = ($page - 1) * $limit;
            $status = $_GET['status'] ?? '';
            $dateFrom = $_GET['date_from'] ?? '';
            $dateTo = $_GET['date_to'] ?? '';
            $customerId = intval($_GET['customer_id'] ?? 0);
            
            // สร้าง WHERE clause
            $whereConditions = ['1=1'];
            $params = [];
            
            // กรองตามสิทธิ์
            if ($userRole === 'customer') {
                $whereConditions[] = 'o.user_id = ?';
                $params[] = $userId;
            } elseif ($userRole === 'kitchen') {
                $whereConditions[] = "o.status IN ('confirmed', 'preparing', 'ready')";
            }
            
            // กรองตามสถานะ
            if ($status && $status !== 'all') {
                $whereConditions[] = 'o.status = ?';
                $params[] = $status;
            }
            
            // กรองตามวันที่
            if ($dateFrom) {
                $whereConditions[] = 'DATE(o.created_at) >= ?';
                $params[] = $dateFrom;
            }
            if ($dateTo) {
                $whereConditions[] = 'DATE(o.created_at) <= ?';
                $params[] = $dateTo;
            }
            
            // กรองตามลูกค้า (สำหรับ admin/staff)
            if ($customerId && in_array($userRole, ['admin', 'staff'])) {
                $whereConditions[] = 'o.user_id = ?';
                $params[] = $customerId;
            }
            
            $whereClause = implode(' AND ', $whereConditions);
            
            // นับจำนวนทั้งหมด
            $countStmt = $conn->prepare("
                SELECT COUNT(*) as total
                FROM orders o
                WHERE $whereClause
            ");
            $countStmt->execute($params);
            $totalOrders = $countStmt->fetchColumn();
            
            // ดึงข้อมูลออเดอร์
            $stmt = $conn->prepare("
                SELECT o.*, u.fullname as customer_name, u.phone, u.email,
                       COUNT(oi.item_id) as total_items,
                       SUM(CASE WHEN oi.status = 'completed' THEN 1 ELSE 0 END) as completed_items
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.user_id
                LEFT JOIN order_items oi ON o.order_id = oi.order_id
                WHERE $whereClause
                GROUP BY o.order_id
                ORDER BY o.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([...$params, $limit, $offset]);
            $orders = $stmt->fetchAll();
            
            // คำนวณ pagination
            $totalPages = ceil($totalOrders / $limit);
            
            echo json_encode([
                'success' => true,
                'orders' => $orders,
                'pagination' => [
                    'current_page' => $page,
                    'total_pages' => $totalPages,
                    'total_orders' => $totalOrders,
                    'per_page' => $limit
                ]
            ]);
            break;
            
        // ดึงรายละเอียดออเดอร์
        case 'get':
            $orderId = intval($_GET['id'] ?? 0);
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            // ตรวจสอบสิทธิ์
            $orderCheckSql = "SELECT user_id FROM orders WHERE order_id = ?";
            if ($userRole === 'customer') {
                $orderCheckSql .= " AND user_id = $userId";
            }
            
            $checkStmt = $conn->prepare($orderCheckSql);
            $checkStmt->execute([$orderId]);
            $orderOwner = $checkStmt->fetch();
            
            if (!$orderOwner) {
                throw new Exception('Order not found or access denied');
            }
            
            // ดึงข้อมูลออเดอร์
            $stmt = $conn->prepare("
                SELECT o.*, u.fullname as customer_name, u.phone, u.email,
                       pm.method_name as payment_method_name
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.user_id
                LEFT JOIN payment_methods pm ON o.payment_method = pm.method_code
                WHERE o.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception('Order not found');
            }
            
            // ดึงรายการสินค้า
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
            
            // ดึงประวัติการเปลี่ยนสถานะ
            $stmt = $conn->prepare("
                SELECT osh.*, u.fullname as changed_by_name
                FROM order_status_history osh
                LEFT JOIN users u ON osh.changed_by = u.user_id
                WHERE osh.order_id = ?
                ORDER BY osh.created_at DESC
            ");
            $stmt->execute([$orderId]);
            $order['status_history'] = $stmt->fetchAll();
            
            // ดึงข้อมูลการชำระเงิน
            $stmt = $conn->prepare("
                SELECT * FROM payments 
                WHERE order_id = ? 
                ORDER BY payment_date DESC
            ");
            $stmt->execute([$orderId]);
            $order['payments'] = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'order' => $order
            ]);
            break;
            
        // สร้างออเดอร์ใหม่
        case 'create':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $items = json_decode($_POST['items'] ?? '[]', true);
            $orderType = $_POST['order_type'] ?? 'dine_in';
            $tableNumber = $_POST['table_number'] ?? null;
            $notes = trim($_POST['notes'] ?? '');
            $customerId = $userRole === 'customer' ? $userId : intval($_POST['customer_id'] ?? 0);
            
            if (empty($items)) {
                throw new Exception('No items selected');
            }
            
            if (!$customerId) {
                throw new Exception('Customer ID is required');
            }
            
            // เริ่ม transaction
            $conn->beginTransaction();
            
            try {
                // คำนวณราคารวม
                $totalPrice = 0;
                $validatedItems = [];
                
                foreach ($items as $item) {
                    $productId = intval($item['product_id']);
                    $quantity = intval($item['quantity']);
                    $itemNotes = trim($item['notes'] ?? '');
                    
                    if ($quantity <= 0) continue;
                    
                    // ดึงข้อมูลสินค้า
                    $productStmt = $conn->prepare("
                        SELECT product_id, name, price, is_available 
                        FROM products 
                        WHERE product_id = ? AND is_available = 1
                    ");
                    $productStmt->execute([$productId]);
                    $product = $productStmt->fetch();
                    
                    if (!$product) {
                        throw new Exception("Product ID $productId not found or not available");
                    }
                    
                    $subtotal = $product['price'] * $quantity;
                    $totalPrice += $subtotal;
                    
                    $validatedItems[] = [
                        'product_id' => $productId,
                        'quantity' => $quantity,
                        'unit_price' => $product['price'],
                        'subtotal' => $subtotal,
                        'notes' => $itemNotes
                    ];
                }
                
                if (empty($validatedItems)) {
                    throw new Exception('No valid items found');
                }
                
                // สร้างหมายเลขคิว
                $queueNumber = generateQueueNumber();
                
                // สร้างออเดอร์
                $orderStmt = $conn->prepare("
                    INSERT INTO orders (
                        user_id, queue_number, total_price, status, order_type, 
                        table_number, notes, created_at
                    ) VALUES (?, ?, ?, 'pending', ?, ?, ?, NOW())
                ");
                $orderStmt->execute([
                    $customerId, $queueNumber, $totalPrice, 
                    $orderType, $tableNumber, $notes
                ]);
                
                $orderId = $conn->lastInsertId();
                
                // เพิ่มรายการสินค้า
                $itemStmt = $conn->prepare("
                    INSERT INTO order_items (
                        order_id, product_id, quantity, unit_price, subtotal, notes
                    ) VALUES (?, ?, ?, ?, ?, ?)
                ");
                
                foreach ($validatedItems as $item) {
                    $itemStmt->execute([
                        $orderId,
                        $item['product_id'],
                        $item['quantity'],
                        $item['unit_price'],
                        $item['subtotal'],
                        $item['notes']
                    ]);
                }
                
                // บันทึกประวัติสถานะ
                $historyStmt = $conn->prepare("
                    INSERT INTO order_status_history (order_id, status, changed_by, created_at)
                    VALUES (?, 'pending', ?, NOW())
                ");
                $historyStmt->execute([$orderId, $userId]);
                
                $conn->commit();
                
                // ส่งการแจ้งเตือน (ถ้ามี)
                if (defined('LINE_NOTIFY_ENABLED') && LINE_NOTIFY_ENABLED) {
                    // TODO: ส่ง LINE notification
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'สร้างออเดอร์สำเร็จ',
                    'order_id' => $orderId,
                    'queue_number' => $queueNumber,
                    'total_price' => $totalPrice
                ]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
            
        // อัปเดตสถานะออเดอร์
        case 'update_status':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $orderId = intval($_POST['order_id'] ?? 0);
            $newStatus = $_POST['status'] ?? '';
            $validStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
            
            if (!$orderId || !in_array($newStatus, $validStatuses)) {
                throw new Exception('Invalid parameters');
            }
            
            // ตรวจสอบสิทธิ์
            if (!in_array($userRole, ['admin', 'staff', 'kitchen'])) {
                throw new Exception('Access denied');
            }
            
            // ตรวจสอบว่าออเดอร์มีอยู่
            $orderStmt = $conn->prepare("SELECT status FROM orders WHERE order_id = ?");
            $orderStmt->execute([$orderId]);
            $currentOrder = $orderStmt->fetch();
            
            if (!$currentOrder) {
                throw new Exception('Order not found');
            }
            
            // ตรวจสอบการเปลี่ยนสถานะที่ถูกต้อง
            $validTransitions = [
                'pending' => ['confirmed', 'cancelled'],
                'confirmed' => ['preparing', 'cancelled'],
                'preparing' => ['ready', 'cancelled'],
                'ready' => ['completed'],
                'completed' => [],
                'cancelled' => []
            ];
            
            if (!in_array($newStatus, $validTransitions[$currentOrder['status']])) {
                throw new Exception('Invalid status transition');
            }
            
            // เริ่ม transaction
            $conn->beginTransaction();
            
            try {
                // อัปเดตสถานะออเดอร์
                $updateStmt = $conn->prepare("
                    UPDATE orders 
                    SET status = ?, updated_at = NOW() 
                    WHERE order_id = ?
                ");
                $updateStmt->execute([$newStatus, $orderId]);
                
                // บันทึกประวัติ
                $historyStmt = $conn->prepare("
                    INSERT INTO order_status_history (order_id, status, changed_by, created_at)
                    VALUES (?, ?, ?, NOW())
                ");
                $historyStmt->execute([$orderId, $newStatus, $userId]);
                
                // อัปเดตสถานะรายการอาหารถ้าจำเป็น
                $itemStatusMap = [
                    'confirmed' => 'pending',
                    'preparing' => 'preparing',
                    'ready' => 'ready',
                    'completed' => 'completed',
                    'cancelled' => 'cancelled'
                ];
                
                if (isset($itemStatusMap[$newStatus])) {
                    $itemUpdateStmt = $conn->prepare("
                        UPDATE order_items 
                        SET status = ? 
                        WHERE order_id = ?
                    ");
                    $itemUpdateStmt->execute([$itemStatusMap[$newStatus], $orderId]);
                }
                
                $conn->commit();
                
                // ส่งการแจ้งเตือน
                if (in_array($newStatus, ['confirmed', 'ready', 'completed'])) {
                    // TODO: ส่ง LINE notification
                }
                
                echo json_encode([
                    'success' => true,
                    'message' => 'อัปเดตสถานะสำเร็จ',
                    'new_status' => $newStatus
                ]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
            
        // ยกเลิกออเดอร์
        case 'cancel':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $orderId = intval($_POST['order_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            
            if (!$orderId) {
                throw new Exception('Order ID is required');
            }
            
            // ตรวจสอบสิทธิ์
            $orderCheckSql = "SELECT user_id, status FROM orders WHERE order_id = ?";
            if ($userRole === 'customer') {
                $orderCheckSql .= " AND user_id = $userId";
            }
            
            $checkStmt = $conn->prepare($orderCheckSql);
            $checkStmt->execute([$orderId]);
            $order = $checkStmt->fetch();
            
            if (!$order) {
                throw new Exception('Order not found or access denied');
            }
            
            // ตรวจสอบว่าสามารถยกเลิกได้
            if (!in_array($order['status'], ['pending', 'confirmed'])) {
                throw new Exception('Cannot cancel order in current status');
            }
            
            // เริ่ม transaction
            $conn->beginTransaction();
            
            try {
                // อัปเดตสถานะ
                $updateStmt = $conn->prepare("
                    UPDATE orders 
                    SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), '\nยกเลิก: ', ?), updated_at = NOW()
                    WHERE order_id = ?
                ");
                $updateStmt->execute([$reason, $orderId]);
                
                // อัปเดตสถานะรายการ
                $itemUpdateStmt = $conn->prepare("
                    UPDATE order_items 
                    SET status = 'cancelled' 
                    WHERE order_id = ?
                ");
                $itemUpdateStmt->execute([$orderId]);
                
                // บันทึกประวัติ
                $historyStmt = $conn->prepare("
                    INSERT INTO order_status_history (order_id, status, changed_by, created_at)
                    VALUES (?, 'cancelled', ?, NOW())
                ");
                $historyStmt->execute([$orderId, $userId]);
                
                $conn->commit();
                
                echo json_encode([
                    'success' => true,
                    'message' => 'ยกเลิกออเดอร์สำเร็จ'
                ]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
            
        // ดึงจำนวนออเดอร์ที่รอดำเนินการ (สำหรับ admin)
        case 'pending_count':
            if (!in_array($userRole, ['admin', 'staff', 'kitchen'])) {
                throw new Exception('Access denied');
            }
            
            $stmt = $conn->prepare("
                SELECT COUNT(*) as count
                FROM orders 
                WHERE status IN ('pending', 'confirmed') 
                AND payment_status = 'unpaid'
            ");
            $stmt->execute();
            $result = $stmt->fetch();
            
            echo json_encode([
                'success' => true,
                'count' => intval($result['count'])
            ]);
            break;
            
        // ดึงสถิติออเดอร์
        case 'stats':
            if (!in_array($userRole, ['admin', 'staff', 'kitchen'])) {
                throw new Exception('Access denied');
            }
            
            $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
            $dateTo = $_GET['date_to'] ?? date('Y-m-d');
            
            // สถิติพื้นฐาน
            $stmt = $conn->prepare("
                SELECT 
                    COUNT(*) as total_orders,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled_orders,
                    SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END) as total_revenue,
                    AVG(CASE WHEN status = 'completed' THEN total_price ELSE NULL END) as avg_order_value
                FROM orders 
                WHERE DATE(created_at) BETWEEN ? AND ?
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $basicStats = $stmt->fetch();
            
            // สถิติตามสถานะ
            $stmt = $conn->prepare("
                SELECT status, COUNT(*) as count
                FROM orders 
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY status
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $statusStats = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
            
            // สถิติรายชั่วโมง
            $stmt = $conn->prepare("
                SELECT 
                    HOUR(created_at) as hour,
                    COUNT(*) as order_count,
                    SUM(CASE WHEN status = 'completed' THEN total_price ELSE 0 END) as revenue
                FROM orders 
                WHERE DATE(created_at) BETWEEN ? AND ?
                GROUP BY HOUR(created_at)
                ORDER BY hour
            ");
            $stmt->execute([$dateFrom, $dateTo]);
            $hourlyStats = $stmt->fetchAll();
            
            echo json_encode([
                'success' => true,
                'stats' => [
                    'basic' => $basicStats,
                    'by_status' => $statusStats,
                    'hourly' => $hourlyStats
                ]
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
    writeLog("Orders API Error: " . $e->getMessage() . " | Action: $action | User: $userId");
}

/**
 * สร้างหมายเลขคิว
 */
function generateQueueNumber() {
    $prefix = 'Q';
    $date = date('ymd');
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // หาหมายเลขล่าสุดของวันนี้
        $stmt = $conn->prepare("
            SELECT queue_number 
            FROM orders 
            WHERE DATE(created_at) = CURDATE() 
            AND queue_number LIKE ? 
            ORDER BY queue_number DESC 
            LIMIT 1
        ");
        $stmt->execute([$prefix . $date . '%']);
        $lastQueue = $stmt->fetchColumn();
        
        if ($lastQueue) {
            // ดึงเลขท้าย 3 หลัก
            $lastNumber = intval(substr($lastQueue, -3));
            $newNumber = $lastNumber + 1;
        } else {
            $newNumber = 1;
        }
        
        return $prefix . $date . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
        
    } catch (Exception $e) {
        // fallback กรณี error
        return $prefix . $date . str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
    }
}
?>