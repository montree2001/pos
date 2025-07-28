<?php
/**
 * หน้าหลักลูกค้า
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

$pageTitle = 'สั่งอาหารออนไลน์';
$pageDescription = 'สั่งอาหารง่ายๆ ผ่านระบบออนไลน์ ตรวจสอบคิวได้แบบ Real-time';

// ดึงข้อมูลหมวดหมู่และสินค้าแนะนำ
try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // หมวดหมู่สินค้า
    $stmt = $conn->prepare("
        SELECT * FROM categories 
        WHERE status = 'active' 
        ORDER BY display_order ASC, name ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    // สินค้าแนะนำ (ขายดี)
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name, 
               COALESCE(SUM(oi.quantity), 0) as total_sold
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        LEFT JOIN order_items oi ON p.product_id = oi.product_id
        LEFT JOIN orders o ON oi.order_id = o.order_id 
            AND o.payment_status = 'paid' 
            AND o.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
        WHERE p.is_available = 1
        GROUP BY p.product_id
        ORDER BY total_sold DESC, p.name ASC
        LIMIT 6
    ");
    $stmt->execute();
    $featuredProducts = $stmt->fetchAll();
    
    // สถิติร้าน
    $stmt = $conn->prepare("
        SELECT 
            COUNT(DISTINCT o.order_id) as total_orders,
            COUNT(DISTINCT p.product_id) as total_products,
            COALESCE(AVG(TIMESTAMPDIFF(MINUTE, o.created_at, o.updated_at)), 15) as avg_time
        FROM orders o
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        LEFT JOIN products p ON oi.product_id = p.product_id
        WHERE o.status = 'completed' 
        AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ");
    $stmt->execute();
    $shopStats = $stmt->fetch();
    
} catch (Exception $e) {
    writeLog("Customer index error: " . $e->getMessage());
    $categories = [];
    $featuredProducts = [];
    $shopStats = ['total_orders' => 0, 'total_products' => 0, 'avg_time' => 15];
}

// นับจำนวนในตะกร้า
$cartCount = getCartItemCount();
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
            --primary-dark: #3730a3;
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
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            line-height: 1.6;
            min-height: 100vh;
        }
        
        /* Header */
        .hero-section {
            background: linear-gradient(135deg, rgba(79, 70, 229, 0.9), rgba(99, 102, 241, 0.9));
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .hero-content h1 {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 1rem;
        }
        
        .hero-stats {
            display: flex;
            gap: 2rem;
            margin-top: 2rem;
        }
        
        .stat-item {
            text-align: center;
            opacity: 0.9;
        }
        
        .stat-number {
            font-size: 1.5rem;
            font-weight: bold;
            display: block;
        }
        
        .stat-label {
            font-size: 0.875rem;
            opacity: 0.8;
        }
        
        /* Navigation Bar */
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
        
        .cart-btn {
            position: relative;
            background: linear-gradient(135deg, var(--secondary-color), #059669);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 12px 24px;
            transition: var(--transition);
        }
        
        .cart-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
            color: white;
        }
        
        .cart-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--danger-color);
            color: white;
            border-radius: 50%;
            width: 24px;
            height: 24px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.75rem;
            font-weight: bold;
        }
        
        /* Main Content */
        .main-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin: 0 1rem 2rem;
            overflow: hidden;
        }
        
        /* Categories Section */
        .section-header {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            padding: 1.5rem 2rem;
        }
        
        .section-title {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0;
        }
        
        .categories-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 1.5rem;
            padding: 2rem;
        }
        
        .category-card {
            background: var(--white);
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            padding: 2rem;
            text-align: center;
            transition: var(--transition);
            cursor: pointer;
            text-decoration: none;
            color: var(--text-color);
        }
        
        .category-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-4px);
            box-shadow: var(--box-shadow);
            color: var(--text-color);
        }
        
        .category-icon {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .category-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .category-description {
            color: var(--text-muted);
            font-size: 0.875rem;
        }
        
        /* Featured Products */
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.5rem;
            padding: 2rem;
        }
        
        .product-card {
            background: var(--white);
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
        }
        
        .product-card:hover {
            border-color: var(--secondary-color);
            transform: translateY(-4px);
            box-shadow: var(--box-shadow);
        }
        
        .product-image {
            width: 100%;
            height: 200px;
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            color: var(--text-muted);
        }
        
        .product-content {
            padding: 1.5rem;
        }
        
        .product-name {
            font-size: 1.125rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .product-price {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--secondary-color);
            margin-bottom: 1rem;
        }
        
        .product-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .add-to-cart-btn {
            width: 100%;
            background: linear-gradient(135deg, var(--secondary-color), #059669);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-weight: 500;
            transition: var(--transition);
        }
        
        .add-to-cart-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        /* Quick Actions */
        .quick-actions {
            background: var(--light-bg);
            padding: 2rem;
            text-align: center;
        }
        
        .action-buttons {
            display: flex;
            gap: 1rem;
            justify-content: center;
            flex-wrap: wrap;
            margin-top: 1.5rem;
        }
        
        .action-btn {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            border: none;
            border-radius: 50px;
            padding: 14px 28px;
            font-weight: 500;
            transition: var(--transition);
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .action-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.3);
            color: white;
        }
        
        .action-btn.secondary {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
        }
        
        .action-btn.secondary:hover {
            box-shadow: 0 8px 25px rgba(245, 158, 11, 0.3);
        }
        
        /* Chatbot Button */
        .chatbot-btn {
            position: fixed;
            bottom: 2rem;
            right: 2rem;
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            border: none;
            border-radius: 50%;
            width: 60px;
            height: 60px;
            font-size: 1.5rem;
            box-shadow: 0 4px 20px rgba(79, 70, 229, 0.3);
            transition: var(--transition);
            z-index: 1000;
        }
        
        .chatbot-btn:hover {
            transform: scale(1.1);
            box-shadow: 0 8px 30px rgba(79, 70, 229, 0.4);
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .hero-content h1 {
                font-size: 2rem;
            }
            
            .hero-stats {
                flex-direction: column;
                gap: 1rem;
                text-align: center;
            }
            
            .categories-grid, .products-grid {
                grid-template-columns: 1fr;
                padding: 1rem;
            }
            
            .main-container {
                margin: 0 0.5rem 1rem;
            }
            
            .section-header {
                padding: 1rem;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: center;
            }
            
            .chatbot-btn {
                bottom: 1rem;
                right: 1rem;
                width: 50px;
                height: 50px;
                font-size: 1.25rem;
            }
        }
        
        /* Loading Animation */
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
        
        /* Pulse Effect */
        .pulse-on-hover:hover {
            animation: pulse 0.5s;
        }
        
        @keyframes pulse {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); }
            100% { transform: scale(1); }
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-store me-2"></i>
                <?php echo SITE_NAME; ?>
            </a>
            
            <div class="d-flex align-items-center gap-3">
                <a href="queue_status.php" class="btn btn-outline-primary">
                    <i class="fas fa-clock me-2"></i>
                    <span class="d-none d-sm-inline">ตรวจสอบคิว</span>
                </a>
                
                <a href="cart.php" class="btn cart-btn">
                    <i class="fas fa-shopping-cart me-2"></i>
                    <span class="d-none d-sm-inline">ตะกร้า</span>
                    <?php if ($cartCount > 0): ?>
                        <span class="cart-badge"><?php echo $cartCount; ?></span>
                    <?php endif; ?>
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Hero Section -->
    <section class="hero-section">
        <div class="container">
            <div class="hero-content text-center animate__animated animate__fadeInUp">
                <h1>สั่งอาหารง่ายๆ ผ่านออนไลน์</h1>
                <p class="lead mb-0">เลือกอาหารโปรด ชำระเงินสะดวก ตรวจสอบคิวแบบ Real-time</p>
                
                <div class="hero-stats justify-content-center">
                    <div class="stat-item">
                        <span class="stat-number"><?php echo number_format($shopStats['total_orders']); ?>+</span>
                        <span class="stat-label">ออเดอร์ที่เสิร์ฟ</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number"><?php echo number_format($shopStats['total_products']); ?>+</span>
                        <span class="stat-label">เมนูให้เลือก</span>
                    </div>
                    <div class="stat-item">
                        <span class="stat-number">~<?php echo ceil($shopStats['avg_time']); ?></span>
                        <span class="stat-label">นาทีเฉลี่ย</span>
                    </div>
                </div>
            </div>
        </div>
    </section>
    
    <!-- Main Content -->
    <div class="container">
        <div class="main-container animate__animated animate__fadeInUp">
            
            <!-- Categories Section -->
            <div class="section-header">
                <h2 class="section-title">
                    <i class="fas fa-th-large me-2"></i>
                    หมวดหมู่สินค้า
                </h2>
            </div>
            
            <div class="categories-grid">
                <?php if (!empty($categories)): ?>
                    <?php 
                    $categoryIcons = [
                        'อาหารจานเดียว' => 'fa-bowl-food',
                        'ก๋วยเตี๋ยว' => 'fa-bowl-rice',
                        'ข้าวราดแกง' => 'fa-pepper-hot',
                        'เครื่องดื่ม' => 'fa-mug-hot',
                        'ของหวาน' => 'fa-ice-cream'
                    ];
                    ?>
                    <?php foreach ($categories as $category): ?>
                        <a href="menu.php?category=<?php echo $category['category_id']; ?>" 
                           class="category-card pulse-on-hover">
                            <div class="category-icon">
                                <i class="fas <?php echo $categoryIcons[$category['name']] ?? 'fa-utensils'; ?>"></i>
                            </div>
                            <div class="category-name"><?php echo clean($category['name']); ?></div>
                            <div class="category-description">
                                <?php echo clean($category['description']) ?: 'ดูเมนูทั้งหมดในหมวดนี้'; ?>
                            </div>
                        </a>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center py-5">
                        <i class="fas fa-box-open fa-3x text-muted mb-3"></i>
                        <p class="text-muted">ยังไม่มีหมวดหมู่สินค้า</p>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Featured Products -->
            <?php if (!empty($featuredProducts)): ?>
                <div class="section-header">
                    <h2 class="section-title">
                        <i class="fas fa-star me-2"></i>
                        เมนูแนะนำ
                    </h2>
                </div>
                
                <div class="products-grid">
                    <?php foreach ($featuredProducts as $product): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <?php if ($product['image']): ?>
                                    <img src="<?php echo SITE_URL . '/uploads/menu_images/' . $product['image']; ?>" 
                                         alt="<?php echo clean($product['name']); ?>" 
                                         class="img-fluid">
                                <?php else: ?>
                                    <i class="fas fa-utensils"></i>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-content">
                                <div class="product-name"><?php echo clean($product['name']); ?></div>
                                <div class="product-price"><?php echo formatCurrency($product['price']); ?></div>
                                
                                <div class="product-meta">
                                    <span>
                                        <i class="fas fa-tag me-1"></i>
                                        <?php echo clean($product['category_name']); ?>
                                    </span>
                                    <span>
                                        <i class="fas fa-clock me-1"></i>
                                        ~<?php echo $product['preparation_time']; ?> นาที
                                    </span>
                                </div>
                                
                                <?php if ($product['description']): ?>
                                    <p class="text-muted small mb-3">
                                        <?php echo clean($product['description']); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <button class="add-to-cart-btn" 
                                        onclick="addToCart(<?php echo $product['product_id']; ?>, '<?php echo clean($product['name']); ?>', <?php echo $product['price']; ?>)">
                                    <i class="fas fa-plus me-2"></i>
                                    เพิ่มลงตะกร้า
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <!-- Quick Actions -->
            <div class="quick-actions">
                <h3>เริ่มต้นสั่งอาหาร</h3>
                <p class="text-muted">เลือกวิธีที่สะดวกสำหรับคุณ</p>
                
                <div class="action-buttons">
                    <a href="menu.php" class="action-btn">
                        <i class="fas fa-utensils"></i>
                        ดูเมนูทั้งหมด
                    </a>
                    
                    <a href="chatbot.php" class="action-btn secondary">
                        <i class="fas fa-robot"></i>
                        สั่งผ่าน AI Bot
                    </a>
                    
                    <?php if ($cartCount > 0): ?>
                        <a href="cart.php" class="action-btn" style="background: linear-gradient(135deg, var(--secondary-color), #059669);">
                            <i class="fas fa-shopping-cart"></i>
                            ไปที่ตะกร้า (<?php echo $cartCount; ?>)
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Chatbot Button -->
    <button class="chatbot-btn" onclick="openChatbot()" title="แชทกับ AI">
        <i class="fas fa-comments"></i>
    </button>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        // Global variables
        const SITE_URL = '<?php echo SITE_URL; ?>';
        
        // Add to cart function
        function addToCart(productId, productName, price) {
            const btn = event.target;
            const originalText = btn.innerHTML;
            
            // Show loading
            btn.innerHTML = '<span class="loading-spinner"></span> กำลังเพิ่ม...';
            btn.disabled = true;
            
            $.ajax({
                url: 'api/cart.php',
                type: 'POST',
                data: {
                    action: 'add',
                    product_id: productId,
                    quantity: 1
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        // Success animation
                        btn.innerHTML = '<i class="fas fa-check me-2"></i>เพิ่มแล้ว!';
                        btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                        
                        // Update cart count
                        updateCartCount(response.cart_count);
                        
                        // Show toast
                        showToast('success', `เพิ่ม "${productName}" ลงตะกร้าแล้ว!`);
                        
                        // Reset button after 2 seconds
                        setTimeout(() => {
                            btn.innerHTML = originalText;
                            btn.disabled = false;
                            btn.style.background = '';
                        }, 2000);
                        
                    } else {
                        throw new Error(response.message || 'เกิดข้อผิดพลาด');
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Add to cart error:', error);
                    showToast('error', 'ไม่สามารถเพิ่มสินค้าได้ กรุณาลองใหม่');
                    
                    // Reset button
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                }
            });
        }
        
        // Update cart count in UI
        function updateCartCount(count) {
            const badge = $('.cart-badge');
            if (count > 0) {
                if (badge.length === 0) {
                    $('.cart-btn').append(`<span class="cart-badge">${count}</span>`);
                } else {
                    badge.text(count);
                }
                badge.addClass('animate__animated animate__pulse');
            } else {
                badge.remove();
            }
        }
        
        // Show toast notification
        function showToast(type, message) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true,
                didOpen: (toast) => {
                    toast.addEventListener('mouseenter', Swal.stopTimer)
                    toast.addEventListener('mouseleave', Swal.resumeTimer)
                }
            });
            
            Toast.fire({
                icon: type,
                title: message
            });
        }
        
        // Open chatbot
        function openChatbot() {
            window.location.href = 'chatbot.php';
        }
        
        // Initialize page
        $(document).ready(function() {
            // Add entrance animations with delay
            $('.category-card, .product-card').each(function(index) {
                $(this).css('animation-delay', (index * 0.1) + 's');
                $(this).addClass('animate__animated animate__fadeInUp');
            });
            
            // Load cart count on page load
            loadCartCount();
        });
        
        // Load current cart count
        function loadCartCount() {
            $.get('api/cart.php?action=count', function(response) {
                if (response.success) {
                    updateCartCount(response.count);
                }
            }).fail(function() {
                console.warn('Failed to load cart count');
            });
        }
        
        // Auto-refresh cart count every 30 seconds (in case of multiple tabs)
        setInterval(loadCartCount, 30000);
        
        console.log('Customer homepage loaded successfully');
    </script>
</body>
</html>