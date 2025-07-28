<?php
/**
 * จัดการคิว
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์
requireAuth('admin');

$pageTitle = 'จัดการคิว';

// จัดการการส่งฟอร์ม
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken($_POST['csrf_token'] ?? '')) {
        setFlashMessage('error', 'Invalid request');
        header('Location: queue_management.php');
        exit();
    }
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'call_queue') {
        $orderId = intval($_POST['order_id'] ?? 0);
        
        if ($orderId) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                // ดึงข้อมูลออเดอร์
                $stmt = $conn->prepare("SELECT * FROM orders WHERE order_id = ?");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch();
                
                if ($order) {
                    // บันทึกการเรียกคิว
                    $stmt = $conn->prepare("
                        INSERT INTO voice_calls (queue_number, order_id, message, created_at) 
                        VALUES (?, ?, ?, NOW())
                    ");
                    $message = "เรียกคิวหมายเลข " . $order['queue_number'] . " กรุณามารับออเดอร์ค่ะ";
                    $stmt->execute([$order['queue_number'], $orderId, $message]);
                    
                    setFlashMessage('success', 'เรียกคิวหมายเลข ' . $order['queue_number'] . ' แล้ว');
                }
                
            } catch (Exception $e) {
                setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
                writeLog("Error calling queue: " . $e->getMessage());
            }
        }
    } elseif ($action === 'reset_queue') {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // รีเซ็ตคิวประจำวัน
            $conn->exec("UPDATE orders SET queue_number = NULL WHERE DATE(created_at) = CURDATE() AND status = 'completed'");
            
            setFlashMessage('success', 'รีเซ็ตคิวประจำวันสำเร็จ');
            writeLog("Queue reset by " . getCurrentUser()['username']);
            
        } catch (Exception $e) {
            setFlashMessage('error', 'เกิดข้อผิดพลาด: ' . $e->getMessage());
            writeLog("Error resetting queue: " . $e->getMessage());
        }
    }
    
    header('Location: queue_management.php');
    exit();
}

// ดึงข้อมูลคิว
$currentQueue = [];
$queueHistory = [];
$stats = [
    'current_queue' => 0,
    'waiting_queue' => 0,
    'avg_wait_time' => 0,
    'total_called' => 0
];

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // คิวปัจจุบัน
    $stmt = $conn->prepare("
        SELECT o.*, u.fullname as customer_name, u.phone,
               TIMESTAMPDIFF(MINUTE, o.created_at, NOW()) as waiting_minutes
        FROM orders o
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE DATE(o.created_at) = CURDATE() 
        AND o.status IN ('confirmed', 'preparing', 'ready')
        AND o.queue_number IS NOT NULL
        ORDER BY o.queue_number ASC
    ");
    $stmt->execute();
    $currentQueue = $stmt->fetchAll();
    
    // ประวัติการเรียกคิว
    $stmt = $conn->prepare("
        SELECT vc.*, o.queue_number, o.total_price, u.fullname as customer_name
        FROM voice_calls vc
        LEFT JOIN orders o ON vc.order_id = o.order_id
        LEFT JOIN users u ON o.user_id = u.user_id
        WHERE DATE(vc.created_at) = CURDATE()
        ORDER BY vc.created_at DESC
        LIMIT 20
    ");
    $stmt->execute();
    $queueHistory = $stmt->fetchAll();
    
    // สถิติ
    $stats['current_queue'] = count($currentQueue);
    $stats['waiting_queue'] = count(array_filter($currentQueue, fn($q) => $q['status'] === 'confirmed'));
    
    $stmt = $conn->prepare("
        SELECT AVG(TIMESTAMPDIFF(MINUTE, created_at, updated_at)) as avg_time
        FROM orders 
        WHERE DATE(created_at) = CURDATE() 
        AND status = 'completed'
    ");
    $stmt->execute();
    $avgTime = $stmt->fetchColumn();
    $stats['avg_wait_time'] = $avgTime ? round($avgTime) : 0;
    
    $stmt = $conn->prepare("SELECT COUNT(*) FROM voice_calls WHERE DATE(created_at) = CURDATE()");
    $stmt->execute();
    $stats['total_called'] = $stmt->fetchColumn();
    
} catch (Exception $e) {
    writeLog("Error loading queue data: " . $e->getMessage());
    setFlashMessage('error', 'ไม่สามารถโหลดข้อมูลได้');
}

require_once '../includes/header.php';
require_once '../includes/sidebar.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h3 mb-1">จัดการคิว</h1>
        <p class="text-muted mb-0">จัดการคิวออเดอร์และเรียกคิวด้วยระบบเสียง</p>
    </div>
    <div>
        <button class="btn btn-warning" onclick="resetQueue()">
            <i class="fas fa-sync-alt me-2"></i>รีเซ็ตคิว
        </button>
        <button class="btn btn-primary" onclick="location.reload()">
            <i class="fas fa-refresh me-2"></i>รีเฟรช
        </button>
    </div>
</div>

<!-- Statistics Cards -->
<div class="row mb-4">
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card info">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo $stats['current_queue']; ?></div>
                    <div class="stats-label">คิวปัจจุบัน</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-list-ol"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card warning">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo $stats['waiting_queue']; ?></div>
                    <div class="stats-label">รอเรียก</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-clock"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card success">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo $stats['avg_wait_time']; ?></div>
                    <div class="stats-label">เวลารอเฉลี่ย (นาที)</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-stopwatch"></i>
                </div>
            </div>
        </div>
    </div>
    
    <div class="col-xl-3 col-md-6 mb-3">
        <div class="stats-card">
            <div class="d-flex justify-content-between">
                <div>
                    <div class="stats-number"><?php echo $stats['total_called']; ?></div>
                    <div class="stats-label">เรียกแล้ววันนี้</div>
                </div>
                <div class="stats-icon">
                    <i class="fas fa-volume-up"></i>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="row">
    <!-- Current Queue -->
    <div class="col-xl-8 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-list me-2"></i>คิวปัจจุบัน
                </h5>
            </div>
            <div class="card-body">
                <?php if (!empty($currentQueue)): ?>
                    <div class="table-responsive">
                        <table class="table table-hover" id="queueTable">
                            <thead>
                                <tr>
                                    <th>หมายเลขคิว</th>
                                    <th>ลูกค้า</th>
                                    <th>ยอดรวม</th>
                                    <th>สถานะ</th>
                                    <th>เวลารอ</th>
                                    <th>การกระทำ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($currentQueue as $queue): ?>
                                    <tr class="<?php echo $queue['status'] === 'ready' ? 'table-success' : ($queue['waiting_minutes'] > 30 ? 'table-warning' : ''); ?>">
                                        <td>
                                            <h4 class="mb-0 text-primary"><?php echo clean($queue['queue_number']); ?></h4>
                                        </td>
                                        <td>
                                            <div><?php echo clean($queue['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></div>
                                            <?php if ($queue['phone']): ?>
                                                <small class="text-muted"><?php echo clean($queue['phone']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo formatCurrency($queue['total_price']); ?></td>
                                        <td>
                                            <span class="badge <?php echo getOrderStatusClass($queue['status']); ?>">
                                                <?php echo getOrderStatusText($queue['status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="<?php echo $queue['waiting_minutes'] > 30 ? 'text-danger fw-bold' : 'text-muted'; ?>">
                                                <?php echo $queue['waiting_minutes']; ?> นาที
                                            </span>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <button class="btn btn-success" onclick="callQueue(<?php echo $queue['order_id']; ?>, '<?php echo $queue['queue_number']; ?>')">
                                                    <i class="fas fa-volume-up"></i> เรียก
                                                </button>
                                                <button class="btn btn-outline-primary" onclick="viewOrderDetails(<?php echo $queue['order_id']; ?>)">
                                                    <i class="fas fa-eye"></i>
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-5">
                        <i class="fas fa-inbox fa-3x mb-3"></i>
                        <h5>ไม่มีคิวในขณะนี้</h5>
                        <p>ยังไม่มีออเดอร์ที่รอเรียกคิว</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- Queue History -->
    <div class="col-xl-4 mb-4">
        <div class="card">
            <div class="card-header">
                <h5 class="mb-0">
                    <i class="fas fa-history me-2"></i>ประวัติการเรียกคิว
                </h5>
            </div>
            <div class="card-body" style="max-height: 500px; overflow-y: auto;">
                <?php if (!empty($queueHistory)): ?>
                    <?php foreach ($queueHistory as $history): ?>
                        <div class="d-flex align-items-center mb-3 p-2 rounded bg-light">
                            <div class="badge bg-primary rounded-pill me-3" style="width: 40px; height: 40px; display: flex; align-items: center; justify-content: center;">
                                <?php echo clean($history['queue_number']); ?>
                            </div>
                            <div class="flex-grow-1">
                                <div class="fw-semibold"><?php echo clean($history['customer_name'] ?: 'ลูกค้าทั่วไป'); ?></div>
                                <small class="text-muted">
                                    เรียกเมื่อ <?php echo formatDate($history['created_at'], 'H:i'); ?>
                                </small>
                            </div>
                            <div class="text-end">
                                <i class="fas fa-volume-up text-success"></i>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="text-center text-muted py-3">
                        <i class="fas fa-history fa-2x mb-2"></i>
                        <p>ยังไม่มีประวัติการเรียกคิว</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Voice Call Testing -->
<div class="card">
    <div class="card-header">
        <h5 class="mb-0">
            <i class="fas fa-microphone me-2"></i>ทดสอบระบบเสียง
        </h5>
    </div>
    <div class="card-body">
        <div class="row align-items-end">
            <div class="col-md-6">
                <label for="testMessage" class="form-label">ข้อความทดสอบ</label>
                <input type="text" class="form-control" id="testMessage" 
                       value="ทดสอบระบบเสียง เรียกคิวหมายเลข 001" placeholder="กรอกข้อความที่ต้องการทดสอบ">
            </div>
            <div class="col-md-3">
                <label for="voiceSpeed" class="form-label">ความเร็วเสียง</label>
                <select class="form-select" id="voiceSpeed">
                    <option value="0.8">ช้า</option>
                    <option value="1" selected>ปกติ</option>
                    <option value="1.2">เร็ว</option>
                </select>
            </div>
            <div class="col-md-3">
                <button class="btn btn-info w-100" onclick="testVoice()">
                    <i class="fas fa-play me-2"></i>ทดสอบเสียง
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Call Queue Form -->
<form id="callQueueForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="call_queue">
    <input type="hidden" name="order_id" id="callOrderId">
</form>

<!-- Reset Queue Form -->
<form id="resetQueueForm" method="POST" style="display: none;">
    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
    <input type="hidden" name="action" value="reset_queue">
</form>

<?php
$inlineJS = "
// เรียกคิว
function callQueue(orderId, queueNumber) {
    confirmAction('ต้องการเรียกคิวหมายเลข ' + queueNumber + '?', function() {
        $('#callOrderId').val(orderId);
        $('#callQueueForm').submit();
        
        // เล่นเสียงทันที
        setTimeout(function() {
            playVoiceMessage('เรียกคิวหมายเลข ' + queueNumber + ' กรุณามารับออเดอร์ค่ะ');
        }, 500);
    });
}

// รีเซ็ตคิว
function resetQueue() {
    confirmAction('ต้องการรีเซ็ตคิวประจำวัน? คิวที่เสร็จแล้วจะถูกลบออก', function() {
        $('#resetQueueForm').submit();
    });
}

// ทดสอบเสียง
function testVoice() {
    const message = $('#testMessage').val();
    const speed = parseFloat($('#voiceSpeed').val());
    
    if (!message) {
        alert('กรุณากรอกข้อความที่ต้องการทดสอบ');
        return;
    }
    
    playVoiceMessage(message, speed);
}

// เล่นเสียงผ่าน Text-to-Speech
function playVoiceMessage(message, rate = 1) {
    if ('speechSynthesis' in window) {
        // หยุดเสียงที่เล่นอยู่
        speechSynthesis.cancel();
        
        const utterance = new SpeechSynthesisUtterance(message);
        utterance.lang = 'th-TH';
        utterance.rate = rate;
        utterance.pitch = 1;
        utterance.volume = 1;
        
        speechSynthesis.speak(utterance);
    } else {
        alert('เบราว์เซอร์นี้ไม่รองรับระบบเสียง');
    }
}

// ดูรายละเอียดออเดอร์
function viewOrderDetails(orderId) {
    // เปิด modal หรือ redirect ไปหน้ารายละเอียด
    window.open(SITE_URL + '/admin/order_management.php?view=' + orderId, '_blank');
}

// Auto refresh ทุก 30 วินาที
setInterval(function() {
    if (document.visibilityState === 'visible') {
        location.reload();
    }
}, 30000);

console.log('Queue Management loaded successfully');
";

require_once '../includes/footer.php';
?>