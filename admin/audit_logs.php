<?php
/**
 * บันทึกการตรวจสอบและการใช้งานระบบ
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

$pageTitle = 'บันทึกการตรวจสอบ';
$currentPage = 'audit';

// เริ่มต้นตัวแปร
$auditLogs = [];
$userActivities = [];
$systemLogs = [];
$error = null;
$success = null;

// ตัวกรองข้อมูล
$filters = [
    'date_from' => $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days')),
    'date_to' => $_GET['date_to'] ?? date('Y-m-d'),
    'user_id' => $_GET['user_id'] ?? '',
    'action_type' => $_GET['action_type'] ?? '',
    'limit' => $_GET['limit'] ?? 100
];

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
            case 'clear_old_logs':
                $days = intval($_POST['days']) ?: 30;
                
                $stmt = $conn->prepare("
                    DELETE FROM audit_logs 
                    WHERE created_at < DATE_SUB(NOW(), INTERVAL ? DAY)
                ");
                $stmt->execute([$days]);
                
                $deletedCount = $stmt->rowCount();
                $success = "ลบบันทึกเก่าแล้ว {$deletedCount} รายการ (เก่ากว่า {$days} วัน)";
                break;
                
            case 'export_logs':
                $format = $_POST['format'] ?? 'csv';
                exportAuditLogs($filters, $format);
                exit();
        }
    }
    
    // สร้างเงื่อนไข WHERE สำหรับการกรองข้อมูล
    $whereConditions = ["DATE(al.created_at) BETWEEN ? AND ?"];
    $params = [$filters['date_from'], $filters['date_to']];
    
    if (!empty($filters['user_id'])) {
        $whereConditions[] = "al.user_id = ?";
        $params[] = $filters['user_id'];
    }
    
    if (!empty($filters['action_type'])) {
        $whereConditions[] = "al.action_type = ?";
        $params[] = $filters['action_type'];
    }
    
    $whereClause = implode(' AND ', $whereConditions);
    
    // ดึงบันทึกการตรวจสอบ
    $stmt = $conn->prepare("
        SELECT 
            al.*,
            u.name as user_name,
            u.role as user_role
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE {$whereClause}
        ORDER BY al.created_at DESC
        LIMIT ?
    ");
    
    $params[] = intval($filters['limit']);
    $stmt->execute($params);
    $auditLogs = $stmt->fetchAll();
    
    // สถิติการใช้งานตามผู้ใช้
    $stmt = $conn->prepare("
        SELECT 
            u.name,
            u.role,
            COUNT(*) as activity_count,
            MAX(al.created_at) as last_activity
        FROM audit_logs al
        LEFT JOIN users u ON al.user_id = u.user_id
        WHERE {$whereClause}
        GROUP BY al.user_id
        ORDER BY activity_count DESC
        LIMIT 10
    ");
    
    // ใช้ params เดิมแต่ไม่รวม limit
    $userParams = array_slice($params, 0, -1);
    $stmt->execute($userParams);
    $userActivities = $stmt->fetchAll();
    
    // สถิติประเภทการกระทำ
    $stmt = $conn->prepare("
        SELECT 
            action_type,
            COUNT(*) as count,
            COUNT(DISTINCT user_id) as unique_users
        FROM audit_logs al
        WHERE {$whereClause}
        GROUP BY action_type
        ORDER BY count DESC
    ");
    $stmt->execute($userParams);
    $actionStats = $stmt->fetchAll();
    
    // ดึงรายชื่อผู้ใช้สำหรับตัวกรอง
    $stmt = $conn->prepare("SELECT user_id, name, role FROM users ORDER BY name");
    $stmt->execute();
    $users = $stmt->fetchAll();
    
    // ดึงประเภทการกระทำที่มี
    $stmt = $conn->prepare("SELECT DISTINCT action_type FROM audit_logs ORDER BY action_type");
    $stmt->execute();
    $actionTypes = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
} catch (Exception $e) {
    $error = $e->getMessage();
    writeLog("Audit logs error: " . $error);
}

/**
 * ส่งออกบันทึกการตรวจสอบ
 */
function exportAuditLogs($filters, $format = 'csv') {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        // สร้างเงื่อนไข WHERE
        $whereConditions = ["DATE(al.created_at) BETWEEN ? AND ?"];
        $params = [$filters['date_from'], $filters['date_to']];
        
        if (!empty($filters['user_id'])) {
            $whereConditions[] = "al.user_id = ?";
            $params[] = $filters['user_id'];
        }
        
        if (!empty($filters['action_type'])) {
            $whereConditions[] = "al.action_type = ?";
            $params[] = $filters['action_type'];
        }
        
        $whereClause = implode(' AND ', $whereConditions);
        
        $stmt = $conn->prepare("
            SELECT 
                al.created_at,
                COALESCE(u.name, 'ระบบ') as user_name,
                u.role as user_role,
                al.action_type,
                al.action_description,
                al.ip_address,
                al.user_agent,
                al.additional_data
            FROM audit_logs al
            LEFT JOIN users u ON al.user_id = u.user_id
            WHERE {$whereClause}
            ORDER BY al.created_at DESC
        ");
        
        $stmt->execute($params);
        $logs = $stmt->fetchAll();
        
        $filename = 'audit_logs_' . date('Y-m-d_H-i-s') . '.' . $format;
        
        if ($format === 'csv') {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . $filename . '"');
            
            $output = fopen('php://output', 'w');
            
            // BOM สำหรับ UTF-8
            fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
            
            // หัวตาราง
            fputcsv($output, [
                'วันที่/เวลา',
                'ผู้ใช้',
                'บทบาท',
                'ประเภทการกระทำ',
                'รายละเอียด',
                'IP Address',
                'User Agent',
                'ข้อมูลเพิ่มเติม'
            ]);
            
            // ข้อมูล
            foreach ($logs as $log) {
                fputcsv($output, [
                    $log['created_at'],
                    $log['user_name'],
                    $log['user_role'],
                    $log['action_type'],
                    $log['action_description'],
                    $log['ip_address'],
                    $log['user_agent'],
                    $log['additional_data']
                ]);
            }
            
            fclose($output);
        }
        
    } catch (Exception $e) {
        writeLog("Export audit logs error: " . $e->getMessage());
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">บันทึกการตรวจสอบ</h1>
        <div>
            <button class="btn btn-success" data-bs-toggle="modal" data-bs-target="#exportModal">
                <i class="fas fa-download"></i> ส่งออกข้อมูล
            </button>
            <button class="btn btn-warning" data-bs-toggle="modal" data-bs-target="#cleanupModal">
                <i class="fas fa-broom"></i> ล้างข้อมูลเก่า
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

    <!-- ตัวกรองข้อมูล -->
    <div class="card mb-4">
        <div class="card-header">
            <h5 class="mb-0">ตัวกรองข้อมูล</h5>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-md-2">
                    <label for="date_from" class="form-label">จากวันที่</label>
                    <input type="date" class="form-control" name="date_from" id="date_from" value="<?php echo htmlspecialchars($filters['date_from']); ?>">
                </div>
                <div class="col-md-2">
                    <label for="date_to" class="form-label">ถึงวันที่</label>
                    <input type="date" class="form-control" name="date_to" id="date_to" value="<?php echo htmlspecialchars($filters['date_to']); ?>">
                </div>
                <div class="col-md-2">
                    <label for="user_id" class="form-label">ผู้ใช้</label>
                    <select class="form-select" name="user_id" id="user_id">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?php echo $user['user_id']; ?>" 
                                    <?php echo $filters['user_id'] == $user['user_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($user['name']) . ' (' . $user['role'] . ')'; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="action_type" class="form-label">ประเภทการกระทำ</label>
                    <select class="form-select" name="action_type" id="action_type">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($actionTypes as $type): ?>
                            <option value="<?php echo htmlspecialchars($type); ?>" 
                                    <?php echo $filters['action_type'] === $type ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label for="limit" class="form-label">จำนวนแสดง</label>
                    <select class="form-select" name="limit" id="limit">
                        <option value="50" <?php echo $filters['limit'] == 50 ? 'selected' : ''; ?>>50</option>
                        <option value="100" <?php echo $filters['limit'] == 100 ? 'selected' : ''; ?>>100</option>
                        <option value="200" <?php echo $filters['limit'] == 200 ? 'selected' : ''; ?>>200</option>
                        <option value="500" <?php echo $filters['limit'] == 500 ? 'selected' : ''; ?>>500</option>
                    </select>
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search"></i> กรองข้อมูล
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- สถิติภาพรวม -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">บันทึกทั้งหมด</h6>
                            <h4 class="mb-0"><?php echo number_format(count($auditLogs)); ?></h4>
                            <small>ตามช่วงที่เลือก</small>
                        </div>
                        <i class="fas fa-list fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">ผู้ใช้ที่มีกิจกรรม</h6>
                            <h4 class="mb-0"><?php echo number_format(count($userActivities)); ?></h4>
                            <small>คน</small>
                        </div>
                        <i class="fas fa-users fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">ประเภทการกระทำ</h6>
                            <h4 class="mb-0"><?php echo number_format(count($actionStats)); ?></h4>
                            <small>ประเภท</small>
                        </div>
                        <i class="fas fa-cogs fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">การกระทำล่าสุด</h6>
                            <h4 class="mb-0">
                                <?php 
                                if (!empty($auditLogs)) {
                                    echo formatDate($auditLogs[0]['created_at'], 'H:i');
                                } else {
                                    echo '-';
                                }
                                ?>
                            </h4>
                        </div>
                        <i class="fas fa-clock fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#logs">
                <i class="fas fa-list-alt"></i> บันทึกกิจกรรม
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#user-stats">
                <i class="fas fa-chart-bar"></i> สถิติผู้ใช้
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#action-stats">
                <i class="fas fa-chart-pie"></i> สถิติการกระทำ
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- บันทึกกิจกรรม -->
        <div class="tab-pane fade show active" id="logs">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">บันทึกกิจกรรมระบบ</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($auditLogs)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-search fa-3x text-muted mb-3"></i>
                            <h5>ไม่พบข้อมูลตามเงื่อนไขที่กำหนด</h5>
                            <p class="text-muted">ลองปรับเปลี่ยนตัวกรองข้อมูล</p>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover" id="auditTable">
                                <thead>
                                    <tr>
                                        <th>วันที่/เวลา</th>
                                        <th>ผู้ใช้</th>
                                        <th>การกระทำ</th>
                                        <th>รายละเอียด</th>
                                        <th>IP Address</th>
                                        <th>ข้อมูลเพิ่มเติม</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($auditLogs as $log): ?>
                                        <tr>
                                            <td>
                                                <small><?php echo formatDate($log['created_at']); ?></small>
                                            </td>
                                            <td>
                                                <?php if ($log['user_name']): ?>
                                                    <div>
                                                        <strong><?php echo htmlspecialchars($log['user_name']); ?></strong>
                                                        <br><small class="text-muted"><?php echo htmlspecialchars($log['user_role']); ?></small>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">ระบบ</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary"><?php echo htmlspecialchars($log['action_type']); ?></span>
                                            </td>
                                            <td><?php echo htmlspecialchars($log['action_description']); ?></td>
                                            <td>
                                                <code><?php echo htmlspecialchars($log['ip_address']); ?></code>
                                            </td>
                                            <td>
                                                <?php if ($log['additional_data']): ?>
                                                    <button class="btn btn-sm btn-outline-info" 
                                                            onclick="showDetails('<?php echo addslashes($log['additional_data']); ?>')">
                                                        <i class="fas fa-eye"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- สถิติผู้ใช้ -->
        <div class="tab-pane fade" id="user-stats">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">สถิติการใช้งานตามผู้ใช้</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>ผู้ใช้</th>
                                    <th>บทบาท</th>
                                    <th>จำนวนกิจกรรม</th>
                                    <th>กิจกรรมล่าสุด</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($userActivities as $activity): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($activity['name']); ?></td>
                                        <td>
                                            <span class="badge bg-primary"><?php echo htmlspecialchars($activity['role']); ?></span>
                                        </td>
                                        <td>
                                            <span class="badge bg-success"><?php echo number_format($activity['activity_count']); ?></span>
                                        </td>
                                        <td><?php echo formatDate($activity['last_activity']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- สถิติการกระทำ -->
        <div class="tab-pane fade" id="action-stats">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">สถิติประเภทการกระทำ</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-8">
                            <div class="table-responsive">
                                <table class="table table-striped">
                                    <thead>
                                        <tr>
                                            <th>ประเภทการกระทำ</th>
                                            <th>จำนวนครั้ง</th>
                                            <th>ผู้ใช้ที่เกี่ยวข้อง</th>
                                            <th>เปอร์เซ็นต์</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php 
                                        $totalActions = array_sum(array_column($actionStats, 'count'));
                                        foreach ($actionStats as $stat): 
                                            $percentage = $totalActions > 0 ? ($stat['count'] / $totalActions) * 100 : 0;
                                        ?>
                                            <tr>
                                                <td><?php echo htmlspecialchars($stat['action_type']); ?></td>
                                                <td>
                                                    <span class="badge bg-info"><?php echo number_format($stat['count']); ?></span>
                                                </td>
                                                <td><?php echo number_format($stat['unique_users']); ?> คน</td>
                                                <td>
                                                    <div class="progress" style="height: 20px;">
                                                        <div class="progress-bar" role="progressbar" 
                                                             style="width: <?php echo $percentage; ?>%"
                                                             aria-valuenow="<?php echo $percentage; ?>" 
                                                             aria-valuemin="0" aria-valuemax="100">
                                                            <?php echo number_format($percentage, 1); ?>%
                                                        </div>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <canvas id="actionChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal ส่งออกข้อมูล -->
<div class="modal fade" id="exportModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">ส่งออกข้อมูลบันทึก</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="export_logs">
                    
                    <div class="mb-3">
                        <label for="export_format" class="form-label">รูปแบบไฟล์</label>
                        <select class="form-select" name="format" id="export_format">
                            <option value="csv">CSV (Excel)</option>
                        </select>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> ข้อมูลที่จะส่งออก:</h6>
                        <ul class="mb-0">
                            <li>ตามเงื่อนไขการกรองที่กำหนดไว้</li>
                            <li>รวมข้อมูลผู้ใช้และรายละเอียดการกระทำ</li>
                            <li>ไฟล์จะถูกดาวน์โหลดทันที</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-success">
                        <i class="fas fa-download"></i> ส่งออก
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Modal ล้างข้อมูลเก่า -->
<div class="modal fade" id="cleanupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">ล้างข้อมูลเก่า</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="clear_old_logs">
                    
                    <div class="mb-3">
                        <label for="days" class="form-label">ลบข้อมูลที่เก่ากว่า (วัน)</label>
                        <input type="number" class="form-control" name="days" id="days" value="30" min="1" max="365" required>
                        <div class="form-text">ข้อมูลที่เก่ากว่าจำนวนวันที่ระบุจะถูกลบอย่างถาวร</div>
                    </div>
                    
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-exclamation-triangle"></i> คำเตือน:</h6>
                        <ul class="mb-0">
                            <li>การลบข้อมูลไม่สามารถย้อนกลับได้</li>
                            <li>ควรส่งออกข้อมูลสำรองก่อนลบ</li>
                            <li>เก็บข้อมูลไว้ตามระยะเวลาที่กฎหมายกำหนด</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-warning">
                        <i class="fas fa-broom"></i> ล้างข้อมูล
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// แสดงรายละเอียดเพิ่มเติม
function showDetails(data) {
    try {
        const jsonData = JSON.parse(data);
        const formatted = JSON.stringify(jsonData, null, 2);
        
        Swal.fire({
            title: 'ข้อมูลเพิ่มเติม',
            html: `<pre class="text-start">${formatted}</pre>`,
            icon: 'info',
            confirmButtonText: 'ปิด'
        });
    } catch (e) {
        Swal.fire({
            title: 'ข้อมูลเพิ่มเติม',
            text: data,
            icon: 'info',
            confirmButtonText: 'ปิด'
        });
    }
}

// สร้างแผนภูมิสถิติการกระทำ
const actionData = <?php echo json_encode($actionStats); ?>;
if (actionData.length > 0) {
    const ctx = document.getElementById('actionChart').getContext('2d');
    new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: actionData.map(item => item.action_type),
            datasets: [{
                data: actionData.map(item => item.count),
                backgroundColor: [
                    '#ff6384', '#36a2eb', '#ffce56', '#4bc0c0',
                    '#9966ff', '#ff9f40', '#ff6384', '#c9cbcf'
                ]
            }]
        },
        options: {
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom'
                }
            }
        }
    });
}

// DataTable
$(document).ready(function() {
    $('#auditTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/th.json'
        },
        order: [[0, 'desc']],
        columnDefs: [
            { orderable: false, targets: [5] }
        ],
        pageLength: 25
    });
});
</script>

<?php include '../includes/footer.php'; ?>