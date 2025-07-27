<?php
/**
 * จัดการเมนูอาหาร
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์
requireAuth('admin');

$pageTitle = 'จัดการเมนู';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: menu_management.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $productId = $_POST['product_id'] ?? null;
        $categoryId = $_POST['category_id'] ?? '';
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $price = floatval($_POST['price'] ?? 0);
        $cost = floatval($_POST['cost'] ?? 0);
        $preparationTime = intval($_POST['preparation_time'] ?? 5);
        $isAvailable = isset($_POST['is_available']) ? 1 : 0;
        
        // Validation
        $errors = [];
        if (empty($name)) $errors[] = 'กรุณากรอกชื่อเมนู';
        if (empty($categoryId)) $errors[] = 'กรุณาเลือกหมวดหมู่';
        if ($price <= 0) $errors[] = 'กรุณากรอกราคาที่ถูกต้อง';
        
        if (empty($errors)) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                // จัดการการอัปโหลดรูปภาพ
                $imagePath = '';
                if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $uploadResult = uploadImage($_FILES['image'], MENU_IMAGE_PATH);
                    if ($uploadResult['success']) {
                        $imagePath = $uploadResult['filename'];
                        
                        // ลบรูปภาพเก่า (ถ้าแก้ไข)
                        if ($action === 'edit' && $productId) {
                            $oldImageStmt = $conn->prepare("SELECT image FROM products WHERE product_id = ?");
                            $oldImageStmt->execute([$productId]);
                            $oldImage = $oldImageStmt->fetchColumn();
                            if ($oldImage) {
                                deleteFile(MENU_IMAGE_PATH . $oldImage);
                            }
                        }
                    } else {
                        setFlashMessage('error', $uploadResult['message']);
                        header('Location: menu_management.php');
                        exit();
                    }
                }
                
                if ($action === 'add') {
                    $stmt = $conn->prepare("
                        INSERT INTO products (category_id, name, description, price, cost, image, preparation_time, is_available, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$categoryId, $name, $description, $price, $cost, $imagePath, $preparationTime, $isAvailable]);
                    
                    setFlashMessage('success', 'เพิ่มเมนูใหม่สำเร็จ');
                    writeLog("Added new menu item: $name by " . getCurrentUser()['username']);
                    
                } elseif ($action === 'edit' && $productId) {
                    if ($imagePath) {
                        $stmt = $conn->prepare("
                            UPDATE products 
                            SET category_id = ?, name = ?, description = ?, price = ?, cost = ?, image = ?, preparation_time = ?, is_available = ?, updated_at = NOW()
                            WHERE product_id = ?
                        ");
                        $stmt->execute([$categoryId, $name, $description, $price, $cost, $imagePath, $preparationTime, $isAvailable, $productId]);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE products 
                            SET category_id = ?, name = ?, description = ?, price = ?, cost = ?, preparation_time = ?, is_available = ?, updated_at = NOW()
                            WHERE product_id = ?
                        ");
                        $stmt->execute([$categoryId, $name, $description, $price, $cost, $preparationTime, $isAvailable, $productId]);
                    }
                    
                    setFlashMessage('success', 'แก้ไขเมนูสำเร็จ');
                    writeLog("Updated menu item ID: $productId by " . getCurrentUser()['username']);
                }
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error managing menu: " . $e->getMessage());
            }
        } else {
            setFlashMessage('error', implode('<br>', $errors));
        }
    }
    
    header('Location: menu_management.php');
    exit();
}

// ดึงข้อมูลหมวดหมู่
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $categoriesStmt = $conn->prepare("SELECT * FROM categories WHERE status = 'active' ORDER BY display_order, name");
    $categoriesStmt->execute();
    $categories = $categoriesStmt->fetchAll();
    
    // ดึงข้อมูลเมนู
    $menuStmt = $conn->prepare("
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.category_id 
        ORDER BY c.display_order, p.name
    ");
    $menuStmt->execute();
    $menuItems = $menuStmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Error loading menu data: " . $e->getMessage());
    $categories = [];
    $menuItems = [];
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">จัดการเมนู</h1>
        <p class="text-muted mb-0">เพิ่ม แก้ไข และจัดการเมนูอาหารในระบบ</p>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#menuModal" onclick="openAddModal()">
            <i class="fas fa-plus me-2"></i>เพิ่มเมนู
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo count($menuItems); ?></div>
                    <div class="stats-label">เมนูทั้งหมด</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-utensils"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo count(array_filter($menuItems, fn($item) => $item['is_available'])); ?></div>
                    <div class="stats-label">เมนูที่เปิดขาย</div>
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
                    <div class="stats-number"><?php echo count(array_filter($menuItems, fn($item) => !$item['is_available'])); ?></div>
                    <div class="stats-label">เมนูที่ปิดขาย</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-times-circle"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo count($categories); ?></div>
                    <div class="stats-label">หมวดหมู่</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-tags"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Menu Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-list me-2"></i>รายการเมนู
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="menuTable">
                <thead>
                    <tr>
                        <th width="80">รูปภาพ</th>
                        <th>ชื่อเมนู</th>
                        <th>หมวดหมู่</th>
                        <th width="100">ราคา</th>
                        <th width="100">ต้นทุน</th>
                        <th width="80">เวลาเตรียม</th>
                        <th width="80">สถานะ</th>
                        <th width="150">การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($menuItems as $item): ?>
                        <tr>
                            <td>
                                <?php if ($item['image']): ?>
                                    <img src="<?php echo SITE_URL; ?>/uploads/menu_images/<?php echo $item['image']; ?>" 
                                         alt="<?php echo clean($item['name']); ?>" 
                                         class="img-thumbnail" 
                                         style="width: 60px; height: 60px; object-fit: cover;">
                                <?php else: ?>
                                    <div class="bg-light d-flex align-items-center justify-content-center" 
                                         style="width: 60px; height: 60px; border-radius: 8px;">
                                        <i class="fas fa-image text-muted"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo clean($item['name']); ?></div>
                                <?php if ($item['description']): ?>
                                    <small class="text-muted"><?php echo clean($item['description']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-secondary"><?php echo clean($item['category_name'] ?: 'ไม่มีหมวดหมู่'); ?></span>
                            </td>
                            <td>
                                <span class="fw-semibold text-success"><?php echo formatCurrency($item['price']); ?></span>
                            </td>
                            <td>
                                <?php if ($item['cost'] > 0): ?>
                                    <span class="text-muted"><?php echo formatCurrency($item['cost']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info"><?php echo $item['preparation_time']; ?> นาที</span>
                            </td>
                            <td>
                                <?php if ($item['is_available']): ?>
                                    <span class="badge bg-success">เปิดขาย</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">ปิดขาย</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#menuModal"
                                            onclick="openEditModal(<?php echo $item['product_id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-outline-success" 
                                            onclick="toggleAvailability(<?php echo $item['product_id']; ?>, <?php echo $item['is_available'] ? 0 : 1; ?>)"
                                            title="<?php echo $item['is_available'] ? 'ปิดขาย' : 'เปิดขาย'; ?>">
                                        <i class="fas fa-<?php echo $item['is_available'] ? 'eye-slash' : 'eye'; ?>"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" 
                                            onclick="deleteMenu(<?php echo $item['product_id']; ?>)">
                                        <i class="fas fa-trash"></i>
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

<!-- Menu Modal -->
<div class="modal fade" id="menuModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" enctype="multipart/form-data" id="menuForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="product_id" id="modalProductId" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">เพิ่มเมนูใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="mb-3">
                                <label for="name" class="form-label">ชื่อเมนู *</label>
                                <input type="text" class="form-control" id="name" name="name" required>
                            </div>
                            
                            <div class="mb-3">
                                <label for="category_id" class="form-label">หมวดหมู่ *</label>
                                <select class="form-select" id="category_id" name="category_id" required>
                                    <option value="">เลือกหมวดหมู่</option>
                                    <?php foreach ($categories as $category): ?>
                                        <option value="<?php echo $category['category_id']; ?>">
                                            <?php echo clean($category['name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">รายละเอียด</label>
                                <textarea class="form-control" id="description" name="description" rows="3"></textarea>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">รูปภาพ</label>
                                <div class="text-center">
                                    <img id="imagePreview" src="" alt="Preview" class="img-thumbnail mb-2" style="width: 150px; height: 150px; object-fit: cover; display: none;">
                                    <div id="imagePlaceholder" class="bg-light d-flex align-items-center justify-content-center mb-2" style="width: 150px; height: 150px; border-radius: 8px; margin: 0 auto;">
                                        <i class="fas fa-image fa-2x text-muted"></i>
                                    </div>
                                    <input type="file" class="form-control" id="image" name="image" accept="image/*">
                                    <small class="text-muted">รองรับ JPG, PNG, GIF (ไม่เกิน 5MB)</small>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="price" class="form-label">ราคาขาย (บาท) *</label>
                                <input type="number" class="form-control" id="price" name="price" step="0.01" min="0" required>
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="cost" class="form-label">ต้นทุน (บาท)</label>
                                <input type="number" class="form-control" id="cost" name="cost" step="0.01" min="0">
                            </div>
                        </div>
                        
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="preparation_time" class="form-label">เวลาเตรียม (นาที)</label>
                                <input type="number" class="form-control" id="preparation_time" name="preparation_time" min="1" value="5">
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input type="checkbox" class="form-check-input" id="is_available" name="is_available" checked>
                            <label class="form-check-label" for="is_available">
                                เปิดขายในระบบ
                            </label>
                        </div>
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php
$additionalJS = [
    'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js'
];

$inlineJS = "
// Initialize DataTable
$('#menuTable').DataTable({
    order: [[2, 'asc'], [1, 'asc']],
    columnDefs: [
        { orderable: false, targets: [0, 7] }
    ],
    language: {
        url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
    }
});

// Image preview
$('#image').on('change', function(e) {
    const file = e.target.files[0];
    if (file) {
        const reader = new FileReader();
        reader.onload = function(e) {
            $('#imagePreview').attr('src', e.target.result).show();
            $('#imagePlaceholder').hide();
        };
        reader.readAsDataURL(file);
    } else {
        $('#imagePreview').hide();
        $('#imagePlaceholder').show();
    }
});

// Open add modal
function openAddModal() {
    $('#modalTitle').text('เพิ่มเมนูใหม่');
    $('#modalAction').val('add');
    $('#modalProductId').val('');
    $('#menuForm')[0].reset();
    $('#imagePreview').hide();
    $('#imagePlaceholder').show();
    $('#is_available').prop('checked', true);
}

// Open edit modal
function openEditModal(productId) {
    $('#modalTitle').text('แก้ไขเมนู');
    $('#modalAction').val('edit');
    $('#modalProductId').val(productId);
    
    // Load product data
    $.get(SITE_URL + '/api/menu.php?action=get&id=' + productId, function(response) {
        if (response.success) {
            const product = response.data;
            $('#name').val(product.name);
            $('#category_id').val(product.category_id);
            $('#description').val(product.description);
            $('#price').val(product.price);
            $('#cost').val(product.cost);
            $('#preparation_time').val(product.preparation_time);
            $('#is_available').prop('checked', product.is_available == 1);
            
            if (product.image) {
                $('#imagePreview').attr('src', SITE_URL + '/uploads/menu_images/' + product.image).show();
                $('#imagePlaceholder').hide();
            } else {
                $('#imagePreview').hide();
                $('#imagePlaceholder').show();
            }
        }
    });
}

// Toggle availability
function toggleAvailability(productId, isAvailable) {
    const action = isAvailable ? 'เปิดขาย' : 'ปิดขาย';
    
    confirmAction('ต้องการ' + action + 'เมนูนี้?', function() {
        $.post(SITE_URL + '/api/menu.php', {
            action: 'toggle_availability',
            product_id: productId,
            is_available: isAvailable
        }, function(response) {
            if (response.success) {
                showSuccess(action + 'สำเร็จ', function() {
                    location.reload();
                });
            }
        });
    });
}

// Delete menu
function deleteMenu(productId) {
    confirmAction('ต้องการลบเมนูนี้? การกระทำนี้ไม่สามารถยกเลิกได้', function() {
        $.post(SITE_URL + '/api/menu.php', {
            action: 'delete',
            product_id: productId
        }, function(response) {
            if (response.success) {
                showSuccess('ลบเมนูสำเร็จ', function() {
                    location.reload();
                });
            }
        });
    });
}

// Form validation
$('#menuForm').on('submit', function(e) {
    const price = parseFloat($('#price').val());
    const cost = parseFloat($('#cost').val()) || 0;
    
    if (cost > 0 && cost >= price) {
        e.preventDefault();
        Swal.fire({
            icon: 'warning',
            title: 'คำเตือน',
            text: 'ต้นทุนไม่ควรมากกว่าหรือเท่ากับราคาขาย',
            confirmButtonText: 'เข้าใจแล้ว'
        });
        return false;
    }
});
";

require_once '../includes/footer.php';
?>