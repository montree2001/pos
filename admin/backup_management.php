<?php
/**
 * จัดการการสำรองข้อมูล
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

$pageTitle = 'จัดการข้อมูลสำรอง';
$currentPage = 'backup';

// เริ่มต้นตัวแปร
$backupFiles = [];
$error = null;
$success = null;

// สร้างโฟลเดอร์สำรองข้อมูลถ้าไม่มี
$backupDir = '../backups/';
if (!file_exists($backupDir)) {
    mkdir($backupDir, 0755, true);
}

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
            case 'create_backup':
                $backupType = $_POST['backup_type'] ?? 'full';
                $description = trim($_POST['description'] ?? '');
                
                $timestamp = date('Y-m-d_H-i-s');
                $filename = "backup_{$backupType}_{$timestamp}.sql";
                $filepath = $backupDir . $filename;
                
                // สร้างไฟล์สำรองข้อมูล
                if (createDatabaseBackup($filepath, $backupType)) {
                    // บันทึกข้อมูลการสำรอง
                    $stmt = $conn->prepare("
                        INSERT INTO backup_logs 
                        (filename, backup_type, file_size, description, created_by, created_at)
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    
                    $fileSize = file_exists($filepath) ? filesize($filepath) : 0;
                    $stmt->execute([
                        $filename,
                        $backupType,
                        $fileSize,
                        $description ?: "การสำรองข้อมูลแบบ " . ($backupType === 'full' ? 'เต็มรูปแบบ' : 'เฉพาะข้อมูลสำคัญ'),
                        getCurrentUserId()
                    ]);
                    
                    $success = 'สร้างไฟล์สำรองข้อมูลเรียบร้อยแล้ว';
                } else {
                    throw new Exception('ไม่สามารถสร้างไฟล์สำรองข้อมูลได้');
                }
                break;
                
            case 'delete_backup':
                $backupId = intval($_POST['backup_id']);
                
                // ดึงข้อมูลไฟล์
                $stmt = $conn->prepare("SELECT filename FROM backup_logs WHERE backup_id = ?");
                $stmt->execute([$backupId]);
                $backup = $stmt->fetch();
                
                if ($backup) {
                    $filepath = $backupDir . $backup['filename'];
                    
                    // ลบไฟล์
                    if (file_exists($filepath)) {
                        unlink($filepath);
                    }
                    
                    // ลบจากฐานข้อมูล
                    $stmt = $conn->prepare("DELETE FROM backup_logs WHERE backup_id = ?");
                    $stmt->execute([$backupId]);
                    
                    $success = 'ลบไฟล์สำรองข้อมูลเรียบร้อยแล้ว';
                } else {
                    throw new Exception('ไม่พบไฟล์สำรองข้อมูล');
                }
                break;
                
            case 'restore_backup':
                $backupId = intval($_POST['backup_id']);
                
                // ดึงข้อมูลไฟล์
                $stmt = $conn->prepare("SELECT filename FROM backup_logs WHERE backup_id = ?");
                $stmt->execute([$backupId]);
                $backup = $stmt->fetch();
                
                if ($backup) {
                    $filepath = $backupDir . $backup['filename'];
                    
                    if (file_exists($filepath)) {
                        if (restoreDatabaseBackup($filepath)) {
                            $success = 'กู้คืนข้อมูลเรียบร้อยแล้ว';
                        } else {
                            throw new Exception('ไม่สามารถกู้คืนข้อมูลได้');
                        }
                    } else {
                        throw new Exception('ไม่พบไฟล์สำรองข้อมูล');
                    }
                } else {
                    throw new Exception('ไม่พบข้อมูลการสำรอง');
                }
                break;
        }
    }
    
    // ดึงรายการไฟล์สำรองข้อมูล
    $stmt = $conn->prepare("
        SELECT 
            bl.*,
            u.name as created_by_name
        FROM backup_logs bl
        LEFT JOIN users u ON bl.created_by = u.user_id
        ORDER BY bl.created_at DESC
    ");
    $stmt->execute();
    $backupFiles = $stmt->fetchAll();
    
} catch (Exception $e) {
    $error = $e->getMessage();
    writeLog("Backup management error: " . $error);
}

/**
 * สร้างไฟล์สำรองข้อมูล
 */
function createDatabaseBackup($filepath, $backupType = 'full') {
    try {
        $db = new Database();
        $conn = $db->getConnection();
        
        $output = "-- Smart Order Management System Database Backup\n";
        $output .= "-- Created: " . date('Y-m-d H:i:s') . "\n";
        $output .= "-- Type: " . $backupType . "\n\n";
        
        $output .= "SET FOREIGN_KEY_CHECKS = 0;\n";
        $output .= "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
        $output .= "SET time_zone = \"+00:00\";\n\n";
        
        // กำหนดตารางที่จะสำรอง
        if ($backupType === 'essential') {
            $tables = ['users', 'categories', 'products', 'orders', 'order_items', 'payments'];
        } else {
            // ดึงรายชื่อตารางทั้งหมด
            $stmt = $conn->query("SHOW TABLES");
            $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
        }
        
        foreach ($tables as $table) {
            // ข้อมูลโครงสร้างตาราง
            $stmt = $conn->query("SHOW CREATE TABLE `$table`");
            $createTable = $stmt->fetch(PDO::FETCH_NUM);
            
            $output .= "-- Structure for table `$table`\n";
            $output .= "DROP TABLE IF EXISTS `$table`;\n";
            $output .= $createTable[1] . ";\n\n";
            
            // ข้อมูลในตาราง
            $stmt = $conn->query("SELECT * FROM `$table`");
            $rowCount = $stmt->rowCount();
            
            if ($rowCount > 0) {
                $output .= "-- Data for table `$table`\n";
                
                // ดึงชื่อคอลัมน์
                $columnStmt = $conn->query("SHOW COLUMNS FROM `$table`");
                $columns = $columnStmt->fetchAll(PDO::FETCH_COLUMN);
                
                $output .= "INSERT INTO `$table` (`" . implode('`, `', $columns) . "`) VALUES\n";
                
                $values = [];
                while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                    $escapedRow = array_map(function($value) use ($conn) {
                        return $value === null ? 'NULL' : $conn->quote($value);
                    }, $row);
                    $values[] = '(' . implode(', ', $escapedRow) . ')';
                }
                
                $output .= implode(",\n", $values) . ";\n\n";
            }
        }
        
        $output .= "SET FOREIGN_KEY_CHECKS = 1;\n";
        
        return file_put_contents($filepath, $output) !== false;
        
    } catch (Exception $e) {
        writeLog("Backup creation error: " . $e->getMessage());
        return false;
    }
}

/**
 * กู้คืนข้อมูลจากไฟล์สำรอง
 */
function restoreDatabaseBackup($filepath) {
    try {
        if (!file_exists($filepath)) {
            throw new Exception('ไม่พบไฟล์สำรองข้อมูล');
        }
        
        $db = new Database();
        $conn = $db->getConnection();
        
        $sql = file_get_contents($filepath);
        if ($sql === false) {
            throw new Exception('ไม่สามารถอ่านไฟล์สำรองข้อมูลได้');
        }
        
        // แยกคำสั่ง SQL
        $statements = array_filter(array_map('trim', explode(';', $sql)));
        
        $conn->beginTransaction();
        
        foreach ($statements as $statement) {
            if (!empty($statement) && !str_starts_with($statement, '--')) {
                $conn->exec($statement);
            }
        }
        
        $conn->commit();
        return true;
        
    } catch (Exception $e) {
        if (isset($conn)) {
            $conn->rollBack();
        }
        writeLog("Backup restore error: " . $e->getMessage());
        return false;
    }
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">จัดการข้อมูลสำรอง</h1>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBackupModal">
                <i class="fas fa-plus"></i> สร้างไฟล์สำรอง
            </button>
            <button class="btn btn-info" onclick="showBackupInfo()">
                <i class="fas fa-info-circle"></i> คำแนะนำ
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

    <!-- สถิติและข้อมูลภาพรวม -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">ไฟล์สำรองทั้งหมด</h6>
                            <h4 class="mb-0"><?php echo count($backupFiles); ?></h4>
                        </div>
                        <i class="fas fa-database fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">ขนาดรวม</h6>
                            <h4 class="mb-0">
                                <?php 
                                $totalSize = array_sum(array_column($backupFiles, 'file_size'));
                                echo formatFileSize($totalSize); 
                                ?>
                            </h4>
                        </div>
                        <i class="fas fa-hdd fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">ล่าสุด</h6>
                            <h4 class="mb-0">
                                <?php 
                                if (!empty($backupFiles)) {
                                    echo formatDate($backupFiles[0]['created_at'], 'd/m/Y');
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
        <div class="col-md-3">
            <div class="card bg-warning text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">พื้นที่ว่าง</h6>
                            <h4 class="mb-0">
                                <?php 
                                $freeSpace = disk_free_space('.');
                                echo formatFileSize($freeSpace); 
                                ?>
                            </h4>
                        </div>
                        <i class="fas fa-server fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- รายการไฟล์สำรอง -->
    <div class="card">
        <div class="card-header">
            <h5 class="mb-0">รายการไฟล์สำรองข้อมูล</h5>
        </div>
        <div class="card-body">
            <?php if (empty($backupFiles)): ?>
                <div class="text-center py-5">
                    <i class="fas fa-database fa-3x text-muted mb-3"></i>
                    <h5>ยังไม่มีไฟล์สำรองข้อมูล</h5>
                    <p class="text-muted">คลิก "สร้างไฟล์สำรอง" เพื่อเริ่มสำรองข้อมูล</p>
                    <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#createBackupModal">
                        <i class="fas fa-plus"></i> สร้างไฟล์สำรองแรก
                    </button>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover" id="backupTable">
                        <thead>
                            <tr>
                                <th>ชื่อไฟล์</th>
                                <th>ประเภท</th>
                                <th>ขนาดไฟล์</th>
                                <th>คำอธิบาย</th>
                                <th>ผู้สร้าง</th>
                                <th>วันที่สร้าง</th>
                                <th>การจัดการ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($backupFiles as $backup): ?>
                                <tr>
                                    <td>
                                        <i class="fas fa-file-archive text-primary me-2"></i>
                                        <code><?php echo htmlspecialchars($backup['filename']); ?></code>
                                    </td>
                                    <td>
                                        <?php if ($backup['backup_type'] === 'full'): ?>
                                            <span class="badge bg-primary">เต็มรูปแบบ</span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary">ข้อมูลสำคัญ</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo formatFileSize($backup['file_size']); ?></td>
                                    <td><?php echo htmlspecialchars($backup['description']); ?></td>
                                    <td><?php echo htmlspecialchars($backup['created_by_name']); ?></td>
                                    <td><?php echo formatDate($backup['created_at']); ?></td>
                                    <td>
                                        <div class="btn-group btn-group-sm">
                                            <a href="../backups/<?php echo htmlspecialchars($backup['filename']); ?>" 
                                               class="btn btn-outline-primary" download>
                                                <i class="fas fa-download"></i>
                                            </a>
                                            <button class="btn btn-outline-warning" 
                                                    onclick="confirmRestore(<?php echo $backup['backup_id']; ?>, '<?php echo addslashes($backup['filename']); ?>')">
                                                <i class="fas fa-undo"></i>
                                            </button>
                                            <button class="btn btn-outline-danger" 
                                                    onclick="confirmDelete(<?php echo $backup['backup_id']; ?>, '<?php echo addslashes($backup['filename']); ?>')">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </div>
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

<!-- Modal สร้างไฟล์สำรอง -->
<div class="modal fade" id="createBackupModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">สร้างไฟล์สำรองข้อมูล</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="create_backup">
                    
                    <div class="mb-3">
                        <label for="backup_type" class="form-label">ประเภทการสำรอง <span class="text-danger">*</span></label>
                        <select class="form-select" name="backup_type" id="backup_type" required>
                            <option value="full">เต็มรูปแบบ (ทุกตารางและข้อมูล)</option>
                            <option value="essential">ข้อมูลสำคัญ (ผู้ใช้, เมนู, ออเดอร์)</option>
                        </select>
                        <div class="form-text">
                            <strong>เต็มรูปแบบ:</strong> สำรองทุกตารางรวมทั้งข้อมูล log และระบบ<br>
                            <strong>ข้อมูลสำคัญ:</strong> สำรองเฉพาะข้อมูลที่จำเป็นสำหรับการดำเนินงาน
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">คำอธิบาย</label>
                        <textarea class="form-control" name="description" id="description" rows="3" 
                                  placeholder="ระบุคำอธิบายหรือเหตุผลในการสำรองข้อมูล"></textarea>
                    </div>
                    
                    <div class="alert alert-info">
                        <h6><i class="fas fa-info-circle"></i> ข้อมูลที่ควรทราบ:</h6>
                        <ul class="mb-0">
                            <li>ไฟล์สำรองจะถูกเก็บในโฟลเดอร์ /backups/</li>
                            <li>ควรสำรองข้อมูลอย่างสม่ำเสมอ</li>
                            <li>ไฟล์สำรองขนาดใหญ่อาจใช้เวลาในการสร้าง</li>
                        </ul>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> สร้างไฟล์สำรอง
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// ยืนยันการลบ
function confirmDelete(backupId, filename) {
    Swal.fire({
        title: 'ยืนยันการลบ',
        text: `ต้องการลบไฟล์สำรอง "${filename}"?`,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#dc2626',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'ลบ',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="delete_backup">
                <input type="hidden" name="backup_id" value="${backupId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// ยืนยันการกู้คืน
function confirmRestore(backupId, filename) {
    Swal.fire({
        title: 'ยืนยันการกู้คืนข้อมูล',
        html: `
            <p>ต้องการกู้คืนข้อมูลจากไฟล์ <strong>"${filename}"</strong>?</p>
            <div class="alert alert-warning text-start">
                <strong>คำเตือน:</strong>
                <ul class="mb-0">
                    <li>ข้อมูลปัจจุบันจะถูกแทนที่</li>
                    <li>การดำเนินการนี้ไม่สามารถย้อนกลับได้</li>
                    <li>ควรสร้างไฟล์สำรองปัจจุบันก่อน</li>
                </ul>
            </div>
        `,
        icon: 'warning',
        showCancelButton: true,
        confirmButtonColor: '#f59e0b',
        cancelButtonColor: '#6b7280',
        confirmButtonText: 'กู้คืนข้อมูล',
        cancelButtonText: 'ยกเลิก'
    }).then((result) => {
        if (result.isConfirmed) {
            Swal.fire({
                title: 'กำลังกู้คืนข้อมูล...',
                text: 'กรุณารอสักครู่',
                allowOutsideClick: false,
                didOpen: () => {
                    Swal.showLoading();
                }
            });
            
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                <input type="hidden" name="action" value="restore_backup">
                <input type="hidden" name="backup_id" value="${backupId}">
            `;
            document.body.appendChild(form);
            form.submit();
        }
    });
}

// แสดงข้อมูลคำแนะนำ
function showBackupInfo() {
    Swal.fire({
        title: 'คำแนะนำการสำรองข้อมูล',
        html: `
            <div class="text-start">
                <h6>แนวปฏิบัติที่ดี:</h6>
                <ul>
                    <li><strong>สำรองข้อมูลเป็นประจำ:</strong> ควรสำรองข้อมูลอย่างน้อยสัปดาหละครั้ง</li>
                    <li><strong>เก็บไฟล์สำรองหลายที่:</strong> คัดลอกไฟล์ไปเก็บในที่อื่นด้วย</li>
                    <li><strong>ทดสอบการกู้คืน:</strong> ทดสอบกู้คืนข้อมูลเป็นครั้งคราว</li>
                    <li><strong>ตั้งชื่อไฟล์ให้ชัดเจน:</strong> ใส่คำอธิบายที่ระบุวัตถุประสงค์</li>
                </ul>
                
                <h6>ข้อควรระวัง:</h6>
                <ul>
                    <li>ไฟล์สำรองอาจมีข้อมูลส่วนบุคคล ควรเก็บรักษาอย่างปลอดภัย</li>
                    <li>การกู้คืนข้อมูลจะลบข้อมูลปัจจุบันทั้งหมด</li>
                    <li>ตรวจสอบพื้นที่ว่างในเซิร์ฟเวอร์ก่อนสำรองข้อมูล</li>
                </ul>
            </div>
        `,
        icon: 'info',
        confirmButtonText: 'เข้าใจแล้ว'
    });
}

// DataTable
$(document).ready(function() {
    $('#backupTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/th.json'
        },
        order: [[5, 'desc']],
        columnDefs: [
            { orderable: false, targets: [6] }
        ]
    });
});
</script>

<?php 
/**
 * ฟังก์ชันแปลงขนาดไฟล์
 */
function formatFileSize($bytes, $precision = 2) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    
    for ($i = 0; $bytes > 1024 && $i < count($units) - 1; $i++) {
        $bytes /= 1024;
    }
    
    return round($bytes, $precision) . ' ' . $units[$i];
}

include '../includes/footer.php'; 
?>