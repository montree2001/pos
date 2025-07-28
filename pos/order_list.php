<?php
/**
 * รายการออเดอร์ทั้งหมด - POS
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์
if (!isLoggedIn() || !in_array(getCurrentUserRole(), ['admin', 'staff'])) {
    header('Location: login.php');
    exit();
}

$pageTitle = 'รายการออเดอร์';

// ตัวแปรสำหรับการค้นหาและกรอง
$status = $_GET['status'] ?? 'all';
$dateFilter = $_GET['date'] ?? date('Y-m-d');
$search = trim($_GET['search'] ?? '');

// เริ่มต้นตัวแปร
$orders = [];
$error = null;

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // สร้าง SQL query
    $whereClause = "WHERE DATE(o.created_at) = ?";
    $params = [$dateFilter];
    
    if ($status !== 'all') {
        $whereClause .= " AND o.status = ?";
        $params[] = $status;
    }
    
    if (!empty($search)) {
        $whereClause .= " AND (o.queue_number LIKE ? OR o.order_id LIKE ? OR u.fullname LIKE ?)";
        $searchTerm = "%$search%";
        $params[] = $searchTerm;
        $params[] = $searchTerm;
        $params[] = $searchTerm;
    }
    
    // ดึงข้อมูลออเดอร์
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name,
               COUNT(oi.item_id) as item_count
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        $whereClause
        GROUP BY o.order_id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Order list error: " . $e->getMessage());
    $error = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
}

// สถิติสำหรับ status badges
$statusCounts = [];
try {
    $stmt = $conn->prepare("
        SELECT status, COUNT(*) as count 
        FROM orders 
        WHERE DATE(created_at) = ?
        GROUP BY status
    ");
    $stmt->execute([$dateFilter]);
    $statusResults = $stmt->fetchAll();
    
    foreach ($statusResults as $result) {
        $statusCounts[$result['status']] = $result['count'];
    }
} catch (Exception $e) {
    // Silent fail
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
    
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
    
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
        
        .filters-section {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            box-shadow: var(--pos-shadow);
            padding: 20px;
            margin-bottom: 20px;
        }
        
        .orders-section {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            box-shadow: var(--pos-shadow);
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
        
        .status-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .status-tab {
            background: #f3f4f6;
            border: none;
            border-radius: 25px;
            padding: 8px 20px;
            font-size: 0.9rem;
            text-decoration: none;
            color: #374151;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .status-tab.active,
        .status-tab:hover {
            background: var(--pos-primary);
            color: white;
        }
        
        .status-tab .badge {
            margin-left: 8px;
            background: rgba(255, 255, 255, 0.2);
        }
        
        .order-card {
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            margin-bottom: 15px;
            transition: all 0.3s ease;
        }
        
        .order-card:hover {
            border-color: var(--pos-primary);
            box-shadow: var(--pos-shadow);
            transform: translateY(-2px);
        }
        
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .queue-number {
            background: linear-gradient(135deg, var(--pos-primary), #6366f1);
            color: white;
            border-radius: 8px;
            padding: 8px 15px;
            font-weight: 700;
            font-size: 1.1rem;
        }
        
        .order-status {
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .order-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .detail-label {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .detail-value {
            font-weight: 600;
            color: #374151;
        }
        
        .order-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        
        .action-btn {
            padding: 6px 12px;
            border-radius: 6px;
            border: 1px solid #e5e7eb;
            background: var(--pos-white);
            color: #374151;
            text-decoration: none;
            font-size: 0.8rem;
            transition: all 0.3s ease;
        }
        
        .action-btn:hover {
            background: var(--pos-primary);
            color: white;
            border-color: var(--pos-primary);
        }
        
        .action-btn.btn-success {
            background: var(--pos-success);
            border-color: var(--pos-success);
            color: white;
        }
        
        .action-btn.btn-warning {
            background: var(--pos-warning);
            border-color: var(--pos-warning);
            color: white;
        }
        
        .action-btn.btn-danger {
            background: var(--pos-danger);
            border-color: var(--pos-danger);
            color: white;
        }
        
        .search-filters {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 15px;
            align-items: end;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            padding: 10px 15px;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--pos-primary);
            box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.15);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .search-filters {
                grid-template-columns: 1fr;
                gap: 10px;
            }
            
            .order-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 10px;
            }
            
            .order-details {
                grid-template-columns: 1fr;
            }
            
            .order-actions {
                justify-content: center;
            }
            
            .status-tabs {
                justify-content: center;
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
                        <i class="fas fa-list-alt me-2"></i>
                        รายการออเดอร์
                    </h1>
                    <p class="mb-0 opacity-75">จัดการและติดตามออเดอร์ทั้งหมด</p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-light">
                        <i class="fas fa-arrow-left me-1"></i>กลับ
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
        
        <!-- Filters Section -->
        <div class="filters-section">
            <form method="GET" class="search-filters">
                <div>
                    <label class="form-label">ค้นหา</label>
                    <input type="text" class="form-control" name="search" 
                           value="<?php echo clean($search); ?>" 
                           placeholder="หมายเลขคิว, ออเดอร์, หรือชื่อลูกค้า">
                </div>
                
                <div>
                    <label class="form-label">วันที่</label>
                    <input type="date" class="form-control" name="date" 
                           value="<?php echo $dateFilter; ?>">
                </div>
                
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i>ค้นหา
                    </button>
                </div>
            </form>
            
            <!-- Status Tabs -->
            <div class="status-tabs mt-3">
                <a href="?date=<?php echo $dateFilter; ?>&search=<?php echo urlencode($search); ?>&status=all" 
                   class="status-tab <?php echo $status === 'all' ? 'active' : ''; ?>">
                    ทั้งหมด
                    <span class="badge"><?php echo array_sum($statusCounts); ?></span>
                </a>
                
                <a href="?date=<?php echo $dateFilter; ?>&search=<?php echo urlencode($search); ?>&status=pending" 
                   class="status-tab <?php echo $status === 'pending' ? 'active' : ''; ?>">
                    รอยืนยัน
                    <span class="badge"><?php echo $statusCounts['pending'] ?? 0; ?></span>
                </a>
                
                <a href="?date=<?php echo $dateFilter; ?>&search=<?php echo urlencode($search); ?>&status=confirmed" 
                   class="status-tab <?php echo $status === 'confirmed' ? 'active' : ''; ?>">
                    ยืนยันแล้ว
                    <span class="badge"><?php echo $statusCounts['confirmed'] ?? 0; ?></span>
                </a>
                
                <a href="?date=<?php echo $dateFilter; ?>&search=<?php echo urlencode($search); ?>&status=preparing" 
                   class="status-tab <?php echo $status === 'preparing' ? 'active' : ''; ?>">
                    กำลังเตรียม
                    <span class="badge"><?php echo $statusCounts['preparing'] ?? 0; ?></span>
                </a>
                
                <a href="?date=<?php echo $dateFilter; ?>&search=<?php echo urlencode($search); ?>&status=ready" 
                   class="status-tab <?php echo $status === 'ready' ? 'active' : ''; ?>">
                    พร้อมเสิร์ฟ
                    <span class="badge"><?php echo $statusCounts['ready'] ?? 0; ?></span>
                </a>
                
                <a href="?date=<?php echo $dateFilter; ?>&search=<?php echo urlencode($search); ?>&status=completed" 
                   class="status-tab <?php echo $status === 'completed' ? 'active' : ''; ?>">
                    เสร็จสิ้น
                    <span class="badge"><?php echo $statusCounts['completed'] ?? 0; ?></span>
                </a>
            </div>
        </div>
        
        <!-- Orders Section -->
        <div class="orders-section">
            <div class="section-header">
                <span>
                    <i class="fas fa-shopping-cart me-2"></i>
                    ออเดอร์วันที่ <?php echo formatDate($dateFilter, 'd/m/Y'); ?>
                </span>
                <span class="badge bg-primary"><?php echo count($orders); ?> รายการ</span>
            </div>
            
            <div class="p-3">
                <?php if (empty($orders)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox fa-3x mb-3"></i>
                        <h5>ไม่พบออเดอร์</h5>
                        <p class="text-muted">ไม่มีออเดอร์ที่ตรงกับเงื่อนไขที่ค้นหา</p>
                        <a href="new_order.php" class="btn btn-primary">
                            <i class="fas fa-plus me-2"></i>สร้างออเดอร์ใหม่
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <div class="order-card">
                            <div class="order-header">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="queue-number">
                                        <?php echo clean($order['queue_number'] ?: 'ORD-' . $order['order_id']); ?>
                                    </div>
                                    <div>
                                        <div class="fw-semibold">
                                            <?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?>
                                        </div>
                                        <small class="text-muted">
                                            <?php echo formatDate($order['created_at'], 'H:i'); ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <div class="order-status <?php echo getOrderStatusClass($order['status']); ?>">
                                    <?php echo getOrderStatusText($order['status']); ?>
                                </div>
                            </div>
                            
                            <div class="order-details">
                                <div>
                                    <div class="detail-item">
                                        <span class="detail-label">ยอดรวม:</span>
                                        <span class="detail-value text-success">
                                            <?php echo formatCurrency($order['total_price']); ?>
                                        </span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">จำนวนรายการ:</span>
                                        <span class="detail-value">
                                            <?php echo number_format($order['item_count']); ?> รายการ
                                        </span>
                                    </div>
                                </div>
                                
                                <div>
                                    <div class="detail-item">
                                        <span class="detail-label">ประเภท:</span>
                                        <span class="detail-value">
                                            <?php
                                            $orderTypes = [
                                                'dine_in' => 'ทานที่ร้าน',
                                                'takeaway' => 'ซื้อกลับ',
                                                'delivery' => 'จัดส่ง'
                                            ];
                                            echo $orderTypes[$order['order_type']] ?? $order['order_type'];
                                            ?>
                                        </span>
                                    </div>
                                    <div class="detail-item">
                                        <span class="detail-label">การชำระ:</span>
                                        <span class="detail-value">
                                            <?php
                                            $paymentMethods = [
                                                'cash' => 'เงินสด',
                                                'promptpay' => 'PromptPay',
                                                'credit_card' => 'บัตรเครดิต',
                                                'line_pay' => 'LINE Pay'
                                            ];
                                            echo $paymentMethods[$order['payment_method']] ?? $order['payment_method'];
                                            ?>
                                        </span>
                                    </div>
                                </div>
                            </div>
                            
                            <?php if ($order['table_number'] || $order['notes']): ?>
                                <div class="mt-2 p-2 bg-light rounded">
                                    <?php if ($order['table_number']): ?>
                                        <small><strong>โต๊ะ:</strong> <?php echo clean($order['table_number']); ?></small><br>
                                    <?php endif; ?>
                                    <?php if ($order['notes']): ?>
                                        <small><strong>หมายเหตุ:</strong> <?php echo clean($order['notes']); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="order-actions">
                                <a href="print_receipt.php?order_id=<?php echo $order['order_id']; ?>" 
                                   class="action-btn" target="_blank">
                                    <i class="fas fa-receipt me-1"></i>ใบเสร็จ
                                </a>
                                
                                <?php if (in_array($order['status'], ['confirmed', 'preparing', 'ready'])): ?>
                                    <button class="action-btn" 
                                            onclick="callQueue('<?php echo $order['queue_number']; ?>')">
                                        <i class="fas fa-volume-up me-1"></i>เรียกคิว
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($order['status'] === 'confirmed'): ?>
                                    <button class="action-btn btn-warning" 
                                            onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'preparing')">
                                        <i class="fas fa-utensils me-1"></i>เริ่มทำ
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($order['status'] === 'preparing'): ?>
                                    <button class="action-btn btn-success" 
                                            onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'ready')">
                                        <i class="fas fa-check me-1"></i>พร้อมเสิร์ฟ
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($order['status'] === 'ready'): ?>
                                    <button class="action-btn btn-success" 
                                            onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'completed')">
                                        <i class="fas fa-check-circle me-1"></i>เสร็จสิ้น
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (in_array($order['status'], ['pending', 'confirmed'])): ?>
                                    <button class="action-btn btn-danger" 
                                            onclick="cancelOrder(<?php echo $order['order_id']; ?>)">
                                        <i class="fas fa-times me-1"></i>ยกเลิก
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Call queue function
        function callQueue(queueNumber) {
            if (confirm('ต้องการเรียกคิว ' + queueNumber + '?')) {
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
        
        // Update order status
        function updateOrderStatus(orderId, newStatus) {
            const statusText = {
                'preparing': 'เริ่มทำอาหาร',
                'ready': 'อาหารพร้อมเสิร์ฟ',
                'completed': 'เสร็จสิ้นออเดอร์'
            };
            
            if (confirm('ต้องการ' + statusText[newStatus] + '?')) {
                fetch('../api/orders.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        action: 'update_status',
                        order_id: orderId,
                        status: newStatus
                    })
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('อัปเดตสถานะเรียบร้อยแล้ว');
                        location.reload();
                    } else {
                        alert('เกิดข้อผิดพลาด: ' + data.error);
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    alert('เกิดข้อผิดพลาดในการอัปเดตสถานะ');
                });
            }
        }
        
        // Cancel order
        function cancelOrder(orderId) {
            if (confirm('ต้องการยกเลิกออเดอร์นี้? การกระทำนี้ไม่สามารถยกเลิกได้')) {
                updateOrderStatus(orderId, 'cancelled');
            }
        }
        
        // Auto refresh every 60 seconds
        setInterval(function() {
            location.reload();
        }, 60000);
        
        console.log('Order list page loaded successfully');
    </script>
</body>
</html>