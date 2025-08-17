<?php
/**
 * หน้าแรกของระบบ Smart Order Management System
 * เป็นหน้าต้อนรับและนำทางไปยังส่วนต่างๆ ของระบบ
 */

define('SYSTEM_INIT', true);
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/config/session.php';

// ตรวจสอบการติดตั้งระบบ
$isInstalled = file_exists('setup_completed.flag');
$needSetup = false;

if (!$isInstalled) {
    // ตรวจสอบว่ามีฐานข้อมูลหรือไม่
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // ตรวจสอบตาราง users
        $stmt = $conn->query("SELECT COUNT(*) FROM users WHERE role = 'admin'");
        $adminCount = $stmt->fetchColumn();
        
        if ($adminCount == 0) {
            $needSetup = true;
        }
    } catch (Exception $e) {
        $needSetup = true;
    }
}

// ถ้าต้องการติดตั้ง redirect ไป setup
if ($needSetup) {
    header('Location: setup.php');
    exit();
}

$pageTitle = 'หน้าหลัก';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo SITE_NAME; ?></title>
    <meta name="description" content="<?php echo SITE_DESCRIPTION; ?>">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    
    <!-- Custom Styles -->
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-dark: #3730a3;
            --secondary-color: #6b7280;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --light-bg: #f8fafc;
            --white: #ffffff;
            --text-color: #1f2937;
            --text-muted: #6b7280;
            --box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
        }
        
        .hero-section {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 0;
        }
        
        .hero-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            max-width: 1200px;
            width: 100%;
            margin: 20px;
        }
        
        .hero-header {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            text-align: center;
            padding: 60px 30px;
        }
        
        .hero-header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .hero-header p {
            font-size: 1.25rem;
            margin-bottom: 0;
            opacity: 0.9;
        }
        
        .hero-body {
            padding: 50px 30px;
        }
        
        .system-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 30px;
            margin-top: 30px;
        }
        
        .system-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 30px;
            text-align: center;
            transition: var(--transition);
            border: 2px solid #e5e7eb;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .system-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--box-shadow);
            border-color: var(--primary-color);
            text-decoration: none;
            color: var(--text-color);
        }
        
        .system-icon {
            width: 80px;
            height: 80px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
            font-size: 2rem;
            color: white;
        }
        
        .system-icon.admin {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
        }
        
        .system-icon.pos {
            background: linear-gradient(135deg, var(--success-color), #059669);
        }
        
        .system-icon.kitchen {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
        }
        
        .system-icon.customer {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
        }
        
        .system-card h3 {
            font-size: 1.5rem;
            font-weight: 600;
            margin-bottom: 15px;
        }
        
        .system-card p {
            color: var(--text-muted);
            margin-bottom: 20px;
        }
        
        .system-card .btn {
            border-radius: 8px;
            padding: 10px 25px;
            font-weight: 600;
            transition: var(--transition);
        }
        
        .features-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-top: 40px;
        }
        
        .feature-item {
            text-align: center;
            padding: 20px;
        }
        
        .feature-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 1.5rem;
        }
        
        .status-bar {
            background: var(--white);
            padding: 20px;
            border-radius: var(--border-radius);
            margin-bottom: 30px;
            box-shadow: var(--box-shadow);
        }
        
        .status-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .status-item:last-child {
            border-bottom: none;
        }
        
        .status-indicator {
            width: 12px;
            height: 12px;
            border-radius: 50%;
            margin-left: 10px;
        }
        
        .status-indicator.online {
            background: var(--success-color);
            box-shadow: 0 0 5px rgba(16, 185, 129, 0.5);
        }
        
        .status-indicator.offline {
            background: var(--danger-color);
        }
        
        .footer-section {
            background: rgba(255, 255, 255, 0.1);
            color: white;
            text-align: center;
            padding: 30px;
        }
        
        @media (max-width: 768px) {
            .hero-header h1 {
                font-size: 2rem;
            }
            
            .hero-header p {
                font-size: 1rem;
            }
            
            .hero-body {
                padding: 30px 20px;
            }
            
            .system-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="hero-section">
        <div class="hero-card">
            <!-- Header -->
            <div class="hero-header">
                <h1>
                    <i class="fas fa-store me-3"></i>
                    <?php echo SITE_NAME; ?>
                </h1>
                <p><?php echo SITE_DESCRIPTION; ?></p>
            </div>
            
            <!-- Body -->
            <div class="hero-body">
                <!-- System Status -->
                <div class="status-bar">
                    <h5 class="mb-3">
                        <i class="fas fa-server me-2"></i>
                        สถานะระบบ
                    </h5>
                    <div class="row">
                        <div class="col-md-3">
                            <div class="status-item">
                                <span>ฐานข้อมูล</span>
                                <div class="status-indicator online" id="dbStatus"></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="status-item">
                                <span>เว็บเซิร์ฟเวอร์</span>
                                <div class="status-indicator online"></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="status-item">
                                <span>PHP <?php echo PHP_VERSION; ?></span>
                                <div class="status-indicator online"></div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="status-item">
                                <span>เวลาระบบ</span>
                                <small class="text-muted"><?php echo date('H:i'); ?></small>
                            </div>
                        </div>
                    </div>
                </div>
                
                <!-- Welcome Message -->
                <div class="text-center mb-4">
                    <h2>ยินดีต้อนรับสู่ระบบจัดการออเดอร์อัจฉริยะ</h2>
                    <p class="text-muted">เลือกระบบที่ต้องการใช้งาน</p>
                </div>
                
                <!-- System Cards -->
                <div class="system-grid">
                    <!-- Admin System -->
                    <a href="admin/" class="system-card">
                        <div class="system-icon admin">
                            <i class="fas fa-user-shield"></i>
                        </div>
                        <h3>ผู้ดูแลระบบ</h3>
                        <p>จัดการเมนู ผู้ใช้ ออเดอร์ และรายงานต่างๆ</p>
                        <button class="btn btn-primary">
                            <i class="fas fa-cog me-2"></i>เข้าสู่ระบบ Admin
                        </button>
                    </a>
                    
                    <!-- POS System -->
                    <a href="pos/" class="system-card">
                        <div class="system-icon pos">
                            <i class="fas fa-cash-register"></i>
                        </div>
                        <h3>ระบบ POS</h3>
                        <p>สำหรับพนักงานขาย รับออเดอร์ และจัดการคิว</p>
                        <button class="btn btn-success">
                            <i class="fas fa-shopping-cart me-2"></i>เปิด POS
                        </button>
                    </a>
                    
                    <!-- Kitchen System -->
                    <a href="kitchen/" class="system-card">
                        <div class="system-icon kitchen">
                            <i class="fas fa-utensils"></i>
                        </div>
                        <h3>ระบบครัว</h3>
                        <p>สำหรับครัว ดูออเดอร์และอัปเดตสถานะอาหาร</p>
                        <button class="btn btn-warning">
                            <i class="fas fa-fire me-2"></i>หน้าจอครัว
                        </button>
                    </a>
                    
                    <!-- Customer System -->
                    <a href="customer/" class="system-card">
                        <div class="system-icon customer">
                            <i class="fas fa-store"></i>
                        </div>
                        <h3>หน้าลูกค้า</h3>
                        <p>สำหรับลูกค้า สั่งอาหารและตรวจสอบคิว</p>
                        <button class="btn btn-danger">
                            <i class="fas fa-utensils me-2"></i>สั่งอาหาร
                        </button>
                    </a>
                </div>
                
                <!-- Features -->
                <div class="mt-5">
                    <h3 class="text-center mb-4">ฟีเจอร์หลักของระบบ</h3>
                    <div class="features-grid">
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-mobile-alt"></i>
                            </div>
                            <h6>Mobile-First Design</h6>
                            <p class="small text-muted">ออกแบบให้ใช้งานบนมือถือและแท็บเล็ตได้อย่างสมบูรณ์</p>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-clock"></i>
                            </div>
                            <h6>Real-time Queue</h6>
                            <p class="small text-muted">ระบบคิวแบบเรียลไทม์ พร้อมการแจ้งเตือนอัตโนมัติ</p>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fab fa-line"></i>
                            </div>
                            <h6>LINE Integration</h6>
                            <p class="small text-muted">เชื่อมต่อกับ LINE OA สำหรับการแจ้งเตือนลูกค้า</p>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-chart-bar"></i>
                            </div>
                            <h6>Analytics & Reports</h6>
                            <p class="small text-muted">รายงานและสถิติการขายแบบละเอียด</p>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-volume-up"></i>
                            </div>
                            <h6>AI Voice System</h6>
                            <p class="small text-muted">เรียกคิวด้วยเสียงไทยธรรมชาติ</p>
                        </div>
                        
                        <div class="feature-item">
                            <div class="feature-icon">
                                <i class="fas fa-robot"></i>
                            </div>
                            <h6>AI Chatbot</h6>
                            <p class="small text-muted">ตอบคำถามลูกค้าอัตโนมัติ 24/7</p>
                        </div>
                    </div>
                </div>
                
                <!-- Quick Actions -->
                <div class="text-center mt-5">
                    <h5 class="mb-3">การดำเนินการด่วน</h5>
                    <div class="btn-group" role="group">
                        <a href="queue_caller.php" class="btn btn-success">
                            <i class="fas fa-bullhorn me-2"></i>เรียกคิว
                        </a>
                        <a href="admin/system_check.php" class="btn btn-outline-primary">
                            <i class="fas fa-stethoscope me-2"></i>ตรวจสอบระบบ
                        </a>
                        <a href="setup.php" class="btn btn-outline-secondary">
                            <i class="fas fa-cogs me-2"></i>Setup Wizard
                        </a>
                        <a href="https://github.com/smartorder/docs" class="btn btn-outline-info" target="_blank">
                            <i class="fas fa-book me-2"></i>คู่มือการใช้งาน
                        </a>
                    </div>
                </div>
            </div>
            
            <!-- Footer -->
            <div class="footer-section">
                <p class="mb-2">
                    <strong><?php echo SITE_NAME; ?></strong> v<?php echo VERSION; ?>
                </p>
                <p class="mb-0">
                    <small>Developed by <?php echo AUTHOR; ?> • 
                    <a href="mailto:support@smartorder.com" class="text-white">ติดต่อสนับสนุน</a>
                    </small>
                </p>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ตรวจสอบสถานะฐานข้อมูล
        document.addEventListener('DOMContentLoaded', function() {
            checkDatabaseStatus();
            
            // อัปเดตเวลาทุกวินาที
            setInterval(updateTime, 1000);
        });
        
        function checkDatabaseStatus() {
            fetch('api/system_status.php?check=database')
                .then(response => response.json())
                .then(data => {
                    const dbStatus = document.getElementById('dbStatus');
                    if (data.success && data.status === 'connected') {
                        dbStatus.className = 'status-indicator online';
                        dbStatus.title = 'ฐานข้อมูลเชื่อมต่อปกติ';
                    } else {
                        dbStatus.className = 'status-indicator offline';
                        dbStatus.title = 'ฐานข้อมูลเชื่อมต่อไม่ได้';
                    }
                })
                .catch(error => {
                    const dbStatus = document.getElementById('dbStatus');
                    dbStatus.className = 'status-indicator offline';
                    dbStatus.title = 'ไม่สามารถตรวจสอบได้';
                });
        }
        
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('th-TH', {
                hour: '2-digit',
                minute: '2-digit'
            });
            
            const timeElements = document.querySelectorAll('.status-item small');
            if (timeElements.length > 0) {
                timeElements[timeElements.length - 1].textContent = timeString;
            }
        }
        
        // เอฟเฟค hover สำหรับ system cards
        document.querySelectorAll('.system-card').forEach(card => {
            card.addEventListener('mouseenter', function() {
                this.style.transform = 'translateY(-8px) scale(1.02)';
            });
            
            card.addEventListener('mouseleave', function() {
                this.style.transform = 'translateY(0) scale(1)';
            });
        });
        
        // การนับ visitors (สำหรับสถิติ)
        if (localStorage) {
            let visitCount = localStorage.getItem('visit_count') || 0;
            visitCount++;
            localStorage.setItem('visit_count', visitCount);
            localStorage.setItem('last_visit', new Date().toISOString());
        }
        
        console.log('%c🍽️ Smart Order Management System', 
                   'color: #4f46e5; font-size: 20px; font-weight: bold;');
        console.log('%cระบบพร้อมใช้งาน ✅', 'color: #10b981; font-size: 16px;');
    </script>
</body>
</html>