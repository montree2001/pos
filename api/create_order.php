<?php
/**
 * API สร้างออเดอร์จากตะกร้าสินค้า
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);

// Error handling
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    require_once '../../config/config.php';
    require_once '../../config/database.php';
    require_once '../../config/session.php';
    require_once '../../includes/functions.php';
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'ไม่สามารถโหลดไฟล์ระบบได้: ' . $e->getMessage(),
        'error' => 'SYSTEM_LOAD_ERROR'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// Set headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// Handle CORS OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// Start session
try {
    SessionManager::start();
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'message' => 'ไม่สามารถเริ่มต้น session ได้: ' . $e->getMessage(),
        'error' => 'SESSION_ERROR'
    ], 500);
}

try {
    $action = $_POST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method !== 'POST') {
        throw new Exception('Method not allowed');
    }
    
    if (empty($action)) {
        throw new Exception('ไม่ได้ระบุการดำเนินการ');
    }
    
    switch ($action) {
        case 'create_from_cart':
            createOrderFromCart();
            break;
            
        default:
            throw new Exception('การดำเนินการไม่ถูกต้อง: ' . $action);
    }
    
} catch (Exception $e) {
    if (function_exists('writeLog')) {
        writeLog("Create Order API Error: " . $e->getMessage(), 'ERROR');
    }
    
    sendJsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ], 400);
}

/**
 * สร้างออเดอร์จากตะกร้าสินค้า
 */
function createOrderFromCart() {
    // ตรวจสอบตะกร้าสินค้า
    $cartItems = getCartItems();
    if (empty($cartItems)) {
        throw new Exception('ตะกร้าสินค้าว่างเปล่า');
    }
    
    // รับข้อมูลลูกค้า
    $customerInfo = $_POST['customer_info'] ?? [];
    $customerName = trim($customerInfo['name'] ?? 'ลูกค้าเดินหน้า');
    $customerPhone = trim($customerInfo['phone'] ?? '');
    $orderNotes = trim($customerInfo['notes'] ?? '');
    
    $db = new Database();
    $conn = $db->getConnection();
    
    try {
        $conn->beginTransaction();
        
        // คำนวณยอดรวม
        $subtotal = 0;
        $orderItems = [];
        
        foreach ($cartItems as $item) {
            // ตรวจสอบสินค้า
            $stmt = $conn->prepare("
                SELECT product_id, name, price, is_available, preparation_time
                FROM products 
                WHERE product_id = ?
            ");
            $stmt->execute([$item['product_id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception('ไม่พบสินค้า ID: ' . $item['product_id']);
            }
            
            if (!$product['is_available']) {
                throw new Exception('สินค้า "' . $product['name'] . '" หมดแล้ว');
            }
            
            $itemPrice = $product['price'];
            $optionText = '';
            
            // ตรวจสอบและคำนวณตัวเลือก
            if (!empty($item['options'])) {
                $optionNames = [];
                foreach ($item['options'] as $optionId) {
                    $stmt = $conn->prepare("
                        SELECT name, price_adjustment 
                        FROM product_options 
                        WHERE option_id = ? AND product_id = ?
                    ");
                    $stmt->execute([$optionId, $item['product_id']]);
                    $option = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($option) {
                        $optionNames[] = $option['name'];
                        $itemPrice += $option['price_adjustment'];
                    }
                }
                $optionText = implode(', ', $optionNames);
            }
            
            $lineTotal = $itemPrice * $item['quantity'];
            $subtotal += $lineTotal;
            
            $orderItems[] = [
                'product_id' => $item['product_id'],
                'product_name' => $product['name'],
                'quantity' => $item['quantity'],
                'unit_price' => $itemPrice,
                'line_total' => $lineTotal,
                'options' => $optionText,
                'preparation_time' => $product['preparation_time']
            ];
        }
        
        // คำนวณภาษีและยอดรวม
        $taxRate = 0.07; // VAT 7%
        $taxAmount = $subtotal * $taxRate;
        $totalAmount = $subtotal + $taxAmount;
        
        // สร้างเลขออเดอร์
        $orderNumber = generateOrderNumber();
        
        // คำนวณเวลาเตรียม (รวมเวลาเตรียมทั้งหมด + buffer)
        $totalPrepTime = 0;
        foreach ($orderItems as $item) {
            $totalPrepTime += ($item['preparation_time'] * $item['quantity']);
        }
        $estimatedPrepTime = max(15, $totalPrepTime + 5); // ขั้นต่ำ 15 นาที
        
        // บันทึกออเดอร์
        $stmt = $conn->prepare("
            INSERT INTO orders (
                order_number, customer_name, customer_phone, 
                subtotal, tax_amount, total_amount,
                order_source, order_status, payment_status,
                notes, estimated_prep_time, created_at
            ) VALUES (
                ?, ?, ?, ?, ?, ?, 'online', 'pending', 'pending', ?, ?, NOW()
            )
        ");
        
        $stmt->execute([
            $orderNumber,
            $customerName,
            $customerPhone,
            $subtotal,
            $taxAmount,
            $totalAmount,
            $orderNotes,
            $estimatedPrepTime
        ]);
        
        $orderId = $conn->lastInsertId();
        
        // บันทึกรายการสินค้า
        $stmt = $conn->prepare("
            INSERT INTO order_items (
                order_id, product_id, product_name, quantity, 
                unit_price, total_price, options, notes
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        foreach ($orderItems as $item) {
            $stmt->execute([
                $orderId,
                $item['product_id'],
                $item['product_name'],
                $item['quantity'],
                $item['unit_price'],
                $item['line_total'],
                $item['options'],
                ''
            ]);
        }
        
        // ล้างตะกร้าสินค้า
        clearCart();
        
        $conn->commit();
        
        // บันทึก log
        writeLog("Order created successfully: Order ID {$orderId}, Number {$orderNumber}, Amount {$totalAmount}");
        
        // ส่งการตอบกลับ
        sendJsonResponse([
            'success' => true,
            'message' => 'สร้างออเดอร์สำเร็จ',
            'order_id' => $orderId,
            'order_number' => $orderNumber,
            'total_amount' => $totalAmount,
            'subtotal' => $subtotal,
            'tax_amount' => $taxAmount,
            'estimated_prep_time' => $estimatedPrepTime,
            'item_count' => count($orderItems),
            'redirect_url' => "checkout.php?order_id={$orderId}&amount={$totalAmount}"
        ]);
        
    } catch (Exception $e) {
        $conn->rollBack();
        throw $e;
    }
}

/**
 * สร้างเลขออเดอร์
 */
function generateOrderNumber() {
    $prefix = 'ORD';
    $date = date('Ymd');
    $random = str_pad(rand(1, 9999), 4, '0', STR_PAD_LEFT);
    
    return $prefix . $date . $random;
}

/**
 * ส่งการตอบกลับ JSON
 */
function sendJsonResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit();
}
?>