<?php
/**
 * จัดการลูกค้า
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

$pageTitle = 'จัดการลูกค้า';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: customer_management.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $userId = $_POST['user_id'] ?? null;
        $username = trim($_POST['username'] ?? '');
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        $lineUserId = trim($_POST['line_user_id'] ?? '');
        
        // Validation
        $errors = [];
        if (empty($username)) $errors[] = 'กรุณากรอกชื่อผู้ใช้';
        if (empty($fullname)) $errors[] = 'กรุณากรอกชื่อเต็ม';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
        if ($action === 'add' && empty($password)) $errors[] = 'กรุณากรอกรหัสผ่าน';
        if (!empty($password) && strlen($password) < 6) $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        
        if (empty($errors)) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                if ($action === 'add') {
                    // ตรวจสอบชื่อผู้ใช้ซ้ำ
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ?");
                    $stmt->execute([$username]);
                    if ($stmt->fetchColumn() > 0) {
                        setFlashMessage('error', 'ชื่อผู้ใช้นี้มีอยู่แล้ว');
                        header('Location: customer_management.php');
                        exit();
                    }
                    
                    // ตรวจสอบอีเมลซ้ำ
                    if (!empty($email)) {
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetchColumn() > 0) {
                            setFlashMessage('error', 'อีเมลนี้มีอยู่แล้ว');
                            header('Location: customer_management.php');
                            exit();
                        }
                    }
                    
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("
                        INSERT INTO users (username, password, fullname, email, phone, role, line_user_id, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, 'customer', ?, ?, NOW())
                    ");
                    $stmt->execute([$username, $hashedPassword, $fullname, $email, $phone, $lineUserId, $status]);
                    
                    setFlashMessage('success', 'เพิ่มลูกค้าใหม่สำเร็จ');
                    writeLog("Added new customer: $username by " . getCurrentUser()['username']);
                    
                } elseif ($action === 'edit' && $userId) {
                    // ตรวจสอบชื่อผู้ใช้ซ้ำ (ยกเว้นตัวเอง)
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id != ?");
                    $stmt->execute([$username, $userId]);
                    if ($stmt->fetchColumn() > 0) {
                        setFlashMessage('error', 'ชื่อผู้ใช้นี้มีอยู่แล้ว');
                        header('Location: customer_management.php');
                        exit();
                    }
                    
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("
                            UPDATE users 
                            SET username = ?, password = ?, fullname = ?, email = ?, phone = ?, line_user_id = ?, status = ?
                            WHERE user_id = ? AND role = 'customer'
                        ");
                        $stmt->execute([$username, $hashedPassword, $fullname, $email, $phone, $lineUserId, $status, $userId]);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE users 
                            SET username = ?, fullname = ?, email = ?, phone = ?, line_user_id = ?, status = ?
                            WHERE user_id = ? AND role = 'customer'
                        ");
                        $stmt->execute([$username, $fullname, $email, $phone, $lineUserId, $status, $userId]);
                    }
                    
                    setFlashMessage('success', 'แก้ไขข้อมูลลูกค้าสำเร็จ');
                    writeLog("Updated customer ID: $userId by " . getCurrentUser()['username']);
                }
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error managing customer: " . $e->getMessage());
            }
        } else {
            setFlashMessage('error', implode('<br>', $errors));
        }
    } elseif ($action === 'delete') {
        $userId = intval($_POST['user_id'] ?? 0);
        
        if ($userId) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                // ตรวจสอบว่ามีออเดอร์หรือไม่
                $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE user_id = ?");
                $stmt->execute([$userId]);
                $orderCount = $stmt->fetchColumn();
                
                if ($orderCount > 0) {
                    setFlashMessage('error', 'ไม่สามารถลบลูกค้าที่มีประวัติการสั่งซื้อได้');
                } else {
                    $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ? AND role = 'customer'");
                    $stmt->execute([$userId]);
                    
                    setFlashMessage('success', 'ลบลูกค้าสำเร็จ');
                    writeLog("Deleted customer ID: $userId by " . getCurrentUser()['username']);
                }
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error deleting customer: " . $e->getMessage());
            }
        }
    }
    
    header('Location: customer_management.php');
    exit();
}

// ดึงข้อมูลลูกค้า
$customers = [];
$stats = [
    'total' => 0, 
    'active' => 0, 
    'with_line' => 0, 
    'with_orders' => 0,
    'new_this_month' => 0
];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงข้อมูลลูกค้าพร้อมสถิติ
    $stmt = $conn->prepare("
        SELECT u.*, 
               COUNT(o.order_id) as total_orders,
               COALESCE(SUM(o.total_price), 0) as total_spent,
               MAX(o.created_at) as last_order_date
        FROM users u
        LEFT JOIN orders o ON u.user_id = o.user_id AND o.payment_status = 'paid'
        WHERE u.role = 'customer'
        GROUP BY u.user_id
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $customers = $stmt->fetchAll();
    
    // สถิติลูกค้า
    foreach ($customers as $customer) {
        $stats['total']++;
        if ($customer['status'] === 'active') $stats['active']++;
        if (!empty($customer['line_user_id'])) $stats['with_line']++;
        if ($customer['total_orders'] > 0) $stats['with_orders']++;
        
        // ลูกค้าใหม่เดือนนี้
        $createdMonth = date('Y-m', strtotime($customer['created_at']));
        $currentMonth = date('Y-m');
        if ($createdMonth === $currentMonth) $stats['new_this_month']++;
    }
    
} catch (Exception $e) {
    writeLog("Error loading customers: " . $e->getMessage());
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">จัดการลูกค้า</h1>
        <p class="text-muted mb-0">ดูและจัดการข้อมูลลูกค้าทั้งหมดในระบบ</p>
    </div>
    <div>
        <button class="btn btn-success me-2" onclick="exportCustomers()">
            <i class="fas fa-download me-2"></i>ส่งออก
        </button>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#customerModal" onclick="openAddModal()">
            <i class="fas fa-plus me-2"></i>เพิ่มลูกค้า
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['total']); ?></div>
                    <div class="stats-label">ลูกค้าทั้งหมด</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['active']); ?></div>
                    <div class="stats-label">ลูกค้าที่ยังใช้งาน</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-user-check"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card warning">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['with_line']); ?></div>
                    <div class="stats-label">เชื่อมต่อ LINE</div>
                </div>
                <div class="stats-icon">
                    <i class="fab fa-line"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card primary">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['new_this_month']); ?></div>
                    <div class="stats-label">ลูกค้าใหม่เดือนนี้</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-user-plus"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Customer Filters -->
<div class="card mb-4">
    <div class="card-body">
        <div class="row g-3">
            <div class="col-md-3">
                <label class="form-label">สถานะ</label>
                <select class="form-select" id="filterStatus">
                    <option value="">ทั้งหมด</option>
                    <option value="active">ใช้งาน</option>
                    <option value="inactive">ระงับ</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">LINE OA</label>
                <select class="form-select" id="filterLine">
                    <option value="">ทั้งหมด</option>
                    <option value="connected">เชื่อมต่อแล้ว</option>
                    <option value="not_connected">ยังไม่เชื่อมต่อ</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">ประวัติสั่งซื้อ</label>
                <select class="form-select" id="filterOrders">
                    <option value="">ทั้งหมด</option>
                    <option value="has_orders">มีประวัติสั่งซื้อ</option>
                    <option value="no_orders">ไม่มีประวัติ</option>
                </select>
            </div>
            <div class="col-md-3">
                <label class="form-label">&nbsp;</label>
                <button class="btn btn-outline-secondary w-100" onclick="clearFilters()">
                    <i class="fas fa-times me-2"></i>ล้างตัวกรอง
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Customers Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-list me-2"></i>รายการลูกค้า
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="customersTable">
                <thead>
                    <tr>
                        <th>ลูกค้า</th>
                        <th>ข้อมูลติดต่อ</th>
                        <th>LINE OA</th>
                        <th>จำนวนออเดอร์</th>
                        <th>ยอดซื้อรวม</th>
                        <th>ซื้อครั้งล่าสุด</th>
                        <th>สถานะ</th>
                        <th>วันที่สมัคร</th>
                        <th>การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($customers as $customer): ?>
                        <tr data-status="<?php echo $customer['status']; ?>" 
                            data-line="<?php echo !empty($customer['line_user_id']) ? 'connected' : 'not_connected'; ?>"
                            data-orders="<?php echo $customer['total_orders'] > 0 ? 'has_orders' : 'no_orders'; ?>">
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar me-3">
                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                                             style="width: 40px; height: 40px;">
                                            <?php echo strtoupper(substr($customer['fullname'], 0, 2)); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <div class="fw-semibold"><?php echo clean($customer['fullname']); ?></div>
                                        <small class="text-muted">@<?php echo clean($customer['username']); ?></small>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <?php if ($customer['email']): ?>
                                    <div><i class="fas fa-envelope text-muted me-1"></i><?php echo clean($customer['email']); ?></div>
                                <?php endif; ?>
                                <?php if ($customer['phone']): ?>
                                    <div><i class="fas fa-phone text-muted me-1"></i><?php echo clean($customer['phone']); ?></div>
                                <?php else: ?>
                                    <small class="text-muted">ไม่มีข้อมูลติดต่อ</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if (!empty($customer['line_user_id'])): ?>
                                    <span class="badge bg-success">
                                        <i class="fab fa-line me-1"></i>เชื่อมต่อแล้ว
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">ยังไม่เชื่อมต่อ</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($customer['total_orders'] > 0): ?>
                                    <strong class="text-primary"><?php echo number_format($customer['total_orders']); ?></strong> ครั้ง
                                <?php else: ?>
                                    <span class="text-muted">ยังไม่เคยสั่ง</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($customer['total_spent'] > 0): ?>
                                    <strong class="text-success"><?php echo formatCurrency($customer['total_spent']); ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($customer['last_order_date']): ?>
                                    <div><?php echo formatDate($customer['last_order_date'], 'd/m/Y'); ?></div>
                                    <small class="text-muted"><?php echo formatDate($customer['last_order_date'], 'H:i'); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($customer['status'] === 'active'): ?>
                                    <span class="badge bg-success">ใช้งาน</span>
                                <?php else: ?>
                                    <span class="badge bg-danger">ระงับ</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo formatDate($customer['created_at'], 'd/m/Y'); ?></div>
                                <small class="text-muted"><?php echo formatDate($customer['created_at'], 'H:i'); ?></small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-info" 
                                            onclick="viewCustomerDetails(<?php echo $customer['user_id']; ?>)"
                                            data-bs-toggle="modal" 
                                            data-bs-target="#customerDetailsModal">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <button class="btn btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#customerModal"
                                            onclick="openEditModal(<?php echo htmlspecialchars(json_encode($customer)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($customer['total_orders'] == 0): ?>
                                        <button class="btn btn-outline-danger" 
                                                onclick="deleteCustomer(<?php echo $customer['user_id']; ?>, '<?php echo clean($customer['fullname']); ?>')">
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

<!-- Customer Modal -->
<div class="modal fade" id="customerModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="customerForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="user_id" id="modalUserId" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">เพิ่มลูกค้าใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="username" class="form-label">ชื่อผู้ใช้ *</label>
                                <input type="text" class="form-control" id="username" name="username" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="fullname" class="form-label">ชื่อเต็ม *</label>
                                <input type="text" class="form-control" id="fullname" name="fullname" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="email" class="form-label">อีเมล</label>
                                <input type="email" class="form-control" id="email" name="email">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="phone" class="form-label">เบอร์โทร</label>
                                <input type="tel" class="form-control" id="phone" name="phone">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="line_user_id" class="form-label">LINE User ID</label>
                                <input type="text" class="form-control" id="line_user_id" name="line_user_id" 
                                       placeholder="U1234567890abcdef1234567890abcdef">
                                <small class="text-muted">ID ของผู้ใช้ใน LINE OA</small>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">สถานะ</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active">ใช้งาน</option>
                                    <option value="inactive">ระงับ</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">รหัสผ่าน <span id="passwordRequired">*</span></label>
                        <input type="password" class="form-control" id="password" name="password">
                        <small class="text-muted" id="passwordHelp">อย่างน้อย 6 ตัวอักษร</small>
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

<!-- Customer Details Modal -->
<div class="modal fade" id="customerDetailsModal" tabindex="-1">
    <div class="modal-dialog modal-xl">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">รายละเอียดลูกค้า</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="customerDetailsBody">
                <div class="text-center">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2">กำลังโหลด...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Delete Form -->
<form id="deleteForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="user_id" id="deleteUserId">
</form>

<?php
$additionalJS = [
    'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js'
];

$inlineJS = "
// Initialize DataTable
let customersTable = $('#customersTable').DataTable({
    order: [[7, 'desc']],
    columnDefs: [
        { orderable: false, targets: [8] }
    ],
    language: {
        url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
    }
});

// Filters
$('#filterStatus, #filterLine, #filterOrders').on('change', function() {
    applyFilters();
});

function applyFilters() {
    const status = $('#filterStatus').val();
    const line = $('#filterLine').val();
    const orders = $('#filterOrders').val();
    
    customersTable.rows().every(function() {
        const row = this.node();
        let show = true;
        
        if (status && $(row).data('status') !== status) {
            show = false;
        }
        
        if (line && $(row).data('line') !== line) {
            show = false;
        }
        
        if (orders && $(row).data('orders') !== orders) {
            show = false;
        }
        
        if (show) {
            $(row).removeClass('d-none');
        } else {
            $(row).addClass('d-none');
        }
    });
    
    customersTable.draw();
}

function clearFilters() {
    $('#filterStatus, #filterLine, #filterOrders').val('');
    customersTable.rows().every(function() {
        $(this.node()).removeClass('d-none');
    });
    customersTable.draw();
}

// Open add modal
function openAddModal() {
    $('#modalTitle').text('เพิ่มลูกค้าใหม่');
    $('#modalAction').val('add');
    $('#modalUserId').val('');
    $('#customerForm')[0].reset();
    $('#passwordRequired').show();
    $('#passwordHelp').text('อย่างน้อย 6 ตัวอักษร');
    $('#password').prop('required', true);
}

// Open edit modal
function openEditModal(customer) {
    $('#modalTitle').text('แก้ไขข้อมูลลูกค้า');
    $('#modalAction').val('edit');
    $('#modalUserId').val(customer.user_id);
    $('#passwordRequired').hide();
    $('#passwordHelp').text('เว้นว่างไว้หากไม่ต้องการเปลี่ยน');
    $('#password').prop('required', false);
    
    $('#username').val(customer.username);
    $('#fullname').val(customer.fullname);
    $('#email').val(customer.email);
    $('#phone').val(customer.phone);
    $('#line_user_id').val(customer.line_user_id);
    $('#status').val(customer.status);
    $('#password').val('');
}

// View customer details
function viewCustomerDetails(customerId) {
    $('#customerDetailsBody').html('<div class=\"text-center\"><div class=\"spinner-border\" role=\"status\"></div><p class=\"mt-2\">กำลังโหลด...</p></div>');
    
    $.get(SITE_URL + '/api/customers.php?action=get_details&id=' + customerId, function(response) {
        if (response.success) {
            const customer = response.customer;
            const orders = response.orders;
            
            let html = '<div class=\"row\">';
            
            // Customer Info
            html += '<div class=\"col-md-4\">';
            html += '<div class=\"card\">';
            html += '<div class=\"card-header\"><h6>ข้อมูลลูกค้า</h6></div>';
            html += '<div class=\"card-body\">';
            html += '<div class=\"text-center mb-3\">';
            html += '<div class=\"bg-primary text-white rounded-circle d-inline-flex align-items-center justify-content-center\" style=\"width: 80px; height: 80px; font-size: 2rem;\">';
            html += customer.fullname.substring(0, 2).toUpperCase();
            html += '</div>';
            html += '<h5 class=\"mt-2\">' + customer.fullname + '</h5>';
            html += '<p class=\"text-muted\">@' + customer.username + '</p>';
            html += '</div>';
            
            if (customer.email) html += '<p><i class=\"fas fa-envelope me-2\"></i>' + customer.email + '</p>';
            if (customer.phone) html += '<p><i class=\"fas fa-phone me-2\"></i>' + customer.phone + '</p>';
            if (customer.line_user_id) html += '<p><i class=\"fab fa-line me-2\"></i>เชื่อมต่อ LINE แล้ว</p>';
            
            html += '<p><i class=\"fas fa-calendar me-2\"></i>สมัครเมื่อ ' + formatDate(customer.created_at) + '</p>';
            html += '<p><i class=\"fas fa-user-clock me-2\"></i>เข้าสู่ระบบล่าสุด ' + (customer.last_login ? formatDate(customer.last_login) : 'ยังไม่เคยเข้าสู่ระบบ') + '</p>';
            
            html += '</div></div></div>';
            
            // Order Statistics
            html += '<div class=\"col-md-8\">';
            html += '<div class=\"card\">';
            html += '<div class=\"card-header\"><h6>สถิติการสั่งซื้อ</h6></div>';
            html += '<div class=\"card-body\">';
            
            html += '<div class=\"row text-center mb-3\">';
            html += '<div class=\"col-4\"><div class=\"h4 text-primary\">' + customer.total_orders + '</div><small>ออเดอร์ทั้งหมด</small></div>';
            html += '<div class=\"col-4\"><div class=\"h4 text-success\">' + formatCurrency(customer.total_spent) + '</div><small>ยอดซื้อรวม</small></div>';
            html += '<div class=\"col-4\"><div class=\"h4 text-info\">' + formatCurrency(customer.avg_order_value) + '</div><small>ยอดเฉลี่ยต่อออเดอร์</small></div>';
            html += '</div>';
            
            if (orders && orders.length > 0) {
                html += '<h6>ออเดอร์ล่าสุด</h6>';
                html += '<div class=\"table-responsive\">';
                html += '<table class=\"table table-sm\">';
                html += '<thead><tr><th>วันที่</th><th>หมายเลข</th><th>ยอดเงิน</th><th>สถานะ</th></tr></thead>';
                html += '<tbody>';
                
                orders.slice(0, 5).forEach(function(order) {
                    html += '<tr>';
                    html += '<td>' + formatDate(order.created_at) + '</td>';
                    html += '<td>' + (order.queue_number || 'ORD-' + order.order_id) + '</td>';
                    html += '<td>' + formatCurrency(order.total_price) + '</td>';
                    html += '<td><span class=\"badge ' + getOrderStatusClass(order.status) + '\">' + getOrderStatusText(order.status) + '</span></td>';
                    html += '</tr>';
                });
                
                html += '</tbody></table>';
                html += '</div>';
                
                if (orders.length > 5) {
                    html += '<p class=\"text-center\"><a href=\"order_management.php?customer=' + customerId + '\" target=\"_blank\">ดูออเดอร์ทั้งหมด</a></p>';
                }
            } else {
                html += '<div class=\"text-center text-muted py-4\">';
                html += '<i class=\"fas fa-shopping-cart fa-3x mb-3\"></i>';
                html += '<p>ยังไม่มีประวัติการสั่งซื้อ</p>';
                html += '</div>';
            }
            
            html += '</div></div></div>';
            html += '</div>';
            
            $('#customerDetailsBody').html(html);
        } else {
            $('#customerDetailsBody').html('<div class=\"alert alert-danger\">ไม่สามารถโหลดข้อมูลได้</div>');
        }
    }).fail(function() {
        $('#customerDetailsBody').html('<div class=\"alert alert-danger\">เกิดข้อผิดพลาดในการโหลดข้อมูล</div>');
    });
}

// Delete customer
function deleteCustomer(userId, fullname) {
    confirmAction('ต้องการลบลูกค้า \"' + fullname + '\"? การกระทำนี้ไม่สามารถยกเลิกได้', function() {
        $('#deleteUserId').val(userId);
        $('#deleteForm').submit();
    });
}

// Export customers
function exportCustomers() {
    window.open(SITE_URL + '/api/export_customers.php', '_blank');
}

// Helper functions
function getOrderStatusText(status) {
    const statusMap = {
        'pending': 'รอยืนยัน',
        'confirmed': 'ยืนยันแล้ว',
        'preparing': 'กำลังเตรียม',
        'ready': 'พร้อมเสิร์ฟ',
        'completed': 'เสร็จสิ้น',
        'cancelled': 'ยกเลิก'
    };
    return statusMap[status] || status;
}

function getOrderStatusClass(status) {
    const classMap = {
        'pending': 'bg-warning',
        'confirmed': 'bg-info',
        'preparing': 'bg-primary',
        'ready': 'bg-success',
        'completed': 'bg-secondary',
        'cancelled': 'bg-danger'
    };
    return classMap[status] || 'bg-secondary';
}

console.log('Customer Management loaded successfully');
";

require_once '../includes/footer.php';
?>