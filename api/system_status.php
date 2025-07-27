<?php
/**
 * API ตรวจสอบสถานะระบบ
 * Smart Order Management System - Fixed Version
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

// ไม่ต้องตรวจสอบ AJAX request เข้มงวด
$check = $_GET['check'] ?? '';

try {
    switch ($check) {
        case 'database':
            sendJsonResponse(checkDatabaseStatus());
            break;
            
        case 'line':
            sendJsonResponse(checkLineStatus());
            break;
            
        case 'printer':
            sendJsonResponse(checkPrinterStatus());
            break;
            
        case 'all':
            sendJsonResponse([
                'database' => checkDatabaseStatus(),
                'line' => checkLineStatus(),
                'printer' => checkPrinterStatus()
            ]);
            break;
            
        default:
            sendJsonResponse([
                'success' => false, 
                'error' => 'Invalid check parameter',
                'available_checks' => ['database', 'line', 'printer', 'all']
            ]);
    }
} catch (Exception $e) {
    writeLog("System status check error: " . $e->getMessage());
    sendJsonResponse([
        'success' => false, 
        'error' => 'System check failed',
        'message' => DEBUG_MODE ? $e->getMessage() : 'Internal error'
    ]);
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
            'message' => DEBUG_MODE ? $e->getMessage() : 'Database connection error',
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
            'message' => DEBUG_MODE ? $e->getMessage() : 'LINE check error',
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
        
        // ตรวจสอบว่าตาราง system_settings มีอยู่หรือไม่
        $tableExists = false;
        try {
            $result = $conn->query("SHOW TABLES LIKE 'system_settings'");
            $tableExists = $result->rowCount() > 0;
        } catch (Exception $e) {
            // Table doesn't exist
        }
        
        if (!$tableExists) {
            return [
                'success' => false,
                'status' => 'not_configured',
                'message' => 'Printer configuration table not available',
                'timestamp' => date('Y-m-d H:i:s')
            ];
        }
        
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