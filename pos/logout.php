<?php
session_start();

// เก็บข้อมูลผู้ใช้ก่อนทำลาย session (สำหรับแสดงข้อความ)
$username = $_SESSION['username'] ?? 'ผู้ใช้';
$user_type = $_SESSION['user_type'] ?? '';

// บันทึก logout log (สำหรับการตรวจสอบความปลอดภัย)
if (isset($_SESSION['user_id'])) {
    logUserActivity($_SESSION['user_id'], 'logout', 'User logged out');
}

// ทำลาย session ทั้งหมด
$_SESSION = array();

// ลบ session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// ทำลาย session
session_destroy();

// ตรวจสอบว่ามีการ redirect หรือไม่
$redirect_to = $_GET['redirect'] ?? 'login.php';
$auto_redirect = $_GET['auto'] ?? '0';

// ฟังก์ชันบันทึก log (ตัวอย่าง - ในการใช้งานจริงต้องเชื่อมต่อฐานข้อมูล)
function logUserActivity($user_id, $action, $description) {
    // TODO: บันทึกลงฐานข้อมูล
    $log_entry = [
        'user_id' => $user_id,
        'action' => $action,
        'description' => $description,
        'ip_address' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    // สำหรับการพัฒนา - บันทึกลง log file
    error_log("User Activity: " . json_encode($log_entry, JSON_UNESCAPED_UNICODE));
}

// ถ้าเป็น auto redirect ให้ redirect ทันที
if ($auto_redirect == '1') {
    header('Location: ' . $redirect_to);
    exit();
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ออกจากระบบ - ระบบจัดการออเดอร์อัจฉริยะ</title>
    
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
        
        .logout-container {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            box-shadow: 0 15px 35px rgba(0, 0, 0, 0.1);
            padding: 2rem;
            width: 100%;
            max-width: 400px;
            text-align: center;
            border: 1px solid rgba(255, 255, 255, 0.2);
        }
        
        .logout-icon {
            width: 80px;
            height: 80px;
            background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 1.5rem;
            box-shadow: 0 10px 25px rgba(40, 167, 69, 0.3);
            animation: pulse 2s infinite;
        }
        
        .logout-icon i {
            font-size: 2rem;
            color: white;
        }
        
        @keyframes pulse {
            0% {
                transform: scale(1);
                box-shadow: 0 10px 25px rgba(40, 167, 69, 0.3);
            }
            50% {
                transform: scale(1.05);
                box-shadow: 0 15px 35px rgba(40, 167, 69, 0.4);
            }
            100% {
                transform: scale(1);
                box-shadow: 0 10px 25px rgba(40, 167, 69, 0.3);
            }
        }
        
        .logout-title {
            color: #28a745;
            font-weight: 600;
            margin-bottom: 1rem;
            font-size: 1.5rem;
        }
        
        .logout-message {
            color: #666;
            margin-bottom: 2rem;
            line-height: 1.6;
        }
        
        .user-info {
            background: rgba(40, 167, 69, 0.1);
            border-radius: 12px;
            padding: 1rem;
            margin-bottom: 2rem;
            border-left: 4px solid #28a745;
        }
        
        .user-info strong {
            color: #28a745;
        }
        
        .btn-container {
            display: flex;
            gap: 1rem;
            flex-wrap: wrap;
        }
        
        .btn-login {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            text-decoration: none;
            color: white;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 120px;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(102, 126, 234, 0.4);
            color: white;
        }
        
        .btn-home {
            background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
            border: none;
            border-radius: 12px;
            padding: 0.75rem 1.5rem;
            font-weight: 500;
            text-decoration: none;
            color: white;
            transition: all 0.3s ease;
            flex: 1;
            min-width: 120px;
        }
        
        .btn-home:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(108, 117, 125, 0.4);
            color: white;
        }
        
        .auto-redirect {
            margin-top: 2rem;
            padding: 1rem;
            background: rgba(102, 126, 234, 0.1);
            border-radius: 12px;
            border-left: 4px solid #667eea;
        }
        
        .countdown {
            font-weight: 600;
            color: #667eea;
            font-size: 1.2rem;
        }
        
        .progress {
            height: 6px;
            background: rgba(102, 126, 234, 0.2);
            border-radius: 3px;
            margin-top: 1rem;
            overflow: hidden;
        }
        
        .progress-bar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            height: 100%;
            border-radius: 3px;
            transition: width 0.1s linear;
        }
        
        @media (max-width: 480px) {
            .logout-container {
                padding: 1.5rem;
                margin: 10px;
            }
            
            .logout-title {
                font-size: 1.25rem;
            }
            
            .logout-icon {
                width: 60px;
                height: 60px;
            }
            
            .logout-icon i {
                font-size: 1.5rem;
            }
            
            .btn-container {
                flex-direction: column;
            }
        }
    </style>
</head>
<body>
    <div class="logout-container">
        <!-- ไอคอนออกจากระบบ -->
        <div class="logout-icon">
            <i class="fas fa-check-circle"></i>
        </div>
        
        <h1 class="logout-title">ออกจากระบบสำเร็จ</h1>
        
        <p class="logout-message">
            คุณได้ออกจากระบบเรียบร้อยแล้ว<br>
            ขอบคุณที่ใช้บริการระบบจัดการออเดอร์อัจฉริยะ
        </p>
        
        <!-- ข้อมูลผู้ใช้ -->
        <div class="user-info">
            <strong>ชื่อผู้ใช้:</strong> <?php echo htmlspecialchars($username); ?><br>
            <strong>เวลาออกจากระบบ:</strong> <?php echo date('d/m/Y H:i:s'); ?>
            <?php if ($user_type): ?>
                <br><strong>ประเภทผู้ใช้:</strong> 
                <?php 
                $type_names = [
                    'admin' => 'ผู้ดูแลระบบ',
                    'shop' => 'ร้านค้า',
                    'cashier' => 'แคชเชียร์'
                ];
                echo $type_names[$user_type] ?? $user_type;
                ?>
            <?php endif; ?>
        </div>
        
        <!-- ปุ่มควบคุม -->
        <div class="btn-container">
            <a href="login.php" class="btn btn-login">
                <i class="fas fa-sign-in-alt me-2"></i>เข้าสู่ระบบใหม่
            </a>
            <a href="../index.php" class="btn btn-home">
                <i class="fas fa-home me-2"></i>หน้าแรก
            </a>
        </div>
        
        <!-- Auto Redirect Countdown -->
        <div class="auto-redirect">
            <div>
                <i class="fas fa-clock me-2"></i>
                จะเปลี่ยนไปหน้าเข้าสู่ระบบอัตโนมัติใน
            </div>
            <div class="countdown" id="countdown">5</div>
            <div class="progress">
                <div class="progress-bar" id="progressBar"></div>
            </div>
            <small class="text-muted mt-2 d-block">
                <a href="javascript:void(0);" onclick="cancelRedirect()" style="color: #667eea;">
                    <i class="fas fa-times me-1"></i>ยกเลิกการเปลี่ยนหน้าอัตโนมัติ
                </a>
            </small>
        </div>
    </div>
    
    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <script>
        // Auto redirect countdown
        let countdownTime = 5;
        let countdownInterval;
        let progressInterval;
        let redirectCancelled = false;
        
        function startCountdown() {
            const countdownElement = document.getElementById('countdown');
            const progressBar = document.getElementById('progressBar');
            
            countdownInterval = setInterval(function() {
                if (redirectCancelled) {
                    clearInterval(countdownInterval);
                    clearInterval(progressInterval);
                    return;
                }
                
                countdownTime--;
                countdownElement.textContent = countdownTime;
                
                if (countdownTime <= 0) {
                    clearInterval(countdownInterval);
                    clearInterval(progressInterval);
                    window.location.href = 'login.php';
                }
            }, 1000);
            
            // Progress bar animation
            let progress = 100;
            progressBar.style.width = '100%';
            
            progressInterval = setInterval(function() {
                if (redirectCancelled) {
                    clearInterval(progressInterval);
                    return;
                }
                
                progress -= (100 / 5); // 5 seconds
                progressBar.style.width = Math.max(0, progress) + '%';
                
                if (progress <= 0) {
                    clearInterval(progressInterval);
                }
            }, 1000);
        }
        
        function cancelRedirect() {
            redirectCancelled = true;
            document.querySelector('.auto-redirect').innerHTML = `
                <div style="color: #28a745;">
                    <i class="fas fa-check me-2"></i>
                    ยกเลิกการเปลี่ยนหน้าอัตโนมัติแล้ว
                </div>
            `;
        }
        
        // เริ่ม countdown เมื่อโหลดหน้าเสร็จ
        document.addEventListener('DOMContentLoaded', function() {
            startCountdown();
        });
        
        // ป้องกันการกลับหน้า
        history.pushState(null, null, window.location.href);
        window.onpopstate = function() {
            history.pushState(null, null, window.location.href);
        };
    </script>
</body>
</html>