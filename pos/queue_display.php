<?php
/**
 * จอแสดงคิวสำหรับหน้าจอใหญ่ - POS
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once dirname(__DIR__) . '/config/config.php';
require_once dirname(__DIR__) . '/config/database.php';
require_once dirname(__DIR__) . '/config/session.php';
require_once dirname(__DIR__) . '/includes/functions.php';

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
    
    <!-- Google Fonts - Kanit -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Kanit:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        :root {
            /* สีหลักที่สบายตา */
            --primary-gradient: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            --soft-blue: #3b82f6;
            --soft-green: #10b981;
            --soft-orange: #f59e0b;
            --soft-red: #ef4444;
            --soft-purple: #8b5cf6;
            --soft-teal: #06b6d4;
            
            /* พื้นหลังและโครงสร้าง */
            --bg-gradient: linear-gradient(135deg, #e0e7ff 0%, #f0f4ff 50%, #fef3c7 100%);
            --card-bg: rgba(255, 255, 255, 0.95);
            --card-shadow: 0 10px 40px rgba(0, 0, 0, 0.1);
            --text-primary: #1f2937;
            --text-secondary: #6b7280;
            --text-light: #9ca3af;
            
            /* สีสำหรับสถานะคิว */
            --ready-color: #059669;
            --ready-bg: linear-gradient(135deg, #d1fae5 0%, #a7f3d0 100%);
            --preparing-color: #d97706;
            --preparing-bg: linear-gradient(135deg, #fef3c7 0%, #fde68a 100%);
            --waiting-color: #2563eb;
            --waiting-bg: linear-gradient(135deg, #dbeafe 0%, #bfdbfe 100%);
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: var(--bg-gradient);
            color: var(--text-primary);
            font-family: 'Kanit', 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            overflow-x: hidden;
            min-height: 100vh;
            font-weight: 400;
        }
        
        .queue-container {
            padding: 20px;
            min-height: 100vh;
        }
        
        .queue-header {
            text-align: center;
            margin-bottom: 40px;
            padding: 30px;
            background: var(--card-bg);
            border-radius: 25px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        
        .queue-header h1 {
            font-size: 3.5rem;
            font-weight: 600;
            margin-bottom: 15px;
            background: var(--primary-gradient);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            font-family: 'Kanit', sans-serif;
        }
        
        .queue-header .datetime {
            font-size: 1.3rem;
            color: var(--text-secondary);
            font-weight: 400;
        }
        
        .queue-sections {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
            margin-bottom: 30px;
        }
        
        .queue-section {
            background: var(--card-bg);
            border-radius: 25px;
            padding: 30px;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
            transition: transform 0.3s ease;
        }
        
        .queue-section:hover {
            transform: translateY(-5px);
        }
        
        .section-title {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 20px;
            font-size: 2rem;
            font-weight: 600;
            margin-bottom: 30px;
            text-align: center;
            font-family: 'Kanit', sans-serif;
        }
        
        .section-title.ready {
            color: var(--ready-color);
        }
        
        .section-title.preparing {
            color: var(--preparing-color);
        }
        
        .section-title.waiting {
            color: var(--waiting-color);
        }
        
        .section-icon {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.8rem;
            color: white;
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.2);
            transition: transform 0.3s ease;
        }
        
        .section-icon:hover {
            transform: scale(1.1);
        }
        
        .section-icon.ready {
            background: linear-gradient(135deg, #059669, #34d399);
        }
        
        .section-icon.preparing {
            background: linear-gradient(135deg, #d97706, #fbbf24);
        }
        
        .section-icon.waiting {
            background: linear-gradient(135deg, #2563eb, #60a5fa);
        }
        
        .queue-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
            gap: 15px;
        }
        
        .queue-item {
            border-radius: 20px;
            padding: 25px;
            text-align: center;
            transition: all 0.3s ease;
            border: 2px solid transparent;
            position: relative;
            overflow: hidden;
            box-shadow: 0 5px 20px rgba(0, 0, 0, 0.1);
        }
        
        .queue-item:hover {
            transform: translateY(-3px);
            box-shadow: 0 10px 30px rgba(0, 0, 0, 0.15);
        }
        
        .queue-item.ready {
            background: var(--ready-bg);
            color: var(--ready-color);
            border-color: var(--ready-color);
            animation: pulse-ready 3s infinite;
        }
        
        .queue-item.preparing {
            background: var(--preparing-bg);
            color: var(--preparing-color);
            border-color: var(--preparing-color);
        }
        
        .queue-item.waiting {
            background: var(--waiting-bg);
            color: var(--waiting-color);
            border-color: var(--waiting-color);
        }
        
        .queue-number {
            font-size: 2.8rem;
            font-weight: 700;
            margin-bottom: 12px;
            font-family: 'Kanit', sans-serif;
            text-shadow: 0 2px 5px rgba(0,0,0,0.1);
            line-height: 1;
        }
        
        .queue-customer {
            font-size: 1.1rem;
            font-weight: 500;
            margin-bottom: 8px;
            font-family: 'Kanit', sans-serif;
        }
        
        .queue-time {
            font-size: 1rem;
            opacity: 0.7;
            font-weight: 400;
        }
        
        .queue-amount {
            font-size: 1rem;
            font-weight: 600;
            margin-top: 8px;
            font-family: 'Kanit', sans-serif;
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
            background: var(--card-bg);
            border-radius: 15px;
            padding: 20px;
            text-align: center;
            border: 2px solid var(--waiting-color);
            transition: all 0.3s ease;
            box-shadow: 0 3px 10px rgba(0, 0, 0, 0.1);
        }
        
        .waiting-item:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(0, 0, 0, 0.15);
        }
        
        .waiting-number {
            font-size: 2rem;
            font-weight: 700;
            margin-bottom: 10px;
            color: var(--waiting-color);
            font-family: 'Kanit', sans-serif;
        }
        
        .waiting-customer {
            font-size: 1rem;
            color: var(--text-secondary);
            font-weight: 500;
            font-family: 'Kanit', sans-serif;
        }
        
        .empty-state {
            text-align: center;
            padding: 50px;
            color: var(--text-light);
        }
        
        .empty-state i {
            font-size: 3.5rem;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        
        .empty-state p {
            font-size: 1.2rem;
            font-family: 'Kanit', sans-serif;
            font-weight: 400;
        }
        
        .current-time {
            position: fixed;
            top: 25px;
            right: 25px;
            background: var(--card-bg);
            color: var(--text-primary);
            padding: 15px 25px;
            border-radius: 30px;
            font-size: 1.2rem;
            font-weight: 600;
            font-family: 'Kanit', sans-serif;
            box-shadow: var(--card-shadow);
            border: 1px solid rgba(255, 255, 255, 0.8);
        }
        
        /* Animations */
        @keyframes pulse-ready {
            0%, 100% {
                transform: scale(1);
                box-shadow: 0 5px 20px rgba(5, 150, 105, 0.3);
            }
            50% {
                transform: scale(1.02);
                box-shadow: 0 8px 30px rgba(5, 150, 105, 0.5);
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
        
        /* Extra Large Screens (4K/Large TV) */
        @media (min-width: 1800px) {
            .queue-header h1 {
                font-size: 5rem;
            }
            
            .section-title {
                font-size: 2.8rem;
            }
            
            .queue-number {
                font-size: 3.5rem;
            }
            
            .queue-grid {
                grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
                gap: 25px;
            }
            
            .queue-container {
                padding: 40px;
            }
        }
        
        /* Large Screens (TV/Large Monitor) */
        @media (min-width: 1200px) and (max-width: 1799px) {
            .queue-header h1 {
                font-size: 4.2rem;
            }
            
            .section-title {
                font-size: 2.4rem;
            }
            
            .queue-number {
                font-size: 3.2rem;
            }
            
            .queue-grid {
                grid-template-columns: repeat(auto-fill, minmax(270px, 1fr));
                gap: 20px;
            }
            
            .waiting-grid {
                grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
                gap: 18px;
            }
        }
        
        /* Tablet Landscape */
        @media (max-width: 1199px) and (min-width: 769px) {
            .queue-header h1 {
                font-size: 3rem;
            }
            
            .section-title {
                font-size: 1.8rem;
            }
            
            .queue-number {
                font-size: 2.5rem;
            }
        }
        
        /* Mobile/Tablet Portrait */
        @media (max-width: 768px) {
            .queue-container {
                padding: 15px;
            }
            
            .queue-sections {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .queue-header {
                padding: 20px;
                margin-bottom: 25px;
            }
            
            .queue-header h1 {
                font-size: 2.5rem;
            }
            
            .section-title {
                font-size: 1.6rem;
                gap: 15px;
            }
            
            .section-icon {
                width: 50px;
                height: 50px;
                font-size: 1.3rem;
            }
            
            .queue-number {
                font-size: 2.2rem;
            }
            
            .queue-grid {
                grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
                gap: 12px;
            }
            
            .waiting-grid {
                grid-template-columns: repeat(auto-fill, minmax(120px, 1fr));
                gap: 10px;
            }
            
            .current-time {
                top: 15px;
                right: 15px;
                padding: 10px 15px;
                font-size: 1rem;
            }
        }
        
        /* Very Small Mobile */
        @media (max-width: 480px) {
            .queue-header h1 {
                font-size: 2rem;
            }
            
            .section-title {
                font-size: 1.3rem;
                flex-direction: column;
                gap: 10px;
            }
            
            .queue-number {
                font-size: 1.8rem;
            }
            
            .queue-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
            }
            
            .waiting-grid {
                grid-template-columns: repeat(auto-fill, minmax(100px, 1fr));
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
                <br>
                <small style="opacity: 0.7; font-size: 0.9rem;">
                    รวม <?php echo count($readyQueue) + count($preparingQueue) + count($currentQueue); ?> คิว | 
                    F11: เต็มจอ | Space: รีเฟรช
                </small>
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
            
            // Spacebar for refresh
            if (e.code === 'Space') {
                e.preventDefault();
                location.reload();
            }
            
            // 'R' for refresh
            if (e.key.toLowerCase() === 'r' && !e.ctrlKey) {
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
                    item.querySelector('.queue-number').textContent.trim()
                );
                
                if (queueNumbers.length > 0) {
                    let message;
                    if (queueNumbers.length === 1) {
                        message = `คิวหมายเลข ${queueNumbers[0]} พร้อมเสิร์ฟแล้วครับ กรุณามารับที่เคาน์เตอร์`;
                    } else {
                        message = `คิวหมายเลข ${queueNumbers.slice(0, -1).join(', ')} และ ${queueNumbers.slice(-1)} พร้อมเสิร์ฟแล้วครับ กรุณามารับที่เคาน์เตอร์`;
                    }
                    
                    const utterance = new SpeechSynthesisUtterance(message);
                    utterance.lang = 'th-TH';
                    utterance.rate = 0.9;
                    utterance.volume = 0.8;
                    
                    // หาเสียงภาษาไทย
                    const voices = speechSynthesis.getVoices();
                    const thaiVoice = voices.find(voice => 
                        voice.lang === 'th-TH' || 
                        voice.lang.startsWith('th') ||
                        voice.name.includes('Thai')
                    );
                    
                    if (thaiVoice) {
                        utterance.voice = thaiVoice;
                    }
                    
                    speechSynthesis.speak(utterance);
                    console.log('Announced:', message);
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