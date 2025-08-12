<?php
/**
 * API ส่งออกรายงาน
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    $type = $_GET['type'] ?? '';
    $format = $_GET['format'] ?? 'csv';
    
    switch ($type) {
        case 'inventory':
            exportInventoryReport($conn, $format);
            break;
            
        case 'financial':
            exportFinancialReport($conn, $format);
            break;
            
        case 'sales':
            exportSalesReport($conn, $format);
            break;
            
        case 'orders':
            exportOrdersReport($conn, $format);
            break;
            
        default:
            throw new Exception('Invalid report type');
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo "Error: " . $e->getMessage();
}

/**
 * ส่งออกรายงานสต็อกสินค้า
 */
function exportInventoryReport($conn, $format = 'csv') {
    $stmt = $conn->prepare("
        SELECT 
            p.name as 'ชื่อสินค้า',
            c.name as 'หมวดหมู่',
            p.stock_quantity as 'สต็อกปัจจุบัน',
            p.min_stock_level as 'สต็อกขั้นต่ำ',
            p.price as 'ราคา',
            CASE 
                WHEN p.stock_quantity <= 0 THEN 'หมด'
                WHEN p.stock_quantity <= p.min_stock_level THEN 'ต่ำ'
                ELSE 'ปกติ'
            END as 'สถานะ',
            p.created_at as 'วันที่เพิ่ม'
        FROM products p
        LEFT JOIN categories c ON p.category_id = c.category_id
        ORDER BY c.name, p.name
    ");
    $stmt->execute();
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        exportToCSV($data, 'inventory_report_' . date('Y-m-d'));
    }
}

/**
 * ส่งออกรายงานการเงิน
 */
function exportFinancialReport($conn, $format = 'csv') {
    // รายได้รายเดือน
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as 'เดือน',
            COUNT(*) as 'จำนวนออเดอร์',
            SUM(total_price) as 'รายได้รวม',
            AVG(total_price) as 'ค่าเฉลี่ยต่อออเดอร์'
        FROM orders 
        WHERE payment_status = 'paid' 
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY เดือน DESC
    ");
    $stmt->execute();
    $revenue = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ค่าใช้จ่ายรายเดือน
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(expense_date, '%Y-%m') as 'เดือน',
            category as 'หมวดหมู่',
            SUM(amount) as 'ยอดรวม',
            COUNT(*) as 'จำนวนรายการ'
        FROM expenses 
        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(expense_date, '%Y-%m'), category
        ORDER BY เดือน DESC, category
    ");
    $stmt->execute();
    $expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        // ส่งออกรายได้
        exportToCSV($revenue, 'revenue_report_' . date('Y-m-d'));
    }
}

/**
 * ส่งออกรายงานยอดขาย
 */
function exportSalesReport($conn, $format = 'csv') {
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d', strtotime('-30 days'));
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT 
            DATE(o.created_at) as 'วันที่',
            o.order_number as 'หมายเลขออเดอร์',
            o.queue_number as 'หมายเลขคิว',
            o.customer_name as 'ชื่อลูกค้า',
            o.total_price as 'ยอดรวม',
            o.payment_method as 'วิธีการชำระ',
            o.status as 'สถานะ',
            COUNT(oi.item_id) as 'จำนวนรายการ'
        FROM orders o
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        GROUP BY o.order_id
        ORDER BY o.created_at DESC
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        exportToCSV($data, 'sales_report_' . $dateFrom . '_to_' . $dateTo);
    }
}

/**
 * ส่งออกรายงานออเดอร์
 */
function exportOrdersReport($conn, $format = 'csv') {
    $dateFrom = $_GET['date_from'] ?? date('Y-m-d');
    $dateTo = $_GET['date_to'] ?? date('Y-m-d');
    
    $stmt = $conn->prepare("
        SELECT 
            o.created_at as 'วันที่สั่ง',
            o.order_number as 'หมายเลขออเดอร์',
            o.queue_number as 'หมายเลขคิว',
            o.customer_name as 'ชื่อลูกค้า',
            o.customer_phone as 'เบอร์โทร',
            o.order_type as 'ประเภทออเดอร์',
            o.table_number as 'หมายเลขโต๊ะ',
            p.name as 'ชื่อสินค้า',
            oi.quantity as 'จำนวน',
            oi.unit_price as 'ราคาต่อหน่วย',
            oi.subtotal as 'ยอดรวมรายการ',
            oi.notes as 'หมายเหตุ',
            o.total_price as 'ยอดรวมออเดอร์',
            o.payment_method as 'วิธีการชำระ',
            o.payment_status as 'สถานะการชำระ',
            o.status as 'สถานะออเดอร์'
        FROM orders o
        LEFT JOIN order_items oi ON o.order_id = oi.order_id
        LEFT JOIN products p ON oi.product_id = p.product_id
        WHERE DATE(o.created_at) BETWEEN ? AND ?
        ORDER BY o.created_at DESC, o.order_id, oi.item_id
    ");
    $stmt->execute([$dateFrom, $dateTo]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'csv') {
        exportToCSV($data, 'orders_detail_' . $dateFrom . '_to_' . $dateTo);
    }
}

/**
 * ส่งออกข้อมูลเป็น CSV
 */
function exportToCSV($data, $filename) {
    if (empty($data)) {
        throw new Exception('ไม่มีข้อมูลสำหรับส่งออก');
    }
    
    $filename = $filename . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, must-revalidate');
    header('Expires: Sat, 26 Jul 1997 05:00:00 GMT');
    
    $output = fopen('php://output', 'w');
    
    // เพิ่ม BOM สำหรับ UTF-8
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // หัวคอลัมน์
    $headers = array_keys($data[0]);
    fputcsv($output, $headers);
    
    // ข้อมูล
    foreach ($data as $row) {
        fputcsv($output, $row);
    }
    
    fclose($output);
}

/**
 * ส่งออกข้อมูลเป็น Excel (XLSX)
 */
function exportToExcel($data, $filename) {
    // สำหรับอนาคต - ต้องติดตั้ง PhpSpreadsheet library
    throw new Exception('Excel export ยังไม่รองรับ');
}
?>