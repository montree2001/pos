<?php
/**
 * จัดการสต็อกสินค้า
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์
if (!isLoggedIn() || getCurrentUserRole() !== 'admin') {
    header('Location: login.php');
    exit();
}

$pageTitle = 'จัดการสต็อกสินค้า';
$currentPage = 'inventory';

// เริ่มต้นตัวแปร
$products = [];
$lowStockProducts = [];
$stockMovements = [];
$error = null;
$success = null;

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // จัดการคำขอ POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid CSRF token');
        }
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'update_stock':
                $productId = intval($_POST['product_id']);
                $newStock = intval($_POST['new_stock']);
                $reason = trim($_POST['reason'] ?? '');
                
                if ($productId <= 0 || $newStock < 0) {
                    throw new Exception('ข้อมูลไม่ถูกต้อง');
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
                    $reason ?: 'ปรับปรุงสต็อก',
                    getCurrentUserId()
                ]);
                
                $conn->commit();
                $success = 'อัปเดตสต็อกเรียบร้อยแล้ว';
                break;
                
            case 'set_min_stock':
                $productId = intval($_POST['product_id']);
                $minStock = intval($_POST['min_stock']);
                
                if ($productId <= 0 || $minStock < 0) {
                    throw new Exception('ข้อมูลไม่ถูกต้อง');
                }
                
                $stmt = $conn->prepare("UPDATE products SET min_stock_level = ? WHERE product_id = ?");
                $stmt->execute([$minStock, $productId]);
                
                $success = 'กำหนดจำนวนสต็อกขั้นต่ำเรียบร้อยแล้ว';
                break;
        }
    }
    
    // ดึงข้อมูลสินค้าและสต็อก
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            c.name as category_name,
            CASE 
                WHEN p.stock_quantity <= p.min_stock_level THEN 'low'
                WHEN p.stock_quantity = 0 THEN 'out'
                ELSE 'normal'
            END as stock_status
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        ORDER BY c.name, p.name
    ");
    $stmt->execute();
    $products = $stmt->fetchAll();
    
    // สินค้าสต็อกต่ำ
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name 
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.stock_quantity <= p.min_stock_level AND p.min_stock_level > 0
        ORDER BY (p.stock_quantity / NULLIF(p.min_stock_level, 0)) ASC
    ");
    $stmt->execute();
    $lowStockProducts = $stmt->fetchAll();
    
    // ประวัติการเคลื่อนไหวล่าสุด
    $stmt = $conn->prepare("
        SELECT 
            sm.*,
            p.name as product_name,
            u.name as user_name
        FROM stock_movements sm
        LEFT JOIN products p ON sm.product_id = p.product_id
        LEFT JOIN users u ON sm.created_by = u.user_id
        ORDER BY sm.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $stockMovements = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = $e->getMessage();
    writeLog("Inventory management error: " . $error);
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">จัดการสต็อกสินค้า</h1>
        <div>
            <button class="btn btn-primary" onclick="showBulkUpdateModal()">
                <i class="fas fa-upload"></i> อัปเดตจำนวนมาก
            </button>
            <button class="btn btn-success" onclick="exportInventoryReport()">
                <i class="fas fa-download"></i> ส่งออกรายงาน
            </button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- สต็อกต่ำ Alert -->
    <?php if (!empty($lowStockProducts)): ?>
        <div class="alert alert-warning">
            <h5><i class="fas fa-exclamation-triangle"></i> สินค้าสต็อกต่ำ (<?php echo count($lowStockProducts); ?> รายการ)</h5>
            <div class="row">
                <?php foreach (array_slice($lowStockProducts, 0, 6) as $product): ?>
                    <div class="col-md-4 col-lg-2 mb-2">
                        <small class="text-muted"><?php echo htmlspecialchars($product['name']); ?></small><br>
                        <span class="badge bg-danger"><?php echo $product['stock_quantity']; ?> ชิ้น</span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php if (count($lowStockProducts) > 6): ?>
                <small class="text-muted">และอีก <?php echo count($lowStockProducts) - 6; ?> รายการ...</small>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#all-products">
                <i class="fas fa-boxes"></i> สินค้าทั้งหมด
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#low-stock">
                <i class="fas fa-exclamation-triangle"></i> สต็อกต่ำ
                <?php if (!empty($lowStockProducts)): ?>
                    <span class="badge bg-danger ms-1"><?php echo count($lowStockProducts); ?></span>
                <?php endif; ?>
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#stock-movements">
                <i class="fas fa-history"></i> ประวัติการเคลื่อนไหว
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- สินค้าทั้งหมด -->
        <div class="tab-pane fade show active" id="all-products">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">รายการสินค้าและสต็อก</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="productsTable">
                            <thead>
                                <tr>
                                    <th>รูปภาพ</th>
                                    <th>ชื่อสินค้า</th>
                                    <th>หมวดหมู่</th>
                                    <th>สต็อกปัจจุบัน</th>
                                    <th>สต็อกขั้นต่ำ</th>
                                    <th>สถานะ</th>
                                    <th>การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($products as $product): ?>
                                    <tr>
                                        <td>
                                            <?php if ($product['image']): ?>
                                                <img src="../uploads/menu_images/<?php echo htmlspecialchars($product['image']); ?>" 
                                                     alt="<?php echo htmlspecialchars($product['name']); ?>"
                                                     class="img-thumbnail" style="width: 50px; height: 50px; object-fit: cover;">
                                            <?php else: ?>
                                                <div class="bg-light d-flex align-items-center justify-content-center" 
                                                     style="width: 50px; height: 50px;">
                                                    <i class="fas fa-image text-muted"></i>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($product['name']); ?></strong>
                                            <br><small class="text-muted">฿<?php echo number_format($product['price'], 2); ?></small>
                                        </td>
                                        <td><?php echo htmlspecialchars($product['category_name'] ?? 'ไม่ระบุ'); ?></td>
                                        <td>
                                            <span class="fs-5 fw-bold <?php echo $product['stock_status'] === 'out' ? 'text-danger' : ($product['stock_status'] === 'low' ? 'text-warning' : 'text-success'); ?>">
                                                <?php echo number_format($product['stock_quantity']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($product['min_stock_level']); ?></td>
                                        <td>
                                            <?php if ($product['stock_status'] === 'out'): ?>
                                                <span class="badge bg-danger">หมด</span>
                                            <?php elseif ($product['stock_status'] === 'low'): ?>
                                                <span class="badge bg-warning">ต่ำ</span>
                                            <?php else: ?>
                                                <span class="badge bg-success">ปกติ</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" 
                                                        onclick="showUpdateStockModal(<?php echo $product['product_id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['stock_quantity']; ?>)">
                                                    <i class="fas fa-edit"></i> แก้ไขสต็อก
                                                </button>
                                                <button class="btn btn-outline-secondary" 
                                                        onclick="showSetMinStockModal(<?php echo $product['product_id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['min_stock_level']; ?>)">
                                                    <i class="fas fa-cog"></i> ขั้นต่ำ
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- สต็อกต่ำ -->
        <div class="tab-pane fade" id="low-stock">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">สินค้าสต็อกต่ำ</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($lowStockProducts)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-check-circle fa-3x text-success mb-3"></i>
                            <h5>ไม่มีสินค้าสต็อกต่ำ</h5>
                            <p class="text-muted">สินค้าทั้งหมดมีสต็อกเพียงพอ</p>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($lowStockProducts as $product): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card border-warning">
                                        <div class="card-body">
                                            <div class="d-flex align-items-center">
                                                <?php if ($product['image']): ?>
                                                    <img src="../uploads/menu_images/<?php echo htmlspecialchars($product['image']); ?>" 
                                                         alt="<?php echo htmlspecialchars($product['name']); ?>"
                                                         class="img-thumbnail me-3" style="width: 60px; height: 60px; object-fit: cover;">
                                                <?php else: ?>
                                                    <div class="bg-light d-flex align-items-center justify-content-center me-3" 
                                                         style="width: 60px; height: 60px;">
                                                        <i class="fas fa-image text-muted"></i>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="flex-grow-1">
                                                    <h6 class="mb-1"><?php echo htmlspecialchars($product['name']); ?></h6>
                                                    <small class="text-muted"><?php echo htmlspecialchars($product['category_name']); ?></small>
                                                    <div class="mt-2">
                                                        <span class="badge bg-danger">เหลือ <?php echo $product['stock_quantity']; ?> ชิ้น</span>
                                                        <small class="text-muted ms-2">ขั้นต่ำ: <?php echo $product['min_stock_level']; ?></small>
                                                    </div>
                                                </div>
                                            </div>
                                            <div class="mt-3">
                                                <button class="btn btn-primary btn-sm w-100" 
                                                        onclick="showUpdateStockModal(<?php echo $product['product_id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['stock_quantity']; ?>)">
                                                    <i class="fas fa-plus"></i> เพิ่มสต็อก
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- ประวัติการเคลื่อนไหว -->
        <div class="tab-pane fade" id="stock-movements">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">ประวัติการเคลื่อนไหวสต็อก</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped" id="movementsTable">
                            <thead>
                                <tr>
                                    <th>วันที่</th>
                                    <th>สินค้า</th>
                                    <th>ประเภท</th>
                                    <th>จำนวนเปลี่ยน</th>
                                    <th>สต็อกเดิม</th>
                                    <th>สต็อกใหม่</th>
                                    <th>เหตุผล</th>
                                    <th>ผู้ดำเนินการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($stockMovements as $movement): ?>
                                    <tr>
                                        <td><?php echo formatDate($movement['created_at']); ?></td>
                                        <td><?php echo htmlspecialchars($movement['product_name']); ?></td>
                                        <td>
                                            <?php if ($movement['movement_type'] === 'in'): ?>
                                                <span class="badge bg-success">เข้า</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">ออก</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span class="<?php echo $movement['movement_type'] === 'in' ? 'text-success' : 'text-danger'; ?>">
                                                <?php echo $movement['movement_type'] === 'in' ? '+' : '-'; ?><?php echo number_format($movement['quantity_change']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo number_format($movement['previous_quantity']); ?></td>
                                        <td><?php echo number_format($movement['new_quantity']); ?></td>
                                        <td><?php echo htmlspecialchars($movement['reason']); ?></td>
                                        <td><?php echo htmlspecialchars($movement['user_name'] ?? 'ระบบ'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal แก้ไขสต็อก -->
<div class="modal fade" id="updateStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">แก้ไขสต็อกสินค้า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="update_stock">
                    <input type="hidden" name="product_id" id="updateProductId">
                    
                    <div class="mb-3">
                        <label class="form-label">สินค้า</label>
                        <p class="form-control-plaintext fw-bold" id="updateProductName"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label class="form-label">สต็อกปัจจุบัน</label>
                        <p class="form-control-plaintext" id="updateCurrentStock"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label for="new_stock" class="form-label">สต็อกใหม่ <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="new_stock" id="new_stock" required min="0">
                    </div>
                    
                    <div class="mb-3">
                        <label for="reason" class="form-label">เหตุผล</label>
                        <textarea class="form-control" name="reason" id="reason" rows="3" placeholder="ระบุเหตุผลในการเปลี่ยนแปลงสต็อก"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal กำหนดสต็อกขั้นต่ำ -->
<div class="modal fade" id="setMinStockModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">กำหนดสต็อกขั้นต่ำ</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="set_min_stock">
                    <input type="hidden" name="product_id" id="minStockProductId">
                    
                    <div class="mb-3">
                        <label class="form-label">สินค้า</label>
                        <p class="form-control-plaintext fw-bold" id="minStockProductName"></p>
                    </div>
                    
                    <div class="mb-3">
                        <label for="min_stock" class="form-label">จำนวนสต็อกขั้นต่ำ <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="min_stock" id="min_stock" required min="0">
                        <div class="form-text">ระบบจะแจ้งเตือนเมื่อสต็อกลดลงต่ำกว่าจำนวนนี้</div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// แสดง modal แก้ไขสต็อก
function showUpdateStockModal(productId, productName, currentStock) {
    document.getElementById('updateProductId').value = productId;
    document.getElementById('updateProductName').textContent = productName;
    document.getElementById('updateCurrentStock').textContent = currentStock + ' ชิ้น';
    document.getElementById('new_stock').value = currentStock;
    
    new bootstrap.Modal(document.getElementById('updateStockModal')).show();
}

// แสดง modal กำหนดสต็อกขั้นต่ำ
function showSetMinStockModal(productId, productName, currentMinStock) {
    document.getElementById('minStockProductId').value = productId;
    document.getElementById('minStockProductName').textContent = productName;
    document.getElementById('min_stock').value = currentMinStock;
    
    new bootstrap.Modal(document.getElementById('setMinStockModal')).show();
}

// ส่งออกรายงาน
function exportInventoryReport() {
    window.open('../api/export_report.php?type=inventory', '_blank');
}

// DataTables initialization
$(document).ready(function() {
    $('#productsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/th.json'
        },
        order: [[1, 'asc']],
        columnDefs: [
            { orderable: false, targets: [0, 6] }
        ]
    });
    
    $('#movementsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/th.json'
        },
        order: [[0, 'desc']],
        columnDefs: [
            { orderable: false, targets: [7] }
        ]
    });
});
</script>

<?php include '../includes/footer.php'; ?>