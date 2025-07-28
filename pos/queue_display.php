<?php
/**
 * จอแสดงคิวสำหรับหน้าจอใหญ่ - POS
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

$pageTitle = 'จอแสดงคิว';

// เริ่มต้นตัวแปร
$currentQueue = [];
$readyQueue = [];
$preparingQueue = [];
$error = null;

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงคิวที่พร้อมเสิร์ฟ (เรียกแล้ว)
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name 
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE o.status = 'ready'
        ORDER BY o.created_at ASC 
        LIMIT 6
    ");
    $stmt->execute();
    $readyQueue = $stmt->fetchAll();
    
    // ดึงคิวที่กำลังเตรียม
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name 
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE o.status = 'preparing'
        ORDER BY o.created_at ASC 
        LIMIT 8
    ");
    $stmt->execute();
    $preparingQueue = $stmt->fetchAll();
    
    // ดึงคิวที่รอดำเนินการ
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name 
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE o.status = 'confirmed'
        ORDER BY o.created_at ASC 
        LIMIT 10
    ");
    $stmt->execute();
    $currentQueue = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("Queue display error: " . $e->getMessage());
    $error = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
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
            --queue-primary: #4f46e5;
            --queue-success: #10b981;
            --queue-warning: #f59e0b;
            --queue-danger: #ef4444;
            --queue-info: #3b82f6;
            --queue-light: #f8fafc;
            --queue-white: #ffffff;
            --queue-dark: #1f2937;
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: var(--queue-white);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
            min-height: 100vh;
        }
        
        .queue-container {
            padding: 20px;
            min-height: 100vh;
        }
        
        .queue-header {
            text-align: center;
            margin-bottom: 30px;
            padding: 20px;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            backdrop-filter: blur(10px);
        }
        
        .queue-header h1 {
            font-size: 3rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 0 2px 10px rgba(0,0,0,0.3);
        }
        
        .queue-header .datetime {
            font-size: 1.2rem;
            opacity: 0.9;
        }
        
        .queue-sections {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .queue-section {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 20px;
            padding: 25px;
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .section-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 15px;
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 25px;
            text-align: center;
        }
        
        .section-title.ready {
            color: #34d399;
        }
        
        .section-title.preparing {
            color: #fbbf24;
        }
        
        .section-title.waiting {
            color: #60a5fa;
        }
        
        .section-icon {
            width: 60px;
            height: 60px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .section-icon.ready {
            background: #34d399;
        }
        
        .section-icon.preparing {
            background: #fbbf24;
        }
        
        .section-icon.waiting {
            background: #60a5fa;
        }
        
        .queue-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .queue-item {
            background: rgba(255, 255, 255, 0.9);
            color: var(--queue-dark);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
        }
        
        .queue-item.ready {
            background: rgba(52, 211, 153, 0.9);
            color: white;
            animation: pulse-ready 2s infinite;
        }
        
        .queue-item.preparing {
            background: rgba(251, 191, 36, 0.9);
            color: var(--queue-dark);
        }
        
        .queue-item.waiting {
            background: rgba(96, 165, 250, 0.9);
            color: white;
        }
        
        .queue-number {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 10px;
            text-shadow: 0 2px 5px rgba(0,0,0,0.2);
        }
        
        .queue-customer {
            font-size: 1rem;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .queue-time {
            font-size: 0.9rem;
            opacity: 0.8;
        }
        
        .queue-amount {
            font-size: 0.9rem;
            font-weight: 600;
            margin-top: 5px;
        }
        
        .waiting-section {
            grid-column: 1 / -1;
        }
        
        .waiting-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 12px;
        }
        
        .waiting-item {
            background: rgba(255, 255, 255, 0.1);
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .waiting-number {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 8px;
        }
        
        .waiting-customer {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            opacity: 0.7;
        }
        
        .empty-state i {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        
        .current-time {
            position: fixed;
            top: 20px;
            right: 20px;
            background: rgba(0, 0, 0, 0.3);
            padding: 10px 20px;
            border-radius: 25px;
            font-size: 1.1rem;
            font-weight: 600;
        }
        
        /* Animations */
        @keyframes pulse-ready {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 0 20px rgba(52, 211, 153, 0.5);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 0 30px rgba(52, 211, 153, 0.8);
            }
        }
        
        @keyframes slideIn {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .queue-item {
            animation: slideIn 0.5s ease-out;
        }
        
        /* TV/Large Screen Optimizations */
        @media (min-width: 1200px) {
            .queue-header h1 {
                font-size: 4rem;
            }
            
            .section-title {
                font-size: 2.2rem;
            }
            
            .queue-number {
                font-size: 3rem;
            }
            
            .queue-grid {
                grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            }
        }
        
        /* Mobile/Tablet Responsive */
        @media (max-width: 768px) {
            .queue-sections {
                grid-template-columns: 1fr;
            }
            
            .queue-header h1 {
                font-size: 2rem;
            }
            
            .section-title {
                font-size: 1.4rem;
            }
            
            .queue-number {
                font-size: 2rem;
            }
            
            .queue-grid {
                grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            }
        }
    </style>
</head>
<body>
    <div class="queue-container">
        <!-- Current Time -->
        <div class="current-time" id="currentTime">
            <?php echo formatDate(date('Y-m-d H:i:s'), 'H:i:s'); ?>
        </div>
        
        <!-- Header -->
        <div class="queue-header">
            <h1>
                <i class="fas fa-tv me-3"></i>
                จอแสดงคิว
            </h1>
            <div class="datetime">
                <?php echo formatDate(date('Y-m-d H:i:s'), 'd/m/Y'); ?> • <?php echo SITE_NAME; ?>
            </div>
        </div>
        
        <?php if ($error): ?>
            <div class="alert alert-danger text-center">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo clean($error); ?>
            </div>
        <?php endif; ?>
        
        <!-- Queue Sections -->
        <div class="queue-sections">
            <!-- Ready Queue -->
            <div class="queue-section">
                <div class="section-title ready">
                    <div class="section-icon ready">
                        <i class="fas fa-bell"></i>
                    </div>
                    <div>
                        เรียกแล้ว - รับออเดอร์
                        <div style="font-size: 0.8em; opacity: 0.8;">
                            <?php echo count($readyQueue); ?> คิว
                        </div>
                    </div>
                </div>
                
                <div class="queue-grid">
                    <?php if (empty($readyQueue)): ?>
                        <div class="empty-state">
                            <i class="fas fa-check-circle"></i>
                            <p>ไม่มีคิวที่พร้อมรับ</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($readyQueue as $order): ?>
                            <div class="queue-item ready">
                                <div class="queue-number">
                                    <?php echo clean($order['queue_number']); ?>
                                </div>
                                <div class="queue-customer">
                                    <?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?>
                                </div>
                                <div class="queue-time">
                                    <?php echo formatDate($order['created_at'], 'H:i'); ?>
                                </div>
                                <div class="queue-amount">
                                    <?php echo formatCurrency($order['total_price']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- Preparing Queue -->
            <div class="queue-section">
                <div class="section-title preparing">
                    <div class="section-icon preparing">
                        <i class="fas fa-utensils"></i>
                    </div>
                    <div>
                        กำลังเตรียม
                        <div style="font-size: 0.8em; opacity: 0.8;">
                            <?php echo count($preparingQueue); ?> คิว
                        </div>
                    </div>
                </div>
                
                <div class="queue-grid">
                    <?php if (empty($preparingQueue)): ?>
                        <div class="empty-state">
                            <i class="fas fa-fire"></i>
                            <p>ไม่มีคิวที่กำลังเตรียม</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($preparingQueue as $order): ?>
                            <div class="queue-item preparing">
                                <div class="queue-number">
                                    <?php echo clean($order['queue_number']); ?>
                                </div>
                                <div class="queue-customer">
                                    <?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?>
                                </div>
                                <div class="queue-time">
                                    <?php echo formatDate($order['created_at'], 'H:i'); ?>
                                </div>
                                <div class="queue-amount">
                                    <?php echo formatCurrency($order['total_price']); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- Waiting Queue -->
        <div class="queue-section waiting-section">
            <div class="section-title waiting">
                <div class="section-icon waiting">
                    <i class="fas fa-clock"></i>
                </div>
                <div>
                    คิวที่รอดำเนินการ
                    <div style="font-size: 0.8em; opacity: 0.8;">
                        <?php echo count($currentQueue); ?> คิว
                    </div>
                </div>
            </div>
            
            <div class="waiting-grid">
                <?php if (empty($currentQueue)): ?>
                    <div class="empty-state" style="grid-column: 1 / -1;">
                        <i class="fas fa-hourglass-half"></i>
                        <p>ไม่มีคิวที่รอดำเนินการ</p>
                    </div>
                <?php else: ?>
                    <?php foreach ($currentQueue as $order): ?>
                        <div class="waiting-item">
                            <div class="waiting-number">
                                <?php echo clean($order['queue_number']); ?>
                            </div>
                            <div class="waiting-customer">
                                <?php echo clean($order['customer_name'] ?: 'ลูกค้าทั่วไป'); ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Update current time
        function updateTime() {
            const now = new Date();
            const timeString = now.toLocaleTimeString('th-TH', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            document.getElementById('currentTime').textContent = timeString;
        }
        
        // Update time every second
        setInterval(updateTime, 1000);
        
        // Auto refresh page every 15 seconds
        setInterval(function() {
            location.reload();
        }, 15000);
        
        // Fullscreen functionality
        function toggleFullscreen() {
            if (!document.fullscreenElement) {
                document.documentElement.requestFullscreen().catch(err => {
                    console.log('Error attempting to enable fullscreen:', err.message);
                });
            } else {
                document.exitFullscreen();
            }
        }
        
        // Keyboard shortcuts
        document.addEventListener('keydown', function(e) {
            // F11 for fullscreen
            if (e.key === 'F11') {
                e.preventDefault();
                toggleFullscreen();
            }
            
            // F5 for refresh
            if (e.key === 'F5') {
                e.preventDefault();
                location.reload();
            }
            
            // Esc to exit fullscreen
            if (e.key === 'Escape' && document.fullscreenElement) {
                document.exitFullscreen();
            }
        });
        
        // Voice announcement for ready queue items
        function announceReadyQueue() {
            const readyItems = document.querySelectorAll('.queue-item.ready');
            if (readyItems.length > 0 && 'speechSynthesis' in window) {
                const queueNumbers = Array.from(readyItems).map(item => 
                    item.querySelector('.queue-number').textContent
                );
                
                if (queueNumbers.length > 0) {
                    const message = `หมายเลขคิว ${queueNumbers.join(', ')} กรุณามารับออเดอร์ที่เคาน์เตอร์`;
                    const utterance = new SpeechSynthesisUtterance(message);
                    utterance.lang = 'th-TH';
                    utterance.rate = 0.8;
                    speechSynthesis.speak(utterance);
                }
            }
        }
        
        // Check for new ready orders and announce
        let previousReadyCount = <?php echo count($readyQueue); ?>;
        
        // Announce ready queue every 30 seconds if there are ready orders
        setInterval(function() {
            const currentReadyCount = document.querySelectorAll('.queue-item.ready').length;
            if (currentReadyCount > 0) {
                announceReadyQueue();
            }
        }, 30000);
        
        // Add visual effects
        document.addEventListener('DOMContentLoaded', function() {
            // Add stagger animation to queue items
            const queueItems = document.querySelectorAll('.queue-item, .waiting-item');
            queueItems.forEach((item, index) => {
                item.style.animationDelay = `${index * 0.1}s`;
            });
            
            // Hide cursor after 5 seconds of inactivity
            let mouseTimer;
            document.addEventListener('mousemove', function() {
                document.body.style.cursor = 'default';
                clearTimeout(mouseTimer);
                mouseTimer = setTimeout(() => {
                    document.body.style.cursor = 'none';
                }, 5000);
            });
        });
        
        console.log('Queue Display loaded successfully');
        console.log('Ready queue:', <?php echo count($readyQueue); ?>);
        console.log('Preparing queue:', <?php echo count($preparingQueue); ?>);
        console.log('Waiting queue:', <?php echo count($currentQueue); ?>);
    </script>
</body>
</html>