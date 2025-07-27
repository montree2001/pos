<?php
/**
 * ตรวจสอบและแก้ไขปัญหาระบบ
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

// ตรวจสอบสิทธิ์ (ถ้ามีการล็อกอิน)
$isAdmin = false;
if (isLoggedIn() && getCurrentUserRole() === 'admin') {
    $isAdmin = true;
}

$pageTitle = 'ตรวจสอบระบบ';

// ตัวแปรสำหรับเก็บผลการตรวจสอบ
$checks = [];
$autoFixApplied = false;

// ฟังก์ชันตรวจสอบต่างๆ
function checkDatabaseConnection() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        return [
            'status' => 'success',
            'message' => 'การเชื่อมต่อฐานข้อมูลสำเร็จ',
            'details' => 'สามารถเชื่อมต่อฐานข้อมูล smart_order ได้'
        ];
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'การเชื่อมต่อฐานข้อมูลล้มเหลว',
            'details' => $e->getMessage(),
            'fix' => 'createDatabase'
        ];
    }
}

function checkRequiredTables() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $requiredTables = [
            'users', 'categories', 'products', 'orders', 
            'order_items', 'payments', 'notifications'
        ];
        
        $missingTables = [];
        
        foreach ($requiredTables as $table) {
            $stmt = $conn->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$table]);
            if ($stmt->rowCount() == 0) {
                $missingTables[] = $table;
            }
        }
        
        if (empty($missingTables)) {
            return [
                'status' => 'success',
                'message' => 'ตารางฐานข้อมูลครบถ้วน',
                'details' => 'ตารางทั้งหมด ' . count($requiredTables) . ' ตาราง พร้อมใช้งาน'
            ];
        } else {
            return [
                'status' => 'warning',
                'message' => 'ตารางฐานข้อมูลไม่ครบ',
                'details' => 'ขาดตาราง: ' . implode(', ', $missingTables),
                'fix' => 'createTables'
            ];
        }
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'ไม่สามารถตรวจสอบตารางได้',
            'details' => $e->getMessage()
        ];
    }
}

function checkDirectories() {
    $requiredDirs = [
        UPLOAD_PATH => 'uploads',
        TEMP_PATH => 'uploads/temp',
        MENU_IMAGE_PATH => 'uploads/menu_images',
        dirname(__DIR__) . '/logs/' => 'โฟลเดอร์ Log'
    ];
    
    $issues = [];
    
    foreach ($requiredDirs as $dir => $name) {
        if (!is_dir($dir)) {
            $issues[] = "$name ไม่พบ ($dir)";
        } elseif (!is_writable($dir)) {
            $issues[] = "$name ไม่สามารถเขียนได้ ($dir)";
        }
    }
    
    if (empty($issues)) {
        return [
            'status' => 'success',
            'message' => 'โฟลเดอร์ทั้งหมดพร้อมใช้งาน',
            'details' => 'ตรวจสอบแล้ว ' . count($requiredDirs) . ' โฟลเดอร์'
        ];
    } else {
        return [
            'status' => 'warning',
            'message' => 'พบปัญหาโฟลเดอร์',
            'details' => implode('<br>', $issues),
            'fix' => 'createDirectories'
        ];
    }
}

function checkPHPVersion() {
    $currentVersion = PHP_VERSION;
    $requiredVersion = '7.4.0';
    
    if (version_compare($currentVersion, $requiredVersion, '>=')) {
        return [
            'status' => 'success',
            'message' => 'เวอร์ชัน PHP เหมาะสม',
            'details' => "PHP $currentVersion (ต้องการ >= $requiredVersion)"
        ];
    } else {
        return [
            'status' => 'error',
            'message' => 'เวอร์ชัน PHP ไม่เหมาะสม',
            'details' => "PHP $currentVersion (ต้องการ >= $requiredVersion)"
        ];
    }
}

function checkRequiredExtensions() {
    $requiredExtensions = [
        'pdo' => 'PDO',
        'pdo_mysql' => 'PDO MySQL',
        'gd' => 'GD (สำหรับจัดการรูปภาพ)',
        'curl' => 'cURL (สำหรับ API)',
        'json' => 'JSON',
        'mbstring' => 'Multibyte String'
    ];
    
    $missing = [];
    
    foreach ($requiredExtensions as $ext => $name) {
        if (!extension_loaded($ext)) {
            $missing[] = $name;
        }
    }
    
    if (empty($missing)) {
        return [
            'status' => 'success',
            'message' => 'PHP Extensions ครบถ้วน',
            'details' => 'Extensions ทั้งหมดที่จำเป็นพร้อมใช้งาน'
        ];
    } else {
        return [
            'status' => 'error',
            'message' => 'PHP Extensions ไม่ครบ',
            'details' => 'ขาด: ' . implode(', ', $missing)
        ];
    }
}

function checkAdminUser() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin' AND status = 'active'");
        $stmt->execute();
        $adminCount = $stmt->fetchColumn();
        
        if ($adminCount > 0) {
            return [
                'status' => 'success',
                'message' => 'มีผู้ดูแลระบบ',
                'details' => "พบผู้ดูแลระบบ $adminCount คน"
            ];
        } else {
            return [
                'status' => 'warning',
                'message' => 'ไม่มีผู้ดูแลระบบ',
                'details' => 'ไม่พบบัญชีผู้ดูแลระบบที่ใช้งานได้',
                'fix' => 'createAdmin'
            ];
        }
    } catch (Exception $e) {
        return [
            'status' => 'error',
            'message' => 'ไม่สามารถตรวจสอบผู้ดูแลได้',
            'details' => $e->getMessage()
        ];
    }
}

// ฟังก์ชันแก้ไขปัญหาอัตโนมัติ
function autoFix($fixType) {
    global $autoFixApplied;
    
    try {
        switch ($fixType) {
            case 'createDirectories':
                createRequiredDirectories();
                $autoFixApplied = true;
                return 'สร้างโฟลเดอร์ที่จำเป็นแล้ว';
                
            case 'createTables':
                $db = new Database();
                $db->createBasicTables();
                $autoFixApplied = true;
                return 'สร้างตารางฐานข้อมูลแล้ว';
                
            case 'createAdmin':
                $db = new Database();
                $conn = $db->getConnection();
                $stmt = $conn->prepare("
                    INSERT INTO users (username, password, fullname, email, role, status, created_at) 
                    VALUES (?, ?, ?, ?, 'admin', 'active', NOW())
                ");
                $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
                $stmt->execute(['admin', $hashedPassword, 'ผู้ดูแลระบบ', 'admin@example.com']);
                $autoFixApplied = true;
                return 'สร้างบัญชีผู้ดูแลแล้ว (admin/admin123)';
                
            default:
                return 'ไม่พบวิธีแก้ไขสำหรับปัญหานี้';
        }
    } catch (Exception $e) {
        return 'การแก้ไขล้มเหลว: ' . $e->getMessage();
    }
}

// ดำเนินการตรวจสอบ
$checks['php_version'] = checkPHPVersion();
$checks['php_extensions'] = checkRequiredExtensions();
$checks['directories'] = checkDirectories();
$checks['database'] = checkDatabaseConnection();

if ($checks['database']['status'] === 'success') {
    $checks['tables'] = checkRequiredTables();
    $checks['admin_user'] = checkAdminUser();
}

// จัดการ Auto Fix
if (isset($_GET['fix']) && !empty($_GET['fix'])) {
    $fixResult = autoFix($_GET['fix']);
    
    // Redirect เพื่อป้องกัน double-execution
    $redirectUrl = 'system_check.php';
    if ($autoFixApplied) {
        $redirectUrl .= '?fixed=1';
    }
    header("Location: $redirectUrl");
    exit();
}

// แสดงผลการแก้ไข
$fixMessage = '';
if (isset($_GET['fixed'])) {
    $fixMessage = 'การแก้ไขสำเร็จ กรุณาตรวจสอบอีกครั้ง';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4f46e5;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .container {
            padding: 20px;
        }
        
        .system-check-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
            margin-bottom: 20px;
        }
        
        .check-item {
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        
        .check-item:last-child {
            border-bottom: none;
        }
        
        .check-icon {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 18px;
            color: white;
        }
        
        .check-icon.success {
            background: var(--success-color);
        }
        
        .check-icon.warning {
            background: var(--warning-color);
        }
        
        .check-icon.error {
            background: var(--danger-color);
        }
        
        .check-content {
            flex: 1;
        }
        
        .check-title {
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .check-details {
            font-size: 14px;
            color: #6b7280;
        }
        
        .fix-button {
            margin-left: 15px;
        }
        
        .header-card {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            text-align: center;
            padding: 40px 20px;
        }
        
        .summary-cards {
            margin: 20px 0;
        }
        
        .summary-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .summary-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="system-check-card">
            <div class="header-card">
                <h1 class="mb-3">
                    <i class="fas fa-stethoscope me-3"></i>
                    ตรวจสอบระบบ
                </h1>
                <p class="mb-0">การตรวจสอบและแก้ไขปัญหาระบบอัตโนมัติ</p>
            </div>
        </div>
        
        <!-- Fix Success Message -->
        <?php if ($fixMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?php echo $fixMessage; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        
        <!-- Summary -->
        <div class="row summary-cards">
            <?php
            $successCount = count(array_filter($checks, fn($check) => $check['status'] === 'success'));
            $warningCount = count(array_filter($checks, fn($check) => $check['status'] === 'warning'));
            $errorCount = count(array_filter($checks, fn($check) => $check['status'] === 'error'));
            ?>
            
            <div class="col-md-4">
                <div class="summary-card">
                    <div class="summary-number text-success"><?php echo $successCount; ?></div>
                    <div>ผ่าน</div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="summary-card">
                    <div class="summary-number text-warning"><?php echo $warningCount; ?></div>
                    <div>คำเตือน</div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="summary-card">
                    <div class="summary-number text-danger"><?php echo $errorCount; ?></div>
                    <div>ข้อผิดพลาด</div>
                </div>
            </div>
        </div>
        
        <!-- Check Results -->
        <div class="system-check-card">
            <?php foreach ($checks as $checkName => $check): ?>
                <div class="check-item">
                    <div class="d-flex align-items-center flex-grow-1">
                        <div class="check-icon <?php echo $check['status']; ?>">
                            <?php
                            switch ($check['status']) {
                                case 'success':
                                    echo '<i class="fas fa-check"></i>';
                                    break;
                                case 'warning':
                                    echo '<i class="fas fa-exclamation"></i>';
                                    break;
                                case 'error':
                                    echo '<i class="fas fa-times"></i>';
                                    break;
                            }
                            ?>
                        </div>
                        
                        <div class="check-content">
                            <div class="check-title"><?php echo $check['message']; ?></div>
                            <div class="check-details"><?php echo $check['details']; ?></div>
                        </div>
                    </div>
                    
                    <?php if (isset($check['fix'])): ?>
                        <div class="fix-button">
                            <a href="?fix=<?php echo $check['fix']; ?>" 
                               class="btn btn-sm btn-primary"
                               onclick="return confirm('ต้องการแก้ไขปัญหานี้อัตโนมัติ?')">
                                <i class="fas fa-wrench me-1"></i>
                                แก้ไข
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <!-- Actions -->
        <div class="text-center mt-4">
            <a href="?" class="btn btn-primary me-3">
                <i class="fas fa-sync-alt me-2"></i>
                ตรวจสอบอีกครั้ง
            </a>
            
            <?php if ($isAdmin): ?>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left me-2"></i>
                    กลับ Dashboard
                </a>
            <?php else: ?>
                <a href="login.php" class="btn btn-secondary">
                    <i class="fas fa-sign-in-alt me-2"></i>
                    เข้าสู่ระบบ
                </a>
            <?php endif; ?>
        </div>
        
        <!-- System Info -->
        <div class="system-check-card mt-4">
            <div class="check-item">
                <div class="check-content">
                    <div class="check-title">ข้อมูลระบบ</div>
                    <div class="check-details">
                        <strong>PHP:</strong> <?php echo PHP_VERSION; ?><br>
                        <strong>Server:</strong> <?php echo $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown'; ?><br>
                        <strong>ระบบปฏิบัติการ:</strong> <?php echo PHP_OS; ?><br>
                        <strong>เวลาตัวอย่างสิชาน:</strong> <?php echo date('Y-m-d H:i:s'); ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>