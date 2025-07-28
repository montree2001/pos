<?php
/**
 * ออกจากระบบครัว
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';

// ตรวจสอบว่าล็อกอินแล้วหรือไม่
if (!isLoggedIn()) {
    header('Location: login.php');
    exit();
}

try {
    // บันทึก Log การออกจากระบบ
    $userId = getCurrentUserId();
    $username = getCurrentUser()['username'];
    writeLog("Kitchen user logout: $username (ID: $userId)");
    
    // ลบ Remember Me Token (ถ้ามี)
    if (isset($_COOKIE['remember_token'])) {
        $db = new Database();
        $conn = $db->getConnection();
        
        $token = $_COOKIE['remember_token'];
        $hashedToken = hash('sha256', $token);
        
        // ลบ token จากฐานข้อมูล
        $stmt = $conn->prepare("DELETE FROM user_tokens WHERE user_id = ? AND token = ?");
        $stmt->execute([$userId, $hashedToken]);
        
        // ลบ cookie
        setcookie('remember_token', '', time() - 3600, '/', '', true, true);
    }
    
    // ออกจากระบบ
    UserSession::logout();
    
    // แสดงข้อความสำเร็จ
    setFlashMessage('success', 'ออกจากระบบเรียบร้อยแล้ว');
    
} catch (Exception $e) {
    writeLog("Kitchen logout error: " . $e->getMessage());
    setFlashMessage('error', 'เกิดข้อผิดพลาดในการออกจากระบบ');
}

// เปลี่ยนเส้นทางไปหน้าล็อกอิน
header('Location: login.php');
exit();
?>