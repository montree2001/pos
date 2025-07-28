/**
 * Kitchen System JavaScript
 * Smart Order Management System
 * File: assets/js/kitchen.js
 */

class KitchenSystem {
    constructor() {
        this.apiUrl = SITE_URL + '/api/kitchen.php';
        this.refreshInterval = 30000; // 30 seconds
        this.timeInterval = 1000; // 1 second
        this.orders = new Map();
        this.stats = {};
        this.refreshTimer = null;
        this.timeTimer = null;
        this.isLoading = false;
        this.isPaused = false;
        this.sounds = {
            newOrder: this.createAudioContext(),
            orderReady: this.createAudioContext()
        };
        
        this.init();
    }
    
    init() {
        console.log('🔥 Kitchen System Initializing...');
        
        // Check if we're on a kitchen page
        if (!this.isKitchenPage()) {
            console.log('Not a kitchen page, skipping initialization');
            return;
        }
        
        // Bind events
        this.bindEvents();
        
        // Start timers
        this.startTimers();
        
        // Initial load
        this.loadActiveOrders();
        this.loadKitchenStats();
        
        // Setup visibility change handler
        this.setupVisibilityHandler();
        
        // Setup connection monitoring
        this.setupConnectionMonitoring();
        
        console.log('✅ Kitchen System Ready');
    }
    
    isKitchenPage() {
        return document.querySelector('.kitchen-orders-grid') || 
               document.querySelector('#ordersGrid') ||
               document.querySelector('.kitchen-container');
    }
    
    createAudioContext() {
        try {
            return new (window.AudioContext || window.webkitAudioContext)();
        } catch (e) {
            console.warn('Audio context not supported');
            return null;
        }
    }
    
    bindEvents() {
        // Page refresh button
        document.addEventListener('click', (e) => {
            if (e.target.matches('[data-action="refresh"]') || 
                e.target.closest('[data-action="refresh"]')) {
                e.preventDefault();
                this.forceRefresh();
            }
        });
        
        // Order action buttons
        document.addEventListener('click', (e) => {
            const button = e.target.closest('[data-action]');
            if (!button) return;
            
            e.preventDefault();
            
            const action = button.dataset.action;
            const orderId = parseInt(button.dataset.orderId);
            const itemId = parseInt(button.dataset.itemId);
            
            // Prevent double clicks
            if (button.disabled) return;
            
            switch (action) {
                case 'update-order-status':
                    const newStatus = button.dataset.status;
                    this.updateOrderStatus(orderId, newStatus, button);
                    break;
                    
                case 'update-item-status':
                    const itemStatus = button.dataset.status;
                    this.updateItemStatus(itemId, itemStatus, button);
                    break;
                    
                case 'view-details':
                    this.viewOrderDetails(orderId);
                    break;
                    
                case 'call-queue':
                    this.callQueue(orderId, button);
                    break;
            }
        });
        
        // Keyboard shortcuts
        document.addEventListener('keydown', (e) => {
            // Don't handle shortcuts when typing in inputs
            if (e.target.matches('input, textarea, select')) return;
            
            switch (e.key) {
                case 'F5':
                    e.preventDefault();
                    this.forceRefresh();
                    break;
                    
                case 'Escape':
                    this.closeModals();
                    break;
                    
                case ' ':
                    e.preventDefault();
                    this.toggleAutoRefresh();
                    break;
                    
                case 'r':
                case 'R':
                    if (e.ctrlKey || e.metaKey) {
                        e.preventDefault();
                        this.forceRefresh();
                    }
                    break;
            }
        });
        
        // Handle modal events
        document.addEventListener('show.bs.modal', (e) => {
            console.log('Modal showing:', e.target.id);
        });
        
        document.addEventListener('hidden.bs.modal', (e) => {
            console.log('Modal hidden:', e.target.id);
        });
    }
    
    setupVisibilityHandler() {
        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                this.pauseTimers();
                console.log('🛌 Kitchen system paused (tab hidden)');
            } else {
                this.resumeTimers();
                this.forceRefresh(); // Refresh when coming back
                console.log('🔥 Kitchen system resumed (tab visible)');
            }
        });
    }
    
    setupConnectionMonitoring() {
        window.addEventListener('online', () => {
            this.showNotification('success', '🌐 เชื่อมต่ออินเทอร์เน็ตแล้ว');
            this.forceRefresh();
        });
        
        window.addEventListener('offline', () => {
            this.showNotification('warning', '📶 ไม่มีการเชื่อมต่ออินเทอร์เน็ต');
        });
    }
    
    startTimers() {
        if (this.isPaused) return;
        
        // Auto refresh orders
        this.refreshTimer = setInterval(() => {
            if (!this.isLoading && !this.isPaused) {
                this.loadActiveOrders();
            }
        }, this.refreshInterval);
        
        // Update current time and order timers
        this.timeTimer = setInterval(() => {
            this.updateCurrentTime();
            this.updateOrderTimers();
        }, this.timeInterval);
        
        // Initial time update
        this.updateCurrentTime();
        
        console.log('⏰ Timers started');
    }
    
    pauseTimers() {
        if (this.refreshTimer) {
            clearInterval(this.refreshTimer);
            this.refreshTimer = null;
        }
        if (this.timeTimer) {
            clearInterval(this.timeTimer);
            this.timeTimer = null;
        }
        this.isPaused = true;
    }
    
    resumeTimers() {
        this.isPaused = false;
        this.startTimers();
    }
    
    updateCurrentTime() {
        const now = new Date();
        const timeElement = document.getElementById('currentTime');
        if (timeElement) {
            timeElement.textContent = now.toLocaleTimeString('th-TH', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
        }
    }
    
    updateOrderTimers() {
        document.querySelectorAll('[data-order-time]').forEach(element => {
            const orderTime = new Date(element.dataset.orderTime);
            const now = new Date();
            const diff = Math.floor((now - orderTime) / 1000 / 60); // minutes
            
            element.textContent = diff + ' นาที';
            
            // Add urgent class if over 20 minutes
            const card = element.closest('.kitchen-order-card');
            if (card) {
                if (diff > 20) {
                    card.classList.add('urgent');
                    element.classList.add('urgent');
                } else {
                    card.classList.remove('urgent');
                    element.classList.remove('urgent');
                }
            }
        });
    }
    
    async loadActiveOrders() {
        if (this.isLoading) return;
        
        try {
            this.isLoading = true;
            this.showLoading(true);
            
            const response = await this.fetchWithRetry(`${this.apiUrl}?action=get_active_orders`);
            const data = await response.json();
            
            if (!data.success) {
                throw new Error(data.message || 'Failed to load orders');
            }
            
            // Check for new orders (for sound notification)
            const newOrderIds = new Set(data.orders.map(o => o.order_id));
            const oldOrderIds = new Set(this.orders.keys());
            const hasNewOrders = [...newOrderIds].some(id => !oldOrderIds.has(id));
            
            if (hasNewOrders && this.orders.size > 0) {
                this.playSound('newOrder');
                this.showNotification('info', '🔔 มีออเดอร์ใหม่!');
            }
            
            // Update orders
            this.orders.clear();
            data.orders.forEach(order => {
                this.orders.set(order.order_id, order);
            });
            
            this.renderOrders();
            this.updateLastRefresh();
            
        } catch (error) {
            console.error('Error loading orders:', error);
            this.showNotification('error', '❌ ไม่สามารถโหลดข้อมูลออเดอร์ได้');
            this.handleNetworkError(error);
        } finally {
            this.isLoading = false;
            this.showLoading(false);
        }
    }
    
    async loadKitchenStats() {
        try {
            const response = await this.fetchWithRetry(`${this.apiUrl}?action=get_kitchen_stats`);
            const data = await response.json();
            
            if (data.success) {
                this.stats = data.stats || {};
                this.renderStats();
            }
        } catch (error) {
            console.error('Error loading stats:', error);
        }
    }
    
    async fetchWithRetry(url, options = {}, retries = 3) {
        for (let i = 0; i < retries; i++) {
            try {
                const response = await fetch(url, options);
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}: ${response.statusText}`);
                }
                return response;
            } catch (error) {
                if (i === retries - 1) throw error;
                await this.delay(1000 * (i + 1)); // Exponential backoff
            }
        }
    }
    
    delay(ms) {
        return new Promise(resolve => setTimeout(resolve, ms));
    }
    
    handleNetworkError(error) {
        if (error.message.includes('Failed to fetch')) {
            this.showNotification('warning', '🌐 ตรวจสอบการเชื่อมต่ออินเทอร์เน็ต');
        } else if (error.message.includes('401')) {
            window.location.href = SITE_URL + '/kitchen/login.php';
        }
    }
    
    renderOrders() {
        const container = document.getElementById('ordersGrid');
        if (!container) return;
        
        if (this.orders.size === 0) {
            container.innerHTML = this.getEmptyStateHTML();
            return;
        }
        
        const ordersHTML = Array.from(this.orders.values())
            .sort((a, b) => new Date(a.created_at) - new Date(b.created_at))
            .map(order => this.getOrderCardHTML(order))
            .join('');
            
        container.innerHTML = ordersHTML;
        
        // Load order details for each order
        this.orders.forEach(order => {
            this.loadOrderItems(order.order_id);
        });
        
        // Update stats count
        this.updateActiveOrdersCount();
    }
    
    renderStats() {
        const statsElements = {
            'total-orders': this.stats.total_orders || 0,
            'preparing-orders': this.stats.preparing_orders || this.getOrdersCountByStatus('preparing'),
            'completed-orders': this.stats.completed_orders || 0,
            'active-orders': this.orders.size
        };
        
        Object.entries(statsElements).forEach(([id, value]) => {
            const element = document.getElementById(id);
            if (element) {
                this.animateNumber(element, value);
            }
        });
    }
    
    getOrdersCountByStatus(status) {
        return Array.from(this.orders.values())
               .filter(order => order.status === status).length;
    }
    
    updateActiveOrdersCount() {
        const element = document.getElementById('active-orders');
        if (element) {
            this.animateNumber(element, this.orders.size);
        }
    }
    
    animateNumber(element, targetValue) {
        const currentValue = parseInt(element.textContent) || 0;
        if (currentValue === targetValue) return;
        
        const difference = targetValue - currentValue;
        const duration = 1000; // 1 second
        const steps = 20;
        const stepValue = difference / steps;
        const stepDuration = duration / steps;
        
        let currentStep = 0;
        const timer = setInterval(() => {
            currentStep++;
            const newValue = Math.round(currentValue + (stepValue * currentStep));
            element.textContent = newValue;
            
            if (currentStep >= steps) {
                clearInterval(timer);
                element.textContent = targetValue;
            }
        }, stepDuration);
    }
    
    getOrderCardHTML(order) {
        const minutesPassed = order.minutes_passed || 0;
        const urgentClass = order.is_urgent ? 'urgent' : '';
        const statusClass = `status-${order.status}`;
        const progress = Math.round(order.progress || 0);
        
        return `
            <div class="kitchen-order-card ${statusClass} ${urgentClass}" 
                 data-order-id="${order.order_id}"
                 data-status="${order.status}">
                <div class="kitchen-order-header">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <div class="kitchen-queue-number">
                                คิว ${order.queue_number || 'ORD-' + order.order_id}
                            </div>
                            <div class="kitchen-order-time">
                                <i class="fas fa-clock"></i>
                                ${this.formatTime(order.created_at)}
                                <span class="kitchen-time-badge ${urgentClass}" 
                                      data-order-time="${order.created_at}">
                                    ${minutesPassed} นาทีที่แล้ว
                                </span>
                            </div>
                        </div>
                        <div class="kitchen-order-status">
                            <span class="kitchen-status-badge ${order.status}">
                                <i class="fas fa-${this.getStatusIcon(order.status)}"></i>
                                ${this.getStatusText(order.status)}
                            </span>
                        </div>
                    </div>
                </div>
                
                <div class="kitchen-order-body">
                    ${order.customer_name ? `
                        <div class="kitchen-customer-info">
                            <i class="fas fa-user"></i>
                            <span>${order.customer_name}</span>
                            ${order.phone ? `
                                <span>
                                    <i class="fas fa-phone"></i> 
                                    <a href="tel:${order.phone}" class="text-decoration-none">${order.phone}</a>
                                </span>
                            ` : ''}
                        </div>
                    ` : ''}
                    
                    <div class="kitchen-progress-container">
                        <div class="kitchen-progress-bar">
                            <div class="kitchen-progress-fill" style="width: ${progress}%"></div>
                        </div>
                        <div class="kitchen-progress-text">
                            ความคืบหน้า: ${progress}% (${order.completed_items || 0}/${order.total_items || 0} รายการ)
                        </div>
                    </div>
                    
                    <div class="kitchen-order-items" id="orderItems${order.order_id}">
                        <div class="kitchen-loading">
                            <div class="kitchen-spinner"></div>
                            <div class="kitchen-loading-text">กำลังโหลดรายการ...</div>
                        </div>
                    </div>
                    
                    ${order.notes ? `
                        <div class="kitchen-order-notes">
                            <i class="fas fa-sticky-note"></i>
                            <strong>หมายเหตุ:</strong> ${this.escapeHtml(order.notes)}
                        </div>
                    ` : ''}
                </div>
                
                <div class="kitchen-order-footer">
                    <div class="kitchen-preparation-time">
                        <i class="fas fa-stopwatch"></i>
                        <span>${minutesPassed} นาที</span>
                        ${minutesPassed > 15 ? '<i class="fas fa-exclamation-triangle text-warning ms-1"></i>' : ''}
                    </div>
                    
                    <div class="kitchen-action-buttons">
                        ${this.getActionButtonsHTML(order)}
                        
                        <button class="kitchen-btn kitchen-btn-outline" 
                                data-action="view-details" 
                                data-order-id="${order.order_id}"
                                title="ดูรายละเอียด">
                            <i class="fas fa-eye"></i>
                        </button>
                        
                        <button class="kitchen-btn kitchen-btn-info" 
                                data-action="call-queue" 
                                data-order-id="${order.order_id}"
                                title="เรียกคิว">
                            <i class="fas fa-microphone"></i>
                        </button>
                    </div>
                </div>
            </div>
        `;
    }
    
    getActionButtonsHTML(order) {
        switch (order.status) {
            case 'confirmed':
                return `
                    <button class="kitchen-btn kitchen-btn-primary" 
                            data-action="update-order-status" 
                            data-order-id="${order.order_id}" 
                            data-status="preparing"
                            title="เริ่มเตรียมอาหาร">
                        <i class="fas fa-play"></i> รับออเดอร์
                    </button>
                `;
                
            case 'preparing':
                return `
                    <button class="kitchen-btn kitchen-btn-success" 
                            data-action="update-order-status" 
                            data-order-id="${order.order_id}" 
                            data-status="ready"
                            title="อาหารพร้อมเสิร์ฟ">
                        <i class="fas fa-check"></i> พร้อมเสิร์ฟ
                    </button>
                `;
                
            case 'ready':
                return `
                    <button class="kitchen-btn kitchen-btn-warning" 
                            data-action="update-order-status" 
                            data-order-id="${order.order_id}" 
                            data-status="completed"
                            title="ลูกค้ารับแล้ว">
                        <i class="fas fa-check-double"></i> ส่งแล้ว
                    </button>
                `;
                
            default:
                return '';
        }
    }
    
    async loadOrderItems(orderId) {
        try {
            const response = await this.fetchWithRetry(`${this.apiUrl}?action=get_order_details&order_id=${orderId}`);
            const data = await response.json();
            
            if (data.success && data.order.items) {
                this.renderOrderItems(orderId, data.order.items);
            }
        } catch (error) {
            console.error('Error loading order items:', error);
            const container = document.getElementById(`orderItems${orderId}`);
            if (container) {
                container.innerHTML = `
                    <div class="text-center text-muted">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div>ไม่สามารถโหลดรายการได้</div>
                    </div>
                `;
            }
        }
    }
    
    renderOrderItems(orderId, items) {
        const container = document.getElementById(`orderItems${orderId}`);
        if (!container) return;
        
        if (!items || items.length === 0) {
            container.innerHTML = '<div class="text-center text-muted">ไม่มีรายการ</div>';
            return;
        }
        
        const itemsHTML = items.map(item => `
            <div class="kitchen-order-item">
                <div class="kitchen-item-info">
                    <div class="kitchen-item-name">${this.escapeHtml(item.product_name)}</div>
                    ${item.notes ? `<div class="kitchen-item-notes">${this.escapeHtml(item.notes)}</div>` : ''}
                </div>
                <div class="kitchen-item-quantity">${item.quantity}</div>
                <div class="kitchen-item-status ${item.status}">
                    ${this.getStatusText(item.status)}
                </div>
            </div>
        `).join('');
        
        container.innerHTML = itemsHTML;
    }
    
    async updateOrderStatus(orderId, newStatus, button) {
        const statusTexts = {
            'preparing': 'เริ่มเตรียมออเดอร์นี้',
            'ready': 'ยืนยันว่าพร้อมเสิร์ฟ',
            'completed': 'ยืนยันว่าส่งแล้ว'
        };
        
        const confirmText = statusTexts[newStatus];
        if (!confirm(`${confirmText}?`)) return;
        
        // Disable button to prevent double clicks
        button.disabled = true;
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i> กำลังประมวลผล...';
        
        try {
            this.showLoading(true);
            
            const formData = new FormData();
            formData.append('action', 'update_order_status');
            formData.append('order_id', orderId);
            formData.append('status', newStatus);
            
            const response = await this.fetchWithRetry(this.apiUrl, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('success', '✅ อัปเดตสถานะสำเร็จ');
                
                // Play sound for ready status
                if (newStatus === 'ready') {
                    this.playSound('orderReady');
                }
                
                // Remove from active orders if completed or ready
                if (newStatus === 'ready' || newStatus === 'completed') {
                    this.orders.delete(orderId);
                    this.renderOrders();
                    this.loadKitchenStats();
                } else {
                    // Just reload orders
                    setTimeout(() => this.loadActiveOrders(), 1000);
                }
            } else {
                throw new Error(data.message || 'Failed to update status');
            }
            
        } catch (error) {
            console.error('Error updating order status:', error);
            this.showNotification('error', '❌ ไม่สามารถอัปเดตสถานะได้: ' + error.message);
        } finally {
            // Re-enable button
            button.disabled = false;
            button.innerHTML = originalHTML;
            this.showLoading(false);
        }
    }
    
    async updateItemStatus(itemId, newStatus, button) {
        // Similar implementation but for individual items
        button.disabled = true;
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        try {
            const formData = new FormData();
            formData.append('action', 'update_item_status');
            formData.append('item_id', itemId);
            formData.append('status', newStatus);
            
            const response = await this.fetchWithRetry(this.apiUrl, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('success', '✅ อัปเดตสถานะรายการสำเร็จ');
                
                if (data.order_ready) {
                    this.showNotification('info', '🍽️ ออเดอร์พร้อมเสิร์ฟแล้ว!');
                    this.playSound('orderReady');
                    setTimeout(() => this.loadActiveOrders(), 1000);
                }
            } else {
                throw new Error(data.message || 'Failed to update item status');
            }
            
        } catch (error) {
            console.error('Error updating item status:', error);
            this.showNotification('error', '❌ ไม่สามารถอัปเดตสถานะรายการได้');
        } finally {
            button.disabled = false;
            button.innerHTML = originalHTML;
        }
    }
    
    async viewOrderDetails(orderId) {
        try {
            this.showLoading(true);
            
            const response = await this.fetchWithRetry(`${this.apiUrl}?action=get_order_details&order_id=${orderId}`);
            const data = await response.json();
            
            if (data.success) {
                this.showOrderDetailsModal(data.order);
            } else {
                throw new Error(data.message || 'Failed to load order details');
            }
            
        } catch (error) {
            console.error('Error loading order details:', error);
            this.showNotification('error', '❌ ไม่สามารถโหลดรายละเอียดออเดอร์ได้');
        } finally {
            this.showLoading(false);
        }
    }
    
    showOrderDetailsModal(order) {
        let modal = document.getElementById('orderDetailsModal');
        
        if (!modal) {
            // Create modal if it doesn't exist
            modal = document.createElement('div');
            modal.id = 'orderDetailsModal';
            modal.className = 'modal fade kitchen-modal';
            modal.tabIndex = -1;
            document.body.appendChild(modal);
        }
        
        const itemsHTML = order.items ? order.items.map(item => `
            <tr>
                <td>
                    <div class="d-flex align-items-center">
                        ${item.image ? `
                            <img src="${SITE_URL}/uploads/menu_images/${item.image}" 
                                 class="me-2" 
                                 style="width: 40px; height: 40px; object-fit: cover; border-radius: 6px;"
                                 onerror="this.style.display='none'">
                        ` : ''}
                        <div>
                            <div class="fw-semibold">${this.escapeHtml(item.product_name)}</div>
                            ${item.notes ? `<small class="text-muted">${this.escapeHtml(item.notes)}</small>` : ''}
                        </div>
                    </div>
                </td>
                <td class="text-center">
                    <span class="badge bg-primary">${item.quantity}</span>
                </td>
                <td class="text-center">
                    <span class="kitchen-item-status ${item.status}">
                        ${this.getStatusText(item.status)}
                    </span>
                </td>
                <td class="text-end">
                    ${this.formatCurrency(item.subtotal)}
                </td>
            </tr>
        `).join('') : '';
        
        modal.innerHTML = `
            <div class="modal-dialog modal-lg">
                <div class="modal-content">
                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="fas fa-receipt me-2"></i>
                            รายละเอียดออเดอร์ - คิว ${order.queue_number || 'ORD-' + order.order_id}
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="row mb-4">
                            <div class="col-md-6">
                                <h6><i class="fas fa-info-circle me-2"></i>ข้อมูลออเดอร์</h6>
                                <table class="table table-sm">
                                    <tr><td><strong>ลูกค้า:</strong></td><td>${this.escapeHtml(order.customer_name || 'ลูกค้าทั่วไป')}</td></tr>
                                    <tr><td><strong>เวลาสั่ง:</strong></td><td>${this.formatDateTime(order.created_at)}</td></tr>
                                    <tr><td><strong>สถานะ:</strong></td><td><span class="kitchen-status-badge ${order.status}">${this.getStatusText(order.status)}</span></td></tr>
                                    <tr><td><strong>ประเภท:</strong></td><td>${this.getOrderTypeText(order.order_type)}</td></tr>
                                    <tr><td><strong>ยอดรวม:</strong></td><td class="text-success fw-bold">${this.formatCurrency(order.total_price)}</td></tr>
                                </table>
                            </div>
                            <div class="col-md-6">
                                <h6><i class="fas fa-phone me-2"></i>ติดต่อลูกค้า</h6>
                                <div class="mb-2">
                                    ${order.phone ? `
                                        <div class="mb-1">
                                            <i class="fas fa-phone me-2"></i>
                                            <a href="tel:${order.phone}" class="text-decoration-none">${order.phone}</a>
                                        </div>
                                    ` : ''}
                                    ${order.email ? `
                                        <div class="mb-1">
                                            <i class="fas fa-envelope me-2"></i>
                                            <a href="mailto:${order.email}" class="text-decoration-none">${order.email}</a>
                                        </div>
                                    ` : ''}
                                </div>
                                ${order.notes ? `
                                    <div class="kitchen-order-notes">
                                        <i class="fas fa-sticky-note"></i>
                                        <strong>หมายเหตุ:</strong> ${this.escapeHtml(order.notes)}
                                    </div>
                                ` : ''}
                            </div>
                        </div>
                        
                        <h6><i class="fas fa-utensils me-2"></i>รายการอาหาร</h6>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>รายการ</th>
                                        <th class="text-center">จำนวน</th>
                                        <th class="text-center">สถานะ</th>
                                        <th class="text-end">ราคา</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${itemsHTML}
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>ปิด
                        </button>
                        ${this.getModalActionButtonsHTML(order)}
                    </div>
                </div>
            </div>
        `;
        
        // Show modal
        const bsModal = new bootstrap.Modal(modal, {
            backdrop: 'static',
            keyboard: true
        });
        bsModal.show();
    }
    
    getModalActionButtonsHTML(order) {
        const buttons = [];
        
        switch (order.status) {
            case 'confirmed':
                buttons.push(`
                    <button type="button" class="btn btn-primary" 
                            data-action="update-order-status" 
                            data-order-id="${order.order_id}" 
                            data-status="preparing"
                            data-bs-dismiss="modal">
                        <i class="fas fa-play me-1"></i>รับออเดอร์
                    </button>
                `);
                break;
                
            case 'preparing':
                buttons.push(`
                    <button type="button" class="btn btn-success" 
                            data-action="update-order-status" 
                            data-order-id="${order.order_id}" 
                            data-status="ready"
                            data-bs-dismiss="modal">
                        <i class="fas fa-check me-1"></i>พร้อมเสิร์ฟ
                    </button>
                `);
                break;
        }
        
        buttons.push(`
            <button type="button" class="btn btn-info" 
                    data-action="call-queue" 
                    data-order-id="${order.order_id}"
                    data-bs-dismiss="modal">
                <i class="fas fa-microphone me-1"></i>เรียกคิว
            </button>
        `);
        
        return buttons.join('');
    }
    
    async callQueue(orderId, button) {
        button.disabled = true;
        const originalHTML = button.innerHTML;
        button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
        
        try {
            this.showLoading(true);
            
            const formData = new FormData();
            formData.append('action', 'call_queue');
            formData.append('order_id', orderId);
            
            const response = await this.fetchWithRetry(this.apiUrl, {
                method: 'POST',
                body: formData
            });
            
            const data = await response.json();
            
            if (data.success) {
                this.showNotification('success', `📢 เรียกคิว ${data.queue_number} แล้ว`);
                
                // Show visual feedback
                const orderCard = document.querySelector(`[data-order-id="${orderId}"]`);
                if (orderCard) {
                    orderCard.classList.add('kitchen-voice-calling');
                    setTimeout(() => {
                        orderCard.classList.remove('kitchen-voice-calling');
                    }, 3000);
                }
                
                // Play voice (if supported)
                this.speakQueue(data.voice_message);
                
            } else {
                throw new Error(data.message || 'Failed to call queue');
            }
            
        } catch (error) {
            console.error('Error calling queue:', error);
            this.showNotification('error', '❌ ไม่สามารถเรียกคิวได้');
        } finally {
            button.disabled = false;
            button.innerHTML = originalHTML;
            this.showLoading(false);
        }
    }
    
    speakQueue(message) {
        if ('speechSynthesis' in window) {
            const utterance = new SpeechSynthesisUtterance(message);
            utterance.lang = 'th-TH';
            utterance.rate = 0.8;
            utterance.volume = 0.8;
            
            // Get Thai voice if available
            const voices = speechSynthesis.getVoices();
            const thaiVoice = voices.find(voice => voice.lang.includes('th'));
            if (thaiVoice) {
                utterance.voice = thaiVoice;
            }
            
            speechSynthesis.speak(utterance);
        }
    }
    
    playSound(soundType) {
        if (!this.sounds[soundType]) return;
        
        try {
            // Create simple beep sound
            const audioContext = this.sounds[soundType];
            const oscillator = audioContext.createOscillator();
            const gainNode = audioContext.createGain();
            
            oscillator.connect(gainNode);
            gainNode.connect(audioContext.destination);
            
            const frequency = soundType === 'newOrder' ? 800 : 600;
            oscillator.frequency.setValueAtTime(frequency, audioContext.currentTime);
            oscillator.type = 'sine';
            
            gainNode.gain.setValueAtTime(0.3, audioContext.currentTime);
            gainNode.gain.exponentialRampToValueAtTime(0.01, audioContext.currentTime + 0.5);
            
            oscillator.start(audioContext.currentTime);
            oscillator.stop(audioContext.currentTime + 0.5);
        } catch (error) {
            console.warn('Could not play sound:', error);
        }
    }
    
    forceRefresh() {
        this.showNotification('info', '🔄 กำลังรีเฟรชข้อมูล...');
        Promise.all([
            this.loadActiveOrders(),
            this.loadKitchenStats()
        ]).then(() => {
            this.showNotification('success', '✅ รีเฟรชข้อมูลเสร็จสิ้น');
        }).catch(() => {
            this.showNotification('error', '❌ เกิดข้อผิดพลาดในการรีเฟรช');
        });
    }
    
    toggleAutoRefresh() {
        if (this.isPaused || !this.refreshTimer) {
            this.resumeTimers();
            this.showNotification('info', '▶️ เริ่มการรีเฟรชอัตโนมัติ');
        } else {
            this.pauseTimers();
            this.showNotification('warning', '⏸️ หยุดการรีเฟรชอัตโนมัติ');
        }
    }
    
    closeModals() {
        const modals = document.querySelectorAll('.modal.show');
        modals.forEach(modal => {
            const bsModal = bootstrap.Modal.getInstance(modal);
            if (bsModal) {
                bsModal.hide();
            }
        });
    }
    
    updateLastRefresh() {
        const element = document.getElementById('lastRefresh');
        if (element) {
            element.textContent = new Date().toLocaleTimeString('th-TH');
        }
    }
    
    showLoading(show) {
        const overlay = document.getElementById('loadingOverlay');
        if (overlay) {
            overlay.style.display = show ? 'flex' : 'none';
        }
    }
    
    showNotification(type, message, duration = 5000) {
        const notification = document.createElement('div');
        notification.className = `kitchen-notification ${type}`;
        notification.innerHTML = `
            <div class="d-flex align-items-start">
                <i class="fas fa-${this.getNotificationIcon(type)} me-2 mt-1"></i>
                <div class="flex-grow-1">${message}</div>
                <button type="button" class="btn-close btn-close-white ms-2" 
                        onclick="this.parentElement.parentElement.remove()"></button>
            </div>
        `;
        
        document.body.appendChild(notification);
        
        // Auto remove
        const timer = setTimeout(() => {
            if (notification.parentElement) {
                notification.style.animation = 'kitchen-slide-out 0.3s ease-in forwards';
                setTimeout(() => notification.remove(), 300);
            }
        }, duration);
        
        // Clear timer if manually closed
        notification.querySelector('.btn-close').addEventListener('click', () => {
            clearTimeout(timer);
        });
    }
    
    getNotificationIcon(type) {
        const icons = {
            'success': 'check-circle',
            'error': 'exclamation-triangle',
            'warning': 'exclamation-triangle',
            'info': 'info-circle'
        };
        return icons[type] || 'info-circle';
    }
    
    getEmptyStateHTML() {
        return `
            <div class="kitchen-empty-state">
                <i class="fas fa-clipboard-check"></i>
                <h4>ไม่มีออเดอร์ที่ต้องดำเนินการ</h4>
                <p>ออเดอร์ทั้งหมดเสร็จสิ้นแล้ว ✨</p>
                <button class="kitchen-btn kitchen-btn-primary" data-action="refresh">
                    <i class="fas fa-sync-alt me-2"></i>รีเฟรช
                </button>
            </div>
        `;
    }
    
    // Utility functions
    formatTime(dateString) {
        return new Date(dateString).toLocaleTimeString('th-TH', {
            hour: '2-digit',
            minute: '2-digit'
        });
    }
    
    formatDateTime(dateString) {
        return new Date(dateString).toLocaleString('th-TH');
    }
    
    formatCurrency(amount) {
        return new Intl.NumberFormat('th-TH', {
            style: 'currency',
            currency: 'THB'
        }).format(amount);
    }
    
    getStatusText(status) {
        const texts = {
            'pending': 'รอดำเนินการ',
            'confirmed': 'ยืนยันแล้ว',
            'preparing': 'กำลังเตรียม',
            'ready': 'พร้อมเสิร์ฟ',
            'completed': 'เสร็จสิ้น',
            'cancelled': 'ยกเลิก'
        };
        return texts[status] || 'ไม่ทราบ';
    }
    
    getStatusIcon(status) {
        const icons = {
            'pending': 'clock',
            'confirmed': 'check-circle',
            'preparing': 'fire',
            'ready': 'bell',
            'completed': 'check-double',
            'cancelled': 'times-circle'
        };
        return icons[status] || 'question-circle';
    }
    
    getOrderTypeText(type) {
        const types = {
            'dine_in': 'ทานที่ร้าน',
            'takeaway': 'ซื้อกลับ',
            'delivery': 'ส่งถึงที่'
        };
        return types[type] || 'ไม่ระบุ';
    }
    
    escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }
    
    // Cleanup method
    destroy() {
        this.pauseTimers();
        console.log('🔥 Kitchen System destroyed');
    }
}

// Auto-initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    // Initialize kitchen system
    window.kitchenSystem = new KitchenSystem();
    
    // Add CSS animation for slide-out
    const style = document.createElement('style');
    style.textContent = `
        @keyframes kitchen-slide-out {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
    `;
    document.head.appendChild(style);
});

// Handle page unload
window.addEventListener('beforeunload', () => {
    if (window.kitchenSystem) {
        window.kitchenSystem.destroy();
    }
});

// Export for global access
window.KitchenSystem = KitchenSystem;