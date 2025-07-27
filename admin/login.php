
<?php
// admin/login.php - หน้าเข้าสู่ระบบ
require_once '../config/database.php';
require_once '../config/config.php';

if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'admin') {
    header('Location: index.php');
    exit();
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        $db = new Database();
        $conn = $db->getConnection();
        
        $stmt = $conn->prepare("SELECT user_id, username, password, fullname, role FROM users WHERE username = ? AND role = 'admin' AND status = 'active'");
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['user_id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['fullname'] = $user['fullname'];
            $_SESSION['role'] = $user['role'];
            
            // อัปเดตเวลา login ล่าสุด
            $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
            $updateStmt->execute([$user['user_id']]);
            
            header('Location: index.php');
            exit();
        } else {
            $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    }
}