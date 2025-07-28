<?php
/**
 * หน้าหลักระบบครัว - แสดงออเดอร์ที่ต้องทำ
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

$pageTitle = 'ระบบครัว';

// ดึงข้อมูลออเดอร์ที่ต้องทำ
$activeOrders = [];
$todayStats = ['total' => 0, 'preparing' => 0, 'completed' => 0];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงออเดอร์ที่ต้องดำเนินการ
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name, u.phone,
               COUNT(oi.item_id) as total_items,
               SUM(CASE WHEN oi.status = 'completed' THEN 1 ELSE 0 END) as completed_items
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        WHERE o.status IN ('confirmed', 'preparing') 
        AND o.payment_status = 'paid'
        GROUP BY o.order_id
        ORDER BY o.created_at ASC
    ");
    $stmt->execute();
    $activeOrders = $stmt->fetchAll();
    
    // สถิติวันนี้
    $stmt = $conn->prepare("
        SELECT 
            COUNT(*) as total,
            SUM(CASE WHEN status = 'preparing' THEN 1 ELSE 0 END) as preparing,
            SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
        FROM orders 
        WHERE DATE(created_at) = CURDATE()
        AND payment_status = 'paid'
    ");
    $stmt->execute();
    $stats = $stmt->fetch();
    if ($stats) {
        $todayStats = $stats;
    }
    
} catch (Exception $e) {
    writeLog("Kitchen error: " . $e->getMessage());
    setFlashMessage('error', 'เกิดข้อผิดพลาดในการโหลดข้อมูล');
}

$additionalCSS = [
    SITE_URL . '/assets/css/kitchen.css'
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
    
    <!-- Custom Kitchen CSS -->
    <style>
        :root {
            --primary-color: #4f46e5;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --orange-color: #f97316;
            --white: #ffffff;
            --light-bg: #f8fafc;
            --border-color: #e5e7eb;
            --text-color: #1f2937;
            --text-muted: #6b7280;
        }
        
        body {
            background: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .kitchen-header {
            background: linear-gradient(135deg, var(--orange-color), #ea580c);
            color: white;
            padding: 20px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 20px rgba(249, 115, 22, 0.3);
        }
        
        .kitchen-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid var(--primary-color);
        }
        
        .stat-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .stat-label {
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .orders-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .order-card {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: all 0.3s ease;
            border-left: 6px solid var(--warning-color);
        }
        
        .order-card.preparing {
            border-left-color: var(--info-color);
        }
        
        .order-card.ready {
            border-left-color: var(--success-color);
        }
        
        .order-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(0,0,0,0.15);
        }
        
        .order-header {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 15px 20px;
            border-bottom: 1px solid var(--border-color);
        }
        
        .order-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        .order-time {
            font-size: 0.85rem;
            color: var(--text-muted);
        }
        
        .order-body {
            padding: 20px;
        }
        
        .order-items {
            margin: 15px 0;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 8px 0;
            border-bottom: 1px solid #f1f5f9;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .item-name {
            font-weight: 600;
            flex: 1;
        }
        
        .item-quantity {
            background: var(--primary-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
            margin: 0 10px;
        }
        
        .item-status {
            font-size: 0.8rem;
        }
        
        .order-footer {
            background: #f8fafc;
            padding: 15px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .status-buttons {
            display: flex;
            gap: 10px;
        }
        
        .btn-status {
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
        }
        
        .btn-accept {
            background: var(--info-color);
            color: white;
        }
        
        .btn-prepare {
            background: var(--warning-color);
            color: white;
        }
        
        .btn-ready {
            background: var(--success-color);
            color: white;
        }
        
        .btn-status:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .progress-bar-custom {
            height: 6px;
            border-radius: 3px;
            background: #e5e7eb;
            margin: 10px 0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--warning-color), var(--success-color));
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        .customer-info {
            font-size: 0.9rem;
            color: var(--text-muted);
            margin-bottom: 10px;
        }
        
        .preparation-time {
            display: flex;
            align-items: center;
            gap: 5px;
            color: var(--text-muted);
            font-size: 0.9rem;
        }
        
        .time-badge {
            background: var(--warning-color);
            color: white;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 0.8rem;
        }
        
        .urgent {
            background: var(--danger-color) !important;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.7; }
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .orders-grid {
                grid-template-columns: 1fr;
            }
            
            .kitchen-stats {
                grid-template-columns: repeat(2, 1fr);
            }
            
            .order-header {
                padding: 12px 15px;
            }
            
            .order-body {
                padding: 15px;
            }
            
            .status-buttons {
                flex-wrap: wrap;
            }
            
            .btn-status {
                flex: 1;
                min-width: 80px;
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
                        <i class="fas fa-fire me-2"></i>ระบบครัว
                    </h1>
                    <p class="mb-0 opacity-75">จัดการออเดอร์และสถานะอาหาร</p>
                </div>
                <div class="text-end">
                    <div class="h5 mb-1" id="currentTime"><?php echo date('H:i:s'); ?></div>
                    <small class="opacity-75"><?php echo formatDate(date('Y-m-d'), 'd/m/Y'); ?></small>
                </div>
            </div>
        </div>
        
        <!-- Stats -->
        <div class="kitchen-stats">
            <div class="stat-card">
                <div class="stat-number text-primary"><?php echo $todayStats['total']; ?></div>
                <div class="stat-label">ออเดอร์วันนี้</div>
            </div>
            <div class="stat-card">
                <div class="stat-number text-warning"><?php echo $todayStats['preparing']; ?></div>
                <div class="stat-label">กำลังเตรียม</div>
            </div>
            <div class="stat-card">
                <div class="stat-number text-success"><?php echo $todayStats['completed']; ?></div>
                <div class="stat-label">เสร็จแล้ว</div>
            </div>
            <div class="stat-card">
                <div class="stat-number text-info"><?php echo count($activeOrders); ?></div>
                <div class="stat-label">รอดำเนินการ</div>
            </div>
        </div>
        
        <!-- Orders Grid -->
        <?php if (empty($activeOrders)): ?>
            <div class="text-center py-5">
                <div class="mb-3">
                    <i class="fas fa-clipboard-check fa-4x text-success"></i>
                </div>
                <h4 class="text-success">ไม่มีออเดอร์ที่ต้องดำเนินการ</h4>
                <p class="text-muted">ออเดอร์ทั้งหมดเสร็จสิ้นแล้ว</p>
                <button onclick="location.reload()" class="btn btn-primary">
                    <i class="fas fa-sync-alt me-2"></i>รีเฟรช
                </button>
            </div>
        <?php else: ?>
            <div class="orders-grid" id="ordersGrid">
                <?php foreach ($activeOrders as $order): ?>
                    <?php
                    // คำนวณเวลาที่ผ่านไป
                    $orderTime = new DateTime($order['created_at']);
                    $now = new DateTime();
                    $diff = $now->diff($orderTime);
                    $minutesPassed = ($diff->h * 60) + $diff->i;
                    
                    // กำหนดสีตามเวลา
                    $urgentClass = $minutesPassed > 20 ? 'urgent' : '';
                    $statusClass = strtolower($order['status']);
                    
                    // คำนวณความคืบหน้า
                    $progress = 0;
                    if ($order['total_items'] > 0) {
                        $progress = ($order['completed_items'] / $order['total_items']) * 100;
                    }
                    ?>
                    <div class="order-card <?php echo $statusClass; ?>" data-order-id="<?php echo $order['order_id']; ?>">
                        <div class="order-header">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <div class="order-number">
                                        คิว <?php echo clean($order['queue_number'] ?: 'ORD-' . $order['order_id']); ?>
                                    </div>
                                    <div class="order-time">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo formatDate($order['created_at'], 'H:i'); ?> 
                                        <span class="time-badge <?php echo $urgentClass; ?>">
                                            <?php echo $minutesPassed; ?> นาที
                                        </span>
                                    </div>
                                </div>
                                <div class="text-end">
                                    <div class="badge bg-<?php echo getOrderStatusClass($order['status']); ?> bg-opacity-10 text-<?php echo getOrderStatusClass($order['status']); ?> border border-<?php echo getOrderStatusClass($order['status']); ?>">
                                        <?php echo getOrderStatusText($order['status']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="order-body">
                            <?php if ($order['customer_name']): ?>
                                <div class="customer-info">
                                    <i class="fas fa-user me-1"></i>
                                    <?php echo clean($order['customer_name']); ?>
                                    <?php if ($order['phone']): ?>
                                        <span class="ms-2">
                                            <i class="fas fa-phone me-1"></i>
                                            <?php echo clean($order['phone']); ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="progress-bar-custom">
                                <div class="progress-fill" style="width: <?php echo $progress; ?>%"></div>
                            </div>
                            
                            <div class="order-items" id="orderItems<?php echo $order['order_id']; ?>">
                                <!-- ข้อมูลรายการจะโหลดด้วย AJAX -->
                            </div>
                            
                            <?php if ($order['notes']): ?>
                                <div class="alert alert-warning alert-sm mt-2">
                                    <i class="fas fa-sticky-note me-1"></i>
                                    <strong>หมายเหตุ:</strong> <?php echo clean($order['notes']); ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        
                        <div class="order-footer">
                            <div class="preparation-time">
                                <i class="fas fa-stopwatch me-1"></i>
                                <span id="timer<?php echo $order['order_id']; ?>"><?php echo $minutesPassed; ?> นาที</span>
                            </div>
                            
                            <div class="status-buttons">
                                <?php if ($order['status'] === 'confirmed'): ?>
                                    <button onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'preparing')" 
                                            class="btn btn-status btn-accept">
                                        <i class="fas fa-play me-1"></i>รับออเดอร์
                                    </button>
                                <?php elseif ($order['status'] === 'preparing'): ?>
                                    <button onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'ready')" 
                                            class="btn btn-status btn-ready">
                                        <i class="fas fa-check me-1"></i>พร้อมเสิร์ฟ
                                    </button>
                                <?php endif; ?>
                                
                                <button onclick="viewOrderDetails(<?php echo $order['order_id']; ?>)" 
                                        class="btn btn-outline-secondary btn-sm">
                                    <i class="fas fa-eye"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
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
                    <!-- รายละเอียดจะโหลดด้วย AJAX -->
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Kitchen JavaScript -->
    <script>
        // อัปเดตเวลาปัจจุบัน
        function updateCurrentTime() {
            const now = new Date();
            document.getElementById('currentTime').textContent = now.toTimeString().substr(0, 8);
        }
        
        // อัปเดต Timer สำหรับแต่ละออเดอร์
        function updateOrderTimers() {
            document.querySelectorAll('[id^="timer"]').forEach(timer => {
                const orderId = timer.id.replace('timer', '');
                const orderCard = document.querySelector(`[data-order-id="${orderId}"]`);
                if (orderCard) {
                    const currentMinutes = parseInt(timer.textContent);
                    timer.textContent = (currentMinutes + 1) + ' นาที';
                    
                    // เปลี่ยนสีเมื่อเวลานาน
                    if (currentMinutes > 20) {
                        orderCard.classList.add('urgent');
                    }
                }
            });
        }
        
        // อัปเดตสถานะออเดอร์
        function updateOrderStatus(orderId, status) {
            const confirmText = status === 'preparing' ? 'เริ่มเตรียมออเดอร์นี้?' : 'ยืนยันว่าพร้อมเสิร์ฟ?';
            
            if (confirm(confirmText)) {
                fetch('../api/orders.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=update_status&order_id=${orderId}&status=${status}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('success', 'อัปเดตสถานะสำเร็จ');
                        
                        // หากเป็น ready ให้ซ่อนการ์ด
                        if (status === 'ready') {
                            const orderCard = document.querySelector(`[data-order-id="${orderId}"]`);
                            orderCard.style.animation = 'fadeOut 0.5s ease-out';
                            setTimeout(() => {
                                orderCard.remove();
                                
                                // ตรวจสอบว่าเหลือออเดอร์หรือไม่
                                if (document.querySelectorAll('.order-card').length === 0) {
                                    location.reload();
                                }
                            }, 500);
                        } else {
                            location.reload();
                        }
                    } else {
                        showNotification('error', 'เกิดข้อผิดพลาด: ' + (data.error || 'ไม่สามารถอัปเดตได้'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                });
            }
        }
        
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
                        <td class="text-center">
                            <span class="badge bg-${getStatusClass(item.status)}">${getStatusText(item.status)}</span>
                        </td>
                    </tr>
                `).join('');
            }
            
            document.getElementById('orderDetailsBody').innerHTML = `
                <div class="row">
                    <div class="col-md-6">
                        <h6><i class="fas fa-info-circle me-2"></i>ข้อมูลออเดอร์</h6>
                        <table class="table table-sm">
                            <tr><td><strong>หมายเลขคิว:</strong></td><td>${order.queue_number || 'ORD-' + order.order_id}</td></tr>
                            <tr><td><strong>ลูกค้า:</strong></td><td>${order.customer_name || 'ลูกค้าทั่วไป'}</td></tr>
                            <tr><td><strong>เวลาสั่ง:</strong></td><td>${new Date(order.created_at).toLocaleString('th-TH')}</td></tr>
                            <tr><td><strong>ประเภท:</strong></td><td>${getOrderTypeText(order.order_type)}</td></tr>
                            <tr><td><strong>ยอดรวม:</strong></td><td class="text-success fw-bold">฿${parseFloat(order.total_price).toLocaleString()}</td></tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <h6><i class="fas fa-phone me-2"></i>ติดต่อลูกค้า</h6>
                        <div class="mb-2">
                            ${order.phone ? `<div><i class="fas fa-phone me-2"></i>${order.phone}</div>` : ''}
                            ${order.email ? `<div><i class="fas fa-envelope me-2"></i>${order.email}</div>` : ''}
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
                                <th class="text-center">สถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            ${itemsHtml}
                        </tbody>
                    </table>
                </div>
            `;
        }
        
        // โหลดรายการอาหารของแต่ละออเดอร์
        function loadOrderItems() {
            document.querySelectorAll('[id^="orderItems"]').forEach(container => {
                const orderId = container.id.replace('orderItems', '');
                
                fetch(`../api/orders.php?action=get&id=${orderId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.order.items) {
                            let itemsHtml = '';
                            data.order.items.forEach(item => {
                                itemsHtml += `
                                    <div class="order-item">
                                        <div class="item-name">${item.product_name}</div>
                                        <div class="item-quantity">${item.quantity}</div>
                                        <div class="item-status text-${getStatusClass(item.status)}">
                                            ${getStatusText(item.status)}
                                        </div>
                                    </div>
                                `;
                            });
                            container.innerHTML = itemsHtml;
                        }
                    })
                    .catch(error => console.error('Error loading items:', error));
            });
        }
        
        // ฟังก์ชันช่วย
        function getStatusClass(status) {
            const classes = {
                'pending': 'secondary',
                'preparing': 'warning',
                'ready': 'success',
                'completed': 'info',
                'cancelled': 'danger'
            };
            return classes[status] || 'secondary';
        }
        
        function getStatusText(status) {
            const texts = {
                'pending': 'รอดำเนินการ',
                'preparing': 'กำลังเตรียม',
                'ready': 'พร้อมเสิร์ฟ',
                'completed': 'เสร็จสิ้น',
                'cancelled': 'ยกเลิก'
            };
            return texts[status] || 'ไม่ทราบ';
        }
        
        function getOrderTypeText(type) {
            const types = {
                'dine_in': 'ทานที่ร้าน',
                'takeaway': 'ซื้อกลับ',
                'delivery': 'ส่งถึงที่'
            };
            return types[type] || 'ไม่ระบุ';
        }
        
        // แสดงการแจ้งเตือน
        function showNotification(type, message) {
            const alertClass = type === 'success' ? 'alert-success' : 'alert-danger';
            const icon = type === 'success' ? 'check-circle' : 'exclamation-triangle';
            
            const alertHtml = `
                <div class="alert ${alertClass} alert-dismissible fade show position-fixed" 
                     style="top: 20px; right: 20px; z-index: 9999; min-width: 300px;">
                    <i class="fas fa-${icon} me-2"></i>
                    ${message}
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            `;
            
            document.body.insertAdjacentHTML('beforeend', alertHtml);
            
            // ลบการแจ้งเตือนอัตโนมัติ
            setTimeout(() => {
                const alert = document.querySelector('.alert');
                if (alert) {
                    alert.remove();
                }
            }, 5000);
        }
        
        // CSS สำหรับ animation
        const style = document.createElement('style');
        style.textContent = `
            @keyframes fadeOut {
                from { opacity: 1; transform: scale(1); }
                to { opacity: 0; transform: scale(0.9); }
            }
        `;
        document.head.appendChild(style);
        
        // เริ่มต้นระบบ
        document.addEventListener('DOMContentLoaded', function() {
            // อัปเดตเวลาทุกวินาที
            setInterval(updateCurrentTime, 1000);
            
            // อัปเดต timer ทุกนาที
            setInterval(updateOrderTimers, 60000);
            
            // โหลดรายการอาหาร
            loadOrderItems();
            
            // รีเฟรชหน้าทุก 5 นาที
            setInterval(() => {
                location.reload();
            }, 300000);
        });
        
        // รองรับ keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // F5 - รีเฟรช
            if (e.key === 'F5') {
                e.preventDefault();
                location.reload();
            }
            
            // ESC - ปิด modal
            if (e.key === 'Escape') {
                const modals = document.querySelectorAll('.modal.show');
                modals.forEach(modal => {
                    bootstrap.Modal.getInstance(modal)?.hide();
                });
            }
        });
    </script>
</body>
</html>