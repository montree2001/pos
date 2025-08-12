<?php
/**
 * API จัดการสต็อกสินค้า
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

// ตั้งค่า Headers
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $method = $_SERVER['REQUEST_METHOD'];
    $action = $_GET['action'] ?? $_POST['action'] ?? '';
    
    // ดึงข้อมูล JSON body ถ้ามี
    $input = json_decode(file_get_contents('php://input'), true);
    if ($input && !$action) {
        $action = $input['action'] ?? '';
    }
    
    switch ($action) {
        case 'get_stock_levels':
            getStockLevels($conn);
            break;
            
        case 'update_stock':
            updateStock($conn, $input ?? $_POST);
            break;
            
        case 'get_low_stock':
            getLowStockProducts($conn);
            break;
            
        case 'get_stock_movements':
            getStockMovements($conn);
            break;
            
        case 'bulk_update_stock':
            bulkUpdateStock($conn, $input ?? $_POST);
            break;
            
        default:
            throw new Exception('Invalid action');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * ดึงระดับสต็อกทั้งหมด
 */
function getStockLevels($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                p.product_id,
                p.name,
                p.stock_quantity,
                p.min_stock_level,
                c.name as category_name,
                CASE 
                    WHEN p.stock_quantity <= 0 THEN 'out'
                    WHEN p.stock_quantity <= p.min_stock_level THEN 'low'
                    ELSE 'normal'
                END as status
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            ORDER BY c.name, p.name
        ");
        $stmt->execute();
        $products = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $products
        ]);
        
    } catch (Exception $e) {
        throw new Exception('ไม่สามารถดึงข้อมูลสต็อกได้');
    }
}

/**
 * อัปเดตสต็อกสินค้า
 */
function updateStock($conn, $data) {
    try {
        $productId = intval($data['product_id'] ?? 0);
        $newStock = intval($data['new_stock'] ?? 0);
        $reason = trim($data['reason'] ?? '');
        $userId = getCurrentUserId();
        
        if ($productId <= 0) {
            throw new Exception('รหัสสินค้าไม่ถูกต้อง');
        }
        
        if ($newStock < 0) {
            throw new Exception('จำนวนสต็อกต้องไม่ติดลบ');
        }
        
        // ดึงสต็อกปัจจุบัน
        $stmt = $conn->prepare("SELECT stock_quantity, name FROM products WHERE product_id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch();
        
        if (!$product) {
            throw new Exception('ไม่พบสินค้า');
        }
        
        $oldStock = $product['stock_quantity'];
        $change = $newStock - $oldStock;
        
        $conn->beginTransaction();
        
        // อัปเดตสต็อก
        $stmt = $conn->prepare("UPDATE products SET stock_quantity = ? WHERE product_id = ?");
        $stmt->execute([$newStock, $productId]);
        
        // บันทึกประวัติการเปลี่ยนแปลง
        if ($change != 0) {
            $stmt = $conn->prepare("
                INSERT INTO stock_movements 
                (product_id, movement_type, quantity_change, previous_quantity, new_quantity, reason, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $movementType = $change > 0 ? 'in' : 'out';
            $stmt->execute([
                $productId,
                $movementType,
                abs($change),
                $oldStock,
                $newStock,
                $reason ?: 'ปรับปรุงสต็อกผ่าน API',
                $userId
            ]);
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'อัปเดตสต็อกเรียบร้อยแล้ว',
            'data' => [
                'product_id' => $productId,
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'change' => $change
            ]
        ]);
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollBack();
        }
        throw $e;
    }
}

/**
 * ดึงสินค้าสต็อกต่ำ
 */
function getLowStockProducts($conn) {
    try {
        $stmt = $conn->prepare("
            SELECT 
                p.product_id,
                p.name,
                p.stock_quantity,
                p.min_stock_level,
                c.name as category_name,
                p.price
            FROM products p
            LEFT JOIN categories c ON p.category_id = c.category_id
            WHERE p.stock_quantity <= p.min_stock_level 
            AND p.min_stock_level > 0
            ORDER BY (p.stock_quantity / NULLIF(p.min_stock_level, 0)) ASC
        ");
        $stmt->execute();
        $products = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $products,
            'count' => count($products)
        ]);
        
    } catch (Exception $e) {
        throw new Exception('ไม่สามารถดึงข้อมูลสินค้าสต็อกต่ำได้');
    }
}

/**
 * ดึงประวัติการเคลื่อนไหวสต็อก
 */
function getStockMovements($conn) {
    try {
        $limit = intval($_GET['limit'] ?? 50);
        $productId = intval($_GET['product_id'] ?? 0);
        
        $sql = "
            SELECT 
                sm.*,
                p.name as product_name,
                u.name as user_name
            FROM stock_movements sm
            LEFT JOIN products p ON sm.product_id = p.product_id
            LEFT JOIN users u ON sm.created_by = u.user_id
        ";
        
        $params = [];
        if ($productId > 0) {
            $sql .= " WHERE sm.product_id = ?";
            $params[] = $productId;
        }
        
        $sql .= " ORDER BY sm.created_at DESC LIMIT ?";
        $params[] = $limit;
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        $movements = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'data' => $movements
        ]);
        
    } catch (Exception $e) {
        throw new Exception('ไม่สามารถดึงประวัติการเคลื่อนไหวได้');
    }
}

/**
 * อัปเดตสต็อกจำนวนมาก
 */
function bulkUpdateStock($conn, $data) {
    try {
        $updates = $data['updates'] ?? [];
        $reason = trim($data['reason'] ?? 'อัปเดตจำนวนมาก');
        $userId = getCurrentUserId();
        
        if (empty($updates) || !is_array($updates)) {
            throw new Exception('ไม่มีข้อมูลสำหรับอัปเดต');
        }
        
        $conn->beginTransaction();
        $results = [];
        
        foreach ($updates as $update) {
            $productId = intval($update['product_id'] ?? 0);
            $newStock = intval($update['new_stock'] ?? 0);
            
            if ($productId <= 0 || $newStock < 0) {
                continue;
            }
            
            // ดึงสต็อกปัจจุบัน
            $stmt = $conn->prepare("SELECT stock_quantity, name FROM products WHERE product_id = ?");
            $stmt->execute([$productId]);
            $product = $stmt->fetch();
            
            if (!$product) {
                continue;
            }
            
            $oldStock = $product['stock_quantity'];
            $change = $newStock - $oldStock;
            
            if ($change == 0) {
                continue;
            }
            
            // อัปเดตสต็อก
            $stmt = $conn->prepare("UPDATE products SET stock_quantity = ? WHERE product_id = ?");
            $stmt->execute([$newStock, $productId]);
            
            // บันทึกประวัติ
            $stmt = $conn->prepare("
                INSERT INTO stock_movements 
                (product_id, movement_type, quantity_change, previous_quantity, new_quantity, reason, created_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $movementType = $change > 0 ? 'in' : 'out';
            $stmt->execute([
                $productId,
                $movementType,
                abs($change),
                $oldStock,
                $newStock,
                $reason,
                $userId
            ]);
            
            $results[] = [
                'product_id' => $productId,
                'product_name' => $product['name'],
                'old_stock' => $oldStock,
                'new_stock' => $newStock,
                'change' => $change
            ];
        }
        
        $conn->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'อัปเดตสต็อกจำนวนมากเรียบร้อยแล้ว',
            'updated_count' => count($results),
            'results' => $results
        ]);
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollBack();
        }
        throw $e;
    }
}
?>