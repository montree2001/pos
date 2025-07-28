<?php
/**
 * จัดการหมวดหมู่สินค้า - Smart Order Management System
 * รองรับการจัดการหมวดหมู่สำหรับทุกประเภทสินค้า ไม่จำกัดแค่อาหาร
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์
if (!isLoggedIn() || !in_array(getCurrentUserRole(), ['admin', 'manager'])) {
    header('Location: login.php');
    exit();
}

$pageTitle = 'จัดการหมวดหมู่สินค้า';

// จัดการคำขอ POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $displayOrder = intval($_POST['display_order'] ?? 0);
        $status = $_POST['status'] ?? 'active';
        $categoryId = intval($_POST['category_id'] ?? 0);
        
        // ตรวจสอบข้อมูล
        $errors = [];
        if (empty($name)) {
            $errors[] = 'กรุณากรอกชื่อหมวดหมู่';
        }
        
        // ตรวจสอบชื่อซ้ำ
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            $checkQuery = "SELECT category_id FROM categories WHERE name = ? AND status != 'deleted'";
            if ($action === 'edit') {
                $checkQuery .= " AND category_id != ?";
            }
            
            $checkStmt = $conn->prepare($checkQuery);
            if ($action === 'edit') {
                $checkStmt->execute([$name, $categoryId]);
            } else {
                $checkStmt->execute([$name]);
            }
            
            if ($checkStmt->fetch()) {
                $errors[] = 'ชื่อหมวดหมู่นี้มีอยู่แล้ว';
            }
            
        } catch (Exception $e) {
            $errors[] = 'เกิดข้อผิดพลาดในการตรวจสอบข้อมูล';
            writeLog("Category validation error: " . $e->getMessage());
        }
        
        if (empty($errors)) {
            try {
                if ($action === 'add') {
                    $stmt = $conn->prepare("
                        INSERT INTO categories (name, description, display_order, status, created_at, updated_at) 
                        VALUES (?, ?, ?, ?, NOW(), NOW())
                    ");
                    $stmt->execute([$name, $description, $displayOrder, $status]);
                    
                    setFlashMessage('success', 'เพิ่มหมวดหมู่สำเร็จ');
                    writeLog("Added category: $name by " . getCurrentUser()['username']);
                    
                } else {
                    $stmt = $conn->prepare("
                        UPDATE categories 
                        SET name = ?, description = ?, display_order = ?, status = ?, updated_at = NOW() 
                        WHERE category_id = ?
                    ");
                    $stmt->execute([$name, $description, $displayOrder, $status, $categoryId]);
                    
                    setFlashMessage('success', 'แก้ไขหมวดหมู่สำเร็จ');
                    writeLog("Updated category ID: $categoryId by " . getCurrentUser()['username']);
                }
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error managing category: " . $e->getMessage());
            }
        } else {
            setFlashMessage('error', implode('<br>', $errors));
        }
    }
    
    // การลบหมวดหมู่ (Soft Delete)
    elseif ($action === 'delete') {
        $categoryId = intval($_POST['category_id'] ?? 0);
        
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // ตรวจสอบว่ามีสินค้าในหมวดหมู่นี้หรือไม่
            $checkStmt = $conn->prepare("SELECT COUNT(*) FROM products WHERE category_id = ?");
            $checkStmt->execute([$categoryId]);
            $productCount = $checkStmt->fetchColumn();
            
            if ($productCount > 0) {
                setFlashMessage('error', 'ไม่สามารถลบหมวดหมู่ที่มีสินค้าอยู่ได้ กรุณาย้ายสินค้าไปหมวดหมู่อื่นก่อน');
            } else {
                $stmt = $conn->prepare("UPDATE categories SET status = 'deleted', updated_at = NOW() WHERE category_id = ?");
                $stmt->execute([$categoryId]);
                
                setFlashMessage('success', 'ลบหมวดหมู่สำเร็จ');
                writeLog("Deleted category ID: $categoryId by " . getCurrentUser()['username']);
            }
            
        } catch (Exception $e) {
            setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            writeLog("Error deleting category: " . $e->getMessage());
        }
    }
    
    header('Location: category_management.php');
    exit();
}

// ดึงข้อมูลหมวดหมู่
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT c.*, 
               COUNT(p.product_id) as product_count,
               COUNT(CASE WHEN p.is_available = 1 THEN 1 END) as available_products
        FROM categories c 
        LEFT JOIN products p ON c.category_id = p.category_id 
        WHERE c.status != 'deleted'
        GROUP BY c.category_id 
        ORDER BY c.display_order, c.name
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Error loading categories: " . $e->getMessage());
    $categories = [];
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">จัดการหมวดหมู่สินค้า</h1>
        <p class="text-muted mb-0">เพิ่ม แก้ไข และจัดการหมวดหมู่สินค้าในระบบ</p>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#categoryModal" onclick="openAddModal()">
            <i class="fas fa-plus me-2"></i>เพิ่มหมวดหมู่
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo count($categories); ?></div>
                    <div class="stats-label">หมวดหมู่ทั้งหมด</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-tags"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo count(array_filter($categories, fn($cat) => $cat['status'] === 'active')); ?></div>
                    <div class="stats-label">หมวดหมู่ที่เปิดใช้</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card warning">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo array_sum(array_column($categories, 'product_count')); ?></div>
                    <div class="stats-label">สินค้าทั้งหมด</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-cube"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card primary">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo array_sum(array_column($categories, 'available_products')); ?></div>
                    <div class="stats-label">สินค้าที่เปิดขาย</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Categories Table -->
<div class="card shadow-sm">
    <div class="card-header bg-white border-bottom-0 py-3">
        <h5 class="card-title mb-0">รายการหมวดหมู่สินค้า</h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table id="categoriesTable" class="table table-hover align-middle">
                <thead class="table-light">
                    <tr>
                        <th width="60">#</th>
                        <th>ชื่อหมวดหมู่</th>
                        <th>รายละเอียด</th>
                        <th width="100">ลำดับแสดง</th>
                        <th width="120">จำนวนสินค้า</th>
                        <th width="100">สถานะ</th>
                        <th width="80">วันที่สร้าง</th>
                        <th width="120">จัดการ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($categories as $index => $category): ?>
                    <tr>
                        <td><?php echo $index + 1; ?></td>
                        <td>
                            <div class="fw-medium text-dark"><?php echo clean($category['name']); ?></div>
                        </td>
                        <td>
                            <div class="text-muted small">
                                <?php echo clean(substr($category['description'], 0, 60)) . (strlen($category['description']) > 60 ? '...' : ''); ?>
                            </div>
                        </td>
                        <td>
                            <span class="badge bg-secondary"><?php echo $category['display_order']; ?></span>
                        </td>
                        <td>
                            <span class="text-primary fw-medium"><?php echo $category['product_count']; ?></span>
                            <small class="text-muted">(<?php echo $category['available_products']; ?> เปิดขาย)</small>
                        </td>
                        <td>
                            <?php if ($category['status'] === 'active'): ?>
                                <span class="badge bg-success">เปิดใช้</span>
                            <?php else: ?>
                                <span class="badge bg-secondary">ปิดใช้</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <small class="text-muted">
                                <?php echo date('d/m/Y', strtotime($category['created_at'])); ?>
                            </small>
                        </td>
                        <td>
                            <div class="btn-group" role="group">
                                <button class="btn btn-sm btn-outline-primary" 
                                        onclick="editCategory(<?php echo htmlspecialchars(json_encode($category)); ?>)"
                                        data-bs-toggle="modal" data-bs-target="#categoryModal">
                                    <i class="fas fa-edit"></i>
                                </button>
                                <?php if ($category['product_count'] == 0): ?>
                                <button class="btn btn-sm btn-outline-danger" 
                                        onclick="deleteCategory(<?php echo $category['category_id']; ?>, '<?php echo clean($category['name']); ?>')">
                                    <i class="fas fa-trash"></i>
                                </button>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Category Modal -->
<div class="modal fade" id="categoryModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="categoryForm" method="POST">
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">เพิ่มหมวดหมู่ใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="action" id="formAction" value="add">
                    <input type="hidden" name="category_id" id="categoryId" value="0">
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">ชื่อหมวดหมู่ *</label>
                        <input type="text" class="form-control" id="name" name="name" required maxlength="100">
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">รายละเอียด</label>
                        <textarea class="form-control" id="description" name="description" rows="3" maxlength="255"></textarea>
                        <div class="form-text">รายละเอียดเพิ่มเติมเกี่ยวกับหมวดหมู่นี้</div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="display_order" class="form-label">ลำดับการแสดง</label>
                                <input type="number" class="form-control" id="display_order" name="display_order" 
                                       value="0" min="0" max="999">
                                <div class="form-text">เลขน้อยจะแสดงก่อน</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">สถานะ</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active">เปิดใช้</option>
                                    <option value="inactive">ปิดใช้</option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary" id="submitBtn">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// DataTable initialization
$(document).ready(function() {
    $('#categoriesTable').DataTable({
        language: {
            url: '../assets/js/datatables-thai.json'
        },
        pageLength: 25,
        order: [[3, 'asc']], // เรียงตามลำดับแสดง
        columnDefs: [
            { orderable: false, targets: [7] } // ปิดการเรียงคอลัมน์จัดการ
        ],
        responsive: true
    });
});

// เปิด Modal สำหรับเพิ่มหมวดหมู่
function openAddModal() {
    $('#modalTitle').text('เพิ่มหมวดหมู่ใหม่');
    $('#formAction').val('add');
    $('#categoryId').val('0');
    $('#categoryForm')[0].reset();
    $('#submitBtn').text('เพิ่มหมวดหมู่');
}

// แก้ไขหมวดหมู่
function editCategory(category) {
    $('#modalTitle').text('แก้ไขหมวดหมู่');
    $('#formAction').val('edit');
    $('#categoryId').val(category.category_id);
    $('#name').val(category.name);
    $('#description').val(category.description);
    $('#display_order').val(category.display_order);
    $('#status').val(category.status);
    $('#submitBtn').text('บันทึกการแก้ไข');
}

// ลบหมวดหมู่
function deleteCategory(categoryId, categoryName) {
    if (confirm('คุณต้องการลบหมวดหมู่ "' + categoryName + '" หรือไม่?\n\nการลบจะไม่สามารถยกเลิกได้')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="category_id" value="${categoryId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// ตรวจสอบฟอร์มก่อนส่ง
$('#categoryForm').on('submit', function(e) {
    const name = $('#name').val().trim();
    if (!name) {
        e.preventDefault();
        alert('กรุณากรอกชื่อหมวดหมู่');
        $('#name').focus();
        return false;
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>