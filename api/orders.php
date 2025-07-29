<?php
/**
 * API จัดการออเดอร์สำหรับลูกค้า
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

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

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($action) {
        
        // สร้างออเดอร์จากตะกร้า
        case 'create_from_cart':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $cartItems = getCartItems();
            if (empty($cartItems)) {
                throw new Exception('ตะกร้าว่างเปล่า');
            }
            
            // รับข้อมูลเพิ่มเติม
            $orderType = $_POST['order_type'] ?? 'takeaway'; // dine_in, takeaway, delivery
            $tableNumber = $_POST['table_number'] ?? null;
            $notes = trim($_POST['notes'] ?? '');
            $customerInfo = $_POST['customer_info'] ?? [];
            
            $conn->beginTransaction();
            
            try {
                // คำนวณราคารวม
                $totalPrice = 0;
                $estimatedTime = 0;
                $orderItems = [];
                
                foreach ($cartItems as $item) {
                    $stmt = $conn->prepare("
                        SELECT product_id, name, price, preparation_time, is_available
                        FROM products 
                        WHERE product_id = ?
                    ");
                    $stmt->execute([$item['product_id']]);
                    $product = $stmt->fetch();
                    
                    if (!$product || !$product['is_available']) {
                        throw new Exception("สินค้า {$product['name']} ไม่พร้อมจำหน่าย");
                    }
                    
                    $itemPrice = $product['price'];
                    $itemTotal = $itemPrice * $item['quantity'];
                    
                    // คำนวณราคาตัวเลือก
                    $optionsData = [];
                    if (!empty($item['options'])) {
                        foreach ($item['options'] as $optionId) {
                            $stmt = $conn->prepare("
                                SELECT option_id, name, price_adjustment
                                FROM product_options 
                                WHERE option_id = ? AND product_id = ?
                            ");
                            $stmt->execute([$optionId, $item['product_id']]);
                            $option = $stmt->fetch();
                            
                            if ($option) {
                                $itemTotal += ($option['price_adjustment'] * $item['quantity']);
                                $optionsData[] = $option;
                            }
                        }
                    }
                    
                    $orderItems[] = [
                        'product_id' => $product['product_id'],
                        'name' => $product['name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $itemPrice,
                        'subtotal' => $itemTotal,
                        'options' => $optionsData
                    ];
                    
                    $totalPrice += $itemTotal;
                    $estimatedTime = max($estimatedTime, $product['preparation_time']);
                }
                
                // เพิ่มเวลาตามจำนวนรายการ
                $totalItems = array_sum(array_column($orderItems, 'quantity'));
                if ($totalItems > 3) {
                    $estimatedTime += ceil(($totalItems - 3) / 2) * 2;
                }
                $estimatedTime = max($estimatedTime, 10); // อย่างน้อย 10 นาที
                
                // สร้างหมายเลขคิว
                $queueNumber = generateQueueNumber();
                
                // สร้าง User ID สำหรับลูกค้าทั่วไป (ถ้าไม่ได้ล็อกอิน)
                $userId = null;
                if (isLoggedIn()) {
                    $userId = getCurrentUserId();
                } elseif (!empty($customerInfo)) {
                    // สร้างลูกค้าใหม่หรือใช้ข้อมูลที่มี
                    $userId = createGuestCustomer($customerInfo);
                }
                
                // บันทึกออเดอร์
                $stmt = $conn->prepare("
                    INSERT INTO orders (
                        user_id, queue_number, total_price, status, order_type, 
                        payment_status, table_number, notes, 
                        estimated_ready_time, created_at
                    ) VALUES (?, ?, ?, 'pending', ?, 'unpaid', ?, ?, 
                             DATE_ADD(NOW(), INTERVAL ? MINUTE), NOW())
                ");
                
                $stmt->execute([
                    $userId,
                    $queueNumber,
                    $totalPrice,
                    $orderType,
                    $tableNumber,
                    $notes,
                    $estimatedTime
                ]);
                
                $orderId = $conn->lastInsertId();
                
                // บันทึกรายการสินค้า
                foreach ($orderItems as $orderItem) {
                    $stmt = $conn->prepare("
                        INSERT INTO order_items (
                            order_id, product_id, quantity, unit_price, subtotal
                        ) VALUES (?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $orderId,
                        $orderItem['product_id'],
                        $orderItem['quantity'],
                        $orderItem['unit_price'],
                        $orderItem['subtotal']
                    ]);
                    
                    $itemId = $conn->lastInsertId();
                    
                    // บันทึกตัวเลือกสินค้า
                    foreach ($orderItem['options'] as $option) {
                        $stmt = $conn->prepare("
                            INSERT INTO order_item_options (item_id, option_id)
                            VALUES (?, ?)
                        ");
                        $stmt->execute([$itemId, $option['option_id']]);
                    }
                }
                
                // บันทึกประวัติสถานะ
                $stmt = $conn->prepare("
                    INSERT INTO order_status_history (order_id, status, created_at)
                    VALUES (?, 'pending', NOW())
                ");
                $stmt->execute([$orderId]);
                
                $conn->commit();
                
                // ล้างตะกร้า
                clearCart();
                
                // ส่งการแจ้งเตือน (ถ้ามี LINE User ID)
                if ($userId) {
                    $stmt = $conn->prepare("SELECT line_user_id FROM users WHERE user_id = ?");
                    $stmt->execute([$userId]);
                    $user = $stmt->fetch();
                    
                    if ($user && !empty($user['line_user_id'])) {
                        // TODO: ส่ง LINE notification
                    }
                }
                
                sendJsonResponse([
                    'success' => true,
                    'message' => 'สร้างออเดอร์สำเร็จ',
                    'order_id' => $orderId,
                    'queue_number' => $queueNumber,
                    'total_amount' => $totalPrice,
                    'estimated_time' => $estimatedTime
                ]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
            
        // ตรวจสอบสถานะออเดอร์
        case 'check_status':
            $orderId = intval($_GET['order_id'] ?? 0);
            $queueNumber = $_GET['queue_number'] ?? '';
            
            if (!$orderId && !$queueNumber) {
                throw new Exception('กรุณาระบุหมายเลขออเดอร์หรือหมายเลขคิว');
            }
            
            $whereClause = $orderId ? "order_id = ?" : "queue_number = ?";
            $param = $orderId ?: $queueNumber;
            
            $stmt = $conn->prepare("
                SELECT o.*, u.fullname as customer_name,
                       COUNT(oi.item_id) as total_items,
                       SUM(CASE WHEN oi.status = 'completed' THEN 1 ELSE 0 END) as completed_items,
                       TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) as minutes_passed
                FROM orders o
                LEFT JOIN users u ON o.user_id = u.user_id
                LEFT JOIN order_items oi ON o.order_id = oi.order_id
                WHERE $whereClause
                GROUP BY o.order_id
            ");
            $stmt->execute([$param]);
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception('ไม่พบออเดอร์');
            }
            
            // คำนวณเปอร์เซ็นต์ความคืบหน้า
            $progress = 0;
            if ($order['total_items'] > 0) {
                $progress = ($order['completed_items'] / $order['total_items']) * 100;
            }
            
            // ประมาณเวลาที่เหลือ
            $remainingTime = 0;
            if ($order['status'] !== 'completed' && $order['estimated_ready_time']) {
                $estimatedTime = new DateTime($order['estimated_ready_time']);
                $now = new DateTime();
                $diff = $estimatedTime->diff($now);
                $remainingTime = $diff->invert ? ($diff->h * 60 + $diff->i) : 0;
            }
            
            // ดึงรายการสินค้า
            $stmt = $conn->prepare("
                SELECT oi.*, p.name as product_name
                FROM order_items oi
                JOIN products p ON oi.product_id = p.product_id
                WHERE oi.order_id = ?
                ORDER BY oi.item_id ASC
            ");
            $stmt->execute([$order['order_id']]);
            $items = $stmt->fetchAll();
            
            sendJsonResponse([
                'success' => true,
                'order' => [
                    'order_id' => $order['order_id'],
                    'queue_number' => $order['queue_number'],
                    'status' => $order['status'],
                    'status_text' => getOrderStatusText($order['status']),
                    'payment_status' => $order['payment_status'],
                    'total_price' => $order['total_price'],
                    'order_type' => $order['order_type'],
                    'table_number' => $order['table_number'],
                    'notes' => $order['notes'],
                    'created_at' => $order['created_at'],
                    'estimated_ready_time' => $order['estimated_ready_time'],
                    'customer_name' => $order['customer_name'],
                    'progress' => $progress,
                    'remaining_time' => $remainingTime,
                    'minutes_passed' => $order['minutes_passed'],
                    'items' => $items
                ]
            ]);
            break;
            
        // ดึงประวัติออเดอร์ของลูกค้า
        case 'history':
            if (!isLoggedIn()) {
                throw new Exception('กรุณาเข้าสู่ระบบ');
            }
            
            $userId = getCurrentUserId();
            $limit = intval($_GET['limit'] ?? 10);
            $offset = intval($_GET['offset'] ?? 0);
            
            $stmt = $conn->prepare("
                SELECT o.*, COUNT(oi.item_id) as total_items
                FROM orders o
                LEFT JOIN order_items oi ON o.order_id = oi.order_id
                WHERE o.user_id = ?
                GROUP BY o.order_id
                ORDER BY o.created_at DESC
                LIMIT ? OFFSET ?
            ");
            $stmt->execute([$userId, $limit, $offset]);
            $orders = $stmt->fetchAll();
            
            sendJsonResponse([
                'success' => true,
                'orders' => $orders
            ]);
            break;
            
        // ยกเลิกออเดอร์
        case 'cancel':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $orderId = intval($_POST['order_id'] ?? 0);
            $reason = trim($_POST['reason'] ?? '');
            
            if (!$orderId) {
                throw new Exception('กรุณาระบุหมายเลขออเดอร์');
            }
            
            $stmt = $conn->prepare("
                SELECT order_id, status, payment_status, user_id 
                FROM orders 
                WHERE order_id = ?
            ");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch();
            
            if (!$order) {
                throw new Exception('ไม่พบออเดอร์');
            }
            
            // ตรวจสอบสิทธิ์
            if (isLoggedIn() && $order['user_id'] != getCurrentUserId()) {
                throw new Exception('ไม่มีสิทธิ์ยกเลิกออเดอร์นี้');
            }
            
            // ตรวจสอบสถานะที่สามารถยกเลิกได้
            if (!in_array($order['status'], ['pending', 'confirmed'])) {
                throw new Exception('ไม่สามารถยกเลิกออเดอร์ได้ในสถานะนี้');
            }
            
            $conn->beginTransaction();
            
            try {
                // อัปเดตสถานะ
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET status = 'cancelled', notes = CONCAT(COALESCE(notes, ''), '\nยกเลิกโดยลูกค้า: ', ?)
                    WHERE order_id = ?
                ");
                $stmt->execute([$reason, $orderId]);
                
                // บันทึกประวัติ
                $stmt = $conn->prepare("
                    INSERT INTO order_status_history (order_id, status, created_at)
                    VALUES (?, 'cancelled', NOW())
                ");
                $stmt->execute([$orderId]);
                
                $conn->commit();
                
                sendJsonResponse([
                    'success' => true,
                    'message' => 'ยกเลิกออเดอร์แล้ว'
                ]);
                
            } catch (Exception $e) {
                $conn->rollBack();
                throw $e;
            }
            break;
            
        default:
            throw new Exception('Action not found');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    sendJsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
    
    writeLog("Orders API Error: " . $e->getMessage() . " | Action: " . ($action ?? 'unknown'));
}

/**
 * สร้างหมายเลขคิว
 */
function generateQueueNumber() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // ดึงหมายเลขคิวล่าสุดของวันนี้
        $stmt = $conn->prepare("
            SELECT queue_number 
            FROM orders 
            WHERE DATE(created_at) = CURDATE() 
            ORDER BY order_id DESC 
            LIMIT 1
        ");
        $stmt->execute();
        $lastQueue = $stmt->fetchColumn();
        
        $prefix = 'Q';
        $date = date('ymd');
        $sequence = 1;
        
        if ($lastQueue && strpos($lastQueue, $prefix . $date) === 0) {
            $lastSequence = intval(substr($lastQueue, -3));
            $sequence = $lastSequence + 1;
        }
        
        return $prefix . $date . str_pad($sequence, 3, '0', STR_PAD_LEFT);
        
    } catch (Exception $e) {
        writeLog("Queue number generation error: " . $e->getMessage());
        return 'Q' . date('ymdHis');
    }
}

/**
 * สร้างลูกค้าแขก
 */
function createGuestCustomer($customerInfo) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $name = $customerInfo['name'] ?? 'ลูกค้าทั่วไป';
        $phone = $customerInfo['phone'] ?? '';
        $email = $customerInfo['email'] ?? '';
        
        // ตรวจสอบว่ามีลูกค้าคนนี้แล้วหรือไม่ (จากเบอร์โทร)
        if (!empty($phone)) {
            $stmt = $conn->prepare("
                SELECT user_id 
                FROM users 
                WHERE phone = ? AND role = 'customer'
            ");
            $stmt->execute([$phone]);
            $existingUser = $stmt->fetchColumn();
            
            if ($existingUser) {
                return $existingUser;
            }
        }
        
        // สร้างลูกค้าใหม่
        $username = 'guest_' . time() . '_' . mt_rand(1000, 9999);
        $password = password_hash(uniqid(), PASSWORD_DEFAULT);
        
        $stmt = $conn->prepare("
            INSERT INTO users (username, password, fullname, phone, email, role, status)
            VALUES (?, ?, ?, ?, ?, 'customer', 'active')
        ");
        $stmt->execute([$username, $password, $name, $phone, $email]);
        
        return $conn->lastInsertId();
        
    } catch (Exception $e) {
        writeLog("Guest customer creation error: " . $e->getMessage());
        return null;
    }
}
?>