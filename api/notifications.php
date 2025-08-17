<?php
/**
 * API จัดการการแจ้งเตือน
 * Smart Order Management System - Fixed Version
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

// Set content type
header('Content-Type: application/json; charset=utf-8');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-TOKEN, X-Requested-With');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ตรวจสอบการเข้าถึง - ปรับปรุงให้ไม่ error เมื่อไม่ใช่ AJAX
$isAjax = isset($_SERVER['HTTP_X_REQUESTED_WITH']) && 
          strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest';

if (!$isAjax && !isset($_GET['api'])) {
    // ถ้าไม่ใช่ AJAX และไม่มี ?api ให้ส่งกลับ empty response แทน error
    sendJsonResponse(['success' => false, 'message' => 'API endpoint'], 200);
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$method = $_SERVER['REQUEST_METHOD'];

try {
    // ตรวจสอบการล็อกอิน
    if (!isLoggedIn()) {
        sendJsonResponse([
            'success' => false, 
            'message' => 'กรุณาเข้าสู่ระบบ',
            'notifications' => []
        ], 200); // ส่ง 200 แทน 401 เพื่อไม่ให้ trigger error handler
    }
    
    $db = new Database();
    $conn = $db->getConnection();
    
    if (!$conn) {
        sendJsonResponse([
            'success' => false,
            'message' => 'ไม่สามารถเชื่อมต่อฐานข้อมูลได้',
            'notifications' => []
        ], 200);
    }
    
    switch ($action) {
        case 'list':
        default:
            echo json_encode(getNotifications($conn));
            break;
            
        case 'mark_read':
            if ($method === 'POST') {
                $notificationId = $_POST['notification_id'] ?? 0;
                echo json_encode(markNotificationAsRead($conn, $notificationId));
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 200);
            }
            break;
            
        case 'mark_all_read':
            if ($method === 'POST') {
                echo json_encode(markAllNotificationsAsRead($conn));
            } else {
                sendJsonResponse(['success' => false, 'message' => 'Method not allowed'], 200);
            }
            break;
    }
    
} catch (Exception $e) {
    writeLog("Notifications API error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false, 
        'message' => 'เกิดข้อผิดพลาดภายในระบบ',
        'notifications' => []
    ], 200); // ส่ง 200 แทน 500
}

/**
 * ดึงรายการการแจ้งเตือน
 */
function getNotifications($conn, $limit = 10) {
    try {
        $userId = getCurrentUserId();
        if (!$userId) {
            return [
                'success' => false,
                'message' => 'ไม่พบข้อมูลผู้ใช้',
                'notifications' => []
            ];
        }
        
        // ตรวจสอบว่าตาราง notifications มีอยู่หรือไม่
        $tableExists = $conn->query("SHOW TABLES LIKE 'notifications'")->rowCount() > 0;
        if (!$tableExists) {
            return [
                'success' => true,
                'message' => 'ไม่มีการแจ้งเตือน',
                'notifications' => []
            ];
        }
        
        $stmt = $conn->prepare("
            SELECT notification_id, type, title, message, is_read, created_at
            FROM notifications 
            WHERE user_id = ? 
            ORDER BY created_at DESC 
            LIMIT ?
        ");
        $stmt->execute([$userId, $limit]);
        $notifications = $stmt->fetchAll();
        
        return [
            'success' => true,
            'notifications' => $notifications ?: []
        ];
        
    } catch (Exception $e) {
        writeLog("Error getting notifications: " . $e->getMessage());
        return [
            'success' => false,
            'message' => 'ไม่สามารถโหลดการแจ้งเตือนได้',
            'notifications' => []
        ];
    }
}

/**
 * ทำเครื่องหมายว่าอ่านแล้ว
 */
function markNotificationAsRead($conn, $notificationId) {
    try {
        $userId = getCurrentUserId();
        
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE notification_id = ? AND user_id = ?
        ");
        $stmt->execute([$notificationId, $userId]);
        
        return [
            'success' => true,
            'message' => 'ทำเครื่องหมายว่าอ่านแล้ว'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'ไม่สามารถอัปเดตได้'
        ];
    }
}

/**
 * ทำเครื่องหมายว่าอ่านทั้งหมด
 */
function markAllNotificationsAsRead($conn) {
    try {
        $userId = getCurrentUserId();
        
        $stmt = $conn->prepare("
            UPDATE notifications 
            SET is_read = 1 
            WHERE user_id = ? AND is_read = 0
        ");
        $stmt->execute([$userId]);
        
        return [
            'success' => true,
            'message' => 'ทำเครื่องหมายว่าอ่านทั้งหมดแล้ว'
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'message' => 'ไม่สามารถอัปเดตได้'
        ];
    }
}
?>