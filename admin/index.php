<?php
/**
 * Dashboard ผู้ดูแลระบบ
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์
if (!isLoggedIn() || getCurrentUserRole() !== 'admin') {
    header('Location: login.php');
    exit();
}

$pageTitle = 'หน้าหลัก';

// เริ่มต้นตัวแปร
$stats = [
    'today_sales' => 0,
    'today_orders' => 0,
    'month_sales' => 0,
    'month_orders' => 0,
    'pending_orders' => 0,
    'total_customers' => 0
];
$recentOrders = [];
$topProducts = [];
$salesChart = [];
$queueStats = ['avg_wait_time' => 0, 'total_completed' => 0];
$error = null;

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        throw new Exception('ไม่สามารถเชื่อมต่อฐานข้อมูลได้');
    }
    
    // สถิติภาพรวม - ยอดขายวันนี้
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
    
    // ยอดขายเดือนนี้
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_price), 0) as month_sales,
               COUNT(*) as month_orders
        FROM orders 
        WHERE YEAR(created_at) = YEAR(CURDATE()) 
        AND MONTH(created_at) = MONTH(CURDATE())
        AND payment_status = 'paid'
    ");
    $stmt->execute();
    $monthData = $stmt->fetch();
    if ($monthData) {
        $stats['month_sales'] = $monthData['month_sales'];
        $stats['month_orders'] = $monthData['month_orders'];
    }
    
    // ออเดอร์ที่รอดำเนินการ
    $stmt = $conn->prepare("
        SELECT COUNT(*) as pending_orders 
        FROM orders 
        WHERE status IN ('pending', 'confirmed', 'preparing')
    ");
    $stmt->execute();
    $pendingCount = $stmt->fetchColumn();
    $stats['pending_orders'] = $pendingCount ? $pendingCount : 0;
    
    // จำนวนลูกค้าทั้งหมด
    $stmt = $conn->prepare("
        SELECT COUNT(*) as total_customers 
        FROM users 
        WHERE role = 'customer' AND status = 'active'
    ");
    $stmt->execute();
    $customerCount = $stmt->fetchColumn();
    $stats['total_customers'] = $customerCount ? $customerCount : 0;
    
    // ออเดอร์ล่าสุด
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name 
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        ORDER BY o.created_at DESC 
        LIMIT 10
    ");
    $stmt->execute();
    $recentOrders = $stmt->fetchAll();
    
    // เมนูขายดี (7 วันที่ผ่านมา)
    $stmt = $conn->prepare("
        SELECT p.name, p.price, SUM(oi.quantity) as total_sold,
               SUM(oi.subtotal) as total_revenue
        FROM order_items oi
        JOIN products p ON oi.product_id = p.product_id
        JOIN orders o ON oi.order_id = o.order_id
        WHERE o.created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND o.payment_status = 'paid'
        GROUP BY p.product_id
        ORDER BY total_sold DESC
        LIMIT 5
    ");
    $stmt->execute();
    $topProducts = $stmt->fetchAll();
    
    // ข้อมูลกราฟยอดขาย (7 วันที่ผ่านมา)
    $stmt = $conn->prepare("
        SELECT DATE(created_at) as sale_date,
               COALESCE(SUM(total_price), 0) as daily_sales,
               COUNT(*) as daily_orders
        FROM orders 
        WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 7 DAY)
        AND payment_status = 'paid'
        GROUP BY DATE(created_at)
        ORDER BY sale_date ASC
    ");
    $stmt->execute();
    $salesChart = $stmt->fetchAll();
    
    // สถิติคิว
    $stmt = $conn->prepare("
        SELECT 
            AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as avg_wait_time,
            COUNT(*) as total_completed
        FROM orders 
        WHERE status = 'completed' 
        AND DATE(created_at) = CURDATE()
    ");
    $stmt->execute();
    $queueData = $stmt->fetch();
    if ($queueData) {
        $queueStats = [
            'avg_wait_time' => $queueData['avg_wait_time'] ? round($queueData['avg_wait_time']) : 0,
            'total_completed' => $queueData['total_completed'] ? $queueData['total_completed'] : 0
        ];
    }
    
} catch (Exception $e) {
    writeLog("Dashboard error: " . $e->getMessage());
    $error = 'เกิดข้อผิดพลาดในการโหลดข้อมูล: ' . $e->getMessage();
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">Dashboard</h1>
        <p class="text-muted mb-0">
            ภาพรวมระบบ ณ วันที่ <?php echo formatDate(date('Y-m-d H:i:s'), 'd/m/Y H:i'); ?>
        </p>
    </div>
    <div>
        <button class="btn btn-primary" onclick="location.reload()">
            <i class="fas fa-sync-alt me-2"></i>รีเฟรช
        </button>
    </div>
</div>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?php echo clean($error); ?>
        <br><small>กรุณาตรวจสอบการเชื่อมต่อฐานข้อมูลและไฟล์ log</small>
    </div>
<?php endif; ?>

<!-- Stats Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo formatCurrency($stats['today_sales']); ?></div>
                    <div class="stats-label">ยอดขายวันนี้</div>
                    <small class="opacity-75"><?php echo number_format($stats['today_orders']); ?> ออเดอร์</small>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo formatCurrency($stats['month_sales']); ?></div>
                    <div class="stats-label">ยอดขายเดือนนี้</div>
                    <small class="opacity-75"><?php echo number_format($stats['month_orders']); ?> ออเดอร์</small>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-chart-line"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card warning">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['pending_orders']); ?></div>
                    <div class="stats-label">ออเดอร์รอดำเนินการ</div>
                    <small class="opacity-75">ต้องจัดการ</small>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['total_customers']); ?></div>
                    <div class="stats-label">ลูกค้าทั้งหมด</div>
                    <small class="opacity-75">ผู้ใช้งานระบบ</small>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-users"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Charts and Analytics -->
<div class="row mb-4">
    <div class="col-xl-8 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-area me-2"></i>ยอดขาย 7 วันที่ผ่านมา
                </h5>
            </div>
            <div class="card-body">
                <canvas id="salesChart" height="100"></canvas>
            </div>
        </div>
    </div>
    
    <div class="col-xl-4 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-trophy me-2"></i>เมนูขายดี
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($topProducts)): ?>
                    <?php foreach ($topProducts as $index => $product): ?>
                        <div class="d-flex align-items-center mb-3">
                            <div class="badge bg-primary rounded-pill me-3" style="width: 30px; height: 30px; display: flex; align-items: center; justify-content: center;">
                                <?php echo $index + 1; ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold"><?php echo clean($product['name']); ?></div>
                                <small class="text-muted">
                                    ขาย <?php echo number_format($product['total_sold']); ?> ชิ้น - 
                                    <?php echo formatCurrency($product['total_revenue']); ?>
                                </small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-inbox fa-2x mb-2"></i>
                        <p>ยังไม่มีข้อมูลการขาย</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Recent Orders and Queue Status -->
<div class="row">
    <div class="col-xl-8 mb-3">
        <div class="card">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="fas fa-shopping-cart me-2"></i>ออเดอร์ล่าสุด
                </h5>
                <a href="order_management.php" class="btn btn-sm btn-outline-primary">
                    ดูทั้งหมด <i class="fas fa-arrow-right ms-1"></i>
                </a>
            </div>
            <div class="card-body p-0">
                <?php if (!empty($recentOrders)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th>หมายเลขออเดอร์</th>
                                    <th>ลูกค้า</th>
                                    <th>ยอดรวม</th>
                                    <th>สถานะ</th>
                                    <th>เวลา</th>
                                    <th>การกระทำ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recentOrders as $order): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo clean($order['queue_number'] ?: 'ORD-' . $order['order_id']); ?></strong>
                                        </td>
                                        <td><?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></td>
                                        <td><?php echo formatCurrency($order['total_price']); ?></td>
                                        <td>
                                            <span class="badge <?php echo getOrderStatusClass($order['status']); ?>">
                                                <?php echo getOrderStatusText($order['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo formatDate($order['created_at'], 'H:i'); ?></td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-outline-primary" onclick="viewOrder(<?php echo $order['order_id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                                <?php if (in_array($order['status'], ['pending', 'confirmed'])): ?>
                                                    <button class="btn btn-outline-success" onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'confirmed')">
                                                        <i class="fas fa-check"></i>
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-inbox fa-2x mb-2"></i>
                        <p>ยังไม่มีออเดอร์</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-xl-4 mb-3">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-stopwatch me-2"></i>สถิติคิววันนี้
                </h5>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-6">
                        <div class="border-end">
                            <div class="h4 text-primary mb-1">
                                <?php echo $queueStats['avg_wait_time']; ?>
                            </div>
                            <small class="text-muted">นาที<br>เวลารอเฉลี่ย</small>
                        </div>
                    </div>
                    <div class="col-6">
                        <div class="h4 text-success mb-1">
                            <?php echo number_format($queueStats['total_completed']); ?>
                        </div>
                        <small class="text-muted">คิว<br>เสร็จสิ้นแล้ว</small>
                    </div>
                </div>
                
                <hr>
                
                <!-- Quick Actions -->
                <div class="d-grid gap-2">
                    <a href="../pos/" class="btn btn-primary" target="_blank">
                        <i class="fas fa-cash-register me-2"></i>เปิด POS
                    </a>
                    <a href="../kitchen/" class="btn btn-warning" target="_blank">
                        <i class="fas fa-fire me-2"></i>หน้าจอครัว
                    </a>
                    <a href="order_management.php" class="btn btn-info">
                        <i class="fas fa-list-alt me-2"></i>จัดการออเดอร์
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Order Details Modal -->
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title">รายละเอียดออเดอร์</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="orderModalBody">
                <div class="text-center">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2">กำลังโหลด...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$additionalJS = [
    'https://cdn.jsdelivr.net/npm/chart.js'
];

$inlineJS = "
// สร้างกราฟยอดขาย
const salesData = " . json_encode($salesChart) . ";
const ctx = document.getElementById('salesChart');

if (ctx) {
    const chart = new Chart(ctx, {
        type: 'line',
        data: {
            labels: salesData.map(item => {
                const date = new Date(item.sale_date);
                return date.toLocaleDateString('th-TH', { month: 'short', day: 'numeric' });
            }),
            datasets: [{
                label: 'ยอดขาย (บาท)',
                data: salesData.map(item => item.daily_sales),
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    ticks: {
                        callback: function(value) {
                            return '฿' + value.toLocaleString();
                        }
                    }
                }
            }
        }
    });
}

// ฟังก์ชันดูออเดอร์
function viewOrder(orderId) {
    const modal = new bootstrap.Modal(document.getElementById('orderModal'));
    modal.show();
    
    $('#orderModalBody').html('<div class=\"text-center\"><div class=\"spinner-border\" role=\"status\"></div><p class=\"mt-2\">กำลังโหลด...</p></div>');
    
    setTimeout(function() {
        $('#orderModalBody').html('<div class=\"alert alert-info\">ฟีเจอร์นี้จะพัฒนาเพิ่มเติม - ออเดอร์ไอดี: ' + orderId + '</div>');
    }, 1000);
}

// ฟังก์ชันอัปเดตสถานะออเดอร์
function updateOrderStatus(orderId, status) {
    if (confirm('ต้องการอัปเดตสถานะออเดอร์นี้?')) {
        alert('ฟีเจอร์นี้จะพัฒนาเพิ่มเติม - ออเดอร์ไอดี: ' + orderId + ', สถานะ: ' + status);
    }
}

console.log('Dashboard loaded successfully');
";

require_once '../includes/footer.php';
?>