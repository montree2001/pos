<?php
/**
 * จัดการตัวเลือกสินค้า
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

$pageTitle = 'ตัวเลือกสินค้า';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: product_options.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $optionId = $_POST['option_id'] ?? null;
        $productId = intval($_POST['product_id'] ?? 0);
        $name = trim($_POST['name'] ?? '');
        $priceAdjustment = floatval($_POST['price_adjustment'] ?? 0);
        
        // Validation
        $errors = [];
        if (empty($name)) $errors[] = 'กรุณากรอกชื่อตัวเลือก';
        if ($productId <= 0) $errors[] = 'กรุณาเลือกสินค้า';
        
        if (empty($errors)) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                if ($action === 'add') {
                    $stmt = $conn->prepare("
                        INSERT INTO product_options (product_id, name, price_adjustment) 
                        VALUES (?, ?, ?)
                    ");
                    $stmt->execute([$productId, $name, $priceAdjustment]);
                    
                    setFlashMessage('success', 'เพิ่มตัวเลือกสินค้าสำเร็จ');
                    writeLog("Added product option: $name for product ID $productId by " . getCurrentUser()['username']);
                    
                } elseif ($action === 'edit' && $optionId) {
                    $stmt = $conn->prepare("
                        UPDATE product_options 
                        SET product_id = ?, name = ?, price_adjustment = ?
                        WHERE option_id = ?
                    ");
                    $stmt->execute([$productId, $name, $priceAdjustment, $optionId]);
                    
                    setFlashMessage('success', 'แก้ไขตัวเลือกสินค้าสำเร็จ');
                    writeLog("Updated product option ID: $optionId by " . getCurrentUser()['username']);
                }
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error managing product option: " . $e->getMessage());
            }
        } else {
            setFlashMessage('error', implode('<br>', $errors));
        }
    } elseif ($action === 'delete') {
        $optionId = intval($_POST['option_id'] ?? 0);
        
        if ($optionId) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                $stmt = $conn->prepare("DELETE FROM product_options WHERE option_id = ?");
                $stmt->execute([$optionId]);
                
                setFlashMessage('success', 'ลบตัวเลือกสินค้าสำเร็จ');
                writeLog("Deleted product option ID: $optionId by " . getCurrentUser()['username']);
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error deleting product option: " . $e->getMessage());
            }
        }
    }
    
    header('Location: product_options.php');
    exit();
}

// ดึงข้อมูลตัวเลือกสินค้า
$productOptions = [];
$products = [];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงข้อมูลสินค้าทั้งหมด
    $stmt = $conn->prepare("
        SELECT p.product_id, p.name, c.name as category_name
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        WHERE p.is_available = 1
        ORDER BY c.name, p.name
    ");
    $stmt->execute();
    $products = $stmt->fetchAll();
    
    // ดึงข้อมูลตัวเลือกสินค้า
    $stmt = $conn->prepare("
        SELECT po.*, p.name as product_name, c.name as category_name
        FROM product_options po
        LEFT JOIN products p ON po.product_id = p.product_id
        LEFT JOIN categories c ON p.category_id = c.category_id
        ORDER BY c.name, p.name, po.name
    ");
    $stmt->execute();
    $productOptions = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Error loading product options: " . $e->getMessage());
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">ตัวเลือกสินค้า</h1>
        <p class="text-muted mb-0">จัดการตัวเลือกเพิ่มเติมสำหรับสินค้า เช่น ขนาด รสชาติ หรือส่วนประกอบ</p>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#optionModal" onclick="openAddModal()">
            <i class="fas fa-plus me-2"></i>เพิ่มตัวเลือก
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo count($productOptions); ?></div>
                    <div class="stats-label">ตัวเลือกทั้งหมด</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-cogs"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo count($products); ?></div>
                    <div class="stats-label">สินค้าที่มีตัวเลือกได้</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-cube"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card warning">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo count(array_filter($productOptions, fn($opt) => $opt['price_adjustment'] > 0)); ?></div>
                    <div class="stats-label">ตัวเลือกเสริมราคา</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-plus-circle"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card danger">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo count(array_filter($productOptions, fn($opt) => $opt['price_adjustment'] < 0)); ?></div>
                    <div class="stats-label">ตัวเลือกลดราคา</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-minus-circle"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Product Options Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-list me-2"></i>รายการตัวเลือกสินค้า
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="optionsTable">
                <thead>
                    <tr>
                        <th>หมวดหมู่</th>
                        <th>สินค้า</th>
                        <th>ชื่อตัวเลือก</th>
                        <th>การปรับราคา</th>
                        <th>การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($productOptions as $option): ?>
                        <tr>
                            <td>
                                <span class="badge bg-secondary"><?php echo clean($option['category_name'] ?: 'ไม่มีหมวดหมู่'); ?></span>
                            </td>
                            <td>
                                <div class="fw-semibold"><?php echo clean($option['product_name']); ?></div>
                            </td>
                            <td>
                                <div class="fw-semibold text-primary"><?php echo clean($option['name']); ?></div>
                            </td>
                            <td>
                                <?php if ($option['price_adjustment'] > 0): ?>
                                    <span class="text-success">+<?php echo formatCurrency($option['price_adjustment']); ?></span>
                                <?php elseif ($option['price_adjustment'] < 0): ?>
                                    <span class="text-danger"><?php echo formatCurrency($option['price_adjustment']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">ไม่เปลี่ยนแปลง</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#optionModal"
                                            onclick="openEditModal(<?php echo htmlspecialchars(json_encode($option)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <button class="btn btn-outline-danger" 
                                            onclick="deleteOption(<?php echo $option['option_id']; ?>, '<?php echo clean($option['name']); ?>')">
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

<!-- Option Modal -->
<div class="modal fade" id="optionModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="optionForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="option_id" id="modalOptionId" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">เพิ่มตัวเลือกใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="product_id" class="form-label">สินค้า *</label>
                        <select class="form-select" id="product_id" name="product_id" required>
                            <option value="">เลือกสินค้า</option>
                            <?php 
                            $currentCategory = '';
                            foreach ($products as $product): 
                                if ($product['category_name'] !== $currentCategory):
                                    if ($currentCategory !== '') echo '</optgroup>';
                                    $currentCategory = $product['category_name'];
                                    echo '<optgroup label="' . clean($currentCategory ?: 'ไม่มีหมวดหมู่') . '">';
                                endif;
                            ?>
                                <option value="<?php echo $product['product_id']; ?>">
                                    <?php echo clean($product['name']); ?>
                                </option>
                            <?php endforeach; ?>
                            <?php if ($currentCategory !== '') echo '</optgroup>'; ?>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="name" class="form-label">ชื่อตัวเลือก *</label>
                        <input type="text" class="form-control" id="name" name="name" required 
                               placeholder="เช่น ขนาดใหญ่, เผ็ดน้อย, เพิ่มชีส">
                        <small class="text-muted">ชื่อของตัวเลือกที่ลูกค้าจะเลือกได้</small>
                    </div>
                    
                    <div class="mb-3">
                        <label for="price_adjustment" class="form-label">การปรับราคา (บาท)</label>
                        <input type="number" class="form-control" id="price_adjustment" name="price_adjustment" 
                               step="0.01" value="0" placeholder="0.00">
                        <small class="text-muted">
                            ใส่จำนวนบวกเพื่อเพิ่มราคา หรือลบเพื่อลดราคา (ใส่ 0 หากไม่เปลี่ยนแปลงราคา)
                        </small>
                    </div>
                    
                    <div class="alert alert-info">
                        <i class="fas fa-info-circle me-2"></i>
                        <strong>ตัวอย่าง:</strong>
                        <ul class="mb-0 mt-2">
                            <li>ขนาดใหญ่: +15.00 (เพิ่มราคา 15 บาท)</li>
                            <li>ไม่ใส่น้ำแข็ง: 0.00 (ไม่เปลี่ยนราคา)</li>
                            <li>ส่วนลด: -10.00 (ลดราคา 10 บาท)</li>
                        </ul>
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

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="option_id" id="deleteOptionId">
</form>

<?php
$additionalJS = [
    'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js'
];

$inlineJS = "
// Initialize DataTable
$('#optionsTable').DataTable({
    order: [[0, 'asc'], [1, 'asc'], [2, 'asc']],
    columnDefs: [
        { orderable: false, targets: [4] }
    ],
    language: {
        url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
    }
});

// Open add modal
function openAddModal() {
    $('#modalTitle').text('เพิ่มตัวเลือกใหม่');
    $('#modalAction').val('add');
    $('#modalOptionId').val('');
    $('#optionForm')[0].reset();
    $('#price_adjustment').val('0');
}

// Open edit modal
function openEditModal(option) {
    $('#modalTitle').text('แก้ไขตัวเลือก');
    $('#modalAction').val('edit');
    $('#modalOptionId').val(option.option_id);
    $('#product_id').val(option.product_id);
    $('#name').val(option.name);
    $('#price_adjustment').val(option.price_adjustment);
}

// Delete option
function deleteOption(optionId, optionName) {
    confirmAction('ต้องการลบตัวเลือก \"' + optionName + '\"? การกระทำนี้ไม่สามารถยกเลิกได้', function() {
        $('#deleteOptionId').val(optionId);
        $('#deleteForm').submit();
    });
}

// Form validation
$('#optionForm').on('submit', function(e) {
    const productId = $('#product_id').val();
    const name = $('#name').val().trim();
    
    if (!productId) {
        e.preventDefault();
        alert('กรุณาเลือกสินค้า');
        $('#product_id').focus();
        return false;
    }
    
    if (!name) {
        e.preventDefault();
        alert('กรุณากรอกชื่อตัวเลือก');
        $('#name').focus();
        return false;
    }
});

// Preview price adjustment
$('#price_adjustment').on('input', function() {
    const value = parseFloat($(this).val()) || 0;
    const preview = $(this).next('.text-muted');
    
    if (value > 0) {
        preview.html('เพิ่มราคา ' + formatCurrency(value) + ' <span class=\"text-success\">(+' + formatCurrency(value) + ')</span>');
    } else if (value < 0) {
        preview.html('ลดราคา ' + formatCurrency(Math.abs(value)) + ' <span class=\"text-danger\">(' + formatCurrency(value) + ')</span>');
    } else {
        preview.html('ไม่เปลี่ยนแปลงราคา <span class=\"text-muted\">(+0.00)</span>');
    }
});

console.log('Product Options loaded successfully');
";

require_once '../includes/footer.php';
?>