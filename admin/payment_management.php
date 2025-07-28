<?php
/**
 * จัดการการชำระเงิน
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

$pageTitle = 'จัดการการชำระเงิน';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: payment_management.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'update_payment_status') {
        $orderId = intval($_POST['order_id'] ?? 0);
        $paymentStatus = $_POST['payment_status'] ?? '';
        
        if ($orderId && in_array($paymentStatus, ['paid', 'unpaid', 'refunded'])) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                $stmt = $conn->prepare("UPDATE orders SET payment_status = ?, updated_at = NOW() WHERE order_id = ?");
                $stmt->execute([$paymentStatus, $orderId]);
                
                setFlashMessage('success', 'อัปเดตสถานะการชำระเงินสำเร็จ');
                writeLog("Updated payment status: Order ID $orderId to $paymentStatus by " . getCurrentUser()['username']);
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error updating payment status: " . $e->getMessage());
            }
        }
    } elseif ($action === 'add_payment') {
        $orderId = intval($_POST['order_id'] ?? 0);
        $amount = floatval($_POST['amount'] ?? 0);
        $paymentMethod = $_POST['payment_method'] ?? '';
        $referenceNumber = trim($_POST['reference_number'] ?? '');
        
        if ($orderId && $amount > 0 && $paymentMethod) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                $conn->beginTransaction();
                
                // เพิ่มรายการชำระเงิน
                $stmt = $conn->prepare("
                    INSERT INTO payments (order_id, amount, payment_method, reference_number, payment_date, status) 
                    VALUES (?, ?, ?, ?, NOW(), 'completed')
                ");
                $stmt->execute([$orderId, $amount, $paymentMethod, $referenceNumber]);
                
                // อัปเดตสถานะออเดอร์
                $stmt = $conn->prepare("UPDATE orders SET payment_status = 'paid', updated_at = NOW() WHERE order_id = ?");
                $stmt->execute([$orderId]);
                
                $conn->commit();
                
                setFlashMessage('success', 'บันทึกการชำระเงินสำเร็จ');
                writeLog("Added payment: Order ID $orderId, Amount $amount by " . getCurrentUser()['username']);
                
            } catch (Exception $e) {
                $conn->rollback();
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error adding payment: " . $e->getMessage());
            }
        }
    }
    
    header('Location: payment_management.php');
    exit();
}

// ดึงข้อมูลการชำระเงิน
$payments = [];
$unpaidOrders = [];
$stats = [
    'total_payments' => 0,
    'today_revenue' => 0,
    'unpaid_orders' => 0,
    'refunded_amount' => 0
];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // รายการชำระเงิน
    $stmt = $conn->prepare("
        SELECT p.*, o.queue_number, o.total_price, u.fullname as customer_name
        FROM payments p
        LEFT JOIN orders o ON p.order_id = o.order_id
        LEFT JOIN users u ON o.user_id = u.user_id
        ORDER BY p.payment_date DESC
        LIMIT 100
    ");
    $stmt->execute();
    $payments = $stmt->fetchAll();
    
    // ออเดอร์ที่ยังไม่ชำระเงิน
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name, u.phone
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE o.payment_status = 'unpaid'
        AND o.status != 'cancelled'
        ORDER BY o.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $unpaidOrders = $stmt->fetchAll();
    
    // สถิติ
    $stmt = $conn->prepare("SELECT COUNT(*) FROM payments WHERE status = 'completed'");
    $stmt->execute();
    $stats['total_payments'] = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM payments 
        WHERE DATE(payment_date) = CURDATE() AND status = 'completed'
    ");
    $stmt->execute();
    $stats['today_revenue'] = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM orders WHERE payment_status = 'unpaid' AND status != 'cancelled'");
    $stmt->execute();
    $stats['unpaid_orders'] = $stmt->fetchColumn();
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) 
        FROM payments 
        WHERE status = 'refunded'
    ");
    $stmt->execute();
    $stats['refunded_amount'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    writeLog("Error loading payment data: " . $e->getMessage());
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">จัดการการชำระเงิน</h1>
        <p class="text-muted mb-0">ตรวจสอบและจัดการการชำระเงินทั้งหมด</p>
    </div>
    <div>
        <button class="btn btn-success" onclick="exportPaymentReport()">
            <i class="fas fa-download me-2"></i>ส่งออกรายงาน
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
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
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['total_payments']); ?></div>
                    <div class="stats-label">ยอดชำระทั้งหมด</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-receipt"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card warning">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo number_format($stats['unpaid_orders']); ?></div>
                    <div class="stats-label">ยังไม่ชำระ</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-exclamation-triangle"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card danger">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo formatCurrency($stats['refunded_amount']); ?></div>
                    <div class="stats-label">ยอดคืนเงิน</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-undo"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Unpaid Orders -->
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-exclamation-circle me-2"></i>ออเดอร์ที่ยังไม่ชำระเงิน
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($unpaidOrders)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm">
                            <thead>
                                <tr>
                                    <th>ออเดอร์</th>
                                    <th>ลูกค้า</th>
                                    <th>ยอดเงิน</th>
                                    <th>วันที่</th>
                                    <th>การกระทำ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($unpaidOrders as $order): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo clean($order['queue_number'] ?: 'ORD-' . $order['order_id']); ?></strong>
                                        </td>
                                        <td>
                                            <div><?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></div>
                                            <?php if ($order['phone']): ?>
                                                <small class="text-muted"><?php echo clean($order['phone']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong class="text-danger"><?php echo formatCurrency($order['total_price']); ?></strong>
                                        </td>
                                        <td>
                                            <small><?php echo formatDate($order['created_at'], 'd/m/Y H:i'); ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-success" 
                                                        onclick="markAsPaid(<?php echo $order['order_id']; ?>)">
                                                    <i class="fas fa-check"></i>
                                                </button>
                                                <button class="btn btn-primary" 
                                                        data-bs-toggle="modal" 
                                                        data-bs-target="#paymentModal"
                                                        onclick="openPaymentModal(<?php echo $order['order_id']; ?>, <?php echo $order['total_price']; ?>)">
                                                    <i class="fas fa-plus"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-check-circle fa-2x mb-2 text-success"></i>
                        <p>ไม่มีออเดอร์ที่ค้างชำระ</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Payment Methods Chart -->
    <div class="col-xl-6 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-chart-pie me-2"></i>สัดส่วนการชำระเงิน
                </h5>
            </div>
            <div class="card-body">
                <canvas id="paymentMethodChart" height="200"></canvas>
            </div>
        </div>
    </div>
</div>

<!-- Payment History -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-history me-2"></i>ประวัติการชำระเงิน
        </h5>
    </div>
    <div class="card-body">
        <div class="table-responsive">
            <table class="table table-hover" id="paymentsTable">
                <thead>
                    <tr>
                        <th>วันที่ชำระ</th>
                        <th>ออเดอร์</th>
                        <th>ลูกค้า</th>
                        <th>จำนวนเงิน</th>
                        <th>วิธีชำระ</th>
                        <th>เลขอ้างอิง</th>
                        <th>สถานะ</th>
                        <th>การกระทำ</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td><?php echo formatDate($payment['payment_date'], 'd/m/Y H:i'); ?></td>
                            <td>
                                <strong><?php echo clean($payment['queue_number'] ?: 'ORD-' . $payment['order_id']); ?></strong>
                            </td>
                            <td><?php echo clean($payment['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></td>
                            <td>
                                <strong class="text-success"><?php echo formatCurrency($payment['amount']); ?></strong>
                            </td>
                            <td>
                                <?php
                                $methodMap = [
                                    'cash' => '<i class="fas fa-money-bill text-success"></i> เงินสด',
                                    'promptpay' => '<i class="fas fa-qrcode text-primary"></i> PromptPay',
                                    'credit_card' => '<i class="fas fa-credit-card text-info"></i> บัตรเครดิต',
                                    'line_pay' => '<i class="fab fa-line text-success"></i> LINE Pay'
                                ];
                                echo $methodMap[$payment['payment_method']] ?? $payment['payment_method'];
                                ?>
                            </td>
                            <td>
                                <?php if ($payment['reference_number']): ?>
                                    <code><?php echo clean($payment['reference_number']); ?></code>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($payment['status'] === 'completed'): ?>
                                    <span class="badge bg-success">สำเร็จ</span>
                                <?php elseif ($payment['status'] === 'pending'): ?>
                                    <span class="badge bg-warning">รอดำเนินการ</span>
                                <?php elseif ($payment['status'] === 'failed'): ?>
                                    <span class="badge bg-danger">ล้มเหลว</span>
                                <?php elseif ($payment['status'] === 'refunded'): ?>
                                    <span class="badge bg-secondary">คืนเงิน</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group btn-group-sm">
                                    <button class="btn btn-outline-primary" onclick="viewPaymentDetails(<?php echo $payment['payment_id']; ?>)">
                                        <i class="fas fa-eye"></i>
                                    </button>
                                    <?php if ($payment['status'] === 'completed'): ?>
                                        <button class="btn btn-outline-warning" onclick="refundPayment(<?php echo $payment['payment_id']; ?>)">
                                            <i class="fas fa-undo"></i>
                                        </button>
                                    <?php endif; ?>
                                    <button class="btn btn-outline-info" onclick="printReceipt(<?php echo $payment['order_id']; ?>)">
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

<!-- Payment Modal -->
<div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" id="paymentForm">
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="add_payment">
                <input type="hidden" name="order_id" id="paymentOrderId">
                
                <div class="modal-header">
                    <h5 class="modal-title">บันทึกการชำระเงิน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                
                <div class="modal-body">
                    <div class="mb-3">
                        <label for="amount" class="form-label">จำนวนเงิน *</label>
                        <input type="number" class="form-control" id="amount" name="amount" step="0.01" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="payment_method" class="form-label">วิธีชำระเงิน *</label>
                        <select class="form-select" id="payment_method" name="payment_method" required>
                            <option value="">เลือกวิธีชำระ</option>
                            <option value="cash">เงินสด</option>
                            <option value="promptpay">PromptPay</option>
                            <option value="credit_card">บัตรเครดิต</option>
                            <option value="line_pay">LINE Pay</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="reference_number" class="form-label">เลขอ้างอิง</label>
                        <input type="text" class="form-control" id="reference_number" name="reference_number" 
                               placeholder="เลขที่ใบเสร็จ หรือ Transaction ID">
                    </div>
                </div>
                
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save me-2"></i>บันทึก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Update Payment Status Form -->
<form id="updatePaymentForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="update_payment_status">
    <input type="hidden" name="order_id" id="updateOrderId">
    <input type="hidden" name="payment_status" id="updatePaymentStatus">
</form>

<?php
$additionalJS = [
    'https://cdn.jsdelivr.net/npm/chart.js',
    'https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js',
    'https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js'
];

$inlineJS = "
// Initialize DataTable
$('#paymentsTable').DataTable({
    order: [[0, 'desc']],
    columnDefs: [
        { orderable: false, targets: [7] }
    ],
    language: {
        url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
    }
});

// Payment Method Chart
const paymentMethods = " . json_encode(array_count_values(array_column($payments, 'payment_method'))) . ";
const ctx = document.getElementById('paymentMethodChart');

if (ctx) {
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: Object.keys(paymentMethods).map(method => {
                const methodMap = {
                    'cash': 'เงินสด',
                    'promptpay': 'PromptPay',
                    'credit_card': 'บัตรเครดิต',
                    'line_pay': 'LINE Pay'
                };
                return methodMap[method] || method;
            }),
            datasets: [{
                data: Object.values(paymentMethods),
                backgroundColor: [
                    '#10b981',
                    '#3b82f6',
                    '#8b5cf6',
                    '#06d6a0'
                ]
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// Open payment modal
function openPaymentModal(orderId, amount) {
    $('#paymentOrderId').val(orderId);
    $('#amount').val(amount);
}

// Mark as paid
function markAsPaid(orderId) {
    confirmAction('ต้องการเปลี่ยนสถานะเป็น \"ชำระแล้ว\"?', function() {
        $('#updateOrderId').val(orderId);
        $('#updatePaymentStatus').val('paid');
        $('#updatePaymentForm').submit();
    });
}

// View payment details
function viewPaymentDetails(paymentId) {
    // Implementation for viewing payment details
    alert('ฟีเจอร์นี้จะพัฒนาเพิ่มเติม - Payment ID: ' + paymentId);
}

// Refund payment
function refundPayment(paymentId) {
    confirmAction('ต้องการคืนเงินรายการนี้? การดำเนินการนี้ไม่สามารถยกเลิกได้', function() {
        alert('ฟีเจอร์นี้จะพัฒนาเพิ่มเติม - Payment ID: ' + paymentId);
    });
}

// Print receipt
function printReceipt(orderId) {
    window.open(SITE_URL + '/api/print_receipt.php?order_id=' + orderId, '_blank');
}

// Export payment report
function exportPaymentReport() {
    window.open(SITE_URL + '/api/export_payments.php', '_blank');
}

console.log('Payment Management loaded successfully');
";

require_once '../includes/footer.php';
?>