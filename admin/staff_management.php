<?php
/**
 * จัดการพนักงาน
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

$pageTitle = 'จัดการพนักงาน';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: staff_management.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $userId = $_POST['user_id'] ?? null;
        $username = trim($_POST['username'] ?? '');
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'staff';
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        $workShift = $_POST['work_shift'] ?? '';
        $position = trim($_POST['position'] ?? '');
        $salary = floatval($_POST['salary'] ?? 0);
        
        // Validation
        $errors = [];
        if (empty($username)) $errors[] = 'กรุณากรอกชื่อผู้ใช้';
        if (empty($fullname)) $errors[] = 'กรุณากรอกชื่อเต็ม';
        if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'รูปแบบอีเมลไม่ถูกต้อง';
        if ($action === 'add' && empty($password)) $errors[] = 'กรุณากรอกรหัสผ่าน';
        if (!empty($password) && strlen($password) < 6) $errors[] = 'รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร';
        if (!in_array($role, ['staff', 'kitchen', 'admin'])) $errors[] = 'บทบาทไม่ถูกต้อง';
        
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
                        header('Location: staff_management.php');
                        exit();
                    }
                    
                    // ตรวจสอบอีเมลซ้ำ
                    if (!empty($email)) {
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetchColumn() > 0) {
                            setFlashMessage('error', 'อีเมลนี้มีอยู่แล้ว');
                            header('Location: staff_management.php');
                            exit();
                        }
                    }
                    
                    $conn->beginTransaction();
                    
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("
                        INSERT INTO users (username, password, fullname, email, phone, role, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$username, $hashedPassword, $fullname, $email, $phone, $role, $status]);
                    
                    $newUserId = $conn->lastInsertId();
                    
                    // เพิ่มข้อมูลพนักงาน
                    $stmt = $conn->prepare("
                        INSERT INTO staff_info (user_id, position, work_shift, salary, hire_date) 
                        VALUES (?, ?, ?, ?, CURDATE())
                    ");
                    $stmt->execute([$newUserId, $position, $workShift, $salary]);
                    
                    $conn->commit();
                    
                    setFlashMessage('success', 'เพิ่มพนักงานใหม่สำเร็จ');
                    writeLog("Added new staff: $username by " . getCurrentUser()['username']);
                    
                } elseif ($action === 'edit' && $userId) {
                    // ตรวจสอบชื่อผู้ใช้ซ้ำ (ยกเว้นตัวเอง)
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id != ?");
                    $stmt->execute([$username, $userId]);
                    if ($stmt->fetchColumn() > 0) {
                        setFlashMessage('error', 'ชื่อผู้ใช้นี้มีอยู่แล้ว');
                        header('Location: staff_management.php');
                        exit();
                    }
                    
                    $conn->beginTransaction();
                    
                    if (!empty($password)) {
                        $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                        $stmt = $conn->prepare("
                            UPDATE users 
                            SET username = ?, password = ?, fullname = ?, email = ?, phone = ?, role = ?, status = ?
                            WHERE user_id = ?
                        ");
                        $stmt->execute([$username, $hashedPassword, $fullname, $email, $phone, $role, $status, $userId]);
                    } else {
                        $stmt = $conn->prepare("
                            UPDATE users 
                            SET username = ?, fullname = ?, email = ?, phone = ?, role = ?, status = ?
                            WHERE user_id = ?
                        ");
                        $stmt->execute([$username, $fullname, $email, $phone, $role, $status, $userId]);
                    }
                    
                    // อัปเดตข้อมูลพนักงาน
                    $stmt = $conn->prepare("
                        UPDATE staff_info 
                        SET position = ?, work_shift = ?, salary = ?
                        WHERE user_id = ?
                    ");
                    $stmt->execute([$position, $workShift, $salary, $userId]);
                    
                    $conn->commit();
                    
                    setFlashMessage('success', 'แก้ไขข้อมูลพนักงานสำเร็จ');
                    writeLog("Updated staff ID: $userId by " . getCurrentUser()['username']);
                }
                
            } catch (Exception $e) {
                if (isset($conn)) $conn->rollback();
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error managing staff: " . $e->getMessage());
            }
        } else {
            setFlashMessage('error', implode('<br>', $errors));
        }
    } elseif ($action === 'delete') {
        $userId = intval($_POST['user_id'] ?? 0);
        
        if ($userId && $userId !== getCurrentUserId()) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                $conn->beginTransaction();
                
                // ลบข้อมูลพนักงาน
                $stmt = $conn->prepare("DELETE FROM staff_info WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                // ลบผู้ใช้
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                $conn->commit();
                
                setFlashMessage('success', 'ลบพนักงานสำเร็จ');
                writeLog("Deleted staff ID: $userId by " . getCurrentUser()['username']);
                
            } catch (Exception $e) {
                if (isset($conn)) $conn->rollback();
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error deleting staff: " . $e->getMessage());
            }
        } else {
            setFlashMessage('error', 'ไม่สามารถลบพนักงานนี้ได้');
        }
    }
    
    header('Location: staff_management.php');
    exit();
}

// สร้างตาราง staff_info ถ้าไม่มี
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $conn->exec("
        CREATE TABLE IF NOT EXISTS `staff_info` (
            `staff_id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `position` varchar(100) DEFAULT NULL,
            `work_shift` enum('morning','afternoon','evening','night','full_time') DEFAULT 'full_time',
            `salary` decimal(10,2) DEFAULT 0.00,
            `hire_date` date DEFAULT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`staff_id`),
            UNIQUE KEY `user_id` (`user_id`),
            FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
    ");
    
} catch (Exception $e) {
    writeLog("Error creating staff_info table: " . $e->getMessage());
}

// ดึงข้อมูลพนักงาน
$staff = [];
$stats = ['total' => 0, 'active' => 0, 'admin' => 0, 'staff' => 0, 'kitchen' => 0];

try {
    $stmt = $conn->prepare("
        SELECT u.*, s.position, s.work_shift, s.salary, s.hire_date
        FROM users u 
        LEFT JOIN staff_info s ON u.user_id = s.user_id
        WHERE u.role IN ('admin', 'staff', 'kitchen')
        ORDER BY u.created_at DESC
    ");
    $stmt->execute();
    $staff = $stmt->fetchAll();
    
    // สถิติพนักงาน
    foreach ($staff as $member) {
        $stats['total']++;
        if ($member['status'] === 'active') $stats['active']++;
        $stats[$member['role']]++;
    }
    
} catch (Exception $e) {
    writeLog("Error loading staff: " . $e->getMessage());
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">จัดการพนักงาน</h1>
        <p class="text-muted mb-0">เพิ่ม แก้ไข และจัดการข้อมูลพนักงานในระบบ</p>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#staffModal" onclick="openAddModal()">
            <i class="fas fa-plus me-2"></i>เพิ่มพนักงาน
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
                    <div class="stats-label">พนักงานทั้งหมด</div>
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
                    <div class="stats-label">พนักงานที่ทำงาน</div>
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
                    <div class="stats-number"><?php echo number_format($stats['staff']); ?></div>
                    <div class="stats-label">พนักงานขาย</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card danger">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['kitchen']); ?></div>
                    <div class="stats-label">พนักงานครัว</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-utensils"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Staff Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-list me-2"></i>รายการพนักงาน
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="staffTable">
                <thead>
                    <tr>
                        <th>ชื่อผู้ใช้</th>
                        <th>ชื่อเต็ม</th>
                        <th>ตำแหน่ง</th>
                        <th>บทบาท</th>
                        <th>กะการทำงาน</th>
                        <th>เงินเดือน</th>
                        <th>สถานะ</th>
                        <th>วันที่เริ่มงาน</th>
                        <th>การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($staff as $member): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar me-2">
                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                                             style="width: 35px; height: 35px;">
                                            <?php echo strtoupper(substr($member['fullname'], 0, 1)); ?>
                                        </div>
                                    </div>
                                    <div>
                                        <strong><?php echo clean($member['username']); ?></strong>
                                        <?php if ($member['email']): ?>
                                            <br><small class="text-muted"><?php echo clean($member['email']); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div><?php echo clean($member['fullname']); ?></div>
                                <?php if ($member['phone']): ?>
                                    <small class="text-muted"><?php echo clean($member['phone']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($member['position']): ?>
                                    <span class="badge bg-secondary"><?php echo clean($member['position']); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $roleClass = '';
                                $roleText = '';
                                switch ($member['role']) {
                                    case 'admin':
                                        $roleClass = 'bg-danger';
                                        $roleText = 'ผู้ดูแลระบบ';
                                        break;
                                    case 'staff':
                                        $roleClass = 'bg-success';
                                        $roleText = 'พนักงานขาย';
                                        break;
                                    case 'kitchen':
                                        $roleClass = 'bg-warning';
                                        $roleText = 'พนักงานครัว';
                                        break;
                                }
                                ?>
                                <span class="badge <?php echo $roleClass; ?>"><?php echo $roleText; ?></span>
                            </td>
                            <td>
                                <?php
                                $shiftMap = [
                                    'morning' => 'เช้า',
                                    'afternoon' => 'บ่าย', 
                                    'evening' => 'เย็น',
                                    'night' => 'กลางคืน',
                                    'full_time' => 'เต็มเวลา'
                                ];
                                echo $shiftMap[$member['work_shift']] ?? '-';
                                ?>
                            </td>
                            <td>
                                <?php if ($member['salary'] > 0): ?>
                                    <strong><?php echo formatCurrency($member['salary']); ?></strong>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($member['status'] === 'active'): ?>
                                    <span class="badge bg-success">ทำงาน</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">ลาออก</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($member['hire_date']): ?>
                                    <?php echo formatDate($member['hire_date'], 'd/m/Y'); ?>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#staffModal"
                                            onclick="openEditModal(<?php echo htmlspecialchars(json_encode($member)); ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($member['user_id'] !== getCurrentUserId()): ?>
                                        <button class="btn btn-outline-danger" 
                                                onclick="deleteStaff(<?php echo $member['user_id']; ?>, '<?php echo clean($member['fullname']); ?>')">
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

<!-- Staff Modal -->
<div class="modal fade" id="staffModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST" id="staffForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="user_id" id="modalUserId" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">เพิ่มพนักงานใหม่</h5>
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
                                <label for="role" class="form-label">บทบาท *</label>
                                <select class="form-select" id="role" name="role" required>
                                    <option value="staff">พนักงานขาย</option>
                                    <option value="kitchen">พนักงานครัว</option>
                                    <option value="admin">ผู้ดูแลระบบ</option>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="status" class="form-label">สถานะ</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="active">ทำงาน</option>
                                    <option value="inactive">ลาออก</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="position" class="form-label">ตำแหน่ง</label>
                                <input type="text" class="form-control" id="position" name="position" 
                                       placeholder="เช่น พนักงานขาย, หัวหน้าครัว">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="work_shift" class="form-label">กะการทำงาน</label>
                                <select class="form-select" id="work_shift" name="work_shift">
                                    <option value="full_time">เต็มเวลา</option>
                                    <option value="morning">กะเช้า</option>
                                    <option value="afternoon">กะบ่าย</option>
                                    <option value="evening">กะเย็น</option>
                                    <option value="night">กะกลางคืน</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="salary" class="form-label">เงินเดือน (บาท)</label>
                                <input type="number" class="form-control" id="salary" name="salary" step="0.01" min="0">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="password" class="form-label">รหัสผ่าน <span id="passwordRequired">*</span></label>
                                <input type="password" class="form-control" id="password" name="password">
                                <small class="text-muted" id="passwordHelp">อย่างน้อย 6 ตัวอักษร</small>
                            </div>
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
$('#staffTable').DataTable({
    order: [[0, 'asc']],
    columnDefs: [
        { orderable: false, targets: [8] }
    ],
    language: {
        url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
    }
});

// Open add modal
function openAddModal() {
    $('#modalTitle').text('เพิ่มพนักงานใหม่');
    $('#modalAction').val('add');
    $('#modalUserId').val('');
    $('#staffForm')[0].reset();
    $('#passwordRequired').show();
    $('#passwordHelp').text('อย่างน้อย 6 ตัวอักษร');
    $('#password').prop('required', true);
}

// Open edit modal
function openEditModal(staff) {
    $('#modalTitle').text('แก้ไขข้อมูลพนักงาน');
    $('#modalAction').val('edit');
    $('#modalUserId').val(staff.user_id);
    $('#passwordRequired').hide();
    $('#passwordHelp').text('เว้นว่างไว้หากไม่ต้องการเปลี่ยน');
    $('#password').prop('required', false);
    
    $('#username').val(staff.username);
    $('#fullname').val(staff.fullname);
    $('#email').val(staff.email);
    $('#phone').val(staff.phone);
    $('#role').val(staff.role);
    $('#status').val(staff.status);
    $('#position').val(staff.position);
    $('#work_shift').val(staff.work_shift);
    $('#salary').val(staff.salary);
    $('#password').val('');
}

// Delete staff
function deleteStaff(userId, fullname) {
    confirmAction('ต้องการลบพนักงาน \"' + fullname + '\"? การกระทำนี้ไม่สามารถยกเลิกได้', function() {
        $('#deleteUserId').val(userId);
        $('#deleteForm').submit();
    });
}

console.log('Staff Management loaded successfully');
";

require_once '../includes/footer.php';
?>