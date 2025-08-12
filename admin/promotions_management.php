<?php
/**
 * จัดการโปรโมชั่นและส่วนลด
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';
require_once '../includes/auth.php';

// ตรวจสอบสิทธิ์
if (!isLoggedIn() || getCurrentUserRole() !== 'admin') {
    header('Location: login.php');
    exit();
}

$pageTitle = 'จัดการโปรโมชั่น';
$currentPage = 'promotions';

// เริ่มต้นตัวแปร
$promotions = [];
$coupons = [];
$error = null;
$success = null;

try {
    $db = new Database();
    $conn = $db->getConnection();
    
    // จัดการคำขอ POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!validateCSRFToken($_POST['csrf_token'] ?? '')) {
            throw new Exception('Invalid CSRF token');
        }
        
        $action = $_POST['action'] ?? '';
        
        switch ($action) {
            case 'add_promotion':
                $name = trim($_POST['name']);
                $description = trim($_POST['description']);
                $discountType = $_POST['discount_type'];
                $discountValue = floatval($_POST['discount_value']);
                $minOrderAmount = floatval($_POST['min_order_amount']);
                $startDate = $_POST['start_date'];
                $endDate = $_POST['end_date'];
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($name)) {
                    throw new Exception('กรุณาระบุชื่อโปรโมชั่น');
                }
                
                if ($discountValue <= 0) {
                    throw new Exception('ค่าส่วนลดต้องมากกว่า 0');
                }
                
                if ($discountType === 'percentage' && $discountValue > 100) {
                    throw new Exception('ส่วนลดเปอร์เซ็นต์ต้องไม่เกิน 100%');
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO promotions 
                    (name, description, discount_type, discount_value, min_order_amount, start_date, end_date, is_active, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $name,
                    $description,
                    $discountType,
                    $discountValue,
                    $minOrderAmount,
                    $startDate,
                    $endDate,
                    $isActive,
                    getCurrentUserId()
                ]);
                
                $success = 'เพิ่มโปรโมชั่นเรียบร้อยแล้ว';
                break;
                
            case 'add_coupon':
                $code = strtoupper(trim($_POST['code']));
                $description = trim($_POST['description']);
                $discountType = $_POST['discount_type'];
                $discountValue = floatval($_POST['discount_value']);
                $minOrderAmount = floatval($_POST['min_order_amount']);
                $usageLimit = intval($_POST['usage_limit']);
                $startDate = $_POST['start_date'];
                $endDate = $_POST['end_date'];
                $isActive = isset($_POST['is_active']) ? 1 : 0;
                
                if (empty($code)) {
                    throw new Exception('กรุณาระบุรหัสคูปอง');
                }
                
                if (strlen($code) < 3) {
                    throw new Exception('รหัสคูปองต้องมีอย่างน้อย 3 ตัวอักษร');
                }
                
                // ตรวจสอบรหัสซ้ำ
                $stmt = $conn->prepare("SELECT coupon_id FROM coupons WHERE code = ?");
                $stmt->execute([$code]);
                if ($stmt->fetch()) {
                    throw new Exception('รหัสคูปองนี้มีอยู่แล้ว');
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO coupons 
                    (code, description, discount_type, discount_value, min_order_amount, usage_limit, start_date, end_date, is_active, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $code,
                    $description,
                    $discountType,
                    $discountValue,
                    $minOrderAmount,
                    $usageLimit,
                    $startDate,
                    $endDate,
                    $isActive,
                    getCurrentUserId()
                ]);
                
                $success = 'เพิ่มคูปองเรียบร้อยแล้ว';
                break;
                
            case 'toggle_promotion':
                $promotionId = intval($_POST['promotion_id']);
                $isActive = intval($_POST['is_active']);
                
                $stmt = $conn->prepare("UPDATE promotions SET is_active = ? WHERE promotion_id = ?");
                $stmt->execute([$isActive, $promotionId]);
                
                $success = $isActive ? 'เปิดใช้งานโปรโมชั่นแล้ว' : 'ปิดใช้งานโปรโมชั่นแล้ว';
                break;
                
            case 'toggle_coupon':
                $couponId = intval($_POST['coupon_id']);
                $isActive = intval($_POST['is_active']);
                
                $stmt = $conn->prepare("UPDATE coupons SET is_active = ? WHERE coupon_id = ?");
                $stmt->execute([$isActive, $couponId]);
                
                $success = $isActive ? 'เปิดใช้งานคูปองแล้ว' : 'ปิดใช้งานคูปองแล้ว';
                break;
        }
    }
    
    // ดึงข้อมูลโปรโมชั่น
    $stmt = $conn->prepare("
        SELECT 
            p.*,
            u.name as created_by_name,
            COUNT(o.order_id) as usage_count,
            COALESCE(SUM(o.discount_amount), 0) as total_discount_given
        FROM promotions p
        LEFT JOIN users u ON p.created_by = u.user_id
        LEFT JOIN orders o ON p.promotion_id = o.promotion_id
        GROUP BY p.promotion_id
        ORDER BY p.created_at DESC
    ");
    $stmt->execute();
    $promotions = $stmt->fetchAll();
    
    // ดึงข้อมูลคูปอง
    $stmt = $conn->prepare("
        SELECT 
            c.*,
            u.name as created_by_name,
            COUNT(cu.usage_id) as usage_count,
            COALESCE(SUM(o.discount_amount), 0) as total_discount_given
        FROM coupons c
        LEFT JOIN users u ON c.created_by = u.user_id
        LEFT JOIN coupon_usage cu ON c.coupon_id = cu.coupon_id
        LEFT JOIN orders o ON cu.order_id = o.order_id
        GROUP BY c.coupon_id
        ORDER BY c.created_at DESC
    ");
    $stmt->execute();
    $coupons = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = $e->getMessage();
    writeLog("Promotions management error: " . $error);
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">จัดการโปรโมชั่น</h1>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addPromotionModal">
                <i class="fas fa-plus"></i> เพิ่มโปรโมชั่น
            </button>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#addCouponModal">
                <i class="fas fa-ticket-alt"></i> เพิ่มคูปอง
            </button>
        </div>
    </div>

    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <?php if ($success): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle"></i> <?php echo htmlspecialchars($success); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#promotions">
                <i class="fas fa-percentage"></i> โปรโมชั่น
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#coupons">
                <i class="fas fa-ticket-alt"></i> คูปองส่วนลด
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#analytics">
                <i class="fas fa-chart-bar"></i> สถิติการใช้งาน
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- โปรโมชั่น -->
        <div class="tab-pane fade show active" id="promotions">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">รายการโปรโมชั่น</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="promotionsTable">
                            <thead>
                                <tr>
                                    <th>ชื่อโปรโมชั่น</th>
                                    <th>ส่วนลด</th>
                                    <th>ขั้นต่ำ</th>
                                    <th>ระยะเวลา</th>
                                    <th>การใช้งาน</th>
                                    <th>สถานะ</th>
                                    <th>การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($promotions as $promotion): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($promotion['name']); ?></strong>
                                            <?php if ($promotion['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($promotion['description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($promotion['discount_type'] === 'percentage'): ?>
                                                <span class="badge bg-success"><?php echo $promotion['discount_value']; ?>%</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">฿<?php echo number_format($promotion['discount_value']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($promotion['min_order_amount'] > 0): ?>
                                                ฿<?php echo number_format($promotion['min_order_amount']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">ไม่กำหนด</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small>
                                                <?php echo formatDate($promotion['start_date'], 'd/m/Y'); ?><br>
                                                ถึง <?php echo formatDate($promotion['end_date'], 'd/m/Y'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo number_format($promotion['usage_count']); ?> ครั้ง</span>
                                            <br><small class="text-muted">ส่วนลด: ฿<?php echo number_format($promotion['total_discount_given']); ?></small>
                                        </td>
                                        <td>
                                            <?php
                                            $now = date('Y-m-d');
                                            $isExpired = $now > $promotion['end_date'];
                                            $isNotStarted = $now < $promotion['start_date'];
                                            ?>
                                            
                                            <?php if ($isExpired): ?>
                                                <span class="badge bg-secondary">หมดอายุ</span>
                                            <?php elseif ($isNotStarted): ?>
                                                <span class="badge bg-warning">รอเริ่ม</span>
                                            <?php elseif ($promotion['is_active']): ?>
                                                <span class="badge bg-success">ใช้งาน</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">ปิด</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$isExpired): ?>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" 
                                                           <?php echo $promotion['is_active'] ? 'checked' : ''; ?>
                                                           onchange="togglePromotion(<?php echo $promotion['promotion_id']; ?>, this.checked)">
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- คูปอง -->
        <div class="tab-pane fade" id="coupons">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">รายการคูปองส่วนลด</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="couponsTable">
                            <thead>
                                <tr>
                                    <th>รหัสคูปอง</th>
                                    <th>ส่วนลด</th>
                                    <th>ขั้นต่ำ</th>
                                    <th>จำกัดการใช้</th>
                                    <th>ระยะเวลา</th>
                                    <th>การใช้งาน</th>
                                    <th>สถานะ</th>
                                    <th>การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($coupons as $coupon): ?>
                                    <tr>
                                        <td>
                                            <span class="badge bg-dark fs-6"><?php echo htmlspecialchars($coupon['code']); ?></span>
                                            <?php if ($coupon['description']): ?>
                                                <br><small class="text-muted"><?php echo htmlspecialchars($coupon['description']); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($coupon['discount_type'] === 'percentage'): ?>
                                                <span class="badge bg-success"><?php echo $coupon['discount_value']; ?>%</span>
                                            <?php else: ?>
                                                <span class="badge bg-primary">฿<?php echo number_format($coupon['discount_value']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($coupon['min_order_amount'] > 0): ?>
                                                ฿<?php echo number_format($coupon['min_order_amount']); ?>
                                            <?php else: ?>
                                                <span class="text-muted">ไม่กำหนด</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($coupon['usage_limit'] > 0): ?>
                                                <?php echo number_format($coupon['usage_limit']); ?> ครั้ง
                                            <?php else: ?>
                                                <span class="text-muted">ไม่จำกัด</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small>
                                                <?php echo formatDate($coupon['start_date'], 'd/m/Y'); ?><br>
                                                ถึง <?php echo formatDate($coupon['end_date'], 'd/m/Y'); ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge bg-info"><?php echo number_format($coupon['usage_count']); ?> ครั้ง</span>
                                            <?php if ($coupon['usage_limit'] > 0): ?>
                                                <br><small class="text-muted">เหลือ: <?php echo max(0, $coupon['usage_limit'] - $coupon['usage_count']); ?> ครั้ง</small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php
                                            $now = date('Y-m-d');
                                            $isExpired = $now > $coupon['end_date'];
                                            $isNotStarted = $now < $coupon['start_date'];
                                            $isUsedUp = $coupon['usage_limit'] > 0 && $coupon['usage_count'] >= $coupon['usage_limit'];
                                            ?>
                                            
                                            <?php if ($isExpired): ?>
                                                <span class="badge bg-secondary">หมดอายุ</span>
                                            <?php elseif ($isUsedUp): ?>
                                                <span class="badge bg-secondary">หมด</span>
                                            <?php elseif ($isNotStarted): ?>
                                                <span class="badge bg-warning">รอเริ่ม</span>
                                            <?php elseif ($coupon['is_active']): ?>
                                                <span class="badge bg-success">ใช้งาน</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">ปิด</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if (!$isExpired && !$isUsedUp): ?>
                                                <div class="form-check form-switch">
                                                    <input class="form-check-input" type="checkbox" 
                                                           <?php echo $coupon['is_active'] ? 'checked' : ''; ?>
                                                           onchange="toggleCoupon(<?php echo $coupon['coupon_id']; ?>, this.checked)">
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- สถิติ -->
        <div class="tab-pane fade" id="analytics">
            <div class="row">
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">สถิติโปรโมชั่น</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="promotionChart"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">สถิติคูปอง</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="couponChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal เพิ่มโปรโมชั่น -->
<div class="modal fade" id="addPromotionModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">เพิ่มโปรโมชั่น</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="add_promotion">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="name" class="form-label">ชื่อโปรโมชั่น <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" id="name" required>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="discount_type" class="form-label">ประเภทส่วนลด <span class="text-danger">*</span></label>
                                <select class="form-select" name="discount_type" id="discount_type" required>
                                    <option value="percentage">เปอร์เซ็นต์ (%)</option>
                                    <option value="fixed">จำนวนเงิน (บาท)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">คำอธิบาย</label>
                        <textarea class="form-control" name="description" id="description" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="discount_value" class="form-label">ค่าส่วนลด <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="discount_value" id="discount_value" required min="0" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="min_order_amount" class="form-label">ยอดขั้นต่ำ (บาท)</label>
                                <input type="number" class="form-control" name="min_order_amount" id="min_order_amount" min="0" step="0.01" value="0">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="start_date" class="form-label">วันที่เริ่ม <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="start_date" id="start_date" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="end_date" class="form-label">วันที่สิ้นสุด <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="end_date" id="end_date" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="is_active" checked>
                            <label class="form-check-label" for="is_active">
                                เปิดใช้งานทันที
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal เพิ่มคูปอง -->
<div class="modal fade" id="addCouponModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">เพิ่มคูปองส่วนลด</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="add_coupon">
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="code" class="form-label">รหัสคูปอง <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="code" id="code" required style="text-transform: uppercase;">
                                <div class="form-text">ควรใช้ตัวอักษรและตัวเลข เช่น SAVE20, WELCOME50</div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="coupon_discount_type" class="form-label">ประเภทส่วนลด <span class="text-danger">*</span></label>
                                <select class="form-select" name="discount_type" id="coupon_discount_type" required>
                                    <option value="percentage">เปอร์เซ็นต์ (%)</option>
                                    <option value="fixed">จำนวนเงิน (บาท)</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="coupon_description" class="form-label">คำอธิบาย</label>
                        <textarea class="form-control" name="description" id="coupon_description" rows="2"></textarea>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="coupon_discount_value" class="form-label">ค่าส่วนลด <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="discount_value" id="coupon_discount_value" required min="0" step="0.01">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="coupon_min_order_amount" class="form-label">ยอดขั้นต่ำ (บาท)</label>
                                <input type="number" class="form-control" name="min_order_amount" id="coupon_min_order_amount" min="0" step="0.01" value="0">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label for="usage_limit" class="form-label">จำกัดการใช้</label>
                                <input type="number" class="form-control" name="usage_limit" id="usage_limit" min="0" placeholder="0 = ไม่จำกัด">
                            </div>
                        </div>
                    </div>
                    
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="coupon_start_date" class="form-label">วันที่เริ่ม <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="start_date" id="coupon_start_date" required value="<?php echo date('Y-m-d'); ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="coupon_end_date" class="form-label">วันที่สิ้นสุด <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="end_date" id="coupon_end_date" required>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="is_active" id="coupon_is_active" checked>
                            <label class="form-check-label" for="coupon_is_active">
                                เปิดใช้งานทันที
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success">บันทึก</button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Toggle promotion status
function togglePromotion(promotionId, isActive) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="action" value="toggle_promotion">
        <input type="hidden" name="promotion_id" value="${promotionId}">
        <input type="hidden" name="is_active" value="${isActive ? 1 : 0}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Toggle coupon status
function toggleCoupon(couponId, isActive) {
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
        <input type="hidden" name="action" value="toggle_coupon">
        <input type="hidden" name="coupon_id" value="${couponId}">
        <input type="hidden" name="is_active" value="${isActive ? 1 : 0}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// DataTables
$(document).ready(function() {
    $('#promotionsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/th.json'
        },
        order: [[0, 'asc']],
        columnDefs: [
            { orderable: false, targets: [6] }
        ]
    });
    
    $('#couponsTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/th.json'
        },
        order: [[0, 'asc']],
        columnDefs: [
            { orderable: false, targets: [7] }
        ]
    });
});

// Generate random coupon code
function generateCouponCode() {
    const chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    let result = '';
    for (let i = 0; i < 8; i++) {
        result += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    document.getElementById('code').value = result;
}

// Add generate button next to coupon code field
document.addEventListener('DOMContentLoaded', function() {
    const codeField = document.getElementById('code');
    const generateBtn = document.createElement('button');
    generateBtn.type = 'button';
    generateBtn.className = 'btn btn-outline-secondary';
    generateBtn.innerHTML = '<i class="fas fa-random"></i>';
    generateBtn.onclick = generateCouponCode;
    
    const inputGroup = document.createElement('div');
    inputGroup.className = 'input-group';
    codeField.parentNode.insertBefore(inputGroup, codeField);
    inputGroup.appendChild(codeField);
    inputGroup.appendChild(generateBtn);
});
</script>

<?php include '../includes/footer.php'; ?>