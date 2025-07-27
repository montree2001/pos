<?php
/**
 * ฟังก์ชันทั่วไป
 * Smart Order Management System
 */

// ป้องกันการเข้าถึงโดยตรง
if (!defined('SYSTEM_INIT')) {
    die('Direct access not allowed');
}

/**
 * ฟังก์ชันจัดการไฟล์และการอัปโหลด
 */

/**
 * อัปโหลดรูปภาพ
 */
function uploadImage($file, $destination, $maxSize = MAX_FILE_SIZE, $allowedTypes = ALLOWED_IMAGE_TYPES) {
    if (!isset($file['tmp_name']) || empty($file['tmp_name'])) {
        return ['success' => false, 'message' => 'ไม่พบไฟล์ที่อัปโหลด'];
    }
    
    // ตรวจสอบข้อผิดพลาด
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'เกิดข้อผิดพลาดในการอัปโหลดไฟล์'];
    }
    
    // ตรวจสอบขนาดไฟล์
    if ($file['size'] > $maxSize) {
        return ['success' => false, 'message' => 'ไฟล์มีขนาดใหญ่เกินไป'];
    }
    
    // ตรวจสอบประเภทไฟล์
    $fileInfo = pathinfo($file['name']);
    $extension = strtolower($fileInfo['extension']);
    
    if (!in_array($extension, $allowedTypes)) {
        return ['success' => false, 'message' => 'ประเภทไฟล์ไม่รองรับ'];
    }
    
    // ตรวจสอบว่าเป็นรูปภาพจริง
    $imageInfo = getimagesize($file['tmp_name']);
    if ($imageInfo === false) {
        return ['success' => false, 'message' => 'ไฟล์ไม่ใช่รูปภาพ'];
    }
    
    // สร้างชื่อไฟล์ใหม่
    $fileName = uniqid() . '_' . time() . '.' . $extension;
    $filePath = $destination . $fileName;
    
    // สร้างโฟลเดอร์ถ้าไม่มี
    if (!is_dir($destination)) {
        mkdir($destination, 0755, true);
    }
    
    // ย้ายไฟล์
    if (move_uploaded_file($file['tmp_name'], $filePath)) {
        // ปรับขนาดรูปภาพ (ถ้าจำเป็น)
        resizeImage($filePath, 800, 600);
        
        return [
            'success' => true, 
            'filename' => $fileName,
            'filepath' => $filePath,
            'url' => str_replace(dirname(__DIR__), SITE_URL, $filePath)
        ];
    } else {
        return ['success' => false, 'message' => 'ไม่สามารถบันทึกไฟล์ได้'];
    }
}

/**
 * ปรับขนาดรูปภาพ
 */
function resizeImage($filePath, $maxWidth = 800, $maxHeight = 600, $quality = 85) {
    if (!file_exists($filePath)) {
        return false;
    }
    
    $imageInfo = getimagesize($filePath);
    if ($imageInfo === false) {
        return false;
    }
    
    $width = $imageInfo[0];
    $height = $imageInfo[1];
    $type = $imageInfo[2];
    
    // ตรวจสอบว่าต้องปรับขนาดหรือไม่
    if ($width <= $maxWidth && $height <= $maxHeight) {
        return true;
    }
    
    // คำนวณขนาดใหม่
    $ratio = min($maxWidth / $width, $maxHeight / $height);
    $newWidth = intval($width * $ratio);
    $newHeight = intval($height * $ratio);
    
    // สร้าง image resource
    switch ($type) {
        case IMAGETYPE_JPEG:
            $source = imagecreatefromjpeg($filePath);
            break;
        case IMAGETYPE_PNG:
            $source = imagecreatefrompng($filePath);
            break;
        case IMAGETYPE_GIF:
            $source = imagecreatefromgif($filePath);
            break;
        default:
            return false;
    }
    
    if (!$source) {
        return false;
    }
    
    // สร้างรูปภาพใหม่
    $destination = imagecreatetruecolor($newWidth, $newHeight);
    
    // รักษาความโปร่งใส (สำหรับ PNG)
    if ($type == IMAGETYPE_PNG) {
        imagealphablending($destination, false);
        imagesavealpha($destination, true);
        $transparent = imagecolorallocatealpha($destination, 255, 255, 255, 127);
        imagefill($destination, 0, 0, $transparent);
    }
    
    // ปรับขนาด
    imagecopyresampled($destination, $source, 0, 0, 0, 0, $newWidth, $newHeight, $width, $height);
    
    // บันทึกไฟล์
    switch ($type) {
        case IMAGETYPE_JPEG:
            $result = imagejpeg($destination, $filePath, $quality);
            break;
        case IMAGETYPE_PNG:
            $result = imagepng($destination, $filePath);
            break;
        case IMAGETYPE_GIF:
            $result = imagegif($destination, $filePath);
            break;
        default:
            $result = false;
    }
    
    // ล้างหน่วยความจำ
    imagedestroy($source);
    imagedestroy($destination);
    
    return $result;
}

/**
 * ลบไฟล์
 */
function deleteFile($filePath) {
    if (file_exists($filePath)) {
        return unlink($filePath);
    }
    return true;
}

/**
 * ฟังก์ชันจัดการข้อมูล
 */

/**
 * Sanitize input data
 */
function sanitizeInput($data) {
    if (is_array($data)) {
        return array_map('sanitizeInput', $data);
    }
    
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    
    return $data;
}

/**
 * Validate required fields
 */
function validateRequired($fields, $data) {
    $errors = [];
    
    foreach ($fields as $field => $label) {
        if (empty($data[$field])) {
            $errors[] = "กรุณากรอก{$label}";
        }
    }
    
    return $errors;
}

/**
 * Generate slug from Thai text
 */
function generateSlug($text) {
    // ตัวอย่างการแปลงข้อความไทยเป็น slug
    $slug = preg_replace('/[^a-zA-Z0-9ก-๙]/u', '-', $text);
    $slug = preg_replace('/-+/', '-', $slug);
    $slug = trim($slug, '-');
    $slug = strtolower($slug);
    
    return $slug;
}

/**
 * ฟังก์ชันจัดการฐานข้อมูล
 */

/**
 * Execute query with parameters
 */
function executeQuery($sql, $params = []) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        return [
            'success' => true,
            'statement' => $stmt,
            'rowCount' => $stmt->rowCount()
        ];
        
    } catch (Exception $e) {
        writeLog("Query error: " . $e->getMessage() . " | SQL: $sql");
        return [
            'success' => false,
            'error' => $e->getMessage()
        ];
    }
}

/**
 * Get single row from database
 */
function fetchOne($sql, $params = []) {
    $result = executeQuery($sql, $params);
    
    if ($result['success']) {
        return $result['statement']->fetch();
    }
    
    return false;
}

/**
 * Get multiple rows from database
 */
function fetchAll($sql, $params = []) {
    $result = executeQuery($sql, $params);
    
    if ($result['success']) {
        return $result['statement']->fetchAll();
    }
    
    return [];
}

/**
 * Get count from database
 */
function fetchCount($sql, $params = []) {
    $result = executeQuery($sql, $params);
    
    if ($result['success']) {
        return $result['statement']->fetchColumn();
    }
    
    return 0;
}

/**
 * ฟังก์ชันจัดการออเดอร์
 */

/**
 * Calculate order total
 */
function calculateOrderTotal($items) {
    $total = 0;
    
    foreach ($items as $item) {
        $subtotal = $item['price'] * $item['quantity'];
        
        // เพิ่มราคาของตัวเลือก
        if (!empty($item['options'])) {
            foreach ($item['options'] as $option) {
                $subtotal += $option['price_adjustment'] * $item['quantity'];
            }
        }
        
        $total += $subtotal;
    }
    
    return $total;
}

/**
 * Generate receipt number
 */
function generateReceiptNumber() {
    $prefix = 'RCP';
    $date = date('ymd');
    $timestamp = time();
    $random = str_pad(mt_rand(1, 99), 2, '0', STR_PAD_LEFT);
    
    return $prefix . $date . $timestamp . $random;
}

/**
 * Get order status text
 */
function getOrderStatusText($status) {
    $statusMap = [
        'pending' => 'รอยืนยัน',
        'confirmed' => 'ยืนยันแล้ว',
        'preparing' => 'กำลังเตรียม',
        'ready' => 'พร้อมเสิร์ฟ',
        'completed' => 'เสร็จสิ้น',
        'cancelled' => 'ยกเลิก'
    ];
    
    return $statusMap[$status] ?? 'ไม่ทราบสถานะ';
}

/**
 * Get order status class
 */
function getOrderStatusClass($status) {
    $classMap = [
        'pending' => 'bg-warning',
        'confirmed' => 'bg-info',
        'preparing' => 'bg-primary',
        'ready' => 'bg-success',
        'completed' => 'bg-secondary',
        'cancelled' => 'bg-danger'
    ];
    
    return $classMap[$status] ?? 'bg-secondary';
}

/**
 * ฟังก์ชันจัดการเวลา
 */

/**
 * Calculate estimated preparation time
 */
function calculateEstimatedTime($items) {
    $totalTime = 0;
    
    foreach ($items as $item) {
        $itemTime = $item['preparation_time'] ?? 5; // default 5 minutes
        $totalTime = max($totalTime, $itemTime); // ใช้เวลานานที่สุด
    }
    
    // เพิ่มเวลาตามจำนวนรายการ
    $itemCount = array_sum(array_column($items, 'quantity'));
    if ($itemCount > 3) {
        $totalTime += ceil(($itemCount - 3) / 2) * 2; // +2 นาทีทุก 2 รายการเพิ่ม
    }
    
    return max($totalTime, 5); // อย่างน้อย 5 นาที
}

/**
 * Format time duration
 */
function formatDuration($minutes) {
    if ($minutes < 60) {
        return $minutes . ' นาที';
    }
    
    $hours = floor($minutes / 60);
    $mins = $minutes % 60;
    
    return $hours . ' ชั่วโมง' . ($mins > 0 ? ' ' . $mins . ' นาที' : '');
}

/**
 * ฟังก์ชันจัดการการแจ้งเตือน
 */

/**
 * Send notification
 */
function sendNotification($userId, $type, $title, $message, $orderId = null) {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            INSERT INTO notifications (user_id, order_id, type, title, message, created_at) 
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([$userId, $orderId, $type, $title, $message]);
        
        // ส่งการแจ้งเตือนผ่าน LINE (ถ้าเปิดใช้งาน)
        if (defined('LINE_CHANNEL_ACCESS_TOKEN') && !empty(LINE_CHANNEL_ACCESS_TOKEN)) {
            $user = fetchOne("SELECT line_user_id FROM users WHERE user_id = ?", [$userId]);
            if ($user && !empty($user['line_user_id'])) {
                sendLineNotification($user['line_user_id'], $title . "\n" . $message);
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        writeLog("Notification error: " . $e->getMessage());
        return false;
    }
}

/**
 * ฟังก์ชันจัดการรายงาน
 */

/**
 * Generate sales report data
 */
function generateSalesReport($startDate, $endDate, $groupBy = 'day') {
    $groupByMap = [
        'day' => 'DATE(created_at)',
        'week' => 'YEARWEEK(created_at)',
        'month' => 'DATE_FORMAT(created_at, "%Y-%m")',
        'year' => 'YEAR(created_at)'
    ];
    
    $groupByClause = $groupByMap[$groupBy] ?? 'DATE(created_at)';
    
    $sql = "
        SELECT 
            $groupByClause as period,
            COUNT(*) as order_count,
            SUM(total_price) as total_sales,
            AVG(total_price) as avg_order_value
        FROM orders 
        WHERE created_at BETWEEN ? AND ?
        AND payment_status = 'paid'
        GROUP BY $groupByClause
        ORDER BY period ASC
    ";
    
    return fetchAll($sql, [$startDate, $endDate]);
}

/**
 * ฟังก์ชันอรรถประโยชน์
 */

/**
 * Generate QR Code
 */
function generateQRCode($data, $size = 200) {
    // ต้องติดตั้ง phpqrcode library
    if (!class_exists('QRcode')) {
        return false;
    }
    
    $tempFile = TEMP_PATH . 'qr_' . uniqid() . '.png';
    
    try {
        QRcode::png($data, $tempFile, QR_ECLEVEL_M, $size / 25);
        
        $base64 = base64_encode(file_get_contents($tempFile));
        unlink($tempFile);
        
        return 'data:image/png;base64,' . $base64;
        
    } catch (Exception $e) {
        writeLog("QR Code generation error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send email
 */
function sendEmail($to, $subject, $body, $from = null) {
    // ต้องกำหนดค่า email settings ใน config
    if (!defined('SMTP_HOST') || empty(SMTP_HOST)) {
        return false;
    }
    
    // ใช้ PHPMailer หรือ mail() function
    // ตัวอย่างด้วย mail() function
    $headers = "MIME-Version: 1.0" . "\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8" . "\r\n";
    $headers .= 'From: ' . ($from ?: 'noreply@' . $_SERVER['HTTP_HOST']) . "\r\n";
    
    return mail($to, $subject, $body, $headers);
}

/**
 * Check internet connection
 */
function checkInternetConnection() {
    $connected = @fsockopen("www.google.com", 80);
    if ($connected) {
        fclose($connected);
        return true;
    }
    return false;
}

/**
 * Get client IP address
 */
function getClientIP() {
    $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'];
    
    foreach ($ipKeys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) !== false) {
                    return $ip;
                }
            }
        }
    }
    
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/**
 * Get user agent info
 */
function getUserAgent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

/**
 * Check if mobile device
 */
function isMobile() {
    return preg_match('/(android|iphone|ipad|mobile)/i', getUserAgent());
}

/**
 * Generate pagination
 */
function generatePagination($currentPage, $totalPages, $baseUrl, $params = []) {
    if ($totalPages <= 1) {
        return '';
    }
    
    $pagination = '<nav aria-label="Pagination"><ul class="pagination justify-content-center">';
    
    // Previous button
    if ($currentPage > 1) {
        $prevUrl = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $currentPage - 1]));
        $pagination .= '<li class="page-item"><a class="page-link" href="' . $prevUrl . '">ก่อนหน้า</a></li>';
    }
    
    // Page numbers
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);
    
    if ($start > 1) {
        $firstUrl = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => 1]));
        $pagination .= '<li class="page-item"><a class="page-link" href="' . $firstUrl . '">1</a></li>';
        if ($start > 2) {
            $pagination .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
    }
    
    for ($i = $start; $i <= $end; $i++) {
        $pageUrl = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $i]));
        $active = ($i == $currentPage) ? ' active' : '';
        $pagination .= '<li class="page-item' . $active . '"><a class="page-link" href="' . $pageUrl . '">' . $i . '</a></li>';
    }
    
    if ($end < $totalPages) {
        if ($end < $totalPages - 1) {
            $pagination .= '<li class="page-item disabled"><span class="page-link">...</span></li>';
        }
        $lastUrl = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $totalPages]));
        $pagination .= '<li class="page-item"><a class="page-link" href="' . $lastUrl . '">' . $totalPages . '</a></li>';
    }
    
    // Next button
    if ($currentPage < $totalPages) {
        $nextUrl = $baseUrl . '?' . http_build_query(array_merge($params, ['page' => $currentPage + 1]));
        $pagination .= '<li class="page-item"><a class="page-link" href="' . $nextUrl . '">ถัดไป</a></li>';
    }
    
    $pagination .= '</ul></nav>';
    
    return $pagination;
}
?>