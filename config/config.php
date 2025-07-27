
<?php
// config/config.php - การตั้งค่าระบบ
define('SITE_URL', 'http://localhost/pos');
define('SITE_NAME', 'ระบบจัดการออเดอร์อัจฉริยะ');
define('VERSION', '1.0.0');

// การตั้งค่าทั่วไป
define('TIMEZONE', 'Asia/Bangkok');
date_default_timezone_set(TIMEZONE);

// การตั้งค่าไฟล์
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);

// การตั้งค่า Session
ini_set('session.cookie_httponly', 1);
ini_set('session.use_only_cookies', 1);
session_start();

// ฟังก์ชันตรวจสอบการล็อกอิน
function checkLogin() {
    if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
        header('Location: login.php');
        exit();
    }
}

// ฟังก์ชันป้องกัน XSS
function clean($string) {
    return htmlspecialchars($string, ENT_QUOTES, 'UTF-8');
}

// ฟังก์ชันจัดรูปแบบวันที่
function formatDate($date) {
    return date('d/m/Y H:i', strtotime($date));
}

// ฟังก์ชันจัดรูปแบบตัวเลข
function formatNumber($number) {
    return number_format($number, 2);
}
?>
