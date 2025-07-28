<?php
/**
 * อัปเดตสถานะออเดอร์ - หน้าสำหรับจัดการสถานะรายการอาหาร
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

// จัดการการอัปเดตสถานะ
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        if ($_POST['action'] === 'update_item_status') {
            $itemId = intval($_POST['item_id']);
            $status = $_POST['status'];
            $validStatuses = ['pending', 'preparing', 'ready', 'completed'];
            
            if (!in_array($status, $validStatuses)) {
                echo json_encode(['success' => false, 'error' => 'Invalid status']);
                exit();
            }
            
            // อัปเดตสถานะรายการ
            $stmt = $conn->prepare("UPDATE order_items SET status = ? WHERE item_id = ?");
            $stmt->execute([$status, $itemId]);
            
            // ตรวจสอบว่าทุกรายการในออเดอร์เสร็จหรือยัง
            $stmt = $conn->prepare("
                SELECT oi.order_id, 
                       COUNT(*) as total_items,
                       SUM(CASE WHEN oi.status = 'completed' THEN 1 ELSE 0 END) as completed_items
                FROM order_items oi 
                WHERE oi.order_id = (SELECT order_id FROM order_items WHERE item_id = ?)
                GROUP BY oi.order_id
            ");
            $stmt->execute([$itemId]);
            $result = $stmt->fetch();
            
            if ($result && $result['total_items'] == $result['completed_items']) {
                // อัปเดตสถานะออเดอร์เป็น ready
                $updateOrder = $conn->prepare("UPDATE orders SET status = 'ready', updated_at = NOW() WHERE order_id = ?");
                $updateOrder->execute([$result['order_id']]);
                
                // บันทึกประวัติ
                $historyStmt = $conn->prepare("
                    INSERT INTO order_status_history (order_id, status, changed_by, created_at)
                    VALUES (?, 'ready', ?, NOW())
                ");
                $historyStmt->execute([$result['order_id'], getCurrentUserId()]);
                
                echo json_encode([
                    'success' => true, 
                    'message' => 'Order completed',
                    'order_ready' => true,
                    'order_id' => $result['order_id']
                ]);
            } else {
                echo json_encode(['success' => true, 'message' => 'Item status updated']);
            }
            exit();
        }
        
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
        exit();
    }
}

$pageTitle = 'จัดการสถานะออเดอร์';

// ดึงข้อมูลออเดอร์และรายการ
$orderItems = [];
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $stmt = $conn->prepare("
        SELECT o.order_id, o.queue_number, o.status as order_status, o.created_at, o.notes,
               u.fullname as customer_name,
               oi.item_id, oi.status as item_status, oi.quantity, oi.notes as item_notes,
               p.name as product_name, p.image, p.preparation_time
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        JOIN order_items oi ON o.order_id = oi.order_id
        JOIN products p ON oi.product_id = p.product_id
        WHERE o.status IN ('confirmed', 'preparing', 'ready')
        AND o.payment_status = 'paid'
        ORDER BY o.created_at ASC, oi.item_id ASC
    ");
    $stmt->execute();
    $results = $stmt->fetchAll();
    
    // จัดกลุ่มตามออเดอร์
    foreach ($results as $row) {
        $orderId = $row['order_id'];
        if (!isset($orderItems[$orderId])) {
            $orderItems[$orderId] = [
                'order_info' => [
                    'order_id' => $row['order_id'],
                    'queue_number' => $row['queue_number'],
                    'order_status' => $row['order_status'],
                    'created_at' => $row['created_at'],
                    'customer_name' => $row['customer_name'],
                    'notes' => $row['notes']
                ],
                'items' => []
            ];
        }
        
        $orderItems[$orderId]['items'][] = [
            'item_id' => $row['item_id'],
            'product_name' => $row['product_name'],
            'image' => $row['image'],
            'quantity' => $row['quantity'],
            'item_status' => $row['item_status'],
            'item_notes' => $row['item_notes'],
            'preparation_time' => $row['preparation_time']
        ];
    }
    
} catch (Exception $e) {
    writeLog("Kitchen order status error: " . $e->getMessage());
    setFlashMessage('error', 'เกิดข้อผิดพลาดในการโหลดข้อมูล');
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
        
        .kitchen-navbar {
            background: linear-gradient(135deg, var(--orange-color), #ea580c);
            color: white;
            padding: 15px 0;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .order-section {
            background: white;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
            margin-bottom: 25px;
            overflow: hidden;
        }
        
        .order-header {
            background: linear-gradient(135deg, #f8fafc, #f1f5f9);
            padding: 20px;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .order-title {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary-color);
            margin-bottom: 5px;
        }
        
        .order-meta {
            display: flex;
            flex-wrap: wrap;
            gap: 15px;
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .items-grid {
            padding: 20px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
        }
        
        .item-card {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            transition: all 0.3s ease;
            background: white;
        }
        
        .item-card.pending {
            border-left: 6px solid var(--warning-color);
        }
        
        .item-card.preparing {
            border-left: 6px solid var(--info-color);
            background: linear-gradient(135deg, rgba(59, 130, 246, 0.05), rgba(59, 130, 246, 0.1));
        }
        
        .item-card.ready {
            border-left: 6px solid var(--success-color);
            background: linear-gradient(135deg, rgba(16, 185, 129, 0.05), rgba(16, 185, 129, 0.1));
        }
        
        .item-card.completed {
            border-left: 6px solid #6b7280;
            background: #f9fafb;
            opacity: 0.7;
        }
        
        .item-image {
            width: 60px;
            height: 60px;
            object-fit: cover;
            border-radius: 8px;
            margin-right: 15px;
        }
        
        .item-content {
            flex: 1;
        }
        
        .item-name {
            font-weight: 600;
            font-size: 1.1rem;
            margin-bottom: 5px;
        }
        
        .item-quantity {
            background: var(--primary-color);
            color: white;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
            display: inline-block;
            margin-bottom: 10px;
        }
        
        .item-controls {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .status-btn {
            border: none;
            border-radius: 8px;
            padding: 8px 16px;
            font-weight: 600;
            font-size: 0.85rem;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .status-btn:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        
        .status-btn.start {
            background: var(--info-color);
            color: white;
        }
        
        .status-btn.complete {
            background: var(--success-color);
            color: white;
        }
        
        .status-btn.undo {
            background: #6b7280;
            color: white;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 8px;
            border-radius: 16px;
            font-size: 0.8rem;
            font-weight: 600;
        }
        
        .status-badge.pending {
            background: rgba(245, 158, 11, 0.1);
            color: var(--warning-color);
        }
        
        .status-badge.preparing {
            background: rgba(59, 130, 246, 0.1);
            color: var(--info-color);
        }
        
        .status-badge.ready {
            background: rgba(16, 185, 129, 0.1);
            color: var(--success-color);
        }
        
        .status-badge.completed {
            background: rgba(107, 114, 128, 0.1);
            color: #6b7280;
        }
        
        .preparation-timer {
            font-size: 0.9rem;
            color: var(--warning-color);
            font-weight: 600;
            margin-top: 8px;
        }
        
        .item-notes {
            background: rgba(245, 158, 11, 0.1);
            border: 1px solid rgba(245, 158, 11, 0.2);
            border-radius: 8px;
            padding: 8px 12px;
            margin-top: 10px;
            font-size: 0.9rem;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6b7280;
        }
        
        .progress-indicator {
            width: 100%;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            margin: 10px 0;
            overflow: hidden;
        }
        
        .progress-fill {
            height: 100%;
            border-radius: 3px;
            transition: width 0.5s ease;
        }
        
        .progress-fill.pending { background: var(--warning-color); width: 25%; }
        .progress-fill.preparing { background: var(--info-color); width: 50%; }
        .progress-fill.ready { background: var(--success-color); width: 75%; }
        .progress-fill.completed { background: #6b7280; width: 100%; }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .items-grid {
                grid-template-columns: 1fr;
                padding: 15px;
            }
            
            .order-meta {
                flex-direction: column;
                gap: 5px;
            }
            
            .item-card {
                padding: 15px;
            }
            
            .status-btn {
                flex: 1;
                min-width: 100px;
            }
        }
        
        /* Animation */
        @keyframes statusUpdate {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .item-card.updating {
            animation: statusUpdate 0.5s ease;
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="kitchen-navbar">
        <div class="container-fluid">
            <div class="d-flex justify-content-between align-items-center">
                <div>
                    <h4 class="mb-0">
                        <i class="fas fa-tasks me-2"></i>จัดการสถานะออเดอร์
                    </h4>
                    <small class="opacity-75">อัปเดตสถานะการเตรียมอาหาร</small>
                </div>
                <div class="d-flex gap-3">
                    <a href="index.php" class="btn btn-light btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>กลับ
                    </a>
                    <button onclick="location.reload()" class="btn btn-light btn-sm">
                        <i class="fas fa-sync-alt me-1"></i>รีเฟรช
                    </button>
                </div>
            </div>
        </div>
    </nav>
    
    <div class="container-fluid py-4">
        <?php if (empty($orderItems)): ?>
            <!-- Empty State -->
            <div class="empty-state">
                <i class="fas fa-clipboard-check fa-4x text-success mb-3"></i>
                <h4>ไม่มีออเดอร์ที่ต้องดำเนินการ</h4>
                <p class="text-muted">ออเดอร์ทั้งหมดเสร็จสิ้นแล้ว</p>
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home me-2"></i>กลับหน้าหลัก
                </a>
            </div>
        <?php else: ?>
            <!-- Orders List -->
            <?php foreach ($orderItems as $orderId => $orderData): ?>
                <?php
                $order = $orderData['order_info'];
                $items = $orderData['items'];
                
                // คำนวณความคืบหน้า
                $totalItems = count($items);
                $completedItems = count(array_filter($items, fn($item) => $item['item_status'] === 'completed'));
                $progressPercent = $totalItems > 0 ? ($completedItems / $totalItems) * 100 : 0;
                
                // คำนวณเวลาที่ผ่านไป
                $orderTime = new DateTime($order['created_at']);
                $now = new DateTime();
                $diff = $now->diff($orderTime);
                $minutesPassed = ($diff->h * 60) + $diff->i;
                ?>
                
                <div class="order-section" data-order-id="<?php echo $orderId; ?>">
                    <!-- Order Header -->
                    <div class="order-header">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <div class="order-title">
                                    คิว <?php echo clean($order['queue_number'] ?: 'ORD-' . $orderId); ?>
                                </div>
                                <div class="order-meta">
                                    <span><i class="fas fa-user me-1"></i><?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></span>
                                    <span><i class="fas fa-clock me-1"></i><?php echo formatDate($order['created_at'], 'H:i'); ?> (<?php echo $minutesPassed; ?> นาทีที่แล้ว)</span>
                                    <span><i class="fas fa-chart-pie me-1"></i>ความคืบหน้า <?php echo number_format($progressPercent, 0); ?>%</span>
                                </div>
                            </div>
                            <div class="text-end">
                                <span class="badge bg-<?php echo getOrderStatusClass($order['order_status']); ?> fs-6">
                                    <?php echo getOrderStatusText($order['order_status']); ?>
                                </span>
                            </div>
                        </div>
                        
                        <!-- Progress Bar -->
                        <div class="progress-indicator mt-3">
                            <div class="progress-fill" style="width: <?php echo $progressPercent; ?>%; background: var(--success-color);"></div>
                        </div>
                        
                        <?php if ($order['notes']): ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                <i class="fas fa-sticky-note me-2"></i>
                                <strong>หมายเหตุออเดอร์:</strong> <?php echo clean($order['notes']); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <!-- Items Grid -->
                    <div class="items-grid">
                        <?php foreach ($items as $item): ?>
                            <div class="item-card <?php echo $item['item_status']; ?>" data-item-id="<?php echo $item['item_id']; ?>">
                                <div class="d-flex align-items-start">
                                    <?php if ($item['image']): ?>
                                        <img src="../uploads/menu_images/<?php echo $item['image']; ?>" 
                                             alt="<?php echo clean($item['product_name']); ?>" 
                                             class="item-image">
                                    <?php else: ?>
                                        <div class="item-image bg-light d-flex align-items-center justify-content-center">
                                            <i class="fas fa-utensils text-muted"></i>
                                        </div>
                                    <?php endif; ?>
                                    
                                    <div class="item-content">
                                        <div class="item-name"><?php echo clean($item['product_name']); ?></div>
                                        <div class="item-quantity">จำนวน <?php echo $item['quantity']; ?></div>
                                        
                                        <div class="d-flex justify-content-between align-items-center mb-2">
                                            <span class="status-badge <?php echo $item['item_status']; ?>">
                                                <i class="fas fa-<?php echo getStatusIcon($item['item_status']); ?>"></i>
                                                <?php echo getStatusText($item['item_status']); ?>
                                            </span>
                                            
                                            <?php if ($item['preparation_time']): ?>
                                                <div class="preparation-timer">
                                                    <i class="fas fa-stopwatch me-1"></i>
                                                    <?php echo $item['preparation_time']; ?> นาที
                                                </div>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Progress Indicator -->
                                        <div class="progress-indicator">
                                            <div class="progress-fill <?php echo $item['item_status']; ?>"></div>
                                        </div>
                                        
                                        <!-- Controls -->
                                        <div class="item-controls">
                                            <?php if ($item['item_status'] === 'pending'): ?>
                                                <button onclick="updateItemStatus(<?php echo $item['item_id']; ?>, 'preparing')" 
                                                        class="status-btn start">
                                                    <i class="fas fa-play me-1"></i>เริ่มทำ
                                                </button>
                                            <?php elseif ($item['item_status'] === 'preparing'): ?>
                                                <button onclick="updateItemStatus(<?php echo $item['item_id']; ?>, 'ready')" 
                                                        class="status-btn complete">
                                                    <i class="fas fa-check me-1"></i>เสร็จแล้ว
                                                </button>
                                                <button onclick="updateItemStatus(<?php echo $item['item_id']; ?>, 'pending')" 
                                                        class="status-btn undo">
                                                    <i class="fas fa-undo me-1"></i>ยกเลิก
                                                </button>
                                            <?php elseif ($item['item_status'] === 'ready'): ?>
                                                <button onclick="updateItemStatus(<?php echo $item['item_id']; ?>, 'completed')" 
                                                        class="status-btn complete">
                                                    <i class="fas fa-check-double me-1"></i>ส่งแล้ว
                                                </button>
                                                <button onclick="updateItemStatus(<?php echo $item['item_id']; ?>, 'preparing')" 
                                                        class="status-btn undo">
                                                    <i class="fas fa-undo me-1"></i>กลับไปทำ
                                                </button>
                                            <?php elseif ($item['item_status'] === 'completed'): ?>
                                                <span class="text-success">
                                                    <i class="fas fa-check-circle me-1"></i>เสร็จสิ้น
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <?php if ($item['item_notes']): ?>
                                            <div class="item-notes">
                                                <i class="fas fa-sticky-note me-1"></i>
                                                <strong>หมายเหตุ:</strong> <?php echo clean($item['item_notes']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // อัปเดตสถานะรายการอาหาร
        function updateItemStatus(itemId, status) {
            const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
            if (!itemCard) return;
            
            // เอฟเฟค loading
            itemCard.classList.add('updating');
            
            const statusTexts = {
                'preparing': 'เริ่มเตรียม',
                'ready': 'เสร็จแล้ว',
                'completed': 'ส่งแล้ว',
                'pending': 'ยกเลิก'
            };
            
            const confirmText = `ต้องการ${statusTexts[status]}รายการนี้?`;
            
            if (confirm(confirmText)) {
                fetch('order_status.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                    },
                    body: `action=update_item_status&item_id=${itemId}&status=${status}`
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        showNotification('success', 'อัปเดตสถานะสำเร็จ');
                        
                        // อัปเดต UI
                        updateItemUI(itemId, status);
                        
                        // ถ้าออเดอร์เสร็จแล้ว
                        if (data.order_ready) {
                            showNotification('info', 'ออเดอร์พร้อมเสิร์ฟแล้ว!');
                            setTimeout(() => {
                                location.reload();
                            }, 2000);
                        }
                    } else {
                        showNotification('error', 'เกิดข้อผิดพลาด: ' + (data.error || 'ไม่สามารถอัปเดตได้'));
                    }
                })
                .catch(error => {
                    console.error('Error:', error);
                    showNotification('error', 'เกิดข้อผิดพลาดในการเชื่อมต่อ');
                })
                .finally(() => {
                    itemCard.classList.remove('updating');
                });
            } else {
                itemCard.classList.remove('updating');
            }
        }
        
        // อัปเดต UI ของรายการ
        function updateItemUI(itemId, newStatus) {
            const itemCard = document.querySelector(`[data-item-id="${itemId}"]`);
            if (!itemCard) return;
            
            // อัปเดต class
            itemCard.className = `item-card ${newStatus}`;
            
            // อัปเดต status badge
            const statusBadge = itemCard.querySelector('.status-badge');
            if (statusBadge) {
                statusBadge.className = `status-badge ${newStatus}`;
                statusBadge.innerHTML = `<i class="fas fa-${getStatusIcon(newStatus)}"></i> ${getStatusText(newStatus)}`;
            }
            
            // อัปเดต progress bar
            const progressFill = itemCard.querySelector('.progress-fill');
            if (progressFill) {
                progressFill.className = `progress-fill ${newStatus}`;
            }
            
            // อัปเดต controls
            const controls = itemCard.querySelector('.item-controls');
            if (controls) {
                controls.innerHTML = getControlsHTML(itemId, newStatus);
            }
            
            // อัปเดต progress ของออเดอร์
            updateOrderProgress(itemCard.closest('[data-order-id]'));
        }
        
        // อัปเดต progress ของออเดอร์
        function updateOrderProgress(orderSection) {
            if (!orderSection) return;
            
            const itemCards = orderSection.querySelectorAll('.item-card');
            const totalItems = itemCards.length;
            const completedItems = orderSection.querySelectorAll('.item-card.completed').length;
            const progressPercent = totalItems > 0 ? (completedItems / totalItems) * 100 : 0;
            
            const progressFill = orderSection.querySelector('.order-header .progress-fill');
            if (progressFill) {
                progressFill.style.width = progressPercent + '%';
            }
            
            // อัปเดตข้อความ progress
            const progressText = orderSection.querySelector('.order-meta');
            if (progressText) {
                const progressSpan = progressText.querySelector('[class*="fa-chart-pie"]').parentElement;
                progressSpan.innerHTML = `<i class="fas fa-chart-pie me-1"></i>ความคืบหน้า ${Math.round(progressPercent)}%`;
            }
        }
        
        // ส่งคืน HTML สำหรับ controls
        function getControlsHTML(itemId, status) {
            switch (status) {
                case 'pending':
                    return `<button onclick="updateItemStatus(${itemId}, 'preparing')" class="status-btn start">
                                <i class="fas fa-play me-1"></i>เริ่มทำ
                            </button>`;
                            
                case 'preparing':
                    return `<button onclick="updateItemStatus(${itemId}, 'ready')" class="status-btn complete">
                                <i class="fas fa-check me-1"></i>เสร็จแล้ว
                            </button>
                            <button onclick="updateItemStatus(${itemId}, 'pending')" class="status-btn undo">
                                <i class="fas fa-undo me-1"></i>ยกเลิก
                            </button>`;
                            
                case 'ready':
                    return `<button onclick="updateItemStatus(${itemId}, 'completed')" class="status-btn complete">
                                <i class="fas fa-check-double me-1"></i>ส่งแล้ว
                            </button>
                            <button onclick="updateItemStatus(${itemId}, 'preparing')" class="status-btn undo">
                                <i class="fas fa-undo me-1"></i>กลับไปทำ
                            </button>`;
                            
                case 'completed':
                    return `<span class="text-success">
                                <i class="fas fa-check-circle me-1"></i>เสร็จสิ้น
                            </span>`;
                            
                default:
                    return '';
            }
        }
        
        // ฟังก์ชันช่วย
        function getStatusIcon(status) {
            const icons = {
                'pending': 'clock',
                'preparing': 'spinner',
                'ready': 'check',
                'completed': 'check-circle'
            };
            return icons[status] || 'question';
        }
        
        function getStatusText(status) {
            const texts = {
                'pending': 'รอดำเนินการ',
                'preparing': 'กำลังเตรียม',
                'ready': 'พร้อมเสิร์ฟ',
                'completed': 'เสร็จสิ้น'
            };
            return texts[status] || 'ไม่ทราบ';
        }
        
        // แสดงการแจ้งเตือน
        function showNotification(type, message) {
            const alertClass = type === 'success' ? 'alert-success' : 
                              type === 'info' ? 'alert-info' : 'alert-danger';
            const icon = type === 'success' ? 'check-circle' : 
                        type === 'info' ? 'info-circle' : 'exclamation-triangle';
            
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
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // F5 - รีเฟรช
            if (e.key === 'F5') {
                e.preventDefault();
                location.reload();
            }
            
            // Escape - กลับหน้าหลัก
            if (e.key === 'Escape') {
                window.location.href = 'index.php';
            }
        });
        
        // Auto refresh ทุก 3 นาที
        setInterval(() => {
            location.reload();
        }, 180000);
        
        console.log('Kitchen Order Status System loaded');
    </script>
</body>
</html>

<?php
// ฟังก์ชันช่วยสำหรับแสดงผล
function getStatusIcon($status) {
    $icons = [
        'pending' => 'clock',
        'preparing' => 'spinner',
        'ready' => 'check',
        'completed' => 'check-circle',
        'cancelled' => 'times'
    ];
    return $icons[$status] ?? 'question';
}

function getStatusText($status) {
    $texts = [
        'pending' => 'รอดำเนินการ',
        'preparing' => 'กำลังเตรียม',
        'ready' => 'พร้อมเสิร์ฟ',
        'completed' => 'เสร็จสิ้น',
        'cancelled' => 'ยกเลิก'
    ];
    return $texts[$status] ?? 'ไม่ทราบ';
}
?>