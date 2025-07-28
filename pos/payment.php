<?php
/**
 * ระบบชำระเงิน - POS
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
                $stmt = $conn->prepare("
                    INSERT INTO payments (order_id, amount, payment_method, payment_date, status)
                    VALUES (?, ?, ?, NOW(), 'completed')
                ");
                $stmt->execute([$orderId, $totalPrice, $paymentMethod]);
                
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
    $prefix = 'Q';
    $date = date('ymd');
    $time = date('Hi');
    $random = str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT);
    return $prefix . $date . $time . $random;
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
                                    <select class="form-select" name="order_type" required>
                                        <option value="dine_in">ทานที่ร้าน</option>
                                        <option value="takeaway">ซื้อกลับ</option>
                                        <option value="delivery">จัดส่ง</option>
                                    </select>
                                </div>
                            </div>
                            
                            <div class="col-md-6">
                                <div class="form-group">
                                    <label class="form-label">หมายเลขโต๊ะ (ไม่บังคับ)</label>
                                    <input type="text" class="form-control" name="table_number" 
                                           placeholder="เช่น A1, B2">
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
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Load cart data from sessionStorage
        let cartData = [];
        
        document.addEventListener('DOMContentLoaded', function() {
            loadCartData();
            updateOrderSummary();
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
        
        // Form submission
        document.getElementById('paymentForm').addEventListener('submit', function(e) {
            if (cartData.length === 0) {
                e.preventDefault();
                alert('ไม่มีสินค้าในตะกร้า');
                return false;
            }
            
            // Show loading
            const submitBtn = document.getElementById('submitBtn');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังดำเนินการ...';
            
            return true;
        });
        
        console.log('Payment page loaded successfully');
    </script>
</body>
</html>