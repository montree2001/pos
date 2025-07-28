<?php
/**
 * POS Dashboard หลัก
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

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
    
    <!-- Custom POS CSS -->
    <style>
        :root {
            --pos-primary: #4f46e5;
            --pos-success: #10b981;
            --pos-warning: #f59e0b;
            --pos-danger: #ef4444;
            --pos-info: #3b82f6;
            --pos-light: #f8fafc;
            --pos-white: #ffffff;
            --pos-shadow: 0 4px 20px rgba(0,0,0,0.1);
            --pos-border-radius: 16px;
        }
        
        body {
            background: var(--pos-light);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 16px;
        }
        
        .pos-container {
            padding: 15px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .pos-header {
            background: linear-gradient(135deg, var(--pos-primary), #6366f1);
            color: white;
            border-radius: var(--pos-border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--pos-shadow);
        }
        
        .pos-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-card {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            padding: 20px;
            text-align: center;
            box-shadow: var(--pos-shadow);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .pos-section {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            box-shadow: var(--pos-shadow);
            margin-bottom: 20px;
            overflow: hidden;
        }
        
        .section-header {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
            display: flex;
            justify-content: between;
            align-items: center;
        }
        
        .section-body {
            padding: 20px;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .action-btn {
            background: var(--pos-white);
            border: 2px solid #e5e7eb;
            border-radius: var(--pos-border-radius);
            padding: 20px;
            text-align: center;
            text-decoration: none;
            color: #374151;
            transition: all 0.3s ease;
            display: flex;
            flex-direction: column;
            align-items: center;
            min-height: 120px;
            justify-content: center;
        }
        
        .action-btn:hover {
            border-color: var(--pos-primary);
            background: #fafbff;
            color: var(--pos-primary);
            transform: translateY(-2px);
            box-shadow: var(--pos-shadow);
        }
        
        .action-btn i {
            font-size: 2rem;
            margin-bottom: 10px;
        }
        
        .queue-item {
            background: var(--pos-light);
            border-radius: 12px;
            padding: 15px;
            margin-bottom: 10px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            transition: all 0.3s ease;
        }
        
        .queue-item:hover {
            background: #f0f9ff;
            transform: translateX(5px);
        }
        
        .queue-number {
            background: var(--pos-primary);
            color: white;
            border-radius: 8px;
            padding: 8px 12px;
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .queue-status {
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
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
            <div class="stat-card">
                <div class="stat-number text-success"><?php echo formatCurrency($stats['today_sales']); ?></div>
                <div class="stat-label">ยอดขายวันนี้</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number text-primary"><?php echo number_format($stats['today_orders']); ?></div>
                <div class="stat-label">ออเดอร์วันนี้</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number text-warning"><?php echo number_format($stats['pending_orders']); ?></div>
                <div class="stat-label">รอดำเนินการ</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-number text-info"><?php echo number_format($stats['completed_today']); ?></div>
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
                                    <div class="d-flex align-items-center">
                                        <div class="queue-number me-3">
                                            <?php echo clean($queue['queue_number']); ?>
                                        </div>
                                        <div>
                                            <div class="fw-semibold">
                                                <?php echo clean($queue['customer_name'] ?: 'ลูกค้าทั่วไป'); ?>
                                            </div>
                                            <small class="text-muted">
                                                <?php echo formatCurrency($queue['total_price']); ?> • 
                                                <?php echo formatDate($queue['created_at'], 'H:i'); ?>
                                            </small>
                                        </div>
                                    </div>
                                    <div>
                                        <span class="queue-status <?php echo getOrderStatusClass($queue['status']); ?>">
                                            <?php echo getOrderStatusText($queue['status']); ?>
                                        </span>
                                        <button class="btn btn-sm btn-outline-primary ms-2" 
                                                onclick="callQueue('<?php echo $queue['queue_number']; ?>')">
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
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto refresh every 30 seconds
        setInterval(function() {
            location.reload();
        }, 30000);
        
        // Call queue function
        function callQueue(queueNumber) {
            if (confirm('ต้องการเรียกคิว ' + queueNumber + '?')) {
                // Call queue API
                fetch('../api/voice_queue.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'call_queue',
                        queue_number: queueNumber
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('เรียกคิว ' + queueNumber + ' เรียบร้อยแล้ว');
                    } else {
                        alert('เกิดข้อผิดพลาด: ' + data.message);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('เกิดข้อผิดพลาดในการเรียกคิว');
                });
            }
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
        
        console.log('POS Dashboard loaded successfully');
    </script>
</body>
</html>