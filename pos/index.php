<?php
/**
 * POS Dashboard หลัก
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/session.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

// ตรวจสอบสิทธิ์ (staff หรือ admin)
if (!isLoggedIn() || !in_array(getCurrentUserRole(), ['admin', 'staff'])) {
    header('Location: login.php');
    exit();
}

$pageTitle = 'POS Dashboard';

// เริ่มต้นตัวแปร
$stats = [
    'today_sales' => 0,
    'today_orders' => 0,
    'pending_orders' => 0,
    'completed_today' => 0
];
$recentOrders = [];
$currentQueue = [];
$error = null;

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // สถิติวันนี้
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_price), 0) as today_sales,
               COUNT(*) as today_orders
        FROM orders 
        WHERE DATE(created_at) = CURDATE() 
        AND payment_status = 'paid'
    ");
    $stmt->execute();
    $todayData = $stmt->fetch();
    if ($todayData) {
        $stats['today_sales'] = $todayData['today_sales'];
        $stats['today_orders'] = $todayData['today_orders'];
    }
    
    // ออเดอร์ที่รอดำเนินการ
    $stmt = $conn->prepare("
        SELECT COUNT(*) as pending_orders 
        FROM orders 
        WHERE status IN ('pending', 'confirmed', 'preparing')
    ");
    $stmt->execute();
    $stats['pending_orders'] = $stmt->fetchColumn() ?: 0;
    
    // ออเดอร์เสร็จสิ้นวันนี้
    $stmt = $conn->prepare("
        SELECT COUNT(*) as completed_today 
        FROM orders 
        WHERE DATE(created_at) = CURDATE() 
        AND status = 'completed'
    ");
    $stmt->execute();
    $stats['completed_today'] = $stmt->fetchColumn() ?: 0;
    
    // ออเดอร์ล่าสุด
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name 
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE DATE(o.created_at) = CURDATE()
        ORDER BY o.created_at DESC 
        LIMIT 8
    ");
    $stmt->execute();
    $recentOrders = $stmt->fetchAll();
    
    // คิวปัจจุบัน
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name 
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE o.status IN ('confirmed', 'preparing', 'ready')
        ORDER BY o.created_at ASC 
        LIMIT 10
    ");
    $stmt->execute();
    $currentQueue = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("POS Dashboard error: " . $e->getMessage());
    $error = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
}

$additionalCSS = [
    '../assets/css/pos.css'
];
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
    
    <!-- Google Fonts - Kanit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <!-- Custom POS CSS -->
    <style>
        :root {
            /* Modern Color Palette */
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --success-gradient: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
            --warning-gradient: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            --info-gradient: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
            
            /* Base Colors */
            --bg-primary: #f8fafc;
            --bg-secondary: #e2e8f0;
            --text-primary: #1a202c;
            --text-secondary: #4a5568;
            --text-light: #a0aec0;
            --white: #ffffff;
            
            /* Status Colors */
            --ready-color: #065f46;
            --ready-bg: #d1fae5;
            --ready-border: #10b981;
            --preparing-color: #92400e;
            --preparing-bg: #fef3c7;
            --preparing-border: #f59e0b;
            --waiting-color: #1e3a8a;
            --waiting-bg: #dbeafe;
            --waiting-border: #3b82f6;
            
            /* Effects */
            --shadow-sm: 0 1px 3px rgba(0, 0, 0, 0.1);
            --shadow-md: 0 4px 6px rgba(0, 0, 0, 0.1);
            --shadow-lg: 0 10px 25px rgba(0, 0, 0, 0.15);
            --shadow-xl: 0 20px 25px rgba(0, 0, 0, 0.1);
            --border-radius: 20px;
            --border-radius-lg: 25px;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: var(--bg-primary);
            font-family: 'Kanit', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 16px;
            color: var(--text-primary);
            line-height: 1.6;
        }
        
        .pos-container {
            padding: 20px;
            max-width: 1600px;
            margin: 0 auto;
            min-height: 100vh;
        }
        
        .pos-header {
            background: var(--white);
            border-radius: var(--border-radius-lg);
            padding: 30px;
            margin-bottom: 30px;
            box-shadow: var(--shadow-lg);
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }
        
        .pos-header::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: var(--primary-gradient);
        }
        
        .pos-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 25px;
            text-align: center;
            box-shadow: var(--shadow-md);
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
            position: relative;
            overflow: hidden;
        }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 4px;
            transition: all 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }
        
        .stat-card.success::before { background: var(--success-gradient); }
        .stat-card.info::before { background: var(--info-gradient); }
        .stat-card.warning::before { background: var(--warning-gradient); }
        .stat-card.primary::before { background: var(--primary-gradient); }
        
        .stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            font-family: 'Kanit', sans-serif;
        }
        
        .stat-number.success { color: #059669; }
        .stat-number.info { color: #0369a1; }
        .stat-number.warning { color: #d97706; }
        .stat-number.primary { color: #7c3aed; }
        
        .stat-label {
            color: var(--text-secondary);
            font-size: 1rem;
            font-weight: 500;
            font-family: 'Kanit', sans-serif;
        }
        
        .pos-section {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-md);
            margin-bottom: 25px;
            overflow: hidden;
            border: 1px solid #e2e8f0;
            transition: all 0.3s ease;
        }
        
        .pos-section:hover {
            box-shadow: var(--shadow-lg);
        }
        
        .section-header {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 20px 25px;
            border-bottom: 1px solid #e2e8f0;
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-family: 'Kanit', sans-serif;
            font-size: 1.1rem;
        }
        
        .section-body {
            padding: 25px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .action-btn {
            background: var(--white);
            border: 2px solid #e2e8f0;
            border-radius: var(--border-radius);
            padding: 25px;
            text-align: center;
            text-decoration: none;
            color: var(--text-primary);
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 140px;
            justify-content: center;
            position: relative;
            overflow: hidden;
        }
        
        .action-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.4), transparent);
            transition: left 0.5s;
        }
        
        .action-btn:hover::before {
            left: 100%;
        }
        
        .action-btn:hover {
            border-color: #667eea;
            background: linear-gradient(135deg, #f8faff, #f0f4ff);
            color: #667eea;
            transform: translateY(-5px);
            box-shadow: var(--shadow-xl);
        }
        
        .action-btn i {
            font-size: 2.5rem;
            margin-bottom: 12px;
            transition: transform 0.3s ease;
        }
        
        .action-btn:hover i {
            transform: scale(1.1);
        }
        
        .action-btn strong {
            font-family: 'Kanit', sans-serif;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        
        .action-btn small {
            opacity: 0.7;
            font-size: 0.9rem;
        }
        
        .queue-item {
            background: var(--white);
            border-radius: var(--border-radius);
            padding: 20px;
            margin-bottom: 15px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
            border: 1px solid #e2e8f0;
            box-shadow: var(--shadow-sm);
        }
        
        .queue-item:hover {
            background: #f8faff;
            transform: translateX(8px);
            box-shadow: var(--shadow-md);
            border-color: #667eea;
        }
        
        .queue-info {
            display: flex;
            align-items: center;
            gap: 15px;
        }
        
        .queue-number {
            background: var(--primary-gradient);
            color: white;
            border-radius: 12px;
            padding: 12px 16px;
            font-weight: 700;
            font-size: 1.2rem;
            font-family: 'Kanit', sans-serif;
            box-shadow: var(--shadow-sm);
            min-width: 70px;
            text-align: center;
        }
        
        .queue-details h6 {
            margin: 0 0 5px 0;
            font-family: 'Kanit', sans-serif;
            font-weight: 600;
            color: var(--text-primary);
        }
        
        .queue-details small {
            color: var(--text-secondary);
            display: block;
        }
        
        .queue-actions {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .queue-status {
            padding: 6px 15px;
            border-radius: 25px;
            font-size: 0.85rem;
            font-weight: 600;
            font-family: 'Kanit', sans-serif;
        }
        
        .queue-status.confirmed {
            background: var(--waiting-bg);
            color: var(--waiting-color);
            border: 1px solid var(--waiting-border);
        }
        
        .queue-status.preparing {
            background: var(--preparing-bg);
            color: var(--preparing-color);
            border: 1px solid var(--preparing-border);
        }
        
        .queue-status.ready {
            background: var(--ready-bg);
            color: var(--ready-color);
            border: 1px solid var(--ready-border);
        }
        
        .order-row {
            border-bottom: 1px solid #f3f4f6;
            padding: 12px 0;
        }
        
        .order-row:last-child {
            border-bottom: none;
        }
        
        .badge {
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        
        
        /* Button animations and feedback */
        .btn-call-queue {
            position: relative;
            overflow: hidden;
            transition: all 0.3s ease;
            background: var(--success-gradient) !important;
            border: none;
            color: white;
            font-family: 'Kanit', sans-serif;
            font-weight: 500;
            padding: 10px 20px;
            border-radius: 25px;
            box-shadow: var(--shadow-sm);
        }
        
        .btn-call-queue:hover {
            transform: translateY(-2px);
            box-shadow: var(--shadow-md);
            color: white;
        }
        
        .btn-call-queue:active {
            transform: translateY(0px);
        }
        
        .btn-call-queue.calling {
            background: var(--warning-gradient) !important;
            animation: pulse-calling 2s infinite;
        }
        
        .btn-call-queue.calling::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            border-radius: 50%;
            background: rgba(255, 255, 255, 0.6);
            transform: translate(-50%, -50%);
            animation: ripple 0.8s linear;
        }
        
        @keyframes pulse-calling {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.02); }
        }
        
        @keyframes ripple {
            to {
                width: 80px;
                height: 80px;
                opacity: 0;
            }
        }
        
        .loading-spinner {
            display: inline-block;
            width: 15px;
            height: 15px;
            border: 2px solid rgba(255,255,255,0.3);
            border-radius: 50%;
            border-top-color: white;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Voice indicator */
        .voice-indicator {
            position: fixed;
            top: 30px;
            right: 30px;
            background: var(--success-gradient);
            color: white;
            padding: 15px 25px;
            border-radius: 30px;
            font-size: 1rem;
            font-family: 'Kanit', sans-serif;
            font-weight: 500;
            z-index: 1050;
            display: none;
            box-shadow: var(--shadow-lg);
            animation: slideInRight 0.4s ease;
        }
        
        @keyframes slideInRight {
            0% { opacity: 0; transform: translateX(100px); }
            100% { opacity: 1; transform: translateX(0); }
        }
        
        /* Notification Toasts */
        .toast-container {
            position: fixed;
            top: 20px;
            right: 20px;
            z-index: 1100;
            max-width: 350px;
        }
        
        .custom-toast {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow-xl);
            border: none;
            margin-bottom: 10px;
            overflow: hidden;
            animation: slideInDown 0.4s ease;
        }
        
        @keyframes slideInDown {
            0% { opacity: 0; transform: translateY(-50px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        
        .toast-success {
            border-left: 5px solid #10b981;
        }
        
        .toast-error {
            border-left: 5px solid #ef4444;
        }
        
        .toast-info {
            border-left: 5px solid #3b82f6;
        }
        
        /* Mobile Optimizations */
        @media (max-width: 768px) {
            .pos-container {
                padding: 10px;
            }
            
            .pos-header {
                padding: 15px;
                text-align: center;
            }
            
            .quick-actions {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .action-btn {
                min-height: 100px;
                padding: 15px;
            }
            
            .action-btn i {
                font-size: 1.5rem;
            }
            
            .stat-number {
                font-size: 1.5rem;
            }
        }
        
        @media (max-width: 576px) {
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .pos-stats {
                grid-template-columns: repeat(2, 1fr);
            }
        }
    </style>
</head>
<body>
    <div class="pos-container">
        <!-- Header -->
        <div class="pos-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="fas fa-cash-register me-2"></i>
                        POS Terminal
                    </h1>
                    <p class="mb-0 opacity-75">
                        <?php echo getCurrentUser()['fullname']; ?> • <?php echo formatDate(date('Y-m-d H:i:s'), 'd/m/Y H:i'); ?>
                    </p>
                </div>
                <div class="text-end">
                    <button onclick="testVoiceSystem()" class="btn btn-info btn-sm me-2" title="ทดสอบเสียง">
                        <i class="fas fa-volume-up me-1"></i>ทดสอบเสียง
                    </button>
                    <a href="../admin/logout.php" class="btn btn-light btn-sm">
                        <i class="fas fa-sign-out-alt me-1"></i>ออกจากระบบ
                    </a>
                </div>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo clean($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="pos-stats">
            <div class="stat-card success">
                <div class="stat-number success"><?php echo formatCurrency($stats['today_sales']); ?></div>
                <div class="stat-label">ยอดขายวันนี้</div>
            </div>
            
            <div class="stat-card primary">
                <div class="stat-number primary"><?php echo number_format($stats['today_orders']); ?></div>
                <div class="stat-label">ออเดอร์วันนี้</div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-number warning"><?php echo number_format($stats['pending_orders']); ?></div>
                <div class="stat-label">รอดำเนินการ</div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-number info"><?php echo number_format($stats['completed_today']); ?></div>
                <div class="stat-label">เสร็จสิ้นแล้ว</div>
            </div>
        </div>
        
        <!-- Quick Actions -->
        <div class="quick-actions">
            <a href="new_order.php" class="action-btn">
                <i class="fas fa-plus-circle text-success"></i>
                <strong>ออเดอร์ใหม่</strong>
                <small>สร้างออเดอร์</small>
            </a>
            
            <a href="order_list.php" class="action-btn">
                <i class="fas fa-list-alt text-primary"></i>
                <strong>รายการออเดอร์</strong>
                <small>ดูทั้งหมด</small>
            </a>
            
            <a href="queue_display.php" class="action-btn" target="_blank">
                <i class="fas fa-tv text-info"></i>
                <strong>จอแสดงคิว</strong>
                <small>หน้าจอใหญ่</small>
            </a>
            
            <a href="payment.php" class="action-btn">
                <i class="fas fa-credit-card text-warning"></i>
                <strong>รับชำระเงิน</strong>
                <small>ชำระออเดอร์</small>
            </a>
        </div>
        
        <div class="row">
            <!-- Current Queue -->
            <div class="col-lg-6 mb-3">
                <div class="pos-section">
                    <div class="section-header">
                        <span>
                            <i class="fas fa-clock me-2"></i>คิวปัจจุบัน
                        </span>
                        <span class="badge bg-primary"><?php echo count($currentQueue); ?> คิว</span>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($currentQueue)): ?>
                            <?php foreach ($currentQueue as $queue): ?>
                                <div class="queue-item">
                                    <div class="queue-info">
                                        <div class="queue-number">
                                            <?php echo clean($queue['queue_number']); ?>
                                        </div>
                                        <div class="queue-details">
                                            <h6><?php echo clean($queue['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></h6>
                                            <small>
                                                <?php echo formatCurrency($queue['total_price']); ?> • 
                                                <?php echo formatDate($queue['created_at'], 'H:i'); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div class="queue-actions">
                                        <span class="queue-status <?php echo $queue['status']; ?>">
                                            <?php echo getOrderStatusText($queue['status']); ?>
                                        </span>
                                        <button class="btn btn-sm btn-call-queue" 
                                                onclick="callQueue('<?php echo $queue['queue_number']; ?>', this)">
                                            <i class="fas fa-volume-up"></i>
                                        </button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p>ไม่มีคิวในขณะนี้</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Recent Orders -->
            <div class="col-lg-6 mb-3">
                <div class="pos-section">
                    <div class="section-header">
                        <span>
                            <i class="fas fa-shopping-cart me-2"></i>ออเดอร์ล่าสุด
                        </span>
                        <a href="order_list.php" class="btn btn-sm btn-outline-primary">
                            ดูทั้งหมด
                        </a>
                    </div>
                    <div class="section-body">
                        <?php if (!empty($recentOrders)): ?>
                            <?php foreach ($recentOrders as $order): ?>
                                <div class="order-row">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <div class="fw-semibold">
                                                <?php echo clean($order['queue_number'] ?: 'ORD-' . $order['order_id']); ?>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?> • 
                                                <?php echo formatDate($order['created_at'], 'H:i'); ?>
                                            </small>
                                        </div>
                                        <div class="text-end">
                                            <div class="fw-semibold text-success">
                                                <?php echo formatCurrency($order['total_price']); ?>
                                            </div>
                                            <span class="badge <?php echo getOrderStatusClass($order['status']); ?>">
                                                <?php echo getOrderStatusText($order['status']); ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="text-center text-muted py-4">
                                <i class="fas fa-inbox fa-2x mb-2"></i>
                                <p>ยังไม่มีออเดอร์วันนี้</p>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Voice Indicator -->
    <div id="voiceIndicator" class="voice-indicator">
        <i class="fas fa-microphone-alt me-2"></i>
        <span>กำลังประกาศคิว...</span>
    </div>
    
    <!-- Test Voice Button -->
    <div style="position: fixed; top: 10px; right: 10px; z-index: 1000;">
        <button class="btn btn-sm btn-info" onclick="testVoice()">
            <i class="fas fa-volume-up"></i> ทดสอบเสียง
        </button>
    </div>
    
    
    <!-- Toast Container -->
    <div class="toast-container"></div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Audio Utilities -->
    <script src="/pos/assets/js/audio-utils.js"></script>
    
    <!-- AI Voice System -->
    <script src="/pos/assets/js/voice.js"></script>
    
    <script>
        // Auto refresh every 30 seconds
        setInterval(function() {
            location.reload();
        }, 30000);
        
        // Global variables
        let currentQueueData = {};
        
        // ข้อความสำหรับเรียกคิว (เพื่อความหลากหลาย)
        const queueMessages = {
            call: [
                'เรียกคิวที่ {queue}',
                'ขอเรียกคิวหมายเลข {queue}',
                'คิวที่ {queue} ครับ',
                'คิวหมายเลข {queue} ค่ะ'
            ],
            ready: [
                'คิวที่ {queue} พร้อมเสิร์ฟแล้วครับ กรุณามารับที่เคาน์เตอร์',
                'คิวหมายเลข {queue} เสร็จแล้วครับ ขอเรียนเชิญมารับที่เคาน์เตอร์',
                'คิวที่ {queue} ออเดอร์เสร็จสิ้นแล้ว โปรดมารับ',
                'คิวหมายเลข {queue} พร้อมแล้วครับ ขอเรียนเชิญมาทางเคาน์เตอร์',
                'คิวที่ {queue} ออเดอร์ของท่านเสร็จสิ้นแล้ว กรุณามารับที่จุดรับออเดอร์'
            ],
            error: [
                'เกิดข้อผิดพลาดในการเรียกคิว',
                'ขออภัย ไม่สามารถเรียกคิวได้ในขณะนี้',
                'เกิดข้อผิดพลาดในการเชื่อมต่อระบบ',
                'ขออภัยครับ มีปัญหาทางเทคนิค'
            ]
        };
        
        // ฟังก์ชันสุ่มข้อความ
        function getRandomMessage(type, queueNumber = '') {
            const messages = queueMessages[type];
            const randomIndex = Math.floor(Math.random() * messages.length);
            return messages[randomIndex].replace(/{queue}/g, queueNumber);
        }
        
        // ฟังก์ชันพูดข้อความ
        function speakMessage(message) {
            console.log('speakMessage called with:', message);
            
            if (!('speechSynthesis' in window)) {
                console.error('speechSynthesis not supported');
                return;
            }
            
            // หยุดการพูดก่อนหน้า
            speechSynthesis.cancel();
            
            const speak = () => {
                console.log('Creating utterance...');
                const utterance = new SpeechSynthesisUtterance(message);
                utterance.lang = 'th-TH';
                utterance.rate = 0.8;
                utterance.volume = 1.0;
                utterance.pitch = 1.0;
                
                // event listeners
                utterance.onstart = () => console.log('Speech started');
                utterance.onend = () => console.log('Speech ended');
                utterance.onerror = (e) => console.error('Speech error:', e);
                
                // หาเสียงภาษาไทย
                const voices = speechSynthesis.getVoices();
                console.log('Available voices:', voices.length);
                
                const thaiVoice = voices.find(voice => 
                    voice.lang === 'th-TH' || 
                    voice.lang.startsWith('th') ||
                    voice.name.includes('Thai')
                );
                
                if (thaiVoice) {
                    console.log('Using Thai voice:', thaiVoice.name);
                    utterance.voice = thaiVoice;
                } else {
                    console.log('No Thai voice found, using default');
                }
                
                console.log('Starting speech...');
                speechSynthesis.speak(utterance);
            };
            
            // ถ้า voices ยังไม่โหลด ให้รอก่อน
            const voices = speechSynthesis.getVoices();
            if (voices.length === 0) {
                console.log('Voices not loaded yet, waiting...');
                speechSynthesis.addEventListener('voiceschanged', speak, { once: true });
                // fallback timeout
                setTimeout(speak, 100);
            } else {
                console.log('Voices already loaded');
                speak();
            }
        }
        
        // ฟังก์ชันทดสอบเสียง
        function testVoice() {
            console.log('Testing voice...');
            speakMessage('ทดสอบเสียงภาษาไทย สวัสดีครับ');
        }
        
        // Call queue function with enhanced audio and visual feedback
        async function callQueue(queueNumber, buttonElement) {
            // ป้องกันการกดซ้ำ
            if (buttonElement && buttonElement.disabled) return;
            
            try {
                // เปลี่ยนสถานะปุ่มและแสดงการโหลด
                if (buttonElement) {
                    buttonElement.disabled = true;
                    buttonElement.classList.add('calling');
                    const originalHTML = buttonElement.innerHTML;
                    buttonElement.innerHTML = '<span class="loading-spinner"></span>';
                }
                
                // แสดง voice indicator
                const voiceIndicator = document.getElementById('voiceIndicator');
                if (voiceIndicator) {
                    voiceIndicator.style.display = 'block';
                }
                
                // ประกาศเรียกคิวด้วยเสียงพูด (แบบง่าย)
                console.log('กำลังเรียกคิว:', queueNumber);
                const callMessage = getRandomMessage('call', queueNumber);
                console.log('ข้อความที่จะพูด:', callMessage);
                speakMessage(callMessage);
                
                // เรียก API
                const response = await fetch('/pos/api/voice_queue.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'call_queue',
                        queue_number: queueNumber
                    })
                });
                
                console.log('API Response status:', response.status);
                console.log('API Response ok:', response.ok);
                
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                
                const data = await response.json();
                console.log('API Response data:', data);
                
                if (data.success) {
                    // รอให้เสียงพูดเสร็จก่อน แล้วประกาศคิวพร้อมเสิร์ฟ
                    setTimeout(() => {
                        const readyMessage = getRandomMessage('ready', queueNumber);
                        speakMessage(readyMessage);
                    }, 2000);
                    
                    // แสดงข้อความสำเร็จ
                    showNotification('เรียกคิว ' + queueNumber + ' เรียบร้อยแล้ว', 'success');
                    
                } else {
                    // ประกาศข้อผิดพลาด
                    const errorMessage = getRandomMessage('error');
                    speakMessage(errorMessage);
                    showNotification('เกิดข้อผิดพลาด: ' + data.message, 'error');
                }
                
            } catch (error) {
                console.error('Error calling queue:', error);
                console.error('Error details:', error.message);
                
                // ประกาศข้อผิดพลาด
                const errorMessage = getRandomMessage('error');
                speakMessage(errorMessage);
                showNotification('เกิดข้อผิดพลาดในการเรียกคิว: ' + error.message, 'error');
                
            } finally {
                // คืนสถานะปุ่ม
                if (buttonElement) {
                    setTimeout(() => {
                        buttonElement.disabled = false;
                        buttonElement.classList.remove('calling');
                        buttonElement.innerHTML = '<i class="fas fa-volume-up"></i>';
                    }, 1000);
                }
                
                // ซ่อน voice indicator
                const voiceIndicator = document.getElementById('voiceIndicator');
                if (voiceIndicator) {
                    setTimeout(() => {
                        voiceIndicator.style.display = 'none';
                    }, 3000);
                }
            }
        }
        
        // แสดงการแจ้งเตือนแบบสวยงาม
        function showNotification(message, type = 'info') {
            const toastContainer = document.querySelector('.toast-container');
            
            const toast = document.createElement('div');
            toast.className = `custom-toast toast-${type}`;
            
            let icon = 'info-circle';
            let iconColor = '#3b82f6';
            if (type === 'error') {
                icon = 'exclamation-triangle';
                iconColor = '#ef4444';
            } else if (type === 'success') {
                icon = 'check-circle';
                iconColor = '#10b981';
            }
            
            toast.innerHTML = `
                <div class="d-flex align-items-center p-3">
                    <div class="me-3">
                        <i class="fas fa-${icon}" style="color: ${iconColor}; font-size: 1.2rem;"></i>
                    </div>
                    <div class="flex-grow-1">
                        <div style="font-family: 'Kanit', sans-serif; font-weight: 500;">
                            ${message}
                        </div>
                    </div>
                    <button type="button" class="btn-close btn-sm ms-3" onclick="this.parentElement.parentElement.remove()"></button>
                </div>
            `;
            
            toastContainer.appendChild(toast);
            
            // เอาออกอัตโนมัติหลัง 5 วินาที
            setTimeout(() => {
                if (toast && toast.parentNode) {
                    toast.remove();
                }
            }, 5000);
        }
        
        // Touch-friendly hover effects
        document.querySelectorAll('.action-btn, .queue-item').forEach(element => {
            element.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.98)';
            });
            
            element.addEventListener('touchend', function() {
                this.style.transform = '';
            });
        });
        
        // Initialize voice system when page loads
        document.addEventListener('DOMContentLoaded', function() {
            // เตรียม audio context เมื่อผู้ใช้กดหน้าจอครั้งแรก
            let userInteracted = false;
            const enableAudio = async () => {
                if (!userInteracted) {
                    userInteracted = true;
                    await audioUtils.requestAudioPermission();
                    
                    // ตั้งค่าเสียงพูดภาษาไทย
                    if (typeof voice !== 'undefined') {
                        voice.language = 'th-TH';
                        voice.volume = 0.8;
                        
                        // หาเสียงภาษาไทยที่ดีที่สุด
                        if (voice.speechSynthesis && voice.speechSynthesis.getVoices) {
                            const voices = voice.speechSynthesis.getVoices();
                            const thaiVoice = voices.find(v => 
                                v.lang === 'th-TH' || 
                                v.lang.startsWith('th') ||
                                v.name.includes('Thai') ||
                                v.name.includes('ไทย')
                            );
                            
                            if (thaiVoice) {
                                voice.voice = thaiVoice;
                                console.log('Thai voice selected:', thaiVoice.name);
                            } else {
                                // fallback เสียงอังกฤษ
                                const enVoice = voices.find(v => 
                                    v.lang === 'en-US' || 
                                    v.lang.startsWith('en')
                                );
                                if (enVoice) {
                                    voice.voice = enVoice;
                                    console.log('English voice selected as fallback:', enVoice.name);
                                }
                            }
                        }
                        
                        console.log('Voice system configured for Thai language');
                    }
                    
                    document.removeEventListener('click', enableAudio);
                    document.removeEventListener('touchstart', enableAudio);
                }
            };
            
            document.addEventListener('click', enableAudio);
            document.addEventListener('touchstart', enableAudio);
            
            // ตั้งค่าเสียงเมื่อ voices โหลดเสร็จ
            if (window.speechSynthesis) {
                speechSynthesis.addEventListener('voiceschanged', function() {
                    if (typeof voice !== 'undefined') {
                        const voices = speechSynthesis.getVoices();
                        const thaiVoice = voices.find(v => 
                            v.lang === 'th-TH' || 
                            v.lang.startsWith('th') ||
                            v.name.includes('Thai') ||
                            v.name.includes('ไทย')
                        );
                        
                        if (thaiVoice && voice.voice !== thaiVoice) {
                            voice.voice = thaiVoice;
                            console.log('Updated to Thai voice:', thaiVoice.name);
                        }
                    }
                });
            }
            
            console.log('POS Dashboard with Enhanced Thai Voice System loaded successfully');
        });
        
        // เพิ่ม event listeners สำหรับ popup
        document.addEventListener('DOMContentLoaded', function() {
            // ปิด popup เมื่อคลิกข้างนอก
            document.getElementById('queuePopupOverlay').addEventListener('click', function(e) {
                if (e.target === this) {
                    hideQueuePopup();
                }
            });
            
            // ปิด popup เมื่อกด Esc
            document.addEventListener('keydown', function(e) {
                if (e.key === 'Escape') {
                    hideQueuePopup();
                }
            });
        });
        
        // ฟังก์ชันทดสอบระบบเสียง
        function testVoiceSystem() {
            if (typeof voice !== 'undefined' && voice.speak) {
                const testMessage = getRandomMessage('call', 'A001');
                voice.speak(testMessage);
                
                setTimeout(() => {
                    const readyMessage = getRandomMessage('ready', 'A001');
                    voice.speak(readyMessage);
                }, 2000);
                
                showNotification('กำลังทดสอบระบบเสียง', 'info');
            } else {
                showNotification('ระบบเสียงไม่พร้อมใช้งาน', 'error');
            }
        }
    </script>
</body>
</html>