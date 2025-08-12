<?php
/**
 * หน้าชำระเงิน
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

$pageTitle = 'ชำระเงิน';
$pageDescription = 'ชำระเงินผ่าน PromptPay QR Code';

// รับ parameters
$orderId = intval($_GET['order_id'] ?? 0);
$amount = floatval($_GET['amount'] ?? 0);

if (!$orderId || !$amount) {
    header('Location: cart.php');
    exit();
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงข้อมูลออเดอร์
    $stmt = $conn->prepare("
        SELECT o.*, 
               COUNT(oi.item_id) as item_count,
               GROUP_CONCAT(CONCAT(oi.product_name, ' x', oi.quantity) SEPARATOR ', ') as items_summary
        FROM orders o
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        WHERE o.order_id = ? AND o.payment_status = 'pending'
        GROUP BY o.order_id
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('Location: cart.php');
        exit();
    }
    
    // ดึงรายการสินค้าในออเดอร์
    $stmt = $conn->prepare("
        SELECT product_name, quantity, unit_price, total_price, options
        FROM order_items 
        WHERE order_id = ?
        ORDER BY item_id ASC
    ");
    $stmt->execute([$orderId]);
    $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    writeLog("Checkout page error: " . $e->getMessage());
    header('Location: cart.php');
    exit();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo SITE_NAME; ?></title>
    <meta name="description" content="<?php echo $pageDescription; ?>">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- Sweet Alert 2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    <!-- Animate.css -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4f46e5;
            --secondary-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --light-bg: #f8fafc;
            --white: #ffffff;
            --border-color: #e5e7eb;
            --text-color: #1f2937;
            --text-muted: #6b7280;
            --border-radius: 16px;
            --box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
        }
        
        .checkout-container {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }
        
        .checkout-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            margin-bottom: 2rem;
        }
        
        .checkout-header {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            padding: 2rem;
            text-align: center;
        }
        
        .order-summary {
            padding: 2rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .order-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1rem;
            margin-bottom: 1.5rem;
        }
        
        .info-item {
            background: var(--light-bg);
            padding: 1rem;
            border-radius: 12px;
            text-align: center;
        }
        
        .info-label {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 0.25rem;
        }
        
        .info-value {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--text-color);
        }
        
        .order-items {
            margin-top: 1.5rem;
        }
        
        .item-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 0;
            border-bottom: 1px solid var(--border-color);
        }
        
        .item-row:last-child {
            border-bottom: none;
        }
        
        .item-details {
            flex: 1;
        }
        
        .item-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .item-options {
            font-size: 0.875rem;
            color: var(--text-muted);
        }
        
        .item-price {
            text-align: right;
            font-weight: 600;
        }
        
        .payment-section {
            padding: 2rem;
        }
        
        .qr-container {
            background: var(--light-bg);
            border: 3px dashed var(--border-color);
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
            margin-bottom: 2rem;
            transition: var(--transition);
        }
        
        .qr-container.loaded {
            border-color: var(--secondary-color);
            background: #f0fdfa;
        }
        
        .qr-code-image {
            max-width: 250px;
            border-radius: 10px;
            box-shadow: var(--box-shadow);
        }
        
        .amount-display {
            background: linear-gradient(135deg, var(--secondary-color), #059669);
            color: white;
            padding: 1.5rem;
            border-radius: 15px;
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .status-indicator {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1rem;
            border-radius: 10px;
            margin-bottom: 1rem;
            font-weight: 500;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #d97706;
            border: 2px solid #fbbf24;
        }
        
        .status-completed {
            background: #dcfce7;
            color: #16a34a;
            border: 2px solid #22c55e;
        }
        
        .status-failed {
            background: #fee2e2;
            color: #dc2626;
            border: 2px solid #ef4444;
        }
        
        .countdown-timer {
            font-size: 1.2rem;
            font-weight: bold;
            color: var(--primary-color);
        }
        
        .slip-upload {
            background: var(--light-bg);
            border: 2px dashed #cbd5e1;
            border-radius: 15px;
            padding: 2rem;
            text-align: center;
            margin-top: 2rem;
            transition: var(--transition);
        }
        
        .slip-upload:hover {
            border-color: var(--primary-color);
            background: #f1f5f9;
        }
        
        .slip-upload.dragover {
            border-color: var(--secondary-color);
            background: #f0fdfa;
        }
        
        .btn-custom {
            border-radius: 10px;
            padding: 12px 24px;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .pulse {
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0% { opacity: 1; }
            50% { opacity: 0.5; }
            100% { opacity: 1; }
        }
        
        .success-animation {
            animation: successBounce 0.6s ease-out;
        }
        
        @keyframes successBounce {
            0% { transform: scale(0.3); opacity: 0; }
            50% { transform: scale(1.05); }
            70% { transform: scale(0.9); }
            100% { transform: scale(1); opacity: 1; }
        }
        
        .loading-spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--border-color);
            border-top: 4px solid var(--primary-color);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }
        
        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }
        
        @media (max-width: 768px) {
            .checkout-container {
                padding: 1rem;
            }
            
            .checkout-header, .order-summary, .payment-section {
                padding: 1.5rem;
            }
            
            .order-info {
                grid-template-columns: 1fr;
            }
            
            .qr-code-image {
                max-width: 200px;
            }
            
            .item-row {
                flex-direction: column;
                align-items: flex-start;
                gap: 0.5rem;
            }
            
            .item-price {
                text-align: left;
            }
        }
    </style>
</head>
<body>
    <div class="checkout-container">
        <!-- Navigation -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <a href="cart.php" class="btn btn-outline-light btn-custom">
                <i class="fas fa-arrow-left me-2"></i>
                กลับไปตะกร้า
            </a>
            
            <div class="text-center">
                <h5 class="text-white mb-0">ออเดอร์ #<?php echo clean($order['order_number']); ?></h5>
                <small class="text-white-50">สร้างเมื่อ <?php echo formatDateTime($order['created_at']); ?></small>
            </div>
            
            <a href="queue_status.php" class="btn btn-outline-light btn-custom">
                <i class="fas fa-clock me-2"></i>
                ตรวจสอบคิว
            </a>
        </div>
        
        <!-- Main Checkout Card -->
        <div class="checkout-card animate__animated animate__fadeInUp">
            <!-- Header -->
            <div class="checkout-header">
                <h2 class="mb-2">
                    <i class="fas fa-qrcode me-2"></i>
                    ชำระเงินผ่าน PromptPay
                </h2>
                <p class="mb-0 opacity-75">สแกน QR Code หรืออัปโหลดสลิปเพื่อชำระเงิน</p>
            </div>
            
            <!-- Order Summary -->
            <div class="order-summary">
                <h4 class="mb-3">
                    <i class="fas fa-receipt me-2"></i>
                    สรุปออเดอร์
                </h4>
                
                <!-- Order Info -->
                <div class="order-info">
                    <div class="info-item">
                        <div class="info-label">เลขออเดอร์</div>
                        <div class="info-value"><?php echo clean($order['order_number']); ?></div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">จำนวนรายการ</div>
                        <div class="info-value"><?php echo $order['item_count']; ?> รายการ</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">เวลาเตรียม</div>
                        <div class="info-value">~<?php echo $order['estimated_prep_time']; ?> นาที</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">ลูกค้า</div>
                        <div class="info-value"><?php echo clean($order['customer_name']); ?></div>
                    </div>
                </div>
                
                <!-- Order Items -->
                <div class="order-items">
                    <h5 class="mb-3">รายการสินค้า</h5>
                    <?php foreach ($orderItems as $item): ?>
                        <div class="item-row">
                            <div class="item-details">
                                <div class="item-name"><?php echo clean($item['product_name']); ?></div>
                                <?php if ($item['options']): ?>
                                    <div class="item-options">
                                        <i class="fas fa-plus me-1"></i>
                                        <?php echo clean($item['options']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                            <div class="item-price">
                                <div>จำนวน: <?php echo $item['quantity']; ?></div>
                                <div class="text-primary fw-bold"><?php echo formatCurrency($item['total_price']); ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    
                    <!-- Order Totals -->
                    <div class="mt-3 pt-3 border-top">
                        <div class="d-flex justify-content-between mb-2">
                            <span>ราคาสินค้า:</span>
                            <span><?php echo formatCurrency($order['subtotal']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between mb-2">
                            <span>ภาษีมูลค่าเพิ่ม (7%):</span>
                            <span><?php echo formatCurrency($order['tax_amount']); ?></span>
                        </div>
                        <div class="d-flex justify-content-between fw-bold text-primary h5">
                            <span>ยอดรวมทั้งสิ้น:</span>
                            <span><?php echo formatCurrency($order['total_amount']); ?></span>
                        </div>
                    </div>
                </div>
            </div>
            
            <!-- Payment Section -->
            <div class="payment-section">
                <!-- Amount Display -->
                <div class="amount-display">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <h4 class="mb-1">จำนวนเงินที่ต้องชำระ</h4>
                            <h2 class="mb-0" id="amount-display"><?php echo formatCurrency($order['total_amount']); ?></h2>
                        </div>
                        <div>
                            <i class="fas fa-money-bill-wave fa-2x opacity-75"></i>
                        </div>
                    </div>
                </div>
                
                <!-- Status Indicator -->
                <div id="status-indicator" class="status-indicator status-pending">
                    <i class="fas fa-clock me-2"></i>
                    <span id="status-text">รอการชำระเงิน...</span>
                </div>
                
                <!-- QR Code Section -->
                <div id="qr-container" class="qr-container">
                    <div id="qr-loading" class="text-center">
                        <div class="loading-spinner mb-3"></div>
                        <p class="text-muted">กำลังสร้าง QR Code...</p>
                    </div>
                    
                    <div id="qr-content" class="d-none">
                        <img id="qr-image" src="" alt="PromptPay QR Code" class="qr-code-image img-fluid mb-3">
                        <h5 class="text-primary mb-2">สแกน QR Code เพื่อชำระเงิน</h5>
                        <p class="text-muted mb-0">เปิดแอปธนาคารและสแกนรหัส QR นี้</p>
                    </div>
                    
                    <div id="qr-success" class="d-none success-animation">
                        <i class="fas fa-check-circle text-success fa-4x mb-3"></i>
                        <h4 class="text-success mb-2">ชำระเงินสำเร็จ!</h4>
                        <p class="text-muted">ขอบคุณที่ใช้บริการ กำลังเตรียมอาหารให้คุณ</p>
                    </div>
                </div>
                
                <!-- Countdown Timer -->
                <div class="progress-container">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="text-muted">เวลาที่เหลือ</span>
                        <span class="countdown-timer" id="countdown-timer">5:00</span>
                    </div>
                    <div class="progress" style="height: 8px;">
                        <div id="countdown-progress" class="progress-bar bg-primary progress-bar-striped progress-bar-animated" 
                             role="progressbar" style="width: 100%"></div>
                    </div>
                </div>
                
                <!-- Action Buttons -->
                <div class="d-grid gap-2 mb-3">
                    <button type="button" class="btn btn-outline-primary btn-custom" id="refresh-qr" onclick="refreshQRCode()">
                        <i class="fas fa-sync-alt me-2"></i>สร้าง QR Code ใหม่
                    </button>
                    
                    <button type="button" class="btn btn-success btn-custom" id="check-status" onclick="checkPaymentStatus()">
                        <i class="fas fa-search me-2"></i>ตรวจสอบสถานะ
                    </button>
                </div>
                
                <!-- Slip Upload Section -->
                <div class="slip-upload" id="slip-upload">
                    <i class="fas fa-cloud-upload-alt fa-2x text-muted mb-3"></i>
                    <h5 class="mb-2">อัปโหลดสลิปโอนเงิน</h5>
                    <p class="text-muted mb-3">ลากไฟล์มาวางที่นี่ หรือคลิกเพื่อเลือกไฟล์</p>
                    
                    <input type="file" id="slip-file" accept="image/*" class="d-none">
                    <button type="button" class="btn btn-outline-secondary btn-custom" onclick="document.getElementById('slip-file').click()">
                        <i class="fas fa-image me-2"></i>เลือกรูปสลิป
                    </button>
                    
                    <div id="slip-preview" class="mt-3 d-none">
                        <img id="slip-image" src="" alt="Slip Preview" class="img-fluid" style="max-width: 300px; border-radius: 10px;">
                        <div class="mt-3">
                            <button type="button" class="btn btn-primary btn-custom" onclick="verifySlip()">
                                <i class="fas fa-check me-2"></i>ตรวจสอบสลิป
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-custom ms-2" onclick="clearSlip()">
                                <i class="fas fa-times me-2"></i>ยกเลิก
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Footer -->
                <div class="text-center mt-4">
                    <small class="text-muted">
                        <i class="fas fa-shield-alt me-1"></i>
                        การชำระเงินปลอดภัยด้วยระบบ PromptPay
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Global variables
        let paymentId = null;
        let orderId = <?php echo $orderId; ?>;
        let amount = <?php echo $order['total_amount']; ?>;
        let countdownTimer = null;
        let statusCheckInterval = null;
        let timeRemaining = 300; // 5 minutes
        
        // Initialize payment
        function initializePayment() {
            createPayment();
        }
        
        // Create payment
        function createPayment() {
            $.ajax({
                url: 'api/create_payment.php',
                type: 'POST',
                data: {
                    order_id: orderId,
                    amount: amount,
                    description: `Order #<?php echo $order['order_number']; ?>`
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        paymentId = response.payment_id;
                        timeRemaining = response.expires_in || 300;
                        
                        displayQRCode(response.qr_code_url);
                        startCountdown();
                        startStatusCheck();
                    } else {
                        showError('ไม่สามารถสร้างการชำระเงินได้: ' + response.message);
                    }
                },
                error: function() {
                    showError('เกิดข้อผิดพลาดในการเชื่อมต่อ');
                }
            });
        }
        
        // Display QR Code
        function displayQRCode(qrUrl) {
            $('#qr-loading').addClass('d-none');
            $('#qr-image').attr('src', qrUrl);
            $('#qr-content').removeClass('d-none');
            $('#qr-container').addClass('loaded');
        }
        
        // Start countdown timer
        function startCountdown() {
            updateCountdownDisplay();
            
            countdownTimer = setInterval(function() {
                timeRemaining--;
                updateCountdownDisplay();
                
                if (timeRemaining <= 0) {
                    clearInterval(countdownTimer);
                    handlePaymentExpired();
                }
            }, 1000);
        }
        
        // Update countdown display
        function updateCountdownDisplay() {
            const minutes = Math.floor(timeRemaining / 60);
            const seconds = timeRemaining % 60;
            const timeString = `${minutes}:${seconds.toString().padStart(2, '0')}`;
            
            $('#countdown-timer').text(timeString);
            
            const percentage = (timeRemaining / 300) * 100;
            $('#countdown-progress').css('width', percentage + '%');
            
            // Change color when time is running out
            if (timeRemaining < 60) {
                $('#countdown-progress').removeClass('bg-primary').addClass('bg-danger');
                $('#countdown-timer').addClass('text-danger');
            } else if (timeRemaining < 180) {
                $('#countdown-progress').removeClass('bg-primary').addClass('bg-warning');
                $('#countdown-timer').addClass('text-warning');
            }
        }
        
        // Start status checking
        function startStatusCheck() {
            statusCheckInterval = setInterval(function() {
                checkPaymentStatus(false); // silent check
            }, 3000);
        }
        
        // Check payment status
        function checkPaymentStatus(showLoading = true) {
            if (!paymentId) return;
            
            if (showLoading) {
                $('#check-status').prop('disabled', true).html('<i class="fas fa-spinner fa-spin me-2"></i>กำลังตรวจสอบ...');
            }
            
            $.ajax({
                url: 'api/check_payment_status.php',
                type: 'GET',
                data: { payment_id: paymentId },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        if (response.status === 'completed') {
                            handlePaymentSuccess();
                        } else if (response.status === 'failed') {
                            handlePaymentFailed();
                        } else if (response.is_expired) {
                            handlePaymentExpired();
                        }
                        
                        if (showLoading) {
                            $('#check-status').prop('disabled', false).html('<i class="fas fa-search me-2"></i>ตรวจสอบสถานะ');
                        }
                    }
                },
                error: function() {
                    if (showLoading) {
                        $('#check-status').prop('disabled', false).html('<i class="fas fa-search me-2"></i>ตรวจสอบสถานะ');
                        showError('ไม่สามารถตรวจสอบสถานะได้');
                    }
                }
            });
        }
        
        // Handle payment success
        function handlePaymentSuccess() {
            clearInterval(countdownTimer);
            clearInterval(statusCheckInterval);
            
            $('#qr-content').addClass('d-none');
            $('#qr-success').removeClass('d-none');
            
            $('#status-indicator').removeClass('status-pending').addClass('status-completed');
            $('#status-text').html('<i class="fas fa-check me-2"></i>ชำระเงินสำเร็จ!');
            
            // Play success sound (if available)
            playSuccessSound();
            
            // Show success message
            Swal.fire({
                icon: 'success',
                title: 'ชำระเงินสำเร็จ!',
                text: 'ขอบคุณที่ใช้บริการ กำลังเตรียมอาหารให้คุณ',
                showConfirmButton: false,
                timer: 3000
            });
            
            // Redirect after delay
            setTimeout(function() {
                window.location.href = `order_success.php?order_id=${orderId}`;
            }, 3000);
        }
        
        // Handle payment failed
        function handlePaymentFailed() {
            clearInterval(countdownTimer);
            clearInterval(statusCheckInterval);
            
            $('#status-indicator').removeClass('status-pending').addClass('status-failed');
            $('#status-text').html('<i class="fas fa-times me-2"></i>การชำระเงินไม่สำเร็จ');
            
            Swal.fire({
                icon: 'error',
                title: 'การชำระเงินไม่สำเร็จ',
                text: 'กรุณาลองใหม่อีกครั้งหรือติดต่อเจ้าหน้าที่',
                confirmButtonText: 'ลองใหม่',
                showCancelButton: true,
                cancelButtonText: 'ติดต่อเจ้าหน้าที่'
            }).then((result) => {
                if (result.isConfirmed) {
                    location.reload();
                }
            });
        }
        
        // Handle payment expired
        function handlePaymentExpired() {
            clearInterval(countdownTimer);
            clearInterval(statusCheckInterval);
            
            $('#status-indicator').removeClass('status-pending').addClass('status-failed');
            $('#status-text').html('<i class="fas fa-clock me-2"></i>หมดเวลาชำระเงิน');
            
            $('#qr-container').removeClass('loaded').addClass('opacity-50');
            
            Swal.fire({
                icon: 'warning',
                title: 'หมดเวลาชำระเงิน',
                text: 'QR Code หมดอายุแล้ว กรุณาสร้างใหม่',
                confirmButtonText: 'สร้าง QR Code ใหม่'
            }).then(() => {
                refreshQRCode();
            });
        }
        
        // Refresh QR Code
        function refreshQRCode() {
            // Reset states
            clearInterval(countdownTimer);
            clearInterval(statusCheckInterval);
            
            $('#qr-content').addClass('d-none');
            $('#qr-success').addClass('d-none');
            $('#qr-loading').removeClass('d-none');
            $('#qr-container').removeClass('loaded opacity-50');
            
            $('#status-indicator').removeClass('status-completed status-failed').addClass('status-pending');
            $('#status-text').html('<i class="fas fa-clock me-2"></i>รอการชำระเงิน...');
            
            $('#countdown-progress').removeClass('bg-warning bg-danger').addClass('bg-primary');
            $('#countdown-timer').removeClass('text-warning text-danger');
            
            // Create new payment
            createPayment();
        }
        
        // Slip upload functionality
        $('#slip-file').on('change', function(e) {
            const file = e.target.files[0];
            if (file) {
                previewSlip(file);
            }
        });
        
        // Drag and drop functionality
        $('#slip-upload').on('dragover', function(e) {
            e.preventDefault();
            $(this).addClass('dragover');
        });
        
        $('#slip-upload').on('dragleave', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');
        });
        
        $('#slip-upload').on('drop', function(e) {
            e.preventDefault();
            $(this).removeClass('dragover');
            
            const files = e.originalEvent.dataTransfer.files;
            if (files.length > 0) {
                previewSlip(files[0]);
            }
        });
        
        // Preview slip image
        function previewSlip(file) {
            if (!file.type.startsWith('image/')) {
                showError('กรุณาเลือกไฟล์รูปภาพ');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                $('#slip-image').attr('src', e.target.result);
                $('#slip-preview').removeClass('d-none');
            };
            reader.readAsDataURL(file);
        }
        
        // Clear slip preview
        function clearSlip() {
            $('#slip-file').val('');
            $('#slip-preview').addClass('d-none');
        }
        
        // Verify slip
        function verifySlip() {
            const file = document.getElementById('slip-file').files[0];
            if (!file) {
                showError('กรุณาเลือกไฟล์สลิป');
                return;
            }
            
            const reader = new FileReader();
            reader.onload = function(e) {
                const base64 = e.target.result.split(',')[1];
                
                // Show loading
                Swal.fire({
                    title: 'กำลังตรวจสอบสลิป...',
                    allowOutsideClick: false,
                    didOpen: () => {
                        Swal.showLoading();
                    }
                });
                
                $.ajax({
                    url: 'api/verify_slip.php',
                    type: 'POST',
                    data: {
                        payment_id: paymentId,
                        slip_image: base64
                    },
                    dataType: 'json',
                    success: function(response) {
                        Swal.close();
                        
                        if (response.success && response.verified) {
                            handlePaymentSuccess();
                        } else {
                            Swal.fire({
                                icon: 'error',
                                title: 'สลิปไม่ถูกต้อง',
                                text: response.message || 'กรุณาตรวจสอบจำนวนเงินและความชัดเจนของสลิป'
                            });
                        }
                    },
                    error: function() {
                        Swal.close();
                        showError('เกิดข้อผิดพลาดในการตรวจสอบสลิป');
                    }
                });
            };
            reader.readAsDataURL(file);
        }
        
        // Utility functions
        function showError(message) {
            Swal.fire({
                icon: 'error',
                title: 'เกิดข้อผิดพลาด',
                text: message
            });
        }
        
        function playSuccessSound() {
            // Create audio element for success sound
            const audio = new Audio('data:audio/wav;base64,UklGRnoGAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YQoGAACBhYqFbF1fdJivrJBhNjVgodDbq2EcBj+a2/LDciUFLIHO8tiJNwgZaLvt559NEAxQp+PwtmMcBjiR1/LMeSwFJHfH8N2QQAoUXrTp66hVFApGn+DyvmUaBDKN0fPQgC4HJXbD8d2SSgyQ');
            audio.volume = 0.3;
            audio.play().catch(() => {
                // Ignore audio play errors
            });
        }
        
        // Initialize when page loads
        $(document).ready(function() {
            initializePayment();
        });
        
        // Cleanup intervals when page unloads
        $(window).on('beforeunload', function() {
            clearInterval(countdownTimer);
            clearInterval(statusCheckInterval);
        });
    </script>
</body>
</html>