<?php
/**
 * จัดการผู้ใช้
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

$pageTitle = 'จัดการผู้ใช้';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: user_management.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'edit') {
        $userId = $_POST['user_id'] ?? null;
        $username = trim($_POST['username'] ?? '');
        $fullname = trim($_POST['fullname'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $role = $_POST['role'] ?? 'customer';
        $status = $_POST['status'] ?? 'active';
        $password = $_POST['password'] ?? '';
        
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
                        header('Location: user_management.php');
                        exit();
                    }
                    
                    // ตรวจสอบอีเมลซ้ำ
                    if (!empty($email)) {
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ?");
                        $stmt->execute([$email]);
                        if ($stmt->fetchColumn() > 0) {
                            setFlashMessage('error', 'อีเมลนี้มีอยู่แล้ว');
                            header('Location: user_management.php');
                            exit();
                        }
                    }
                    
                    $hashedPassword = password_hash($password, PASSWORD_DEFAULT);
                    $stmt = $conn->prepare("
                        INSERT INTO users (username, password, fullname, email, phone, role, status, created_at) 
                        VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$username, $hashedPassword, $fullname, $email, $phone, $role, $status]);
                    
                    setFlashMessage('success', 'เพิ่มผู้ใช้ใหม่สำเร็จ');
                    writeLog("Added new user: $username by " . getCurrentUser()['username']);
                    
                } elseif ($action === 'edit' && $userId) {
                    // ตรวจสอบชื่อผู้ใช้ซ้ำ (ยกเว้นตัวเอง)
                    $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE username = ? AND user_id != ?");
                    $stmt->execute([$username, $userId]);
                    if ($stmt->fetchColumn() > 0) {
                        setFlashMessage('error', 'ชื่อผู้ใช้นี้มีอยู่แล้ว');
                        header('Location: user_management.php');
                        exit();
                    }
                    
                    // ตรวจสอบอีเมลซ้ำ (ยกเว้นตัวเอง)
                    if (!empty($email)) {
                        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND user_id != ?");
                        $stmt->execute([$email, $userId]);
                        if ($stmt->fetchColumn() > 0) {
                            setFlashMessage('error', 'อีเมลนี้มีอยู่แล้ว');
                            header('Location: user_management.php');
                            exit();
                        }
                    }
                    
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
                    
                    setFlashMessage('success', 'แก้ไขข้อมูลผู้ใช้สำเร็จ');
                    writeLog("Updated user ID: $userId by " . getCurrentUser()['username']);
                }
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error managing user: " . $e->getMessage());
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
                
                $stmt = $conn->prepare("DELETE FROM users WHERE user_id = ?");
                $stmt->execute([$userId]);
                
                setFlashMessage('success', 'ลบผู้ใช้สำเร็จ');
                writeLog("Deleted user ID: $userId by " . getCurrentUser()['username']);
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error deleting user: " . $e->getMessage());
            }
        } else {
            setFlashMessage('error', 'ไม่สามารถลบผู้ใช้นี้ได้');
        }
    }
    
    header('Location: user_management.php');
    exit();
}

// ดึงข้อมูลผู้ใช้
$users = [];
$stats = ['total' => 0, 'admin' => 0, 'staff' => 0, 'customer' => 0];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("SELECT * FROM users ORDER BY created_at DESC");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    // สถิติผู้ใช้
    foreach ($users as $user) {
        $stats['total']++;
        $stats[$user['role']]++;
    }
    
} catch (Exception $e) {
    writeLog("Error loading users: " . $e->getMessage());
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">จัดการผู้ใช้</h1>
        <p class="text-muted mb-0">เพิ่ม แก้ไข และจัดการผู้ใช้ในระบบ</p>
    </div>
    <div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#userModal" onclick="openAddModal()">
            <i class="fas fa-plus me-2"></i>เพิ่มผู้ใช้
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
                    <div class="stats-label">ผู้ใช้ทั้งหมด</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card danger">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['admin']); ?></div>
                    <div class="stats-label">ผู้ดูแลระบบ</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-user-shield"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['staff']); ?></div>
                    <div class="stats-label">พนักงาน</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-user-tie"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card warning">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['customer']); ?></div>
                    <div class="stats-label">ลูกค้า</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-user-check"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Users Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-list me-2"></i>รายการผู้ใช้
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="usersTable">
                <thead>
                    <tr>
                        <th>ชื่อผู้ใช้</th>
                        <th>ชื่อเต็ม</th>
                        <th>อีเมล</th>
                        <th>เบอร์โทร</th>
                        <th>บทบาท</th>
                        <th>สถานะ</th>
                        <th>เข้าสู่ระบบล่าสุด</th>
                        <th>การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-items-center">
                                    <div class="avatar me-2">
                                        <div class="bg-primary text-white rounded-circle d-flex align-items-center justify-content-center" 
                                             style="width: 35px; height: 35px;">
                                            <?php echo strtoupper(substr($user['fullname'], 0, 1)); ?>
                                        </div>
                                    </div>
                                    <strong><?php echo clean($user['username']); ?></strong>
                                </div>
                            </td>
                            <td><?php echo clean($user['fullname']); ?></td>
                            <td>
                                <?php if ($user['email']): ?>
                                    <a href="mailto:<?php echo clean($user['email']); ?>"><?php echo clean($user['email']); ?></a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['phone']): ?>
                                    <a href="tel:<?php echo clean($user['phone']); ?>"><?php echo clean($user['phone']); ?></a>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $roleClass = '';
                                $roleText = '';
                                switch ($user['role']) {
                                    case 'admin':
                                        $roleClass = 'bg-danger';
                                        $roleText = 'ผู้ดูแลระบบ';
                                        break;
                                    case 'staff':
                                        $roleClass = 'bg-success';
                                        $roleText = 'พนักงาน';
                                        break;
                                    case 'kitchen':
                                        $roleClass = 'bg-warning';
                                        $roleText = 'ครัว';
                                        break;
                                    case 'customer':
                                        $roleClass = 'bg-info';
                                        $roleText = 'ลูกค้า';
                                        break;
                                }
                                ?>
                                <span class="badge <?php echo $roleClass; ?>"><?php echo $roleText; ?></span>
                            </td>
                            <td>
                                <?php if ($user['status'] === 'active'): ?>
                                    <span class="badge bg-success">ใช้งาน</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">ระงับ</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($user['last_login']): ?>
                                    <div><?php echo formatDate($user['last_login'], 'd/m/Y'); ?></div>
                                    <small class="text-muted"><?php echo formatDate($user['last_login'], 'H:i'); ?></small>
                                <?php else: ?>
                                    <span class="text-muted">ยังไม่เคยเข้าสู่ระบบ</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" 
                                            data-bs-toggle="modal" 
                                            data-bs-target="#userModal"
                                            onclick="openEditModal(<?php echo $user['user_id']; ?>)">
                                        <i class="fas fa-edit"></i>
                                    </button>
                                    <?php if ($user['user_id'] !== getCurrentUserId()): ?>
                                        <button class="btn btn-outline-danger" 
                                                onclick="deleteUser(<?php echo $user['user_id']; ?>, '<?php echo clean($user['username']); ?>')">
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

<!-- User Modal -->
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="userForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" id="modalAction" value="add">
                <input type="hidden" name="user_id" id="modalUserId" value="">
                
                <div class="modal-header">
                    <h5 class="modal-title" id="modalTitle">เพิ่มผู้ใช้ใหม่</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="username" class="form-label">ชื่อผู้ใช้ *</label>
                        <input type="text" class="form-control" id="username" name="username" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="fullname" class="form-label">ชื่อเต็ม *</label>
                        <input type="text" class="form-control" id="fullname" name="fullname" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="email" class="form-label">อีเมล</label>
                        <input type="email" class="form-control" id="email" name="email">
                    </div>
                    
                    <div class="mb-3">
                        <label for="phone" class="form-label">เบอร์โทร</label>
                        <input type="tel" class="form-control" id="phone" name="phone">
                    </div>
                    
                    <div class="mb-3">
                        <label for="role" class="form-label">บทบาท *</label>
                        <select class="form-select" id="role" name="role" required>
                            <option value="customer">ลูกค้า</option>
                            <option value="staff">พนักงาน</option>
                            <option value="kitchen">ครัว</option>
                            <option value="admin">ผู้ดูแลระบบ</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="status" class="form-label">สถานะ</label>
                        <select class="form-select" id="status" name="status">
                            <option value="active">ใช้งาน</option>
                            <option value="inactive">ระงับ</option>
                        </select>
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
$('#usersTable').DataTable({
    order: [[0, 'asc']],
    columnDefs: [
        { orderable: false, targets: [7] }
    ],
    language: {
        url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
    }
});

// Open add modal
function openAddModal() {
    $('#modalTitle').text('เพิ่มผู้ใช้ใหม่');
    $('#modalAction').val('add');
    $('#modalUserId').val('');
    $('#userForm')[0].reset();
    $('#passwordRequired').show();
    $('#passwordHelp').text('อย่างน้อย 6 ตัวอักษร');
    $('#password').prop('required', true);
}

// Open edit modal
function openEditModal(userId) {
    $('#modalTitle').text('แก้ไขข้อมูลผู้ใช้');
    $('#modalAction').val('edit');
    $('#modalUserId').val(userId);
    $('#passwordRequired').hide();
    $('#passwordHelp').text('เว้นว่างไว้หากไม่ต้องการเปลี่ยน');
    $('#password').prop('required', false);
    
    // Load user data
    const users = " . json_encode($users) . ";
    const user = users.find(u => u.user_id == userId);
    
    if (user) {
        $('#username').val(user.username);
        $('#fullname').val(user.fullname);
        $('#email').val(user.email);
        $('#phone').val(user.phone);
        $('#role').val(user.role);
        $('#status').val(user.status);
        $('#password').val('');
    }
}

// Delete user
function deleteUser(userId, username) {
    confirmAction('ต้องการลบผู้ใช้ \"' + username + '\"? การกระทำนี้ไม่สามารถยกเลิกได้', function() {
        $('#deleteUserId').val(userId);
        $('#deleteForm').submit();
    });
}

console.log('User Management loaded successfully');
";

require_once '../includes/footer.php';
?>