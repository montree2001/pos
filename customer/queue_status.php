<?php
/**
 * หน้าตรวจสอบสถานะคิว
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

$pageTitle = 'ตรวจสอบสถานะคิว';
$pageDescription = 'ตรวจสอบสถานะและเวลารอคิวแบบ Real-time';

// รับ queue number จาก URL
$queueNumber = $_GET['queue'] ?? '';
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
            --primary-color: #4f46e5;
            --secondary-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
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
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        /* Navigation */
        .navbar-custom {
            background: var(--white);
            box-shadow: var(--box-shadow);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
        }
        
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color) !important;
        }
        
        /* Header */
        .queue-header {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.9), rgba(99, 102, 241, 0.9));
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        /* Search Section */
        .search-section {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            text-align: center;
        }
        
        .search-form {
            max-width: 500px;
            margin: 0 auto;
        }
        
        .queue-input {
            border: 3px solid var(--border-color);
            border-radius: 12px;
            padding: 16px 20px;
            font-size: 1.25rem;
            font-weight: 600;
            text-align: center;
            text-transform: uppercase;
            letter-spacing: 2px;
            transition: var(--transition);
        }
        
        .queue-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
            transform: translateY(-2px);
        }
        
        .search-btn {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            border: none;
            border-radius: 12px;
            padding: 16px 32px;
            font-size: 1.125rem;
            font-weight: 600;
            transition: var(--transition);
            min-width: 150px;
        }
        
        .search-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.3);
        }
        
        /* Queue Status Card */
        .queue-status-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
            display: none;
        }
        
        .queue-status-card.show {
            display: block;
            animation: fadeInUp 0.5s ease-out;
        }
        
        .queue-number-display {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .queue-number {
            font-size: 3rem;
            font-weight: 800;
            color: var(--primary-color);
            letter-spacing: 3px;
            margin: 0;
        }
        
        .queue-label {
            color: var(--text-muted);
            font-size: 1.125rem;
            margin-top: 0.5rem;
        }
        
        /* Status Indicator */
        .status-indicator {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            padding: 12px 24px;
            border-radius: 50px;
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 1rem;
        }
        
        .status-pending {
            background: #fef3c7;
            color: #d97706;
            border: 2px solid #fbbf24;
        }
        
        .status-confirmed {
            background: #ddd6fe;
            color: #7c3aed;
            border: 2px solid #a78bfa;
        }
        
        .status-preparing {
            background: #dbeafe;
            color: #2563eb;
            border: 2px solid #60a5fa;
        }
        
        .status-ready {
            background: #dcfce7;
            color: #16a34a;
            border: 2px solid #22c55e;
        }
        
        .status-completed {
            background: #f3f4f6;
            color: #4b5563;
            border: 2px solid #9ca3af;
        }
        
        .status-cancelled {
            background: #fee2e2;
            color: #dc2626;
            border: 2px solid #ef4444;
        }
        
        .status-description {
            color: var(--text-muted);
            font-size: 1rem;
        }
        
        /* Progress Bar */
        .progress-section {
            margin-bottom: 2rem;
        }
        
        .progress-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
        }
        
        .progress-title {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .progress-percentage {
            font-weight: 700;
            color: var(--secondary-color);
        }
        
        .progress {
            height: 12px;
            border-radius: 10px;
            background: var(--light-bg);
            overflow: hidden;
        }
        
        .progress-bar {
            border-radius: 10px;
            transition: width 1s ease-in-out;
        }
        
        /* Time Info */
        .time-info {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .time-card {
            background: var(--light-bg);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            border: 2px solid var(--border-color);
            transition: var(--transition);
        }
        
        .time-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-2px);
        }
        
        .time-icon {
            font-size: 2rem;
            color: var(--primary-color);
            margin-bottom: 0.5rem;
        }
        
        .time-value {
            font-size: 1.5rem;
            font-weight: 700;
            margin-bottom: 0.25rem;
        }
        
        .time-label {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        /* Order Details */
        .order-details {
            background: var(--light-bg);
            border-radius: 12px;
            padding: 1.5rem;
            margin-bottom: 2rem;
        }
        
        .details-title {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 1rem;
            color: var(--text-color);
        }
        
        .order-items {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        
        .order-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 1rem;
            background: var(--white);
            border-radius: 8px;
            border: 1px solid var(--border-color);
        }
        
        .item-info {
            flex: 1;
        }
        
        .item-name {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .item-details {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        .item-price {
            font-weight: 700;
            color: var(--secondary-color);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
        }
        
        .action-btn {
            background: linear-gradient(135deg, var(--secondary-color), #059669);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 12px 24px;
            font-weight: 500;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
            color: white;
        }
        
        .action-btn.secondary {
            background: linear-gradient(135deg, var(--info-color), #2563eb);
        }
        
        .action-btn.warning {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
        }
        
        .action-btn.secondary:hover {
            box-shadow: 0 8px 25px rgba(59, 130, 246, 0.3);
        }
        
        .action-btn.warning:hover {
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
        }
        
        /* Loading State */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Error State */
        .error-message {
            background: #fee2e2;
            color: #dc2626;
            border: 2px solid #ef4444;
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .error-message i {
            font-size: 2rem;
            margin-bottom: 0.5rem;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .search-section {
                margin: 0 1rem 2rem;
                padding: 1.5rem;
            }
            
            .queue-status-card {
                margin: 0 1rem 2rem;
                padding: 1.5rem;
            }
            
            .queue-number {
                font-size: 2.5rem;
            }
            
            .time-info {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .action-btn {
                width: 100%;
                max-width: 300px;
                justify-content: center;
            }
        }
        
        /* Auto-refresh indicator */
        .refresh-indicator {
            position: fixed;
            top: 100px;
            right: 2rem;
            background: var(--white);
            border: 2px solid var(--border-color);
            border-radius: 50px;
            padding: 8px 16px;
            font-size: 0.875rem;
            color: var(--text-muted);
            box-shadow: var(--box-shadow);
            z-index: 999;
        }
        
        .refresh-indicator.updating {
            border-color: var(--primary-color);
            color: var(--primary-color);
        }
        
        /* Success notification */
        .queue-found {
            animation: successPulse 0.6s ease-out;
        }
        
        @keyframes successPulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.02); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-arrow-left me-2"></i>
                กลับหน้าหลัก
            </a>
            
            <div class="d-flex align-items-center gap-3">
                <a href="menu.php" class="btn btn-outline-primary">
                    <i class="fas fa-utensils me-2"></i>
                    <span class="d-none d-sm-inline">เมนู</span>
                </a>
                
                <a href="cart.php" class="btn btn-outline-secondary">
                    <i class="fas fa-shopping-cart me-2"></i>
                    <span class="d-none d-sm-inline">ตะกร้า</span>
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Header -->
    <section class="queue-header">
        <div class="container text-center">
            <h1 class="mb-2">ตรวจสอบสถานะคิว</h1>
            <p class="lead mb-0">ดูสถานะและเวลารอคิวแบบ Real-time</p>
        </div>
    </section>
    
    <div class="container">
        <!-- Search Section -->
        <div class="search-section animate__animated animate__fadeInUp">
            <h3 class="mb-3">ใส่หมายเลขคิวของคุณ</h3>
            <p class="text-muted mb-4">หมายเลขคิวจะเริ่มต้นด้วย Q ตามด้วยตัวเลข เช่น Q2507270001</p>
            
            <form class="search-form" onsubmit="searchQueue(event)">
                <div class="row g-3">
                    <div class="col-md-8">
                        <input type="text" 
                               id="queueInput" 
                               class="form-control queue-input" 
                               placeholder="Q2507270001" 
                               value="<?php echo clean($queueNumber); ?>"
                               pattern="[Qq][0-9]{9,10}"
                               maxlength="11"
                               required>
                        <div class="form-text">ตัวอย่าง: Q2507270001</div>
                    </div>
                    <div class="col-md-4">
                        <button type="submit" class="btn search-btn w-100" id="searchBtn">
                            <i class="fas fa-search me-2"></i>
                            ค้นหา
                        </button>
                    </div>
                </div>
            </form>
        </div>
        
        <!-- Error Message -->
        <div id="errorMessage" class="error-message" style="display: none;">
            <i class="fas fa-exclamation-triangle"></i>
            <h5>ไม่พบหมายเลขคิว</h5>
            <p id="errorText">กรุณาตรวจสอบหมายเลขคิวและลองใหม่อีกครั้ง</p>
        </div>
        
        <!-- Queue Status Card -->
        <div id="queueStatusCard" class="queue-status-card">
            <!-- Queue Number Display -->
            <div class="queue-number-display">
                <h2 class="queue-number" id="queueNumber">Q2507270001</h2>
                <p class="queue-label">หมายเลขคิวของคุณ</p>
            </div>
            
            <!-- Status Indicator -->
            <div class="status-indicator">
                <div id="statusBadge" class="status-badge status-preparing">
                    <i class="fas fa-clock"></i>
                    <span id="statusText">กำลังเตรียม</span>
                </div>
                <p id="statusDescription" class="status-description">
                    อาหารของคุณกำลังอยู่ในขั้นตอนการเตรียม
                </p>
            </div>
            
            <!-- Progress Bar -->
            <div class="progress-section">
                <div class="progress-header">
                    <span class="progress-title">ความคืบหน้า</span>
                    <span class="progress-percentage" id="progressPercentage">60%</span>
                </div>
                <div class="progress">
                    <div id="progressBar" class="progress-bar bg-success" style="width: 60%"></div>
                </div>
            </div>
            
            <!-- Time Information -->
            <div class="time-info">
                <div class="time-card">
                    <div class="time-icon">
                        <i class="fas fa-clock"></i>
                    </div>
                    <div class="time-value" id="elapsedTime">15</div>
                    <div class="time-label">นาทีที่ผ่านมา</div>
                </div>
                
                <div class="time-card">
                    <div class="time-icon">
                        <i class="fas fa-hourglass-half"></i>
                    </div>
                    <div class="time-value" id="remainingTime">5</div>
                    <div class="time-label">นาทีโดยประมาณ</div>
                </div>
                
                <div class="time-card">
                    <div class="time-icon">
                        <i class="fas fa-utensils"></i>
                    </div>
                    <div class="time-value" id="totalItems">3</div>
                    <div class="time-label">รายการอาหาร</div>
                </div>
            </div>
            
            <!-- Order Details -->
            <div class="order-details">
                <h4 class="details-title">รายละเอียดออเดอร์</h4>
                <div id="orderItems" class="order-items">
                    <!-- Items will be loaded here -->
                </div>
                
                <div class="d-flex justify-content-between align-items-center mt-3 pt-3 border-top">
                    <strong>ยอดรวมทั้งสิ้น</strong>
                    <strong id="totalPrice" class="text-success">฿150.00</strong>
                </div>
            </div>
            
            <!-- Action Buttons -->
            <div class="action-buttons">
                <button type="button" class="action-btn" onclick="refreshStatus()">
                    <i class="fas fa-sync-alt"></i>
                    รีเฟรชสถานะ
                </button>
                
                <a href="menu.php" class="action-btn secondary">
                    <i class="fas fa-plus"></i>
                    สั่งเพิ่ม
                </a>
                
                <button type="button" class="action-btn warning" onclick="showCancelDialog()" id="cancelBtn">
                    <i class="fas fa-times"></i>
                    ยกเลิกออเดอร์
                </button>
            </div>
        </div>
    </div>
    
    <!-- Auto-refresh Indicator -->
    <div id="refreshIndicator" class="refresh-indicator" style="display: none;">
        <i class="fas fa-sync-alt me-2"></i>
        <span id="refreshText">อัปเดต 30 วินาทีที่แล้ว</span>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const SITE_URL = '<?php echo SITE_URL; ?>';
        let currentOrderId = null;
        let refreshInterval = null;
        let lastUpdateTime = null;
        
        // Status configurations
        const statusConfig = {
            'pending': {
                class: 'status-pending',
                icon: 'fas fa-clock',
                text: 'รอยืนยัน',
                description: 'ออเดอร์ของคุณรอการยืนยันจากร้าน'
            },
            'confirmed': {
                class: 'status-confirmed',
                icon: 'fas fa-check',
                text: 'ยืนยันแล้ว',
                description: 'ออเดอร์ได้รับการยืนยันแล้ว เตรียมเริ่มทำอาหาร'
            },
            'preparing': {
                class: 'status-preparing',
                icon: 'fas fa-fire',
                text: 'กำลังเตรียม',
                description: 'อาหารของคุณกำลังอยู่ในขั้นตอนการเตรียม'
            },
            'ready': {
                class: 'status-ready',
                icon: 'fas fa-bell',
                text: 'พร้อมเสิร์ฟ',
                description: 'อาหารพร้อมแล้ว! กรุณามารับที่เคาน์เตอร์'
            },
            'completed': {
                class: 'status-completed',
                icon: 'fas fa-check-circle',
                text: 'เสร็จสิ้น',
                description: 'ขอบคุณที่ใช้บริการ'
            },
            'cancelled': {
                class: 'status-cancelled',
                icon: 'fas fa-times-circle',
                text: 'ยกเลิกแล้ว',
                description: 'ออเดอร์ถูกยกเลิก'
            }
        };
        
        // Search queue
        function searchQueue(event) {
            event.preventDefault();
            
            const queueNumber = document.getElementById('queueInput').value.trim().toUpperCase();
            if (!queueNumber) {
                showError('กรุณาใส่หมายเลขคิว');
                return;
            }
            
            loadQueueStatus(queueNumber);
        }
        
        // Load queue status
        function loadQueueStatus(queueNumber, silent = false) {
            if (!silent) {
                const btn = document.getElementById('searchBtn');
                btn.innerHTML = '<span class="loading-spinner"></span> กำลังค้นหา...';
                btn.disabled = true;
            }
            
            // Update refresh indicator
            updateRefreshIndicator(true);
            
            $.ajax({
                url: 'api/orders.php',
                type: 'GET',
                data: {
                    action: 'check_status',
                    queue_number: queueNumber
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        displayQueueStatus(response.order);
                        hideError();
                        
                        if (!silent) {
                            document.getElementById('queueStatusCard').classList.add('queue-found');
                        }
                        
                        // Start auto-refresh
                        startAutoRefresh(queueNumber);
                        
                    } else {
                        showError(response.message || 'ไม่พบหมายเลขคิวนี้');
                        hideQueueStatus();
                        stopAutoRefresh();
                    }
                },
                error: function() {
                    showError('เกิดข้อผิดพลาดในการเชื่อมต่อ');
                    hideQueueStatus();
                    stopAutoRefresh();
                },
                complete: function() {
                    if (!silent) {
                        const btn = document.getElementById('searchBtn');
                        btn.innerHTML = '<i class="fas fa-search me-2"></i>ค้นหา';
                        btn.disabled = false;
                    }
                    
                    updateRefreshIndicator(false);
                    lastUpdateTime = new Date();
                }
            });
        }
        
        // Display queue status
        function displayQueueStatus(order) {
            currentOrderId = order.order_id;
            
            // Update queue number
            document.getElementById('queueNumber').textContent = order.queue_number;
            
            // Update status
            const config = statusConfig[order.status] || statusConfig['pending'];
            const statusBadge = document.getElementById('statusBadge');
            statusBadge.className = `status-badge ${config.class}`;
            statusBadge.innerHTML = `<i class="${config.icon}"></i><span>${config.text}</span>`;
            document.getElementById('statusText').textContent = config.text;
            document.getElementById('statusDescription').textContent = config.description;
            
            // Update progress
            const progress = Math.round(order.progress || 0);
            document.getElementById('progressPercentage').textContent = progress + '%';
            document.getElementById('progressBar').style.width = progress + '%';
            
            // Update time info
            document.getElementById('elapsedTime').textContent = order.minutes_passed || 0;
            document.getElementById('remainingTime').textContent = Math.max(0, order.remaining_time || 0);
            document.getElementById('totalItems').textContent = order.items ? order.items.length : 0;
            
            // Update total price
            document.getElementById('totalPrice').textContent = formatCurrency(order.total_price);
            
            // Update order items
            updateOrderItems(order.items || []);
            
            // Show/hide cancel button
            const cancelBtn = document.getElementById('cancelBtn');
            if (['pending', 'confirmed'].includes(order.status)) {
                cancelBtn.style.display = 'inline-flex';
            } else {
                cancelBtn.style.display = 'none';
            }
            
            // Show the card
            document.getElementById('queueStatusCard').classList.add('show');
        }
        
        // Update order items
        function updateOrderItems(items) {
            const container = document.getElementById('orderItems');
            container.innerHTML = '';
            
            items.forEach(item => {
                const itemElement = document.createElement('div');
                itemElement.className = 'order-item';
                itemElement.innerHTML = `
                    <div class="item-info">
                        <div class="item-name">${item.product_name}</div>
                        <div class="item-details">จำนวน: ${item.quantity} | ราคา: ${formatCurrency(item.unit_price)}</div>
                    </div>
                    <div class="item-price">${formatCurrency(item.subtotal)}</div>
                `;
                container.appendChild(itemElement);
            });
        }
        
        // Start auto-refresh
        function startAutoRefresh(queueNumber) {
            stopAutoRefresh(); // Clear existing interval
            
            refreshInterval = setInterval(() => {
                loadQueueStatus(queueNumber, true);
            }, 10000); // Refresh every 10 seconds
            
            // Show refresh indicator
            document.getElementById('refreshIndicator').style.display = 'block';
            updateRefreshCounter();
        }
        
        // Stop auto-refresh
        function stopAutoRefresh() {
            if (refreshInterval) {
                clearInterval(refreshInterval);
                refreshInterval = null;
            }
            document.getElementById('refreshIndicator').style.display = 'none';
        }
        
        // Update refresh counter
        function updateRefreshCounter() {
            const refreshCounterInterval = setInterval(() => {
                if (!lastUpdateTime || !refreshInterval) {
                    clearInterval(refreshCounterInterval);
                    return;
                }
                
                const now = new Date();
                const seconds = Math.floor((now - lastUpdateTime) / 1000);
                const text = seconds < 60 ? 
                    `อัปเดต ${seconds} วินาทีที่แล้ว` : 
                    `อัปเดต ${Math.floor(seconds / 60)} นาทีที่แล้ว`;
                
                document.getElementById('refreshText').textContent = text;
            }, 1000);
        }
        
        // Update refresh indicator
        function updateRefreshIndicator(isUpdating) {
            const indicator = document.getElementById('refreshIndicator');
            if (isUpdating) {
                indicator.classList.add('updating');
            } else {
                indicator.classList.remove('updating');
            }
        }
        
        // Refresh status manually
        function refreshStatus() {
            const queueNumber = document.getElementById('queueInput').value.trim().toUpperCase();
            if (queueNumber) {
                loadQueueStatus(queueNumber);
            }
        }
        
        // Show error
        function showError(message) {
            document.getElementById('errorText').textContent = message;
            document.getElementById('errorMessage').style.display = 'block';
        }
        
        // Hide error
        function hideError() {
            document.getElementById('errorMessage').style.display = 'none';
        }
        
        // Hide queue status
        function hideQueueStatus() {
            document.getElementById('queueStatusCard').classList.remove('show');
        }
        
        // Show cancel dialog
        function showCancelDialog() {
            if (!currentOrderId) return;
            
            Swal.fire({
                title: 'ยกเลิกออเดอร์',
                text: 'คุณแน่ใจว่าต้องการยกเลิกออเดอร์นี้?',
                icon: 'warning',
                input: 'textarea',
                inputLabel: 'เหตุผลในการยกเลิก (ไม่บังคับ)',
                inputPlaceholder: 'เช่น เปลี่ยนใจ, รอนานเกินไป',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ยกเลิกออเดอร์',
                cancelButtonText: 'ไม่ยกเลิก',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    cancelOrder(result.value || '');
                }
            });
        }
        
        // Cancel order
        function cancelOrder(reason) {
            Swal.fire({
                title: 'กำลังยกเลิกออเดอร์...',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            $.ajax({
                url: 'api/orders.php',
                type: 'POST',
                data: {
                    action: 'cancel',
                    order_id: currentOrderId,
                    reason: reason
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        Swal.fire({
                            icon: 'success',
                            title: 'ยกเลิกออเดอร์สำเร็จ',
                            text: 'ออเดอร์ของคุณถูกยกเลิกแล้ว',
                            confirmButtonText: 'ตกลง'
                        }).then(() => {
                            // Refresh status
                            const queueNumber = document.getElementById('queueInput').value.trim().toUpperCase();
                            loadQueueStatus(queueNumber);
                        });
                    } else {
                        Swal.fire({
                            icon: 'error',
                            title: 'ไม่สามารถยกเลิกได้',
                            text: response.message || 'เกิดข้อผิดพลาด'
                        });
                    }
                },
                error: function() {
                    Swal.fire({
                        icon: 'error',
                        title: 'เกิดข้อผิดพลาด',
                        text: 'ไม่สามารถเชื่อมต่อเซิร์ฟเวอร์ได้'
                    });
                }
            });
        }
        
        // Format currency
        function formatCurrency(amount) {
            return '฿' + parseFloat(amount).toLocaleString('th-TH', {
                minimumFractionDigits: 2,
                maximumFractionDigits: 2
            });
        }
        
        // Initialize page
        $(document).ready(function() {
            // Auto-uppercase queue input
            $('#queueInput').on('input', function() {
                this.value = this.value.toUpperCase();
            });
            
            // Auto-search if queue number provided in URL
            const initialQueue = '<?php echo clean($queueNumber); ?>';
            if (initialQueue) {
                document.getElementById('queueInput').value = initialQueue;
                loadQueueStatus(initialQueue);
            }
            
            console.log('Queue status page loaded successfully');
        });
        
        // Cleanup on page unload
        $(window).on('beforeunload', function() {
            stopAutoRefresh();
        });
    </script>
</body>
</html>