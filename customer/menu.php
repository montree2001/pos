<?php
/**
 * หน้าเมนูอาหาร
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

$pageTitle = 'เมนูอาหาร';
$pageDescription = 'เลือกอาหารโปรดจากเมนูที่หลากหลาย';

// รับ parameters
$selectedCategory = intval($_GET['category'] ?? 0);
$searchQuery = trim($_GET['search'] ?? '');
$sortBy = $_GET['sort'] ?? 'name';
$priceRange = $_GET['price_range'] ?? '';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงหมวดหมู่ทั้งหมด
    $stmt = $conn->prepare("
        SELECT c.*, COUNT(p.product_id) as product_count
        FROM categories c
        LEFT JOIN products p ON c.category_id = p.category_id AND p.is_available = 1
        WHERE c.status = 'active'
        GROUP BY c.category_id
        ORDER BY c.display_order ASC, c.name ASC
    ");
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    // สร้าง WHERE clause สำหรับการกรอง
    $whereConditions = ["p.is_available = 1"];
    $params = [];
    
    if ($selectedCategory > 0) {
        $whereConditions[] = "p.category_id = ?";
        $params[] = $selectedCategory;
    }
    
    if (!empty($searchQuery)) {
        $whereConditions[] = "(p.name LIKE ? OR p.description LIKE ?)";
        $params[] = "%$searchQuery%";
        $params[] = "%$searchQuery%";
    }
    
    if (!empty($priceRange)) {
        switch ($priceRange) {
            case 'under_50':
                $whereConditions[] = "p.price < 50";
                break;
            case '50_100':
                $whereConditions[] = "p.price BETWEEN 50 AND 100";
                break;
            case '100_200':
                $whereConditions[] = "p.price BETWEEN 100 AND 200";
                break;
            case 'over_200':
                $whereConditions[] = "p.price > 200";
                break;
        }
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // สร้าง ORDER BY clause
    $orderBy = "p.name ASC";
    switch ($sortBy) {
        case 'price_low':
            $orderBy = "p.price ASC";
            break;
        case 'price_high':
            $orderBy = "p.price DESC";
            break;
        case 'popular':
            $orderBy = "total_sold DESC, p.name ASC";
            break;
        case 'newest':
            $orderBy = "p.created_at DESC";
            break;
    }
    
    // ดึงสินค้าทั้งหมด
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name,
               COALESCE(SUM(oi.quantity), 0) as total_sold,
               COUNT(DISTINCT po.option_id) as options_count
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        LEFT JOIN order_items oi ON p.product_id = oi.product_id
        LEFT JOIN orders o ON oi.order_id = o.order_id 
            AND o.payment_status = 'paid' 
            AND o.created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        LEFT JOIN product_options po ON p.product_id = po.product_id
        WHERE $whereClause
        GROUP BY p.product_id
        ORDER BY $orderBy
    ");
    $stmt->execute($params);
    $products = $stmt->fetchAll();
    
    // ดึงตัวเลือกสินค้า (สำหรับสินค้าที่มีตัวเลือก)
    $productOptions = [];
    if (!empty($products)) {
        $productIds = array_column($products, 'product_id');
        $placeholders = str_repeat('?,', count($productIds) - 1) . '?';
        
        $stmt = $conn->prepare("
            SELECT product_id, option_id, name, price_adjustment
            FROM product_options 
            WHERE product_id IN ($placeholders)
            ORDER BY option_id ASC
        ");
        $stmt->execute($productIds);
        $options = $stmt->fetchAll();
        
        foreach ($options as $option) {
            $productOptions[$option['product_id']][] = $option;
        }
    }
    
} catch (Exception $e) {
    writeLog("Menu page error: " . $e->getMessage());
    $categories = [];
    $products = [];
    $productOptions = [];
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
            background: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            line-height: 1.6;
        }
        
        /* Header */
        .header-section {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
        }
        
        .navbar-custom {
            background: var(--white);
            box-shadow: var(--box-shadow);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
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
        
        /* Filters */
        .filters-section {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            margin-bottom: 2rem;
        }
        
        .filter-tabs {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 2rem;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            background: var(--light-bg);
            border: 2px solid var(--border-color);
            border-radius: 50px;
            padding: 8px 16px;
            color: var(--text-color);
            text-decoration: none;
            transition: var(--transition);
            font-size: 0.875rem;
            font-weight: 500;
        }
        
        .filter-tab:hover, .filter-tab.active {
            background: var(--primary-color);
            border-color: var(--primary-color);
            color: white;
            transform: translateY(-2px);
        }
        
        .search-controls {
            display: grid;
            grid-template-columns: 1fr auto auto;
            gap: 1rem;
            align-items: end;
        }
        
        .search-input {
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 16px;
            transition: var(--transition);
        }
        
        .search-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
        }
        
        .filter-select {
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 12px 16px;
            min-width: 150px;
        }
        
        /* Products Grid */
        .products-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
        }
        
        .products-header {
            display: flex;
            justify-content: between;
            align-items: center;
            margin-bottom: 2rem;
            flex-wrap: wrap;
            gap: 1rem;
        }
        
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(320px, 1fr));
            gap: 2rem;
        }
        
        .product-card {
            background: var(--white);
            border: 2px solid var(--border-color);
            border-radius: var(--border-radius);
            overflow: hidden;
            transition: var(--transition);
            position: relative;
        }
        
        .product-card:hover {
            border-color: var(--secondary-color);
            transform: translateY(-4px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.12);
        }
        
        .product-image {
            width: 100%;
            height: 220px;
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 4rem;
            color: var(--text-muted);
            position: relative;
            overflow: hidden;
        }
        
        .product-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }
        
        .product-card:hover .product-image img {
            transform: scale(1.05);
        }
        
        .product-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            background: var(--warning-color);
            color: white;
            padding: 4px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: bold;
        }
        
        .product-badge.popular {
            background: var(--danger-color);
        }
        
        .product-content {
            padding: 1.5rem;
        }
        
        .product-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 1rem;
        }
        
        .product-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin: 0;
            line-height: 1.3;
        }
        
        .product-price {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--secondary-color);
            white-space: nowrap;
        }
        
        .product-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 1rem;
        }
        
        .product-description {
            color: var(--text-muted);
            font-size: 0.875rem;
            margin-bottom: 1rem;
            line-height: 1.5;
        }
        
        .product-options {
            margin-bottom: 1rem;
        }
        
        .options-title {
            font-size: 0.875rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .option-pills {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        
        .option-pill {
            background: var(--light-bg);
            border: 1px solid var(--border-color);
            border-radius: 20px;
            padding: 4px 12px;
            font-size: 0.75rem;
            color: var(--text-muted);
        }
        
        .product-actions {
            display: flex;
            gap: 0.5rem;
        }
        
        .quantity-controls {
            display: flex;
            align-items: center;
            border: 2px solid var(--border-color);
            border-radius: 8px;
            overflow: hidden;
        }
        
        .qty-btn {
            background: var(--light-bg);
            border: none;
            width: 36px;
            height: 36px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
        }
        
        .qty-btn:hover {
            background: var(--primary-color);
            color: white;
        }
        
        .qty-input {
            border: none;
            width: 50px;
            text-align: center;
            font-weight: 600;
            padding: 8px 4px;
        }
        
        .add-to-cart-btn {
            flex: 1;
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
        
        .add-to-cart-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
        }
        
        /* Empty State */
        .empty-state {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }
        
        .empty-state i {
            font-size: 4rem;
            margin-bottom: 1rem;
            opacity: 0.5;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .search-controls {
                grid-template-columns: 1fr;
                gap: 1rem;
            }
            
            .products-grid {
                grid-template-columns: 1fr;
                gap: 1.5rem;
            }
            
            .filter-tabs {
                justify-content: center;
            }
            
            .products-header {
                flex-direction: column;
                align-items: stretch;
                text-align: center;
            }
            
            .filters-section, .products-container {
                margin-left: 1rem;
                margin-right: 1rem;
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
                <a href="queue_status.php" class="btn btn-outline-primary">
                    <i class="fas fa-clock me-2"></i>
                    <span class="d-none d-sm-inline">คิว</span>
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
    
    <!-- Header -->
    <section class="header-section">
        <div class="container text-center">
            <h1 class="mb-2">เมนูอาหาร</h1>
            <p class="lead mb-0">เลือกอาหารโปรดจากเมนูที่หลากหลาย</p>
        </div>
    </section>
    
    <div class="container">
        <!-- Filters -->
        <div class="filters-section animate__animated animate__fadeInUp">
            <!-- Category Tabs -->
            <div class="filter-tabs">
                <a href="menu.php" class="filter-tab <?php echo $selectedCategory == 0 ? 'active' : ''; ?>">
                    <i class="fas fa-th-large me-1"></i>
                    ทั้งหมด
                </a>
                <?php foreach ($categories as $category): ?>
                    <a href="menu.php?category=<?php echo $category['category_id']; ?>" 
                       class="filter-tab <?php echo $selectedCategory == $category['category_id'] ? 'active' : ''; ?>">
                        <?php echo clean($category['name']); ?>
                        <span class="badge bg-secondary ms-1"><?php echo $category['product_count']; ?></span>
                    </a>
                <?php endforeach; ?>
            </div>
            
            <!-- Search and Sort Controls -->
            <form method="GET" class="search-controls">
                <div>
                    <label class="form-label">ค้นหาเมนู</label>
                    <input type="text" name="search" class="form-control search-input" 
                           placeholder="ชื่อเมนูหรือคำอธิบาย..." 
                           value="<?php echo clean($searchQuery); ?>">
                </div>
                
                <div>
                    <label class="form-label">ช่วงราคา</label>
                    <select name="price_range" class="form-select filter-select">
                        <option value="">ทุกราคา</option>
                        <option value="under_50" <?php echo $priceRange == 'under_50' ? 'selected' : ''; ?>>ต่ำกว่า ฿50</option>
                        <option value="50_100" <?php echo $priceRange == '50_100' ? 'selected' : ''; ?>>฿50 - ฿100</option>
                        <option value="100_200" <?php echo $priceRange == '100_200' ? 'selected' : ''; ?>>฿100 - ฿200</option>
                        <option value="over_200" <?php echo $priceRange == 'over_200' ? 'selected' : ''; ?>>มากกว่า ฿200</option>
                    </select>
                </div>
                
                <div>
                    <label class="form-label">เรียงตาม</label>
                    <select name="sort" class="form-select filter-select">
                        <option value="name" <?php echo $sortBy == 'name' ? 'selected' : ''; ?>>ชื่อ A-Z</option>
                        <option value="price_low" <?php echo $sortBy == 'price_low' ? 'selected' : ''; ?>>ราคาต่ำ-สูง</option>
                        <option value="price_high" <?php echo $sortBy == 'price_high' ? 'selected' : ''; ?>>ราคาสูง-ต่ำ</option>
                        <option value="popular" <?php echo $sortBy == 'popular' ? 'selected' : ''; ?>>ขายดี</option>
                        <option value="newest" <?php echo $sortBy == 'newest' ? 'selected' : ''; ?>>ใหม่ล่าสุด</option>
                    </select>
                </div>
                
                <?php if ($selectedCategory > 0): ?>
                    <input type="hidden" name="category" value="<?php echo $selectedCategory; ?>">
                <?php endif; ?>
                
                <div class="d-flex gap-2">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i>
                    </button>
                    <a href="menu.php" class="btn btn-outline-secondary">
                        <i class="fas fa-times"></i>
                    </a>
                </div>
            </form>
        </div>
        
        <!-- Products -->
        <div class="products-container animate__animated animate__fadeInUp" style="animation-delay: 0.2s">
            <div class="products-header">
                <div>
                    <h3 class="mb-1">
                        <?php if ($selectedCategory > 0): ?>
                            <?php 
                            $selectedCat = array_filter($categories, function($cat) use ($selectedCategory) {
                                return $cat['category_id'] == $selectedCategory;
                            });
                            $selectedCat = reset($selectedCat);
                            echo clean($selectedCat['name'] ?? 'เมนูอาหาร');
                            ?>
                        <?php else: ?>
                            เมนูอาหารทั้งหมด
                        <?php endif; ?>
                    </h3>
                    <p class="text-muted mb-0">พบ <?php echo count($products); ?> รายการ</p>
                </div>
                
                <?php if (!empty($searchQuery)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-search me-2"></i>
                        ผลการค้นหา: "<?php echo clean($searchQuery); ?>"
                    </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($products)): ?>
                <div class="products-grid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-card">
                            <div class="product-image">
                                <?php if ($product['image']): ?>
                                    <img src="<?php echo SITE_URL . '/uploads/menu_images/' . $product['image']; ?>" 
                                         alt="<?php echo clean($product['name']); ?>">
                                <?php else: ?>
                                    <i class="fas fa-utensils"></i>
                                <?php endif; ?>
                                
                                <?php if ($product['total_sold'] > 10): ?>
                                    <div class="product-badge popular">ขายดี</div>
                                <?php elseif (strtotime($product['created_at']) > strtotime('-7 days')): ?>
                                    <div class="product-badge">ใหม่</div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="product-content">
                                <div class="product-header">
                                    <h4 class="product-name"><?php echo clean($product['name']); ?></h4>
                                    <div class="product-price"><?php echo formatCurrency($product['price']); ?></div>
                                </div>
                                
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
                                    <p class="product-description">
                                        <?php echo clean($product['description']); ?>
                                    </p>
                                <?php endif; ?>
                                
                                <?php if (!empty($productOptions[$product['product_id']])): ?>
                                    <div class="product-options">
                                        <div class="options-title">ตัวเลือก:</div>
                                        <div class="option-pills">
                                            <?php foreach ($productOptions[$product['product_id']] as $option): ?>
                                                <span class="option-pill">
                                                    <?php echo clean($option['name']); ?>
                                                    <?php if ($option['price_adjustment'] != 0): ?>
                                                        <?php echo $option['price_adjustment'] > 0 ? '+' : ''; ?>
                                                        <?php echo formatCurrency($option['price_adjustment']); ?>
                                                    <?php endif; ?>
                                                </span>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="product-actions">
                                    <div class="quantity-controls">
                                        <button type="button" class="qty-btn" onclick="changeQuantity(<?php echo $product['product_id']; ?>, -1)">
                                            <i class="fas fa-minus"></i>
                                        </button>
                                        <input type="number" class="qty-input" id="qty-<?php echo $product['product_id']; ?>" 
                                               value="1" min="1" max="99">
                                        <button type="button" class="qty-btn" onclick="changeQuantity(<?php echo $product['product_id']; ?>, 1)">
                                            <i class="fas fa-plus"></i>
                                        </button>
                                    </div>
                                    
                                    <button class="add-to-cart-btn" 
                                            onclick="addToCart(<?php echo $product['product_id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['price']; ?>)">
                                        <i class="fas fa-cart-plus me-2"></i>
                                        เพิ่มลงตะกร้า
                                    </button>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php else: ?>
                <div class="empty-state">
                    <i class="fas fa-search"></i>
                    <h4>ไม่พบเมนูที่ตรงกับการค้นหา</h4>
                    <p>ลองเปลี่ยนคำค้นหาหรือตัวกรองดู</p>
                    <a href="menu.php" class="btn btn-primary">
                        <i class="fas fa-refresh me-2"></i>
                        ดูเมนูทั้งหมด
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const SITE_URL = '<?php echo SITE_URL; ?>';
        
        // Change quantity
        function changeQuantity(productId, change) {
            const input = document.getElementById(`qty-${productId}`);
            let newValue = parseInt(input.value) + change;
            
            if (newValue < 1) newValue = 1;
            if (newValue > 99) newValue = 99;
            
            input.value = newValue;
        }
        
        // Add to cart
        function addToCart(productId, productName, price) {
            const btn = event.target;
            const qtyInput = document.getElementById(`qty-${productId}`);
            const quantity = parseInt(qtyInput.value);
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
                    quantity: quantity
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
                        showToast('success', `เพิ่ม "${productName}" ${quantity} ชิ้น ลงตะกร้าแล้ว!`);
                        
                        // Reset quantity to 1
                        qtyInput.value = 1;
                        
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
        
        // Update cart count
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
        
        // Show toast
        function showToast(type, message) {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });
            
            Toast.fire({
                icon: type,
                title: message
            });
        }
        
        // Initialize page
        $(document).ready(function() {
            // Load cart count
            loadCartCount();
            
            // Add stagger animation to product cards
            $('.product-card').each(function(index) {
                $(this).css('animation-delay', (index * 0.1) + 's');
                $(this).addClass('animate__animated animate__fadeInUp');
            });
        });
        
        // Load cart count
        function loadCartCount() {
            $.get('api/cart.php?action=count', function(response) {
                if (response.success) {
                    updateCartCount(response.count);
                }
            }).fail(function() {
                console.warn('Failed to load cart count');
            });
        }
        
        console.log('Menu page loaded successfully');




        // ========================================
// ฟังก์ชันจัดการตะกร้าสินค้า - แก้ไขปัญหา
// ========================================

// Global variables
let cartUpdateInProgress = false;

// ปรับปรุงฟังก์ชัน addToCart
function addToCart(productId, productName, price) {
    // ป้องกันการคลิกซ้ำ
    if (cartUpdateInProgress) return;
    
    const btn = event.target;
    const originalText = btn.innerHTML;
    
    // ตรวจสอบข้อมูล
    if (!productId || !productName) {
        showToast('error', 'ข้อมูลสินค้าไม่ถูกต้อง');
        return;
    }
    
    cartUpdateInProgress = true;
    
    // แสดง loading
    btn.innerHTML = '<span class="loading-spinner"></span> กำลังเพิ่ม...';
    btn.disabled = true;
    
    // ส่งข้อมูลไป API
    $.ajax({
        url: 'api/cart.php', // แก้ไขเส้นทาง
        type: 'POST',
        timeout: 10000, // timeout 10 วินาที
        data: {
            action: 'add',
            product_id: productId,
            quantity: 1
        },
        dataType: 'json',
        success: function(response) {
            console.log('Cart response:', response); // Debug
            
            if (response.success) {
                // แสดงผลสำเร็จ
                btn.innerHTML = '<i class="fas fa-check me-2"></i>เพิ่มแล้ว!';
                btn.style.background = 'linear-gradient(135deg, #10b981, #059669)';
                
                // อัปเดตจำนวนในตะกร้า
                updateCartCount(response.cart_count);
                
                // แสดง toast notification
                showToast('success', `เพิ่ม "${productName}" ลงตะกร้าแล้ว!`);
                
                // รีเซ็ตปุ่มหลัง 2 วินาที
                setTimeout(() => {
                    btn.innerHTML = originalText;
                    btn.disabled = false;
                    btn.style.background = '';
                    cartUpdateInProgress = false;
                }, 2000);
                
            } else {
                throw new Error(response.message || 'เกิดข้อผิดพลาดไม่ทราบสาเหตุ');
            }
        },
        error: function(xhr, status, error) {
            console.error('Cart Error Details:', {
                status: xhr.status,
                statusText: xhr.statusText,
                responseText: xhr.responseText,
                error: error
            });
            
            let errorMessage = 'ไม่สามารถเพิ่มสินค้าได้';
            
            // แสดงข้อผิดพลาดตามสถานะ
            if (xhr.status === 404) {
                errorMessage = 'ไม่พบไฟล์ API กรุณาตรวจสอบการติดตั้ง';
            } else if (xhr.status === 500) {
                errorMessage = 'เกิดข้อผิดพลาดภายในเซิร์ฟเวอร์';
            } else if (status === 'timeout') {
                errorMessage = 'การเชื่อมต่อหมดเวลา กรุณาลองใหม่';
            } else if (xhr.responseText) {
                try {
                    const response = JSON.parse(xhr.responseText);
                    errorMessage = response.message || errorMessage;
                } catch (e) {
                    // ไม่สามารถ parse JSON ได้
                }
            }
            
            showToast('error', errorMessage);
            
            // รีเซ็ตปุ่ม
            btn.innerHTML = originalText;
            btn.disabled = false;
            cartUpdateInProgress = false;
        }
    });
}

// อัปเดตจำนวนสินค้าในตะกร้า
function updateCartCount(count) {
    const badge = $('#cartBadge');
    const cartBtn = $('.cart-btn, .header-btn[title="ตะกร้าสินค้า"]');
    
    if (count > 0) {
        badge.text(count).show();
        cartBtn.addClass('has-items');
        
        // อนิเมชัน bounce
        badge.addClass('animate__animated animate__bounce');
        setTimeout(() => {
            badge.removeClass('animate__animated animate__bounce');
        }, 1000);
    } else {
        badge.hide();
        cartBtn.removeClass('has-items');
    }
}

// โหลดจำนวนสินค้าในตะกร้าเมื่อเริ่มต้น
function loadCartCount() {
    $.ajax({
        url: 'api/cart.php',
        type: 'GET',
        data: { action: 'count' },
        dataType: 'json',
        success: function(response) {
            if (response.success) {
                updateCartCount(response.count);
            }
        },
        error: function(xhr, status, error) {
            console.log('Load cart count error:', error);
            // ไม่แสดง error สำหรับการโหลดจำนวน
        }
    });
}

// แสดง Toast Notification
function showToast(type, message, duration = 3000) {
    // ลบ toast เก่า (ถ้ามี)
    $('.toast-notification').remove();
    
    const toastHtml = `
        <div class="toast-notification toast-${type}">
            <div class="toast-content">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-circle'}"></i>
                <span>${message}</span>
            </div>
            <button class="toast-close" onclick="$(this).parent().remove()">
                <i class="fas fa-times"></i>
            </button>
        </div>
    `;
    
    $('body').append(toastHtml);
    
    // แสดง toast
    const toast = $('.toast-notification');
    setTimeout(() => {
        toast.addClass('show');
    }, 100);
    
    // ซ่อน toast หลังเวลาที่กำหนด
    setTimeout(() => {
        toast.removeClass('show');
        setTimeout(() => {
            toast.remove();
        }, 300);
    }, duration);
}

// ฟังก์ชันเปิด Chatbot
function openChatbot() {
    window.open('chatbot.php', '_blank', 'width=400,height=600,scrollbars=yes,resizable=yes');
}

// เมื่อ Document พร้อม
$(document).ready(function() {
    // โหลดจำนวนสินค้าในตะกร้า
    loadCartCount();
    
    // ตั้งค่า AJAX global error handler
    $(document).ajaxError(function(event, xhr, settings, error) {
        if (settings.url && settings.url.includes('cart.php')) {
            console.error('Global AJAX Error for cart:', {
                url: settings.url,
                status: xhr.status,
                error: error
            });
        }
    });
    
    // เพิ่ม CSS สำหรับ animations
    if (!$('#cart-animations').length) {
        $('head').append(`
            <style id="cart-animations">
                .loading-spinner {
                    display: inline-block;
                    width: 12px;
                    height: 12px;
                    border: 2px solid rgba(255,255,255,0.3);
                    border-top: 2px solid white;
                    border-radius: 50%;
                    animation: spin 1s linear infinite;
                }
                
                @keyframes spin {
                    0% { transform: rotate(0deg); }
                    100% { transform: rotate(360deg); }
                }
                
                .cart-badge {
                    background: #e74c3c;
                    color: white;
                    border-radius: 50%;
                    padding: 2px 6px;
                    font-size: 12px;
                    position: absolute;
                    top: -8px;
                    right: -8px;
                    min-width: 18px;
                    text-align: center;
                }
                
                .has-items {
                    animation: pulse 2s infinite;
                }
                
                @keyframes pulse {
                    0% { transform: scale(1); }
                    50% { transform: scale(1.1); }
                    100% { transform: scale(1); }
                }
                
                .toast-notification {
                    position: fixed;
                    top: 20px;
                    right: 20px;
                    background: white;
                    border-radius: 8px;
                    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
                    padding: 16px;
                    display: flex;
                    align-items: center;
                    gap: 12px;
                    z-index: 9999;
                    transform: translateX(400px);
                    transition: transform 0.3s ease;
                    max-width: 300px;
                }
                
                .toast-notification.show {
                    transform: translateX(0);
                }
                
                .toast-success {
                    border-left: 4px solid #10b981;
                }
                
                .toast-error {
                    border-left: 4px solid #e74c3c;
                }
                
                .toast-content {
                    display: flex;
                    align-items: center;
                    gap: 8px;
                    flex: 1;
                }
                
                .toast-success i {
                    color: #10b981;
                }
                
                .toast-error i {
                    color: #e74c3c;
                }
                
                .toast-close {
                    background: none;
                    border: none;
                    color: #666;
                    cursor: pointer;
                    padding: 4px;
                }
            </style>
        `);
    }
});
    </script>
</body>
</html>