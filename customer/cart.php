<?php
/**
 * หน้าตะกร้าสินค้า
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

$pageTitle = 'ตะกร้าสินค้า';
$pageDescription = 'ตรวจสอบรายการสินค้าในตะกร้าและไปยังหน้าชำระเงิน';

// เริ่มต้น Session
SessionManager::start();

/**
 * ดึงการตั้งค่าระบบ
 */
function getSystemSetting($key, $default = null) {
    static $settings = null;
    
    if ($settings === null) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("SELECT setting_key, setting_value FROM system_settings");
            $stmt->execute();
            $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        } catch (Exception $e) {
            $settings = [];
        }
    }
    
    return $settings[$key] ?? $default;
}

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // ดึงรายการสินค้าในตะกร้า
    $cartItems = getCartItems();
    $cartDetails = [];
    $cartSummary = [
        'subtotal' => 0,
        'service_charge' => 0,
        'service_charge_rate' => 0,
        'tax' => 0,
        'tax_rate' => 0,
        'total' => 0,
        'item_count' => 0,
        'total_quantity' => 0
    ];
    
    if (!empty($cartItems)) {
        foreach ($cartItems as $itemKey => $item) {
            $stmt = $conn->prepare("
                SELECT p.*, c.name as category_name
                FROM products p
                LEFT JOIN categories c ON p.category_id = c.category_id
                WHERE p.product_id = ? AND p.is_available = 1
            ");
            $stmt->execute([$item['product_id']]);
            $product = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($product) {
                $itemPrice = $product['price'];
                $optionText = '';
                $optionPrice = 0;
                
                // ดึงข้อมูลตัวเลือกสินค้า
                if (!empty($item['options'])) {
                    $optionNames = [];
                    foreach ($item['options'] as $optionId) {
                        $stmt = $conn->prepare("
                            SELECT name, price_adjustment 
                            FROM product_options 
                            WHERE option_id = ?
                        ");
                        $stmt->execute([$optionId]);
                        $option = $stmt->fetch(PDO::FETCH_ASSOC);
                        if ($option) {
                            $optionNames[] = $option['name'];
                            $optionPrice += $option['price_adjustment'];
                        }
                    }
                    $optionText = implode(', ', $optionNames);
                }
                
                $finalPrice = $itemPrice + $optionPrice;
                $lineTotal = $finalPrice * $item['quantity'];
                
                $cartDetails[] = [
                    'key' => $itemKey,
                    'product_id' => $item['product_id'],
                    'name' => $product['name'],
                    'description' => $product['description'],
                    'image' => $product['image'],
                    'category_name' => $product['category_name'],
                    'preparation_time' => $product['preparation_time'],
                    'base_price' => $product['price'],
                    'option_text' => $optionText,
                    'option_price' => $optionPrice,
                    'final_price' => $finalPrice,
                    'quantity' => $item['quantity'],
                    'line_total' => $lineTotal,
                    'options' => $item['options'] ?? [],
                    'added_at' => $item['added_at'] ?? time()
                ];
                
                $cartSummary['subtotal'] += $lineTotal;
                $cartSummary['total_quantity'] += $item['quantity'];
                $cartSummary['item_count']++;
            }
        }
        
        // ดึงการตั้งค่าภาษีจากระบบ
        $taxRate = floatval(getSystemSetting('tax_rate', 7)) / 100;
        $serviceChargeRate = floatval(getSystemSetting('service_charge', 0)) / 100;
        
        // คำนวณภาษีและค่าบริการ
        $cartSummary['service_charge'] = $cartSummary['subtotal'] * $serviceChargeRate;
        $cartSummary['service_charge_rate'] = $serviceChargeRate * 100;
        $taxableAmount = $cartSummary['subtotal'] + $cartSummary['service_charge'];
        $cartSummary['tax'] = $taxableAmount * $taxRate;
        $cartSummary['tax_rate'] = $taxRate * 100;
        $cartSummary['total'] = $taxableAmount + $cartSummary['tax'];
    }
    
} catch (Exception $e) {
    writeLog("Cart page error: " . $e->getMessage());
    $cartDetails = [];
    $cartSummary = [
        'subtotal' => 0,
        'service_charge' => 0,
        'service_charge_rate' => 0,
        'tax' => 0,
        'tax_rate' => 0,
        'total' => 0,
        'item_count' => 0,
        'total_quantity' => 0
    ];
}

// ดึงหมวดหมู่สำหรับแนะนำ
$suggestedCategories = [];
try {
    $stmt = $conn->prepare("
        SELECT category_id, name, description 
        FROM categories 
        WHERE status = 'active' 
        ORDER BY display_order ASC 
        LIMIT 6
    ");
    $stmt->execute();
    $suggestedCategories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    // ไม่แสดง error เพราะไม่ใช่ส่วนสำคัญ
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo SITE_NAME; ?></title>
    <meta name="description" content="<?php echo $pageDescription; ?>">
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo SITE_URL; ?>/assets/images/favicon.ico">
    
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
            --success-color: #10b981;
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
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            line-height: 1.6;
            overflow-x: hidden;
        }
        
        /* Header */
        .header-section {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            padding: 2rem 0;
            margin-bottom: 2rem;
            position: relative;
            overflow: hidden;
        }
        
        .header-section::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1000 100" fill="white" opacity="0.1"><polygon points="0,100 1000,0 1000,100"/></svg>') no-repeat center bottom;
            background-size: cover;
        }
        
        .navbar-custom {
            background: var(--white);
            box-shadow: var(--box-shadow);
            padding: 1rem 0;
            position: sticky;
            top: 0;
            z-index: 1000;
            backdrop-filter: blur(10px);
        }
        
        .navbar-brand {
            font-weight: 600;
            color: var(--text-color) !important;
            transition: var(--transition);
        }
        
        .navbar-brand:hover {
            color: var(--primary-color) !important;
        }
        
        /* Cart Container */
        .cart-container {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            margin-bottom: 2rem;
            border: 1px solid var(--border-color);
        }
        
        .cart-header {
            background: linear-gradient(135deg, var(--secondary-color), #059669);
            color: white;
            padding: 1.5rem 2rem;
            position: relative;
        }
        
        .cart-header::after {
            content: '';
            position: absolute;
            bottom: -10px;
            left: 0;
            right: 0;
            height: 20px;
            background: linear-gradient(135deg, var(--secondary-color), #059669);
            border-radius: 0 0 50% 50%;
        }
        
        .cart-body {
            padding: 0;
        }
        
        /* Cart Item */
        .cart-item {
            border-bottom: 2px solid var(--border-color);
            padding: 1.5rem 2rem;
            transition: var(--transition);
            position: relative;
            background: var(--white);
        }
        
        .cart-item:last-child {
            border-bottom: none;
        }
        
        .cart-item:hover {
            background: #f9fafb;
            transform: translateX(5px);
        }
        
        .cart-item::before {
            content: '';
            position: absolute;
            left: 0;
            top: 0;
            bottom: 0;
            width: 4px;
            background: var(--secondary-color);
            transform: scaleY(0);
            transition: var(--transition);
        }
        
        .cart-item:hover::before {
            transform: scaleY(1);
        }
        
        .item-image {
            width: 90px;
            height: 90px;
            border-radius: 12px;
            background: linear-gradient(135deg, #f3f4f6, #e5e7eb);
            display: flex;
            align-items: center;
            justify-content: center;
            overflow: hidden;
            flex-shrink: 0;
            position: relative;
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .item-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: var(--transition);
        }
        
        .item-image:hover img {
            transform: scale(1.1);
        }
        
        .item-image i {
            font-size: 2.5rem;
            color: var(--text-muted);
        }
        
        .item-details {
            flex: 1;
            margin-left: 1.5rem;
        }
        
        .item-name {
            font-size: 1.25rem;
            font-weight: 600;
            margin-bottom: 0.5rem;
            color: var(--text-color);
        }
        
        .item-category {
            font-size: 0.875rem;
            color: var(--text-muted);
            margin-bottom: 0.5rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .category-badge {
            background: var(--light-bg);
            color: var(--text-muted);
            padding: 2px 8px;
            border-radius: 20px;
            font-size: 0.75rem;
        }
        
        .item-options {
            background: linear-gradient(135deg, #fef3c7, #fbbf24);
            border-radius: 8px;
            padding: 0.5rem 0.75rem;
            font-size: 0.875rem;
            color: #92400e;
            margin-bottom: 0.5rem;
            border-left: 3px solid var(--warning-color);
        }
        
        .item-meta {
            display: flex;
            align-items: center;
            gap: 1rem;
            font-size: 0.875rem;
            color: var(--text-muted);
            flex-wrap: wrap;
        }
        
        .meta-item {
            display: flex;
            align-items: center;
            gap: 0.25rem;
        }
        
        .item-controls {
            display: flex;
            flex-direction: column;
            align-items: flex-end;
            gap: 1rem;
            min-width: 120px;
        }
        
        .price-display {
            text-align: right;
        }
        
        .base-price {
            font-size: 0.875rem;
            color: var(--text-muted);
            text-decoration: line-through;
        }
        
        .final-price {
            font-size: 1.125rem;
            font-weight: 600;
            color: var(--secondary-color);
        }
        
        .line-total {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Quantity Controls */
        .quantity-controls {
            display: flex;
            align-items: center;
            border: 2px solid var(--border-color);
            border-radius: 10px;
            overflow: hidden;
            background: var(--white);
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .qty-btn {
            background: var(--light-bg);
            border: none;
            width: 40px;
            height: 40px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            font-size: 0.875rem;
            color: var(--text-color);
        }
        
        .qty-btn:hover:not(:disabled) {
            background: var(--primary-color);
            color: white;
            transform: scale(1.1);
        }
        
        .qty-btn:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .qty-input {
            border: none;
            width: 60px;
            text-align: center;
            font-weight: 600;
            padding: 10px 4px;
            background: var(--white);
            color: var(--text-color);
        }
        
        .qty-input:focus {
            outline: none;
            background: var(--light-bg);
        }
        
        .remove-btn {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 8px 12px;
            font-size: 0.875rem;
            transition: var(--transition);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .remove-btn:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
        }
        
        /* Cart Summary */
        .cart-summary {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            padding: 2rem;
            border: 1px solid var(--border-color);
            position: sticky;
            top: 100px;
        }
        
        .summary-header {
            text-align: center;
            margin-bottom: 1.5rem;
            padding-bottom: 1rem;
            border-bottom: 2px solid var(--border-color);
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 1rem;
            padding: 0.5rem 0;
        }
        
        .summary-row:last-child {
            margin-bottom: 0;
            padding-top: 1rem;
            border-top: 2px solid var(--border-color);
        }
        
        .summary-label {
            font-weight: 500;
            color: var(--text-color);
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .summary-value {
            font-weight: 600;
            color: var(--text-color);
        }
        
        .total-amount {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color);
        }
        
        /* Action Buttons */
        .action-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 1rem;
            margin-top: 2rem;
        }
        
        .btn-custom {
            border-radius: 12px;
            padding: 16px 24px;
            font-weight: 600;
            font-size: 1rem;
            transition: var(--transition);
            border: none;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 0.5rem;
            text-decoration: none;
        }
        
        .btn-continue {
            background: var(--light-bg);
            color: var(--text-color);
            border: 2px solid var(--border-color);
        }
        
        .btn-continue:hover {
            background: var(--primary-color);
            color: white !important;
            border-color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        }
        
        .btn-checkout {
            background: linear-gradient(135deg, var(--secondary-color), #059669);
            color: white;
            border: 2px solid var(--secondary-color);
        }
        
        .btn-checkout:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16, 185, 129, 0.3);
            color: white;
        }
        
        .btn-checkout:disabled {
            opacity: 0.6;
            cursor: not-allowed;
            transform: none;
            background: #9ca3af;
            border-color: #9ca3af;
        }
        
        .btn-clear {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
            color: white;
            border: 2px solid var(--danger-color);
            grid-column: 1 / -1;
            margin-top: 1rem;
        }
        
        .btn-clear:hover {
            background: linear-gradient(135deg, #dc2626, #b91c1c);
            border-color: #dc2626;
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(239, 68, 68, 0.3);
            color: white;
        }
        
        /* Empty Cart */
        .empty-cart {
            text-align: center;
            padding: 4rem 2rem;
            color: var(--text-muted);
        }
        
        .empty-cart i {
            font-size: 5rem;
            margin-bottom: 1.5rem;
            opacity: 0.5;
            color: var(--text-muted);
        }
        
        .empty-cart h4 {
            margin-bottom: 1rem;
            color: var(--text-color);
        }
        
        .empty-cart p {
            margin-bottom: 2rem;
            font-size: 1.1rem;
        }
        
        /* Suggestions */
        .suggestions-section {
            margin-top: 3rem;
        }
        
        .suggestions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 1rem;
            margin-top: 1.5rem;
        }
        
        .suggestion-card {
            background: var(--white);
            border-radius: 12px;
            padding: 1.5rem;
            text-align: center;
            border: 2px solid var(--border-color);
            transition: var(--transition);
            text-decoration: none;
            color: var(--text-color);
        }
        
        .suggestion-card:hover {
            border-color: var(--primary-color);
            transform: translateY(-4px);
            box-shadow: var(--box-shadow);
            color: var(--text-color);
        }
        
        /* Loading States */
        .loading-overlay {
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(255, 255, 255, 0.9);
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 10;
            border-radius: 12px;
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
        
        /* Pulse animation for updates */
        .pulse-update {
            animation: pulseUpdate 0.5s ease-in-out;
        }
        
        @keyframes pulseUpdate {
            0% { transform: scale(1); }
            50% { transform: scale(1.05); background-color: #f0fdfa; }
            100% { transform: scale(1); }
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .cart-item {
                padding: 1rem;
            }
            
            .cart-item .d-flex {
                flex-direction: column;
                gap: 1rem;
            }
            
            .item-details {
                margin-left: 0;
            }
            
            .item-controls {
                flex-direction: row;
                justify-content: space-between;
                align-items: center;
                width: 100%;
            }
            
            .price-display {
                text-align: left;
            }
            
            .action-buttons {
                grid-template-columns: 1fr;
            }
            
            .cart-summary {
                margin: 1rem;
                position: static;
            }
            
            .cart-container {
                margin: 1rem;
            }
            
            .suggestions-grid {
                grid-template-columns: 1fr;
            }
            
            .item-image {
                width: 80px;
                height: 80px;
            }
            
            .navbar-custom .container {
                padding: 0 1rem;
            }
        }
        
        @media (max-width: 576px) {
            .header-section {
                padding: 1.5rem 0;
            }
            
            .cart-header {
                padding: 1rem 1.5rem;
            }
            
            .cart-summary {
                padding: 1.5rem;
            }
            
            .item-name {
                font-size: 1.1rem;
            }
            
            .line-total {
                font-size: 1.25rem;
            }
        }
        
        /* Print styles */
        @media print {
            .navbar-custom,
            .action-buttons,
            .suggestions-section {
                display: none;
            }
            
            .cart-container {
                box-shadow: none;
                border: 1px solid #000;
            }
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
                <a href="menu.php" class="btn btn-outline-primary btn-sm">
                    <i class="fas fa-utensils me-2"></i>
                    <span class="d-none d-sm-inline">เมนูอาหาร</span>
                </a>
                
                <a href="queue_status.php" class="btn btn-outline-secondary btn-sm">
                    <i class="fas fa-clock me-2"></i>
                    <span class="d-none d-sm-inline">ตรวจสอบคิว</span>
                </a>
                
                <a href="chatbot.php" class="btn btn-outline-info btn-sm">
                    <i class="fas fa-robot me-2"></i>
                    <span class="d-none d-sm-inline">AI ช่วยเหลือ</span>
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Header -->
    <section class="header-section">
        <div class="container text-center">
            <h1 class="mb-2 animate__animated animate__fadeInDown">
                <i class="fas fa-shopping-cart me-3"></i>
                ตะกร้าสินค้า
            </h1>
            <p class="lead mb-0 animate__animated animate__fadeInUp" style="animation-delay: 0.2s">
                ตรวจสอบรายการสินค้าของคุณก่อนชำระเงิน
            </p>
        </div>
    </section>
    
    <div class="container">
        <div class="row">
            <!-- Cart Items -->
            <div class="col-lg-8 mb-4">
                <div class="cart-container animate__animated animate__fadeInLeft">
                    <div class="cart-header">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <h3 class="mb-1">
                                    <i class="fas fa-list me-2"></i>
                                    รายการสินค้า
                                </h3>
                                <p class="mb-0 opacity-75">
                                    <?php echo $cartSummary['item_count']; ?> รายการ 
                                    (<?php echo $cartSummary['total_quantity']; ?> ชิ้น)
                                </p>
                            </div>
                            
                            <?php if (!empty($cartDetails)): ?>
                                <button type="button" class="btn btn-outline-light btn-sm" onclick="clearCart()">
                                    <i class="fas fa-trash me-1"></i>
                                    <span class="d-none d-sm-inline">ล้างตะกร้า</span>
                                </button>
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div class="cart-body">
                        <?php if (!empty($cartDetails)): ?>
                            <?php foreach ($cartDetails as $index => $item): ?>
                                <div class="cart-item animate__animated animate__fadeInUp" 
                                     data-key="<?php echo $item['key']; ?>" 
                                     style="animation-delay: <?php echo $index * 0.1; ?>s">
                                    <div class="d-flex align-items-start">
                                        <!-- Item Image -->
                                        <div class="item-image">
                                            <?php if ($item['image']): ?>
                                                <img src="<?php echo SITE_URL . '/uploads/menu_images/' . $item['image']; ?>" 
                                                     alt="<?php echo clean($item['name']); ?>"
                                                     loading="lazy">
                                            <?php else: ?>
                                                <i class="fas fa-utensils"></i>
                                            <?php endif; ?>
                                        </div>
                                        
                                        <!-- Item Details -->
                                        <div class="item-details">
                                            <h4 class="item-name"><?php echo clean($item['name']); ?></h4>
                                            
                                            <div class="item-category">
                                                <i class="fas fa-tag"></i>
                                                <span class="category-badge"><?php echo clean($item['category_name']); ?></span>
                                            </div>
                                            
                                            <?php if ($item['option_text']): ?>
                                                <div class="item-options">
                                                    <i class="fas fa-plus me-1"></i>
                                                    <?php echo clean($item['option_text']); ?>
                                                    <?php if ($item['option_price'] > 0): ?>
                                                        (+<?php echo formatCurrency($item['option_price']); ?>)
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="item-meta">
                                                <div class="meta-item">
                                                    <i class="fas fa-clock"></i>
                                                    <span>~<?php echo $item['preparation_time']; ?> นาที</span>
                                                </div>
                                                <div class="meta-item">
                                                    <i class="fas fa-money-bill"></i>
                                                    <span>ราคาฐาน <?php echo formatCurrency($item['base_price']); ?></span>
                                                </div>
                                                <div class="meta-item">
                                                    <i class="fas fa-calendar-plus"></i>
                                                    <span>เพิ่มเมื่อ <?php echo date('H:i', $item['added_at']); ?></span>
                                                </div>
                                            </div>
                                        </div>
                                        
                                        <!-- Item Controls -->
                                        <div class="item-controls">
                                            <div class="price-display">
                                                <?php if ($item['option_price'] > 0): ?>
                                                    <div class="base-price"><?php echo formatCurrency($item['base_price']); ?></div>
                                                <?php endif; ?>
                                                <div class="final-price"><?php echo formatCurrency($item['final_price']); ?></div>
                                                <div class="line-total"><?php echo formatCurrency($item['line_total']); ?></div>
                                            </div>
                                            
                                            <div class="d-flex align-items-center gap-2">
                                                <!-- Quantity Controls -->
                                                <div class="quantity-controls">
                                                    <button type="button" class="qty-btn" 
                                                            onclick="updateQuantity('<?php echo $item['key']; ?>', <?php echo $item['quantity'] - 1; ?>)"
                                                            <?php echo $item['quantity'] <= 1 ? 'disabled' : ''; ?>
                                                            title="ลดจำนวน">
                                                        <i class="fas fa-minus"></i>
                                                    </button>
                                                    <input type="number" class="qty-input" 
                                                           value="<?php echo $item['quantity']; ?>" 
                                                           min="1" max="99" 
                                                           data-key="<?php echo $item['key']; ?>"
                                                           onchange="updateQuantityInput(this)">
                                                    <button type="button" class="qty-btn" 
                                                            onclick="updateQuantity('<?php echo $item['key']; ?>', <?php echo $item['quantity'] + 1; ?>)"
                                                            <?php echo $item['quantity'] >= 99 ? 'disabled' : ''; ?>
                                                            title="เพิ่มจำนวน">
                                                        <i class="fas fa-plus"></i>
                                                    </button>
                                                </div>
                                                
                                                <!-- Remove Button -->
                                                <button type="button" class="remove-btn" 
                                                        onclick="removeItem('<?php echo $item['key']; ?>', '<?php echo addslashes($item['name']); ?>')"
                                                        title="ลบรายการ">
                                                    <i class="fas fa-trash"></i>
                                                    <span class="d-none d-sm-inline">ลบ</span>
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="empty-cart">
                                <i class="fas fa-shopping-cart"></i>
                                <h4>ตะกร้าสินค้าว่างเปล่า</h4>
                                <p>ยังไม่มีสินค้าในตะกร้า กรุณาเลือกสินค้าจากเมนูอาหาร</p>
                                <a href="menu.php" class="btn btn-primary btn-custom">
                                    <i class="fas fa-utensils me-2"></i>
                                    ดูเมนูอาหาร
                                </a>
                                
                                <!-- Suggested Categories -->
                                <?php if (!empty($suggestedCategories)): ?>
                                    <div class="suggestions-section">
                                        <h5 class="text-center mb-3">หมวดหมู่แนะนำ</h5>
                                        <div class="suggestions-grid">
                                            <?php foreach ($suggestedCategories as $category): ?>
                                                <a href="menu.php?category=<?php echo $category['category_id']; ?>" class="suggestion-card">
                                                    <i class="fas fa-utensils fa-2x mb-2 text-primary"></i>
                                                    <h6><?php echo clean($category['name']); ?></h6>
                                                    <?php if ($category['description']): ?>
                                                        <p class="small text-muted mb-0"><?php echo clean($category['description']); ?></p>
                                                    <?php endif; ?>
                                                </a>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- Cart Summary -->
            <div class="col-lg-4">
                <div class="cart-summary animate__animated animate__fadeInRight">
                    <div class="summary-header">
                        <h3>
                            <i class="fas fa-calculator me-2"></i>
                            สรุปการสั่งซื้อ
                        </h3>
                        <?php if (!empty($cartDetails)): ?>
                            <small class="text-muted">อัปเดตล่าสุด: <?php echo date('H:i:s'); ?></small>
                        <?php endif; ?>
                    </div>
                    
                    <?php if (!empty($cartDetails)): ?>
                        <div class="summary-row">
                            <span class="summary-label">
                                <i class="fas fa-shopping-bag"></i>
                                ราคาสินค้า
                            </span>
                            <span class="summary-value" id="subtotal-display"><?php echo formatCurrency($cartSummary['subtotal']); ?></span>
                        </div>
                        
                        <?php if ($cartSummary['service_charge'] > 0): ?>
                        <div class="summary-row">
                            <span class="summary-label">
                                <i class="fas fa-concierge-bell"></i>
                                ค่าบริการ (<?php echo number_format($cartSummary['service_charge_rate'], 1); ?>%)
                            </span>
                            <span class="summary-value" id="service-charge-display"><?php echo formatCurrency($cartSummary['service_charge']); ?></span>
                        </div>
                        <?php endif; ?>
                        
                        <div class="summary-row">
                            <span class="summary-label">
                                <i class="fas fa-receipt"></i>
                                ภาษีมูลค่าเพิ่ม (<?php echo number_format($cartSummary['tax_rate'], 1); ?>%)
                            </span>
                            <span class="summary-value" id="tax-display"><?php echo formatCurrency($cartSummary['tax']); ?></span>
                        </div>
                        
                        <div class="summary-row">
                            <span class="summary-label">
                                <i class="fas fa-shipping-fast"></i>
                                ค่าจัดส่ง
                            </span>
                            <span class="summary-value text-success">ฟรี</span>
                        </div>
                        
                        <div class="summary-row">
                            <span class="summary-label">
                                <i class="fas fa-gift"></i>
                                ส่วนลด
                            </span>
                            <span class="summary-value text-success">-</span>
                        </div>
                        
                        <div class="summary-row">
                            <span class="summary-label total-amount">
                                <i class="fas fa-money-check-alt"></i>
                                ยอดรวมทั้งสิ้น
                            </span>
                            <span class="summary-value total-amount" id="total-display"><?php echo formatCurrency($cartSummary['total']); ?></span>
                        </div>
                        
                        <div class="action-buttons">
                            <a href="menu.php" class="btn btn-continue btn-custom">
                                <i class="fas fa-arrow-left"></i>
                                เลือกเพิ่ม
                            </a>
                            
                            <button type="button" 
                                    class="btn btn-checkout btn-custom" 
                                    onclick="proceedToCheckout()"
                                    id="checkout-btn">
                                <i class="fas fa-credit-card"></i>
                                ชำระเงิน
                            </button>
                            
                            <button type="button" 
                                    class="btn btn-clear btn-custom" 
                                    onclick="clearCart()">
                                <i class="fas fa-trash"></i>
                                ล้างตะกร้า
                            </button>
                        </div>
                        
                        <div class="mt-3 text-center">
                            <small class="text-muted">
                                <i class="fas fa-info-circle me-1"></i>
                                คุณสามารถแก้ไขจำนวนสินค้าได้ก่อนชำระเงิน
                            </small>
                        </div>
                        
                        <!-- Cart Summary Stats -->
                        <div class="mt-3 pt-3 border-top">
                            <div class="row text-center">
                                <div class="col-4">
                                    <div class="small text-muted">รายการ</div>
                                    <div class="fw-bold"><?php echo $cartSummary['item_count']; ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="small text-muted">จำนวน</div>
                                    <div class="fw-bold"><?php echo $cartSummary['total_quantity']; ?></div>
                                </div>
                                <div class="col-4">
                                    <div class="small text-muted">เฉลี่ย</div>
                                    <div class="fw-bold">
                                        <?php echo formatCurrency($cartSummary['total'] / $cartSummary['total_quantity']); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php else: ?>
                        <div class="text-center py-4">
                            <i class="fas fa-shopping-cart fa-3x text-muted mb-3"></i>
                            <h5>ตะกร้าว่างเปล่า</h5>
                            <p class="text-muted">เพิ่มสินค้าลงตะกร้าเพื่อดูสรุปการสั่งซื้อ</p>
                            <a href="menu.php" class="btn btn-primary btn-custom">
                                <i class="fas fa-utensils me-2"></i>
                                เลือกเมนูอาหาร
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Quick Actions -->
                <div class="cart-summary mt-3">
                    <h5 class="mb-3">
                        <i class="fas fa-bolt me-2"></i>
                        ดำเนินการเร็ว
                    </h5>
                    
                    <div class="d-grid gap-2">
                        <a href="chatbot.php" class="btn btn-outline-info btn-sm">
                            <i class="fas fa-robot me-2"></i>
                            ถาม AI แนะนำเมนู
                        </a>
                        
                        <a href="queue_status.php" class="btn btn-outline-warning btn-sm">
                            <i class="fas fa-clock me-2"></i>
                            ตรวจสอบคิวปัจจุบัน
                        </a>
                        
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="saveCart()">
                            <i class="fas fa-save me-2"></i>
                            บันทึกตะกร้า
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const SITE_URL = '<?php echo SITE_URL; ?>';
        let isUpdating = false;
        
        // Update quantity via buttons
        function updateQuantity(itemKey, newQuantity) {
            if (newQuantity < 1 || newQuantity > 99 || isUpdating) {
                return;
            }
            
            isUpdating = true;
            
            // Show loading overlay
            const cartItem = $(`.cart-item[data-key="${itemKey}"]`);
            cartItem.css('position', 'relative');
            cartItem.append('<div class="loading-overlay"><div class="loading-spinner"></div></div>');
            
            $.ajax({
                url: 'api/cart.php',
                type: 'POST',
                data: {
                    action: 'update',
                    item_key: itemKey,
                    quantity: newQuantity
                },
                dataType: 'json',
                timeout: 10000,
                success: function(response) {
                    if (response.success) {
                        // อัปเดต UI แบบ real-time
                        updateCartDisplayRealtime(response.cart_summary);
                        
                        // อัปเดต quantity input
                        cartItem.find('.qty-input').val(newQuantity);
                        
                        // Add update animation
                        cartItem.addClass('pulse-update');
                        setTimeout(() => {
                            cartItem.removeClass('pulse-update');
                        }, 500);
                        
                        showToast('success', 'อัปเดตจำนวนสำเร็จ');
                    } else {
                        showToast('error', response.message || 'ไม่สามารถอัปเดตจำนวนได้');
                    }
                    
                    cartItem.find('.loading-overlay').remove();
                    isUpdating = false;
                },
                error: function(xhr, status, error) {
                    console.error('Update quantity error:', error);
                    
                    let errorMessage = 'เกิดข้อผิดพลาดในการอัปเดต';
                    if (status === 'timeout') {
                        errorMessage = 'การเชื่อมต่อหมดเวลา กรุณาลองใหม่';
                    }
                    
                    showToast('error', errorMessage);
                    cartItem.find('.loading-overlay').remove();
                    isUpdating = false;
                    
                    // Reset quantity input to original value
                    const originalQty = cartItem.find('.qty-input').data('original-qty') || 1;
                    cartItem.find('.qty-input').val(originalQty);
                }
            });
        }
        
        // Update cart display real-time
        function updateCartDisplayRealtime(cartSummary) {
            if (!cartSummary) return;
            
            // อัปเดตยอดเงิน
            $('#subtotal-display').text(formatCurrency(cartSummary.subtotal));
            $('#tax-display').text(formatCurrency(cartSummary.tax));
            $('#total-display').text(formatCurrency(cartSummary.total));
            
            // อัปเดตค่าบริการ (ถ้ามี)
            if (cartSummary.service_charge > 0) {
                $('#service-charge-display').text(formatCurrency(cartSummary.service_charge));
            }
            
            // อัปเดตจำนวนรายการ
            $('.cart-header p').text(`${cartSummary.item_count} รายการ (${cartSummary.total_quantity} ชิ้น)`);
            
            // อัปเดตสถิติ
            $('.row.text-center .col-4').eq(0).find('.fw-bold').text(cartSummary.item_count);
            $('.row.text-center .col-4').eq(1).find('.fw-bold').text(cartSummary.total_quantity);
            if (cartSummary.total_quantity > 0) {
                const avgPrice = cartSummary.total / cartSummary.total_quantity;
                $('.row.text-center .col-4').eq(2).find('.fw-bold').text(formatCurrency(avgPrice));
            }
            
            // อัปเดตราคาของแต่ละรายการ
            cartSummary.items.forEach(item => {
                const cartItem = $(`.cart-item[data-key="${item.key}"]`);
                if (cartItem.length) {
                    cartItem.find('.final-price').text(formatCurrency(item.final_price));
                    cartItem.find('.line-total').text(formatCurrency(item.line_total));
                }
            });
        }
        
        // Update quantity via input
        function updateQuantityInput(input) {
            const itemKey = $(input).data('key');
            const newQuantity = parseInt($(input).val());
            const originalQty = $(input).data('original-qty') || 1;
            
            if (newQuantity >= 1 && newQuantity <= 99 && newQuantity !== originalQty) {
                // Debounce the update
                clearTimeout(input.updateTimeout);
                input.updateTimeout = setTimeout(() => {
                    if (!isUpdating) {
                        // Store new original quantity
                        $(input).data('original-qty', newQuantity);
                        updateQuantity(itemKey, newQuantity);
                    }
                }, 1500);
            } else if (newQuantity < 1 || newQuantity > 99) {
                $(input).val(originalQty); // Reset to original
                showToast('warning', 'จำนวนต้องอยู่ระหว่าง 1-99');
            }
        }
        
        // Remove item from cart
        function removeItem(itemKey, itemName) {
            Swal.fire({
                title: 'ลบรายการ?',
                text: `คุณต้องการลบ "${itemName}" ออกจากตะกร้าหรือไม่?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="fas fa-trash me-1"></i> ลบ',
                cancelButtonText: '<i class="fas fa-times me-1"></i> ยกเลิก',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    const cartItem = $(`.cart-item[data-key="${itemKey}"]`);
                    cartItem.css('position', 'relative');
                    cartItem.append('<div class="loading-overlay"><div class="loading-spinner"></div></div>');
                    
                    $.ajax({
                        url: 'api/cart.php',
                        type: 'POST',
                        data: {
                            action: 'remove',
                            item_key: itemKey
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                // Animate item removal
                                cartItem.addClass('animate__animated animate__fadeOutLeft');
                                
                                setTimeout(() => {
                                    if (response.cart_empty) {
                                        location.reload();
                                    } else {
                                        cartItem.remove();
                                        updateCartDisplay(response.cart_summary);
                                    }
                                }, 500);
                                
                                showToast('success', `ลบ "${itemName}" ออกจากตะกร้าแล้ว`);
                            } else {
                                showToast('error', response.message || 'ไม่สามารถลบรายการได้');
                                cartItem.find('.loading-overlay').remove();
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Remove item error:', error);
                            showToast('error', 'เกิดข้อผิดพลาดในการลบรายการ');
                            cartItem.find('.loading-overlay').remove();
                        }
                    });
                }
            });
        }
        
        // Clear entire cart
        function clearCart() {
            <?php if (empty($cartDetails)): ?>
                showToast('info', 'ตะกร้าว่างเปล่าอยู่แล้ว');
                return;
            <?php endif; ?>
            
            Swal.fire({
                title: 'ล้างตะกร้า?',
                text: 'คุณต้องการลบสินค้าทั้งหมดออกจากตะกร้าหรือไม่?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '<i class="fas fa-trash me-1"></i> ล้างทั้งหมด',
                cancelButtonText: '<i class="fas fa-times me-1"></i> ยกเลิก',
                reverseButtons: true
            }).then((result) => {
                if (result.isConfirmed) {
                    // Show loading
                    Swal.fire({
                        title: 'กำลังล้างตะกร้า...',
                        allowOutsideClick: false,
                        didOpen: () => {
                            Swal.showLoading();
                        }
                    });
                    
                    $.ajax({
                        url: 'api/cart.php',
                        type: 'POST',
                        data: {
                            action: 'clear'
                        },
                        dataType: 'json',
                        success: function(response) {
                            if (response.success) {
                                Swal.close();
                                showToast('success', 'ล้างตะกร้าแล้ว');
                                setTimeout(() => {
                                    location.reload();
                                }, 1000);
                            } else {
                                Swal.close();
                                showToast('error', response.message || 'ไม่สามารถล้างตะกร้าได้');
                            }
                        },
                        error: function(xhr, status, error) {
                            console.error('Clear cart error:', error);
                            Swal.close();
                            showToast('error', 'เกิดข้อผิดพลาดในการล้างตะกร้า');
                        }
                    });
                }
            });
        }
        
        // Proceed to checkout
        function proceedToCheckout() {
            // Check if cart is empty
            <?php if (empty($cartDetails)): ?>
                showToast('warning', 'กรุณาเลือกสินค้าก่อนชำระเงิน');
                return;
            <?php endif; ?>
            
            // Show loading
            const btn = $('#checkout-btn');
            const originalText = btn.html();
            btn.prop('disabled', true).html('<div class="spinner-border spinner-border-sm me-2"></div>กำลังดำเนินการ...');
            
            // Create order
            $.ajax({
                url: 'api/create_order.php',
                type: 'POST',
                data: {
                    action: 'create_from_cart',
                    customer_info: {
                        name: 'ลูกค้าเดินหน้า',
                        phone: '',
                        notes: ''
                    }
                },
                dataType: 'json',
                timeout: 15000,
                success: function(response) {
                    if (response.success) {
                        showToast('success', 'สร้างออเดอร์สำเร็จ กำลังไปหน้าชำระเงิน...');
                        
                        // Redirect to checkout page with order ID
                        setTimeout(() => {
                            window.location.href = `checkout.php?order_id=${response.order_id}&amount=${response.total_amount}`;
                        }, 1500);
                    } else {
                        showToast('error', response.message || 'ไม่สามารถสร้างออเดอร์ได้');
                        btn.prop('disabled', false).html(originalText);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Create order error:', error);
                    
                    let errorMessage = 'เกิดข้อผิดพลาดในการสร้างออเดอร์';
                    if (status === 'timeout') {
                        errorMessage = 'การเชื่อมต่อหมดเวลา กรุณาลองใหม่';
                    } else if (xhr.responseJSON && xhr.responseJSON.message) {
                        errorMessage = xhr.responseJSON.message;
                    }
                    
                    showToast('error', errorMessage);
                    btn.prop('disabled', false).html(originalText);
                }
            });
        }
        
        // Save cart (optional feature)
        function saveCart() {
            <?php if (empty($cartDetails)): ?>
                showToast('info', 'ไม่มีข้อมูลตะกร้าที่จะบันทึก');
                return;
            <?php endif; ?>
            
            // This could be extended to save cart to localStorage or server
            const cartData = {
                items: <?php echo json_encode($cartDetails); ?>,
                summary: <?php echo json_encode($cartSummary); ?>,
                saved_at: new Date().toISOString()
            };
            
            localStorage.setItem('saved_cart', JSON.stringify(cartData));
            showToast('success', 'บันทึกตะกร้าเรียบร้อย');
        }
        
        // Update cart display (for AJAX updates)
        function updateCartDisplay(cartSummary) {
            if (cartSummary) {
                // Update summary values
                $('.summary-value').each(function(index) {
                    const values = [
                        formatCurrency(cartSummary.subtotal),
                        formatCurrency(cartSummary.tax),
                        'ฟรี',
                        '-',
                        formatCurrency(cartSummary.total)
                    ];
                    if (values[index]) {
                        $(this).text(values[index]);
                    }
                });
                
                // Update item counts
                $('.cart-header p').text(`${cartSummary.item_count} รายการ (${cartSummary.total_quantity} ชิ้น)`);
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
                    toast.addEventListener('mouseenter', Swal.stopTimer);
                    toast.addEventListener('mouseleave', Swal.resumeTimer);
                }
            });
            
            Toast.fire({
                icon: type,
                title: message
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
            // Store original quantities for comparison
            $('.qty-input').each(function() {
                $(this).data('original-qty', parseInt($(this).val()));
            });
            
            // Load saved cart message if exists
            const savedCart = localStorage.getItem('saved_cart');
            if (savedCart) {
                const data = JSON.parse(savedCart);
                const savedTime = new Date(data.saved_at).toLocaleString('th-TH');
                console.log(`มีตะกร้าที่บันทึกไว้เมื่อ: ${savedTime}`);
            }
            
            // Auto-save cart items on quantity change
            $('.qty-input').on('input', function() {
                const $this = $(this);
                const itemKey = $this.data('key');
                const newQuantity = parseInt($this.val());
                
                // Validate input
                if (isNaN(newQuantity) || newQuantity < 1) {
                    $this.val(1);
                    return;
                }
                
                if (newQuantity > 99) {
                    $this.val(99);
                    return;
                }
                
                // Debounce the update
                clearTimeout(this.updateTimeout);
                this.updateTimeout = setTimeout(() => {
                    if (!isUpdating) {
                        updateQuantityInput(this);
                    }
                }, 1500);
            });
            
            // Handle keyboard shortcuts
            $(document).on('keydown', function(e) {
                // Escape key to go back to menu
                if (e.key === 'Escape') {
                    window.location.href = 'menu.php';
                }
                
                // Enter key to proceed to checkout
                if (e.key === 'Enter' && !$('#checkout-btn').prop('disabled')) {
                    <?php if (!empty($cartDetails)): ?>
                        proceedToCheckout();
                    <?php endif; ?>
                }
                
                // Ctrl+D to clear cart
                if (e.ctrlKey && e.key === 'd') {
                    e.preventDefault();
                    clearCart();
                }
            });
            
            // Update checkout button state
            updateCheckoutButton();
            
            // Auto-refresh cart every 30 seconds (optional)
            setInterval(syncCart, 30000);
            
            // Show success message if coming from menu
            const urlParams = new URLSearchParams(window.location.search);
            if (urlParams.get('added') === '1') {
                showToast('success', 'เพิ่มสินค้าลงตะกร้าแล้ว!');
                // Remove parameter from URL
                window.history.replaceState({}, document.title, window.location.pathname);
            }
            
            // Display current cart info
            console.log('Cart page loaded successfully');
            console.log('Cart summary:', <?php echo json_encode($cartSummary, JSON_UNESCAPED_UNICODE); ?>);
            console.log('Total items in cart:', <?php echo $cartSummary['item_count']; ?>);
            console.log('Tax rate from system:', <?php echo $cartSummary['tax_rate']; ?>);
        });
        
        // Update checkout button state
        function updateCheckoutButton() {
            const cartItemCount = <?php echo $cartSummary['item_count']; ?>;
            const checkoutBtn = $('#checkout-btn');
            
            if (cartItemCount === 0) {
                checkoutBtn.prop('disabled', true)
                    .html('<i class="fas fa-shopping-cart me-2"></i>ตะกร้าว่าง');
            } else {
                checkoutBtn.prop('disabled', false)
                    .html('<i class="fas fa-credit-card me-2"></i>ชำระเงิน');
            }
        }
        
        // Real-time cart sync (optional)
        function syncCart() {
            $.get('api/cart.php?action=count', function(response) {
                if (response.success) {
                    const currentCount = <?php echo $cartSummary['item_count']; ?>;
                    if (response.count !== currentCount) {
                        // Cart changed, show notification
                        showToast('info', 'ตะกร้าของคุณมีการเปลี่ยนแปลง');
                        setTimeout(() => {
                            location.reload();
                        }, 2000);
                    }
                }
            }).fail(function() {
                console.warn('Failed to sync cart');
            });
        }
        
        // Page visibility handling
        document.addEventListener('visibilitychange', function() {
            if (!document.hidden) {
                // Page became visible, sync cart
                syncCart();
            }
        });
        
        // Before unload warning if cart has items
        window.addEventListener('beforeunload', function(e) {
            <?php if (!empty($cartDetails)): ?>
                const confirmationMessage = 'คุณมีสินค้าในตะกร้า ต้องการออกจากหน้านี้หรือไม่?';
                e.returnValue = confirmationMessage;
                return confirmationMessage;
            <?php endif; ?>
        });
        
        console.log('Cart page loaded successfully');
        console.log('Cart summary:', <?php echo json_encode($cartSummary, JSON_UNESCAPED_UNICODE); ?>);
        console.log('Total items in cart:', <?php echo $cartSummary['item_count']; ?>);
        console.log('Tax rate from system:', '<?php echo $cartSummary['tax_rate']; ?>%');
        <?php if ($cartSummary['service_charge_rate'] > 0): ?>
        console.log('Service charge rate:', '<?php echo $cartSummary['service_charge_rate']; ?>%');
        <?php endif; ?>
    </script>
</body>
</html>