<?php
/**
 * ตัวติดตั้งระบบ Smart Order Management System
 * สำหรับการติดตั้งเริ่มต้นและแก้ไขปัญหา
 */

// ป้องกันการเรียกใช้หลายครั้ง
if (file_exists('setup_completed.flag')) {
    die('ระบบติดตั้งเรียบร้อยแล้ว หากต้องการติดตั้งใหม่ กรุณาลบไฟล์ setup_completed.flag');
}

define('SYSTEM_INIT', true);
require_once 'config/config.php';

$step = $_GET['step'] ?? 1;
$errors = [];
$success = [];

// ฟังก์ชันตรวจสอบข้อกำหนดระบบ
function checkSystemRequirements() {
    $requirements = [
        'php_version' => [
            'test' => version_compare(PHP_VERSION, '7.4.0', '>='),
            'message' => 'PHP เวอร์ชัน 7.4.0 หรือสูงกว่า',
            'current' => PHP_VERSION
        ],
        'pdo_extension' => [
            'test' => extension_loaded('pdo'),
            'message' => 'PDO Extension',
            'current' => extension_loaded('pdo') ? 'ติดตั้งแล้ว' : 'ไม่พบ'
        ],
        'pdo_mysql' => [
            'test' => extension_loaded('pdo_mysql'),
            'message' => 'PDO MySQL Extension',
            'current' => extension_loaded('pdo_mysql') ? 'ติดตั้งแล้ว' : 'ไม่พบ'
        ],
        'gd_extension' => [
            'test' => extension_loaded('gd'),
            'message' => 'GD Extension (สำหรับจัดการรูปภาพ)',
            'current' => extension_loaded('gd') ? 'ติดตั้งแล้ว' : 'ไม่พบ'
        ],
        'curl_extension' => [
            'test' => extension_loaded('curl'),
            'message' => 'cURL Extension (สำหรับ API)',
            'current' => extension_loaded('curl') ? 'ติดตั้งแล้ว' : 'ไม่พบ'
        ],
        'json_extension' => [
            'test' => extension_loaded('json'),
            'message' => 'JSON Extension',
            'current' => extension_loaded('json') ? 'ติดตั้งแล้ว' : 'ไม่พบ'
        ],
        'uploads_writable' => [
            'test' => is_writable('uploads') || mkdir('uploads', 0755, true),
            'message' => 'โฟลเดอร์ uploads สามารถเขียนได้',
            'current' => is_writable('uploads') ? 'เขียนได้' : 'เขียนไม่ได้'
        ],
        'logs_writable' => [
            'test' => is_writable('logs') || mkdir('logs', 0755, true),
            'message' => 'โฟลเดอร์ logs สามารถเขียนได้',
            'current' => is_writable('logs') ? 'เขียนได้' : 'เขียนไม่ได้'
        ]
    ];
    
    return $requirements;
}

// ฟังก์ชันสร้างฐานข้อมูลและตาราง
function setupDatabase($host, $username, $password, $dbname) {
    try {
        // เชื่อมต่อโดยไม่ระบุฐานข้อมูล
        $pdo = new PDO("mysql:host=$host;charset=utf8mb4", $username, $password);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        
        // สร้างฐานข้อมูล
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$dbname` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `$dbname`");
        
        // อ่านและรันไฟล์ SQL
        $sqlFile = 'smart_order.sql';
        if (file_exists($sqlFile)) {
            $sql = file_get_contents($sqlFile);
            $pdo->exec($sql);
        }
        
        // รันไฟล์แก้ไขและข้อมูลตัวอย่าง
        $fixFile = 'database_fix.sql';
        if (file_exists($fixFile)) {
            $sql = file_get_contents($fixFile);
            $pdo->exec($sql);
        }
        
        return ['success' => true, 'message' => 'สร้างฐานข้อมูลสำเร็จ'];
        
    } catch (Exception $e) {
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาด: ' . $e->getMessage()];
    }
}

// ฟังก์ชันสร้างไฟล์ config
function createConfigFile($host, $username, $password, $dbname, $siteUrl) {
    $configContent = "<?php
/**
 * การตั้งค่าฐานข้อมูล
 * Smart Order Management System
 * สร้างอัตโนมัติโดย Setup Wizard
 */

// ป้องกันการเข้าถึงโดยตรง
if (!defined('SYSTEM_INIT')) {
    die('Direct access not allowed');
}

class Database {
    private \$host = '$host';
    private \$dbname = '$dbname';
    private \$username = '$username';
    private \$password = '$password';
    private \$conn;
    private \$queryCount = 0;
    
    public function getConnection() {
        if (\$this->conn !== null) {
            return \$this->conn;
        }
        
        try {
            \$dsn = \"mysql:host=\" . \$this->host . \";dbname=\" . \$this->dbname . \";charset=utf8mb4\";
            
            \$options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => \"SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci\"
            ];
            
            \$this->conn = new PDO(\$dsn, \$this->username, \$this->password, \$options);
            \$this->conn->query(\"SELECT 1\");
            
            return \$this->conn;
            
        } catch (PDOException \$e) {
            if (function_exists('writeLog')) {
                writeLog(\"Database connection failed: \" . \$e->getMessage());
            }
            
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                throw new Exception(\"Database Connection Error: \" . \$e->getMessage());
            } else {
                throw new Exception(\"Database connection failed\");
            }
        }
    }
    
    public function testConnection() {
        try {
            \$conn = \$this->getConnection();
            if (\$conn) {
                \$stmt = \$conn->query(\"SELECT 1 as test\");
                \$result = \$stmt->fetch();
                return \$result['test'] === 1;
            }
            return false;
        } catch (Exception \$e) {
            return false;
        }
    }
    
    public function getQueryCount() {
        return \$this->queryCount;
    }
    
    public function closeConnection() {
        \$this->conn = null;
    }
    
    public function __destruct() {
        \$this->closeConnection();
    }
}
?>";

    // อัปเดตไฟล์ config.php ด้วย
    $configMainContent = str_replace(
        "define('SITE_URL', 'http://localhost/pos');",
        "define('SITE_URL', '$siteUrl');",
        file_get_contents('config/config.php')
    );
    
    file_put_contents('config/database.php', $configContent);
    file_put_contents('config/config.php', $configMainContent);
    
    return true;
}

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    switch ($step) {
        case 2: // Database Setup
            $host = $_POST['db_host'] ?? 'localhost';
            $username = $_POST['db_username'] ?? '';
            $password = $_POST['db_password'] ?? '';
            $dbname = $_POST['db_name'] ?? 'smart_order';
            $siteUrl = $_POST['site_url'] ?? 'http://localhost/pos';
            
            if (empty($username)) {
                $errors[] = 'กรุณากรอกชื่อผู้ใช้ฐานข้อมูล';
            } else {
                $result = setupDatabase($host, $username, $password, $dbname);
                if ($result['success']) {
                    createConfigFile($host, $username, $password, $dbname, $siteUrl);
                    $success[] = $result['message'];
                    $step = 3;
                } else {
                    $errors[] = $result['message'];
                }
            }
            break;
            
        case 3: // Complete Setup
            file_put_contents('setup_completed.flag', date('Y-m-d H:i:s'));
            $step = 4;
            break;
    }
}

?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ติดตั้งระบบ - <?php echo SITE_NAME; ?></title>
    
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
        
        .setup-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .setup-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            overflow: hidden;
            max-width: 800px;
            width: 100%;
        }
        
        .setup-header {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .setup-body {
            padding: 30px;
        }
        
        .step-indicator {
            display: flex;
            justify-content: center;
            margin-bottom: 30px;
        }
        
        .step {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 10px;
            font-weight: bold;
            position: relative;
        }
        
        .step.active {
            background: var(--primary-color);
            color: white;
        }
        
        .step.completed {
            background: var(--success-color);
            color: white;
        }
        
        .step.pending {
            background: #e5e7eb;
            color: #6b7280;
        }
        
        .step::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 100%;
            width: 60px;
            height: 2px;
            background: #e5e7eb;
            transform: translateY(-50%);
        }
        
        .step:last-child::after {
            display: none;
        }
        
        .step.completed::after {
            background: var(--success-color);
        }
        
        .requirement-item {
            display: flex;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .requirement-icon {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin-right: 15px;
            font-size: 14px;
            color: white;
        }
        
        .requirement-icon.success {
            background: var(--success-color);
        }
        
        .requirement-icon.error {
            background: var(--danger-color);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            border: none;
            border-radius: 8px;
            padding: 12px 30px;
            font-weight: 600;
        }
        
        .form-control {
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            padding: 12px 15px;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
        }
    </style>
</head>
<body>
    <div class="setup-container">
        <div class="setup-card">
            <div class="setup-header">
                <h1 class="mb-3">
                    <i class="fas fa-cogs me-3"></i>
                    ติดตั้งระบบ
                </h1>
                <p class="mb-0"><?php echo SITE_NAME; ?></p>
            </div>
            
            <div class="setup-body">
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step <?php echo $step >= 1 ? ($step == 1 ? 'active' : 'completed') : 'pending'; ?>">1</div>
                    <div class="step <?php echo $step >= 2 ? ($step == 2 ? 'active' : 'completed') : 'pending'; ?>">2</div>
                    <div class="step <?php echo $step >= 3 ? ($step == 3 ? 'active' : 'completed') : 'pending'; ?>">3</div>
                    <div class="step <?php echo $step >= 4 ? 'active' : 'pending'; ?>">4</div>
                </div>
                
                <!-- Messages -->
                <?php if (!empty($errors)): ?>
                    <div class="alert alert-danger">
                        <h6><i class="fas fa-exclamation-triangle me-2"></i>เกิดข้อผิดพลาด</h6>
                        <ul class="mb-0">
                            <?php foreach ($errors as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($success)): ?>
                    <div class="alert alert-success">
                        <h6><i class="fas fa-check-circle me-2"></i>สำเร็จ</h6>
                        <ul class="mb-0">
                            <?php foreach ($success as $msg): ?>
                                <li><?php echo htmlspecialchars($msg); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    </div>
                <?php endif; ?>
                
                <!-- Step Content -->
                <?php if ($step == 1): ?>
                    <!-- System Requirements Check -->
                    <h3 class="mb-4">ขั้นตอนที่ 1: ตรวจสอบข้อกำหนดระบบ</h3>
                    
                    <?php 
                    $requirements = checkSystemRequirements();
                    $allPassed = true;
                    ?>
                    
                    <div class="requirements-list">
                        <?php foreach ($requirements as $key => $req): ?>
                            <?php $allPassed = $allPassed && $req['test']; ?>
                            <div class="requirement-item">
                                <div class="requirement-icon <?php echo $req['test'] ? 'success' : 'error'; ?>">
                                    <i class="fas fa-<?php echo $req['test'] ? 'check' : 'times'; ?>"></i>
                                </div>
                                <div class="flex-grow-1">
                                    <div class="fw-semibold"><?php echo $req['message']; ?></div>
                                    <small class="text-muted">สถานะ: <?php echo $req['current']; ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <div class="mt-4">
                        <?php if ($allPassed): ?>
                            <div class="alert alert-success">
                                <i class="fas fa-check-circle me-2"></i>
                                ระบบพร้อมสำหรับการติดตั้ง
                            </div>
                            <a href="?step=2" class="btn btn-primary">
                                ดำเนินการต่อ <i class="fas fa-arrow-right ms-2"></i>
                            </a>
                        <?php else: ?>
                            <div class="alert alert-warning">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                กรุณาแก้ไขข้อกำหนดที่ไม่ผ่านก่อนดำเนินการต่อ
                            </div>
                            <button onclick="location.reload()" class="btn btn-secondary">
                                <i class="fas fa-sync-alt me-2"></i>ตรวจสอบอีกครั้ง
                            </button>
                        <?php endif; ?>
                    </div>
                    
                <?php elseif ($step == 2): ?>
                    <!-- Database Configuration -->
                    <h3 class="mb-4">ขั้นตอนที่ 2: ตั้งค่าฐานข้อมูล</h3>
                    
                    <form method="POST">
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="db_host" class="form-label">เซิร์ฟเวอร์ฐานข้อมูล</label>
                                    <input type="text" class="form-control" id="db_host" name="db_host" value="localhost" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="db_name" class="form-label">ชื่อฐานข้อมูล</label>
                                    <input type="text" class="form-control" id="db_name" name="db_name" value="smart_order" required>
                                </div>
                            </div>
                        </div>
                        
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="db_username" class="form-label">ชื่อผู้ใช้</label>
                                    <input type="text" class="form-control" id="db_username" name="db_username" value="root" required>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="db_password" class="form-label">รหัสผ่าน</label>
                                    <input type="password" class="form-control" id="db_password" name="db_password">
                                    <small class="text-muted">ปล่อยว่างได้หากไม่มีรหัสผ่าน</small>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mb-3">
                            <label for="site_url" class="form-label">URL ของเว็บไซต์</label>
                            <input type="url" class="form-control" id="site_url" name="site_url" 
                                   value="<?php echo 'http://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']); ?>" required>
                            <small class="text-muted">URL ที่ใช้เข้าถึงระบบ (ไม่ต้องใส่ / ท้าย)</small>
                        </div>
                        
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>หมายเหตุ:</strong> ระบบจะสร้างฐานข้อมูลและตารางให้อัตโนมัติ พร้อมข้อมูลตัวอย่าง
                        </div>
                        
                        <div class="d-flex justify-content-between">
                            <a href="?step=1" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i>ย้อนกลับ
                            </a>
                            <button type="submit" class="btn btn-primary">
                                ติดตั้งฐานข้อมูล <i class="fas fa-database ms-2"></i>
                            </button>
                        </div>
                    </form>
                    
                <?php elseif ($step == 3): ?>
                    <!-- Final Setup -->
                    <h3 class="mb-4">ขั้นตอนที่ 3: เสร็จสิ้นการติดตั้ง</h3>
                    
                    <div class="alert alert-success">
                        <i class="fas fa-check-circle me-2"></i>
                        ฐานข้อมูลติดตั้งเรียบร้อยแล้ว
                    </div>
                    
                    <div class="card">
                        <div class="card-body">
                            <h5 class="card-title">ข้อมูลผู้ดูแลระบบเริ่มต้น</h5>
                            <div class="row">
                                <div class="col-md-6">
                                    <strong>ชื่อผู้ใช้:</strong> admin<br>
                                    <strong>รหัสผ่าน:</strong> admin123
                                </div>
                                <div class="col-md-6">
                                    <strong>อีเมล:</strong> admin@smartorder.com<br>
                                    <strong>บทบาท:</strong> ผู้ดูแลระบบ
                                </div>
                            </div>
                            <div class="alert alert-warning mt-3">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <strong>คำเตือน:</strong> กรุณาเปลี่ยนรหัสผ่านหลังจากเข้าสู่ระบบครั้งแรก
                            </div>
                        </div>
                    </div>
                    
                    <form method="POST" class="mt-4">
                        <div class="d-grid">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="fas fa-check me-2"></i>เสร็จสิ้นการติดตั้ง
                            </button>
                        </div>
                    </form>
                    
                <?php else: ?>
                    <!-- Installation Complete -->
                    <div class="text-center">
                        <div class="mb-4">
                            <i class="fas fa-check-circle text-success" style="font-size: 4rem;"></i>
                        </div>
                        
                        <h3 class="text-success mb-3">ติดตั้งเสร็จสิ้น!</h3>
                        <p class="text-muted mb-4">ระบบพร้อมใช้งานแล้ว</p>
                        
                        <div class="d-grid gap-2 d-md-flex justify-content-md-center">
                            <a href="admin/login.php" class="btn btn-primary">
                                <i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ Admin
                            </a>
                            <a href="admin/system_check.php" class="btn btn-secondary">
                                <i class="fas fa-stethoscope me-2"></i>ตรวจสอบระบบ
                            </a>
                        </div>
                        
                        <hr class="my-4">
                        
                        <div class="text-start">
                            <h6>เมนูระบบ:</h6>
                            <ul class="list-unstyled">
                                <li><i class="fas fa-user-shield me-2"></i><a href="admin/">ผู้ดูแลระบบ</a></li>
                                <li><i class="fas fa-cash-register me-2"></i><a href="pos/">ระบบ POS</a></li>
                                <li><i class="fas fa-utensils me-2"></i><a href="kitchen/">ระบบครัว</a></li>
                                <li><i class="fas fa-shopping-cart me-2"></i><a href="customer/">หน้าลูกค้า</a></li>
                            </ul>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>