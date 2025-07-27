<?php

/**
 * Footer ส่วนล่าง
 * Smart Order Management System
 */
?>
<!-- Footer -->
<footer class="bg-light mt-5 py-4">
    <div class="container">
        <div class="row">
            <div class="col-md-6">
                <h5><?php echo SITE_NAME; ?></h5>
                <p class="text-muted mb-0">
                    <?php echo SITE_DESCRIPTION; ?>
                </p>
                <small class="text-muted">
                    Version <?php echo VERSION; ?> |
                    © <?php echo date('Y'); ?> <?php echo AUTHOR; ?>
                </small>
            </div>
            <div class="col-md-6 text-md-end">
                <div class="mb-2">
                    <a href="<?php echo SITE_URL; ?>/customer/" class="text-decoration-none me-3">
                        <i class="fas fa-shopping-cart me-1"></i>สั่งอาหาร
                    </a>
                    <a href="<?php echo SITE_URL; ?>/customer/queue_status.php" class="text-decoration-none me-3">
                        <i class="fas fa-clock me-1"></i>ตรวจสอบคิว
                    </a>
                    <a href="<?php echo SITE_URL; ?>/about.php" class="text-decoration-none">
                        <i class="fas fa-info-circle me-1"></i>เกี่ยวกับเรา
                    </a>
                </div>
                <div class="social-links">
                    <a href="#" class="text-muted me-3" title="Facebook">
                        <i class="fab fa-facebook-square fa-lg"></i>
                    </a>
                    <a href="#" class="text-muted me-3" title="LINE">
                        <i class="fab fa-line fa-lg"></i>
                    </a>
                    <a href="#" class="text-muted" title="Instagram">
                        <i class="fab fa-instagram fa-lg"></i>
                    </a>
                </div>
            </div>
        </div>

        <?php if (defined('DEBUG_MODE') && DEBUG_MODE && isLoggedIn() && getCurrentUserRole() === 'admin'): ?>
            <hr class="my-3">
            <div class="row">
                <div class="col-12">
                    <small class="text-muted">
                        <strong>Debug Info:</strong>
                        Page Load: <?php echo number_format((microtime(true) - $_SERVER['REQUEST_TIME_FLOAT']) * 1000, 2); ?>ms |
                        Memory: <?php echo number_format(memory_get_peak_usage() / 1024 / 1024, 2); ?>MB |
                        <?php if (isset($db) && method_exists($db, 'getQueryCount')): ?>
                            Queries: <?php echo $db->getQueryCount(); ?> |
                        <?php endif; ?>
                        PHP: <?php echo PHP_VERSION; ?>
                    </small>
                </div>
            </div>
        <?php endif; ?>
    </div>
</footer>

<!-- jQuery -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- DataTables JS -->
<script src="https://cdn.datatables.net/1.13.7/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.7/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<!-- Sweet Alert 2 -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>

<!-- Chart.js (สำหรับกราฟ) -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<!-- Custom JavaScript -->
<script src="<?php echo SITE_URL; ?>/assets/js/custom.js"></script>

<?php if (isset($additionalJS) && is_array($additionalJS)): ?>
    <?php foreach ($additionalJS as $js): ?>
        <script src="<?php echo $js; ?>"></script>
    <?php endforeach; ?>
<?php endif; ?>

<script>
    // Global JavaScript Variables
    const SITE_URL = '<?php echo SITE_URL; ?>';
    const CSRF_TOKEN = $('meta[name="csrf-token"]').attr('content');
    const USER_ROLE = '<?php echo isLoggedIn() ? getCurrentUserRole() : 'guest'; ?>';
    const IS_LOGGED_IN = <?php echo isLoggedIn() ? 'true' : 'false'; ?>;

    // Global jQuery Settings
    $.ajaxSetup({
        headers: {
            'X-CSRF-TOKEN': CSRF_TOKEN,
            'X-Requested-With': 'XMLHttpRequest'
        },
        beforeSend: function() {
            showLoading();
        },
        complete: function() {
            hideLoading();
        },
        error: function(xhr, status, error) {
            hideLoading();
            // ไม่แสดง error สำหรับ silent requests
            if (!this.silent) {
                handleAjaxError(xhr, status, error);
            }
        }
    });

    // Loading Functions
    function showLoading() {
        $('#loadingOverlay').show();
    }

    function hideLoading() {
        $('#loadingOverlay').hide();
    }

    // Error Handling
    function handleAjaxError(xhr, status, error) {
        let message = 'เกิดข้อผิดพลาด กรุณาลองใหม่อีกครั้ง';

        if (xhr.status === 401) {
            message = 'กรุณาเข้าสู่ระบบใหม่';
            setTimeout(() => {
                window.location.href = SITE_URL + '/login.php';
            }, 2000);
        } else if (xhr.status === 403) {
            message = 'ไม่มีสิทธิ์เข้าถึง';
        } else if (xhr.status === 422) {
            try {
                const response = JSON.parse(xhr.responseText);
                if (response.errors) {
                    message = Object.values(response.errors).flat().join('<br>');
                }
            } catch (e) {
                message = 'ข้อมูลไม่ถูกต้อง';
            }
        } else if (xhr.status >= 500) {
            message = 'เกิดข้อผิดพลาดภายในเซิร์ฟเวอร์';
        }

        Swal.fire({
            icon: 'error',
            title: 'เกิดข้อผิดพลาด',
            html: message,
            confirmButtonColor: '#ef4444'
        });
    }

    // Success Message
    function showSuccess(message, callback = null) {
        Swal.fire({
            icon: 'success',
            title: 'สำเร็จ',
            text: message,
            confirmButtonColor: '#10b981',
            timer: 3000
        }).then(() => {
            if (callback) callback();
        });
    }

    // Confirmation Dialog
    function confirmAction(message, callback) {
        Swal.fire({
            title: 'ยืนยันการดำเนินการ',
            text: message,
            icon: 'question',
            showCancelButton: true,
            confirmButtonColor: '#ef4444',
            cancelButtonColor: '#6b7280',
            confirmButtonText: 'ยืนยัน',
            cancelButtonText: 'ยกเลิก'
        }).then((result) => {
            if (result.isConfirmed && callback) {
                callback();
            }
        });
    }

    // DataTable Default Settings
    if (typeof $.fn.DataTable !== 'undefined') {
        $.extend(true, $.fn.dataTable.defaults, {
            language: {
                url: 'https://cdn.datatables.net/plug-ins/1.13.7/i18n/th.json'
            },
            responsive: true,
            pageLength: 25,
            order: [
                [0, 'desc']
            ],
            dom: '<"row"<"col-sm-12 col-md-6"l><"col-sm-12 col-md-6"f>>' +
                '<"row"<"col-sm-12"tr>>' +
                '<"row"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
            drawCallback: function() {
                // Re-initialize tooltips after table redraw
                $('[data-bs-toggle="tooltip"]').tooltip();
            }
        });
    }

    // Initialize Tooltips
    $(document).ready(function() {
        // Bootstrap Tooltips
        if (typeof bootstrap !== 'undefined') {
            $('[data-bs-toggle="tooltip"]').tooltip();
        }

        // Auto-dismiss alerts
        $('.alert').each(function() {
            const alert = $(this);
            if (!alert.find('.btn-close').length) {
                setTimeout(() => {
                    alert.fadeOut();
                }, 5000);
            }
        });

        // Smooth scrolling for anchor links
        $('a[href^="#"]').on('click', function(e) {
            const target = $(this.getAttribute('href'));
            if (target.length) {
                e.preventDefault();
                $('html, body').stop().animate({
                    scrollTop: target.offset().top - 100
                }, 800);
            }
        });

        // Auto-refresh notifications (if logged in)
        if (IS_LOGGED_IN) {
            loadNotifications();
            setInterval(loadNotifications, 30000); // ทุก 30 วินาที
        }
    });

    // Load Notifications
    function loadNotifications() {
        $.get(SITE_URL + '/api/notifications.php', function(response) {
            if (response.success) {
                updateNotificationUI(response.notifications);
            }
        }).fail(function() {
            // Silent fail for notifications
        });
    }

    // Update Notification UI
    function updateNotificationUI(notifications) {
        const unreadCount = notifications.filter(n => !n.is_read).length;
        const badge = $('#notificationCount');
        const list = $('#notificationsList');

        if (unreadCount > 0) {
            badge.text(unreadCount).show();
        } else {
            badge.hide();
        }

        list.empty();

        if (notifications.length === 0) {
            list.append('<li><span class="dropdown-item-text">ไม่มีการแจ้งเตือน</span></li>');
            return;
        }

        notifications.slice(0, 5).forEach(notification => {
            const item = $(`
                    <li>
                        <a class="dropdown-item ${!notification.is_read ? 'fw-bold' : ''}" 
                           href="#" 
                           data-notification-id="${notification.notification_id}">
                            <div class="d-flex align-items-start">
                                <div class="flex-grow-1">
                                    <div class="fw-semibold">${notification.title}</div>
                                    <small class="text-muted">${notification.message}</small>
                                    <br><small class="text-muted">${formatDate(notification.created_at)}</small>
                                </div>
                                ${!notification.is_read ? '<span class="badge bg-primary rounded-pill">ใหม่</span>' : ''}
                            </div>
                        </a>
                    </li>
                `);
            list.append(item);
        });

        if (notifications.length > 5) {
            list.append('<li><hr class="dropdown-divider"></li>');
            list.append('<li><a class="dropdown-item text-center" href="' + SITE_URL + '/notifications.php">ดูทั้งหมด</a></li>');
        }
    }

    // Format Date for JavaScript
    function formatDate(dateString) {
        const date = new Date(dateString);
        const now = new Date();
        const diff = now - date;
        const minutes = Math.floor(diff / 60000);
        const hours = Math.floor(minutes / 60);
        const days = Math.floor(hours / 24);

        if (minutes < 1) return 'เมื่อสักครู่';
        if (minutes < 60) return `${minutes} นาทีที่แล้ว`;
        if (hours < 24) return `${hours} ชั่วโมงที่แล้ว`;
        if (days < 7) return `${days} วันที่แล้ว`;

        return date.toLocaleDateString('th-TH', {
            year: 'numeric',
            month: 'short',
            day: 'numeric'
        });
    }

    // Number formatting
    function formatNumber(number, decimals = 2) {
        return new Intl.NumberFormat('th-TH', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals
        }).format(number);
    }

    // Currency formatting
    function formatCurrency(amount) {
        return new Intl.NumberFormat('th-TH', {
            style: 'currency',
            currency: 'THB'
        }).format(amount);
    }

    // Copy to clipboard
    function copyToClipboard(text) {
        navigator.clipboard.writeText(text).then(() => {
            showSuccess('คัดลอกแล้ว');
        }).catch(() => {
            // Fallback for older browsers
            const textArea = document.createElement('textarea');
            textArea.value = text;
            document.body.appendChild(textArea);
            textArea.select();
            document.execCommand('copy');
            document.body.removeChild(textArea);
            showSuccess('คัดลอกแล้ว');
        });
    }

    // Print function
    function printElement(elementId) {
        const printContent = document.getElementById(elementId);
        const originalContent = document.body.innerHTML;
        document.body.innerHTML = printContent.innerHTML;
        window.print();
        document.body.innerHTML = originalContent;
        location.reload();
    }
</script>

<?php if (isset($inlineJS)): ?>
    <script>
        <?php echo $inlineJS; ?>
    </script>
<?php endif; ?>

<!-- Voice System (if enabled) -->
<?php if (defined('VOICE_ENABLED') && VOICE_ENABLED): ?>
    <script src="<?php echo SITE_URL; ?>/assets/js/voice.js"></script>
<?php endif; ?>

<!-- AI Chatbot (if enabled) -->
<?php if (defined('AI_ENABLED') && AI_ENABLED): ?>
    <script src="<?php echo SITE_URL; ?>/assets/js/chatbot.js"></script>
<?php endif; ?>

</body>

</html>