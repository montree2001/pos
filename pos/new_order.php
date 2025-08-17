<?php
/**
 * สร้างออเดอร์ใหม่ - POS
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

$pageTitle = 'สร้างออเดอร์ใหม่';

// เริ่มต้นตัวแปร
$categories = [];
$products = [];
$error = null;

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงหมวดหมู่
    $stmt = $conn->prepare("SELECT * FROM categories WHERE status = 'active' ORDER BY display_order, name");
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    // ดึงสินค้า
    $stmt = $conn->prepare("
        SELECT p.*, c.name as category_name 
        FROM products p 
        LEFT JOIN categories c ON p.category_id = c.category_id 
        WHERE p.is_available = 1
        ORDER BY c.display_order, p.name
    ");
    $stmt->execute();
    $products = $stmt->fetchAll();
    
} catch (Exception $e) {
    writeLog("New order page error: " . $e->getMessage());
    $error = 'เกิดข้อผิดพลาดในการโหลดข้อมูล';
}

// จัดกลุ่มสินค้าตามหมวดหมู่
$productsByCategory = [];
foreach ($products as $product) {
    $categoryId = $product['category_id'] ?: 0;
    if (!isset($productsByCategory[$categoryId])) {
        $productsByCategory[$categoryId] = [];
    }
    $productsByCategory[$categoryId][] = $product;
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
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .pos-header {
            background: linear-gradient(135deg, var(--pos-primary), #6366f1);
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
        
        .products-section {
            background: var(--pos-white);
            border-radius: var(--pos-border-radius);
            box-shadow: var(--pos-shadow);
            overflow: hidden;
        }
        
        .cart-section {
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
        
        .categories-nav {
            display: flex;
            overflow-x: auto;
            padding: 15px 20px;
            gap: 10px;
            border-bottom: 1px solid #e5e7eb;
        }
        
        .category-btn {
            background: #f3f4f6;
            border: none;
            border-radius: 25px;
            padding: 8px 20px;
            font-size: 0.9rem;
            white-space: nowrap;
            transition: all 0.3s ease;
            cursor: pointer;
        }
        
        .category-btn.active,
        .category-btn:hover {
            background: var(--pos-primary);
            color: white;
        }
        
        .products-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
            gap: 15px;
            padding: 20px;
        }
        
        .product-card {
            background: var(--pos-white);
            border: 2px solid #e5e7eb;
            border-radius: 12px;
            padding: 15px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s ease;
            position: relative;
        }
        
        .product-card:hover {
            border-color: var(--pos-primary);
            transform: translateY(-2px);
            box-shadow: var(--pos-shadow);
        }
        
        .product-image {
            width: 80px;
            height: 80px;
            border-radius: 8px;
            object-fit: cover;
            margin: 0 auto 10px;
            background: #f3f4f6;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .product-name {
            font-weight: 600;
            font-size: 0.9rem;
            margin-bottom: 5px;
            line-height: 1.2;
        }
        
        .product-price {
            color: var(--pos-primary);
            font-weight: 700;
            font-size: 1rem;
        }
        
        .cart-items {
            max-height: 400px;
            overflow-y: auto;
            padding: 20px;
        }
        
        .cart-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px 0;
            border-bottom: 1px solid #f3f4f6;
        }
        
        .cart-item:last-child {
            border-bottom: none;
        }
        
        .item-info {
            flex: 1;
        }
        
        .item-name {
            font-weight: 600;
            font-size: 0.9rem;
        }
        
        .item-price {
            color: #6b7280;
            font-size: 0.8rem;
        }
        
        .quantity-controls {
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .qty-btn {
            width: 30px;
            height: 30px;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            background: var(--pos-white);
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .qty-btn:hover {
            background: var(--pos-primary);
            color: white;
            border-color: var(--pos-primary);
        }
        
        .qty-input {
            width: 50px;
            text-align: center;
            border: 1px solid #e5e7eb;
            border-radius: 6px;
            padding: 4px;
        }
        
        .cart-summary {
            padding: 20px;
            border-top: 1px solid #e5e7eb;
            background: #f8fafc;
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
        }
        
        .checkout-btn {
            width: 100%;
            background: linear-gradient(135deg, var(--pos-success), #059669);
            border: none;
            border-radius: 12px;
            padding: 15px;
            color: white;
            font-weight: 600;
            font-size: 1.1rem;
            margin-top: 15px;
            transition: all 0.3s ease;
        }
        
        .checkout-btn:hover:not(:disabled) {
            background: linear-gradient(135deg, #059669, #047857);
            transform: translateY(-1px);
        }
        
        .checkout-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .empty-cart {
            text-align: center;
            padding: 40px 20px;
            color: #6b7280;
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-content {
                grid-template-columns: 1fr;
            }
            
            .cart-section {
                position: relative;
                top: 0;
                order: -1;
            }
            
            .products-grid {
                grid-template-columns: repeat(auto-fill, minmax(140px, 1fr));
                gap: 10px;
                padding: 15px;
            }
            
            .product-card {
                padding: 12px;
            }
            
            .product-image {
                width: 60px;
                height: 60px;
            }
            
            .categories-nav {
                padding: 10px 15px;
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
                        <i class="fas fa-plus-circle me-2"></i>
                        สร้างออเดอร์ใหม่
                    </h1>
                    <p class="mb-0 opacity-75">เลือกสินค้าและสร้างออเดอร์</p>
                </div>
                <div>
                    <a href="index.php" class="btn btn-light">
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
            <!-- Products Section -->
            <div class="products-section">
                <div class="section-header">
                    <i class="fas fa-shopping-cart me-2"></i>
                    เลือกสินค้า
                </div>
                
                <!-- Categories Navigation -->
                <div class="categories-nav">
                    <button class="category-btn active" onclick="showCategory('all')">
                        ทั้งหมด
                    </button>
                    <?php foreach ($categories as $category): ?>
                        <button class="category-btn" onclick="showCategory(<?php echo $category['category_id']; ?>)">
                            <?php echo clean($category['name']); ?>
                        </button>
                    <?php endforeach; ?>
                </div>
                
                <!-- Products Grid -->
                <div class="products-grid" id="productsGrid">
                    <?php foreach ($products as $product): ?>
                        <div class="product-card" 
                             data-category="<?php echo $product['category_id'] ?: 0; ?>"
                             onclick="addToCart(<?php echo $product['product_id']; ?>, '<?php echo addslashes($product['name']); ?>', <?php echo $product['price']; ?>)">
                            
                            <?php if ($product['image']): ?>
                                <img src="../uploads/menu_images/<?php echo $product['image']; ?>" 
                                     alt="<?php echo clean($product['name']); ?>" 
                                     class="product-image">
                            <?php else: ?>
                                <div class="product-image">
                                    <i class="fas fa-image text-muted"></i>
                                </div>
                            <?php endif; ?>
                            
                            <div class="product-name"><?php echo clean($product['name']); ?></div>
                            <div class="product-price"><?php echo formatCurrency($product['price']); ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
            
            <!-- Cart Section -->
            <div class="cart-section">
                <div class="section-header">
                    <i class="fas fa-shopping-basket me-2"></i>
                    ตะกร้าสินค้า
                </div>
                
                <div class="cart-items" id="cartItems">
                    <div class="empty-cart">
                        <i class="fas fa-shopping-basket fa-2x mb-2"></i>
                        <p>ไม่มีสินค้าในตะกร้า</p>
                    </div>
                </div>
                
                <div class="cart-summary" id="cartSummary" style="display: none;">
                    <div class="summary-row">
                        <span>จำนวนรายการ:</span>
                        <span id="totalItems">0</span>
                    </div>
                    <div class="summary-row total-row">
                        <span>รวมทั้งสิ้น:</span>
                        <span id="totalPrice">฿0.00</span>
                    </div>
                    
                    <button class="checkout-btn" id="checkoutBtn" onclick="proceedToPayment()" disabled>
                        <i class="fas fa-credit-card me-2"></i>
                        ดำเนินการชำระเงิน
                    </button>
                    
                    <button class="btn btn-outline-danger w-100 mt-2" onclick="clearCart()">
                        <i class="fas fa-trash me-2"></i>
                        ล้างตะกร้า
                    </button>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Cart data
        let cart = [];
        
        // Show category products
        function showCategory(categoryId) {
            // Update active category button
            document.querySelectorAll('.category-btn').forEach(btn => {
                btn.classList.remove('active');
            });
            event.target.classList.add('active');
            
            // Show/hide products
            document.querySelectorAll('.product-card').forEach(card => {
                const productCategory = card.getAttribute('data-category');
                if (categoryId === 'all' || productCategory == categoryId) {
                    card.style.display = 'block';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        // Add item to cart
        function addToCart(productId, productName, price) {
            const existingItem = cart.find(item => item.id === productId);
            
            if (existingItem) {
                existingItem.quantity += 1;
            } else {
                cart.push({
                    id: productId,
                    name: productName,
                    price: price,
                    quantity: 1
                });
            }
            
            updateCartDisplay();
        }
        
        // Update quantity
        function updateQuantity(productId, newQuantity) {
            if (newQuantity <= 0) {
                removeFromCart(productId);
                return;
            }
            
            const item = cart.find(item => item.id === productId);
            if (item) {
                item.quantity = newQuantity;
                updateCartDisplay();
            }
        }
        
        // Remove item from cart
        function removeFromCart(productId) {
            cart = cart.filter(item => item.id !== productId);
            updateCartDisplay();
        }
        
        // Clear cart
        function clearCart() {
            if (cart.length === 0) return;
            
            if (confirm('ต้องการล้างสินค้าทั้งหมดในตะกร้า?')) {
                cart = [];
                updateCartDisplay();
            }
        }
        
        // Update cart display
        function updateCartDisplay() {
            const cartItems = document.getElementById('cartItems');
            const cartSummary = document.getElementById('cartSummary');
            const checkoutBtn = document.getElementById('checkoutBtn');
            
            if (cart.length === 0) {
                cartItems.innerHTML = `
                    <div class="empty-cart">
                        <i class="fas fa-shopping-basket fa-2x mb-2"></i>
                        <p>ไม่มีสินค้าในตะกร้า</p>
                    </div>
                `;
                cartSummary.style.display = 'none';
                return;
            }
            
            let cartHTML = '';
            let totalItems = 0;
            let totalPrice = 0;
            
            cart.forEach(item => {
                const subtotal = item.price * item.quantity;
                totalItems += item.quantity;
                totalPrice += subtotal;
                
                cartHTML += `
                    <div class="cart-item">
                        <div class="item-info">
                            <div class="item-name">${item.name}</div>
                            <div class="item-price">฿${item.price.toFixed(2)} x ${item.quantity}</div>
                        </div>
                        <div class="quantity-controls">
                            <button class="qty-btn" onclick="updateQuantity(${item.id}, ${item.quantity - 1})">
                                <i class="fas fa-minus"></i>
                            </button>
                            <input type="number" class="qty-input" value="${item.quantity}" 
                                   onchange="updateQuantity(${item.id}, parseInt(this.value))"
                                   min="1" max="99">
                            <button class="qty-btn" onclick="updateQuantity(${item.id}, ${item.quantity + 1})">
                                <i class="fas fa-plus"></i>
                            </button>
                        </div>
                    </div>
                `;
            });
            
            cartItems.innerHTML = cartHTML;
            cartSummary.style.display = 'block';
            
            document.getElementById('totalItems').textContent = totalItems;
            document.getElementById('totalPrice').textContent = '฿' + totalPrice.toFixed(2);
            
            checkoutBtn.disabled = cart.length === 0;
        }
        
        // Proceed to payment
        function proceedToPayment() {
            if (cart.length === 0) {
                alert('กรุณาเลือกสินค้าก่อน');
                return;
            }
            
            // Store cart data in sessionStorage
            sessionStorage.setItem('posCart', JSON.stringify(cart));
            
            // Redirect to payment page
            window.location.href = 'payment.php';
        }
        
        // Format currency
        function formatCurrency(amount) {
            return '฿' + parseFloat(amount).toFixed(2);
        }
        
        // Touch events for mobile
        document.querySelectorAll('.product-card').forEach(card => {
            card.addEventListener('touchstart', function() {
                this.style.transform = 'scale(0.95)';
            });
            
            card.addEventListener('touchend', function() {
                this.style.transform = '';
            });
        });
        
        console.log('New Order page loaded successfully');
    </script>
</body>
</html>