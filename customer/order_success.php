<?php
/**
 * หน้าแสดงการชำระเงินสำเร็จ
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

$pageTitle = 'ชำระเงินสำเร็จ';
$pageDescription = 'ขอบคุณสำหรับการสั่งซื้อ';

// รับ order_id
$orderId = intval($_GET['order_id'] ?? 0);

if (!$orderId) {
    header('Location: index.php');
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
        WHERE o.order_id = ? AND o.payment_status = 'completed'
        GROUP BY o.order_id
    ");
    $stmt->execute([$orderId]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$order) {
        header('Location: index.php');
        exit();
    }
    
    // ดึงข้อมูลการชำระเงิน
    $stmt = $conn->prepare("
        SELECT * FROM payments 
        WHERE order_id = ? AND status = 'completed'
        ORDER BY processed_at DESC 
        LIMIT 1
    ");
    $stmt->execute([$orderId]);
    $payment = $stmt->fetch(PDO::FETCH_ASSOC);
    
} catch (Exception $e) {
    writeLog("Order success page error: " . $e->getMessage());
    header('Location: index.php');
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
    <!-- Animate.css -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    
    <style>
        :root {
            --success-color: #10b981;
            --primary-color: #4f46e5;
            --light-bg: #f8fafc;
            --white: #ffffff;
            --text-color: #1f2937;
            --text-muted: #6b7280;
            --border-radius: 16px;
            --box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        
        .success-container {
            max-width: 600px;
            width: 100%;
        }
        
        .success-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            text-align: center;
            position: relative;
        }
        
        .success-header {
            background: linear-gradient(135deg, var(--success-color), #059669);
            color: white;
            padding: 3rem 2rem;
            position: relative;
        }
        
        .success-icon {
            width: 80px;
            height: 80px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 1.5rem;
            animation: successPulse 2s infinite;
        }
        
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
        
        .success-body {
            padding: 2rem;
        }
        
        .order-info {
            background: var(--light-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 0.75rem;
        }
        
        .info-row:last-child {
            margin-bottom: 0;
            padding-top: 0.75rem;
            border-top: 2px solid #e5e7eb;
            font-weight: bold;
            color: var(--success-color);
        }
        
        .btn-custom {
            border-radius: 12px;
            padding: 16px 24px;
            font-weight: 600;
            transition: var(--transition);
            margin: 0.5rem;
        }
        
        .btn-custom:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .estimated-time {
            background: linear-gradient(135deg, #fef3c7, #fbbf24);
            color: #92400e;
            border-radius: 12px;
            padding: 1rem;
            margin: 1.5rem 0;
            border-left: 4px solid #f59e0b;
        }
        
        .confetti {
            position: absolute;
            width: 10px;
            height: 10px;
            background: #ff6b6b;
            border-radius: 50%;
        }
        
        @keyframes fall {
            to {
                transform: translateY(100vh);
            }
        }
    </style>
</head>
<body>
    <div class="success-container">
        <div class="success-card animate__animated animate__bounceIn">
            <!-- Success Header -->
            <div class="success-header">
                <div class="success-icon">
                    <i class="fas fa-check fa-2x"></i>
                </div>
                <h1 class="mb-2">ชำระเงินสำเร็จ!</h1>
                <p class="mb-0 opacity-90">ขอบคุณสำหรับการสั่งซื้อ</p>
            </div>
            
            <!-- Success Body -->
            <div class="success-body">
                <div class="order-info">
                    <div class="info-row">
                        <span>เลขที่ออเดอร์:</span>
                        <strong><?php echo clean($order['order_number']); ?></strong>
                    </div>
                    <div class="info-row">
                        <span>จำนวนรายการ:</span>
                        <span><?php echo $order['item_count']; ?> รายการ</span>
                    </div>
                    <div class="info-row">
                        <span>วิธีการชำระเงิน:</span>
                        <span>
                            <?php 
                            if ($payment) {
                                switch($payment['payment_method']) {
                                    case 'qr_payment':
                                        echo '<i class="fas fa-qrcode me-1"></i>PromptPay QR';
                                        break;
                                    default:
                                        echo ucfirst($payment['payment_method']);
                                }
                            }
                            ?>
                        </span>
                    </div>
                    <?php if ($payment && $payment['processed_at']): ?>
                    <div class="info-row">
                        <span>เวลาชำระเงิน:</span>
                        <span><?php echo formatDateTime($payment['processed_at']); ?></span>
                    </div>
                    <?php endif; ?>
                    <div class="info-row">
                        <span>ยอดรวม:</span>
                        <span><?php echo formatCurrency($order['total_amount']); ?></span>
                    </div>
                </div>
                
                <!-- Estimated Time -->
                <div class="estimated-time">
                    <div class="d-flex align-items-center justify-content-center">
                        <i class="fas fa-clock fa-lg me-2"></i>
                        <div>
                            <h5 class="mb-1">เวลาเตรียมโดยประมาณ</h5>
                            <p class="mb-0"><?php echo $order['estimated_prep_time']; ?> นาที</p>
                        </div>
                    </div>
                </div>
                
                <!-- Order Items Summary -->
                <div class="mb-3">
                    <h6 class="text-muted mb-2">รายการที่สั่ง:</h6>
                    <p class="small"><?php echo clean($order['items_summary']); ?></p>
                </div>
                
                <!-- Action Buttons -->
                <div class="d-flex flex-column flex-sm-row justify-content-center">
                    <a href="queue_status.php?order_id=<?php echo $order['order_id']; ?>" 
                       class="btn btn-success btn-custom">
                        <i class="fas fa-clock me-2"></i>
                        ตรวจสอบสถานะคิว
                    </a>
                    
                    <a href="index.php" class="btn btn-primary btn-custom">
                        <i class="fas fa-home me-2"></i>
                        กลับหน้าหลัก
                    </a>
                </div>
                
                <div class="mt-3">
                    <a href="menu.php" class="btn btn-outline-secondary btn-custom">
                        <i class="fas fa-utensils me-2"></i>
                        สั่งเพิ่ม
                    </a>
                </div>
                
                <!-- Footer -->
                <div class="mt-4 pt-3 border-top text-center">
                    <small class="text-muted">
                        <i class="fas fa-heart text-danger me-1"></i>
                        ขอบคุณที่ใช้บริการ <?php echo SITE_NAME; ?>
                    </small>
                </div>
            </div>
        </div>
    </div>
    
    <script>
        // Create confetti effect
        function createConfetti() {
            const colors = ['#ff6b6b', '#4ecdc4', '#45b7d1', '#96ceb4', '#feca57'];
            const confettiCount = 50;
            
            for (let i = 0; i < confettiCount; i++) {
                const confetti = document.createElement('div');
                confetti.className = 'confetti';
                confetti.style.left = Math.random() * 100 + '%';
                confetti.style.backgroundColor = colors[Math.floor(Math.random() * colors.length)];
                confetti.style.animationDuration = Math.random() * 3 + 2 + 's';
                confetti.style.animationDelay = Math.random() * 2 + 's';
                confetti.style.animation = 'fall linear infinite';
                
                document.body.appendChild(confetti);
                
                // Remove confetti after animation
                setTimeout(() => {
                    confetti.remove();
                }, 5000);
            }
        }
        
        // Show confetti on load
        window.onload = function() {
            setTimeout(createConfetti, 500);
        };
    </script>
</body>
</html>