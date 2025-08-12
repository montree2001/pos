<?php
/**
 * Cart API Handler
 * Smart Order Management System
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, must-revalidate');

define('SYSTEM_INIT', true);
require_once '../../config/config.php';
require_once '../../config/database.php';
require_once '../../config/session.php';
require_once '../../includes/functions.php';

// เริ่มต้น Session
SessionManager::start();

// รับข้อมูลจาก request
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$response = ['success' => false, 'message' => '', 'data' => []];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    switch ($action) {
        case 'add':
            $response = handleAddToCart($_POST, $conn);
            break;
            
        case 'update':
            $response = handleUpdateQuantity($_POST, $conn);
            break;
            
        case 'remove':
            $response = handleRemoveItem($_POST, $conn);
            break;
            
        case 'clear':
            $response = handleClearCart($conn);
            break;
            
        case 'get':
            $response = handleGetCart($conn);
            break;
            
        case 'count':
            $response = handleGetCartCount($conn);
            break;
            
        default:
            $response = [
                'success' => false,
                'message' => 'Invalid action specified'
            ];
            break;
    }
    
} catch (Exception $e) {
    writeLog("Cart API error: " . $e->getMessage());
    $response = [
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง'
    ];
}

// ส่งผลลัพธ์กลับ
echo json_encode($response, JSON_UNESCAPED_UNICODE);
exit;

/**
 * เพิ่มสินค้าลงตะกร้า
 */
function handleAddToCart($data, $conn) {
    $product_id = intval($data['product_id'] ?? 0);
    $quantity = intval($data['quantity'] ?? 1);
    $options = $data['options'] ?? [];
    
    if ($product_id <= 0) {
        return ['success' => false, 'message' => 'รหัสสินค้าไม่ถูกต้อง'];
    }
    
    if ($quantity < 1 || $quantity > 99) {
        return ['success' => false, 'message' => 'จำนวนสินค้าต้องอยู่ระหว่าง 1-99'];
    }
    
    // ตรวจสอบสินค้า
    $stmt = $conn->prepare("
        SELECT * FROM products 
        WHERE product_id = ? AND is_available = 1
    ");
    $stmt->execute([$product_id]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        return ['success' => false, 'message' => 'ไม่พบสินค้าหรือสินค้าไม่พร้อมจำหน่าย'];
    }
    
    // ตรวจสอบตัวเลือกสินค้า
    $validOptions = [];
    if (!empty($options) && is_array($options)) {
        foreach ($options as $optionId) {
            $optionId = intval($optionId);
            if ($optionId > 0) {
                $stmt = $conn->prepare("
                    SELECT * FROM product_options 
                    WHERE option_id = ? AND product_id = ?
                ");
                $stmt->execute([$optionId, $product_id]);
                if ($stmt->fetch()) {
                    $validOptions[] = $optionId;
                }
            }
        }
    }
    
    // เพิ่มลงตะกร้า
    $result = addToCart($product_id, $quantity, $validOptions);
    
    if ($result) {
        $cartSummary = calculateCartSummary($conn);
        return [
            'success' => true,
            'message' => 'เพิ่มสินค้าลงตะกร้าเรียบร้อย',
            'cart_summary' => $cartSummary
        ];
    } else {
        return ['success' => false, 'message' => 'ไม่สามารถเพิ่มสินค้าลงตะกร้าได้'];
    }
}

/**
 * อัปเดตจำนวนสินค้าในตะกร้า
 */
function handleUpdateQuantity($data, $conn) {
    $item_key = $data['item_key'] ?? '';
    $quantity = intval($data['quantity'] ?? 1);
    
    if (empty($item_key)) {
        return ['success' => false, 'message' => 'รหัสสินค้าในตะกร้าไม่ถูกต้อง'];
    }
    
    if ($quantity < 1 || $quantity > 99) {
        return ['success' => false, 'message' => 'จำนวนสินค้าต้องอยู่ระหว่าง 1-99'];
    }
    
    // อัปเดตจำนวน
    $result = updateCartQuantity($item_key, $quantity);
    
    if ($result) {
        $cartSummary = calculateCartSummary($conn);
        return [
            'success' => true,
            'message' => 'อัปเดตจำนวนสินค้าเรียบร้อย',
            'cart_summary' => $cartSummary
        ];
    } else {
        return ['success' => false, 'message' => 'ไม่สามารถอัปเดตจำนวนสินค้าได้'];
    }
}

/**
 * ลบสินค้าออกจากตะกร้า
 */
function handleRemoveItem($data, $conn) {
    $item_key = $data['item_key'] ?? '';
    
    if (empty($item_key)) {
        return ['success' => false, 'message' => 'รหัสสินค้าในตะกร้าไม่ถูกต้อง'];
    }
    
    // ลบสินค้า
    $result = removeFromCart($item_key);
    
    if ($result) {
        $cartItems = getCartItems();
        $cartEmpty = empty($cartItems);
        
        $response = [
            'success' => true,
            'message' => 'ลบสินค้าออกจากตะกร้าเรียบร้อย',
            'cart_empty' => $cartEmpty
        ];
        
        if (!$cartEmpty) {
            $response['cart_summary'] = calculateCartSummary($conn);
        }
        
        return $response;
    } else {
        return ['success' => false, 'message' => 'ไม่สามารถลบสินค้าออกจากตะกร้าได้'];
    }
}

/**
 * ล้างตะกร้าทั้งหมด
 */
function handleClearCart($conn) {
    $result = clearCart();
    
    if ($result) {
        return [
            'success' => true,
            'message' => 'ล้างตะกร้าเรียบร้อย'
        ];
    } else {
        return ['success' => false, 'message' => 'ไม่สามารถล้างตะกร้าได้'];
    }
}

/**
 * ดึงข้อมูลตะกร้า
 */
function handleGetCart($conn) {
    $cartItems = getCartItems();
    $cartDetails = [];
    
    if (!empty($cartItems)) {
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
                $itemPrice = $product['price'];
                $optionText = '';
                $optionPrice = 0;
                
                // ดึงข้อมูลตัวเลือกสินค้า
                if (!empty($item['options'])) {
                    $optionNames = [];
                    foreach ($item['options'] as $optionId) {
                        $stmt = $conn->prepare("
                            SELECT name, price_adjustment 
                            FROM product_options 
                            WHERE option_id = ?
                        ");
                        $stmt->execute([$optionId]);
                        $option = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($option) {
                            $optionNames[] = $option['name'];
                            $optionPrice += $option['price_adjustment'];
                        }
                    }
                    $optionText = implode(', ', $optionNames);
                }
                
                $finalPrice = $itemPrice + $optionPrice;
                $lineTotal = $finalPrice * $item['quantity'];
                
                $cartDetails[] = [
                    'key' => $itemKey,
                    'product_id' => $item['product_id'],
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'image' => $product['image'],
                    'category_name' => $product['category_name'],
                    'preparation_time' => $product['preparation_time'],
                    'base_price' => $product['price'],
                    'option_text' => $optionText,
                    'option_price' => $optionPrice,
                    'final_price' => $finalPrice,
                    'quantity' => $item['quantity'],
                    'line_total' => $lineTotal,
                    'options' => $item['options'] ?? [],
                    'added_at' => $item['added_at'] ?? time()
                ];
            }
        }
    }
    
    $cartSummary = calculateCartSummary($conn);
    
    return [
        'success' => true,
        'cart_items' => $cartDetails,
        'cart_summary' => $cartSummary
    ];
}

/**
 * ดึงจำนวนสินค้าในตะกร้า
 */
function handleGetCartCount($conn) {
    $cartItems = getCartItems();
    $itemCount = 0;
    $totalQuantity = 0;
    
    if (!empty($cartItems)) {
        $itemCount = count($cartItems);
        foreach ($cartItems as $item) {
            $totalQuantity += $item['quantity'];
        }
    }
    
    return [
        'success' => true,
        'count' => $itemCount,
        'total_quantity' => $totalQuantity
    ];
}

/**
 * คำนวณสรุปตะกร้า
 */
function calculateCartSummary($conn) {
    $cartItems = getCartItems();
    $summary = [
        'subtotal' => 0,
        'service_charge' => 0,
        'service_charge_rate' => 0,
        'tax' => 0,
        'tax_rate' => 0,
        'total' => 0,
        'item_count' => 0,
        'total_quantity' => 0,
        'items' => []
    ];
    
    if (empty($cartItems)) {
        return $summary;
    }
    
    foreach ($cartItems as $itemKey => $item) {
        $stmt = $conn->prepare("
            SELECT price FROM products 
            WHERE product_id = ? AND is_available = 1
        ");
        $stmt->execute([$item['product_id']]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($product) {
            $itemPrice = $product['price'];
            $optionPrice = 0;
            
            // คำนวณราคาตัวเลือก
            if (!empty($item['options'])) {
                foreach ($item['options'] as $optionId) {
                    $stmt = $conn->prepare("
                        SELECT price_adjustment 
                        FROM product_options 
                        WHERE option_id = ?
                    ");
                    $stmt->execute([$optionId]);
                    $option = $stmt->fetch(PDO::FETCH_ASSOC);
                    if ($option) {
                        $optionPrice += $option['price_adjustment'];
                    }
                }
            }
            
            $finalPrice = $itemPrice + $optionPrice;
            $lineTotal = $finalPrice * $item['quantity'];
            
            $summary['subtotal'] += $lineTotal;
            $summary['total_quantity'] += $item['quantity'];
            $summary['item_count']++;
            
            // เพิ่มข้อมูลรายการสำหรับ real-time update
            $summary['items'][] = [
                'key' => $itemKey,
                'final_price' => $finalPrice,
                'line_total' => $lineTotal
            ];
        }
    }
    
    // ดึงการตั้งค่าภาษีจากระบบ
    try {
        $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings WHERE setting_key IN ('tax_rate', 'service_charge')");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        $taxRate = floatval($settings['tax_rate'] ?? 7) / 100;
        $serviceChargeRate = floatval($settings['service_charge'] ?? 0) / 100;
    } catch (Exception $e) {
        $taxRate = 0.07; // 7% default
        $serviceChargeRate = 0; // no service charge default
    }
    
    // คำนวณภาษีและค่าบริการ
    $summary['service_charge'] = $summary['subtotal'] * $serviceChargeRate;
    $summary['service_charge_rate'] = $serviceChargeRate * 100;
    $taxableAmount = $summary['subtotal'] + $summary['service_charge'];
    $summary['tax'] = $taxableAmount * $taxRate;
    $summary['tax_rate'] = $taxRate * 100;
    $summary['total'] = $taxableAmount + $summary['tax'];
    
    return $summary;
}
?>