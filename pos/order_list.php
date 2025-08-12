<?php
/**
 * รายการออเดอร์ทั้งหมด - POS (แก้ไขแล้ว)
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
    
    <!-- Sweet Alert 2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <style>
        :root {
            --pos-primary: #2563eb;
            --pos-primary-dark: #1d4ed8;
            --pos-success: #059669;
            --pos-warning: #d97706;
            --pos-danger: #dc2626;
            --pos-info: #0284c7;
            --pos-light: #f8fafc;
            --pos-white: #ffffff;
            --pos-gray-50: #f9fafb;
            --pos-gray-100: #f3f4f6;
            --pos-gray-200: #e5e7eb;
            --pos-gray-300: #d1d5db;
            --pos-gray-400: #9ca3af;
            --pos-gray-500: #6b7280;
            --pos-gray-600: #4b5563;
            --pos-gray-700: #374151;
            --pos-gray-800: #1f2937;
            --pos-gray-900: #111827;
            --pos-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --pos-shadow-lg: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
            --pos-border-radius: 12px;
            --pos-transition: all 0.2s ease-in-out;
        }
        
        body {
            background: linear-gradient(135deg, var(--pos-gray-50) 0%, #e0e7ff 100%);
            font-family: 'Inter', 'Segoe UI', system-ui, sans-serif;
            font-size: 14px;
            color: var(--pos-gray-800);
            line-height: 1.5;
        }
        
        .pos-container {
            padding: 20px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .pos-header {
            background: linear-gradient(135deg, var(--pos-primary), var(--pos-primary-dark));
            color: white;
            border-radius: var(--pos-border-radius);
            padding: 24px;
            margin-bottom: 24px;
            box-shadow: var(--pos-shadow-lg);
        }
        
        .filters-section {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            box-shadow: var(--pos-shadow);
            padding: 24px;
            margin-bottom: 24px;
            border: 1px solid var(--pos-gray-200);
        }
        
        .orders-section {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            box-shadow: var(--pos-shadow);
            overflow: hidden;
            border: 1px solid var(--pos-gray-200);
        }
        
        .section-header {
            background: linear-gradient(135deg, var(--pos-gray-50), var(--pos-gray-100));
            padding: 16px 24px;
            border-bottom: 2px solid var(--pos-gray-200);
            font-weight: 600;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: var(--pos-gray-700);
        }
        
        .status-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 20px;
            flex-wrap: wrap;
        }
        
        .status-tab {
            background: var(--pos-gray-100);
            border: 2px solid var(--pos-gray-200);
            border-radius: 25px;
            padding: 10px 18px;
            font-size: 14px;
            text-decoration: none;
            color: var(--pos-gray-700);
            transition: var(--pos-transition);
            position: relative;
            font-weight: 500;
        }
        
        .status-tab.active,
        .status-tab:hover {
            background: var(--pos-primary);
            color: white;
            border-color: var(--pos-primary);
            transform: translateY(-1px);
            box-shadow: var(--pos-shadow);
        }
        
        .status-tab .badge {
            margin-left: 8px;
            background: rgba(255, 255, 255, 0.9);
            color: var(--pos-gray-700);
            font-weight: 600;
        }
        
        .status-tab.active .badge {
            background: rgba(255, 255, 255, 0.2);
            color: white;
        }
        
        .order-card {
            border: 2px solid var(--pos-gray-200);
            border-radius: var(--pos-border-radius);
            padding: 20px;
            margin-bottom: 16px;
            transition: var(--pos-transition);
            background: var(--pos-white);
        }
        
        .order-card:hover {
            border-color: var(--pos-primary);
            box-shadow: var(--pos-shadow-lg);
            transform: translateY(-2px);
        }
        
        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--pos-gray-200);
        }
        
        .queue-number {
            background: linear-gradient(135deg, var(--pos-primary), var(--pos-primary-dark));
            color: white;
            border-radius: 10px;
            padding: 12px 16px;
            font-weight: 700;
            font-size: 18px;
            box-shadow: var(--pos-shadow);
        }
        
        .order-status {
            padding: 8px 14px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .order-details {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 16px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 0;
            border-bottom: 1px solid var(--pos-gray-100);
        }
        
        .detail-item:last-child {
            border-bottom: none;
        }
        
        .detail-label {
            color: var(--pos-gray-600);
            font-size: 13px;
            font-weight: 500;
        }
        
        .detail-value {
            font-weight: 600;
            color: var(--pos-gray-800);
            font-size: 14px;
        }
        
        .order-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            padding-top: 16px;
            border-top: 1px solid var(--pos-gray-200);
        }
        
        .action-btn {
            padding: 8px 16px;
            border-radius: 8px;
            border: 2px solid;
            background: var(--pos-white);
            text-decoration: none;
            font-size: 13px;
            font-weight: 600;
            transition: var(--pos-transition);
            display: inline-flex;
            align-items: center;
            gap: 6px;
            cursor: pointer;
        }
        
        .action-btn.btn-view {
            color: var(--pos-gray-600);
            border-color: var(--pos-gray-300);
        }
        
        .action-btn.btn-view:hover {
            background: var(--pos-gray-50);
            border-color: var(--pos-gray-400);
        }
        
        .action-btn.btn-call {
            color: var(--pos-info);
            border-color: var(--pos-info);
        }
        
        .action-btn.btn-call:hover {
            background: var(--pos-info);
            color: white;
        }
        
        .action-btn.btn-preparing {
            color: var(--pos-warning);
            border-color: var(--pos-warning);
        }
        
        .action-btn.btn-preparing:hover {
            background: var(--pos-warning);
            color: white;
        }
        
        .action-btn.btn-ready {
            color: var(--pos-success);
            border-color: var(--pos-success);
        }
        
        .action-btn.btn-ready:hover {
            background: var(--pos-success);
            color: white;
        }
        
        .action-btn.btn-complete {
            color: var(--pos-success);
            border-color: var(--pos-success);
        }
        
        .action-btn.btn-complete:hover {
            background: var(--pos-success);
            color: white;
        }
        
        .action-btn.btn-cancel {
            color: var(--pos-danger);
            border-color: var(--pos-danger);
        }
        
        .action-btn.btn-cancel:hover {
            background: var(--pos-danger);
            color: white;
        }
        
        .search-filters {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 16px;
            align-items: end;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid var(--pos-gray-200);
            padding: 12px 16px;
            font-size: 14px;
            transition: var(--pos-transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--pos-primary);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.1);
            outline: none;
        }
        
        .form-label {
            font-weight: 600;
            color: var(--pos-gray-700);
            margin-bottom: 8px;
            font-size: 14px;
        }
        
        .btn {
            border-radius: 8px;
            padding: 12px 20px;
            font-weight: 600;
            font-size: 14px;
            transition: var(--pos-transition);
            border: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--pos-primary), var(--pos-primary-dark));
            color: white;
        }
        
        .btn-primary:hover {
            transform: translateY(-1px);
            box-shadow: var(--pos-shadow-lg);
        }
        
        .btn-light {
            background: var(--pos-white);
            color: var(--pos-gray-700);
            border: 2px solid var(--pos-gray-200);
        }
        
        .btn-light:hover {
            background: var(--pos-gray-50);
            border-color: var(--pos-gray-300);
        }
        
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: var(--pos-gray-500);
        }
        
        .empty-state i {
            color: var(--pos-gray-400);
            margin-bottom: 16px;
        }
        
        /* Badge Colors */
        .bg-pending { background: var(--pos-warning) !important; }
        .bg-confirmed { background: var(--pos-info) !important; }
        .bg-preparing { background: var(--pos-primary) !important; }
        .bg-ready { background: var(--pos-success) !important; }
        .bg-completed { background: var(--pos-gray-500) !important; }
        .bg-cancelled { background: var(--pos-danger) !important; }
        
        /* Alert Styles */
        .alert {
            border-radius: var(--pos-border-radius);
            border: none;
            box-shadow: var(--pos-shadow);
            padding: 16px 20px;
        }
        
        .alert-danger {
            background: #fef2f2;
            color: var(--pos-danger);
        }
        
        /* Loading States */
        .loading {
            opacity: 0.6;
            pointer-events: none;
        }
        
        .loading::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 20px;
            height: 20px;
            margin: -10px 0 0 -10px;
            border: 2px solid var(--pos-primary);
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .pos-container {
                padding: 12px;
            }
            
            .pos-header {
                padding: 16px;
                text-align: center;
            }
            
            .search-filters {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .order-header {
                flex-direction: column;
                align-items: flex-start;
                gap: 12px;
            }
            
            .order-details {
                grid-template-columns: 1fr;
            }
            
            .order-actions {
                justify-content: center;
                gap: 8px;
            }
            
            .action-btn {
                font-size: 12px;
                padding: 6px 12px;
            }
            
            .status-tabs {
                justify-content: center;
                gap: 8px;
            }
            
            .status-tab {
                padding: 8px 14px;
                font-size: 13px;
            }
        }
        
        @media (max-width: 576px) {
            .queue-number {
                font-size: 16px;
                padding: 10px 14px;
            }
            
            .detail-value {
                font-size: 13px;
            }
            
            .order-card {
                padding: 16px;
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
                    <h1 class="h3 mb-2">
                        <i class="fas fa-list-alt me-2"></i>
                        รายการออเดอร์
                    </h1>
                    <p class="mb-0 opacity-90">จัดการและติดตามออเดอร์ทั้งหมด</p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-light">
                        <i class="fas fa-arrow-left"></i>กลับ
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
                        <i class="fas fa-search"></i>ค้นหา
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
            
            <div class="p-4">
                <?php if (empty($orders)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox fa-4x"></i>
                        <h5 class="mt-3">ไม่พบออเดอร์</h5>
                        <p class="text-muted">ไม่มีออเดอร์ที่ตรงกับเงื่อนไขที่ค้นหา</p>
                        <a href="new_order.php" class="btn btn-primary mt-3">
                            <i class="fas fa-plus"></i>สร้างออเดอร์ใหม่
                        </a>
                    </div>
                <?php else: ?>
                    <?php foreach ($orders as $order): ?>
                        <div class="order-card" id="order-<?php echo $order['order_id']; ?>">
                            <div class="order-header">
                                <div class="d-flex align-items-center gap-3">
                                    <div class="queue-number">
                                        <?php echo clean($order['queue_number'] ?: 'ORD-' . $order['order_id']); ?>
                                    </div>
                                    <div>
                                        <div class="fw-bold fs-5">
                                            <?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?>
                                        </div>
                                        <small class="text-muted">
                                            <i class="fas fa-clock me-1"></i>
                                            <?php echo formatDate($order['created_at'], 'H:i'); ?>
                                        </small>
                                    </div>
                                </div>
                                
                                <div class="order-status bg-<?php echo $order['status']; ?>">
                                    <?php echo getOrderStatusText($order['status']); ?>
                                </div>
                            </div>
                            
                            <div class="order-details">
                                <div>
                                    <div class="detail-item">
                                        <span class="detail-label">ยอดรวม:</span>
                                        <span class="detail-value text-success fw-bold">
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
                                <div class="mt-3 p-3 bg-light rounded">
                                    <?php if ($order['table_number']): ?>
                                        <small><strong>โต๊ะ:</strong> <?php echo clean($order['table_number']); ?></small><br>
                                    <?php endif; ?>
                                    <?php if ($order['notes']): ?>
                                        <small><strong>หมายเหตุ:</strong> <?php echo clean($order['notes']); ?></small>
                                    <?php endif; ?>
                                </div>
                            <?php endif; ?>
                            
                            <div class="order-actions">
                                <button class="action-btn btn-view" onclick="viewOrderDetails(<?php echo $order['order_id']; ?>)">
                                    <i class="fas fa-eye"></i>ดูรายละเอียด
                                </button>
                                
                                <a href="print_receipt.php?order_id=<?php echo $order['order_id']; ?>" 
                                   class="action-btn btn-view" target="_blank">
                                    <i class="fas fa-receipt"></i>ใบเสร็จ
                                </a>
                                
                                <?php if (in_array($order['status'], ['confirmed', 'preparing', 'ready'])): ?>
                                    <button class="action-btn btn-call" 
                                            onclick="callQueue('<?php echo $order['queue_number']; ?>', <?php echo $order['order_id']; ?>)">
                                        <i class="fas fa-volume-up"></i>เรียกคิว
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($order['status'] === 'confirmed'): ?>
                                    <button class="action-btn btn-preparing" 
                                            onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'preparing')">
                                        <i class="fas fa-utensils"></i>เริ่มทำ
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($order['status'] === 'preparing'): ?>
                                    <button class="action-btn btn-ready" 
                                            onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'ready')">
                                        <i class="fas fa-check"></i>พร้อมเสิร์ฟ
                                    </button>
                                <?php endif; ?>
                                
                                <?php if ($order['status'] === 'ready'): ?>
                                    <button class="action-btn btn-complete" 
                                            onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'completed')">
                                        <i class="fas fa-check-circle"></i>เสร็จสิ้น
                                    </button>
                                <?php endif; ?>
                                
                                <?php if (in_array($order['status'], ['pending', 'confirmed'])): ?>
                                    <button class="action-btn btn-cancel" 
                                            onclick="cancelOrder(<?php echo $order['order_id']; ?>)">
                                        <i class="fas fa-times"></i>ยกเลิก
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
    
    <!-- jQuery -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    
    <!-- Sweet Alert 2 -->
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const SITE_URL = '<?php echo SITE_URL; ?>';
        
        // Call queue function with improved error handling
        function callQueue(queueNumber, orderId) {
            Swal.fire({
                title: 'เรียกคิว',
                text: `ต้องการเรียกคิว ${queueNumber}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#2563eb',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'เรียกคิว',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    const orderCard = document.getElementById(`order-${orderId}`);
                    if (orderCard) {
                        orderCard.classList.add('loading');
                    }
                    
                    fetch(`${SITE_URL}/api/voice_queue.php`, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                        },
                        body: JSON.stringify({
                            action: 'call_queue',
                            queue_number: queueNumber,
                            order_id: orderId
                        })
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (orderCard) {
                            orderCard.classList.remove('loading');
                        }
                        
                        if (data.success) {
                            // Play voice message
                            playVoiceMessage(data.voice_message || `เรียกคิวหมายเลข ${queueNumber} กรุณามารับอาหารค่ะ`);
                            
                            Swal.fire({
                                icon: 'success',
                                title: 'เรียกคิวสำเร็จ',
                                text: `เรียกคิว ${queueNumber} เรียบร้อยแล้ว`,
                                timer: 2000,
                                showConfirmButton: false
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: data.error || 'ไม่สามารถเรียกคิวได้'
                            });
                        }
                    })
                    .catch(error => {
                        if (orderCard) {
                            orderCard.classList.remove('loading');
                        }
                        
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้'
                        });
                    });
                }
            });
        }
        
        // Update order status with improved feedback
        function updateOrderStatus(orderId, newStatus) {
            const statusText = {
                'preparing': 'เริ่มทำอาหาร',
                'ready': 'อาหารพร้อมเสิร์ฟ',
                'completed': 'เสร็จสิ้นออเดอร์'
            };
            
            Swal.fire({
                title: 'อัปเดตสถานะ',
                text: `ต้องการ${statusText[newStatus]}?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#059669',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ยืนยัน',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const orderCard = document.getElementById(`order-${orderId}`);
                    if (orderCard) {
                        orderCard.classList.add('loading');
                    }
                    
                    fetch(`${SITE_URL}/api/orders.php`, {
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
                        if (orderCard) {
                            orderCard.classList.remove('loading');
                        }
                        
                        if (data.success) {
                            Swal.fire({
                                icon: 'success',
                                title: 'อัปเดตสำเร็จ',
                                text: 'อัปเดตสถานะเรียบร้อยแล้ว',
                                timer: 1500,
                                showConfirmButton: false
                            }).then(() => {
                                location.reload();
                            });
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'เกิดข้อผิดพลาด',
                                text: data.error || 'ไม่สามารถอัปเดตสถานะได้'
                            });
                        }
                    })
                    .catch(error => {
                        if (orderCard) {
                            orderCard.classList.remove('loading');
                        }
                        
                        console.error('Error:', error);
                        Swal.fire({
                            icon: 'error',
                            title: 'เกิดข้อผิดพลาด',
                            text: 'ไม่สามารถเชื่อมต่อกับเซิร์ฟเวอร์ได้'
                        });
                    });
                }
            });
        }
        
        // Cancel order
        function cancelOrder(orderId) {
            Swal.fire({
                title: 'ยกเลิกออเดอร์',
                text: 'ต้องการยกเลิกออเดอร์นี้? การกระทำนี้ไม่สามารถยกเลิกได้',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#dc2626',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ยกเลิกออเดอร์',
                cancelButtonText: 'ไม่ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    updateOrderStatus(orderId, 'cancelled');
                }
            });
        }
        
        // View order details
        function viewOrderDetails(orderId) {
            // TODO: Show order details in modal or navigate to details page
            window.open(`${SITE_URL}/pos/order_details.php?id=${orderId}`, '_blank');
        }
        
        // Play voice message using Web Speech API
        function playVoiceMessage(message, rate = 1) {
            if ('speechSynthesis' in window) {
                // Stop any currently playing speech
                speechSynthesis.cancel();
                
                const utterance = new SpeechSynthesisUtterance(message);
                utterance.lang = 'th-TH';
                utterance.rate = rate;
                utterance.pitch = 1;
                utterance.volume = 1;
                
                // Handle errors
                utterance.onerror = function(event) {
                    console.error('Speech synthesis error:', event.error);
                };
                
                speechSynthesis.speak(utterance);
            } else {
                console.warn('Speech synthesis not supported in this browser');
            }
        }
        
        // Auto refresh every 60 seconds
        let autoRefreshInterval = setInterval(function() {
            if (document.visibilityState === 'visible') {
                location.reload();
            }
        }, 60000);
        
        // Pause auto refresh when page is not visible
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'hidden') {
                clearInterval(autoRefreshInterval);
            } else {
                autoRefreshInterval = setInterval(function() {
                    location.reload();
                }, 60000);
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // R key for refresh
            if (e.key === 'r' && e.ctrlKey) {
                e.preventDefault();
                location.reload();
            }
            
            // F5 for refresh
            if (e.key === 'F5') {
                e.preventDefault();
                location.reload();
            }
        });
        
        console.log('Order list page loaded successfully');
    </script>
</body>
</html>