<?php
/**
 * หน้าเข้าสู่ระบบ Admin
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';

// ตรวจสอบว่าล็อกอินแล้วหรือไม่
if (isLoggedIn() && getCurrentUserRole() === 'admin') {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $rememberMe = isset($_POST['remember_me']);
    
    if (empty($username) || empty($password)) {
        $error = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    } else {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                SELECT user_id, username, password, fullname, email, role, status, last_login 
                FROM users 
                WHERE username = ? AND role = 'admin'
            ");
            $stmt->execute([$username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['password'])) {
                if ($user['status'] !== 'active') {
                    $error = 'บัญชีของคุณถูกระงับ กรุณาติดต่อผู้ดูแลระบบ';
                } else {
                    // เข้าสู่ระบบสำเร็จ
                    UserSession::login([
                        'user_id' => $user['user_id'],
                        'username' => $user['username'],
                        'fullname' => $user['fullname'],
                        'email' => $user['email'],
                        'role' => $user['role']
                    ]);
                    
                    // อัปเดตเวลา login ล่าสุด
                    $updateStmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE user_id = ?");
                    $updateStmt->execute([$user['user_id']]);
                    
                    // จัดการ Remember Me
                    if ($rememberMe) {
                        $token = bin2hex(random_bytes(32));
                        $hashedToken = hash('sha256', $token);
                        
                        // สร้างตาราง user_tokens ถ้ายังไม่มี
                        $conn->exec("
                            CREATE TABLE IF NOT EXISTS user_tokens (
                                id INT AUTO_INCREMENT PRIMARY KEY,
                                user_id INT NOT NULL,
                                token VARCHAR(255) NOT NULL,
                                expires_at DATETIME NOT NULL,
                                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                                INDEX (user_id),
                                INDEX (token)
                            )
                        ");
                        
                        // บันทึก token
                        $tokenStmt = $conn->prepare("
                            INSERT INTO user_tokens (user_id, token, expires_at) 
                            VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 30 DAY))
                        ");
                        $tokenStmt->execute([$user['user_id'], $hashedToken]);
                        
                        // ตั้งค่า cookie
                        setcookie('remember_token', $token, time() + (30 * 24 * 60 * 60), '/', '', true, true);
                    }
                    
                    $success = 'เข้าสู่ระบบสำเร็จ';
                    
                    // Redirect ไปหน้า Dashboard
                    header('Location: index.php');
                    exit();
                }
            } else {
                $error = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
            }
        } catch (Exception $e) {
            writeLog("Login error: " . $e->getMessage());
            $error = 'เกิดข้อผิดพลาดในระบบ กรุณาลองใหม่อีกครั้ง';
        }
    }
}

$pageTitle = 'เข้าสู่ระบบ';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-dark: #3730a3;
            --light-bg: #f8fafc;
            --white: #ffffff;
            --border-color: #e5e7eb;
            --text-color: #1f2937;
            --text-muted: #6b7280;
            --box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            --border-radius: 12px;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }
        
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-card {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            overflow: hidden;
            max-width: 400px;
            width: 100%;
        }
        
        .login-header {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            padding: 30px;
            text-align: center;
        }
        
        .login-header i {
            font-size: 3rem;
            margin-bottom: 15px;
        }
        
        .login-body {
            padding: 30px;
        }
        
        .form-control {
            border-radius: 8px;
            border: 2px solid var(--border-color);
            padding: 12px 15px;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .form-control:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            border: none;
            border-radius: 8px;
            padding: 12px;
            font-weight: 600;
            font-size: 16px;
            transition: all 0.3s ease;
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), #4338ca);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        }
        
        .alert {
            border-radius: 8px;
            border: none;
            font-size: 14px;
        }
        
        .form-check-input:checked {
            background-color: var(--primary-color);
            border-color: var(--primary-color);
        }
        
        .login-footer {
            background: #f8fafc;
            padding: 20px 30px;
            text-align: center;
            border-top: 1px solid var(--border-color);
        }
        
        .login-footer a {
            color: var(--primary-color);
            text-decoration: none;
            font-weight: 500;
        }
        
        .login-footer a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 576px) {
            .login-header, .login-body, .login-footer {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-card">
            <div class="login-header">
                <i class="fas fa-shield-alt"></i>
                <h2 class="mb-0">เข้าสู่ระบบ</h2>
                <p class="mb-0 opacity-75">ผู้ดูแลระบบ</p>
            </div>
            
            <div class="login-body">
                <?php if ($error): ?>
                    <div class="alert alert-danger" role="alert">
                        <i class="fas fa-exclamation-circle me-2"></i>
                        <?php echo clean($error); ?>
                    </div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success" role="alert">
                        <i class="fas fa-check-circle me-2"></i>
                        <?php echo clean($success); ?>
                    </div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="mb-3">
                        <label for="username" class="form-label">
                            <i class="fas fa-user me-2"></i>ชื่อผู้ใช้
                        </label>
                        <input type="text" 
                               class="form-control" 
                               id="username" 
                               name="username" 
                               value="<?php echo clean($_POST['username'] ?? ''); ?>"
                               required 
                               autofocus
                               placeholder="กรอกชื่อผู้ใช้">
                    </div>
                    
                    <div class="mb-3">
                        <label for="password" class="form-label">
                            <i class="fas fa-lock me-2"></i>รหัสผ่าน
                        </label>
                        <div class="position-relative">
                            <input type="password" 
                                   class="form-control" 
                                   id="password" 
                                   name="password" 
                                   required
                                   placeholder="กรอกรหัสผ่าน">
                            <button type="button" 
                                    class="btn btn-link position-absolute end-0 top-50 translate-middle-y text-muted"
                                    id="togglePassword"
                                    style="border: none; background: none; z-index: 10;">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                    
                    <div class="mb-3 form-check">
                        <input type="checkbox" 
                               class="form-check-input" 
                               id="remember_me" 
                               name="remember_me">
                        <label class="form-check-label" for="remember_me">
                            จดจำการเข้าสู่ระบบ (30 วัน)
                        </label>
                    </div>
                    
                    <div class="d-grid">
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ
                        </button>
                    </div>
                </form>
            </div>
            
            <div class="login-footer">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    <?php echo SITE_NAME; ?> v<?php echo VERSION; ?>
                </small>
                <br>
                <a href="../" class="mt-2 d-inline-block">
                    <i class="fas fa-arrow-left me-1"></i>กลับหน้าหลัก
                </a>
            </div>
        </div>
    </div>
    
    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Toggle password visibility
        document.getElementById('togglePassword').addEventListener('click', function() {
            const password = document.getElementById('password');
            const icon = this.querySelector('i');
            
            if (password.type === 'password') {
                password.type = 'text';
                icon.className = 'fas fa-eye-slash';
            } else {
                password.type = 'password';
                icon.className = 'fas fa-eye';
            }
        });
        
        // Auto dismiss alerts
        setTimeout(function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (alert.classList.contains('alert-success')) {
                    alert.style.opacity = '0';
                    setTimeout(() => alert.remove(), 300);
                }
            });
        }, 3000);
        
        // Form validation
        document.querySelector('form').addEventListener('submit', function(e) {
            const username = document.getElementById('username').value.trim();
            const password = document.getElementById('password').value;
            
            if (!username || !password) {
                e.preventDefault();
                alert('กรุณากรอกชื่อผู้ใช้และรหัสผ่าน');
                return false;
            }
            
            if (username.length < 3) {
                e.preventDefault();
                alert('ชื่อผู้ใช้ต้องมีอย่างน้อย 3 ตัวอักษร');
                return false;
            }
            
            if (password.length < 6) {
                e.preventDefault();
                alert('รหัสผ่านต้องมีอย่างน้อย 6 ตัวอักษร');
                return false;
            }
        });
    </script>
</body>
</html>