<?php
/**
 * API ตรวจสอบสถานะระบบ
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

// ตั้งค่า Content-Type
header('Content-Type: application/json; charset=utf-8');

$check = $_GET['check'] ?? '';

try {
    switch ($check) {
        case 'database':
            checkDatabaseStatus();
            break;
            
        case 'line':
            checkLineStatus();
            break;
            
        case 'printer':
            checkPrinterStatus();
            break;
            
        default:
            throw new Exception('ประเภทการตรวจสอบไม่ถูกต้อง');
    }
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'status' => 'error',
        'message' => $e->getMessage()
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
            $stmt = $conn->query("SELECT 1");
            $result = $stmt->fetch();
            
            if ($result) {
                echo json_encode([
                    'success' => true,
                    'status' => 'connected',
                    'message' => 'ฐานข้อมูลเชื่อมต่อปกติ'
                ]);
            } else {
                throw new Exception('ไม่สามารถ query ฐานข้อมูลได้');
            }
        } else {
            throw new Exception('ไม่สามารถเชื่อมต่อฐานข้อมูลได้');
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'status' => 'disconnected',
            'message' => $e->getMessage()
        ]);
    }
}

/**
 * ตรวจสอบสถานะ LINE OA
 */
function checkLineStatus() {
    // ตรวจสอบว่ามีการตั้งค่า LINE หรือไม่
    if (!defined('LINE_CHANNEL_ACCESS_TOKEN') || empty(LINE_CHANNEL_ACCESS_TOKEN)) {
        echo json_encode([
            'success' => false,
            'status' => 'not_configured',
            'message' => 'ยังไม่ได้ตั้งค่า LINE OA'
        ]);
        return;
    }
    
    // ตรวจสอบการเชื่อมต่อ LINE API
    try {
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => 'https://api.line.me/v2/bot/info',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' . LINE_CHANNEL_ACCESS_TOKEN
            ]
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            echo json_encode([
                'success' => true,
                'status' => 'active',
                'message' => 'LINE OA เชื่อมต่อแล้ว'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'status' => 'configured',
                'message' => 'ตั้งค่าแล้ว แต่การเชื่อมต่อมีปัญหา'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'status' => 'configured',
            'message' => 'ตั้งค่าแล้ว แต่ไม่สามารถทดสอบการเชื่อมต่อได้'
        ]);
    }
}

/**
 * ตรวจสอบสถานะเครื่องพิมพ์
 */
function checkPrinterStatus() {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // ดึงการตั้งค่าเครื่องพิมพ์
        $stmt = $conn->prepare("
            SELECT setting_key, setting_value 
            FROM payment_settings 
            WHERE setting_key IN ('printer_enabled', 'printer_ip', 'printer_port')
        ");
        $stmt->execute();
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
        
        if (!isset($settings['printer_enabled']) || $settings['printer_enabled'] !== '1') {
            echo json_encode([
                'success' => false,
                'status' => 'disabled',
                'message' => 'เครื่องพิมพ์ไม่ได้เปิดใช้งาน'
            ]);
            return;
        }
        
        $printerIP = $settings['printer_ip'] ?? '';
        $printerPort = $settings['printer_port'] ?? '9100';
        
        if (empty($printerIP)) {
            echo json_encode([
                'success' => false,
                'status' => 'not_configured',
                'message' => 'ยังไม่ได้ตั้งค่า IP เครื่องพิมพ์'
            ]);
            return;
        }
        
        // ทดสอบการเชื่อมต่อเครื่องพิมพ์
        $connection = @fsockopen($printerIP, $printerPort, $errno, $errstr, 5);
        
        if ($connection) {
            fclose($connection);
            echo json_encode([
                'success' => true,
                'status' => 'online',
                'message' => 'เครื่องพิมพ์พร้อมใช้งาน'
            ]);
        } else {
            echo json_encode([
                'success' => false,
                'status' => 'configured',
                'message' => 'ตั้งค่าแล้ว แต่ไม่สามารถเชื่อมต่อได้'
            ]);
        }
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'status' => 'error',
            'message' => $e->getMessage()
        ]);
    }
}
?>