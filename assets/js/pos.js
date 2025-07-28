/**
 * Smart Order Management System - POS JavaScript
 * ระบบจัดการ POS ที่เหมาะสำหรับ Tablet และมือถือ
 */

class POSSystem {
    constructor() {
        this.cart = [];
        this.currentOrder = null;
        this.selectedCategory = 'all';
        this.customerInfo = {};
        this.settings = {
            currency: '฿',
            taxRate: 7, // 7% VAT
            serviceCharge: 0 // ค่าบริการ
        };
        
        this.init();
    }
    
    init() {
        this.loadProducts();
        this.bindEvents();
        this.updateCartDisplay();
        this.loadSettings();
    }
    
    // โหลดสินค้าจากฐานข้อมูล
    async loadProducts() {
        try {
            const response = await fetch('api/get_products.php');
            const data = await response.json();
            
            if (data.success) {
                this.products = data.products;
                this.categories = data.categories;
                this.renderCategories();
                this.renderProducts();
            } else {
                this.showNotification('ไม่สามารถโหลดข้อมูลสินค้าได้', 'error');
            }
        } catch (error) {
            console.error('Error loading products:', error);
            this.showNotification('เกิดข้อผิดพลาดในการโหลดข้อมูล', 'error');
        }
    }
    
    // แสดงหมวดหมู่สินค้า
    renderCategories() {
        const categoryContainer = document.getElementById('categoryContainer');
        if (!categoryContainer) return;
        
        let html = `
            <button class="btn ${this.selectedCategory === 'all' ? 'btn-primary' : 'btn-outline-primary'} me-2 mb-2" 
                    onclick="pos.filterByCategory('all')">
                <i class="fas fa-th-large me-1"></i>ทั้งหมด
            </button>
        `;
        
        this.categories.forEach(category => {
            const isActive = this.selectedCategory === category.category_id.toString();
            html += `
                <button class="btn ${isActive ? 'btn-primary' : 'btn-outline-primary'} me-2 mb-2" 
                        onclick="pos.filterByCategory('${category.category_id}')">
                    <i class="fas fa-tag me-1"></i>${category.name}
                </button>
            `;
        });
        
        categoryContainer.innerHTML = html;
    }
    
    // แสดงสินค้า
    renderProducts() {
        const productContainer = document.getElementById('productContainer');
        if (!productContainer) return;
        
        let filteredProducts = this.products;
        if (this.selectedCategory !== 'all') {
            filteredProducts = this.products.filter(p => 
                p.category_id.toString() === this.selectedCategory
            );
        }
        
        let html = '';
        filteredProducts.forEach(product => {
            const isAvailable = product.is_available && product.stock_quantity > 0;
            html += `
                <div class="product-card ${!isAvailable ? 'disabled' : ''}" 
                     onclick="${isAvailable ? `pos.addToCart(${product.product_id})` : ''}">
                    <div class="product-image-container">
                        <img src="${product.image || 'assets/images/no-image.png'}" 
                             alt="${product.name}" class="product-image">
                        ${!isAvailable ? '<div class="product-overlay">หมด</div>' : ''}
                    </div>
                    <div class="product-info">
                        <div class="product-name">${product.name}</div>
                        <div class="product-price">${this.formatPrice(product.price)}</div>
                        ${product.preparation_time ? 
                            `<small class="text-muted">เวลาเตรียม ${product.preparation_time} นาที</small>` : 
                            ''
                        }
                    </div>
                </div>
            `;
        });
        
        if (html === '') {
            html = '<div class="text-center text-muted py-4">ไม่มีสินค้าในหมวดหมู่นี้</div>';
        }
        
        productContainer.innerHTML = html;
    }
    
    // กรองสินค้าตามหมวดหมู่
    filterByCategory(categoryId) {
        this.selectedCategory = categoryId;
        this.renderCategories();
        this.renderProducts();
    }
    
    // เพิ่มสินค้าในตะกร้า
    addToCart(productId) {
        const product = this.products.find(p => p.product_id === productId);
        if (!product) return;
        
        const existingItem = this.cart.find(item => item.product_id === productId);
        
        if (existingItem) {
            existingItem.quantity += 1;
        } else {
            this.cart.push({
                product_id: productId,
                name: product.name,
                price: parseFloat(product.price),
                quantity: 1,
                image: product.image,
                preparation_time: product.preparation_time || 0
            });
        }
        
        this.updateCartDisplay();
        this.showNotification(`เพิ่ม "${product.name}" ในตะกร้าแล้ว`, 'success');
        
        // เล่นเสียงเมื่อเพิ่มสินค้า
        this.playSound('add-item');
    }
    
    // ลบสินค้าจากตะกร้า
    removeFromCart(productId) {
        const itemIndex = this.cart.findIndex(item => item.product_id === productId);
        if (itemIndex > -1) {
            const item = this.cart[itemIndex];
            if (item.quantity > 1) {
                item.quantity -= 1;
            } else {
                this.cart.splice(itemIndex, 1);
            }
            this.updateCartDisplay();
        }
    }
    
    // ลบทั้งหมดของสินค้าจากตะกร้า
    removeAllFromCart(productId) {
        this.cart = this.cart.filter(item => item.product_id !== productId);
        this.updateCartDisplay();
    }
    
    // อัปเดตการแสดงผลตะกร้า
    updateCartDisplay() {
        const cartContainer = document.getElementById('cartContainer');
        const cartTotal = document.getElementById('cartTotal');
        const cartCount = document.getElementById('cartCount');
        
        if (!cartContainer) return;
        
        if (this.cart.length === 0) {
            cartContainer.innerHTML = `
                <div class="text-center text-muted py-4">
                    <i class="fas fa-shopping-cart fa-3x mb-3"></i>
                    <p>ตะกร้าว่าง</p>
                </div>
            `;
        } else {
            let html = '';
            this.cart.forEach(item => {
                const itemTotal = item.price * item.quantity;
                html += `
                    <div class="cart-item">
                        <div class="d-flex align-items-center">
                            <img src="${item.image || 'assets/images/no-image.png'}" 
                                 alt="${item.name}" class="cart-item-image">
                            <div class="flex-grow-1 ms-3">
                                <div class="cart-item-name">${item.name}</div>
                                <div class="cart-item-price">${this.formatPrice(item.price)} × ${item.quantity}</div>
                            </div>
                            <div class="cart-item-controls">
                                <button class="btn btn-sm btn-outline-secondary" 
                                        onclick="pos.removeFromCart(${item.product_id})">
                                    <i class="fas fa-minus"></i>
                                </button>
                                <span class="mx-2 fw-bold">${item.quantity}</span>
                                <button class="btn btn-sm btn-outline-secondary" 
                                        onclick="pos.addToCart(${item.product_id})">
                                    <i class="fas fa-plus"></i>
                                </button>
                                <button class="btn btn-sm btn-outline-danger ms-2" 
                                        onclick="pos.removeAllFromCart(${item.product_id})">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <div class="cart-item-total">${this.formatPrice(itemTotal)}</div>
                    </div>
                `;
            });
            cartContainer.innerHTML = html;
        }
        
        // อัปเดตยอดรวม
        const subtotal = this.calculateSubtotal();
        const tax = this.calculateTax(subtotal);
        const total = subtotal + tax;
        
        if (cartTotal) {
            cartTotal.innerHTML = `
                <div class="d-flex justify-content-between">
                    <span>ยอดรวม</span>
                    <span>${this.formatPrice(subtotal)}</span>
                </div>
                ${tax > 0 ? `
                <div class="d-flex justify-content-between">
                    <span>ภาษี (${this.settings.taxRate}%)</span>
                    <span>${this.formatPrice(tax)}</span>
                </div>
                ` : ''}
                <hr>
                <div class="d-flex justify-content-between h5">
                    <span>รวมทั้งสิ้น</span>
                    <span class="text-primary">${this.formatPrice(total)}</span>
                </div>
            `;
        }
        
        // อัปเดตจำนวนสินค้าในตะกร้า
        if (cartCount) {
            const totalItems = this.cart.reduce((sum, item) => sum + item.quantity, 0);
            cartCount.textContent = totalItems;
            cartCount.style.display = totalItems > 0 ? 'inline' : 'none';
        }
        
        // อัปเดตปุ่มชำระเงิน
        const checkoutBtn = document.getElementById('checkoutBtn');
        if (checkoutBtn) {
            checkoutBtn.disabled = this.cart.length === 0;
        }
    }
    
    // คำนวณยอดรวมก่อนภาษี
    calculateSubtotal() {
        return this.cart.reduce((sum, item) => sum + (item.price * item.quantity), 0);
    }
    
    // คำนวณภาษี
    calculateTax(subtotal) {
        return subtotal * (this.settings.taxRate / 100);
    }
    
    // เคลียร์ตะกร้า
    clearCart() {
        if (this.cart.length === 0) return;
        
        if (confirm('คุณต้องการเคลียร์ตะกร้าทั้งหมดหรือไม่?')) {
            this.cart = [];
            this.updateCartDisplay();
            this.showNotification('เคลียร์ตะกร้าแล้ว', 'info');
        }
    }
    
    // เปิดหน้าต่างชำระเงิน
    openCheckout() {
        if (this.cart.length === 0) {
            this.showNotification('กรุณาเลือกสินค้าก่อน', 'warning');
            return;
        }
        
        const modal = new bootstrap.Modal(document.getElementById('checkoutModal'));
        this.updateCheckoutModal();
        modal.show();
    }
    
    // อัปเดตหน้าต่างชำระเงิน
    updateCheckoutModal() {
        const checkoutItems = document.getElementById('checkoutItems');
        const checkoutSummary = document.getElementById('checkoutSummary');
        
        if (checkoutItems) {
            let html = '';
            this.cart.forEach(item => {
                const itemTotal = item.price * item.quantity;
                html += `
                    <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                        <div>
                            <div class="fw-medium">${item.name}</div>
                            <small class="text-muted">${this.formatPrice(item.price)} × ${item.quantity}</small>
                        </div>
                        <div class="fw-bold">${this.formatPrice(itemTotal)}</div>
                    </div>
                `;
            });
            checkoutItems.innerHTML = html;
        }
        
        if (checkoutSummary) {
            const subtotal = this.calculateSubtotal();
            const tax = this.calculateTax(subtotal);
            const total = subtotal + tax;
            
            checkoutSummary.innerHTML = `
                <div class="d-flex justify-content-between">
                    <span>ยอดรวม</span>
                    <span>${this.formatPrice(subtotal)}</span>
                </div>
                ${tax > 0 ? `
                <div class="d-flex justify-content-between">
                    <span>ภาษี (${this.settings.taxRate}%)</span>
                    <span>${this.formatPrice(tax)}</span>
                </div>
                ` : ''}
                <hr>
                <div class="d-flex justify-content-between h5">
                    <span>รวมทั้งสิ้น</span>
                    <span class="text-primary">${this.formatPrice(total)}</span>
                </div>
            `;
        }
    }
    
    // ประมวลผลการชำระเงิน
    async processPayment(paymentMethod) {
        const subtotal = this.calculateSubtotal();
        const tax = this.calculateTax(subtotal);
        const total = subtotal + tax;
        
        const orderData = {
            items: this.cart,
            subtotal: subtotal,
            tax: tax,
            total: total,
            payment_method: paymentMethod,
            customer_info: this.customerInfo
        };
        
        try {
            const response = await fetch('api/create_order.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(orderData)
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.currentOrder = result.order;
                this.showNotification('สร้างออเดอร์สำเร็จ', 'success');
                this.playSound('order-success');
                
                // เคลียร์ตะกร้า
                this.cart = [];
                this.updateCartDisplay();
                
                // ปิด modal
                const modal = bootstrap.Modal.getInstance(document.getElementById('checkoutModal'));
                modal.hide();
                
                // เปิดหน้าต่างพิมพ์ใบเสร็จ
                this.openReceiptModal();
                
            } else {
                this.showNotification('เกิดข้อผิดพลาด: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('Error processing payment:', error);
            this.showNotification('เกิดข้อผิดพลาดในการประมวลผล', 'error');
        }
    }
    
    // เปิดหน้าต่างใบเสร็จ
    openReceiptModal() {
        if (!this.currentOrder) return;
        
        const modal = new bootstrap.Modal(document.getElementById('receiptModal'));
        this.updateReceiptModal();
        modal.show();
    }
    
    // อัปเดตหน้าต่างใบเสร็จ
    updateReceiptModal() {
        const receiptContent = document.getElementById('receiptContent');
        if (!receiptContent || !this.currentOrder) return;
        
        const order = this.currentOrder;
        const date = new Date(order.created_at).toLocaleString('th-TH');
        
        let itemsHtml = '';
        order.items.forEach(item => {
            const itemTotal = item.price * item.quantity;
            itemsHtml += `
                <tr>
                    <td>${item.name}</td>
                    <td class="text-center">${item.quantity}</td>
                    <td class="text-end">${this.formatPrice(item.price)}</td>
                    <td class="text-end">${this.formatPrice(itemTotal)}</td>
                </tr>
            `;
        });
        
        receiptContent.innerHTML = `
            <div class="receipt-header text-center mb-4">
                <h4>ใบเสร็จรับเงิน</h4>
                <p class="mb-0">เลขที่ออเดอร์: ${order.order_number}</p>
                <p class="mb-0">วันที่: ${date}</p>
                <p class="mb-0">หมายเลขคิว: ${order.queue_number}</p>
            </div>
            
            <table class="table table-sm">
                <thead>
                    <tr>
                        <th>รายการ</th>
                        <th class="text-center">จำนวน</th>
                        <th class="text-end">ราคา</th>
                        <th class="text-end">รวม</th>
                    </tr>
                </thead>
                <tbody>
                    ${itemsHtml}
                </tbody>
                <tfoot>
                    <tr>
                        <th colspan="3">ยอดรวม</th>
                        <th class="text-end">${this.formatPrice(order.subtotal)}</th>
                    </tr>
                    ${order.tax > 0 ? `
                    <tr>
                        <th colspan="3">ภาษี (${this.settings.taxRate}%)</th>
                        <th class="text-end">${this.formatPrice(order.tax)}</th>
                    </tr>
                    ` : ''}
                    <tr class="table-active">
                        <th colspan="3">รวมทั้งสิ้น</th>
                        <th class="text-end">${this.formatPrice(order.total)}</th>
                    </tr>
                </tfoot>
            </table>
            
            <div class="text-center mt-4">
                <p class="mb-0">ขอบคุณที่ใช้บริการ</p>
                <p class="mb-0">กรุณาเก็บใบเสร็จไว้เป็นหลักฐาน</p>
            </div>
        `;
    }
    
    // พิมพ์ใบเสร็จ
    printReceipt() {
        const printContent = document.getElementById('receiptContent').innerHTML;
        const printWindow = window.open('', '_blank');
        
        printWindow.document.write(`
            <!DOCTYPE html>
            <html>
            <head>
                <title>ใบเสร็จ - ${this.currentOrder?.order_number}</title>
                <style>
                    body { font-family: 'Courier New', monospace; padding: 20px; }
                    .receipt-header { text-align: center; margin-bottom: 20px; }
                    table { width: 100%; border-collapse: collapse; }
                    th, td { padding: 5px; border-bottom: 1px solid #ddd; }
                    .text-center { text-align: center; }
                    .text-end { text-align: right; }
                    .table-active { background-color: #f8f9fa; }
                </style>
            </head>
            <body>
                ${printContent}
            </body>
            </html>
        `);
        
        printWindow.document.close();
        printWindow.focus();
        printWindow.print();
        printWindow.close();
    }
    
    // ส่งใบเสร็จผ่าน LINE
    async sendReceiptToLine() {
        if (!this.currentOrder) return;
        
        try {
            const response = await fetch('api/send_receipt_line.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    order_id: this.currentOrder.order_id,
                    phone: this.customerInfo.phone
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.showNotification('ส่งใบเสร็จผ่าน LINE แล้ว', 'success');
            } else {
                this.showNotification('ไม่สามารถส่งใบเสร็จได้: ' + result.message, 'error');
            }
        } catch (error) {
            console.error('Error sending receipt:', error);
            this.showNotification('เกิดข้อผิดพลาดในการส่งใบเสร็จ', 'error');
        }
    }
    
    // จัดรูปแบบราคา
    formatPrice(price) {
        return new Intl.NumberFormat('th-TH', {
            style: 'currency',
            currency: 'THB',
            minimumFractionDigits: 0
        }).format(price);
    }
    
    // แสดงแจ้งเตือน
    showNotification(message, type = 'info') {
        const toastContainer = document.getElementById('toastContainer') || this.createToastContainer();
        const toastId = 'toast-' + Date.now();
        
        const toastHtml = `
            <div id="${toastId}" class="toast align-items-center text-white bg-${type === 'error' ? 'danger' : type} border-0" role="alert">
                <div class="d-flex">
                    <div class="toast-body">
                        <i class="fas fa-${this.getIconByType(type)} me-2"></i>
                        ${message}
                    </div>
                    <button type="button" class="btn-close btn-close-white me-2 m-auto" data-bs-dismiss="toast"></button>
                </div>
            </div>
        `;
        
        toastContainer.insertAdjacentHTML('beforeend', toastHtml);
        const toast = new bootstrap.Toast(document.getElementById(toastId));
        toast.show();
        
        // ลบ toast หลังจากซ่อน
        setTimeout(() => {
            const element = document.getElementById(toastId);
            if (element) element.remove();
        }, 5000);
    }
    
    // สร้าง Toast Container
    createToastContainer() {
        const container = document.createElement('div');
        container.id = 'toastContainer';
        container.className = 'toast-container position-fixed top-0 end-0 p-3';
        container.style.zIndex = '1055';
        document.body.appendChild(container);
        return container;
    }
    
    // ได้ไอคอนตามประเภท
    getIconByType(type) {
        const icons = {
            success: 'check-circle',
            error: 'exclamation-circle',
            warning: 'exclamation-triangle',
            info: 'info-circle'
        };
        return icons[type] || 'info-circle';
    }
    
    // เล่นเสียง
    playSound(type) {
        const audio = new Audio(`assets/sounds/${type}.mp3`);
        audio.volume = 0.5;
        audio.play().catch(() => {
            // ไม่สามารถเล่นเสียงได้ (อาจถูกบล็อคโดยเบราว์เซอร์)
        });
    }
    
    // ผูกเหตุการณ์
    bindEvents() {
        // ค้นหาสินค้า
        const searchInput = document.getElementById('productSearch');
        if (searchInput) {
            searchInput.addEventListener('input', (e) => {
                this.searchProducts(e.target.value);
            });
        }
        
        // เคลียร์ตะกร้า
        const clearCartBtn = document.getElementById('clearCartBtn');
        if (clearCartBtn) {
            clearCartBtn.addEventListener('click', () => this.clearCart());
        }
        
        // ชำระเงิน
        const checkoutBtn = document.getElementById('checkoutBtn');
        if (checkoutBtn) {
            checkoutBtn.addEventListener('click', () => this.openCheckout());
        }
        
        // ปุ่มชำระเงินในหน้าต่าง
        document.querySelectorAll('.payment-btn').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const paymentMethod = e.target.dataset.method;
                this.processPayment(paymentMethod);
            });
        });
        
        // ปุ่มพิมพ์ใบเสร็จ
        const printBtn = document.getElementById('printReceiptBtn');
        if (printBtn) {
            printBtn.addEventListener('click', () => this.printReceipt());
        }
        
        // ปุ่มส่ง LINE
        const lineBtn = document.getElementById('sendLineBtn');
        if (lineBtn) {
            lineBtn.addEventListener('click', () => this.sendReceiptToLine());
        }
    }
    
    // ค้นหาสินค้า
    searchProducts(query) {
        const products = document.querySelectorAll('.product-card');
        const searchTerm = query.toLowerCase().trim();
        
        products.forEach(product => {
            const productName = product.querySelector('.product-name').textContent.toLowerCase();
            if (searchTerm === '' || productName.includes(searchTerm)) {
                product.style.display = 'block';
            } else {
                product.style.display = 'none';
            }
        });
    }
    
    // โหลดการตั้งค่า
    async loadSettings() {
        try {
            const response = await fetch('api/get_settings.php');
            const data = await response.json();
            
            if (data.success) {
                this.settings = { ...this.settings, ...data.settings };
            }
        } catch (error) {
            console.error('Error loading settings:', error);
        }
    }
}

// สร้าง instance ของ POS System
const pos = new POSSystem();

// ฟังก์ชันสำหรับ responsive design
function toggleSidebar() {
    const sidebar = document.querySelector('.sidebar');
    if (sidebar) {
        sidebar.classList.toggle('show');
    }
}

// จัดการ touch events สำหรับ mobile
document.addEventListener('DOMContentLoaded', function() {
    // ปิด sidebar เมื่อคลิกนอกพื้นที่
    document.addEventListener('click', function(e) {
        const sidebar = document.querySelector('.sidebar');
        const sidebarToggle = document.querySelector('.sidebar-toggle');
        
        if (sidebar && sidebar.classList.contains('show')) {
            if (!sidebar.contains(e.target) && !sidebarToggle?.contains(e.target)) {
                sidebar.classList.remove('show');
            }
        }
    });
    
    // จัดการ swipe gestures สำหรับ mobile
    let touchStartX = 0;
    let touchEndX = 0;
    
    document.addEventListener('touchstart', function(e) {
        touchStartX = e.changedTouches[0].screenX;
    });
    
    document.addEventListener('touchend', function(e) {
        touchEndX = e.changedTouches[0].screenX;
        handleGesture();
    });
    
    function handleGesture() {
        const swipeThreshold = 100;
        const diff = touchEndX - touchStartX;
        
        if (Math.abs(diff) > swipeThreshold) {
            const sidebar = document.querySelector('.sidebar');
            if (diff > 0 && touchStartX < 50) {
                // Swipe right from left edge - open sidebar
                sidebar?.classList.add('show');
            } else if (diff < 0 && sidebar?.classList.contains('show')) {
                // Swipe left - close sidebar
                sidebar.classList.remove('show');
            }
        }
    }
});