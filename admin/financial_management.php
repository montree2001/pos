<?php
/**
 * จัดการการเงิน และบัญชี
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

$pageTitle = 'จัดการการเงิน';
$currentPage = 'financial';

// เริ่มต้นตัวแปร
$expenses = [];
$monthlyStats = [];
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
            case 'add_expense':
                $amount = floatval($_POST['amount']);
                $category = trim($_POST['category']);
                $description = trim($_POST['description']);
                $expenseDate = $_POST['expense_date'];
                
                if ($amount <= 0) {
                    throw new Exception('จำนวนเงินต้องมากกว่า 0');
                }
                
                if (empty($category)) {
                    throw new Exception('กรุณาระบุหมวดหมู่ค่าใช้จ่าย');
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO expenses 
                    (amount, category, description, expense_date, created_by, created_at)
                    VALUES (?, ?, ?, ?, ?, NOW())
                ");
                
                $stmt->execute([
                    $amount,
                    $category,
                    $description,
                    $expenseDate,
                    getCurrentUserId()
                ]);
                
                $success = 'บันทึกค่าใช้จ่ายเรียบร้อยแล้ว';
                break;
                
            case 'delete_expense':
                $expenseId = intval($_POST['expense_id']);
                
                if ($expenseId <= 0) {
                    throw new Exception('ข้อมูลไม่ถูกต้อง');
                }
                
                $stmt = $conn->prepare("DELETE FROM expenses WHERE expense_id = ?");
                $stmt->execute([$expenseId]);
                
                $success = 'ลบค่าใช้จ่ายเรียบร้อยแล้ว';
                break;
        }
    }
    
    // ดึงข้อมูลค่าใช้จ่าย
    $stmt = $conn->prepare("
        SELECT 
            e.*,
            u.name as created_by_name
        FROM expenses e
        LEFT JOIN users u ON e.created_by = u.user_id
        ORDER BY e.expense_date DESC, e.created_at DESC
        LIMIT 100
    ");
    $stmt->execute();
    $expenses = $stmt->fetchAll();
    
    // สถิติรายเดือน
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(expense_date, '%Y-%m') as month,
            SUM(amount) as total_expenses,
            COUNT(*) as count_expenses
        FROM expenses
        WHERE expense_date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(expense_date, '%Y-%m')
        ORDER BY month DESC
    ");
    $stmt->execute();
    $expensesByMonth = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // รายได้รายเดือน
    $stmt = $conn->prepare("
        SELECT 
            DATE_FORMAT(created_at, '%Y-%m') as month,
            SUM(total_price) as total_revenue,
            COUNT(*) as count_orders
        FROM orders
        WHERE payment_status = 'paid' 
        AND created_at >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
        GROUP BY DATE_FORMAT(created_at, '%Y-%m')
        ORDER BY month DESC
    ");
    $stmt->execute();
    $revenueByMonth = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    
    // รวมข้อมูลรายเดือน
    $months = array_unique(array_merge(array_keys($expensesByMonth), array_keys($revenueByMonth)));
    sort($months);
    
    foreach ($months as $month) {
        $revenue = $revenueByMonth[$month] ?? 0;
        $expenses_total = $expensesByMonth[$month] ?? 0;
        
        $monthlyStats[] = [
            'month' => $month,
            'revenue' => $revenue,
            'expenses' => $expenses_total,
            'profit' => $revenue - $expenses_total,
            'profit_margin' => $revenue > 0 ? (($revenue - $expenses_total) / $revenue) * 100 : 0
        ];
    }
    
    // สถิติวันนี้
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(total_price), 0) as today_revenue,
            COUNT(*) as today_orders
        FROM orders 
        WHERE DATE(created_at) = CURDATE() AND payment_status = 'paid'
    ");
    $stmt->execute();
    $todayStats = $stmt->fetch();
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as today_expenses
        FROM expenses 
        WHERE DATE(expense_date) = CURDATE()
    ");
    $stmt->execute();
    $todayExpenses = $stmt->fetchColumn();
    
    // สถิติเดือนนี้
    $stmt = $conn->prepare("
        SELECT 
            COALESCE(SUM(total_price), 0) as month_revenue,
            COUNT(*) as month_orders
        FROM orders 
        WHERE YEAR(created_at) = YEAR(CURDATE()) 
        AND MONTH(created_at) = MONTH(CURDATE()) 
        AND payment_status = 'paid'
    ");
    $stmt->execute();
    $monthStats = $stmt->fetch();
    
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(amount), 0) as month_expenses
        FROM expenses 
        WHERE YEAR(expense_date) = YEAR(CURDATE()) 
        AND MONTH(expense_date) = MONTH(CURDATE())
    ");
    $stmt->execute();
    $monthExpenses = $stmt->fetchColumn();
    
} catch (Exception $e) {
    $error = $e->getMessage();
    writeLog("Financial management error: " . $error);
}

include '../includes/header.php';
?>

<div class="container-fluid">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h1 class="h3 mb-0">จัดการการเงิน</h1>
        <div>
            <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addExpenseModal">
                <i class="fas fa-plus"></i> เพิ่มค่าใช้จ่าย
            </button>
            <button class="btn btn-success" onclick="exportFinancialReport()">
                <i class="fas fa-download"></i> ส่งออกรายงาน
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

    <!-- สถิติภาพรวม -->
    <div class="row mb-4">
        <div class="col-md-3">
            <div class="card bg-primary text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">รายได้วันนี้</h6>
                            <h4 class="mb-0">฿<?php echo number_format($todayStats['today_revenue'], 2); ?></h4>
                            <small><?php echo number_format($todayStats['today_orders']); ?> ออเดอร์</small>
                        </div>
                        <i class="fas fa-chart-line fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-danger text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">ค่าใช้จ่ายวันนี้</h6>
                            <h4 class="mb-0">฿<?php echo number_format($todayExpenses, 2); ?></h4>
                        </div>
                        <i class="fas fa-money-bill-wave fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-success text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">กำไรวันนี้</h6>
                            <h4 class="mb-0">฿<?php echo number_format($todayStats['today_revenue'] - $todayExpenses, 2); ?></h4>
                        </div>
                        <i class="fas fa-coins fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card bg-info text-white">
                <div class="card-body">
                    <div class="d-flex align-items-center">
                        <div class="flex-grow-1">
                            <h6 class="mb-0">รายได้เดือนนี้</h6>
                            <h4 class="mb-0">฿<?php echo number_format($monthStats['month_revenue'], 2); ?></h4>
                            <small><?php echo number_format($monthStats['month_orders']); ?> ออเดอร์</small>
                        </div>
                        <i class="fas fa-calendar-alt fa-2x opacity-75"></i>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tabs -->
    <ul class="nav nav-tabs mb-4" role="tablist">
        <li class="nav-item">
            <a class="nav-link active" data-bs-toggle="tab" href="#overview">
                <i class="fas fa-chart-pie"></i> ภาพรวม
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#expenses">
                <i class="fas fa-receipt"></i> ค่าใช้จ่าย
            </a>
        </li>
        <li class="nav-item">
            <a class="nav-link" data-bs-toggle="tab" href="#profit-loss">
                <i class="fas fa-chart-bar"></i> กำไร-ขาดทุน
            </a>
        </li>
    </ul>

    <div class="tab-content">
        <!-- ภาพรวม -->
        <div class="tab-pane fade show active" id="overview">
            <div class="row">
                <div class="col-lg-8">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">แผนภูมิรายได้และค่าใช้จ่าย (12 เดือนล่าสุด)</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="revenueExpenseChart" height="300"></canvas>
                        </div>
                    </div>
                </div>
                <div class="col-lg-4">
                    <div class="card">
                        <div class="card-header">
                            <h5 class="mb-0">สัดส่วนค่าใช้จ่าย</h5>
                        </div>
                        <div class="card-body">
                            <canvas id="expenseCategoryChart"></canvas>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- ค่าใช้จ่าย -->
        <div class="tab-pane fade" id="expenses">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">รายการค่าใช้จ่าย</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="expensesTable">
                            <thead>
                                <tr>
                                    <th>วันที่</th>
                                    <th>หมวดหมู่</th>
                                    <th>รายละเอียด</th>
                                    <th>จำนวนเงิน</th>
                                    <th>ผู้บันทึก</th>
                                    <th>การจัดการ</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($expenses as $expense): ?>
                                    <tr>
                                        <td><?php echo formatDate($expense['expense_date']); ?></td>
                                        <td>
                                            <span class="badge bg-secondary"><?php echo htmlspecialchars($expense['category']); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($expense['description']); ?></td>
                                        <td class="text-end">
                                            <span class="text-danger fw-bold">฿<?php echo number_format($expense['amount'], 2); ?></span>
                                        </td>
                                        <td><?php echo htmlspecialchars($expense['created_by_name']); ?></td>
                                        <td>
                                            <button class="btn btn-sm btn-outline-danger" 
                                                    onclick="deleteExpense(<?php echo $expense['expense_id']; ?>)">
                                                <i class="fas fa-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- กำไร-ขาดทุน -->
        <div class="tab-pane fade" id="profit-loss">
            <div class="card">
                <div class="card-header">
                    <h5 class="mb-0">รายงานกำไร-ขาดทุนรายเดือน</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-striped">
                            <thead>
                                <tr>
                                    <th>เดือน</th>
                                    <th class="text-end">รายได้</th>
                                    <th class="text-end">ค่าใช้จ่าย</th>
                                    <th class="text-end">กำไรสุทธิ</th>
                                    <th class="text-end">อัตรากำไร</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach (array_reverse($monthlyStats) as $stat): ?>
                                    <tr>
                                        <td><?php echo date('F Y', strtotime($stat['month'] . '-01')); ?></td>
                                        <td class="text-end text-success">฿<?php echo number_format($stat['revenue'], 2); ?></td>
                                        <td class="text-end text-danger">฿<?php echo number_format($stat['expenses'], 2); ?></td>
                                        <td class="text-end <?php echo $stat['profit'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <strong>฿<?php echo number_format($stat['profit'], 2); ?></strong>
                                        </td>
                                        <td class="text-end <?php echo $stat['profit_margin'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo number_format($stat['profit_margin'], 1); ?>%
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Modal เพิ่มค่าใช้จ่าย -->
<div class="modal fade" id="addExpenseModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST">
                <div class="modal-header">
                    <h5 class="modal-title">เพิ่มค่าใช้จ่าย</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
                    <input type="hidden" name="action" value="add_expense">
                    
                    <div class="mb-3">
                        <label for="amount" class="form-label">จำนวนเงิน <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <span class="input-group-text">฿</span>
                            <input type="number" class="form-control" name="amount" id="amount" required min="0" step="0.01">
                        </div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="category" class="form-label">หมวดหมู่ <span class="text-danger">*</span></label>
                        <select class="form-select" name="category" id="category" required>
                            <option value="">เลือกหมวดหมู่</option>
                            <option value="วัตถุดิบ">วัตถุดิบ</option>
                            <option value="เงินเดือน">เงินเดือน</option>
                            <option value="ค่าเช่า">ค่าเช่า</option>
                            <option value="ค่าน้ำ-ไฟ">ค่าน้ำ-ไฟ</option>
                            <option value="ค่าขนส่ง">ค่าขนส่ง</option>
                            <option value="ค่าบำรุงรักษา">ค่าบำรุงรักษา</option>
                            <option value="ค่าโฆษณา">ค่าโฆษณา</option>
                            <option value="อุปกรณ์">อุปกรณ์</option>
                            <option value="อื่นๆ">อื่นๆ</option>
                        </select>
                    </div>
                    
                    <div class="mb-3">
                        <label for="description" class="form-label">รายละเอียด <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="description" id="description" rows="3" required></textarea>
                    </div>
                    
                    <div class="mb-3">
                        <label for="expense_date" class="form-label">วันที่ <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" name="expense_date" id="expense_date" required value="<?php echo date('Y-m-d'); ?>">
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

<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
// ลบค่าใช้จ่าย
function deleteExpense(expenseId) {
    if (confirm('ต้องการลบค่าใช้จ่ายนี้?')) {
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="csrf_token" value="<?php echo generateCSRFToken(); ?>">
            <input type="hidden" name="action" value="delete_expense">
            <input type="hidden" name="expense_id" value="${expenseId}">
        `;
        document.body.appendChild(form);
        form.submit();
    }
}

// ส่งออกรายงาน
function exportFinancialReport() {
    window.open('../api/export_report.php?type=financial', '_blank');
}

// แผนภูมิรายได้และค่าใช้จ่าย
const revenueExpenseData = <?php echo json_encode($monthlyStats); ?>;
const ctx1 = document.getElementById('revenueExpenseChart').getContext('2d');
new Chart(ctx1, {
    type: 'line',
    data: {
        labels: revenueExpenseData.map(d => {
            const date = new Date(d.month + '-01');
            return date.toLocaleDateString('th-TH', { year: 'numeric', month: 'short' });
        }),
        datasets: [{
            label: 'รายได้',
            data: revenueExpenseData.map(d => d.revenue),
            borderColor: '#10b981',
            backgroundColor: 'rgba(16, 185, 129, 0.1)',
            tension: 0.1
        }, {
            label: 'ค่าใช้จ่าย',
            data: revenueExpenseData.map(d => d.expenses),
            borderColor: '#ef4444',
            backgroundColor: 'rgba(239, 68, 68, 0.1)',
            tension: 0.1
        }, {
            label: 'กำไร',
            data: revenueExpenseData.map(d => d.profit),
            borderColor: '#3b82f6',
            backgroundColor: 'rgba(59, 130, 246, 0.1)',
            tension: 0.1
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: {
                position: 'top',
            }
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: {
                    callback: function(value) {
                        return '฿' + value.toLocaleString();
                    }
                }
            }
        }
    }
});

// DataTable
$(document).ready(function() {
    $('#expensesTable').DataTable({
        language: {
            url: '//cdn.datatables.net/plug-ins/1.11.5/i18n/th.json'
        },
        order: [[0, 'desc']],
        columnDefs: [
            { orderable: false, targets: [5] }
        ]
    });
});
</script>

<?php include '../includes/footer.php'; ?>