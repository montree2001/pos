<?php
/**
 * หน้าหลักระบบครัว - แสดงออเดอร์ที่ต้องทำ
 * Smart Order Management System - Updated Version
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์ครัว
if (!isLoggedIn() || getCurrentUserRole() !== 'kitchen') {
    header('Location: login.php');
    exit();
}

$pageTitle = 'ระบบครัว';

// ดึงสถิติเบื้องต้น
$todayStats = [
    'total_orders' => 0,
    'preparing_orders' => 0,
    'ready_orders' => 0,
    'completed_orders' => 0,
    'avg_preparation_time' => 0
];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // สถิติวันนี้
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_orders,
            SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) as preparing_orders,
            SUM(CASE WHEN status = 'ready' THEN 1 ELSE 0 END) as ready_orders,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_orders,
            AVG(TIMESTAMPDIFF(MINUTE, created_at, 
                CASE WHEN status IN ('ready', 'completed') THEN updated_at ELSE NULL END
            )) as avg_preparation_time
        FROM orders 
        WHERE DATE(created_at) = CURDATE()
        AND payment_status = 'paid'
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    
    if ($stats) {
        $todayStats = array_merge($todayStats, $stats);
    }
    
    // จำนวนออเดอร์ที่รอดำเนินการ
    $stmt = $conn->prepare("
        SELECT COUNT(*) as active_orders
        FROM orders 
        WHERE status IN ('confirmed', 'preparing') 
        AND payment_status = 'paid'
    ");
    $stmt->execute();
    $activeCount = $stmt->fetchColumn();
    $todayStats['active_orders'] = $activeCount;
    
} catch (Exception $e) {
    writeLog("Kitchen stats error: " . $e->getMessage());
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
    
    <!-- Kitchen System CSS -->
    <link href="<?php echo SITE_URL; ?>/assets/css/kitchen.css" rel="stylesheet">
    
    <style>
        /* Additional inline styles for immediate styling */
        :root {
            --kitchen-primary: #f97316;
            --kitchen-primary-dark: #ea580c;
            --kitchen-success: #10b981;
            --kitchen-warning: #f59e0b;
            --kitchen-info: #3b82f6;
            --kitchen-light: #f8fafc;
            --kitchen-white: #ffffff;
        }
        
        body {
            background: linear-gradient(135deg, var(--kitchen-light) 0%, #e2e8f0 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .kitchen-container {
            padding: 15px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .kitchen-header {
            background: linear-gradient(135deg, var(--kitchen-primary), var(--kitchen-primary-dark));
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(249, 115, 22, 0.3);
            position: sticky;
            top: 15px;
            z-index: 100;
        }
        
        .kitchen-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .kitchen-stat-card {
            background: var(--kitchen-white);
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 20px rgba(0, 0, 0, 0.1);
            border-left: 4px solid var(--kitchen-primary);
            transition: all 0.3s ease;
        }
        
        .kitchen-stat-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0, 0, 0, 0.15);
        }
        
        .kitchen-stat-card.total { border-left-color: var(--kitchen-info); }
        .kitchen-stat-card.preparing { border-left-color: var(--kitchen-warning); }
        .kitchen-stat-card.completed { border-left-color: var(--kitchen-success); }
        .kitchen-stat-card.active { border-left-color: var(--kitchen-primary); }
        
        .kitchen-stat-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 5px;
            color: #1e293b;
        }
        
        .kitchen-stat-label {
            color: #64748b;
            font-size: 0.9rem;
            font-weight: 500;
        }
        
        .kitchen-orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }
        
        #loadingOverlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 9999;
            color: white;
        }
        
        .loading-content {
            text-align: center;
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid rgba(255, 255, 255, 0.3);
            border-left: 4px solid white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
            margin: 0 auto 15px;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .kitchen-container {
                padding: 10px;
            }
            
            .kitchen-orders-grid {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .kitchen-stats {
                grid-template-columns: repeat(2, 1fr);
                gap: 10px;
            }
        }
    </style>
</head>
<body class="kitchen-body">
    <div class="kitchen-container">
        <!-- Header -->
        <div class="kitchen-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="fas fa-fire me-2"></i>ระบบครัว
                    </h1>
                    <p class="mb-0 subtitle">จัดการออเดอร์และสถานะอาหาร</p>
                </div>
                <div class="text-end">
                    <div class="kitchen-time">
                        <div id="currentTime"><?php echo date('H:i:s'); ?></div>
                        <div class="kitchen-date"><?php echo formatDate(date('Y-m-d'), 'd/m/Y'); ?></div>
                    </div>
                </div>
            </div>
            
            <!-- Quick Actions -->
            <div class="d-flex justify-content-between align-items-center mt-3">
                <div class="d-flex gap-2">
                    <button class="btn btn-light btn-sm" data-action="refresh" title="รีเฟรช (F5)">
                        <i class="fas fa-sync-alt me-1"></i>รีเฟรช
                    </button>
                    <a href="order_status.php" class="btn btn-light btn-sm">
                        <i class="fas fa-tasks me-1"></i>จัดการสถานะ
                    </a>
                    <a href="completed_orders.php" class="btn btn-light btn-sm">
                        <i class="fas fa-check-circle me-1"></i>ออเดอร์เสร็จ
                    </a>
                </div>
                <div class="d-flex gap-2">
                    <small class="text-light opacity-75">
                        อัปเดตล่าสุด: <span id="lastRefresh"><?php echo date('H:i:s'); ?></span>
                    </small>
                    <a href="logout.php" class="btn btn-outline-light btn-sm">
                        <i class="fas fa-sign-out-alt me-1"></i>ออกจากระบบ
                    </a>
                </div>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="kitchen-stats">
            <div class="kitchen-stat-card total">
                <div class="kitchen-stat-number" id="total-orders"><?php echo $todayStats['total_orders']; ?></div>
                <div class="kitchen-stat-label">ออเดอร์วันนี้</div>
            </div>
            <div class="kitchen-stat-card preparing">
                <div class="kitchen-stat-number" id="preparing-orders"><?php echo $todayStats['preparing_orders']; ?></div>
                <div class="kitchen-stat-label">กำลังเตรียม</div>
            </div>
            <div class="kitchen-stat-card completed">
                <div class="kitchen-stat-number" id="completed-orders"><?php echo $todayStats['completed_orders']; ?></div>
                <div class="kitchen-stat-label">เสร็จแล้ว</div>
            </div>
            <div class="kitchen-stat-card active">
                <div class="kitchen-stat-number" id="active-orders"><?php echo $todayStats['active_orders']; ?></div>
                <div class="kitchen-stat-label">รอดำเนินการ</div>
            </div>
        </div>
        
        <!-- Orders Grid -->
        <div class="kitchen-orders-grid" id="ordersGrid">
            <!-- Orders will be loaded here by JavaScript -->
            <div class="d-flex justify-content-center align-items-center" style="min-height: 200px;">
                <div class="text-center text-muted">
                    <div class="loading-spinner"></div>
                    <div>กำลังโหลดข้อมูลออเดอร์...</div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay">
        <div class="loading-content">
            <div class="loading-spinner"></div>
            <div>กำลังประมวลผล...</div>
        </div>
    </div>
    
    <!-- Order Details Modal -->
    <div class="modal fade kitchen-modal" id="orderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="fas fa-receipt me-2"></i>รายละเอียดออเดอร์
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="orderDetailsBody">
                    <!-- Content will be loaded by JavaScript -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Global JavaScript Variables -->
    <script>
        const SITE_URL = '<?php echo SITE_URL; ?>';
        const USER_ROLE = '<?php echo getCurrentUserRole(); ?>';
        const USER_ID = <?php echo getCurrentUserId(); ?>;
        const IS_LOGGED_IN = true;
    </script>
    
    <!-- Kitchen System JavaScript -->
    <script src="<?php echo SITE_URL; ?>/assets/js/kitchen.js"></script>
    
    <script>
        // Additional initialization for this page
        document.addEventListener('DOMContentLoaded', function() {
            console.log('🔥 Kitchen Dashboard Ready');
            
            // Add keyboard shortcuts info
            console.log('⌨️ Keyboard Shortcuts:');
            console.log('  F5 - รีเฟรชข้อมูล');
            console.log('  ESC - ปิด Modal');
            console.log('  Space - หยุด/เริ่ม Auto Refresh');
            
            // Show welcome notification
            setTimeout(() => {
                if (window.kitchenSystem) {
                    window.kitchenSystem.showNotification('info', 'ยินดีต้อนรับสู่ระบบครัว', 3000);
                }
            }, 1000);
            
            // Add connection status indicator
            window.addEventListener('online', function() {
                document.querySelector('.kitchen-header').style.borderLeft = '6px solid #10b981';
            });
            
            window.addEventListener('offline', function() {
                document.querySelector('.kitchen-header').style.borderLeft = '6px solid #ef4444';
            });
        });
        
        // Voice synthesis support check
        if ('speechSynthesis' in window) {
            console.log('🎤 Voice synthesis supported');
        } else {
            console.log('❌ Voice synthesis not supported');
        }
        
        // Service Worker for offline support (optional)
        if ('serviceWorker' in navigator) {
            navigator.serviceWorker.register('/sw.js').then(function(registration) {
                console.log('SW registered: ', registration);
            }).catch(function(registrationError) {
                console.log('SW registration failed: ', registrationError);
            });
        }
    </script>
    
    <!-- Error handling -->
    <script>
        window.addEventListener('error', function(e) {
            console.error('Kitchen System Error:', e);
            
            // Show user-friendly error message
            if (window.kitchenSystem) {
                window.kitchenSystem.showNotification('error', 'เกิดข้อผิดพลาดในระบบ กรุณารีเฟรชหน้า');
            }
        });
        
        // Handle unhandled promise rejections
        window.addEventListener('unhandledrejection', function(e) {
            console.error('Unhandled Promise Rejection:', e);
            
            if (window.kitchenSystem) {
                window.kitchenSystem.showNotification('warning', 'การเชื่อมต่อมีปัญหา กำลังลองใหม่อัตโนมัติ');
            }
        });
    </script>
    
    <!-- Performance monitoring -->
    <script>
        // Log performance metrics
        window.addEventListener('load', function() {
            const perfData = performance.getEntriesByType('navigation')[0];
            console.log('⚡ Page Load Time:', Math.round(perfData.loadEventEnd - perfData.fetchStart) + 'ms');
        });
    </script>
</body>
</html>