<?php
/**
 * API ตรวจสอบสถานะระบบ
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';

// Set content type
header('Content-Type: application/json; charset=utf-8');

// CORS headers
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Handle preflight requests
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

// ตรวจสอบว่าเป็น AJAX request
if (!isAjaxRequest()) {
    sendJsonResponse(['error' => 'Invalid request method'], 400);
}

$check = $_GET['check'] ?? '';

try {
    switch ($check) {
        case 'database':
            echo json_encode(checkDatabaseStatus());
            break;
            
        case 'line':
            echo json_encode(checkLineStatus());
            break;
            
        case 'printer':
            echo json_encode(checkPrinterStatus());
            break;
            
        case 'all':
            echo json_encode([
                'database' => checkDatabaseStatus(),
                'line' => checkLineStatus(),
                'printer' => checkPrinterStatus()
            ]);
            break;
            
        default:
            sendJsonResponse(['error' => 'Invalid check parameter'], 400);
    }
} catch (Exception $e) {
    writeLog("System status check error: " . $e->getMessage());
    sendJsonResponse(['error' => 'System check failed'], 500);
}

/**
 * ตรวจสอบสถานะฐานข้อมูล
 */
function checkDatabaseStatus() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        if ($conn) {
            // ทดสอบการ query
            $stmt = $conn->query("SELECT 1 as test");
            $result = $stmt->fetch();
            
            if ($result && $result['test'] == 1) {
                return [
                    'success' => true,
                    'status' => 'connected',
                    'message' => 'Database connection successful',
                    'timestamp' => date('Y-m-d H:i:s')
                ];
            }
        }
        
        return [
            'success' => false,
            'status' => 'disconnected',
            'message' => 'Database connection failed',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'status' => 'error',
            'message' => 'Database error: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

/**
 * ตรวจสอบสถานะ LINE OA
 */
function checkLineStatus() {
    try {
        // ตรวจสอบว่ามีการตั้งค่า LINE หรือไม่
        if (!defined('LINE_CHANNEL_ACCESS_TOKEN') || empty(LINE_CHANNEL_ACCESS_TOKEN)) {
            return [
                'success' => false,
                'status' => 'not_configured',
                'message' => 'LINE OA not configured',
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
        
        // ตรวจสอบการเชื่อมต่อกับ LINE API (ถ้าต้องการ)
        // สำหรับตอนนี้แสดงสถานะตามการตั้งค่า
        return [
            'success' => true,
            'status' => 'configured',
            'message' => 'LINE OA configured but not tested',
            'timestamp' => date('Y-m-d H:i:s')
        ];
        
    } catch (Exception $e) {
        return [
            'success' => false,
            'status' => 'error',
            'message' => 'LINE check error: ' . $e->getMessage(),
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

/**
 * ตรวจสอบสถานะเครื่องพิมพ์
 */
function checkPrinterStatus() {
    try {
        // ตรวจสอบการตั้งค่าเครื่องพิมพ์จากฐานข้อมูล
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("
            SELECT setting_value 
            FROM system_settings 
            WHERE setting_key = 'printer_enabled'
        ");
        $stmt->execute();
        $printerEnabled = $stmt->fetchColumn();
        
        if ($printerEnabled === '1' || $printerEnabled === 'true') {
            return [
                'success' => true,
                'status' => 'configured',
                'message' => 'Printer configured',
                'timestamp' => date('Y-m-d H:i:s')
            ];
        } else {
            return [
                'success' => false,
                'status' => 'not_configured',
                'message' => 'Printer not configured',
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
        
    } catch (Exception $e) {
        // ถ้าไม่มีตาราง system_settings ยัง
        return [
            'success' => false,
            'status' => 'not_configured',
            'message' => 'Printer configuration not available',
            'timestamp' => date('Y-m-d H:i:s')
        ];
    }
}

/**
 * ตรวจสอบสถานะโดยรวม
 */
function getSystemOverallStatus() {
    $database = checkDatabaseStatus();
    $line = checkLineStatus();
    $printer = checkPrinterStatus();
    
    $allSuccessful = $database['success'] && $line['success'] && $printer['success'];
    
    return [
        'success' => $allSuccessful,
        'status' => $allSuccessful ? 'healthy' : 'issues',
        'components' => [
            'database' => $database,
            'line' => $line,
            'printer' => $printer
        ],
        'timestamp' => date('Y-m-d H:i:s')
    ];
}
?>