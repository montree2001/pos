<?php
/**
 * Header ส่วนบน
 * Smart Order Management System
 */

// ป้องกันการเข้าถึงโดยตรง
if (!defined('SYSTEM_INIT')) {
    define('SYSTEM_INIT', true);
    require_once dirname(__DIR__) . '/config/config.php';
    require_once dirname(__DIR__) . '/config/session.php';
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?php echo isset($pageDescription) ? clean($pageDescription) : SITE_DESCRIPTION; ?>">
    <meta name="author" content="<?php echo AUTHOR; ?>">
    <meta name="robots" content="index, follow">
    
    <title><?php echo isset($pageTitle) ? clean($pageTitle) . ' - ' . SITE_NAME : SITE_NAME; ?></title>
    
    <!-- Favicon -->
    <link rel="icon" type="image/x-icon" href="<?php echo SITE_URL; ?>/assets/images/favicon.ico">
    
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    
    <!-- DataTables CSS -->
    <link href="https://cdn.datatables.net/1.13.7/css/dataTables.bootstrap5.min.css" rel="stylesheet">
    <link href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css" rel="stylesheet">
    
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    
    <!-- Sweet Alert 2 -->
    <link href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css" rel="stylesheet">
    
    <!-- Custom CSS -->
    <link href="<?php echo SITE_URL; ?>/assets/css/custom.css" rel="stylesheet">
    
    <?php if (isset($additionalCSS) && is_array($additionalCSS)): ?>
        <?php foreach ($additionalCSS as $css): ?>
            <link href="<?php echo $css; ?>" rel="stylesheet">
        <?php endforeach; ?>
    <?php endif; ?>
    
    <style>
        :root {
            --primary-color: #4f46e5;
            --primary-dark: #3730a3;
            --secondary-color: #6b7280;
            --success-color: #10b981;
            --warning-color: #f59e0b;
            --danger-color: #ef4444;
            --info-color: #3b82f6;
            --light-bg: #f8fafc;
            --white: #ffffff;
            --border-color: #e5e7eb;
            --text-color: #1f2937;
            --text-muted: #6b7280;
            --box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            --border-radius: 12px;
            --transition: all 0.3s ease;
        }
        
        * {
            box-sizing: border-box;
        }
        
        body {
            background-color: var(--light-bg);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            line-height: 1.6;
        }
        
        /* Navbar Styles */
        .navbar {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            box-shadow: var(--box-shadow);
        }
        
        .navbar-brand {
            font-weight: 700;
            font-size: 1.25rem;
        }
        
        .navbar-nav .nav-link {
            font-weight: 500;
            color: rgba(255, 255, 255, 0.9) !important;
            transition: var(--transition);
        }
        
        .navbar-nav .nav-link:hover {
            color: white !important;
            transform: translateY(-1px);
        }
        
        /* Sidebar Styles */
        .sidebar {
            background: var(--white);
            box-shadow: 2px 0 10px rgba(0,0,0,0.1);
            min-height: calc(100vh - 76px);
            position: sticky;
            top: 76px;
        }
        
        .sidebar .nav-link {
            color: var(--secondary-color);
            padding: 12px 20px;
            border-radius: 8px;
            margin: 4px 10px;
            transition: var(--transition);
            font-weight: 500;
        }
        
        .sidebar .nav-link:hover,
        .sidebar .nav-link.active {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            transform: translateX(5px);
        }
        
        .sidebar .nav-link i {
            width: 20px;
            text-align: center;
        }
        
        /* Main Content */
        .main-content {
            background: var(--white);
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            margin: 20px;
            padding: 30px;
            min-height: calc(100vh - 152px);
        }
        
        /* Cards */
        .card {
            border: none;
            border-radius: var(--border-radius);
            box-shadow: var(--box-shadow);
            transition: var(--transition);
            overflow: hidden;
        }
        
        .card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
        }
        
        .card-header {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            border-bottom: none;
            padding: 15px 20px;
            font-weight: 600;
        }
        
        /* Buttons */
        .btn {
            border-radius: 8px;
            padding: 10px 20px;
            font-weight: 500;
            transition: var(--transition);
            border: none;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
        }
        
        .btn-primary:hover {
            background: linear-gradient(135deg, var(--primary-dark), #4338ca);
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(79, 70, 229, 0.3);
        }
        
        .btn-success {
            background: linear-gradient(135deg, var(--success-color), #059669);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
        }
        
        .btn-danger {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
        }
        
        .btn-info {
            background: linear-gradient(135deg, var(--info-color), #2563eb);
        }
        
        /* Tables */
        .table {
            border-radius: 8px;
            overflow: hidden;
            box-shadow: var(--box-shadow);
        }
        
        .table thead th {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            border: none;
            font-weight: 600;
            padding: 15px;
        }
        
        .table tbody tr {
            transition: var(--transition);
        }
        
        .table tbody tr:hover {
            background-color: #f8fafc;
        }
        
        /* Forms */
        .form-control, .form-select, .form-check-input {
            border-radius: 8px;
            border: 2px solid var(--border-color);
            padding: 12px;
            transition: var(--transition);
        }
        
        .form-control:focus, .form-select:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
        }
        
        .form-label {
            font-weight: 600;
            color: var(--text-color);
            margin-bottom: 8px;
        }
        
        /* Badges */
        .badge {
            border-radius: 20px;
            padding: 8px 12px;
            font-weight: 500;
        }
        
        /* Stats Cards */
        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: var(--border-radius);
            padding: 20px;
            transition: var(--transition);
        }
        
        .stats-card:hover {
            transform: translateY(-2px);
        }
        
        .stats-card.success {
            background: linear-gradient(135deg, var(--success-color), #059669);
        }
        
        .stats-card.warning {
            background: linear-gradient(135deg, var(--warning-color), #d97706);
        }
        
        .stats-card.danger {
            background: linear-gradient(135deg, var(--danger-color), #dc2626);
        }
        
        .stats-card.info {
            background: linear-gradient(135deg, var(--info-color), #2563eb);
        }
        
        .stats-card .stats-icon {
            font-size: 2.5rem;
            opacity: 0.8;
        }
        
        .stats-card .stats-number {
            font-size: 2rem;
            font-weight: 700;
            margin: 10px 0;
        }
        
        .stats-card .stats-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
        
        /* Loading Spinner */
        .loading-spinner {
            display: inline-block;
            width: 20px;
            height: 20px;
            border: 3px solid rgba(255,255,255,.3);
            border-radius: 50%;
            border-top-color: #fff;
            animation: spin 1s ease-in-out infinite;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Alert Styles */
        .alert {
            border-radius: 8px;
            border: none;
            box-shadow: var(--box-shadow);
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .main-content {
                margin: 10px;
                padding: 20px;
            }
            
            .sidebar {
                position: static;
                min-height: auto;
            }
            
            .table-responsive {
                font-size: 0.875rem;
            }
            
            .btn {
                padding: 8px 16px;
                font-size: 0.875rem;
            }
            
            .stats-card {
                margin-bottom: 15px;
            }
        }
        
        /* Print Styles */
        @media print {
            .navbar, .sidebar, .btn, .alert {
                display: none !important;
            }
            
            .main-content {
                margin: 0;
                padding: 0;
                box-shadow: none;
            }
        }
        
        /* Dark Mode Support */
        @media (prefers-color-scheme: dark) {
            :root {
                --light-bg: #1f2937;
                --white: #374151;
                --text-color: #f9fafb;
                --text-muted: #d1d5db;
                --border-color: #4b5563;
            }
        }
    </style>
    
    <?php if (isset($inlineCSS)): ?>
        <style><?php echo $inlineCSS; ?></style>
    <?php endif; ?>
</head>
<body>
    
    <!-- Top Navigation -->
    <nav class="navbar navbar-expand-lg navbar-dark sticky-top">
        <div class="container-fluid">
            <a class="navbar-brand" href="<?php echo SITE_URL; ?>">
                <i class="fas fa-store me-2"></i>
                <?php echo SITE_NAME; ?>
            </a>
            
            <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarNav">
                <span class="navbar-toggler-icon"></span>
            </button>
            
            <div class="collapse navbar-collapse" id="navbarNav">
                <?php if (isLoggedIn()): ?>
                    <ul class="navbar-nav ms-auto">
                        <?php if (getCurrentUserRole() === 'admin'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo SITE_URL; ?>/admin/">
                                    <i class="fas fa-tachometer-alt me-1"></i>
                                    Admin
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php if (getCurrentUserRole() === 'staff'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo SITE_URL; ?>/pos/">
                                    <i class="fas fa-cash-register me-1"></i>
                                    POS
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <?php if (getCurrentUserRole() === 'kitchen'): ?>
                            <li class="nav-item">
                                <a class="nav-link" href="<?php echo SITE_URL; ?>/kitchen/">
                                    <i class="fas fa-utensils me-1"></i>
                                    ครัว
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Notifications -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="notificationsDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-bell"></i>
                                <span class="badge bg-danger" id="notificationCount" style="display: none;">0</span>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end" id="notificationsList">
                                <li><span class="dropdown-item-text">ไม่มีการแจ้งเตือน</span></li>
                            </ul>
                        </li>
                        
                        <!-- User Menu -->
                        <li class="nav-item dropdown">
                            <a class="nav-link dropdown-toggle" href="#" id="userDropdown" role="button" data-bs-toggle="dropdown">
                                <i class="fas fa-user-circle me-2"></i>
                                <?php echo clean(getCurrentUser()['fullname']); ?>
                            </a>
                            <ul class="dropdown-menu dropdown-menu-end">
                                <li>
                                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>/profile.php">
                                        <i class="fas fa-user me-2"></i>โปรไฟล์
                                    </a>
                                </li>
                                <li>
                                    <a class="dropdown-item" href="<?php echo SITE_URL; ?>/settings.php">
                                        <i class="fas fa-cog me-2"></i>ตั้งค่า
                                    </a>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-danger" href="<?php echo SITE_URL; ?>/logout.php">
                                        <i class="fas fa-sign-out-alt me-2"></i>ออกจากระบบ
                                    </a>
                                </li>
                            </ul>
                        </li>
                    </ul>
                <?php else: ?>
                    <ul class="navbar-nav ms-auto">
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/customer/">
                                <i class="fas fa-shopping-cart me-1"></i>
                                สั่งอาหาร
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/customer/queue_status.php">
                                <i class="fas fa-clock me-1"></i>
                                ตรวจสอบคิว
                            </a>
                        </li>
                        <li class="nav-item">
                            <a class="nav-link" href="<?php echo SITE_URL; ?>/login.php">
                                <i class="fas fa-sign-in-alt me-1"></i>
                                เข้าสู่ระบบ
                            </a>
                        </li>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </nav>
    
    <!-- Flash Messages -->
    <?php if (hasFlashMessage()): ?>
        <div class="container-fluid mt-3">
            <?php 
            $flashMessages = getFlashMessage();
            foreach ($flashMessages as $type => $message):
                $alertClass = '';
                switch ($type) {
                    case 'success': $alertClass = 'alert-success'; break;
                    case 'error': $alertClass = 'alert-danger'; break;
                    case 'warning': $alertClass = 'alert-warning'; break;
                    case 'info': $alertClass = 'alert-info'; break;
                    default: $alertClass = 'alert-info';
                }
            ?>
                <div class="alert <?php echo $alertClass; ?> alert-dismissible fade show" role="alert">
                    <?php echo clean($message); ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
    
    <!-- CSRF Token for AJAX -->
    <meta name="csrf-token" content="<?php echo generateCSRFToken(); ?>">
    
    <!-- Loading Overlay -->
    <div id="loadingOverlay" style="display: none; position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); z-index: 9999;">
        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); color: white; text-align: center;">
            <div class="loading-spinner"></div>
            <div class="mt-2">กำลังโหลด...</div>
        </div>
    </div>