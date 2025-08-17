<?php
/**
 * การตั้งค่าระบบหลัก
 * Smart Order Management System
 * อัปเดตสำหรับ macOS XAMPP
 */

// ป้องกันการเข้าถึงโดยตรง
if (!defined('SYSTEM_INIT')) {
    define('SYSTEM_INIT', true);
}

// ข้อมูลระบบ - อัปเดตให้เหมาะกับ environment
define('SITE_URL', 'http://localhost/pos');
define('SITE_NAME', 'ระบบจัดการออเดอร์อัจฉริยะ');
define('SITE_DESCRIPTION', 'ระบบ POS และการจัดการออเดอร์แบบครบวงจร');
define('VERSION', '1.0.0');
define('AUTHOR', 'Smart Order Team');

// การตั้งค่าเขตเวลา
define('TIMEZONE', 'Asia/Bangkok');
date_default_timezone_set(TIMEZONE);

// การตั้งค่าการพัฒนา
define('DEBUG_MODE', true);
define('LOG_ERRORS', true);

// การตั้งค่าไฟล์ - อัปเดตให้เหมาะกับ macOS
define('MAX_FILE_SIZE', 5242880); // 5MB
define('ALLOWED_IMAGE_TYPES', ['jpg', 'jpeg', 'png', 'gif']);
define('UPLOAD_PATH', dirname(__DIR__) . '/uploads/');
define('TEMP_PATH', dirname(__DIR__) . '/uploads/temp/');
define('MENU_IMAGE_PATH', dirname(__DIR__) . '/uploads/menu_images/');

// การตั้งค่าระบบ
define('DEFAULT_LANGUAGE', 'th');
define('DEFAULT_CURRENCY', 'THB');
define('ITEMS_PER_PAGE', 25);

// การตั้งค่าความปลอดภัย
define('HASH_ALGO', 'sha256');
define('SESSION_LIFETIME', 3600); // 1 ชั่วโมง
define('CSRF_TOKEN_LENGTH', 32);

// การตั้งค่าระบบเสียงและ AI
define('VOICE_ENABLED', true);
define('AI_ENABLED', true);

// ฟังก์ชันเริ่มต้นระบบ
function initializeSystem() {
    // ตั้งค่า Error Reporting
    if (DEBUG_MODE) {
        error_reporting(E_ALL);
        ini_set('display_errors', 1);
    } else {
        error_reporting(0);
        ini_set('display_errors', 0);
    }
    
    // ตั้งค่า Session (ไม่เรียก session_start() ที่นี่)
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
    ini_set('session.gc_maxlifetime', SESSION_LIFETIME);
    
    // สร้างโฟลเดอร์ที่จำเป็น
    createRequiredDirectories();
}

// สร้างโฟลเดอร์ที่จำเป็น
function createRequiredDirectories() {
    $directories = [
        UPLOAD_PATH,
        TEMP_PATH,
        MENU_IMAGE_PATH,
        UPLOAD_PATH . 'receipts/',
        dirname(__DIR__) . '/logs/'
    ];
    
    foreach ($directories as $dir) {
        if (!is_dir($dir)) {
            @mkdir($dir, 0777, true); // เพิ่ม @ เพื่อป้องกัน warning
            @chmod($dir, 0777); // ตั้งค่า permission สำหรับ macOS
        }
    }
}

// ฟังก์ชันป้องกัน XSS
function clean($string) {
    if (is_array($string)) {
        return array_map('clean', $string);
    }
    if ($string === null) {
        return '';
    }
    return htmlspecialchars(trim($string), ENT_QUOTES, 'UTF-8');
}

// ฟังก์ชันจัดรูปแบบวันที่
function formatDate($date, $format = 'd/m/Y H:i') {
    if (empty($date) || $date === '0000-00-00 00:00:00') {
        return '-';
    }
    
    $timestamp = is_numeric($date) ? $date : strtotime($date);
    return date($format, $timestamp);
}

// ฟังก์ชันจัดรูปแบบตัวเลข
function formatNumber($number, $decimals = 2) {
    return number_format($number, $decimals);
}

// ฟังก์ชันจัดรูปแบบเงิน
function formatCurrency($amount) {
    return '฿' . number_format($amount, 2);
}

// ฟังก์ชันตรวจสอบการร้องขอ AJAX
function isAjaxRequest() {
    return isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
           strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';
}

// ฟังก์ชันส่ง JSON Response
function sendJsonResponse($data, $httpCode = 200) {
    http_response_code($httpCode);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit();
}

// ฟังก์ชันบันทึก Log - ปรับปรุงสำหรับ macOS
function writeLog($message, $level = 'INFO') {
    if (!LOG_ERRORS) return;
    
    $logDir = dirname(__DIR__) . '/logs/';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
        @chmod($logDir, 0777);
    }
    
    $logFile = $logDir . 'system_' . date('Y-m-d') . '.log';
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $user = 'guest';
    
    // ตรวจสอบว่าฟังก์ชัน getCurrentUserId มีอยู่หรือไม่
    if (function_exists('getCurrentUserId')) {
        $userId = getCurrentUserId();
        if ($userId) {
            $user = 'user_' . $userId;
        }
    }
    
    $logMessage = "[$timestamp] [$level] [$ip] [$user] $message" . PHP_EOL;
    
    // ใช้ try-catch เพื่อป้องกันข้อผิดพลาดในการเขียน log
    try {
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
        @chmod($logFile, 0666);
    } catch (Exception $e) {
        // Silent fail สำหรับการเขียน log
    }
}

// ฟังก์ชันสร้าง CSRF Token
function generateCSRFToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (!isset($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH));
    }
    
    return $_SESSION['csrf_token'];
}

// ฟังก์ชันตรวจสอบ CSRF Token
function verifyCSRFToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    return isset($_SESSION['csrf_token']) && hash_equals($_SESSION['csrf_token'], $token);
}

// ฟังก์ชันได้รับ URL สำหรับล็อกอิน
function getLoginUrl() {
    $currentDir = basename(dirname($_SERVER['PHP_SELF']));
    
    switch ($currentDir) {
        case 'admin':
            return SITE_URL . '/admin/login.php';
        case 'pos':
            return SITE_URL . '/pos/login.php';
        case 'kitchen':
            return SITE_URL . '/kitchen/login.php';
        default:
            return SITE_URL . '/login.php';
    }
}

// ฟังก์ชันตรวจสอบการเชื่อมต่อฐานข้อมูล
function testDatabaseConnection() {
    try {
        require_once dirname(__FILE__) . '/database.php';
        $db = new Database();
        $conn = $db->getConnection();
        
        if ($conn) {
            return true;
        }
        return false;
    } catch (Exception $e) {
        writeLog("Database connection test failed: " . $e->getMessage());
        return false;
    }
}

// เริ่มต้นระบบ
initializeSystem();
?>