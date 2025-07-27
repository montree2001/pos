<?php
/**
 * การตั้งค่า LINE Official Account
 * Smart Order Management System
 */

// การตั้งค่า LINE Messaging API
define('LINE_CHANNEL_ACCESS_TOKEN', ''); // ใส่ Channel Access Token
define('LINE_CHANNEL_SECRET', ''); // ใส่ Channel Secret
define('LINE_WEBHOOK_URL', SITE_URL . '/api/line_webhook.php');

// การตั้งค่า LINE Pay (ถ้าใช้)
define('LINE_PAY_CHANNEL_ID', '');
define('LINE_PAY_CHANNEL_SECRET', '');
define('LINE_PAY_SANDBOX', true); // เปลี่ยนเป็น false เมื่อใช้งานจริง

/**
 * คลาสจัดการ LINE Bot
 */
class LineBot {
    private $channelAccessToken;
    private $channelSecret;
    
    public function __construct() {
        $this->channelAccessToken = LINE_CHANNEL_ACCESS_TOKEN;
        $this->channelSecret = LINE_CHANNEL_SECRET;
    }
    
    /**
     * ส่งข้อความแบบ Text
     */
    public function sendTextMessage($userId, $message) {
        $data = [
            'to' => $userId,
            'messages' => [
                [
                    'type' => 'text',
                    'text' => $message
                ]
            ]
        ];
        
        return $this->sendMessage($data);
    }
    
    /**
     * ส่งข้อความแบบ Flex Message
     */
    public function sendFlexMessage($userId, $altText, $flexContent) {
        $data = [
            'to' => $userId,
            'messages' => [
                [
                    'type' => 'flex',
                    'altText' => $altText,
                    'contents' => $flexContent
                ]
            ]
        ];
        
        return $this->sendMessage($data);
    }
    
    /**
     * ส่งรูปภาพ
     */
    public function sendImageMessage($userId, $originalUrl, $previewUrl = null) {
        if (!$previewUrl) {
            $previewUrl = $originalUrl;
        }
        
        $data = [
            'to' => $userId,
            'messages' => [
                [
                    'type' => 'image',
                    'originalContentUrl' => $originalUrl,
                    'previewImageUrl' => $previewUrl
                ]
            ]
        ];
        
        return $this->sendMessage($data);
    }
    
    /**
     * ส่งข้อความยืนยันการสั่งซื้อ
     */
    public function sendOrderConfirmation($userId, $orderData) {
        $message = "✅ ยืนยันการสั่งซื้อ\n\n";
        $message .= "หมายเลขออเดอร์: " . $orderData['order_id'] . "\n";
        $message .= "หมายเลขคิว: " . $orderData['queue_number'] . "\n";
        $message .= "จำนวนเงิน: " . formatCurrency($orderData['total_price']) . "\n";
        $message .= "เวลาโดยประมาณ: " . $orderData['estimated_time'] . " นาที\n\n";
        $message .= "ขอบคุณที่ใช้บริการครับ 🙏";
        
        return $this->sendTextMessage($userId, $message);
    }
    
    /**
     * ส่งข้อความแจ้งสถานะคิว
     */
    public function sendQueueUpdate($userId, $queueData) {
        $message = "🔔 อัปเดตสถานะคิว\n\n";
        $message .= "หมายเลขคิว: " . $queueData['queue_number'] . "\n";
        
        switch ($queueData['status']) {
            case 'preparing':
                $message .= "สถานะ: กำลังเตรียม 👨‍🍳\n";
                break;
            case 'ready':
                $message .= "สถานะ: พร้อมเสิร์ฟ ✅\n";
                $message .= "กรุณาเข้ารับออเดอร์ที่เคาน์เตอร์";
                break;
            case 'near_turn':
                $message .= "สถานะ: ใกล้ถึงคิวแล้ว ⏰\n";
                $message .= "อีก " . $queueData['remaining_queue'] . " คิว จะถึงคิวของคุณ";
                break;
        }
        
        return $this->sendTextMessage($userId, $message);
    }
    
    /**
     * ส่งใบเสร็จ
     */
    public function sendReceipt($userId, $receiptData) {
        // สร้าง Flex Message สำหรับใบเสร็จ
        $flexContent = $this->createReceiptFlexMessage($receiptData);
        
        return $this->sendFlexMessage($userId, 'ใบเสร็จการสั่งซื้อ', $flexContent);
    }
    
    /**
     * สร้าง Flex Message สำหรับใบเสร็จ
     */
    private function createReceiptFlexMessage($receiptData) {
        $items = [];
        foreach ($receiptData['items'] as $item) {
            $items[] = [
                'type' => 'box',
                'layout' => 'horizontal',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => $item['name'],
                        'size' => 'sm',
                        'flex' => 0
                    ],
                    [
                        'type' => 'text',
                        'text' => 'x' . $item['quantity'],
                        'size' => 'sm',
                        'align' => 'end'
                    ],
                    [
                        'type' => 'text',
                        'text' => formatCurrency($item['subtotal']),
                        'size' => 'sm',
                        'align' => 'end'
                    ]
                ]
            ];
        }
        
        return [
            'type' => 'bubble',
            'header' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => [
                    [
                        'type' => 'text',
                        'text' => 'ใบเสร็จการสั่งซื้อ',
                        'weight' => 'bold',
                        'size' => 'xl',
                        'align' => 'center'
                    ]
                ]
            ],
            'body' => [
                'type' => 'box',
                'layout' => 'vertical',
                'contents' => array_merge([
                    [
                        'type' => 'text',
                        'text' => 'หมายเลขออเดอร์: ' . $receiptData['order_id'],
                        'size' => 'sm'
                    ],
                    [
                        'type' => 'text',
                        'text' => 'วันที่: ' . formatDate($receiptData['created_at']),
                        'size' => 'sm'
                    ],
                    [
                        'type' => 'separator',
                        'margin' => 'md'
                    ]
                ], $items, [
                    [
                        'type' => 'separator',
                        'margin' => 'md'
                    ],
                    [
                        'type' => 'box',
                        'layout' => 'horizontal',
                        'contents' => [
                            [
                                'type' => 'text',
                                'text' => 'รวมทั้งสิ้น',
                                'weight' => 'bold'
                            ],
                            [
                                'type' => 'text',
                                'text' => formatCurrency($receiptData['total_price']),
                                'weight' => 'bold',
                                'align' => 'end'
                            ]
                        ]
                    ]
                ])
            ]
        ];
    }
    
    /**
     * ส่งข้อความผ่าน LINE API
     */
    private function sendMessage($data) {
        $url = 'https://api.line.me/v2/bot/message/push';
        
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $this->channelAccessToken
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // บันทึก Log
        $logData = [
            'url' => $url,
            'data' => $data,
            'result' => $result,
            'http_code' => $httpCode
        ];
        writeLog('LINE API Request: ' . json_encode($logData));
        
        return [
            'success' => $httpCode === 200,
            'http_code' => $httpCode,
            'result' => json_decode($result, true)
        ];
    }
    
    /**
     * ตรวจสอบ Webhook Signature
     */
    public function verifySignature($body, $signature) {
        $hash = hash_hmac('sha256', $body, $this->channelSecret, true);
        $expected = base64_encode($hash);
        
        return hash_equals($expected, $signature);
    }
    
    /**
     * ประมวลผล Webhook Event
     */
    public function handleWebhookEvents($events) {
        foreach ($events as $event) {
            switch ($event['type']) {
                case 'message':
                    $this->handleMessageEvent($event);
                    break;
                case 'follow':
                    $this->handleFollowEvent($event);
                    break;
                case 'unfollow':
                    $this->handleUnfollowEvent($event);
                    break;
            }
        }
    }
    
    /**
     * จัดการ Message Event
     */
    private function handleMessageEvent($event) {
        $userId = $event['source']['userId'];
        $messageType = $event['message']['type'];
        
        if ($messageType === 'text') {
            $text = $event['message']['text'];
            $response = $this->processTextMessage($text);
            $this->sendTextMessage($userId, $response);
        }
    }
    
    /**
     * จัดการ Follow Event
     */
    private function handleFollowEvent($event) {
        $userId = $event['source']['userId'];
        $welcomeMessage = "สวัสดีครับ! 👋\n\n";
        $welcomeMessage .= "ยินดีต้อนรับสู่ " . SITE_NAME . "\n";
        $welcomeMessage .= "คุณสามารถใช้บริการต่อไปนี้ได้:\n\n";
        $welcomeMessage .= "🍽️ สั่งอาหาร\n";
        $welcomeMessage .= "📋 ตรวจสอบคิว\n";
        $welcomeMessage .= "💰 ชำระเงิน\n";
        $welcomeMessage .= "📄 ขอใบเสร็จ\n\n";
        $welcomeMessage .= "พิมพ์ 'เมนู' เพื่อดูรายการอาหาร";
        
        $this->sendTextMessage($userId, $welcomeMessage);
    }
    
    /**
     * จัดการ Unfollow Event
     */
    private function handleUnfollowEvent($event) {
        $userId = $event['source']['userId'];
        // บันทึกว่าผู้ใช้ unfollow
        writeLog("User unfollowed: $userId");
    }
    
    /**
     * ประมวลผลข้อความ
     */
    private function processTextMessage($text) {
        $text = trim(strtolower($text));
        
        // คำสั่งพื้นฐาน
        switch ($text) {
            case 'เมนู':
            case 'menu':
                return $this->getMenuResponse();
                
            case 'คิว':
            case 'queue':
                return "กรุณาส่งหมายเลขคิวของคุณมา เช่น Q250723001";
                
            case 'ช่วยเหลือ':
            case 'help':
                return $this->getHelpResponse();
                
            default:
                // ตรวจสอบว่าเป็นหมายเลขคิวหรือไม่
                if (preg_match('/^Q\d+/', $text)) {
                    return $this->getQueueStatusResponse($text);
                }
                
                // ใช้ AI Chatbot ตอบ
                return $this->getAIChatbotResponse($text);
        }
    }
    
    /**
     * ตอบกลับรายการเมนู
     */
    private function getMenuResponse() {
        return "📋 ดูเมนูทั้งหมดได้ที่: " . SITE_URL . "/customer/menu.php\n\n" .
               "หรือพิมพ์ชื่ออาหารที่ต้องการสอบถาม เช่น 'ข้าวผัด'";
    }
    
    /**
     * ตอบกลับสถานะคิว
     */
    private function getQueueStatusResponse($queueNumber) {
        // ดึงข้อมูลคิวจากฐานข้อมูล
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("SELECT * FROM orders WHERE queue_number = ?");
            $stmt->execute([$queueNumber]);
            $order = $stmt->fetch();
            
            if ($order) {
                $message = "📋 สถานะคิว: " . $queueNumber . "\n\n";
                
                switch ($order['status']) {
                    case 'confirmed':
                        $message .= "สถานะ: รอการเตรียม ⏳\n";
                        break;
                    case 'preparing':
                        $message .= "สถานะ: กำลังเตรียม 👨‍🍳\n";
                        break;
                    case 'ready':
                        $message .= "สถานะ: พร้อมเสิร์ฟ ✅\n";
                        $message .= "กรุณาเข้ารับที่เคาน์เตอร์";
                        break;
                    case 'completed':
                        $message .= "สถานะ: เสร็จสิ้นแล้ว ✅";
                        break;
                }
                
                if ($order['estimated_ready_time']) {
                    $message .= "\nเวลาโดยประมาณ: " . formatDate($order['estimated_ready_time'], 'H:i');
                }
                
                return $message;
            } else {
                return "❌ ไม่พบหมายเลขคิว: " . $queueNumber . "\nกรุณาตรวจสอบอีกครั้ง";
            }
        } catch (Exception $e) {
            writeLog('Error getting queue status: ' . $e->getMessage());
            return "❌ เกิดข้อผิดพลาดในการตรวจสอบคิว กรุณาลองใหม่อีกครั้ง";
        }
    }
    
    /**
     * ตอบกลับข้อความช่วยเหลือ
     */
    private function getHelpResponse() {
        return "🆘 วิธีใช้งาน\n\n" .
               "📋 พิมพ์ 'เมนู' - ดูรายการอาหาร\n" .
               "🔍 พิมพ์ 'คิว' - ตรวจสอบสถานะ\n" .
               "💡 พิมพ์หมายเลขคิว - ดูสถานะคิว\n" .
               "❓ สอบถามเพิ่มเติม - พิมพ์คำถาม\n\n" .
               "📞 ติดต่อร้าน: 02-xxx-xxxx";
    }
    
    /**
     * ใช้ AI Chatbot ตอบคำถาม
     */
    private function getAIChatbotResponse($text) {
        // TODO: ผสานกับ AI Chatbot
        return "🤖 ขออภัย ยังไม่เข้าใจคำถามของคุณ\n" .
               "กรุณาพิมพ์ 'ช่วยเหลือ' เพื่อดูวิธีใช้งาน";
    }
}

/**
 * ฟังก์ชันช่วยสำหรับการใช้งาน LINE Bot
 */
function sendLineNotification($userId, $message) {
    if (empty(LINE_CHANNEL_ACCESS_TOKEN)) {
        return false;
    }
    
    $lineBot = new LineBot();
    return $lineBot->sendTextMessage($userId, $message);
}

function sendOrderConfirmationLine($userId, $orderData) {
    if (empty(LINE_CHANNEL_ACCESS_TOKEN)) {
        return false;
    }
    
    $lineBot = new LineBot();
    return $lineBot->sendOrderConfirmation($userId, $orderData);
}

function sendQueueUpdateLine($userId, $queueData) {
    if (empty(LINE_CHANNEL_ACCESS_TOKEN)) {
        return false;
    }
    
    $lineBot = new LineBot();
    return $lineBot->sendQueueUpdate($userId, $queueData);
}

function sendReceiptLine($userId, $receiptData) {
    if (empty(LINE_CHANNEL_ACCESS_TOKEN)) {
        return false;
    }
    
    $lineBot = new LineBot();
    return $lineBot->sendReceipt($userId, $receiptData);
}
?>