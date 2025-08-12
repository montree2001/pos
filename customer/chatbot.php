<?php
/**
 * Chatbot with Deepseek API Integration
 * Smart Order Management System
 */

define('SYSTEM_INIT', true);
require_once '../config/config.php';
require_once '../config/database.php';
require_once '../config/session.php';
require_once '../includes/functions.php';

// Check if customer is logged in (optional for chatbot)
$customerId = $_SESSION['customer_id'] ?? null;

// Generate unique session ID for chatbot
$chatbotSessionId = 'chatbot_' . session_id() . '_' . time();

$pageTitle = 'AI Assistant';
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $pageTitle . ' - ' . SITE_NAME; ?></title>
    
    <!-- CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/animate.css/4.1.1/animate.min.css" rel="stylesheet">
    <link href="../assets/css/customer.css" rel="stylesheet">
    
    <style>
        :root {
            --primary-color: #4f46e5;
            --secondary-color: #10b981;
            --accent-color: #f59e0b;
            --text-dark: #1f2937;
            --text-muted: #6b7280;
            --border-color: #e5e7eb;
            --chat-bg: #f8fafc;
            --white: #ffffff;
            --gradient-primary: linear-gradient(135deg, #4f46e5, #7c3aed);
            --gradient-secondary: linear-gradient(135deg, #10b981, #059669);
            --transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
            --shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            --shadow-lg: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04);
        }
        
        body {
            background: var(--chat-bg);
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, sans-serif;
            margin: 0;
            padding: 0;
            height: 100vh;
            overflow: hidden;
        }
        
        /* Chat Container */
        .chat-container {
            height: 100vh;
            display: flex;
            flex-direction: column;
            max-width: 100%;
            margin: 0 auto;
            background: var(--white);
            box-shadow: var(--shadow-lg);
        }
        
        /* Chat Header */
        .chat-header {
            background: var(--gradient-primary);
            color: white;
            padding: 1.5rem 2rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-shrink: 0;
        }
        
        .chat-header h1 {
            margin: 0;
            font-size: 1.5rem;
            font-weight: 700;
        }
        
        .header-actions {
            display: flex;
            gap: 1rem;
        }
        
        .header-btn {
            background: rgba(255, 255, 255, 0.2);
            border: none;
            color: white;
            border-radius: 12px;
            padding: 8px 12px;
            transition: var(--transition);
            backdrop-filter: blur(10px);
        }
        
        .header-btn:hover {
            background: rgba(255, 255, 255, 0.3);
            transform: translateY(-1px);
        }
        
        /* Chat Messages */
        .chat-messages {
            flex: 1;
            overflow-y: auto;
            padding: 2rem;
            background: var(--chat-bg);
            scroll-behavior: smooth;
        }
        
        .welcome-message {
            text-align: center;
            padding: 3rem 2rem;
            background: var(--white);
            border-radius: 20px;
            box-shadow: var(--shadow);
            margin-bottom: 2rem;
            animation: fadeInUp 0.6s ease-out;
        }
        
        .welcome-message i {
            font-size: 3rem;
            color: var(--primary-color);
            margin-bottom: 1rem;
        }
        
        .welcome-message h4 {
            color: var(--text-dark);
            font-weight: 700;
            margin-bottom: 0.5rem;
        }
        
        .welcome-message p {
            color: var(--text-muted);
            margin-bottom: 2rem;
        }
        
        /* Message Bubbles */
        .message {
            display: flex;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease-out;
        }
        
        .message.user {
            justify-content: flex-end;
        }
        
        .message-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 0.75rem;
            flex-shrink: 0;
        }
        
        .message.user .message-avatar {
            background: var(--gradient-primary);
            color: white;
        }
        
        .message.bot .message-avatar {
            background: var(--gradient-secondary);
            color: white;
        }
        
        .message-content {
            max-width: 70%;
            background: var(--white);
            border-radius: 18px;
            padding: 1rem 1.25rem;
            box-shadow: var(--shadow);
            position: relative;
        }
        
        .message.user .message-content {
            background: var(--gradient-primary);
            color: white;
        }
        
        .message-time {
            font-size: 0.75rem;
            opacity: 0.6;
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
            background: rgba(79, 70, 229, 0.1);
            color: var(--primary-color);
            border: 1px solid rgba(79, 70, 229, 0.3);
            border-radius: 20px;
            padding: 6px 12px;
            font-size: 0.875rem;
            transition: var(--transition);
            cursor: pointer;
        }
        
        .quick-reply:hover {
            background: var(--primary-color);
            color: white;
            transform: translateY(-1px);
        }
        
        /* Typing Indicator */
        .typing-indicator {
            display: flex;
            align-items: center;
            margin-bottom: 1.5rem;
            animation: fadeInUp 0.4s ease-out;
        }
        
        .typing-dots {
            display: flex;
            gap: 4px;
            margin-right: 0.75rem;
        }
        
        .typing-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--text-muted);
            animation: typingDot 1.4s infinite ease-in-out;
        }
        
        .typing-dot:nth-child(1) { animation-delay: -0.32s; }
        .typing-dot:nth-child(2) { animation-delay: -0.16s; }
        
        @keyframes typingDot {
            0%, 80%, 100% { transform: scale(0); opacity: 0.5; }
            40% { transform: scale(1); opacity: 1; }
        }
        
        /* Action Chips */
        .chat-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: center;
        }
        
        .action-chip {
            background: var(--white);
            border: 2px solid var(--border-color);
            color: var(--text-dark);
            border-radius: 25px;
            padding: 10px 16px;
            font-weight: 500;
            transition: var(--transition);
            cursor: pointer;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .action-chip:hover {
            border-color: var(--primary-color);
            color: var(--primary-color);
            transform: translateY(-2px);
            box-shadow: var(--shadow);
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
        
        /* Cart Badge */
        .cart-badge {
            position: absolute;
            top: -8px;
            right: -8px;
            background: var(--accent-color);
            color: white;
            border-radius: 50%;
            width: 20px;
            height: 20px;
            font-size: 0.75rem;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .chat-header {
                padding: 1rem 1.5rem;
            }
            
            .chat-header h1 {
                font-size: 1.25rem;
            }
            
            .chat-messages {
                padding: 1.5rem;
            }
            
            .chat-input {
                padding: 1rem 1.5rem;
            }
            
            .message-content {
                max-width: 85%;
            }
            
            .welcome-message {
                padding: 2rem 1.5rem;
            }
            
            .action-chip {
                font-size: 0.875rem;
                padding: 8px 12px;
            }
        }
        
        /* Animations */
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Scrollbar */
        .chat-messages::-webkit-scrollbar {
            width: 6px;
        }
        
        .chat-messages::-webkit-scrollbar-track {
            background: var(--border-color);
            border-radius: 3px;
        }
        
        .chat-messages::-webkit-scrollbar-thumb {
            background: var(--text-muted);
            border-radius: 3px;
        }
        
        .chat-messages::-webkit-scrollbar-thumb:hover {
            background: var(--text-dark);
        }
    </style>
</head>
<body>
    <div class="chat-container">
        <!-- Chat Header -->
        <div class="chat-header">
            <div class="d-flex align-items-center">
                <i class="fas fa-robot me-3" style="font-size: 1.5rem;"></i>
                <div>
                    <h1>AI Assistant</h1>
                    <small style="opacity: 0.8;">ยินดีให้บริการ 24/7</small>
                </div>
            </div>
            
            <div class="header-actions">
                <button class="header-btn" onclick="clearChat()" title="ล้างการสนทนา">
                    <i class="fas fa-trash"></i>
                </button>
                <a href="index.php" class="header-btn" title="กลับหน้าหลัก">
                    <i class="fas fa-home"></i>
                </a>
                <a href="cart.php" class="header-btn position-relative" title="ตะกร้าสินค้า">
                    <i class="fas fa-shopping-cart"></i>
                    <span id="cartBadge" class="cart-badge" style="display: none;">0</span>
                </a>
            </div>
        </div>
        
        <!-- Chat Messages -->
        <div class="chat-messages" id="chatMessages">
            <div class="welcome-message">
                <i class="fas fa-comments"></i>
                <h4>👋 ยินดีต้อนรับ</h4>
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
                <div class="message-avatar">
                    <i class="fas fa-robot"></i>
                </div>
                <div class="message-content">
                    <div class="typing-dots">
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                        <div class="typing-dot"></div>
                    </div>
                    <span>AI กำลังพิมพ์...</span>
                </div>
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
        
        // Send to Deepseek AI
        function sendToAI(message) {
            // เตรียมข้อมูล context สำหรับ AI
            const systemMessage = `คุณคือ AI Assistant ของร้านอาหาร ชื่อว่า "Smart Order Assistant" 
คุณสามารถ:
- แนะนำเมนูอาหาร
- ตอบคำถามเกี่ยวกับราคา เวลาเตรียม
- ช่วยลูกค้าสั่งอาหาร
- แจ้งสถานะคิว
- ให้ข้อมูลการชำระเงิน

ตอบเป็นภาษาไทยเท่านั้น พูดจาสุภาพและเป็นมิตร ใช้อีโมจิให้เหมาะสม`;

            // สร้าง messages array สำหรับ API
            const messages = [
                {
                    "role": "system",
                    "content": systemMessage
                }
            ];

            // เพิ่มประวัติการสนทนา (เก็บเฉพาะ 10 ข้อความล่าสุด)
            const recentHistory = messageHistory.slice(-10);
            recentHistory.forEach(item => {
                if (item.type === 'user') {
                    messages.push({
                        "role": "user",
                        "content": item.content
                    });
                } else if (item.type === 'bot') {
                    messages.push({
                        "role": "assistant", 
                        "content": item.content.replace(/<[^>]*>/g, '') // ลบ HTML tags
                    });
                }
            });

            // เพิ่มข้อความปัจจุบัน
            messages.push({
                "role": "user",
                "content": message
            });

            // เรียก API Deepseek
            fetch('https://api.deepseek.com/chat/completions', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Authorization': 'Bearer sk-18e5b31f78cc416fb0e68175c5a0ce76'
                },
                body: JSON.stringify({
                    model: 'deepseek-chat',
                    messages: messages,
                    max_tokens: 1000,
                    temperature: 0.7,
                    stream: false
                })
            })
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                hideTypingIndicator();
                
                if (data.choices && data.choices.length > 0) {
                    const aiResponse = data.choices[0].message.content;
                    
                    // ประมวลผลคำตอบและสร้าง quick replies
                    const processedResponse = processAIResponse(aiResponse, message);
                    addMessage('bot', processedResponse.content, processedResponse.options);
                    
                } else {
                    // Fallback response
                    addMessage('bot', 'ขออภัยค่ะ เกิดข้อผิดพลาดในการประมวลผล กรุณาลองใหม่อีกครั้งค่ะ 🙏');
                }
            })
            .catch(error => {
                console.error('Deepseek API Error:', error);
                hideTypingIndicator();
                
                // Fallback to local response in case of API error
                const fallbackResponse = getFallbackResponse(message);
                addMessage('bot', fallbackResponse.content, fallbackResponse.options);
            });
        }

        // ประมวลผลคำตอบจาก AI และเพิ่ม quick replies
        function processAIResponse(aiResponse, userMessage) {
            const msg = userMessage.toLowerCase();
            let quickReplies = [];
            
            // กำหนด quick replies ตามบริบท
            if (msg.includes('เมนู') || msg.includes('อาหาร')) {
                quickReplies = ['ข้าวผัด', 'ก๋วยเตี๋ยว', 'เครื่องดื่ม', 'ของหวาน'];
            } else if (msg.includes('ราคา') || msg.includes('เท่าไหร่')) {
                quickReplies = ['ดูเมนูทั้งหมด', 'แนะนำอาหารราคาประหยัด', 'อาหารยอดนิยม'];
            } else if (msg.includes('คิว') || msg.includes('รอ')) {
                quickReplies = ['ตรวจสอบคิว', 'เวลาเตรียมอาหาร', 'ติดต่อร้าน'];
            } else if (msg.includes('สั่ง') || msg.includes('ซื้อ')) {
                quickReplies = ['ดูตะกร้า', 'เพิ่มเมนู', 'ชำระเงิน'];
            } else {
                quickReplies = ['ดูเมนู', 'ตรวจสอบคิว', 'ติดต่อร้าน', 'ช่วยเหลือ'];
            }
            
            return {
                content: aiResponse,
                options: {
                    quickReplies: quickReplies
                }
            };
        }

        // ฟังก์ชัน fallback เมื่อ API มีปัญหา
        function getFallbackResponse(message) {
            const msg = message.toLowerCase();
            
            // Menu related
            if (msg.includes('เมนู') || msg.includes('อาหาร') || msg.includes('ขาย')) {
                return {
                    content: `🍽️ เรามีเมนูอาหารหลากหลายให้เลือก:<br><br>
                        • <strong>อาหารจานเดียว</strong> - ข้าวผัด ข้าวคลุกกะปิ<br>
                        • <strong>ก๋วยเตี๋ยว</strong> - น้ำใส น้ำตก ต้มยำ<br>
                        • <strong>ข้าวราดแกง</strong> - แกงเขียวหวาน แกงเผ็ด<br>
                        • <strong>เครื่องดื่ม</strong> - กาแฟ ชา น้ำผลไม้<br><br>
                        ต้องการดูรายละเอียดเมนูไหนคะ? 😊`,
                    options: {
                        quickReplies: ['ข้าวผัด', 'ก๋วยเตี๋ยว', 'เครื่องดื่ม', 'ของหวาน', 'ดูเมนูทั้งหมด']
                    }
                };
            }
            
            // Price related
            if (msg.includes('ราคา') || msg.includes('เท่าไหร่') || msg.includes('กี่บาท')) {
                return {
                    content: `💰 ราคาอาหารของเรา:<br><br>
                        • ข้าวผัด: 40-60 บาท<br>
                        • ก๋วยเตี๋ยว: 35-55 บาท<br>
                        • ข้าวราดแกง: 30-50 บาท<br>
                        • เครื่องดื่ม: 15-35 บาท<br><br>
                        ต้องการทราบราคาเมนูใดเป็นพิเศษคะ? 🤔`,
                    options: {
                        quickReplies: ['ข้าวผัด', 'ก๋วยเตี๋ยว', 'แกง', 'เครื่องดื่ม']
                    }
                };
            }
            
            // Queue related
            if (msg.includes('คิว') || msg.includes('รอ') || msg.includes('นาน')) {
                return {
                    content: `⏰ สถานะคิวปัจจุบัน:<br><br>
                        📊 คิวที่กำลังเตรียม: 15<br>
                        ⏳ เวลารอโดยประมาณ: 12-15 นาที<br>
                        🎯 คิวถัดไป: 16, 17, 18<br><br>
                        ต้องการตรวจสอบคิวของคุณไหมคะ? กรุณาแจ้งหมายเลขออเดอร์ค่ะ 📱`,
                    options: {
                        quickReplies: ['ตรวจสอบคิว', 'เวลาเตรียม', 'ติดต่อร้าน']
                    }
                };
            }
            
            // Ordering
            if (msg.includes('สั่ง') || msg.includes('ซื้อ') || msg.includes('เอา')) {
                return {
                    content: `🛒 วิธีการสั่งอาหาร:<br><br>
                        1. เลือกเมนูจากรายการ<br>
                        2. เพิ่มลงตะกร้า<br>
                        3. ตรวจสอบรายการ<br>
                        4. ชำระเงิน<br>
                        5. รอรับอาหารตามคิว<br><br>
                        เริ่มสั่งอาหารเลยไหมคะ? 😋`,
                    options: {
                        quickReplies: ['ดูเมนู', 'วิธีชำระเงิน', 'ติดต่อร้าน']
                    }
                };
            }
            
            // Default response
            return {
                content: `สวัสดีค่ะ! 👋 ยินดีให้บริการครับ<br><br>
                    ฉันสามารถช่วยคุณได้เรื่อง:<br>
                    🍽️ แนะนำเมนูอาหาร<br>
                    💰 สอบถามราคา<br>
                    ⏰ ตรวจสอบคิว<br>
                    🛒 วิธีการสั่งซื้อ<br><br>
                    มีอะไรให้ช่วยไหมคะ? 😊`,
                options: {
                    quickReplies: ['ดูเมนู', 'ตรวจสอบคิว', 'ราคาอาหาร', 'วิธีสั่งซื้อ']
                }
            };
        }
        
        // Scroll to bottom
        function scrollToBottom() {
            const messagesContainer = document.getElementById('chatMessages');
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }
        
        // Auto-resize textarea
        function adjustTextareaHeight(textarea) {
            textarea.style.height = 'auto';
            textarea.style.height = Math.min(textarea.scrollHeight, 120) + 'px';
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
            
            console.log('Chatbot page loaded successfully with Deepseek API integration');
        });
    </script>
</body>
</html>