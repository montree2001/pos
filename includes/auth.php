<?php
/**
 * การตรวจสอบสิทธิ์และการรักษาความปลอดภัย
 * Smart Order Management System
 */

// ป้องกันการเข้าถึงโดยตรง
if (!defined('SYSTEM_INIT')) {
    die('Direct access not allowed');
}

/**
 * คลาสจัดการสิทธิ์และความปลอดภัย
 */
class AuthManager {
    
    /**
     * ตรวจสอบสิทธิ์การเข้าถึงหน้าต่างๆ
     */
    public static function checkPageAccess($requiredRole = null, $requiredPermissions = []) {
        // ตรวจสอบว่าล็อกอินแล้วหรือไม่
        if (!UserSession::isLoggedIn()) {
            self::redirectToLogin();
        }
        
        // ตรวจสอบสถานะผู้ใช้
        if (!self::isUserActive()) {
            self::handleInactiveUser();
        }
        
        // ตรวจสอบ session timeout
        if (!self::checkSessionTimeout()) {
            self::handleSessionTimeout();
        }
        
        // ตรวจสอบบทบาท
        if ($requiredRole && !UserSession::hasRole($requiredRole)) {
            self::handleUnauthorized();
        }
        
        // ตรวจสอบสิทธิ์เฉพาะ
        if (!empty($requiredPermissions)) {
            foreach ($requiredPermissions as $permission) {
                if (!self::hasPermission($permission)) {
                    self::handleUnauthorized();
                }
            }
        }
        
        // อัปเดตเวลาล่าสุด
        SessionManager::set('last_activity', time());
        
        return true;
    }
    
    /**
     * ตรวจสอบสถานะผู้ใช้ในฐานข้อมูล
     */
    private static function isUserActive() {
        try {
            $userId = UserSession::getUserId();
            if (!$userId) return false;
            
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("SELECT status FROM users WHERE user_id = ?");
            $stmt->execute([$userId]);
            $user = $stmt->fetch();
            
            return $user && $user['status'] === 'active';
            
        } catch (Exception $e) {
            writeLog("Error checking user status: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ตรวจสอบ session timeout
     */
    private static function checkSessionTimeout() {
        $lastActivity = SessionManager::get('last_activity', 0);
        $currentTime = time();
        $timeout = 3600; // 1 ชั่วโมง
        
        return ($currentTime - $lastActivity) < $timeout;
    }
    
    /**
     * ตรวจสอบสิทธิ์เฉพาะ
     */
    private static function hasPermission($permission) {
        $userRole = UserSession::getRole();
        
        // Admin มีสิทธิ์ทุกอย่าง
        if ($userRole === 'admin') {
            return true;
        }
        
        // กำหนดสิทธิ์ตามบทบาท
        $rolePermissions = [
            'staff' => [
                'pos.access',
                'pos.create_order',
                'pos.payment',
                'pos.print_receipt',
                'queue.view',
                'queue.call'
            ],
            'kitchen' => [
                'kitchen.access',
                'kitchen.view_orders',
                'kitchen.update_status',
                'queue.view'
            ],
            'customer' => [
                'customer.access',
                'customer.order',
                'customer.view_queue',
                'customer.payment'
            ]
        ];
        
        $permissions = $rolePermissions[$userRole] ?? [];
        return in_array($permission, $permissions);
    }
    
    /**
     * เปลี่ยนเส้นทางไปหน้าล็อกอิน
     */
    private static function redirectToLogin() {
        if (isAjaxRequest()) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'กรุณาเข้าสู่ระบบ',
                'redirect' => getLoginUrl()
            ]);
            exit();
        } else {
            header('Location: ' . getLoginUrl());
            exit();
        }
    }
    
    /**
     * จัดการผู้ใช้ที่ถูกระงับ
     */
    private static function handleInactiveUser() {
        UserSession::logout();
        setFlashMessage('error', 'บัญชีของคุณถูกระงับ กรุณาติดต่อผู้ดูแลระบบ');
        
        if (isAjaxRequest()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'บัญชีถูกระงับ',
                'redirect' => getLoginUrl()
            ]);
            exit();
        } else {
            header('Location: ' . getLoginUrl());
            exit();
        }
    }
    
    /**
     * จัดการ session timeout
     */
    private static function handleSessionTimeout() {
        UserSession::logout();
        setFlashMessage('warning', 'เซสชันหมดอายุ กรุณาเข้าสู่ระบบใหม่');
        
        if (isAjaxRequest()) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'เซสชันหมดอายุ',
                'redirect' => getLoginUrl()
            ]);
            exit();
        } else {
            header('Location: ' . getLoginUrl());
            exit();
        }
    }
    
    /**
     * จัดการการเข้าถึงที่ไม่ได้รับอนุญาต
     */
    private static function handleUnauthorized() {
        if (isAjaxRequest()) {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'คุณไม่มีสิทธิ์เข้าถึงส่วนนี้'
            ]);
            exit();
        } else {
            setFlashMessage('error', 'คุณไม่มีสิทธิ์เข้าถึงหน้านี้');
            header('Location: ' . self::getUnauthorizedRedirect());
            exit();
        }
    }
    
    /**
     * หาหน้าที่เหมาะสมสำหรับการ redirect เมื่อไม่มีสิทธิ์
     */
    private static function getUnauthorizedRedirect() {
        $role = UserSession::getRole();
        
        switch ($role) {
            case 'admin':
                return SITE_URL . '/admin/';
            case 'staff':
                return SITE_URL . '/pos/';
            case 'kitchen':
                return SITE_URL . '/kitchen/';
            case 'customer':
                return SITE_URL . '/customer/';
            default:
                return SITE_URL . '/';
        }
    }
}

/**
 * คลาสจัดการรหัสผ่าน
 */
class PasswordManager {
    
    /**
     * สร้างรหัสผ่านที่แข็งแกร่ง
     */
    public static function generateStrongPassword($length = 12) {
        $uppercase = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $lowercase = 'abcdefghijklmnopqrstuvwxyz';
        $numbers = '0123456789';
        $symbols = '!@#$%^&*()_+-=[]{}|;:,.<>?';
        
        $all = $uppercase . $lowercase . $numbers . $symbols;
        $password = '';
        
        // ให้มีอย่างน้อยหนึ่งตัวจากแต่ละประเภท
        $password .= $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];
        
        // เติมตัวอักษรที่เหลือ
        for ($i = 4; $i < $length; $i++) {
            $password .= $all[random_int(0, strlen($all) - 1)];
        }
        
        return str_shuffle($password);
    }
    
    /**
     * ตรวจสอบความแข็งแกร่งของรหัสผ่าน
     */
    public static function checkPasswordStrength($password) {
        $score = 0;
        $feedback = [];
        
        // ความยาว
        if (strlen($password) >= 8) {
            $score += 1;
        } else {
            $feedback[] = 'รหัสผ่านควรมีอย่างน้อย 8 ตัวอักษร';
        }
        
        // ตัวพิมพ์เล็ก
        if (preg_match('/[a-z]/', $password)) {
            $score += 1;
        } else {
            $feedback[] = 'ควรมีตัวอักษรพิมพ์เล็ก';
        }
        
        // ตัวพิมพ์ใหญ่
        if (preg_match('/[A-Z]/', $password)) {
            $score += 1;
        } else {
            $feedback[] = 'ควรมีตัวอักษรพิมพ์ใหญ่';
        }
        
        // ตัวเลข
        if (preg_match('/[0-9]/', $password)) {
            $score += 1;
        } else {
            $feedback[] = 'ควรมีตัวเลข';
        }
        
        // สัญลักษณ์
        if (preg_match('/[^a-zA-Z0-9]/', $password)) {
            $score += 1;
        } else {
            $feedback[] = 'ควรมีสัญลักษณ์พิเศษ';
        }
        
        // ประเมินผล
        $strength = 'อ่อนแอ';
        $class = 'danger';
        
        if ($score >= 4) {
            $strength = 'แข็งแกร่ง';
            $class = 'success';
        } elseif ($score >= 3) {
            $strength = 'ปานกลาง';
            $class = 'warning';
        }
        
        return [
            'score' => $score,
            'strength' => $strength,
            'class' => $class,
            'feedback' => $feedback
        ];
    }
    
    /**
     * Hash รหัสผ่าน
     */
    public static function hashPassword($password) {
        return password_hash($password, PASSWORD_DEFAULT);
    }
    
    /**
     * ตรวจสอบรหัสผ่าน
     */
    public static function verifyPassword($password, $hash) {
        return password_verify($password, $hash);
    }
    
    /**
     * ตรวจสอบว่ารหัสผ่านต้องการ rehash หรือไม่
     */
    public static function needsRehash($hash) {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}

/**
 * คลาสป้องกัน Brute Force Attack
 */
class BruteForceProtection {
    
    private static $maxAttempts = 5;
    private static $lockoutTime = 900; // 15 นาที
    
    /**
     * บันทึกความพยายามล็อกอินที่ล้มเหลว
     */
    public static function recordFailedAttempt($username, $ipAddress = null) {
        try {
            if (!$ipAddress) {
                $ipAddress = getClientIP();
            }
            
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                INSERT INTO login_attempts (ip_address, username, attempt_time) 
                VALUES (?, ?, NOW())
            ");
            $stmt->execute([$ipAddress, $username]);
            
        } catch (Exception $e) {
            writeLog("Error recording failed attempt: " . $e->getMessage());
        }
    }
    
    /**
     * ตรวจสอบว่า IP นี้ถูกล็อกหรือไม่
     */
    public static function isBlocked($ipAddress = null) {
        try {
            if (!$ipAddress) {
                $ipAddress = getClientIP();
            }
            
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                SELECT COUNT(*) as attempts 
                FROM login_attempts 
                WHERE ip_address = ? 
                AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$ipAddress, self::$lockoutTime]);
            
            $result = $stmt->fetch();
            return $result['attempts'] >= self::$maxAttempts;
            
        } catch (Exception $e) {
            writeLog("Error checking blocked IP: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ล้างความพยายามล็อกอินที่ล้มเหลว (เมื่อล็อกอินสำเร็จ)
     */
    public static function clearFailedAttempts($username, $ipAddress = null) {
        try {
            if (!$ipAddress) {
                $ipAddress = getClientIP();
            }
            
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                DELETE FROM login_attempts 
                WHERE (ip_address = ? OR username = ?)
                AND attempt_time > DATE_SUB(NOW(), INTERVAL ? SECOND)
            ");
            $stmt->execute([$ipAddress, $username, self::$lockoutTime]);
            
        } catch (Exception $e) {
            writeLog("Error clearing failed attempts: " . $e->getMessage());
        }
    }
    
    /**
     * ได้รับเวลาที่เหลือของการล็อก
     */
    public static function getRemainingLockTime($ipAddress = null) {
        try {
            if (!$ipAddress) {
                $ipAddress = getClientIP();
            }
            
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                SELECT TIMESTAMPDIFF(SECOND, NOW(), DATE_ADD(attempt_time, INTERVAL ? SECOND)) as remaining
                FROM login_attempts 
                WHERE ip_address = ? 
                ORDER BY attempt_time DESC 
                LIMIT 1
            ");
            $stmt->execute([self::$lockoutTime, $ipAddress]);
            
            $result = $stmt->fetch();
            return max(0, $result['remaining'] ?? 0);
            
        } catch (Exception $e) {
            writeLog("Error getting remaining lock time: " . $e->getMessage());
            return 0;
        }
    }
}

/**
 * คลาส Two-Factor Authentication (ถ้าต้องการใช้)
 */
class TwoFactorAuth {
    
    /**
     * สร้างรหัส OTP
     */
    public static function generateOTP($length = 6) {
        $otp = '';
        for ($i = 0; $i < $length; $i++) {
            $otp .= random_int(0, 9);
        }
        return $otp;
    }
    
    /**
     * ส่งรหัส OTP ทาง SMS (ต้องผสานกับ SMS Gateway)
     */
    public static function sendOTPBySMS($phoneNumber, $otp) {
        // TODO: ผสานกับ SMS Gateway
        return false;
    }
    
    /**
     * ส่งรหัส OTP ทาง Email
     */
    public static function sendOTPByEmail($email, $otp) {
        $subject = 'รหัสยืนยันตัวตน - ' . SITE_NAME;
        $body = "
            <h3>รหัสยืนยันตัวตน</h3>
            <p>รหัส OTP ของคุณคือ: <strong>$otp</strong></p>
            <p>รหัสนี้จะหมดอายุใน 5 นาที</p>
            <p>หากคุณไม่ได้ขอรหัสนี้ กรุณาเพิกเฉย</p>
        ";
        
        return sendEmail($email, $subject, $body);
    }
    
    /**
     * บันทึกรหัส OTP ในฐานข้อมูล
     */
    public static function storeOTP($userId, $otp, $expiresIn = 300) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                INSERT INTO user_otp (user_id, otp, expires_at) 
                VALUES (?, ?, DATE_ADD(NOW(), INTERVAL ? SECOND))
                ON DUPLICATE KEY UPDATE 
                otp = VALUES(otp), 
                expires_at = VALUES(expires_at)
            ");
            $stmt->execute([$userId, hash('sha256', $otp), $expiresIn]);
            
            return true;
            
        } catch (Exception $e) {
            writeLog("Error storing OTP: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * ตรวจสอบรหัส OTP
     */
    public static function verifyOTP($userId, $otp) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                SELECT * FROM user_otp 
                WHERE user_id = ? AND otp = ? AND expires_at > NOW()
            ");
            $stmt->execute([$userId, hash('sha256', $otp)]);
            
            $result = $stmt->fetch();
            
            if ($result) {
                // ลบ OTP หลังจากใช้งาน
                $deleteStmt = $conn->prepare("DELETE FROM user_otp WHERE user_id = ?");
                $deleteStmt->execute([$userId]);
                
                return true;
            }
            
            return false;
            
        } catch (Exception $e) {
            writeLog("Error verifying OTP: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * ฟังก์ชันช่วยสำหรับการใช้งาน
 */

/**
 * ตรวจสอบสิทธิ์และเปลี่ยนเส้นทางถ้าจำเป็น
 */
function requireAuth($role = null, $permissions = []) {
    return AuthManager::checkPageAccess($role, $permissions);
}

/**
 * ตรวจสอบสิทธิ์สำหรับ AJAX
 */
function requireAuthAjax($role = null, $permissions = []) {
    try {
        return AuthManager::checkPageAccess($role, $permissions);
    } catch (Exception $e) {
        // จะส่ง JSON response และ exit() แล้ว
        return false;
    }
}

/**
 * ตรวจสอบว่าถูกบล็อกหรือไม่
 */
function checkBruteForce() {
    if (BruteForceProtection::isBlocked()) {
        $remainingTime = BruteForceProtection::getRemainingLockTime();
        $minutes = ceil($remainingTime / 60);
        
        if (isAjaxRequest()) {
            http_response_code(429);
            echo json_encode([
                'success' => false,
                'message' => "คุณถูกล็อกเนื่องจากพยายามล็อกอินหลายครั้ง กรุณารอ $minutes นาที"
            ]);
            exit();
        } else {
            setFlashMessage('error', "คุณถูกล็อกเนื่องจากพยายามล็อกอินหลายครั้ง กรุณารอ $minutes นาที");
            header('Location: login.php');
            exit();
        }
    }
}

/**
 * รีเซ็ตรหัสผ่าน
 */
function resetPassword($userId, $newPassword) {
    try {
        $hashedPassword = PasswordManager::hashPassword($newPassword);
        
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("UPDATE users SET password = ? WHERE user_id = ?");
        $stmt->execute([$hashedPassword, $userId]);
        
        writeLog("Password reset for user ID: $userId");
        return true;
        
    } catch (Exception $e) {
        writeLog("Error resetting password: " . $e->getMessage());
        return false;
    }
}


// ป้องกันการเข้าถึงโดยตรง
if (!defined('SYSTEM_INIT')) {
    die('Direct access not allowed');
}

/**
 * ฟังก์ชันช่วยเหลือ - เพิ่มเพื่อแก้ไขปัญหา undefined function
 */
if (!function_exists('getClientIP')) {
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
}

if (!function_exists('writeLog')) {
    function writeLog($message, $level = 'INFO') {
        // Simple log function for auth.php if not already defined
        $logDir = dirname(__DIR__) . '/logs/';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        
        $logFile = $logDir . 'auth_' . date('Y-m-d') . '.log';
        $timestamp = date('Y-m-d H:i:s');
        $logMessage = "[$timestamp] [$level] $message" . PHP_EOL;
        
        @file_put_contents($logFile, $logMessage, FILE_APPEND | LOCK_EX);
    }
}
?>