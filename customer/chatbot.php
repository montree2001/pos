<?php
/**
 * หน้า AI Chatbot
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

$pageTitle = 'AI Assistant';
$pageDescription = 'แชทกับ AI เพื่อสั่งอาหารและสอบถามข้อมูล';

// สร้าง session ID สำหรับ chatbot
if (!SessionManager::has('chatbot_session_id')) {
    SessionManager::set('chatbot_session_id', uniqid('chat_', true));
}
$chatbotSessionId = SessionManager::get('chatbot_session_id');
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle; ?> - <?php echo SITE_NAME; ?></title>
    <meta name="description" content="<?php echo $pageDescription; ?>">
    
    <!-- Bootstrap 5 -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Font Awesome -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css" rel="stylesheet">
    <!-- Animate.css -->
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4f46e5;
            --secondary-color: #10b981;
            --bot-color: #6366f1;
            --user-color: #059669;
            --light-bg: #f8fafc;
            --white: #ffffff;
            --border-color: #e5e7eb;
            --text-color: #1f2937;
            --text-muted: #6b7280;
            --border-radius: 16px;
            --box-shadow: 0 4px 20px rgba(0,0,0,0.08);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        }
        
        body {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: var(--text-color);
            line-height: 1.6;
            height: 100vh;
            overflow: hidden;
        }
        
        /* Navigation */
        .navbar-custom {
            background: var(--white);
            box-shadow: var(--box-shadow);
            padding: 1rem 0;
            position: relative;
            z-index: 1000;
        }
        
        .navbar-brand {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary-color) !important;
        }
        
        /* Main Container */
        .chat-container {
            height: calc(100vh - 76px);
            display: flex;
            flex-direction: column;
            background: var(--white);
            margin: 0 auto;
            max-width: 1200px;
            box-shadow: var(--box-shadow);
        }
        
        /* Chat Header */
        .chat-header {
            background: linear-gradient(135deg, var(--bot-color), #8b5cf6);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            gap: 1rem;
        }
        
        .bot-avatar {
            width: 50px;
            height: 50px;
            background: rgba(255, 255, 255, 0.2);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.5rem;
        }
        
        .bot-info h3 {
            margin: 0;
            font-size: 1.25rem;
        }
        
        .bot-status {
            font-size: 0.875rem;
            opacity: 0.9;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .status-dot {
            width: 8px;
            height: 8px;
            background: #22c55e;
            border-radius: 50%;
            animation: pulse 2s infinite;
        }
        
        @keyframes pulse {
            0%, 100% { opacity: 1; }
            50% { opacity: 0.5; }
        }
        
        /* Chat Messages */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
            background: var(--light-bg);
            scroll-behavior: smooth;
        }
        
        .message {
            display: flex;
            margin-bottom: 1.5rem;
            animation: messageSlide 0.3s ease-out;
        }
        
        @keyframes messageSlide {
            from { opacity: 0; transform: translateY(20px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .message.user {
            justify-content: flex-end;
        }
        
        .message-content {
            max-width: 70%;
            padding: 1rem 1.5rem;
            border-radius: 20px;
            position: relative;
            word-wrap: break-word;
        }
        
        .message.bot .message-content {
            background: var(--white);
            color: var(--text-color);
            border: 2px solid var(--border-color);
            margin-left: 60px;
        }
        
        .message.user .message-content {
            background: linear-gradient(135deg, var(--user-color), #047857);
            color: white;
            margin-right: 60px;
        }
        
        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        
        .message.bot .message-avatar {
            background: linear-gradient(135deg, var(--bot-color), #8b5cf6);
            color: white;
            position: absolute;
            left: 10px;
            top: 0;
        }
        
        .message.user .message-avatar {
            background: linear-gradient(135deg, var(--user-color), #047857);
            color: white;
            position: absolute;
            right: 10px;
            top: 0;
        }
        
        .message-time {
            font-size: 0.75rem;
            opacity: 0.7;
            margin-top: 0.5rem;
        }
        
        /* Quick Replies */
        .quick-replies {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
            margin-top: 1rem;
        }
        
        .quick-reply {
            background: var(--light-bg);
            border: 2px solid var(--border-color);
            border-radius: 20px;
            padding: 8px 16px;
            font-size: 0.875rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .quick-reply:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        /* Product Cards in Chat */
        .product-card-chat {
            background: var(--white);
            border: 2px solid var(--border-color);
            border-radius: 12px;
            padding: 1rem;
            margin: 0.5rem 0;
            display: flex;
            gap: 1rem;
            transition: var(--transition);
        }
        
        .product-card-chat:hover {
            border-color: var(--secondary-color);
            transform: translateY(-2px);
        }
        
        .product-image-chat {
            width: 60px;
            height: 60px;
            background: var(--light-bg);
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: var(--text-muted);
            flex-shrink: 0;
        }
        
        .product-info-chat {
            flex: 1;
        }
        
        .product-name-chat {
            font-weight: 600;
            margin-bottom: 0.25rem;
        }
        
        .product-price-chat {
            color: var(--secondary-color);
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .add-to-cart-chat {
            background: linear-gradient(135deg, var(--secondary-color), #059669);
            color: white;
            border: none;
            border-radius: 8px;
            padding: 6px 12px;
            font-size: 0.875rem;
            transition: var(--transition);
        }
        
        .add-to-cart-chat:hover {
            transform: translateY(-1px);
            box-shadow: 0 4px 15px rgba(16, 185, 129, 0.3);
        }
        
        /* Chat Input */
        .chat-input {
            background: var(--white);
            border-top: 2px solid var(--border-color);
            padding: 1.5rem 2rem;
        }
        
        .input-group {
            display: flex;
            gap: 1rem;
            align-items: end;
        }
        
        .message-input {
            flex: 1;
            border: 2px solid var(--border-color);
            border-radius: 25px;
            padding: 12px 20px;
            resize: none;
            max-height: 120px;
            min-height: 48px;
            transition: var(--transition);
        }
        
        .message-input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 0.2rem rgba(79, 70, 229, 0.25);
            outline: none;
        }
        
        .send-btn {
            background: linear-gradient(135deg, var(--primary-color), #6366f1);
            color: white;
            border: none;
            border-radius: 50%;
            width: 48px;
            height: 48px;
            display: flex;
            align-items: center;
            justify-content: center;
            transition: var(--transition);
            flex-shrink: 0;
        }
        
        .send-btn:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79, 70, 229, 0.3);
        }
        
        .send-btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* Action Buttons */
        .chat-actions {
            display: flex;
            gap: 0.5rem;
            margin-bottom: 1rem;
        }
        
        .action-chip {
            background: var(--white);
            border: 2px solid var(--border-color);
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 0.875rem;
            cursor: pointer;
            transition: var(--transition);
        }
        
        .action-chip:hover {
            background: var(--primary-color);
            color: white;
            border-color: var(--primary-color);
        }
        
        /* Typing Indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            padding: 1rem 1.5rem;
            background: var(--white);
            border: 2px solid var(--border-color);
            border-radius: 20px;
            margin-left: 60px;
            margin-bottom: 1.5rem;
        }
        
        .typing-dots {
            display: flex;
            gap: 4px;
        }
        
        .typing-dot {
            width: 8px;
            height: 8px;
            background: var(--text-muted);
            border-radius: 50%;
            animation: typingDot 1.4s infinite;
        }
        
        .typing-dot:nth-child(2) { animation-delay: 0.2s; }
        .typing-dot:nth-child(3) { animation-delay: 0.4s; }
        
        @keyframes typingDot {
            0%, 60%, 100% { transform: translateY(0); }
            30% { transform: translateY(-10px); }
        }
        
        /* Welcome Message */
        .welcome-message {
            text-align: center;
            padding: 3rem 2rem;
            color: var(--text-muted);
        }
        
        .welcome-message i {
            font-size: 4rem;
            margin-bottom: 1rem;
            color: var(--bot-color);
        }
        
        /* Mobile Responsive */
        @media (max-width: 768px) {
            .chat-container {
                margin: 0;
                height: calc(100vh - 60px);
            }
            
            .chat-header {
                padding: 1rem;
            }
            
            .chat-messages {
                padding: 1rem;
            }
            
            .message-content {
                max-width: 85%;
            }
            
            .chat-input {
                padding: 1rem;
            }
            
            .message.bot .message-content {
                margin-left: 50px;
            }
            
            .message.user .message-content {
                margin-right: 50px;
            }
        }
        
        /* Scrollbar */
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }
        
        .chat-messages::-webkit-scrollbar-track {
            background: var(--light-bg);
        }
        
        .chat-messages::-webkit-scrollbar-thumb {
            background: var(--border-color);
            border-radius: 3px;
        }
        
        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: var(--text-muted);
        }
    </style>
</head>
<body>
    <!-- Navigation -->
    <nav class="navbar navbar-custom">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="fas fa-arrow-left me-2"></i>
                กลับหน้าหลัก
            </a>
            
            <div class="d-flex align-items-center gap-3">
                <a href="menu.php" class="btn btn-outline-primary">
                    <i class="fas fa-utensils me-2"></i>
                    <span class="d-none d-sm-inline">เมนู</span>
                </a>
                
                <a href="cart.php" class="btn btn-outline-secondary">
                    <i class="fas fa-shopping-cart me-2"></i>
                    <span class="d-none d-sm-inline">ตะกร้า</span>
                    <span id="cartBadge" class="badge bg-danger ms-1" style="display: none;">0</span>
                </a>
            </div>
        </div>
    </nav>
    
    <!-- Chat Container -->
    <div class="chat-container">
        <!-- Chat Header -->
        <div class="chat-header">
            <div class="bot-avatar">
                <i class="fas fa-robot"></i>
            </div>
            <div class="bot-info">
                <h3>AI Assistant</h3>
                <div class="bot-status">
                    <span class="status-dot"></span>
                    <span>ออนไลน์ - พร้อมช่วยเหลือคุณ</span>
                </div>
            </div>
            <div class="ms-auto">
                <button class="btn btn-light btn-sm" onclick="clearChat()">
                    <i class="fas fa-trash me-2"></i>ล้างการสนทนา
                </button>
            </div>
        </div>
        
        <!-- Chat Messages -->
        <div class="chat-messages" id="chatMessages">
            <div class="welcome-message">
                <i class="fas fa-comments"></i>
                <h4>สวัสดีค่ะ! ยินดีต้อนรับ</h4>
                <p>ฉันคือ AI Assistant ของร้าน พร้อมช่วยคุณสั่งอาหารและตอบคำถามต่างๆ</p>
                
                <div class="chat-actions">
                    <button class="action-chip" onclick="sendQuickMessage('ดูเมนูอาหาร')">
                        <i class="fas fa-utensils me-1"></i>ดูเมนู
                    </button>
                    <button class="action-chip" onclick="sendQuickMessage('แนะนำอาหารยอดนิยม')">
                        <i class="fas fa-star me-1"></i>แนะนำเมนู
                    </button>
                    <button class="action-chip" onclick="sendQuickMessage('ตรวจสอบคิว')">
                        <i class="fas fa-clock me-1"></i>ตรวจสอบคิว
                    </button>
                    <button class="action-chip" onclick="sendQuickMessage('ราคาอาหาร')">
                        <i class="fas fa-money-bill me-1"></i>ราคา
                    </button>
                </div>
            </div>
        </div>
        
        <!-- Chat Input -->
        <div class="chat-input">
            <div class="input-group">
                <textarea 
                    id="messageInput" 
                    class="message-input" 
                    placeholder="พิมพ์ข้อความ... (เช่น แนะนำอาหารหน่อย)"
                    rows="1"></textarea>
                <button id="sendBtn" class="send-btn" onclick="sendMessage()">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </div>
        </div>
    </div>
    
    <!-- Scripts -->
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    
    <script>
        const SITE_URL = '<?php echo SITE_URL; ?>';
        const CHATBOT_SESSION_ID = '<?php echo $chatbotSessionId; ?>';
        
        // Initialize chat
        let messageHistory = [];
        let isTyping = false;
        
        // Quick messages templates
        const quickReplies = {
            'menu': ['ข้าวผัด', 'ก๋วยเตี๋ยว', 'เครื่องดื่ม', 'ของหวาน', 'ดูเมนูทั้งหมด'],
            'recommendation': ['อาหารยอดนิยม', 'ราคาประหยัด', 'อาหารใหม่', 'เมนูแนะนำ'],
            'help': ['วิธีการสั่ง', 'การชำระเงิน', 'เวลาเตรียม', 'ติดต่อร้าน']
        };
        
        // Send message
        function sendMessage() {
            const input = document.getElementById('messageInput');
            const message = input.value.trim();
            
            if (!message || isTyping) return;
            
            // Add user message to chat
            addMessage('user', message);
            input.value = '';
            adjustTextareaHeight(input);
            
            // Show typing indicator
            showTypingIndicator();
            
            // Send to AI
            sendToAI(message);
        }
        
        // Send quick message
        function sendQuickMessage(message) {
            document.getElementById('messageInput').value = message;
            sendMessage();
        }
        
        // Add message to chat
        function addMessage(type, content, options = {}) {
            const messagesContainer = document.getElementById('chatMessages');
            const messageElement = document.createElement('div');
            messageElement.className = `message ${type}`;
            
            const time = new Date().toLocaleTimeString('th-TH', { 
                hour: '2-digit', 
                minute: '2-digit' 
            });
            
            if (type === 'user') {
                messageElement.innerHTML = `
                    <div class="message-avatar">
                        <i class="fas fa-user"></i>
                    </div>
                    <div class="message-content">
                        ${content}
                        <div class="message-time">${time}</div>
                    </div>
                `;
            } else {
                messageElement.innerHTML = `
                    <div class="message-avatar">
                        <i class="fas fa-robot"></i>
                    </div>
                    <div class="message-content">
                        ${content}
                        <div class="message-time">${time}</div>
                        ${options.quickReplies ? createQuickReplies(options.quickReplies) : ''}
                    </div>
                `;
            }
            
            // Remove welcome message if exists
            const welcomeMsg = messagesContainer.querySelector('.welcome-message');
            if (welcomeMsg) {
                welcomeMsg.remove();
            }
            
            messagesContainer.appendChild(messageElement);
            scrollToBottom();
            
            // Store in history
            messageHistory.push({
                type: type,
                content: content,
                timestamp: new Date().toISOString()
            });
        }
        
        // Create quick replies
        function createQuickReplies(replies) {
            if (!replies || replies.length === 0) return '';
            
            const repliesHtml = replies.map(reply => 
                `<button class="quick-reply" onclick="sendQuickMessage('${reply}')">${reply}</button>`
            ).join('');
            
            return `<div class="quick-replies">${repliesHtml}</div>`;
        }
        
        // Show typing indicator
        function showTypingIndicator() {
            if (isTyping) return;
            
            isTyping = true;
            const messagesContainer = document.getElementById('chatMessages');
            const typingElement = document.createElement('div');
            typingElement.className = 'typing-indicator';
            typingElement.id = 'typingIndicator';
            typingElement.innerHTML = `
                <div class="typing-dots">
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                    <div class="typing-dot"></div>
                </div>
                <span>AI กำลังพิมพ์...</span>
            `;
            
            messagesContainer.appendChild(typingElement);
            scrollToBottom();
        }
        
        // Hide typing indicator
        function hideTypingIndicator() {
            const typingElement = document.getElementById('typingIndicator');
            if (typingElement) {
                typingElement.remove();
            }
            isTyping = false;
        }
        
        // Send to AI
        function sendToAI(message) {
            // Simulate AI processing time
            const processingTime = Math.random() * 2000 + 1000; // 1-3 seconds
            
            setTimeout(() => {
                hideTypingIndicator();
                
                // Get AI response
                const response = getAIResponse(message);
                addMessage('bot', response.content, response.options);
                
            }, processingTime);
        }
        
        // Get AI response (simplified simulation)
        function getAIResponse(message) {
            const msg = message.toLowerCase();
            
            // Menu related
            if (msg.includes('เมนู') || msg.includes('อาหาร') || msg.includes('ขาย')) {
                return {
                    content: `🍽️ เรามีเมนูอาหารหลากหลายให้เลือก:<br><br>
                        • <strong>อาหารจานเดียว</strong> - ข้าวผัด ข้าวคลุกกะปิ<br>
                        • <strong>ก๋วยเตี๋ยว</strong> - น้ำใส น้ำตก ต้มยำ<br>
                        • <strong>ข้าวราดแกง</strong> - แกงเขียวหวาน แกงเผ็ด<br>
                        • <strong>เครื่องดื่ม</strong> - กาแฟ ชา น้ำผลไม้<br>
                        • <strong>ของหวาน</strong> - ไอศกรีม ขนมไทย<br><br>
                        ต้องการดูรายละเอียดหมวดไหนเป็นพิเศษไหมคะ?`,
                    options: {
                        quickReplies: ['ข้าวผัด', 'ก๋วยเตี๋ยว', 'เครื่องดื่ม', 'ของหวาน', 'ดูเมนูทั้งหมด']
                    }
                };
            }
            
            // Popular items
            if (msg.includes('แนะนำ') || msg.includes('ยอดนิยม') || msg.includes('ขายดี')) {
                return {
                    content: `⭐ เมนูยอดนิยมของเรา:<br><br>
                        🥇 <strong>ข้าวผัดหมู</strong> - ฿45<br>
                        🥈 <strong>ก๋วยเตี๋ยวหมูน้ำใส</strong> - ฿40<br>
                        🥉 <strong>กาแฟเย็น</strong> - ฿35<br><br>
                        เมนูเหล่านี้เป็นที่ชื่นชอบของลูกค้ามากที่สุดค่ะ ต้องการเพิ่มลงตะกร้าไหมคะ?`,
                    options: {
                        quickReplies: ['เพิ่มข้าวผัดหมู', 'เพิ่มก๋วยเตี๋ยว', 'เพิ่มกาแฟเย็น', 'ดูเมนูอื่น']
                    }
                };
            }
            
            // Queue checking
            if (msg.includes('คิว') || msg.includes('รอ') || msg.includes('สถานะ')) {
                return {
                    content: `🕐 ตรวจสอบสถานะคิว:<br><br>
                        คุณสามารถตรวจสอบสถานะคิวได้ง่ายๆ โดยใส่หมายเลขคิวของคุณ<br><br>
                        หมายเลขคิวจะขึ้นต้นด้วย "Q" ตามด้วยตัวเลข เช่น Q2507270001<br><br>
                        <a href="queue_status.php" target="_blank">👆 คลิกที่นี่เพื่อตรวจสอบคิว</a>`,
                    options: {
                        quickReplies: ['วิธีดูคิว', 'เวลารอโดยเฉลี่ย', 'แจ้งเตือนคิว']
                    }
                };
            }
            
            // Price inquiry
            if (msg.includes('ราคา') || msg.includes('เท่าไหร่') || msg.includes('บาท')) {
                return {
                    content: `💰 ช่วงราคาของเรา:<br><br>
                        • <strong>อาหารจานเดียว:</strong> ฿35-65<br>
                        • <strong>ก๋วยเตี๋ยว:</strong> ฿40-55<br>
                        • <strong>ข้าวราดแกง:</strong> ฿45-50<br>
                        • <strong>เครื่องดื่ม:</strong> ฿10-35<br>
                        • <strong>ของหวาน:</strong> ฿25-35<br><br>
                        ต้องการทราบราคาเมนูไหนเป็นพิเศษไหมคะ?`,
                    options: {
                        quickReplies: ['ราคาข้าวผัด', 'ราคาก๋วยเตี๋ยว', 'ราคาเครื่องดื่ม', 'ดูราคาทั้งหมด']
                    }
                };
            }
            
            // Ordering help
            if (msg.includes('สั่ง') || msg.includes('วิธี') || msg.includes('ช่วย')) {
                return {
                    content: `📝 วิธีการสั่งอาหาร:<br><br>
                        1️⃣ เลือกเมนูจากหมวดหมู่ต่างๆ<br>
                        2️⃣ เพิ่มสินค้าลงตะกร้า<br>
                        3️⃣ ตรวจสอบรายการในตะกร้า<br>
                        4️⃣ ดำเนินการชำระเงิน<br>
                        5️⃣ รับหมายเลขคิวและรอรับอาหาร<br><br>
                        มีอะไรให้ช่วยเพิ่มเติมไหมคะ?`,
                    options: {
                        quickReplies: ['เริ่มสั่งอาหาร', 'วิธีชำระเงิน', 'ตรวจสอบตะกร้า', 'ติดต่อร้าน']
                    }
                };
            }
            
            // Greetings
            if (msg.includes('สวัสดี') || msg.includes('หวัดดี') || msg.includes('ดี') || msg.includes('hello')) {
                const greetings = [
                    'สวัสดีค่ะ! ยินดีต้อนรับสู่ร้านของเรา 😊',
                    'หวัดดีค่ะ! มีอะไรให้ช่วยเหลือไหมคะ? 🙏',
                    'สวัสดีค่ะ! พร้อมแนะนำเมนูอร่อยๆ ให้คุณเลยค่ะ 🍽️'
                ];
                
                return {
                    content: greetings[Math.floor(Math.random() * greetings.length)],
                    options: {
                        quickReplies: ['ดูเมนู', 'แนะนำอาหาร', 'ตรวจสอบคิว', 'ถามราคา']
                    }
                };
            }
            
            // Default response
            return {
                content: `ขออภัยค่ะ ฉันไม่เข้าใจคำถามของคุณ 😅<br><br>
                    คุณสามารถ:<br>
                    • ถามเกี่ยวกับเมนูอาหาร<br>
                    • ขอแนะนำอาหารยอดนิยม<br>
                    • ตรวจสอบสถานะคิว<br>
                    • สอบถามราคาอาหาร<br>
                    • ขอความช่วยเหลือการสั่งอาหาร<br><br>
                    ลองพิมพ์คำถามใหม่ดูนะคะ 🤗`,
                options: {
                    quickReplies: ['ดูเมนู', 'แนะนำอาหาร', 'ช่วยเหลือ', 'ติดต่อร้าน']
                }
            };
        }
        
        // Scroll to bottom
        function scrollToBottom() {
            const messagesContainer = document.getElementById('chatMessages');
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // Auto-resize textarea
        function adjustTextareaHeight(element) {
            element.style.height = 'auto';
            element.style.height = (element.scrollHeight) + 'px';
        }
        
        // Clear chat
        function clearChat() {
            Swal.fire({
                title: 'ล้างการสนทนา?',
                text: 'ข้อความทั้งหมดจะถูกลบ',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#ef4444',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'ล้าง',
                cancelButtonText: 'ยกเลิก'
            }).then((result) => {
                if (result.isConfirmed) {
                    const messagesContainer = document.getElementById('chatMessages');
                    messagesContainer.innerHTML = `
                        <div class="welcome-message">
                            <i class="fas fa-comments"></i>
                            <h4>เริ่มการสนทนาใหม่</h4>
                            <p>พิมพ์ข้อความเพื่อเริ่มแชทกับ AI Assistant</p>
                            
                            <div class="chat-actions">
                                <button class="action-chip" onclick="sendQuickMessage('ดูเมนูอาหาร')">
                                    <i class="fas fa-utensils me-1"></i>ดูเมนู
                                </button>
                                <button class="action-chip" onclick="sendQuickMessage('แนะนำอาหารยอดนิยม')">
                                    <i class="fas fa-star me-1"></i>แนะนำเมนู
                                </button>
                                <button class="action-chip" onclick="sendQuickMessage('ตรวจสอบคิว')">
                                    <i class="fas fa-clock me-1"></i>ตรวจสอบคิว
                                </button>
                                <button class="action-chip" onclick="sendQuickMessage('ราคาอาหาร')">
                                    <i class="fas fa-money-bill me-1"></i>ราคา
                                </button>
                            </div>
                        </div>
                    `;
                    messageHistory = [];
                }
            });
        }
        
        // Add to cart from chat
        function addToCartFromChat(productId, productName, price) {
            $.ajax({
                url: 'api/cart.php',
                type: 'POST',
                data: {
                    action: 'add',
                    product_id: productId,
                    quantity: 1
                },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        updateCartBadge(response.cart_count);
                        addMessage('bot', `✅ เพิ่ม "${productName}" ลงตะกร้าแล้วค่ะ!<br><br>ต้องการสั่งอะไรเพิ่มไหมคะ?`, {
                            quickReplies: ['ดูตะกร้า', 'สั่งเมนูอื่น', 'ชำระเงิน', 'เสร็จแล้ว']
                        });
                    } else {
                        addMessage('bot', `❌ ขออภัยค่ะ ไม่สามารถเพิ่มสินค้าได้: ${response.message}`);
                    }
                },
                error: function() {
                    addMessage('bot', '❌ เกิดข้อผิดพลาดในการเพิ่มสินค้า กรุณาลองใหม่อีกครั้งค่ะ');
                }
            });
        }
        
        // Update cart badge
        function updateCartBadge(count) {
            const badge = document.getElementById('cartBadge');
            if (count > 0) {
                badge.textContent = count;
                badge.style.display = 'inline';
            } else {
                badge.style.display = 'none';
            }
        }
        
        // Load cart count on page load
        function loadCartCount() {
            $.get('api/cart.php?action=count', function(response) {
                if (response.success) {
                    updateCartBadge(response.count);
                }
            }).fail(function() {
                console.warn('Failed to load cart count');
            });
        }
        
        // Initialize page
        $(document).ready(function() {
            // Auto-resize textarea
            const messageInput = document.getElementById('messageInput');
            messageInput.addEventListener('input', function() {
                adjustTextareaHeight(this);
            });
            
            // Send message on Enter (but not Shift+Enter)
            messageInput.addEventListener('keydown', function(e) {
                if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    sendMessage();
                }
            });
            
            // Load cart count
            loadCartCount();
            
            console.log('Chatbot page loaded successfully');
        });
    </script>
</body>
</html>