<?php
/**
 * รายงานและสถิติ
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

$pageTitle = 'รายงานและสถิติ';

// ตั้งค่าช่วงวันที่
$startDate = $_GET['start_date'] ?? date('Y-m-01'); // วันแรกของเดือน
$endDate = $_GET['end_date'] ?? date('Y-m-d'); // วันนี้
$reportType = $_GET['report_type'] ?? 'sales';

// Validate dates
if (strtotime($startDate) > strtotime($endDate)) {
    $temp = $startDate;
    $startDate = $endDate;
    $endDate = $temp;
}

// เริ่มต้นตัวแปร
$salesData = [];
$productData = [];
$queueData = [];
$customerData = [];
$summaryStats = [
    'total_sales' => 0,
    'total_orders' => 0,
    'avg_order_value' => 0,
    'total_customers' => 0,
    'avg_queue_time' => 0
];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // สถิติสรุป
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(total_price), 0) as total_sales,
            COUNT(*) as total_orders,
            COALESCE(AVG(total_price), 0) as avg_order_value
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND payment_status = 'paid'
    ");
    $stmt->execute([$startDate, $endDate]);
    $salesSummary = $stmt->fetch();
    
    $summaryStats['total_sales'] = $salesSummary['total_sales'];
    $summaryStats['total_orders'] = $salesSummary['total_orders'];
    $summaryStats['avg_order_value'] = $salesSummary['avg_order_value'];
    
    // จำนวนลูกค้า
    $stmt = $conn->prepare("
        SELECT COUNT(DISTINCT user_id) as total_customers
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND user_id IS NOT NULL
    ");
    $stmt->execute([$startDate, $endDate]);
    $customerSummary = $stmt->fetch();
    $summaryStats['total_customers'] = $customerSummary['total_customers'];
    
    // เวลารอเฉลี่ย
    $stmt = $conn->prepare("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as avg_queue_time
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND status = 'completed'
        AND updated_at IS NOT NULL
    ");
    $stmt->execute([$startDate, $endDate]);
    $queueSummary = $stmt->fetch();
    $summaryStats['avg_queue_time'] = $queueSummary['avg_queue_time'] ?: 0;
    
    // ข้อมูลยอดขายรายวัน
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as sale_date,
            COALESCE(SUM(total_price), 0) as daily_sales,
            COUNT(*) as daily_orders
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND payment_status = 'paid'
        GROUP BY DATE(created_at)
        ORDER BY sale_date ASC
    ");
    $stmt->execute([$startDate, $endDate]);
    $salesData = $stmt->fetchAll();
    
    // สินค้าขายดี
    $stmt = $conn->prepare("
        SELECT 
            p.name,
            p.price,
            SUM(oi.quantity) as total_sold,
            SUM(oi.subtotal) as total_revenue
        FROM order_items oi
        JOIN products p ON oi.product_id = p.product_id
        JOIN orders o ON oi.order_id = o.order_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        AND o.payment_status = 'paid'
        GROUP BY p.product_id
        ORDER BY total_sold DESC
        LIMIT 10
    ");
    $stmt->execute([$startDate, $endDate]);
    $productData = $stmt->fetchAll();
    
    // ข้อมูลคิวรายชั่วโมง
    $stmt = $conn->prepare("
        SELECT 
            HOUR(created_at) as hour,
            COUNT(*) as order_count,
            AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as avg_wait_time
        FROM orders 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND status = 'completed'
        GROUP BY HOUR(created_at)
        ORDER BY hour ASC
    ");
    $stmt->execute([$startDate, $endDate]);
    $queueData = $stmt->fetchAll();
    
    // ข้อมูลลูกค้าใหม่
    $stmt = $conn->prepare("
        SELECT 
            DATE(created_at) as date,
            COUNT(*) as new_customers
        FROM users 
        WHERE DATE(created_at) BETWEEN ? AND ?
        AND role = 'customer'
        GROUP BY DATE(created_at)
        ORDER BY date ASC
    ");
    $stmt->execute([$startDate, $endDate]);
    $customerData = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Error loading reports: " . $e->getMessage());
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลรายงานได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">รายงานและสถิติ</h1>
        <p class="text-muted mb-0">
            ข้อมูลระหว่างวันที่ <?php echo formatDate($startDate, 'd/m/Y'); ?> - <?php echo formatDate($endDate, 'd/m/Y'); ?>
        </p>
    </div>
    <div>
        <button class="btn btn-success" onclick="exportReport()">
            <i class="fas fa-download me-2"></i>ส่งออกรายงาน
        </button>
    </div>
</div>

<!-- Date Range Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="start_date" class="form-label">วันที่เริ่มต้น</label>
                <input type="date" class="form-control" id="start_date" name="start_date" value="<?php echo $startDate; ?>">
            </div>
            <div class="col-md-3">
                <label for="end_date" class="form-label">วันที่สิ้นสุด</label>
                <input type="date" class="form-control" id="end_date" name="end_date" value="<?php echo $endDate; ?>">
            </div>
            <div class="col-md-3">
                <label for="report_type" class="form-label">ประเภทรายงาน</label>
                <select class="form-select" id="report_type" name="report_type">
                    <option value="sales" <?php echo $reportType === 'sales' ? 'selected' : ''; ?>>รายงานยอดขาย</option>
                    <option value="products" <?php echo $reportType === 'products' ? 'selected' : ''; ?>>รายงานสินค้า</option>
                    <option value="queue" <?php echo $reportType === 'queue' ? 'selected' : ''; ?>>รายงานคิว</option>
                    <option value="customers" <?php echo $reportType === 'customers' ? 'selected' : ''; ?>>รายงานลูกค้า</option>
                </select>
            </div>
            <div class="col-md-3">
                <button type="submit" class="btn btn-primary w-100">
                    <i class="fas fa-search me-2"></i>ดูรายงาน
                </button>
            </div>
        </form>
    </div>
</div>

<!-- Summary Statistics -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo formatCurrency($summaryStats['total_sales']); ?></div>
                    <div class="stats-label">ยอดขายรวม</div>
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
                    <div class="stats-number"><?php echo number_format($summaryStats['total_orders']); ?></div>
                    <div class="stats-label">จำนวนออเดอร์</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-shopping-cart"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card warning">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo formatCurrency($summaryStats['avg_order_value']); ?></div>
                    <div class="stats-label">ยอดเฉลี่ยต่อออเดอร์</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-calculator"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo round($summaryStats['avg_queue_time']); ?></div>
                    <div class="stats-label">เวลารอเฉลี่ย (นาที)</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Sales Chart -->
    <div class="col-xl-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-line me-2"></i>กราฟยอดขายรายวัน
                </h5>
            </div>
            <div class="card-body">
                <canvas id="salesChart" height="100"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Top Products -->
    <div class="col-xl-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-trophy me-2"></i>สินค้าขายดี
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($productData)): ?>
                    <?php foreach ($productData as $index => $product): ?>
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
                        <p>ไม่มีข้อมูลการขาย</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Queue Analysis -->
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-clock me-2"></i>การวิเคราะห์คิวตามชั่วโมง
                </h5>
            </div>
            <div class="card-body">
                <canvas id="queueChart" height="150"></canvas>
            </div>
        </div>
    </div>
    
    <!-- Customer Growth -->
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-users me-2"></i>การเติบโตของลูกค้า
                </h5>
            </div>
            <div class="card-body">
                <canvas id="customerChart" height="150"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Detailed Tables -->
<div class="row">
    <div class="col-12">
        <div class="card">
            <div class="card-header">
                <ul class="nav nav-tabs card-header-tabs" id="reportTabs" role="tablist">
                    <li class="nav-item" role="presentation">
                        <button class="nav-link active" id="sales-tab" data-bs-toggle="tab" data-bs-target="#sales" type="button" role="tab">
                            ยอดขายรายวัน
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="products-tab" data-bs-toggle="tab" data-bs-target="#products" type="button" role="tab">
                            สินค้าขายดี
                        </button>
                    </li>
                    <li class="nav-item" role="presentation">
                        <button class="nav-link" id="queue-tab" data-bs-toggle="tab" data-bs-target="#queue" type="button" role="tab">
                            สถิติคิว
                        </button>
                    </li>
                </ul>
            </div>
            <div class="card-body">
                <div class="tab-content" id="reportTabsContent">
                    <!-- Sales Table -->
                    <div class="tab-pane fade show active" id="sales" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover" id="salesTable">
                                <thead>
                                    <tr>
                                        <th>วันที่</th>
                                        <th>จำนวนออเดอร์</th>
                                        <th>ยอดขาย</th>
                                        <th>ยอดเฉลี่ยต่อออเดอร์</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($salesData as $sale): ?>
                                        <tr>
                                            <td><?php echo formatDate($sale['sale_date'], 'd/m/Y'); ?></td>
                                            <td><?php echo number_format($sale['daily_orders']); ?></td>
                                            <td><?php echo formatCurrency($sale['daily_sales']); ?></td>
                                            <td><?php echo formatCurrency($sale['daily_orders'] > 0 ? $sale['daily_sales'] / $sale['daily_orders'] : 0); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Products Table -->
                    <div class="tab-pane fade" id="products" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover" id="productsTable">
                                <thead>
                                    <tr>
                                        <th>อันดับ</th>
                                        <th>ชื่อสินค้า</th>
                                        <th>ราคา</th>
                                        <th>จำนวนที่ขาย</th>
                                        <th>รายได้</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($productData as $index => $product): ?>
                                        <tr>
                                            <td><?php echo $index + 1; ?></td>
                                            <td><?php echo clean($product['name']); ?></td>
                                            <td><?php echo formatCurrency($product['price']); ?></td>
                                            <td><?php echo number_format($product['total_sold']); ?></td>
                                            <td><?php echo formatCurrency($product['total_revenue']); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    
                    <!-- Queue Table -->
                    <div class="tab-pane fade" id="queue" role="tabpanel">
                        <div class="table-responsive">
                            <table class="table table-hover" id="queueTable">
                                <thead>
                                    <tr>
                                        <th>ชั่วโมง</th>
                                        <th>จำนวนออเดอร์</th>
                                        <th>เวลารอเฉลี่ย (นาที)</th>
                                        <th>ช่วงเวลา</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($queueData as $queue): ?>
                                        <tr>
                                            <td><?php echo str_pad($queue['hour'], 2, '0', STR_PAD_LEFT) . ':00'; ?></td>
                                            <td><?php echo number_format($queue['order_count']); ?></td>
                                            <td><?php echo round($queue['avg_wait_time'] ?: 0); ?></td>
                                            <td>
                                                <?php
                                                $hour = $queue['hour'];
                                                if ($hour >= 6 && $hour < 12) echo '<span class="badge bg-warning">เช้า</span>';
                                                elseif ($hour >= 12 && $hour < 18) echo '<span class="badge bg-danger">บ่าย</span>';
                                                elseif ($hour >= 18 && $hour < 22) echo '<span class="badge bg-info">เย็น</span>';
                                                else echo '<span class="badge bg-secondary">กลางคืน</span>';
                                                ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$additionalJS = [
    'https://cdn.jsdelivr.net/npm/chart.js',
    'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js'
];

$inlineJS = "
// Prepare data for charts
const salesData = " . json_encode($salesData) . ";
const queueData = " . json_encode($queueData) . ";
const customerData = " . json_encode($customerData) . ";

// Sales Chart
const salesCtx = document.getElementById('salesChart');
if (salesCtx) {
    new Chart(salesCtx, {
        type: 'line',
        data: {
            labels: salesData.map(item => {
                const date = new Date(item.sale_date);
                return date.toLocaleDateString('th-TH', { month: 'short', day: 'numeric' });
            }),
            datasets: [{
                label: 'ยอดขาย (บาท)',
                data: salesData.map(item => item.daily_sales),
                borderColor: '#10b981',
                backgroundColor: 'rgba(16, 185, 129, 0.1)',
                tension: 0.4,
                fill: true
            }, {
                label: 'จำนวนออเดอร์',
                data: salesData.map(item => item.daily_orders),
                borderColor: '#4f46e5',
                backgroundColor: 'rgba(79, 70, 229, 0.1)',
                tension: 0.4,
                yAxisID: 'y1'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    type: 'linear',
                    display: true,
                    position: 'left',
                    ticks: {
                        callback: function(value) {
                            return '฿' + value.toLocaleString();
                        }
                    }
                },
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    grid: {
                        drawOnChartArea: false,
                    },
                }
            }
        }
    });
}

// Queue Chart
const queueCtx = document.getElementById('queueChart');
if (queueCtx) {
    new Chart(queueCtx, {
        type: 'bar',
        data: {
            labels: queueData.map(item => item.hour + ':00'),
            datasets: [{
                label: 'จำนวนออเดอร์',
                data: queueData.map(item => item.order_count),
                backgroundColor: '#f59e0b',
                borderColor: '#d97706',
                borderWidth: 1
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// Customer Chart
const customerCtx = document.getElementById('customerChart');
if (customerCtx) {
    new Chart(customerCtx, {
        type: 'line',
        data: {
            labels: customerData.map(item => {
                const date = new Date(item.date);
                return date.toLocaleDateString('th-TH', { month: 'short', day: 'numeric' });
            }),
            datasets: [{
                label: 'ลูกค้าใหม่',
                data: customerData.map(item => item.new_customers),
                borderColor: '#3b82f6',
                backgroundColor: 'rgba(59, 130, 246, 0.1)',
                tension: 0.4,
                fill: true
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                y: {
                    beginAtZero: true
                }
            }
        }
    });
}

// Initialize DataTables
$('#salesTable, #productsTable, #queueTable').DataTable({
    language: {
        url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
    },
    pageLength: 25,
    order: [[0, 'desc']]
});

// Export function
function exportReport() {
    const startDate = $('#start_date').val();
    const endDate = $('#end_date').val();
    const reportType = $('#report_type').val();
    
    window.open(SITE_URL + '/api/export_report.php?start_date=' + startDate + '&end_date=' + endDate + '&type=' + reportType, '_blank');
}

console.log('Reports loaded successfully');
";

require_once '../includes/footer.php';
?>