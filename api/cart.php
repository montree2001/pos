<?php
/**
 * API จัดการตะกร้าสินค้า
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
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    $method = $_SERVER['REQUEST_METHOD'];
    
    switch ($action) {
        
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
                    $product = $stmt->fetch();
                    
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
                                $option = $stmt->fetch();
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
                SELECT product_id, name, price, is_available 
                FROM products 
                WHERE product_id = ?
            ");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
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
            SessionManager::set(CartSession::CART_KEY, $cartItems);
            
            // คำนวณราคาใหม่
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("SELECT price FROM products WHERE product_id = ?");
            $stmt->execute([$cartItems[$itemKey]['product_id']]);
            $product = $stmt->fetch();
            
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
            SessionManager::set(CartSession::CART_KEY, $cartItems);
            
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
            throw new Exception('Action not found');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    sendJsonResponse([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => $e->getMessage()
    ]);
    
    writeLog("Cart API Error: " . $e->getMessage() . " | Action: " . ($action ?? 'unknown'));
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
                $product = $stmt->fetch();
                
                if ($product) {
                    $itemTotal = $product['price'] * $item['quantity'];
                    
                    // เพิ่มราคาตัวเลือก
                    if (!empty($item['options'])) {
                        foreach ($item['options'] as $optionId) {
                            $stmt = $conn->prepare("SELECT price_adjustment FROM product_options WHERE option_id = ?");
                            $stmt->execute([$optionId]);
                            $option = $stmt->fetch();
                            if ($option) {
                                $itemTotal += ($option['price_adjustment'] * $item['quantity']);
                            }
                        }
                    }
                    
                    $subtotal += $itemTotal;
                    $itemCount++;
                    $totalQuantity += $item['quantity'];
                }
            }
        } catch (Exception $e) {
            writeLog("Cart summary calculation error: " . $e->getMessage());
        }
    }
    
    // คำนวณภาษีและค่าบริการ
    $taxRate = 7; // 7%
    $serviceChargeRate = 0; // 0%
    
    $tax = ($subtotal * $taxRate) / 100;
    $service = ($subtotal * $serviceChargeRate) / 100;
    $total = $subtotal + $tax + $service;
    
    return [
        'subtotal' => $subtotal,
        'tax' => $tax,
        'service' => $service,
        'total' => $total,
        'item_count' => $itemCount,
        'total_quantity' => $totalQuantity
    ];
}
?>