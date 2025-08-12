<?php
// จำลองการเรียก create_order API
$_SERVER['REQUEST_METHOD'] = 'POST';
$_POST['action'] = 'create_from_cart';
$_POST['customer_info'] = [
    'name' => 'ลูกค้าทดสอบ',
    'phone' => '0812345678',
    'notes' => 'ทดสอบระบบ'
];

// จำลองการมี session cart
define('SYSTEM_INIT', true);
require_once 'config/config.php';
require_once 'config/database.php';
require_once 'config/session.php';
require_once 'includes/functions.php';

SessionManager::start();

// เพิ่มสินค้าทดสอบลงตะกร้า
addToCart(22, 1, []); // สินค้า ID 22 จำนวน 1

echo "Cart items: " . json_encode(getCartItems()) . "\n\n";

// เรียก create_order.php
ob_start();
try {
    include 'customer/api/create_order.php';
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
}
$output = ob_get_clean();
echo "Output: $output\n";
?>