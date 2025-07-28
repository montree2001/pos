<?php
/**
 * ออเดอร์ที่เสร็จแล้ว - แสดงประวัติและสถิติ
 * Smart Order Management System
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

$pageTitle = 'ออเดอร์ที่เสร็จแล้ว';

// รับค่าตัวกรอง
$filterDate = $_GET['date'] ?? date('Y-m-d');
$filterStatus = $_GET['status'] ?? 'all';
$page = max(1, intval($_GET['page'] ?? 1));
$limit = 20;
$offset = ($page - 1) * $limit;

// ดึงข้อมูลออเดอร์ที่เสร็จแล้ว
$completedOrders = [];
$totalOrders = 0;
$todayStats = [];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // สร้าง WHERE clause
    $whereConditions = ["DATE(o.created_at) = ?"];
    $params = [$filterDate];
    
    if ($filterStatus !== 'all') {
        $whereConditions[] = "o.status = ?";
        $params[] = $filterStatus;
    } else {
        $whereConditions[] = "o.status IN ('ready', 'completed')";
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // นับจำนวนทั้งหมด
    $countStmt = $conn->prepare("
        SELECT COUNT(*) as total
        FROM orders o
        WHERE $whereClause
        AND o.payment_status = 'paid'
    ");
    $countStmt->execute($params);
    $totalOrders = $countStmt->fetchColumn();
    
    // ดึงข้อมูลออเดอร์
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name, u.phone,
               COUNT(oi.item_id) as total_items,
               TIMESTAMPDIFF(MINUTE, o.created_at, o.updated_at) as preparation_time
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        WHERE $whereClause
        AND o.payment_status = 'paid'
        GROUP BY o.order_id
        ORDER BY o.updated_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->execute([...$params, $limit, $offset]);
    $completedOrders = $stmt->fetchAll();
    
    // สถิติวันนี้
    $statsStmt = $conn->prepare("
        SELECT 
            COUNT(*) as total_completed,
            AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as avg_preparation_time,
            MIN(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as min_preparation_time,
            MAX(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as max_preparation_time,
            SUM(total_price) as total_revenue
        FROM orders 
        WHERE DATE(created_at) = ?
        AND status IN ('ready', 'completed')
        AND payment_status = 'paid'
    ");
    $statsStmt->execute([$filterDate]);
    $todayStats = $statsStmt->fetch();
    
    // สถิติรายชั่วโมง
    $hourlyStmt = $conn->prepare("
        SELECT 
            HOUR(updated_at) as hour,
            COUNT(*) as completed_count
        FROM orders 
        WHERE DATE(created_at) = ?
        AND status IN ('ready', 'completed')
        AND payment_status = 'paid'
        GROUP BY HOUR(updated_at)
        ORDER BY hour ASC
    ");
    $hourlyStmt->execute([$filterDate]);
    $hourlyStats = $hourlyStmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Kitchen completed orders error: " . $e->getMessage());
    setFlashMessage('error', 'เกิดข้อผิดพลาดในการโหลดข้อมูล');
}

// คำนวณ pagination
$totalPages = ceil($totalOrders / $limit);
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
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    
    <style>
        :root {
            --primary-color: #4f46e5;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --orange-color: #f97316;
        }
        
        body {
            background: #f8fafc;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .kitchen-header {
            background: linear-gradient(135deg, var(--success-color), #059669);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(16, 185, 129, 0.3);
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            transition: transform 0.3s ease;
        }
        
        .stat-card:hover {
            transform: translateY(-2px);
        }
        
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 15px;
            font-size: 24px;
            color: white;
        }
        
        .stat-icon.completed {
            background: linear-gradient(135deg, var(--success-color), #059669);
        }
        
        .stat-icon.time {
            background: linear-gradient(135deg, var(--info-color), #2563eb);
        }
        
        .stat-icon.revenue {
            background: linear-gradient(135deg, var(--orange-color), #ea580c);
        }
        
        .stat-icon.performance {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .filters-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .order-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 20px;
            overflow: hidden;
            transition: transform 0.3s ease;
        }
        
        .order-card:hover {
            transform: translateY(-2px);
        }
        
        .order-header {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 20px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .order-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .order-time {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .order-body {
            padding: 20px;
        }
        
        .order-items {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            margin: 15px 0;
        }
        
        .item-badge {
            background: #f3f4f6;
            border: 1px solid #d1d5db;
            border-radius: 20px;
            padding: 5px 12px;
            font-size: 0.85rem;
            color: #374151;
        }
        
        .performance-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .performance-indicator.excellent {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }
        
        .performance-indicator.good {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info-color);
        }
        
        .performance-indicator.average {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }
        
        .performance-indicator.slow {
            background: rgba(239, 68, 68, 0.1);
            color: var(--danger-color);
        }
        
        .chart-container {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .order-header {
                padding: 15px;
            }
            
            .order-body {
                padding: 15px;
            }
            
            .filters-card {
                padding: 15px;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid p-3">
        <!-- Header -->
        <div class="kitchen-header">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h1 class="h3 mb-1">
                        <i class="fas fa-check-circle me-2"></i>ออเดอร์ที่เสร็จแล้ว
                    </h1>
                    <p class="mb-0 opacity-75">ประวัติและสถิติการเตรียมอาหาร</p>
                </div>
                <div class="d-flex gap-2">
                    <a href="index.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-1"></i>กลับ
                    </a>
                    <button onclick="location.reload()" class="btn btn-light">
                        <i class="fas fa-sync-alt me-1"></i>รีเฟรช
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Statistics -->
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-icon completed">
                    <i class="fas fa-check-double"></i>
                </div>
                <div class="stat-number"><?php echo number_format($todayStats['total_completed'] ?? 0); ?></div>
                <div class="stat-label">ออเดอร์เสร็จแล้ว</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon time">
                    <i class="fas fa-stopwatch"></i>
                </div>
                <div class="stat-number"><?php echo number_format($todayStats['avg_preparation_time'] ?? 0, 1); ?></div>
                <div class="stat-label">เวลาเฉลี่ย (นาที)</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon revenue">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
                <div class="stat-number"><?php echo formatCurrency($todayStats['total_revenue'] ?? 0); ?></div>
                <div class="stat-label">ยอดขายรวม</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-icon performance">
                    <i class="fas fa-chart-line"></i>
                </div>
                <div class="stat-number">
                    <?php 
                    $minTime = $todayStats['min_preparation_time'] ?? 0;
                    $maxTime = $todayStats['max_preparation_time'] ?? 0;
                    echo $minTime . '-' . $maxTime;
                    ?>
                </div>
                <div class="stat-label">ช่วงเวลา (นาที)</div>
            </div>
        </div>
        
        <!-- Hourly Chart -->
        <?php if (!empty($hourlyStats)): ?>
            <div class="chart-container">
                <h5 class="mb-3">
                    <i class="fas fa-chart-bar me-2"></i>ออเดอร์ที่เสร็จตามชั่วโมง
                </h5>
                <canvas id="hourlyChart" height="100"></canvas>
            </div>
        <?php endif; ?>
        
        <!-- Filters -->
        <div class="filters-card">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="date" class="form-label">วันที่</label>
                    <input type="date" class="form-control" id="date" name="date" value="<?php echo $filterDate; ?>">
                </div>
                
                <div class="col-md-3">
                    <label for="status" class="form-label">สถานะ</label>
                    <select class="form-select" id="status" name="status">
                        <option value="all" <?php echo $filterStatus === 'all' ? 'selected' : ''; ?>>ทั้งหมด</option>
                        <option value="ready" <?php echo $filterStatus === 'ready' ? 'selected' : ''; ?>>พร้อมเสิร์ฟ</option>
                        <option value="completed" <?php echo $filterStatus === 'completed' ? 'selected' : ''; ?>>เสร็จสิ้น</option>
                    </select>
                </div>
                
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>ค้นหา
                    </button>
                    <a href="?" class="btn btn-outline-secondary">
                        <i class="fas fa-times me-1"></i>ล้าง
                    </a>
                </div>
                
                <div class="col-md-3 text-end">
                    <span class="text-muted">ทั้งหมด <?php echo number_format($totalOrders); ?> ออเดอร์</span>
                </div>
            </form>
        </div>
        
        <!-- Orders List -->
        <?php if (empty($completedOrders)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox fa-4x mb-3"></i>
                <h4>ไม่พบออเดอร์</h4>
                <p>ไม่มีออเดอร์ที่เสร็จแล้วในวันที่เลือก</p>
            </div>
        <?php else: ?>
            <div class="row">
                <?php foreach ($completedOrders as $order): ?>
                    <?php
                    // คำนวณประสิทธิภาพ
                    $prepTime = $order['preparation_time'] ?? 0;
                    $performance = '';
                    $performanceClass = '';
                    
                    if ($prepTime <= 10) {
                        $performance = 'ยอดเยี่ยม';
                        $performanceClass = 'excellent';
                    } elseif ($prepTime <= 15) {
                        $performance = 'ดี';
                        $performanceClass = 'good';
                    } elseif ($prepTime <= 20) {
                        $performance = 'ปานกลาง';
                        $performanceClass = 'average';
                    } else {
                        $performance = 'ช้า';
                        $performanceClass = 'slow';
                    }
                    ?>
                    <div class="col-lg-6 col-xl-4">
                        <div class="order-card">
                            <div class="order-header">
                                <div class="d-flex justify-content-between align-items-start">
                                    <div>
                                        <div class="order-number">
                                            คิว <?php echo clean($order['queue_number'] ?: 'ORD-' . $order['order_id']); ?>
                                        </div>
                                        <div class="order-time">
                                            <i class="fas fa-clock me-1"></i>
                                            <?php echo formatDate($order['created_at'], 'H:i'); ?> - 
                                            <?php echo formatDate($order['updated_at'], 'H:i'); ?>
                                        </div>
                                    </div>
                                    <div class="text-end">
                                        <span class="badge bg-<?php echo getOrderStatusClass($order['status']); ?>">
                                            <?php echo getOrderStatusText($order['status']); ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="order-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <small class="text-muted">ลูกค้า</small>
                                        <div class="fw-semibold"><?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></div>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted">ยอดรวม</small>
                                        <div class="fw-semibold text-success"><?php echo formatCurrency($order['total_price']); ?></div>
                                    </div>
                                </div>
                                
                                <div class="row mt-2">
                                    <div class="col-md-6">
                                        <small class="text-muted">จำนวนรายการ</small>
                                        <div class="fw-semibold">
                                            <i class="fas fa-utensils me-1"></i>
                                            <?php echo $order['total_items']; ?> รายการ
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <small class="text-muted">เวลาเตรียม</small>
                                        <div class="fw-semibold">
                                            <i class="fas fa-stopwatch me-1"></i>
                                            <?php echo $prepTime; ?> นาที
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="d-flex justify-content-between align-items-center mt-3">
                                    <div class="performance-indicator <?php echo $performanceClass; ?>">
                                        <i class="fas fa-<?php echo $performanceClass === 'excellent' ? 'star' : ($performanceClass === 'good' ? 'thumbs-up' : ($performanceClass === 'average' ? 'minus' : 'clock')); ?>"></i>
                                        <?php echo $performance; ?>
                                    </div>
                                    
                                    <button onclick="viewOrderDetails(<?php echo $order['order_id']; ?>)" 
                                            class="btn btn-outline-primary btn-sm">
                                        <i class="fas fa-eye me-1"></i>ดูรายละเอียด
                                    </button>
                                </div>
                                
                                <?php if ($order['notes']): ?>
                                    <div class="alert alert-info alert-sm mt-2">
                                        <i class="fas fa-sticky-note me-1"></i>
                                        <strong>หมายเหตุ:</strong> <?php echo clean($order['notes']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Pagination -->
            <?php if ($totalPages > 1): ?>
                <nav aria-label="Pagination" class="mt-4">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <i class="fas fa-chevron-left"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php
                        $start = max(1, $page - 2);
                        $end = min($totalPages, $page + 2);
                        
                        for ($i = $start; $i <= $end; $i++):
                        ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <?php if ($page < $totalPages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
    
    <!-- Order Details Modal -->
    <div class="modal fade" id="orderDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">รายละเอียดออเดอร์</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body" id="orderDetailsBody">
                    <!-- จะโหลดด้วย AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // สร้างกราฟรายชั่วโมง
        <?php if (!empty($hourlyStats)): ?>
        const hourlyData = <?php echo json_encode($hourlyStats); ?>;
        const ctx = document.getElementById('hourlyChart');
        
        // สร้างข้อมูล 24 ชั่วโมง
        const hours = Array.from({length: 24}, (_, i) => i);
        const hourlyCount = hours.map(hour => {
            const found = hourlyData.find(item => parseInt(item.hour) === hour);
            return found ? parseInt(found.completed_count) : 0;
        });
        
        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: hours.map(h => h.toString().padStart(2, '0') + ':00'),
                datasets: [{
                    label: 'ออเดอร์ที่เสร็จ',
                    data: hourlyCount,
                    backgroundColor: 'rgba(16, 185, 129, 0.8)',
                    borderColor: 'rgba(16, 185, 129, 1)',
                    borderWidth: 1,
                    borderRadius: 4
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
                            stepSize: 1
                        }
                    },
                    x: {
                        grid: {
                            display: false
                        }
                    }
                }
            }
        });
        <?php endif; ?>
        
        // ดูรายละเอียดออเดอร์
        function viewOrderDetails(orderId) {
            const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
            modal.show();
            
            document.getElementById('orderDetailsBody').innerHTML = `
                <div class="text-center">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2">กำลังโหลดข้อมูล...</p>
                </div>
            `;
            
            fetch(`../api/orders.php?action=get&id=${orderId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        displayOrderDetails(data.order);
                    } else {
                        document.getElementById('orderDetailsBody').innerHTML = `
                            <div class="alert alert-danger">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                ไม่สามารถโหลดข้อมูลได้
                            </div>
                        `;
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    document.getElementById('orderDetailsBody').innerHTML = `
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            เกิดข้อผิดพลาดในการเชื่อมต่อ
                        </div>
                    `;
                });
        }
        
        // แสดงรายละเอียดออเดอร์
        function displayOrderDetails(order) {
            let itemsHtml = '';
            if (order.items && order.items.length > 0) {
                itemsHtml = order.items.map(item => `
                    <tr>
                        <td>
                            <div class="d-flex align-items-center">
                                ${item.image ? `<img src="../uploads/menu_images/${item.image}" class="me-2" style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px;">` : ''}
                                <div>
                                    <div class="fw-semibold">${item.product_name}</div>
                                    ${item.notes ? `<small class="text-muted">${item.notes}</small>` : ''}
                                </div>
                            </div>
                        </td>
                        <td class="text-center">
                            <span class="badge bg-primary">${item.quantity}</span>
                        </td>
                        <td class="text-end">
                            ${formatCurrency(item.subtotal)}
                        </td>
                    </tr>
                `).join('');
            }
            
            // คำนวณเวลาเตรียม
            const startTime = new Date(order.created_at);
            const endTime = new Date(order.updated_at);
            const prepTime = Math.round((endTime - startTime) / (1000 * 60));
            
            document.getElementById('orderDetailsBody').innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-info-circle me-2"></i>ข้อมูลออเดอร์</h6>
                        <table class="table table-sm">
                            <tr><td><strong>หมายเลขคิว:</strong></td><td>${order.queue_number || 'ORD-' + order.order_id}</td></tr>
                            <tr><td><strong>ลูกค้า:</strong></td><td>${order.customer_name || 'ลูกค้าทั่วไป'}</td></tr>
                            <tr><td><strong>เวลาสั่ง:</strong></td><td>${formatDateTime(order.created_at)}</td></tr>
                            <tr><td><strong>เวลาเสร็จ:</strong></td><td>${formatDateTime(order.updated_at)}</td></tr>
                            <tr><td><strong>เวลาเตรียม:</strong></td><td class="text-primary fw-bold">${prepTime} นาที</td></tr>
                            <tr><td><strong>ยอดรวม:</strong></td><td class="text-success fw-bold">${formatCurrency(order.total_price)}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-chart-line me-2"></i>ประสิทธิภาพ</h6>
                        <div class="mb-3">
                            ${getPerformanceHTML(prepTime)}
                        </div>
                        ${order.notes ? `
                            <div class="alert alert-warning alert-sm">
                                <i class="fas fa-sticky-note me-2"></i>
                                <strong>หมายเหตุ:</strong> ${order.notes}
                            </div>
                        ` : ''}
                    </div>
                </div>
                
                <hr>
                
                <h6><i class="fas fa-utensils me-2"></i>รายการอาหาร</h6>
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead class="table-light">
                            <tr>
                                <th>รายการ</th>
                                <th class="text-center">จำนวน</th>
                                <th class="text-end">ราคา</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        // ฟังก์ชันช่วย
        function formatCurrency(amount) {
            return new Intl.NumberFormat('th-TH', {
                style: 'currency',
                currency: 'THB'
            }).format(amount);
        }
        
        function formatDateTime(dateString) {
            return new Date(dateString).toLocaleString('th-TH');
        }
        
        function getPerformanceHTML(minutes) {
            let performance, className, icon;
            
            if (minutes <= 10) {
                performance = 'ยอดเยี่ยม';
                className = 'success';
                icon = 'star';
            } else if (minutes <= 15) {
                performance = 'ดี';
                className = 'info';
                icon = 'thumbs-up';
            } else if (minutes <= 20) {
                performance = 'ปานกลาง';
                className = 'warning';
                icon = 'minus';
            } else {
                performance = 'ช้า';
                className = 'danger';
                icon = 'clock';
            }
            
            return `
                <div class="alert alert-${className} d-flex align-items-center">
                    <i class="fas fa-${icon} me-2"></i>
                    <strong>${performance}</strong>
                    <small class="ms-2">(${minutes} นาที)</small>
                </div>
            `;
        }
        
        // Auto refresh ทุก 5 นาที
        setInterval(() => {
            location.reload();
        }, 300000);
        
        console.log('Kitchen Completed Orders loaded');
    </script>
</body>
</html>