/**
 * Smart Order Management System - AI Voice System
 * ระบบเสียงอัจฉริยะสำหรับเรียกคิวลูกค้า
 */

class AIVoiceSystem {
    constructor() {
        this.isEnabled = true;
        this.volume = 0.8;
        this.language = 'th-TH';
        this.voice = null;
        this.speechSynthesis = window.speechSynthesis;
        this.currentQueue = null;
        this.queueHistory = [];
        this.settings = {
            autoAnnounce: true,
            repeatAnnouncement: false,
            repeatInterval: 30000, // 30 seconds
            customMessages: {
                welcome: 'ยินดีต้อนรับสู่ร้านของเรา',
                queueReady: 'คิวที่ {queue} ออเดอร์ {order} พร้อมเสิร์ฟแล้วครับ กรุณามารับที่เคาน์เตอร์',
                queueWaiting: 'คิวที่ {queue} กำลังเตรียมออเดอร์ของคุณ โปรดรอสักครู่',
                orderComplete: 'ออเดอร์หมายเลข {order} เสร็จสิ้นแล้ว',
                callNext: 'เรียกคิวถัดไป',
                thankYou: 'ขอบคุณที่ใช้บริการครับ'
            }
        };
        
        this.init();
    }
    
    async init() {
        await this.loadVoices();
        this.loadSettings();
        this.bindEvents();
        this.startQueueMonitoring();
    }
    
    // โหลดเสียงที่ใช้ได้
    async loadVoices() {
        return new Promise((resolve) => {
            const loadVoices = () => {
                const voices = this.speechSynthesis.getVoices();
                
                // หาเสียงภาษาไทยที่เหมาะสม
                this.voice = voices.find(voice => 
                    voice.lang === 'th-TH' || 
                    voice.lang.startsWith('th')
                ) || voices.find(voice => 
                    voice.lang === 'en-US' || 
                    voice.lang.startsWith('en')
                ) || voices[0];
                
                console.log('Available voices:', voices.length);
                console.log('Selected voice:', this.voice?.name);
                
                resolve();
            };
            
            if (this.speechSynthesis.getVoices().length > 0) {
                loadVoices();
            } else {
                this.speechSynthesis.addEventListener('voiceschanged', loadVoices);
            }
        });
    }
    
    // พูดข้อความ
    speak(text, options = {}) {
        if (!this.isEnabled || !text) return;
        
        // หยุดการพูดปัจจุบัน
        this.speechSynthesis.cancel();
        
        const utterance = new SpeechSynthesisUtterance(text);
        
        // ตั้งค่าเสียง
        utterance.voice = this.voice;
        utterance.volume = options.volume || this.volume;
        utterance.rate = options.rate || 0.9;
        utterance.pitch = options.pitch || 1.0;
        utterance.lang = options.lang || this.language;
        
        // Event handlers
        utterance.onstart = () => {
            console.log('Started speaking:', text);
            this.onSpeechStart(text);
        };
        
        utterance.onend = () => {
            console.log('Finished speaking:', text);
            this.onSpeechEnd(text);
        };
        
        utterance.onerror = (event) => {
            console.error('Speech error:', event.error);
            this.onSpeechError(event.error);
        };
        
        // เล่นเสียง
        this.speechSynthesis.speak(utterance);
        
        return utterance;
    }
    
    // เรียกคิว
    announceQueue(queueData) {
        if (!queueData) return;
        
        const { queue_number, order_number, status, customer_name } = queueData;
        let message = '';
        
        switch (status) {
            case 'ready':
                message = this.settings.customMessages.queueReady
                    .replace('{queue}', queue_number)
                    .replace('{order}', order_number);
                break;
                
            case 'preparing':
                message = this.settings.customMessages.queueWaiting
                    .replace('{queue}', queue_number);
                break;
                
            case 'completed':
                message = this.settings.customMessages.orderComplete
                    .replace('{order}', order_number);
                break;
        }
        
        if (customer_name) {
            message = `คุณ${customer_name} ` + message;
        }
        
        this.speak(message);
        
        // บันทึกการเรียก
        this.queueHistory.push({
            queue_number,
            order_number,
            status,
            message,
            timestamp: new Date(),
            customer_name
        });
        
        // อัปเดต UI
        this.updateQueueDisplay(queueData);
        
        // เล่นเสียงแจ้งเตือนก่อนพูด
        this.playNotificationSound();
        
        // ส่งแจ้งเตือนผ่าน LINE (ถ้ามี)
        this.sendLineNotification(queueData);
    }
    
    // เล่นเสียงแจ้งเตือน
    playNotificationSound() {
        const audio = new Audio('assets/sounds/notification.mp3');
        audio.volume = 0.6;
        audio.play().catch(e => {
            console.log('Could not play notification sound:', e);
        });
    }
    
    // ข้อความต้อนรับ
    announceWelcome() {
        this.speak(this.settings.customMessages.welcome);
    }
    
    // ข้อความขอบคุณ
    announceThankYou() {
        this.speak(this.settings.customMessages.thankYou);
    }
    
    // เรียกคิูถัดไป
    announceNextQueue() {
        this.speak(this.settings.customMessages.callNext);
    }
    
    // อัปเดตการแสดงผลคิว
    updateQueueDisplay(queueData) {
        const queueDisplay = document.getElementById('currentQueueDisplay');
        const queueStatus = document.getElementById('queueStatus');
        const queueList = document.getElementById('queueList');
        
        if (queueDisplay) {
            queueDisplay.textContent = queueData.queue_number;
            queueDisplay.className = `queue-number ${queueData.status}`;
        }
        
        if (queueStatus) {
            const statusText = {
                'waiting': 'รอการเตรียม',
                'preparing': 'กำลังเตรียม',
                'ready': 'พร้อมเสิร์ฟ',
                'completed': 'เสร็จสิ้น'
            };
            
            queueStatus.textContent = statusText[queueData.status] || queueData.status;
            queueStatus.className = `queue-status ${queueData.status}`;
        }
        
        // อัปเดตรายการคิว
        if (queueList) {
            this.updateQueueList();
        }
    }
    
    // อัปเดตรายการคิวทั้งหมด
    async updateQueueList() {
        try {
            const response = await fetch('/pos/api/get_queue_status.php');
            const data = await response.json();
            
            if (data.success) {
                const queueList = document.getElementById('queueList');
                if (queueList) {
                    let html = '';
                    
                    data.queues.forEach(queue => {
                        const statusClass = queue.status;
                        const statusText = {
                            'waiting': 'รอการเตรียม',
                            'preparing': 'กำลังเตรียม',
                            'ready': 'พร้อมเสิร์ฟ',
                            'completed': 'เสร็จสิ้น'
                        };
                        
                        html += `
                            <div class="queue-item ${statusClass}" data-queue-id="${queue.order_id}">
                                <div class="queue-item-header">
                                    <span class="queue-number">คิว ${queue.queue_number}</span>
                                    <span class="badge bg-${this.getStatusColor(queue.status)}">
                                        ${statusText[queue.status]}
                                    </span>
                                </div>
                                <div class="queue-item-details">
                                    <div>ออเดอร์: ${queue.order_number}</div>
                                    ${queue.customer_name ? `<div>ลูกค้า: ${queue.customer_name}</div>` : ''}
                                    <div>เวลา: ${new Date(queue.created_at).toLocaleTimeString('th-TH')}</div>
                                </div>
                                <div class="queue-item-actions">
                                    ${queue.status !== 'completed' ? `
                                        <button class="btn btn-sm btn-primary" 
                                                onclick="voice.announceQueue(${JSON.stringify(queue).replace(/"/g, '&quot;')})">
                                            <i class="fas fa-volume-up"></i> เรียก
                                        </button>
                                    ` : ''}
                                    ${queue.status === 'ready' ? `
                                        <button class="btn btn-sm btn-success" 
                                                onclick="voice.markAsCompleted(${queue.order_id})">
                                            <i class="fas fa-check"></i> เสร็จสิ้น
                                        </button>
                                    ` : ''}
                                </div>
                            </div>
                        `;
                    });
                    
                    queueList.innerHTML = html || '<div class="text-center text-muted py-4">ไม่มีคิวในขณะนี้</div>';
                }
            }
        } catch (error) {
            console.error('Error updating queue list:', error);
        }
    }
    
    // ได้สีสถานะ
    getStatusColor(status) {
        const colors = {
            'waiting': 'secondary',
            'preparing': 'warning',
            'ready': 'success',
            'completed': 'info'
        };
        return colors[status] || 'secondary';
    }
    
    // มาร์คคิวเป็นเสร็จสิ้น
    async markAsCompleted(orderId) {
        try {
            const response = await fetch('/pos/api/update_queue_status.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    order_id: orderId,
                    status: 'completed'
                })
            });
            
            const result = await response.json();
            
            if (result.success) {
                this.announceThankYou();
                this.updateQueueList();
                
                // แจ้งเตือนผ่าน LINE
                this.sendLineNotification({
                    order_id: orderId,
                    status: 'completed'
                });
            }
        } catch (error) {
            console.error('Error marking queue as completed:', error);
        }
    }
    
    // ส่งแจ้งเตือนผ่าน LINE
    async sendLineNotification(queueData) {
        try {
            const response = await fetch('/pos/api/send_line_notification.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify({
                    queue_data: queueData,
                    message_type: 'queue_update'
                })
            });
            
            const result = await response.json();
            console.log('LINE notification result:', result);
        } catch (error) {
            console.error('Error sending LINE notification:', error);
        }
    }
    
    // เริ่มตรวจสอบคิว
    startQueueMonitoring() {
        // ตรวจสอบคิวใหม่ทุก 10 วินาที
        setInterval(() => {
            this.checkForNewQueues();
        }, 10000);
        
        // ตรวจสอบการอัปเดตสถานะทุก 5 วินาที
        setInterval(() => {
            this.updateQueueList();
        }, 5000);
    }
    
    // ตรวจสอบคิวใหม่
    async checkForNewQueues() {
        if (!this.settings.autoAnnounce) return;
        
        try {
            const response = await fetch('/pos/api/get_new_queues.php');
            const data = await response.json();
            
            if (data.success && data.newQueues.length > 0) {
                for (const queue of data.newQueues) {
                    // ประกาศคิวใหม่
                    setTimeout(() => {
                        this.announceQueue(queue);
                    }, 1000); // หน่วงเวลา 1 วินาที
                }
            }
        } catch (error) {
            console.error('Error checking for new queues:', error);
        }
    }
    
    // ตั้งค่าระบบเสียง
    updateSettings(newSettings) {
        this.settings = { ...this.settings, ...newSettings };
        this.saveSettings();
    }
    
    // บันทึกการตั้งค่า
    saveSettings() {
        localStorage.setItem('voiceSettings', JSON.stringify(this.settings));
    }
    
    // โหลดการตั้งค่า
    loadSettings() {
        const saved = localStorage.getItem('voiceSettings');
        if (saved) {
            try {
                const parsedSettings = JSON.parse(saved);
                this.settings = { ...this.settings, ...parsedSettings };
            } catch (error) {
                console.error('Error loading voice settings:', error);
            }
        }
    }
    
    // เปิด/ปิดระบบเสียง
    toggle() {
        this.isEnabled = !this.isEnabled;
        this.updateUI();
        
        if (this.isEnabled) {
            this.speak('เปิดระบบเสียงแล้ว');
        }
    }
    
    // ปรับระดับเสียง
    setVolume(volume) {
        this.volume = Math.max(0, Math.min(1, volume));
        this.updateUI();
    }
    
    // ทดสอบเสียง
    testVoice() {
        const testMessage = 'ทดสอบระบบเสียง คิวที่ 1 ออเดอร์ A001 พร้อมเสิร์ฟแล้วครับ';
        this.speak(testMessage);
    }
    
    // อัปเดต UI
    updateUI() {
        const toggleBtn = document.getElementById('voiceToggleBtn');
        const volumeSlider = document.getElementById('volumeSlider');
        const volumeValue = document.getElementById('volumeValue');
        
        if (toggleBtn) {
            toggleBtn.textContent = this.isEnabled ? 'ปิดเสียง' : 'เปิดเสียง';
            toggleBtn.className = `btn ${this.isEnabled ? 'btn-success' : 'btn-secondary'}`;
        }
        
        if (volumeSlider) {
            volumeSlider.value = this.volume * 100;
        }
        
        if (volumeValue) {
            volumeValue.textContent = Math.round(this.volume * 100) + '%';
        }
    }
    
    // Event handlers
    onSpeechStart(text) {
        const speakingIndicator = document.getElementById('speakingIndicator');
        if (speakingIndicator) {
            speakingIndicator.style.display = 'block';
            speakingIndicator.textContent = 'กำลังพูด...';
        }
    }
    
    onSpeechEnd(text) {
        const speakingIndicator = document.getElementById('speakingIndicator');
        if (speakingIndicator) {
            speakingIndicator.style.display = 'none';
        }
    }
    
    onSpeechError(error) {
        console.error('Speech synthesis error:', error);
        const speakingIndicator = document.getElementById('speakingIndicator');
        if (speakingIndicator) {
            speakingIndicator.style.display = 'none';
        }
    }
    
    // ผูกเหตุการณ์
    bindEvents() {
        // ปุ่มเปิด/ปิดเสียง
        const toggleBtn = document.getElementById('voiceToggleBtn');
        if (toggleBtn) {
            toggleBtn.addEventListener('click', () => this.toggle());
        }
        
        // แถบปรับระดับเสียง
        const volumeSlider = document.getElementById('volumeSlider');
        if (volumeSlider) {
            volumeSlider.addEventListener('input', (e) => {
                this.setVolume(parseInt(e.target.value) / 100);
            });
        }
        
        // ปุ่มทดสอบเสียง
        const testBtn = document.getElementById('testVoiceBtn');
        if (testBtn) {
            testBtn.addEventListener('click', () => this.testVoice());
        }
        
        // ปุ่มต้อนรับ
        const welcomeBtn = document.getElementById('welcomeBtn');
        if (welcomeBtn) {
            welcomeBtn.addEventListener('click', () => this.announceWelcome());
        }
        
        // ปุ่มเรียกคิวถัดไป
        const nextQueueBtn = document.getElementById('nextQueueBtn');
        if (nextQueueBtn) {
            nextQueueBtn.addEventListener('click', () => this.announceNextQueue());
        }
        
        // Checkbox การประกาศอัตโนมัติ
        const autoAnnounceCheck = document.getElementById('autoAnnounceCheck');
        if (autoAnnounceCheck) {
            autoAnnounceCheck.checked = this.settings.autoAnnounce;
            autoAnnounceCheck.addEventListener('change', (e) => {
                this.updateSettings({ autoAnnounce: e.target.checked });
            });
        }
    }
    
    // หยุดการพูดทั้งหมด
    stopSpeaking() {
        this.speechSynthesis.cancel();
    }
    
    // ได้ประวัติการเรียกคิว
    getQueueHistory() {
        return this.queueHistory;
    }
    
    // เคลียร์ประวัติ
    clearHistory() {
        this.queueHistory = [];
    }
}

// สร้าง instance ของระบบเสียง
const voice = new AIVoiceSystem();

// Export สำหรับใช้งานในไฟล์อื่น
if (typeof module !== 'undefined' && module.exports) {
    module.exports = AIVoiceSystem;
}

// Keyboard shortcuts
document.addEventListener('keydown', function(event) {
    // Ctrl + Shift + V = Toggle voice
    if (event.ctrlKey && event.shiftKey && event.key === 'V') {
        event.preventDefault();
        voice.toggle();
    }
    
    // Ctrl + Shift + T = Test voice
    if (event.ctrlKey && event.shiftKey && event.key === 'T') {
        event.preventDefault();
        voice.testVoice();
    }
    
    // Ctrl + Shift + W = Welcome message
    if (event.ctrlKey && event.shiftKey && event.key === 'W') {
        event.preventDefault();
        voice.announceWelcome();
    }
    
    // Ctrl + Shift + N = Next queue
    if (event.ctrlKey && event.shiftKey && event.key === 'N') {
        event.preventDefault();
        voice.announceNextQueue();
    }
    
    // Escape = Stop speaking
    if (event.key === 'Escape') {
        voice.stopSpeaking();
    }
});