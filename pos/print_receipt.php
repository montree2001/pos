<?php
/**
 * พิมพ์ใบเสร็จ - POS
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

$pageTitle = 'ใบเสร็จ';
$orderId = $_GET['order_id'] ?? 0;
$order = null;
$orderItems = [];
$error = null;

if (!$orderId) {
    $error = 'ไม่พบหมายเลขออเดอร์';
} else {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // ดึงข้อมูลออเดอร์
        $stmt = $conn->prepare("
            SELECT o.*, p.payment_date, p.payment_method as payment_method_detail
            FROM orders o
            LEFT JOIN payments p ON o.order_id = p.order_id
            WHERE o.order_id = ?
        ");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch();
        
        if (!$order) {
            $error = 'ไม่พบออเดอร์';
        } else {
            // ดึงรายการสินค้า
            $stmt = $conn->prepare("
                SELECT oi.*, p.name as product_name
                FROM order_items oi
                JOIN products p ON oi.product_id = p.product_id
                WHERE oi.order_id = ?
            ");
            $stmt->execute([$orderId]);
            $orderItems = $stmt->fetchAll();
        }
        
    } catch (Exception $e) {
        writeLog("Print receipt error: " . $e->getMessage());
        $error = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
    }
}

// ล้างตะกร้าจาก sessionStorage (ถ้ามาจากหน้าชำระเงิน)
$clearCart = isset($_GET['clear_cart']) ? true : false;
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
            --pos-light: #f8fafc;
            --pos-white: #ffffff;
            --pos-shadow: 0 4px 20px rgba(0,0,0,0.1);
            --pos-border-radius: 16px;
        }
        
        body {
            background: var(--pos-light);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .pos-container {
            padding: 15px;
            max-width: 800px;
            margin: 0 auto;
        }
        
        .pos-header {
            background: linear-gradient(135deg, var(--pos-success), #059669);
            color: white;
            border-radius: var(--pos-border-radius);
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: var(--pos-shadow);
            text-align: center;
        }
        
        .receipt-container {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            box-shadow: var(--pos-shadow);
            padding: 30px;
            margin-bottom: 20px;
        }
        
        .receipt-header {
            text-align: center;
            border-bottom: 2px solid #e5e7eb;
            padding-bottom: 20px;
            margin-bottom: 20px;
        }
        
        .receipt-logo {
            font-size: 2rem;
            color: var(--pos-primary);
            margin-bottom: 10px;
        }
        
        .shop-name {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 5px;
        }
        
        .shop-info {
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .receipt-details {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
            padding: 15px;
            background: #f8fafc;
            border-radius: 8px;
        }
        
        .detail-group h6 {
            color: #374151;
            font-weight: 600;
            margin-bottom: 8px;
        }
        
        .detail-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 5px;
            font-size: 0.9rem;
        }
        
        .items-table {
            width: 100%;
            margin-bottom: 20px;
        }
        
        .items-table th {
            background: #f3f4f6;
            padding: 12px;
            font-weight: 600;
            border-bottom: 2px solid #e5e7eb;
        }
        
        .items-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .items-table .text-end {
            text-align: right;
        }
        
        .summary-section {
            border-top: 2px solid #e5e7eb;
            padding-top: 15px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 8px;
            font-size: 1rem;
        }
        
        .total-row {
            font-weight: 700;
            font-size: 1.2rem;
            border-top: 1px solid #e5e7eb;
            padding-top: 8px;
            color: var(--pos-success);
        }
        
        .receipt-footer {
            text-align: center;
            margin-top: 30px;
            padding-top: 20px;
            border-top: 1px dashed #d1d5db;
            color: #6b7280;
            font-size: 0.9rem;
        }
        
        .action-buttons {
            display: flex;
            gap: 15px;
            justify-content: center;
            margin-bottom: 20px;
        }
        
        .btn-print {
            background: linear-gradient(135deg, var(--pos-primary), #6366f1);
            border: none;
            color: white;
            padding: 12px 30px;
            border-radius: 8px;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .btn-print:hover {
            background: linear-gradient(135deg, #3730a3, #4338ca);
            transform: translateY(-1px);
        }
        
        .queue-highlight {
            background: linear-gradient(135deg, var(--pos-primary), #6366f1);
            color: white;
            padding: 20px;
            border-radius: 12px;
            text-align: center;
            margin-bottom: 20px;
        }
        
        .queue-number {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 10px;
        }
        
        .queue-status {
            font-size: 1.1rem;
            opacity: 0.9;
        }
        
        /* Print Styles */
        @media print {
            body {
                background: white;
                font-size: 12px;
            }
            
            .pos-header,
            .action-buttons,
            .btn {
                display: none !important;
            }
            
            .pos-container {
                padding: 0;
                max-width: none;
            }
            
            .receipt-container {
                box-shadow: none;
                border-radius: 0;
                padding: 20px;
            }
            
            .queue-highlight {
                border: 2px solid #000;
                background: white !important;
                color: black !important;
            }
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .receipt-details {
                grid-template-columns: 1fr;
                gap: 15px;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .action-buttons .btn {
                width: 100%;
                max-width: 300px;
            }
        }
    </style>
</head>
<body>
    <div class="pos-container">
        <!-- Header -->
        <div class="pos-header">
            <h1 class="h3 mb-1">
                <i class="fas fa-check-circle me-2"></i>
                ออเดอร์สำเร็จ!
            </h1>
            <p class="mb-0 opacity-75">ใบเสร็จและข้อมูลออเดอร์</p>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo clean($error); ?>
            </div>
            
            <div class="text-center">
                <a href="index.php" class="btn btn-primary">
                    <i class="fas fa-home me-2"></i>กลับหน้าหลัก
                </a>
            </div>
        <?php else: ?>
            
            <!-- Queue Number Highlight -->
            <div class="queue-highlight">
                <div class="queue-number"><?php echo clean($order['queue_number']); ?></div>
                <div class="queue-status">หมายเลขคิวของท่าน</div>
            </div>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <button onclick="printReceipt()" class="btn btn-print">
                    <i class="fas fa-print me-2"></i>พิมพ์ใบเสร็จ
                </button>
                
                <a href="new_order.php" class="btn btn-success">
                    <i class="fas fa-plus me-2"></i>ออเดอร์ใหม่
                </a>
                
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-home me-2"></i>หน้าหลัก
                </a>
            </div>
            
            <!-- Receipt -->
            <div class="receipt-container" id="receiptContent">
                <!-- Receipt Header -->
                <div class="receipt-header">
                    <div class="receipt-logo">
                        <i class="fas fa-store"></i>
                    </div>
                    <div class="shop-name"><?php echo SITE_NAME; ?></div>
                    <div class="shop-info">
                        ระบบ POS อัจฉริยะ<br>
                        โทร: 02-xxx-xxxx<br>
                        www.smartorder.com
                    </div>
                </div>
                
                <!-- Order Details -->
                <div class="receipt-details">
                    <div class="detail-group">
                        <h6>ข้อมูลออเดอร์</h6>
                        <div class="detail-item">
                            <span>หมายเลขออเดอร์:</span>
                            <span><?php echo clean($order['order_id']); ?></span>
                        </div>
                        <div class="detail-item">
                            <span>หมายเลขคิว:</span>
                            <span><strong><?php echo clean($order['queue_number']); ?></strong></span>
                        </div>
                        <div class="detail-item">
                            <span>ประเภท:</span>
                            <span>
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
                        <?php if ($order['table_number']): ?>
                        <div class="detail-item">
                            <span>โต๊ะ:</span>
                            <span><?php echo clean($order['table_number']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="detail-group">
                        <h6>การชำระเงิน</h6>
                        <div class="detail-item">
                            <span>วันที่:</span>
                            <span><?php echo formatDate($order['created_at'], 'd/m/Y'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span>เวลา:</span>
                            <span><?php echo formatDate($order['created_at'], 'H:i'); ?></span>
                        </div>
                        <div class="detail-item">
                            <span>วิธีชำระ:</span>
                            <span>
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
                        <div class="detail-item">
                            <span>สถานะ:</span>
                            <span class="text-success">
                                <i class="fas fa-check-circle"></i> ชำระแล้ว
                            </span>
                        </div>
                    </div>
                </div>
                
                <!-- Order Items -->
                <table class="items-table">
                    <thead>
                        <tr>
                            <th>รายการ</th>
                            <th class="text-center">จำนวน</th>
                            <th class="text-end">ราคาต่อหน่วย</th>
                            <th class="text-end">รวม</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderItems as $item): ?>
                        <tr>
                            <td><?php echo clean($item['product_name']); ?></td>
                            <td class="text-center"><?php echo number_format($item['quantity']); ?></td>
                            <td class="text-end"><?php echo formatCurrency($item['unit_price']); ?></td>
                            <td class="text-end"><?php echo formatCurrency($item['subtotal']); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                
                <!-- Summary -->
                <div class="summary-section">
                    <div class="summary-row">
                        <span>จำนวนรายการ:</span>
                        <span><?php echo count($orderItems); ?> รายการ</span>
                    </div>
                    <div class="summary-row">
                        <span>ยอดรวม:</span>
                        <span><?php echo formatCurrency($order['total_price']); ?></span>
                    </div>
                    <div class="summary-row total-row">
                        <span>ยอดที่ชำระ:</span>
                        <span><?php echo formatCurrency($order['total_price']); ?></span>
                    </div>
                </div>
                
                <?php if ($order['notes']): ?>
                <div style="margin-top: 20px; padding: 15px; background: #fef3cd; border-radius: 8px;">
                    <strong>หมายเหตุ:</strong><br>
                    <?php echo nl2br(clean($order['notes'])); ?>
                </div>
                <?php endif; ?>
                
                <!-- Footer -->
                <div class="receipt-footer">
                    <p><strong>ขอบคุณที่ใช้บริการ</strong></p>
                    <p>กรุณาเก็บใบเสร็จนี้ไว้เป็นหลักฐาน</p>
                    <p>หมายเลขคิว: <strong><?php echo clean($order['queue_number']); ?></strong></p>
                    <hr style="margin: 15px 0; border-style: dashed;">
                    <small>
                        ระบบ POS อัจฉริยะ - Smart Order Management<br>
                        พิมพ์โดย: <?php echo clean(getCurrentUser()['fullname']); ?><br>
                        <?php echo formatDate(date('Y-m-d H:i:s'), 'd/m/Y H:i:s'); ?>
                    </small>
                </div>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Clear cart from sessionStorage if specified
        <?php if ($clearCart): ?>
        sessionStorage.removeItem('posCart');
        <?php endif; ?>
        
        // Print receipt function
        function printReceipt() {
            window.print();
        }
        
        // Auto print on load (optional)
        // window.addEventListener('load', function() {
        //     setTimeout(printReceipt, 1000);
        // });
        
        // Voice announcement for queue number
        function announceQueue() {
            const queueNumber = '<?php echo $order['queue_number'] ?? ''; ?>';
            if (queueNumber && 'speechSynthesis' in window) {
                const utterance = new SpeechSynthesisUtterance(
                    `หมายเลขคิว ${queueNumber} สำหรับลูกค้า กรุณารอการเรียกคิว`
                );
                utterance.lang = 'th-TH';
                utterance.rate = 0.8;
                speechSynthesis.speak(utterance);
            }
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // Ctrl + P for print
            if (e.ctrlKey && e.key === 'p') {
                e.preventDefault();
                printReceipt();
            }
            
            // F2 for new order
            if (e.key === 'F2') {
                e.preventDefault();
                window.location.href = 'new_order.php';
            }
            
            // Esc for home
            if (e.key === 'Escape') {
                e.preventDefault();
                window.location.href = 'index.php';
            }
        });
        
        console.log('Receipt page loaded successfully');
    </script>
</body>
</html>