<?php
/**
 * Sidebar เมนู (Admin)
 * Smart Order Management System
 */

// ป้องกันการเข้าถึงโดยตรง
if (!defined('SYSTEM_INIT')) {
    die('Direct access not allowed');
}

// ตรวจสอบสิทธิ์ Admin
if (!isLoggedIn() || getCurrentUserRole() !== 'admin') {
    return;
}

// กำหนดเมนูที่ Active
$currentPage = basename($_SERVER['PHP_SELF']);
$currentPath = $_SERVER['REQUEST_URI'];

function isMenuActive($menuPage) {
    global $currentPage;
    return $currentPage === $menuPage ? 'active' : '';
}

function isMenuGroupActive($menuPages) {
    global $currentPage;
    return in_array($currentPage, $menuPages) ? 'show' : '';
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-md-3 col-lg-2 px-0">
            <div class="sidebar">
                <div class="p-3">
                    <nav class="nav flex-column">
                        
                        <!-- Dashboard -->
                        <a class="nav-link <?php echo isMenuActive('index.php'); ?>" href="<?php echo SITE_URL; ?>/admin/index.php">
                            <i class="fas fa-tachometer-alt me-2"></i>
                            หน้าหลัก
                        </a>
                        
                        <!-- Order Management -->
                        <div class="nav-section">
                            <h6 class="nav-header px-3 py-2 text-muted fw-bold">
                                <i class="fas fa-shopping-cart me-2"></i>
                                จัดการออเดอร์
                            </h6>
                            
                            <a class="nav-link <?php echo isMenuActive('order_management.php'); ?>" href="<?php echo SITE_URL; ?>/admin/order_management.php">
                                <i class="fas fa-list-alt me-2"></i>
                                รายการออเดอร์
                                <span class="badge bg-primary ms-auto" id="pendingOrderCount">0</span>
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('queue_management.php'); ?>" href="<?php echo SITE_URL; ?>/admin/queue_management.php">
                                <i class="fas fa-clock me-2"></i>
                                จัดการคิว
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('payment_management.php'); ?>" href="<?php echo SITE_URL; ?>/admin/payment_management.php">
                                <i class="fas fa-credit-card me-2"></i>
                                จัดการการชำระเงิน
                            </a>
                        </div>
                        
                        <!-- Product Management -->
                        <div class="nav-section">
                            <h6 class="nav-header px-3 py-2 text-muted fw-bold">
                                <i class="fas fa-box me-2"></i>
                                จัดการสินค้า
                            </h6>
                            
                            <a class="nav-link <?php echo isMenuActive('menu_management.php'); ?>" href="<?php echo SITE_URL; ?>/admin/menu_management.php">
                                <i class="fas fa-utensils me-2"></i>
                                จัดการเมนู
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('category_management.php'); ?>" href="<?php echo SITE_URL; ?>/admin/category_management.php">
                                <i class="fas fa-tags me-2"></i>
                                จัดการหมวดหมู่
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('product_options.php'); ?>" href="<?php echo SITE_URL; ?>/admin/product_options.php">
                                <i class="fas fa-cogs me-2"></i>
                                ตัวเลือกสินค้า
                            </a>
                        </div>
                        
                        <!-- User Management -->
                        <div class="nav-section">
                            <h6 class="nav-header px-3 py-2 text-muted fw-bold">
                                <i class="fas fa-users me-2"></i>
                                จัดการผู้ใช้
                            </h6>
                            
                            <a class="nav-link <?php echo isMenuActive('user_management.php'); ?>" href="<?php echo SITE_URL; ?>/admin/user_management.php">
                                <i class="fas fa-user-friends me-2"></i>
                                ผู้ใช้ทั้งหมด
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('staff_management.php'); ?>" href="<?php echo SITE_URL; ?>/admin/staff_management.php">
                                <i class="fas fa-user-tie me-2"></i>
                                พนักงาน
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('customer_management.php'); ?>" href="<?php echo SITE_URL; ?>/admin/customer_management.php">
                                <i class="fas fa-user-check me-2"></i>
                                ลูกค้า
                            </a>
                        </div>
                        
                        <!-- Reports & Analytics -->
                        <div class="nav-section">
                            <h6 class="nav-header px-3 py-2 text-muted fw-bold">
                                <i class="fas fa-chart-bar me-2"></i>
                                รายงานและสถิติ
                            </h6>
                            
                            <a class="nav-link <?php echo isMenuActive('reports.php'); ?>" href="<?php echo SITE_URL; ?>/admin/reports.php">
                                <i class="fas fa-chart-line me-2"></i>
                                รายงานยอดขาย
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('analytics.php'); ?>" href="<?php echo SITE_URL; ?>/admin/analytics.php">
                                <i class="fas fa-chart-pie me-2"></i>
                                วิเคราะห์ข้อมูล
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('queue_analytics.php'); ?>" href="<?php echo SITE_URL; ?>/admin/queue_analytics.php">
                                <i class="fas fa-stopwatch me-2"></i>
                                สถิติคิว
                            </a>
                        </div>
                        
                        <!-- Communications -->
                        <div class="nav-section">
                            <h6 class="nav-header px-3 py-2 text-muted fw-bold">
                                <i class="fas fa-comments me-2"></i>
                                การสื่อสาร
                            </h6>
                            
                            <a class="nav-link <?php echo isMenuActive('line_settings.php'); ?>" href="<?php echo SITE_URL; ?>/admin/line_settings.php">
                                <i class="fab fa-line me-2"></i>
                                ตั้งค่า LINE OA
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('chatbot_management.php'); ?>" href="<?php echo SITE_URL; ?>/admin/chatbot_management.php">
                                <i class="fas fa-robot me-2"></i>
                                จัดการ Chatbot
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('notifications.php'); ?>" href="<?php echo SITE_URL; ?>/admin/notifications.php">
                                <i class="fas fa-bell me-2"></i>
                                การแจ้งเตือน
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('message_templates.php'); ?>" href="<?php echo SITE_URL; ?>/admin/message_templates.php">
                                <i class="fas fa-envelope-open-text me-2"></i>
                                เทมเพลตข้อความ
                            </a>
                        </div>
                        
                        <!-- System Settings -->
                        <div class="nav-section">
                            <h6 class="nav-header px-3 py-2 text-muted fw-bold">
                                <i class="fas fa-cog me-2"></i>
                                ตั้งค่าระบบ
                            </h6>
                            
                            <a class="nav-link <?php echo isMenuActive('system_settings.php'); ?>" href="<?php echo SITE_URL; ?>/admin/system_settings.php">
                                <i class="fas fa-sliders-h me-2"></i>
                                ตั้งค่าทั่วไป
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('payment_settings.php'); ?>" href="<?php echo SITE_URL; ?>/admin/payment_settings.php">
                                <i class="fas fa-money-bill-wave me-2"></i>
                                ตั้งค่าการชำระเงิน
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('printer_settings.php'); ?>" href="<?php echo SITE_URL; ?>/admin/printer_settings.php">
                                <i class="fas fa-print me-2"></i>
                                ตั้งค่าเครื่องพิมพ์
                            </a>
                            
                            <a class="nav-link <?php echo isMenuActive('backup_restore.php'); ?>" href="<?php echo SITE_URL; ?>/admin/backup_restore.php">
                                <i class="fas fa-database me-2"></i>
                                สำรองข้อมูล
                            </a>
                        </div>
                        
                        <!-- Quick Actions -->
                        <div class="nav-section">
                            <h6 class="nav-header px-3 py-2 text-muted fw-bold">
                                <i class="fas fa-bolt me-2"></i>
                                การดำเนินการด่วน
                            </h6>
                            
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/pos/" target="_blank">
                                <i class="fas fa-cash-register me-2"></i>
                                เปิด POS
                                <i class="fas fa-external-link-alt ms-auto"></i>
                            </a>
                            
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/kitchen/" target="_blank">
                                <i class="fas fa-fire me-2"></i>
                                หน้าจอครัว
                                <i class="fas fa-external-link-alt ms-auto"></i>
                            </a>
                            
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/customer/" target="_blank">
                                <i class="fas fa-storefront me-2"></i>
                                หน้าลูกค้า
                                <i class="fas fa-external-link-alt ms-auto"></i>
                            </a>
                        </div>
                        
                        <!-- System Status -->
                        <div class="nav-section">
                            <div class="px-3 py-2">
                                <h6 class="text-muted fw-bold mb-2">
                                    <i class="fas fa-server me-2"></i>
                                    สถานะระบบ
                                </h6>
                                
                                <div class="system-status">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="text-muted">ฐานข้อมูล</small>
                                        <span class="badge bg-success" id="dbStatus">
                                            <i class="fas fa-check"></i>
                                        </span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <small class="text-muted">LINE OA</small>
                                        <span class="badge bg-warning" id="lineStatus">
                                            <i class="fas fa-exclamation"></i>
                                        </span>
                                    </div>
                                    
                                    <div class="d-flex justify-content-between align-items-center">
                                        <small class="text-muted">เครื่องพิมพ์</small>
                                        <span class="badge bg-secondary" id="printerStatus">
                                            <i class="fas fa-question"></i>
                                        </span>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                    </nav>
                </div>
            </div>
        </div>
        
        <div class="col-md-9 col-lg-10">
            <div class="main-content">

<style>
    .nav-section {
        margin-bottom: 1rem;
    }
    
    .nav-header {
        font-size: 0.75rem;
        text-transform: uppercase;
        letter-spacing: 0.05em;
        border-bottom: 1px solid #e5e7eb;
        margin-bottom: 0.5rem;
    }
    
    .nav-link {
        position: relative;
        font-size: 0.875rem;
    }
    
    .nav-link .badge {
        font-size: 0.7rem;
        padding: 2px 6px;
    }
    
    .nav-link i.fa-external-link-alt {
        font-size: 0.7rem;
        opacity: 0.7;
    }
    
    .system-status .badge {
        width: 20px;
        height: 20px;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 0.6rem;
    }
    
    @media (max-width: 768px) {
        .sidebar {
            position: static;
            min-height: auto;
        }
        
        .nav-header {
            font-size: 0.7rem;
        }
        
        .nav-link {
            font-size: 0.8rem;
            padding: 8px 15px;
        }
    }
</style>

<script>
$(document).ready(function() {
    // โหลดจำนวนออเดอร์ที่รอดำเนินการ
    loadPendingOrderCount();
    
    // อัปเดตสถานะระบบ
    checkSystemStatus();
    
    // รีเฟรชข้อมูลทุก 30 วินาที
    setInterval(function() {
        loadPendingOrderCount();
        checkSystemStatus();
    }, 30000);
});

function loadPendingOrderCount() {
    $.get(SITE_URL + '/api/orders.php?action=pending_count', function(response) {
        if (response.success) {
            const count = response.count;
            const badge = $('#pendingOrderCount');
            
            if (count > 0) {
                badge.text(count).show();
                badge.removeClass('bg-secondary').addClass('bg-danger');
            } else {
                badge.text('0').removeClass('bg-danger').addClass('bg-secondary');
            }
        }
    }).fail(function() {
        $('#pendingOrderCount').text('!').removeClass('bg-secondary bg-danger').addClass('bg-warning');
    });
}

function checkSystemStatus() {
    // ตรวจสอบสถานะฐานข้อมูล
    $.get(SITE_URL + '/api/system_status.php?check=database', function(response) {
        const badge = $('#dbStatus');
        if (response.success && response.status === 'connected') {
            badge.removeClass('bg-danger bg-warning').addClass('bg-success')
                 .html('<i class="fas fa-check"></i>')
                 .attr('title', 'เชื่อมต่อปกติ');
        } else {
            badge.removeClass('bg-success bg-warning').addClass('bg-danger')
                 .html('<i class="fas fa-times"></i>')
                 .attr('title', 'เชื่อมต่อไม่ได้');
        }
    }).fail(function() {
        $('#dbStatus').removeClass('bg-success bg-warning').addClass('bg-danger')
                     .html('<i class="fas fa-times"></i>')
                     .attr('title', 'เชื่อมต่อไม่ได้');
    });
    
    // ตรวจสอบสถานะ LINE OA
    $.get(SITE_URL + '/api/system_status.php?check=line', function(response) {
        const badge = $('#lineStatus');
        if (response.success && response.status === 'active') {
            badge.removeClass('bg-danger bg-secondary').addClass('bg-success')
                 .html('<i class="fas fa-check"></i>')
                 .attr('title', 'LINE OA เชื่อมต่อแล้ว');
        } else if (response.status === 'configured') {
            badge.removeClass('bg-danger bg-success').addClass('bg-warning')
                 .html('<i class="fas fa-exclamation"></i>')
                 .attr('title', 'ตั้งค่าแล้ว แต่ยังไม่ได้ทดสอบ');
        } else {
            badge.removeClass('bg-success bg-warning').addClass('bg-secondary')
                 .html('<i class="fas fa-times"></i>')
                 .attr('title', 'ยังไม่ได้ตั้งค่า');
        }
    }).fail(function() {
        $('#lineStatus').removeClass('bg-success bg-warning').addClass('bg-secondary')
                       .html('<i class="fas fa-question"></i>')
                       .attr('title', 'ไม่สามารถตรวจสอบได้');
    });
    
    // ตรวจสอบสถานะเครื่องพิมพ์
    $.get(SITE_URL + '/api/system_status.php?check=printer', function(response) {
        const badge = $('#printerStatus');
        if (response.success && response.status === 'online') {
            badge.removeClass('bg-danger bg-warning').addClass('bg-success')
                 .html('<i class="fas fa-check"></i>')
                 .attr('title', 'เครื่องพิมพ์พร้อมใช้งาน');
        } else if (response.status === 'configured') {
            badge.removeClass('bg-danger bg-success').addClass('bg-warning')
                 .html('<i class="fas fa-exclamation"></i>')
                 .attr('title', 'ตั้งค่าแล้ว แต่ไม่สามารถเชื่อมต่อได้');
        } else {
            badge.removeClass('bg-success bg-warning').addClass('bg-secondary')
                 .html('<i class="fas fa-times"></i>')
                 .attr('title', 'ยังไม่ได้ตั้งค่า');
        }
    }).fail(function() {
        $('#printerStatus').removeClass('bg-success bg-warning').addClass('bg-secondary')
                          .html('<i class="fas fa-question"></i>')
                          .attr('title', 'ไม่สามารถตรวจสอบได้');
    });
}
</script>