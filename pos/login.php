<?php
session_start();

// ถ้าเข้าสู่ระบบแล้วให้ redirect ไปหน้าหลัก
if (isset($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error_message = '';

// ตรวจสอบการ submit form
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = trim($_POST['password']);
    
    // ตรวจสอบข้อมูลเข้าสู่ระบบ (เชื่อมต่อฐานข้อมูลจริงในภายหลัง)
    if (!empty($username) && !empty($password)) {
        // TODO: เชื่อมต่อฐานข้อมูลและตรวจสอบ username/password
        // ตัวอย่างการตรวจสอบแบบง่าย
        if (validateUser($username, $password)) {
            // สร้าง session
            $_SESSION['user_id'] = getUserId($username);
            $_SESSION['username'] = $username;
            $_SESSION['user_type'] = getUserType($username);
            $_SESSION['login_time'] = time();
            
            // redirect ไปหน้าหลัก
            header('Location: dashboard.php');
            exit();
        } else {
            $error_message = 'ชื่อผู้ใช้หรือรหัสผ่านไม่ถูกต้อง';
        }
    } else {
        $error_message = 'กรุณากรอกชื่อผู้ใช้และรหัสผ่าน';
    }
}

// ฟังก์ชันตรวจสอบผู้ใช้ (ตัวอย่าง - ในการใช้งานจริงต้องเชื่อมต่อฐานข้อมูล)
function validateUser($username, $password) {
    // TODO: เชื่อมต่อฐานข้อมูลและตรวจสอบ
    $valid_users = [
        'admin' => ['password' => 'admin123', 'type' => 'admin'],
        'shop1' => ['password' => 'shop123', 'type' => 'shop'],
        'cashier1' => ['password' => 'cashier123', 'type' => 'cashier']
    ];
    
    return isset($valid_users[$username]) && $valid_users[$username]['password'] === $password;
}

function getUserId($username) {
    // TODO: ดึง user_id จากฐานข้อมูล
    return hash('crc32', $username);
}

function getUserType($username) {
    // TODO: ดึง user_type จากฐานข้อมูล
    $valid_users = [
        'admin' => 'admin',
        'shop1' => 'shop',
        'cashier1' => 'cashier'
    ];
    
    return $valid_users[$username] ?? 'user';
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>เข้าสู่ระบบ - ระบบจัดการออเดอร์อัจฉริยะ</title>
    
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    
    <style>
        * {
            font-family: 'Prompt', sans-serif;
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        
        .login-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .logo-section {
            text-align: center;
            margin-bottom: 2rem;
        }
        
        .logo-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1rem;
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.3);
        }
        
        .logo-icon i {
            font-size: 2rem;
            color: white;
        }
        
        .system-title {
            color: #333;
            font-weight: 600;
            margin-bottom: 0.5rem;
            font-size: 1.5rem;
        }
        
        .system-subtitle {
            color: #666;
            font-weight: 300;
            font-size: 0.9rem;
        }
        
        .form-floating {
            margin-bottom: 1.5rem;
        }
        
        .form-control {
            border: 2px solid #e1e8ed;
            border-radius: 12px;
            padding: 1rem 0.75rem;
            font-size: 1rem;
            transition: all 0.3s ease;
            background: rgba(255, 255, 255, 0.8);
        }
        
        .form-control:focus {
            border-color: #667eea;
            box-shadow: 0 0 0 0.2rem rgba(102, 126, 234, 0.25);
            background: white;
        }
        
        .form-floating label {
            color: #666;
            font-weight: 400;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 1rem;
            font-weight: 500;
            font-size: 1.1rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            transition: all 0.3s ease;
            width: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
        }
        
        .alert {
            border-radius: 12px;
            border: none;
            font-weight: 400;
        }
        
        .alert-danger {
            background: rgba(220, 53, 69, 0.1);
            color: #dc3545;
            border-left: 4px solid #dc3545;
        }
        
        .input-group-text {
            background: rgba(255, 255, 255, 0.8);
            border: 2px solid #e1e8ed;
            border-right: none;
            border-radius: 12px 0 0 12px;
        }
        
        .password-toggle {
            background: rgba(255, 255, 255, 0.8);
            border: 2px solid #e1e8ed;
            border-left: none;
            border-radius: 0 12px 12px 0;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .password-toggle:hover {
            background: rgba(102, 126, 234, 0.1);
        }
        
        .demo-accounts {
            margin-top: 2rem;
            padding: 1rem;
            background: rgba(102, 126, 234, 0.05);
            border-radius: 12px;
            border-left: 4px solid #667eea;
        }
        
        .demo-accounts h6 {
            color: #667eea;
            font-weight: 600;
            margin-bottom: 0.5rem;
        }
        
        .demo-accounts small {
            color: #666;
            font-size: 0.8rem;
            line-height: 1.4;
        }
        
        @media (max-width: 480px) {
            .login-container {
                padding: 1.5rem;
                margin: 10px;
            }
            
            .system-title {
                font-size: 1.25rem;
            }
            
            .logo-icon {
                width: 60px;
                height: 60px;
            }
            
            .logo-icon i {
                font-size: 1.5rem;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <!-- Logo และชื่อระบบ -->
        <div class="logo-section">
            <div class="logo-icon">
                <i class="fas fa-shopping-cart"></i>
            </div>
            <h1 class="system-title">Smart Order POS</h1>
            <p class="system-subtitle">ระบบจัดการออเดอร์อัจฉริยะ</p>
        </div>
        
        <!-- แสดงข้อผิดพลาด -->
        <?php if (!empty($error_message)): ?>
            <div class="alert alert-danger" role="alert">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo htmlspecialchars($error_message); ?>
            </div>
        <?php endif; ?>
        
        <!-- ฟอร์มเข้าสู่ระบบ -->
        <form method="POST" action="">
            <div class="form-floating">
                <input type="text" class="form-control" id="username" name="username" 
                       placeholder="ชื่อผู้ใช้" required autocomplete="username">
                <label for="username">
                    <i class="fas fa-user me-2"></i>ชื่อผู้ใช้
                </label>
            </div>
            
            <div class="form-floating">
                <div class="input-group">
                    <input type="password" class="form-control" id="password" name="password" 
                           placeholder="รหัสผ่าน" required autocomplete="current-password">
                    <span class="input-group-text password-toggle" onclick="togglePassword()">
                        <i class="fas fa-eye" id="passwordToggleIcon"></i>
                    </span>
                </div>
                <label for="password" style="margin-left: 0;">
                    <i class="fas fa-lock me-2"></i>รหัสผ่าน
                </label>
            </div>
            
            <button type="submit" class="btn btn-primary btn-login">
                <i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบ
            </button>
        </form>
        
        <!-- ข้อมูลบัญชีทดลอง -->
        <div class="demo-accounts">
            <h6><i class="fas fa-info-circle me-2"></i>บัญชีทดลอง</h6>
            <small>
                <strong>ผู้ดูแลระบบ:</strong> admin / admin123<br>
                <strong>ร้านค้า:</strong> shop1 / shop123<br>
                <strong>แคชเชียร์:</strong> cashier1 / cashier123
            </small>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // ฟังก์ชันแสดง/ซ่อนรหัสผ่าน
        function togglePassword() {
            const passwordField = document.getElementById('password');
            const toggleIcon = document.getElementById('passwordToggleIcon');
            
            if (passwordField.type === 'password') {
                passwordField.type = 'text';
                toggleIcon.classList.remove('fa-eye');
                toggleIcon.classList.add('fa-eye-slash');
            } else {
                passwordField.type = 'password';
                toggleIcon.classList.remove('fa-eye-slash');
                toggleIcon.classList.add('fa-eye');
            }
        }
        
        // Auto focus ที่ช่องชื่อผู้ใช้
        document.addEventListener('DOMContentLoaded', function() {
            document.getElementById('username').focus();
        });
        
        // ป้องกันการ submit ฟอร์มซ้ำ
        document.querySelector('form').addEventListener('submit', function(e) {
            const submitBtn = document.querySelector('.btn-login');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>กำลังเข้าสู่ระบบ...';
        });
    </script>
</body>
</html>