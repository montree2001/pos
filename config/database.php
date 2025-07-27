<?php
/**
 * การตั้งค่าฐานข้อมูล
 * Smart Order Management System
 * สร้างด้วยตนเองเนื่องจากปัญหา Permission
 */

// ป้องกันการเข้าถึงโดยตรง
if (!defined('SYSTEM_INIT')) {
    die('Direct access not allowed');
}

class Database {
    private $host = 'localhost';
    private $dbname = 'smart_order';
    private $username = 'root';        // เปลี่ยนตามของคุณ
    private $password = '';            // เปลี่ยนตามของคุณ
    private $conn;
    private $queryCount = 0;
    
    public function getConnection() {
        if ($this->conn !== null) {
            return $this->conn;
        }
        
        try {
            $dsn = "mysql:host=" . $this->host . ";dbname=" . $this->dbname . ";charset=utf8mb4";
            
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
                PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci"
            ];
            
            $this->conn = new PDO($dsn, $this->username, $this->password, $options);
            $this->conn->query("SELECT 1");
            
            return $this->conn;
            
        } catch (PDOException $e) {
            if (function_exists('writeLog')) {
                writeLog("Database connection failed: " . $e->getMessage());
            }
            
            if (defined('DEBUG_MODE') && DEBUG_MODE) {
                throw new Exception("Database Connection Error: " . $e->getMessage());
            } else {
                throw new Exception("Database connection failed");
            }
        }
    }
    
    public function testConnection() {
        try {
            $conn = $this->getConnection();
            if ($conn) {
                $stmt = $conn->query("SELECT 1 as test");
                $result = $stmt->fetch();
                return $result['test'] === 1;
            }
            return false;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function tableExists($tableName) {
        try {
            $conn = $this->getConnection();
            $stmt = $conn->prepare("SHOW TABLES LIKE ?");
            $stmt->execute([$tableName]);
            return $stmt->rowCount() > 0;
        } catch (Exception $e) {
            return false;
        }
    }
    
    public function createBasicTables() {
        try {
            $conn = $this->getConnection();
            
            // สร้างตาราง users
            $conn->exec("
                CREATE TABLE IF NOT EXISTS `users` (
                    `user_id` int(11) NOT NULL AUTO_INCREMENT,
                    `username` varchar(50) NOT NULL,
                    `password` varchar(255) NOT NULL,
                    `fullname` varchar(100) NOT NULL,
                    `email` varchar(100) DEFAULT NULL,
                    `phone` varchar(20) DEFAULT NULL,
                    `role` enum('admin','staff','kitchen','customer') NOT NULL,
                    `line_user_id` varchar(50) DEFAULT NULL,
                    `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
                    `last_login` datetime DEFAULT NULL,
                    `status` enum('active','inactive') DEFAULT 'active',
                    PRIMARY KEY (`user_id`),
                    UNIQUE KEY `username` (`username`),
                    UNIQUE KEY `email` (`email`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci
            ");
            
            // เพิ่มผู้ใช้ admin เริ่มต้น
            $this->createDefaultAdmin();
            
            return true;
            
        } catch (Exception $e) {
            if (function_exists('writeLog')) {
                writeLog("Failed to create basic tables: " . $e->getMessage());
            }
            return false;
        }
    }
    
    private function createDefaultAdmin() {
        try {
            $conn = $this->getConnection();
            
            // ตรวจสอบว่ามี admin แล้วหรือไม่
            $stmt = $conn->prepare("SELECT COUNT(*) FROM users WHERE role = 'admin'");
            $stmt->execute();
            $adminCount = $stmt->fetchColumn();
            
            if ($adminCount == 0) {
                // สร้าง admin เริ่มต้น
                $stmt = $conn->prepare("
                    INSERT INTO users (username, password, fullname, email, role, status) 
                    VALUES (?, ?, ?, ?, 'admin', 'active')
                ");
                
                $hashedPassword = password_hash('admin123', PASSWORD_DEFAULT);
                $stmt->execute([
                    'admin',
                    $hashedPassword,
                    'ผู้ดูแลระบบ',
                    'admin@smartorder.com'
                ]);
                
                if (function_exists('writeLog')) {
                    writeLog("Default admin user created successfully");
                }
            }
            
        } catch (Exception $e) {
            if (function_exists('writeLog')) {
                writeLog("Failed to create default admin: " . $e->getMessage());
            }
        }
    }
    
    public function getQueryCount() {
        return $this->queryCount;
    }
    
    public function executeQuery($sql, $params = []) {
        $this->queryCount++;
        
        try {
            $conn = $this->getConnection();
            $stmt = $conn->prepare($sql);
            $stmt->execute($params);
            return $stmt;
        } catch (Exception $e) {
            if (function_exists('writeLog')) {
                writeLog("Query execution failed: " . $e->getMessage() . " | SQL: " . $sql);
            }
            throw $e;
        }
    }
    
    public function closeConnection() {
        $this->conn = null;
    }
    
    public function __destruct() {
        $this->closeConnection();
    }
}
?>