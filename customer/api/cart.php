<?php
/**
 * API จัดการตะกร้าสินค้า - แก้ไขปัญหา 500 Error
 * Smart Order Management System
 */

// กำหนดให้ระบบรู้ว่าเป็นการเริ่มต้นที่ถูกต้อง
define('SYSTEM_INIT', true);

// จัดการ Error ให้แสดงรายละเอียด (เฉพาะขณะพัฒนา)
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // โหลดไฟล์จำเป็น
    require_once '../../config/config.php';
    require_once '../../config/database.php';
    require_once '../../config/session.php'; 
    require_once '../../includes/functions.php';
} catch (Exception $e) {
    // ถ้าโหลดไฟล์ไม่ได้
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'success' => false,
        'message' => 'ไม่สามารถโหลดไฟล์ระบบได้: ' . $e->getMessage(),
        'error' => 'SYSTEM_LOAD_ERROR'
    ], JSON_UNESCAPED_UNICODE);
    exit();
}

// ตั้งค่า Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');

// จัดการ CORS OPTIONS request
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// เริ่มต้น session
try {
    SessionManager::start();
} catch (Exception $e) {
    sendJsonResponse([
        'success' => false,
        'message' => 'ไม่สามารถเริ่มต้น session ได้: ' . $e->getMessage(),
        'error' => 'SESSION_ERROR'
    ], 500);
}

// ดักจับ errors ทั้งหมด
try {
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    // ตรวจสอบ action ที่ส่งมา
    if (empty($action)) {
        throw new Exception('ไม่ได้ระบุการดำเนินการ');
    }
    
    switch ($action) {
        
        // เพิ่มสินค้าลงตะกร้า
        case 'add':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $productId = intval($_POST['product_id'] ?? 0);
            $quantity = intval($_POST['quantity'] ?? 1);
            $options = $_POST['options'] ?? [];
            
            if (!$productId || $quantity < 1) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            
            // ตรวจสอบสินค้า
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                SELECT product_id, name, price, is_available, image
                FROM products 
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$product) {
                throw new Exception('ไม่พบสินค้าที่เลือก');
            }
            
            if (!$product['is_available']) {
                throw new Exception('สินค้าหมดแล้ว');
            }
            
            // ตรวจสอบตัวเลือก (ถ้ามี)
            if (!empty($options)) {
                if (!is_array($options)) {
                    $options = [$options];
                }
                
                foreach ($options as $optionId) {
                    $stmt = $conn->prepare("
                        SELECT option_id 
                        FROM product_options 
                        WHERE option_id = ? AND product_id = ?
                    ");
                    $stmt->execute([$optionId, $productId]);
                    if (!$stmt->fetch()) {
                        throw new Exception('ตัวเลือกไม่ถูกต้อง');
                    }
                }
            }
            
            // เพิ่มลงตะกร้า
            addToCart($productId, $quantity, $options);
            
            sendJsonResponse([
                'success' => true,
                'message' => 'เพิ่มสินค้าลงตะกร้าแล้ว',
                'product_name' => $product['name'],
                'cart_count' => getCartItemCount()
            ]);
            break;
            
        // ดึงจำนวนสินค้าในตะกร้า
        case 'count':
            $count = getCartItemCount();
            sendJsonResponse([
                'success' => true,
                'count' => $count
            ]);
            break;
            
        // ดึงรายการสินค้าในตะกร้า
        case 'get':
            $cartItems = getCartItems();
            $cartDetails = [];
            $total = 0;
            
            if (!empty($cartItems)) {
                $db = new Database();
                $conn = $db->getConnection();
                
                foreach ($cartItems as $itemKey => $item) {
                    $stmt = $conn->prepare("
                        SELECT p.*, c.name as category_name
                        FROM products p
                        LEFT JOIN categories c ON p.category_id = c.category_id
                        WHERE p.product_id = ? AND p.is_available = 1
                    ");
                    $stmt->execute([$item['product_id']]);
                    $product = $stmt->fetch(PDO::FETCH_ASSOC);
                    
                    if ($product) {
                        $subtotal = $product['price'] * $item['quantity'];
                        
                        // คำนวณราคาตัวเลือก
                        $optionsTotal = 0;
                        $optionDetails = [];
                        if (!empty($item['options'])) {
                            foreach ($item['options'] as $optionId) {
                                $stmt = $conn->prepare("
                                    SELECT name, price_adjustment 
                                    FROM product_options 
                                    WHERE option_id = ?
                                ");
                                $stmt->execute([$optionId]);
                                $option = $stmt->fetch(PDO::FETCH_ASSOC);
                                if ($option) {
                                    $optionsTotal += $option['price_adjustment'];
                                    $optionDetails[] = $option;
                                }
                            }
                            $subtotal += ($optionsTotal * $item['quantity']);
                        }
                        
                        $cartDetails[] = [
                            'key' => $itemKey,
                            'product_id' => $product['product_id'],
                            'name' => $product['name'],
                            'price' => $product['price'],
                            'quantity' => $item['quantity'],
                            'subtotal' => $subtotal,
                            'image' => $product['image'],
                            'category' => $product['category_name'],
                            'options' => $optionDetails
                        ];
                        
                        $total += $subtotal;
                    }
                }
            }
            
            sendJsonResponse([
                'success' => true,
                'items' => $cartDetails,
                'total' => $total,
                'count' => count($cartDetails)
            ]);
            break;
            
        // อัปเดตจำนวนสินค้า
        case 'update':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $itemKey = $_POST['item_key'] ?? '';
            $quantity = intval($_POST['quantity'] ?? 1);
            
            if (empty($itemKey) || $quantity < 1) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            
            // อัปเดตจำนวน
            $cartItems = getCartItems();
            if (!isset($cartItems[$itemKey])) {
                throw new Exception('ไม่พบสินค้าในตะกร้า');
            }
            
            $cartItems[$itemKey]['quantity'] = $quantity;
            SessionManager::set('cart_items', $cartItems);
            
            // คำนวณราคาใหม่
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("SELECT price FROM products WHERE product_id = ?");
            $stmt->execute([$cartItems[$itemKey]['product_id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $itemSubtotal = $product['price'] * $quantity;
            
            // คำนวณราคารวม
            $cartSummary = calculateCartSummary();
            
            sendJsonResponse([
                'success' => true,
                'message' => 'อัปเดตจำนวนแล้ว',
                'item_subtotal' => $itemSubtotal,
                'cart_summary' => $cartSummary
            ]);
            break;
            
        // ลบสินค้าออกจากตะกร้า
        case 'remove':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            $itemKey = $_POST['item_key'] ?? '';
            
            if (empty($itemKey)) {
                throw new Exception('ข้อมูลไม่ถูกต้อง');
            }
            
            // ลบสินค้า
            $cartItems = getCartItems();
            if (!isset($cartItems[$itemKey])) {
                throw new Exception('ไม่พบสินค้าในตะกร้า');
            }
            
            unset($cartItems[$itemKey]);
            SessionManager::set('cart_items', $cartItems);
            
            $cartEmpty = empty($cartItems);
            $cartSummary = $cartEmpty ? null : calculateCartSummary();
            
            sendJsonResponse([
                'success' => true,
                'message' => 'ลบสินค้าแล้ว',
                'cart_empty' => $cartEmpty,
                'cart_summary' => $cartSummary
            ]);
            break;
            
        // ล้างตะกร้าทั้งหมด
        case 'clear':
            if ($method !== 'POST') {
                throw new Exception('Method not allowed');
            }
            
            clearCart();
            
            sendJsonResponse([
                'success' => true,
                'message' => 'ล้างตะกร้าแล้ว'
            ]);
            break;
            
        default:
            throw new Exception('การดำเนินการไม่ถูกต้อง: ' . $action);
    }
    
} catch (Exception $e) {
    // บันทึก error
    if (function_exists('writeLog')) {
        writeLog("Cart API Error: " . $e->getMessage() . " | Action: " . ($action ?? 'unknown') . " | Method: " . $method, 'ERROR');
    }
    
    sendJsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage(),
        'debug' => [
            'action' => $action ?? 'not_set',
            'method' => $method,
            'post_data' => $_POST,
            'get_data' => $_GET
        ]
    ], 400);
}

/**
 * คำนวณสรุปราคาตะกร้า
 */
function calculateCartSummary() {
    $cartItems = getCartItems();
    $subtotal = 0;
    $itemCount = 0;
    $totalQuantity = 0;
    
    if (!empty($cartItems)) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            foreach ($cartItems as $item) {
                $stmt = $conn->prepare("SELECT price FROM products WHERE product_id = ?");
                $stmt->execute([$item['product_id']]);
                $product = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($product) {
                    $itemPrice = $product['price'];
                    
                    // คำนวณราคาตัวเลือก
                    if (!empty($item['options'])) {
                        foreach ($item['options'] as $optionId) {
                            $stmt = $conn->prepare("SELECT price_adjustment FROM product_options WHERE option_id = ?");
                            $stmt->execute([$optionId]);
                            $option = $stmt->fetch(PDO::FETCH_ASSOC);
                            if ($option) {
                                $itemPrice += $option['price_adjustment'];
                            }
                        }
                    }
                    
                    $subtotal += ($itemPrice * $item['quantity']);
                    $totalQuantity += $item['quantity'];
                    $itemCount++;
                }
            }
        } catch (Exception $e) {
            if (function_exists('writeLog')) {
                writeLog("Calculate cart summary error: " . $e->getMessage(), 'ERROR');
            }
        }
    }
    
    // คำนวณภาษี (ถ้ามี)
    $tax = $subtotal * 0.07; // VAT 7%
    $total = $subtotal + $tax;
    
    return [
        'subtotal' => $subtotal,
        'tax' => $tax,
        'total' => $total,
        'item_count' => $itemCount,
        'total_quantity' => $totalQuantity
    ];
}
?>