<?php
define('SYSTEM_INIT', true);
require_once "config/database.php";
$db = new Database();
$conn = $db->getConnection();
$stmt = $conn->prepare("SELECT order_id, order_number, customer_name, total_amount, created_at FROM orders ORDER BY created_at DESC LIMIT 3");
$stmt->execute();
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach($orders as $order) {
    echo "Order: {$order['order_number']} - {$order['customer_name']} - {$order['total_amount']} - {$order['created_at']}\n";
}
?>