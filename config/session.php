<?php
/**
 * การจัดการ Session
 * Smart Order Management System
 */

// ป้องกันการเข้าถึงโดยตรง
if (!defined('SYSTEM_INIT')) {
    die('Direct access not allowed');
}

/**
 * คลาสจัดการ Session
 */
class SessionManager {
    
    /**
     * เริ่มต้น Session
     */
    public static function start() {
        if (session_status() === PHP_SESSION_NONE) {
            // การตั้งค่าความปลอดภัย
            ini_set('session.cookie_httponly', 1);
            ini_set('session.use_only_cookies', 1);
            ini_set('session.cookie_secure', isset($_SERVER['HTTPS']));
            ini_set('session.gc_maxlifetime', 3600); // 1 ชั่วโมง
            
            session_start();
            
            // ป้องกัน Session Hijacking
            if (!isset($_SESSION['created'])) {
                $_SESSION['created'] = time();
            } else if (time() - $_SESSION['created'] > 1800) { // 30 นาที
                session_regenerate_id(true);
                $_SESSION['created'] = time();
            }
        }
    }
    
    /**
     * ตั้งค่า Session
     */
    public static function set($key, $value) {
        self::start();
        $_SESSION[$key] = $value;
    }
    
    /**
     * อ่านค่า Session
     */
    public static function get($key, $default = null) {
        self::start();
        return isset($_SESSION[$key]) ? $_SESSION[$key] : $default;
    }
    
    /**
     * ลบ Session
     */
    public static function remove($key) {
        self::start();
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }
    
    /**
     * ตรวจสอบว่ามี Session หรือไม่
     */
    public static function has($key) {
        self::start();
        return isset($_SESSION[$key]);
    }
    
    /**
     * ล้าง Session ทั้งหมด
     */
    public static function destroy() {
        self::start();
        session_unset();
        session_destroy();
        
        // ลบ Cookie
        if (ini_get("session.use_cookies")) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000,
                $params["path"], $params["domain"],
                $params["secure"], $params["httponly"]
            );
        }
    }
    
    /**
     * สร้าง Session ID ใหม่
     */
    public static function regenerateId() {
        self::start();
        session_regenerate_id(true);
        $_SESSION['created'] = time();
    }
    
    /**
     * ตั้งค่า Flash Message
     */
    public static function setFlash($type, $message) {
        self::start();
        $_SESSION['flash'][$type] = $message;
    }
    
    /**
     * อ่าน Flash Message
     */
    public static function getFlash($type = null) {
        self::start();
        
        if ($type) {
            $message = isset($_SESSION['flash'][$type]) ? $_SESSION['flash'][$type] : null;
            unset($_SESSION['flash'][$type]);
            return $message;
        }
        
        $messages = isset($_SESSION['flash']) ? $_SESSION['flash'] : [];
        unset($_SESSION['flash']);
        return $messages;
    }
    
    /**
     * ตรวจสอบว่ามี Flash Message หรือไม่
     */
    public static function hasFlash($type = null) {
        self::start();
        
        if ($type) {
            return isset($_SESSION['flash'][$type]);
        }
        
        return isset($_SESSION['flash']) && !empty($_SESSION['flash']);
    }
}

/**
 * คลาสจัดการ User Session
 */
class UserSession {
    
    /**
     * เข้าสู่ระบบ
     */
    public static function login($userData) {
        SessionManager::start();
        
        $_SESSION['user_id'] = $userData['user_id'];
        $_SESSION['username'] = $userData['username'];
        $_SESSION['fullname'] = $userData['fullname'];
        $_SESSION['email'] = $userData['email'];
        $_SESSION['role'] = $userData['role'];
        $_SESSION['login_time'] = time();
        $_SESSION['last_activity'] = time();
        
        // สร้าง Session ID ใหม่เพื่อความปลอดภัย
        SessionManager::regenerateId();
        
        // บันทึก Log
        writeLog("User login: {$userData['username']} (ID: {$userData['user_id']})");
    }
    
    /**
     * ออกจากระบบ
     */
    public static function logout() {
        $username = self::getUsername();
        $userId = self::getUserId();
        
        SessionManager::destroy();
        
        // บันทึก Log
        writeLog("User logout: $username (ID: $userId)");
    }
    
    /**
     * ตรวจสอบการล็อกอิน
     */
    public static function isLoggedIn() {
        return SessionManager::has('user_id');
    }
    
    /**
     * ตรวจสอบบทบาท
     */
    public static function hasRole($role) {
        return self::getRole() === $role;
    }
    
    /**
     * ตรวจสอบสิทธิ์
     */
    public static function checkPermission($requiredRole) {
        if (!self::isLoggedIn()) {
            return false;
        }
        
        if ($requiredRole && !self::hasRole($requiredRole)) {
            return false;
        }
        
        // ตรวจสอบ Session timeout
        $lastActivity = SessionManager::get('last_activity', 0);
        if (time() - $lastActivity > 3600) { // 1 ชั่วโมง
            self::logout();
            return false;
        }
        
        // อัปเดตเวลาล่าสุด
        SessionManager::set('last_activity', time());
        
        return true;
    }
    
    /**
     * ได้รับ User ID
     */
    public static function getUserId() {
        return SessionManager::get('user_id');
    }
    
    /**
     * ได้รับ Username
     */
    public static function getUsername() {
        return SessionManager::get('username');
    }
    
    /**
     * ได้รับ Fullname
     */
    public static function getFullname() {
        return SessionManager::get('fullname');
    }
    
    /**
     * ได้รับ Email
     */
    public static function getEmail() {
        return SessionManager::get('email');
    }
    
    /**
     * ได้รับ Role
     */
    public static function getRole() {
        return SessionManager::get('role');
    }
    
    /**
     * ได้รับเวลาล็อกอิน
     */
    public static function getLoginTime() {
        return SessionManager::get('login_time');
    }
    
    /**
     * ได้รับข้อมูล User ทั้งหมด
     */
    public static function getUserData() {
        return [
            'user_id' => self::getUserId(),
            'username' => self::getUsername(),
            'fullname' => self::getFullname(),
            'email' => self::getEmail(),
            'role' => self::getRole(),
            'login_time' => self::getLoginTime()
        ];
    }
}

/**
 * คลาสจัดการ Cart Session (สำหรับลูกค้า)
 */
class CartSession {
    
    const CART_KEY = 'shopping_cart';
    
    /**
     * เพิ่มสินค้าลงตะกร้า
     */
    public static function addItem($productId, $quantity = 1, $options = []) {
        SessionManager::start();
        
        if (!isset($_SESSION[self::CART_KEY])) {
            $_SESSION[self::CART_KEY] = [];
        }
        
        $itemKey = $productId . '_' . md5(serialize($options));
        
        if (isset($_SESSION[self::CART_KEY][$itemKey])) {
            $_SESSION[self::CART_KEY][$itemKey]['quantity'] += $quantity;
        } else {
            $_SESSION[self::CART_KEY][$itemKey] = [
                'product_id' => $productId,
                'quantity' => $quantity,
                'options' => $options,
                'added_at' => time()
            ];
        }
        return true;
    }
    
    /**
     * อัปเดตจำนวนสินค้า
     */
    public static function updateQuantity($itemKey, $quantity) {
        SessionManager::start();
        
        if ($quantity <= 0) {
            return self::removeItem($itemKey);
        } else {
            if (isset($_SESSION[self::CART_KEY][$itemKey])) {
                $_SESSION[self::CART_KEY][$itemKey]['quantity'] = $quantity;
                return true;
            }
        }
        return false;
    }
    
    /**
     * ลบสินค้าออกจากตะกร้า
     */
    public static function removeItem($itemKey) {
        SessionManager::start();
        
        if (isset($_SESSION[self::CART_KEY][$itemKey])) {
            unset($_SESSION[self::CART_KEY][$itemKey]);
            return true;
        }
        return false;
    }
    
    /**
     * ล้างตะกร้าทั้งหมด
     */
    public static function clear() {
        SessionManager::remove(self::CART_KEY);
        return true;
    }
    
    /**
     * ได้รับรายการในตะกร้า
     */
    public static function getItems() {
        return SessionManager::get(self::CART_KEY, []);
    }
    
    /**
     * นับจำนวนสินค้าในตะกร้า
     */
    public static function getItemCount() {
        $items = self::getItems();
        $count = 0;
        
        foreach ($items as $item) {
            $count += $item['quantity'];
        }
        
        return $count;
    }
    
    /**
     * ตรวจสอบว่าตะกร้าว่างหรือไม่
     */
    public static function isEmpty() {
        return empty(self::getItems());
    }
}

/**
 * ฟังก์ชันช่วยสำหรับการจัดการ Session
 */

// เริ่มต้น Session
function startSession() {
    SessionManager::start();
}

// Flash Messages
function setFlashMessage($type, $message) {
    SessionManager::setFlash($type, $message);
}

function getFlashMessage($type = null) {
    return SessionManager::getFlash($type);
}

function hasFlashMessage($type = null) {
    return SessionManager::hasFlash($type);
}

// User Session
function isLoggedIn() {
    return UserSession::isLoggedIn();
}

function requireLogin($role = null) {
    if (!UserSession::checkPermission($role)) {
        if (isAjaxRequest()) {
            sendJsonResponse(['error' => 'กรุณาเข้าสู่ระบบ'], 401);
        } else {
            header('Location: ' . getLoginUrl());
            exit();
        }
    }
}

function getCurrentUser() {
    return UserSession::getUserData();
}

function getCurrentUserId() {
    return UserSession::getUserId();
}

function getCurrentUserRole() {
    return UserSession::getRole();
}

// Cart Session
function addToCart($productId, $quantity = 1, $options = []) {
    return CartSession::addItem($productId, $quantity, $options);
}

function getCartItems() {
    return CartSession::getItems();
}

function getCartItemCount() {
    return CartSession::getItemCount();
}

function updateCartQuantity($itemKey, $quantity) {
    return CartSession::updateQuantity($itemKey, $quantity);
}

function removeFromCart($itemKey) {
    return CartSession::removeItem($itemKey);
}

function clearCart() {
    return CartSession::clear();
}

// เริ่มต้น Session อัตโนมัติ
SessionManager::start();
?>