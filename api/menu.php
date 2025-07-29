<?php
/**
 * API จัดการเมนู
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตั้งค่า Content-Type
header('Content-Type: application/json; charset=utf-8');

// ตรวจสอบ HTTP Method
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? $_POST['action'] ?? '';

try {
    // ตรวจสอบสิทธิ์
    if (!isLoggedIn() || getCurrentUserRole() !== 'admin') {
        throw new Exception('ไม่มีสิทธิ์เข้าถึง');
    }

    $db = new Database();
    $conn = $db->getConnection();

    switch ($action) {
        case 'get':
            handleGetProduct($conn);
            break;
            
        case 'toggle_availability':
            handleToggleAvailability($conn);
            break;
            
        case 'delete':
            handleDeleteProduct($conn);
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
 * ดึงข้อมูลสินค้าตาม ID
 */
function handleGetProduct($conn) {
    $productId = $_GET['id'] ?? '';
    
    if (empty($productId)) {
        throw new Exception('ไม่พบรหัสสินค้า');
    }
    
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.category_id 
        WHERE p.product_id = ?
    ");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    
    if (!$product) {
        throw new Exception('ไม่พบสินค้า');
    }
    
    echo json_encode([
        'success' => true,
        'data' => $product
    ]);
}

/**
 * เปิด/ปิดการขาย
 */
function handleToggleAvailability($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('วิธีการร้องขอไม่ถูกต้อง');
    }
    
    $productId = $_POST['product_id'] ?? '';
    $isAvailable = $_POST['is_available'] ?? 0;
    
    if (empty($productId)) {
        throw new Exception('ไม่พบรหัสสินค้า');
    }
    
    $stmt = $conn->prepare("
        UPDATE products 
        SET is_available = ?, updated_at = NOW() 
        WHERE product_id = ?
    ");
    $stmt->execute([$isAvailable, $productId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('ไม่สามารถอัปเดตสถานะได้');
    }
    
    $status = $isAvailable ? 'เปิดขาย' : 'ปิดขาย';
    writeLog("Product ID $productId status changed to: $status by " . getCurrentUser()['username']);
    
    echo json_encode([
        'success' => true,
        'message' => "อัปเดตสถานะเป็น $status สำเร็จ"
    ]);
}

/**
 * ลบสินค้า
 */
function handleDeleteProduct($conn) {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('วิธีการร้องขอไม่ถูกต้อง');
    }
    
    $productId = $_POST['product_id'] ?? '';
    
    if (empty($productId)) {
        throw new Exception('ไม่พบรหัสสินค้า');
    }
    
    // ตรวจสอบว่ามีออเดอร์ที่ใช้สินค้านี้หรือไม่
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count 
        FROM order_items 
        WHERE product_id = ?
    ");
    $stmt->execute([$productId]);
    $orderCount = $stmt->fetchColumn();
    
    if ($orderCount > 0) {
        // ถ้ามีออเดอร์แล้ว ให้ปิดการขายแทนการลบ
        $stmt = $conn->prepare("
            UPDATE products 
            SET is_available = 0, updated_at = NOW() 
            WHERE product_id = ?
        ");
        $stmt->execute([$productId]);
        
        echo json_encode([
            'success' => true,
            'message' => 'ไม่สามารถลบสินค้าที่มีออเดอร์แล้ว จึงเปลี่ยนเป็นปิดการขายแทน'
        ]);
        return;
    }
    
    // ดึงข้อมูลรูปภาพก่อนลบ
    $stmt = $conn->prepare("SELECT image FROM products WHERE product_id = ?");
    $stmt->execute([$productId]);
    $product = $stmt->fetch();
    
    // ลบสินค้า
    $stmt = $conn->prepare("DELETE FROM products WHERE product_id = ?");
    $stmt->execute([$productId]);
    
    if ($stmt->rowCount() === 0) {
        throw new Exception('ไม่สามารถลบสินค้าได้');
    }
    
    // ลบรูปภาพ (ถ้ามี)
    if ($product && $product['image']) {
        $imagePath = MENU_IMAGE_PATH . $product['image'];
        if (file_exists($imagePath)) {
            unlink($imagePath);
        }
    }
    
    writeLog("Product ID $productId deleted by " . getCurrentUser()['username']);
    
    echo json_encode([
        'success' => true,
        'message' => 'ลบสินค้าสำเร็จ'
    ]);
}
?>