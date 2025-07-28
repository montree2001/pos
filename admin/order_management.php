<?php
/**
 * จัดการออเดอร์
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

$pageTitle = 'จัดการออเดอร์';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: order_management.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_status') {
        $orderId = intval($_POST['order_id'] ?? 0);
        $status = $_POST['status'] ?? '';
        
        $validStatuses = ['pending', 'confirmed', 'preparing', 'ready', 'completed', 'cancelled'];
        if ($orderId && in_array($status, $validStatuses)) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                $conn->beginTransaction();
                
                // อัปเดตสถานะออเดอร์
                $stmt = $conn->prepare("UPDATE orders SET status = ?, updated_at = NOW() WHERE order_id = ?");
                $stmt->execute([$status, $orderId]);
                
                // บันทึกประวัติ
                $stmt = $conn->prepare("
                    INSERT INTO order_status_history (order_id, status, changed_by, created_at) 
                    VALUES (?, ?, ?, NOW())
                ");
                $stmt->execute([$orderId, $status, getCurrentUserId()]);
                
                $conn->commit();
                
                setFlashMessage('success', 'อัปเดตสถานะออเดอร์สำเร็จ');
                writeLog("Updated order status: Order ID $orderId to $status by " . getCurrentUser()['username']);
                
            } catch (Exception $e) {
                $conn->rollback();
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error updating order status: " . $e->getMessage());
            }
        }
    }
    
    header('Location: order_management.php');
    exit();
}

// ดึงข้อมูลออเดอร์
$orders = [];
$stats = ['total' => 0, 'pending' => 0, 'completed' => 0, 'today_revenue' => 0];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงข้อมูลออเดอร์
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name, u.phone 
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        ORDER BY o.created_at DESC 
        LIMIT 100
    ");
    $stmt->execute();
    $orders = $stmt->fetchAll();
    
    // สถิติออเดอร์
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders");
    $stmt->execute();
    $stats['total'] = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE status IN ('pending', 'confirmed', 'preparing')");
    $stmt->execute();
    $stats['pending'] = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE status = 'completed'");
    $stmt->execute();
    $stats['completed'] = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_price), 0) 
        FROM orders 
        WHERE DATE(created_at) = CURDATE() AND payment_status = 'paid'
    ");
    $stmt->execute();
    $stats['today_revenue'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    writeLog("Error loading orders: " . $e->getMessage());
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">จัดการออเดอร์</h1>
        <p class="text-muted mb-0">ดูและจัดการออเดอร์ทั้งหมดในระบบ</p>
    </div>
    <div>
        <button class="btn btn-primary" onclick="location.reload()">
            <i class="fas fa-sync-alt me-2"></i>รีเฟรช
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['total']); ?></div>
                    <div class="stats-label">ออเดอร์ทั้งหมด</div>
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
                    <div class="stats-number"><?php echo number_format($stats['pending']); ?></div>
                    <div class="stats-label">รอดำเนินการ</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['completed']); ?></div>
                    <div class="stats-label">เสร็จสิ้น</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-check-circle"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo formatCurrency($stats['today_revenue']); ?></div>
                    <div class="stats-label">รายได้วันนี้</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-money-bill-wave"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Orders Table -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-list me-2"></i>รายการออเดอร์
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="ordersTable">
                <thead>
                    <tr>
                        <th>หมายเลขออเดอร์</th>
                        <th>ลูกค้า</th>
                        <th>ยอดรวม</th>
                        <th>สถานะ</th>
                        <th>การชำระเงิน</th>
                        <th>วันที่สั่ง</th>
                        <th>การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($orders as $order): ?>
                        <tr>
                            <td>
                                <strong><?php echo clean($order['queue_number'] ?: 'ORD-' . $order['order_id']); ?></strong>
                                <?php if ($order['order_type'] !== 'dine_in'): ?>
                                    <br><small class="text-muted"><?php echo ucfirst($order['order_type']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></div>
                                <?php if ($order['phone']): ?>
                                    <small class="text-muted"><?php echo clean($order['phone']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo formatCurrency($order['total_price']); ?></strong>
                            </td>
                            <td>
                                <span class="badge <?php echo getOrderStatusClass($order['status']); ?>">
                                    <?php echo getOrderStatusText($order['status']); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($order['payment_status'] === 'paid'): ?>
                                    <span class="badge bg-success">ชำระแล้ว</span>
                                <?php elseif ($order['payment_status'] === 'unpaid'): ?>
                                    <span class="badge bg-danger">ยังไม่ชำระ</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary"><?php echo $order['payment_status']; ?></span>
                                <?php endif; ?>
                                <?php if ($order['payment_method'] && $order['payment_method'] !== 'cash'): ?>
                                    <br><small class="text-muted"><?php echo ucfirst($order['payment_method']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div><?php echo formatDate($order['created_at'], 'd/m/Y'); ?></div>
                                <small class="text-muted"><?php echo formatDate($order['created_at'], 'H:i'); ?></small>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" 
                                            onclick="viewOrderDetails(<?php echo $order['order_id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if (in_array($order['status'], ['pending', 'confirmed', 'preparing'])): ?>
                                        <div class="dropdown">
                                            <button class="btn btn-outline-success dropdown-toggle" 
                                                    type="button" data-bs-toggle="dropdown">
                                                <i class="fas fa-edit"></i>
                                            </button>
                                            <ul class="dropdown-menu">
                                                <li><a class="dropdown-item" href="#" onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'confirmed')">ยืนยัน</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'preparing')">กำลังเตรียม</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'ready')">พร้อมเสิร์ฟ</a></li>
                                                <li><a class="dropdown-item" href="#" onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'completed')">เสร็จสิ้น</a></li>
                                                <li><hr class="dropdown-divider"></li>
                                                <li><a class="dropdown-item text-danger" href="#" onclick="updateOrderStatus(<?php echo $order['order_id']; ?>, 'cancelled')">ยกเลิก</a></li>
                                            </ul>
                                        </div>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-info" 
                                            onclick="printReceipt(<?php echo $order['order_id']; ?>)">
                                        <i class="fas fa-print"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
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
                <div class="text-center">
                    <div class="spinner-border" role="status"></div>
                    <p class="mt-2">กำลังโหลด...</p>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Status Update Form -->
<form id="statusUpdateForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="update_status">
    <input type="hidden" name="order_id" id="statusOrderId">
    <input type="hidden" name="status" id="statusNewStatus">
</form>

<?php
$additionalJS = [
    'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js'
];

$inlineJS = "
// Initialize DataTable
$('#ordersTable').DataTable({
    order: [[5, 'desc']],
    columnDefs: [
        { orderable: false, targets: [6] }
    ],
    language: {
        url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
    }
});

// View order details
function viewOrderDetails(orderId) {
    const modal = new bootstrap.Modal(document.getElementById('orderDetailsModal'));
    modal.show();
    
    $('#orderDetailsBody').html('<div class=\"text-center\"><div class=\"spinner-border\" role=\"status\"></div><p class=\"mt-2\">กำลังโหลด...</p></div>');
    
    $.get(SITE_URL + '/api/orders.php?action=get&id=' + orderId, function(response) {
        if (response.success) {
            const order = response.order;
            let html = '<div class=\"row\">';
            html += '<div class=\"col-md-6\">';
            html += '<h6>ข้อมูลออเดอร์</h6>';
            html += '<p><strong>หมายเลข:</strong> ' + (order.queue_number || 'ORD-' + order.order_id) + '</p>';
            html += '<p><strong>ลูกค้า:</strong> ' + (order.customer_name || 'ลูกค้าทั่วไป') + '</p>';
            html += '<p><strong>เบอร์โทร:</strong> ' + (order.phone || '-') + '</p>';
            html += '<p><strong>ประเภท:</strong> ' + order.order_type + '</p>';
            html += '<p><strong>ยอดรวม:</strong> ' + formatCurrency(order.total_price) + '</p>';
            html += '</div>';
            html += '<div class=\"col-md-6\">';
            html += '<h6>สถานะ</h6>';
            html += '<p><strong>สถานะออเดอร์:</strong> <span class=\"badge ' + getOrderStatusClass(order.status) + '\">' + getOrderStatusText(order.status) + '</span></p>';
            html += '<p><strong>การชำระเงิน:</strong> ' + order.payment_status + '</p>';
            html += '<p><strong>วิธีชำระ:</strong> ' + (order.payment_method || '-') + '</p>';
            html += '<p><strong>วันที่สั่ง:</strong> ' + formatDate(order.created_at) + '</p>';
            html += '</div>';
            html += '</div>';
            
            if (order.notes) {
                html += '<hr><h6>หมายเหตุ</h6><p>' + order.notes + '</p>';
            }
            
            if (order.items && order.items.length > 0) {
                html += '<hr><h6>รายการสินค้า</h6>';
                html += '<div class=\"table-responsive\">';
                html += '<table class=\"table table-sm\">';
                html += '<thead><tr><th>สินค้า</th><th>จำนวน</th><th>ราคา</th><th>รวม</th></tr></thead>';
                html += '<tbody>';
                order.items.forEach(function(item) {
                    html += '<tr>';
                    html += '<td>' + item.product_name + '</td>';
                    html += '<td>' + item.quantity + '</td>';
                    html += '<td>' + formatCurrency(item.unit_price) + '</td>';
                    html += '<td>' + formatCurrency(item.subtotal) + '</td>';
                    html += '</tr>';
                });
                html += '</tbody></table>';
                html += '</div>';
            }
            
            $('#orderDetailsBody').html(html);
        } else {
            $('#orderDetailsBody').html('<div class=\"alert alert-danger\">ไม่สามารถโหลดข้อมูลได้</div>');
        }
    }).fail(function() {
        $('#orderDetailsBody').html('<div class=\"alert alert-danger\">เกิดข้อผิดพลาดในการโหลดข้อมูล</div>');
    });
}

// Update order status
function updateOrderStatus(orderId, status) {
    const statusText = getOrderStatusText(status);
    
    confirmAction('ต้องการเปลี่ยนสถานะเป็น \"' + statusText + '\"?', function() {
        $('#statusOrderId').val(orderId);
        $('#statusNewStatus').val(status);
        $('#statusUpdateForm').submit();
    });
}

// Print receipt
function printReceipt(orderId) {
    window.open(SITE_URL + '/api/print_receipt.php?order_id=' + orderId, '_blank');
}

// Helper functions
function getOrderStatusText(status) {
    const statusMap = {
        'pending': 'รอยืนยัน',
        'confirmed': 'ยืนยันแล้ว',
        'preparing': 'กำลังเตรียม',
        'ready': 'พร้อมเสิร์ฟ',
        'completed': 'เสร็จสิ้น',
        'cancelled': 'ยกเลิก'
    };
    return statusMap[status] || status;
}

function getOrderStatusClass(status) {
    const classMap = {
        'pending': 'bg-warning',
        'confirmed': 'bg-info',
        'preparing': 'bg-primary',
        'ready': 'bg-success',
        'completed': 'bg-secondary',
        'cancelled': 'bg-danger'
    };
    return classMap[status] || 'bg-secondary';
}

console.log('Order Management loaded successfully');
";

require_once '../includes/footer.php';
?>