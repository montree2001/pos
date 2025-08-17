<?php
/**
 * ระบบชำระเงิน - POS (แก้ไขแล้ว)
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/session.php';
require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/auth.php';

// ตรวจสอบสิทธิ์
if (!isLoggedIn() || !in_array(getCurrentUserRole(), ['admin', 'staff'])) {
    header('Location: login.php');
    exit();
}

$pageTitle = 'ชำระเงิน';
$error = null;
$success = null;

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid request';
    } else {
        $cartData = json_decode($_POST['cart_data'] ?? '[]', true);
        $customerName = trim($_POST['customer_name'] ?? '');
        $customerPhone = trim($_POST['customer_phone'] ?? '');
        $orderType = $_POST['order_type'] ?? 'dine_in';
        $tableNumber = trim($_POST['table_number'] ?? '');
        $paymentMethod = $_POST['payment_method'] ?? 'cash';
        $notes = trim($_POST['notes'] ?? '');
        $cashReceived = floatval($_POST['cash_received'] ?? 0);
        $cashChange = floatval($_POST['cash_change'] ?? 0);
        
        if (empty($cartData)) {
            $error = 'ไม่มีสินค้าในตะกร้า';
        } else {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                $conn->beginTransaction();
                
                // คำนวณยอดรวม
                $totalPrice = 0;
                foreach ($cartData as $item) {
                    $totalPrice += $item['price'] * $item['quantity'];
                }
                
                // สร้างหมายเลขคิว
                $queueNumber = generateQueueNumber();
                
                // สร้างออเดอร์
                $stmt = $conn->prepare("
                    INSERT INTO orders (user_id, queue_number, total_price, status, order_type, 
                                      payment_status, payment_method, table_number, notes, created_at)
                    VALUES (NULL, ?, ?, 'confirmed', ?, 'paid', ?, ?, ?, NOW())
                ");
                $stmt->execute([$queueNumber, $totalPrice, $orderType, $paymentMethod, $tableNumber, $notes]);
                $orderId = $conn->lastInsertId();
                
                // เพิ่มรายการสินค้า
                foreach ($cartData as $item) {
                    $subtotal = $item['price'] * $item['quantity'];
                    $stmt = $conn->prepare("
                        INSERT INTO order_items (order_id, product_id, quantity, unit_price, subtotal, status)
                        VALUES (?, ?, ?, ?, ?, 'pending')
                    ");
                    $stmt->execute([$orderId, $item['id'], $item['quantity'], $item['price'], $subtotal]);
                }
                
                // บันทึกการชำระเงิน
                $paymentNotes = '';
                if ($paymentMethod === 'cash' && $cashReceived > 0) {
                    $paymentNotes = "เงินที่รับมา: ฿" . number_format($cashReceived, 2) . " | เงินทอน: ฿" . number_format($cashChange, 2);
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO payments (order_id, amount, payment_method, payment_date, status, admin_note)
                    VALUES (?, ?, ?, NOW(), 'completed', ?)
                ");
                $stmt->execute([$orderId, $totalPrice, $paymentMethod, $paymentNotes]);
                
                // สร้างข้อมูลลูกค้าชั่วคราว (ถ้ามี)
                if (!empty($customerName)) {
                    // อัปเดตออเดอร์ด้วยข้อมูลลูกค้า
                    $stmt = $conn->prepare("UPDATE orders SET notes = CONCAT(IFNULL(notes, ''), ' | ลูกค้า: $customerName', IF(? != '', CONCAT(' | โทร: ', ?), '')) WHERE order_id = ?");
                    $stmt->execute([$customerPhone, $customerPhone, $orderId]);
                }
                
                $conn->commit();
                
                // Redirect ไปหน้าพิมพ์ใบเสร็จ
                header("Location: print_receipt.php?order_id=$orderId");
                exit();
                
            } catch (Exception $e) {
                $conn->rollback();
                writeLog("Payment error: " . $e->getMessage());
                $error = 'เกิดข้อผิดพลาดในการบันทึกออเดอร์: ' . $e->getMessage();
            }
        }
    }
}

// ฟังก์ชันสร้างหมายเลขคิว
function generateQueueNumber() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // หาหมายเลขคิวล่าสุดของวันนี้
        $today = date('Y-m-d');
        $stmt = $conn->prepare("
            SELECT queue_number 
            FROM orders 
            WHERE DATE(created_at) = ? 
            AND queue_number LIKE ? 
            ORDER BY queue_number DESC 
            LIMIT 1
        ");
        
        $prefix = 'Q' . date('ymd'); // Q250729
        $stmt->execute([$today, $prefix . '%']);
        $lastQueue = $stmt->fetch();
        
        if ($lastQueue) {
            // ดึงเลขท้ายและเพิ่มขึ้น 1
            $lastNumber = intval(substr($lastQueue['queue_number'], -3));
            $newNumber = $lastNumber + 1;
        } else {
            // เริ่มต้นด้วย 1
            $newNumber = 1;
        }
        
        // สร้างหมายเลขคิวใหม่ (รูปแบบ: Q250729001)
        $queueNumber = $prefix . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
        
        // ตรวจสอบความยาวไม่เกิน 10 ตัวอักษร
        if (strlen($queueNumber) > 10) {
            // ถ้าเกิน 10 ตัว ให้ใช้รูปแบบสั้นลง
            $shortPrefix = 'Q' . date('md'); // Q0729
            $queueNumber = $shortPrefix . str_pad($newNumber, 3, '0', STR_PAD_LEFT);
        }
        
        return $queueNumber;
        
    } catch (Exception $e) {
        // ถ้าเกิดข้อผิดพลาด ใช้รูปแบบง่าย ๆ
        $prefix = 'Q' . date('md'); // Q0729
        $random = str_pad(mt_rand(1, 999), 3, '0', STR_PAD_LEFT);
        return $prefix . $random;
    }
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
            --pos-primary: #4f46e5;
            --pos-success: #10b981;
            --pos-warning: #f59e0b;
            --pos-danger: #ef4444;
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
            max-width: 1200px;
            margin: 0 auto;
        }
        
        .pos-header {
            background: linear-gradient(135deg, var(--pos-success), #059669);
            color: white;
            border-radius: var(--pos-border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--pos-shadow);
        }
        
        .main-content {
            display: grid;
            grid-template-columns: 1fr 400px;
            gap: 20px;
        }
        
        .payment-section {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            box-shadow: var(--pos-shadow);
            overflow: hidden;
        }
        
        .order-summary {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            box-shadow: var(--pos-shadow);
            position: sticky;
            top: 20px;
            height: fit-content;
        }
        
        .section-header {
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            padding: 15px 20px;
            border-bottom: 1px solid #e5e7eb;
            font-weight: 600;
        }
        
        .section-body {
            padding: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-label {
            font-weight: 600;
            margin-bottom: 8px;
            color: #374151;
        }
        
        .form-control, .form-select {
            border-radius: 8px;
            border: 2px solid #e5e7eb;
            padding: 12px 15px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--pos-primary);
            box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.15);
        }
        
        .payment-methods {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .payment-method {
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .payment-method:hover {
            border-color: var(--pos-primary);
            background: #fafbff;
        }
        
        .payment-method.selected {
            border-color: var(--pos-primary);
            background: #fafbff;
        }
        
        .payment-method input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }
        
        .payment-icon {
            font-size: 2rem;
            margin-bottom: 10px;
            color: var(--pos-primary);
        }
        
        .order-items {
            max-height: 300px;
            overflow-y: auto;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .order-item:last-child {
            border-bottom: none;
        }
        
        .item-info {
            flex: 1;
        }
        
        .item-name {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .item-details {
            color: #6b7280;
            font-size: 0.8rem;
        }
        
        .item-total {
            font-weight: 600;
            color: var(--pos-primary);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
        }
        
        .total-row {
            font-weight: 700;
            font-size: 1.2rem;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            color: var(--pos-success);
        }
        
        .submit-btn {
            width: 100%;
            background: linear-gradient(135deg, var(--pos-success), #059669);
            border: none;
            border-radius: 12px;
            padding: 15px;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            margin-top: 20px;
            transition: all 0.3s ease;
        }
        
        .submit-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-1px);
        }
        
        .submit-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6b7280;
        }
        
        .cash-payment-section {
            background: #f8fafc;
            border-radius: 8px;
            padding: 15px;
            margin-top: 15px;
        }
        
        .change-display {
            background: #f0f9f4;
            border: 2px solid #d1fae5;
            border-radius: 8px;
            padding: 12px;
            font-size: 1.1rem;
            font-weight: 600;
            text-align: center;
        }
        
        .change-display.negative {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }
        
        .change-display.positive {
            background: #f0f9f4;
            border-color: #d1fae5;
            color: #059669;
        }
        
        .table-grid {
            display: grid;
            grid-template-columns: repeat(8, 1fr);
            gap: 8px;
            max-height: 280px;
            overflow-y: auto;
            padding: 15px;
            background: #f8fafc;
            border-radius: 12px;
            border: 2px solid #e5e7eb;
        }
        
        .table-option {
            padding: 12px 8px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            background: white;
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .table-option:hover {
            border-color: var(--pos-success);
            background: #f0f9f4;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.2);
        }
        
        .table-option.selected {
            border-color: var(--pos-success);
            background: var(--pos-success);
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(16, 185, 129, 0.3);
        }
        
        .table-selector {
            position: relative;
        }
        
        /* Cash Payment Modal Styles */
        .quick-amounts {
            background: #f8fafc;
            border-radius: 12px;
            padding: 15px;
        }
        
        .amount-btn {
            padding: 15px 10px;
            border: 2px solid #e5e7eb;
            border-radius: 8px;
            background: white;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            font-weight: 600;
            font-size: 1.1rem;
        }
        
        .amount-btn:hover {
            border-color: var(--pos-success);
            background: #f0f9f4;
            transform: translateY(-2px);
        }
        
        .amount-btn.selected {
            border-color: var(--pos-success);
            background: var(--pos-success);
            color: white;
        }
        
        .amount-display {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            font-size: 1.3rem;
            font-weight: 700;
            border: 2px solid #e5e7eb;
        }
        
        .amount-display.received {
            background: #eff6ff;
            border-color: #bfdbfe;
            color: #1d4ed8;
        }
        
        .amount-display.change {
            background: #f0f9f4;
            border-color: #bbf7d0;
            color: #059669;
        }
        
        .amount-display.negative {
            background: #fef2f2;
            border-color: #fecaca;
            color: #dc2626;
        }
        
        .status-message {
            padding: 10px;
            border-radius: 8px;
            text-align: center;
            font-weight: 600;
            background: #f3f4f6;
            color: #6b7280;
        }
        
        .status-message.success {
            background: #f0f9f4;
            color: #059669;
        }
        
        .status-message.error {
            background: #fef2f2;
            color: #dc2626;
        }
        
        .change-summary {
            background: #f8fafc;
            border-radius: 12px;
            padding: 20px;
            border: 2px solid #e5e7eb;
        }
        
        #cashAmountInput:focus {
            border-color: var(--pos-success);
            box-shadow: 0 0 0 0.2rem rgba(16, 185, 129, 0.25);
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .order-summary {
                position: relative;
                top: 0;
                order: -1;
            }
            
            .payment-methods {
                grid-template-columns: 1fr;
            }
            
            .pos-container {
                padding: 10px;
            }
            
            .table-grid {
                grid-template-columns: repeat(6, 1fr);
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
                        <i class="fas fa-credit-card me-2"></i>
                        ชำระเงิน
                    </h1>
                    <p class="mb-0 opacity-75">ยืนยันออเดอร์และรับชำระเงิน</p>
                </div>
                <div>
                    <a href="new_order.php" class="btn btn-light">
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
        
        <div class="main-content">
            <!-- Payment Form -->
            <div class="payment-section">
                <div class="section-header">
                    <i class="fas fa-clipboard-list me-2"></i>
                    ข้อมูลออเดอร์
                </div>
                
                <div class="section-body">
                    <form method="POST" id="paymentForm">
                        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                        <input type="hidden" name="cart_data" id="cartData" value="">
                        
                        <!-- Customer Information -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">ชื่อลูกค้า (ไม่บังคับ)</label>
                                    <input type="text" class="form-control" name="customer_name" 
                                           placeholder="ระบุชื่อลูกค้า">
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">เบอร์โทร (ไม่บังคับ)</label>
                                    <input type="tel" class="form-control" name="customer_phone" 
                                           placeholder="หมายเลขโทรศัพท์">
                                </div>
                            </div>
                        </div>
                        
                        <!-- Order Type -->
                        <div class="row">
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">ประเภทออเดอร์</label>
                                    <select class="form-select" name="order_type" id="orderType" required onchange="toggleTableSelection()">
                                        <option value="dine_in">ทานที่ร้าน</option>
                                        <option value="takeaway">ซื้อกลับ</option>
                                        <option value="delivery">จัดส่ง</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group" id="tableGroup">
                                    <label class="form-label">หมายเลขโต๊ะ</label>
                                    <div class="table-selector">
                                        <div class="table-grid">
                                            <?php 
                                            // สร้างตัวเลือกโต๊ะ A1-A40 แบบ grid
                                            for ($i = 1; $i <= 40; $i++): 
                                                $tableNum = 'A' . str_pad($i, 2, '0', STR_PAD_LEFT);
                                            ?>
                                                <div class="table-option" data-table="<?php echo $tableNum; ?>" onclick="selectTable('<?php echo $tableNum; ?>')">
                                                    <?php echo $tableNum; ?>
                                                </div>
                                            <?php endfor; ?>
                                        </div>
                                        <input type="hidden" name="table_number" id="selectedTable" value="">
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Payment Method -->
                        <div class="form-group">
                            <label class="form-label">วิธีชำระเงิน</label>
                            <div class="payment-methods">
                                <div class="payment-method selected" onclick="selectPayment('cash')">
                                    <input type="radio" name="payment_method" value="cash" checked>
                                    <div class="payment-icon">
                                        <i class="fas fa-money-bill-wave"></i>
                                    </div>
                                    <div class="fw-semibold">เงินสด</div>
                                </div>
                                
                                <div class="payment-method" onclick="selectPayment('promptpay')">
                                    <input type="radio" name="payment_method" value="promptpay">
                                    <div class="payment-icon">
                                        <i class="fas fa-qrcode"></i>
                                    </div>
                                    <div class="fw-semibold">PromptPay</div>
                                </div>
                                
                                <div class="payment-method" onclick="selectPayment('credit_card')">
                                    <input type="radio" name="payment_method" value="credit_card">
                                    <div class="payment-icon">
                                        <i class="fas fa-credit-card"></i>
                                    </div>
                                    <div class="fw-semibold">บัตรเครดิต</div>
                                </div>
                                
                                <div class="payment-method" onclick="selectPayment('line_pay')">
                                    <input type="radio" name="payment_method" value="line_pay">
                                    <div class="payment-icon">
                                        <i class="fab fa-line"></i>
                                    </div>
                                    <div class="fw-semibold">LINE Pay</div>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Hidden fields for cash payment -->
                        <input type="hidden" name="cash_received" id="cashReceivedHidden" value="">
                        <input type="hidden" name="cash_change" id="cashChangeHidden" value="">
                        
                        <!-- Notes -->
                        <div class="form-group">
                            <label class="form-label">หมายเหตุ (ไม่บังคับ)</label>
                            <textarea class="form-control" name="notes" rows="3" 
                                      placeholder="ข้อมูลเพิ่มเติม เช่น ความต้องการพิเศษ"></textarea>
                        </div>
                        
                        <button type="submit" class="submit-btn" id="submitBtn" disabled>
                            <i class="fas fa-check-circle me-2"></i>
                            ยืนยันและชำระเงิน
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- Order Summary -->
            <div class="order-summary">
                <div class="section-header">
                    <i class="fas fa-receipt me-2"></i>
                    สรุปออเดอร์
                </div>
                
                <div class="section-body">
                    <div class="order-items" id="orderItems">
                        <div class="text-center text-muted py-4">
                            <i class="fas fa-shopping-basket fa-2x mb-2"></i>
                            <p>ไม่มีสินค้าในออเดอร์</p>
                        </div>
                    </div>
                    
                    <div class="mt-3" id="orderSummary" style="display: none;">
                        <div class="summary-row">
                            <span>จำนวนรายการ:</span>
                            <span id="totalItems">0</span>
                        </div>
                        <div class="summary-row total-row">
                            <span>รวมทั้งสิ้น:</span>
                            <span id="totalPrice">฿0.00</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Cash Payment Modal -->
    <div class="modal fade" id="cashPaymentModal" tabindex="-1" aria-labelledby="cashPaymentModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title" id="cashPaymentModalLabel">
                        <i class="fas fa-money-bill-wave me-2"></i>
                        ชำระเงินสด
                    </h5>
                </div>
                <div class="modal-body">
                    <div class="text-center mb-4">
                        <h3 class="text-success">ยอดที่ต้องชำระ</h3>
                        <h1 class="display-4 text-success fw-bold" id="totalAmountDisplay">฿0.00</h1>
                    </div>
                    
                    <div class="row">
                        <!-- Quick Amount Buttons -->
                        <div class="col-md-8">
                            <label class="form-label fw-bold">เลือกจำนวนเงินที่รับมา</label>
                            <div class="quick-amounts mb-3">
                                <div class="row g-2" id="quickAmountButtons">
                                    <!-- จะถูกสร้างด้วย JavaScript -->
                                </div>
                            </div>
                            
                            <label class="form-label fw-bold">หรือกรอกจำนวนเงิน</label>
                            <div class="input-group">
                                <span class="input-group-text">฿</span>
                                <input type="number" class="form-control form-control-lg" 
                                       id="cashAmountInput" step="1" min="0" 
                                       placeholder="0" style="font-size: 1.5rem; text-align: center;">
                            </div>
                        </div>
                        
                        <!-- Change Display -->
                        <div class="col-md-4">
                            <div class="change-summary">
                                <div class="mb-3">
                                    <label class="form-label">เงินที่รับมา</label>
                                    <div class="amount-display received" id="receivedDisplay">฿0.00</div>
                                </div>
                                
                                <div class="mb-3">
                                    <label class="form-label">เงินทอน</label>
                                    <div class="amount-display change" id="changeDisplay">฿0.00</div>
                                </div>
                                
                                <div class="status-message" id="statusMessage">
                                    กรุณาเลือกจำนวนเงินที่รับมา
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times me-1"></i>ยกเลิก
                    </button>
                    <button type="button" class="btn btn-success btn-lg" id="confirmCashPayment" disabled>
                        <i class="fas fa-check-circle me-2"></i>
                        ยืนยันการชำระเงิน
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Load cart data from sessionStorage
        let cartData = [];
        
        document.addEventListener('DOMContentLoaded', function() {
            loadCartData();
            updateOrderSummary();
            toggleTableSelection(); // Initialize table selection
            
            // Add event listener for cash amount input
            document.getElementById('cashAmountInput').addEventListener('input', function() {
                // Remove selected class from amount buttons
                document.querySelectorAll('.amount-btn').forEach(btn => {
                    btn.classList.remove('selected');
                });
                
                updateCashDisplay();
            });
            
            // Add event listener for confirm cash payment button
            document.getElementById('confirmCashPayment').addEventListener('click', confirmCashPayment);
        });
        
        function loadCartData() {
            const storedCart = sessionStorage.getItem('posCart');
            if (storedCart) {
                cartData = JSON.parse(storedCart);
                document.getElementById('cartData').value = storedCart;
            } else {
                // Redirect back if no cart data
                alert('ไม่พบข้อมูลตะกร้าสินค้า กรุณาเลือกสินค้าใหม่');
                window.location.href = 'new_order.php';
            }
        }
        
        function updateOrderSummary() {
            const orderItems = document.getElementById('orderItems');
            const orderSummary = document.getElementById('orderSummary');
            const submitBtn = document.getElementById('submitBtn');
            
            if (cartData.length === 0) {
                orderItems.innerHTML = `
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-shopping-basket fa-2x mb-2"></i>
                        <p>ไม่มีสินค้าในออเดอร์</p>
                    </div>
                `;
                orderSummary.style.display = 'none';
                submitBtn.disabled = true;
                return;
            }
            
            let itemsHTML = '';
            let totalItems = 0;
            let totalPrice = 0;
            
            cartData.forEach(item => {
                const subtotal = item.price * item.quantity;
                totalItems += item.quantity;
                totalPrice += subtotal;
                
                itemsHTML += `
                    <div class="order-item">
                        <div class="item-info">
                            <div class="item-name">${item.name}</div>
                            <div class="item-details">฿${item.price.toFixed(2)} x ${item.quantity}</div>
                        </div>
                        <div class="item-total">฿${subtotal.toFixed(2)}</div>
                    </div>
                `;
            });
            
            orderItems.innerHTML = itemsHTML;
            orderSummary.style.display = 'block';
            
            document.getElementById('totalItems').textContent = totalItems;
            document.getElementById('totalPrice').textContent = '฿' + totalPrice.toFixed(2);
            
            submitBtn.disabled = false;
        }
        
        function selectPayment(method) {
            // Remove selected class from all methods
            document.querySelectorAll('.payment-method').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Add selected class to clicked method
            event.currentTarget.classList.add('selected');
            
            // Check the radio button
            document.querySelector(`input[name="payment_method"][value="${method}"]`).checked = true;
        }
        
        // Select table function
        function selectTable(tableNumber) {
            // Remove selected class from all tables
            document.querySelectorAll('.table-option').forEach(el => {
                el.classList.remove('selected');
            });
            
            // Add selected class to clicked table
            event.currentTarget.classList.add('selected');
            
            // Set hidden input value
            document.getElementById('selectedTable').value = tableNumber;
        }
        
        // Toggle table selection based on order type
        function toggleTableSelection() {
            const orderType = document.getElementById('orderType').value;
            const tableGroup = document.getElementById('tableGroup');
            
            if (orderType === 'dine_in') {
                tableGroup.style.display = 'block';
            } else {
                tableGroup.style.display = 'none';
                // Clear table selection
                document.querySelectorAll('.table-option').forEach(el => {
                    el.classList.remove('selected');
                });
                document.getElementById('selectedTable').value = '';
            }
        }
        
        // Get total price from order summary
        function getTotalPrice() {
            let total = 0;
            cartData.forEach(item => {
                total += item.price * item.quantity;
            });
            return total;
        }
        
        // Show cash payment modal
        function showCashPaymentModal() {
            const totalPrice = getTotalPrice();
            document.getElementById('totalAmountDisplay').textContent = '฿' + totalPrice.toFixed(2);
            
            // Generate quick amount buttons
            generateQuickAmountButtons(totalPrice);
            
            // Reset modal state
            resetCashModal();
            
            // Show modal
            const modal = new bootstrap.Modal(document.getElementById('cashPaymentModal'));
            modal.show();
        }
        
        // Generate quick amount buttons
        function generateQuickAmountButtons(totalPrice) {
            const container = document.getElementById('quickAmountButtons');
            container.innerHTML = '';
            
            // Common denominations
            const amounts = [20, 50, 100, 500, 1000];
            
            // Add exact amount button
            amounts.unshift(Math.ceil(totalPrice));
            
            // Add amounts that are convenient for change
            const convenientAmounts = [
                Math.ceil(totalPrice / 10) * 10, // Round up to nearest 10
                Math.ceil(totalPrice / 50) * 50, // Round up to nearest 50
                Math.ceil(totalPrice / 100) * 100 // Round up to nearest 100
            ];
            
            convenientAmounts.forEach(amount => {
                if (amount > totalPrice && !amounts.includes(amount)) {
                    amounts.push(amount);
                }
            });
            
            // Sort and remove duplicates
            const uniqueAmounts = [...new Set(amounts)].sort((a, b) => a - b);
            
            uniqueAmounts.forEach(amount => {
                if (amount >= totalPrice) {
                    const col = document.createElement('div');
                    col.className = 'col-6 col-md-4 mb-2';
                    
                    const button = document.createElement('div');
                    button.className = 'amount-btn';
                    button.textContent = '฿' + amount;
                    button.onclick = () => selectQuickAmount(amount);
                    button.dataset.amount = amount;
                    
                    col.appendChild(button);
                    container.appendChild(col);
                }
            });
        }
        
        // Select quick amount
        function selectQuickAmount(amount) {
            // Remove selected class from all amount buttons
            document.querySelectorAll('.amount-btn').forEach(btn => {
                btn.classList.remove('selected');
            });
            
            // Add selected class to clicked button
            event.currentTarget.classList.add('selected');
            
            // Update input field
            document.getElementById('cashAmountInput').value = amount;
            
            // Update displays
            updateCashDisplay(amount);
        }
        
        // Update cash display
        function updateCashDisplay(receivedAmount = null) {
            const totalPrice = getTotalPrice();
            const amount = receivedAmount || parseFloat(document.getElementById('cashAmountInput').value) || 0;
            
            // Update received display
            document.getElementById('receivedDisplay').textContent = '฿' + amount.toFixed(2);
            
            // Calculate and update change
            const change = amount - totalPrice;
            const changeDisplay = document.getElementById('changeDisplay');
            const statusMessage = document.getElementById('statusMessage');
            const confirmButton = document.getElementById('confirmCashPayment');
            
            if (amount === 0) {
                changeDisplay.textContent = '฿0.00';
                changeDisplay.className = 'amount-display change';
                statusMessage.textContent = 'กรุณาเลือกจำนวนเงินที่รับมา';
                statusMessage.className = 'status-message';
                confirmButton.disabled = true;
            } else if (change >= 0) {
                changeDisplay.textContent = '฿' + change.toFixed(2);
                changeDisplay.className = 'amount-display change';
                statusMessage.textContent = change === 0 ? 'จำนวนเงินพอดี' : 'ทอนเงิน ฿' + change.toFixed(2);
                statusMessage.className = 'status-message success';
                confirmButton.disabled = false;
            } else {
                const shortage = Math.abs(change);
                changeDisplay.textContent = 'ขาด ฿' + shortage.toFixed(2);
                changeDisplay.className = 'amount-display change negative';
                statusMessage.textContent = 'เงินไม่เพียงพอ ขาดอีก ฿' + shortage.toFixed(2);
                statusMessage.className = 'status-message error';
                confirmButton.disabled = true;
            }
        }
        
        // Reset cash modal
        function resetCashModal() {
            document.getElementById('cashAmountInput').value = '';
            document.getElementById('receivedDisplay').textContent = '฿0.00';
            document.getElementById('changeDisplay').textContent = '฿0.00';
            document.getElementById('changeDisplay').className = 'amount-display change';
            document.getElementById('statusMessage').textContent = 'กรุณาเลือกจำนวนเงินที่รับมา';
            document.getElementById('statusMessage').className = 'status-message';
            document.getElementById('confirmCashPayment').disabled = true;
            
            // Remove selected class from amount buttons
            document.querySelectorAll('.amount-btn').forEach(btn => {
                btn.classList.remove('selected');
            });
        }
        
        // Confirm cash payment
        function confirmCashPayment() {
            const receivedAmount = parseFloat(document.getElementById('cashAmountInput').value);
            const totalPrice = getTotalPrice();
            const change = receivedAmount - totalPrice;
            
            // Set hidden form values
            document.getElementById('cashReceivedHidden').value = receivedAmount.toFixed(2);
            document.getElementById('cashChangeHidden').value = change.toFixed(2);
            
            // Close modal
            bootstrap.Modal.getInstance(document.getElementById('cashPaymentModal')).hide();
            
            // Submit form
            document.getElementById('paymentForm').submit();
        }
        
        // Form submission
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            e.preventDefault(); // Always prevent default submission
            
            if (cartData.length === 0) {
                alert('ไม่มีสินค้าในตะกร้า');
                return false;
            }
            
            // Check table selection for dine-in orders
            const orderType = document.getElementById('orderType').value;
            const selectedTable = document.getElementById('selectedTable').value;
            
            if (orderType === 'dine_in' && !selectedTable) {
                alert('กรุณาเลือกหมายเลขโต๊ะสำหรับการทานที่ร้าน');
                return false;
            }
            
            // Check payment method
            const paymentMethod = document.querySelector('input[name="payment_method"]:checked').value;
            
            if (paymentMethod === 'cash') {
                // Show cash payment modal
                showCashPaymentModal();
            } else {
                // For other payment methods, submit directly
                this.submit();
            }
        });
        
        console.log('Payment page loaded successfully');
        console.log('Cart data loaded:', cartData.length, 'items');
    </script>
</body>
</html>