<?php
/**
 * ระบบตรวจสอบการชำระเงิน PromptPay
 * Smart Order Management System
 * 
 * วิธีการตรวจสอบ:
 * 1. Bank API Integration (แนะนำ)
 * 2. Payment Gateway (Omise, 2C2P)
 * 3. Slip Verification API
 * 4. Webhook Integration
 * 5. Bank Statement Parsing
 */

/**
 * 1. Integration กับ Omise Payment Gateway
 * รองรับ PromptPay และมี Webhook
 */
class OmisePromptPayPayment {
    
    private $publicKey;
    private $secretKey;
    private $webhookEndpoint;
    
    public function __construct($publicKey, $secretKey, $webhookEndpoint) {
        $this->publicKey = $publicKey;
        $this->secretKey = $secretKey;
        $this->webhookEndpoint = $webhookEndpoint;
    }
    
    /**
     * สร้าง PromptPay Charge
     */
    public function createPromptPayCharge($amount, $orderId, $description = '') {
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.omise.co/charges",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic " . base64_encode($this->secretKey . ":"),
                "Content-Type: application/x-www-form-urlencoded"
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'amount' => $amount * 100, // Omise ใช้สตางค์
                'currency' => 'THB',
                'source[type]' => 'promptpay',
                'description' => $description ?: "Order #$orderId",
                'metadata[order_id]' => $orderId,
                'return_uri' => $this->webhookEndpoint . '/return',
                'webhook_endpoints[]' => $this->webhookEndpoint . '/webhook'
            ])
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            
            return [
                'success' => true,
                'charge_id' => $data['id'],
                'amount' => $data['amount'] / 100,
                'status' => $data['status'],
                'qr_code_url' => $data['source']['scannable_code']['image']['download_uri'] ?? null,
                'expires_at' => $data['expires_at']
            ];
        }
        
        return ['success' => false, 'error' => 'Failed to create charge'];
    }
    
    /**
     * ตรวจสอบสถานะ Charge
     */
    public function getChargeStatus($chargeId) {
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://api.omise.co/charges/$chargeId",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Basic " . base64_encode($this->secretKey . ":")
            ]
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            
            return [
                'success' => true,
                'charge_id' => $data['id'],
                'status' => $data['status'], // pending, successful, failed
                'paid' => $data['paid'],
                'amount' => $data['amount'] / 100,
                'transaction_id' => $data['transaction'] ?? null,
                'paid_at' => $data['paid_at'] ?? null
            ];
        }
        
        return ['success' => false, 'error' => 'Failed to get charge status'];
    }
    
    /**
     * จัดการ Webhook
     */
    public function handleWebhook($payload, $signature) {
        // Verify webhook signature
        $computedSignature = base64_encode(hash_hmac('sha256', $payload, $this->secretKey, true));
        
        if (!hash_equals($signature, $computedSignature)) {
            return ['success' => false, 'error' => 'Invalid signature'];
        }
        
        $data = json_decode($payload, true);
        
        if ($data['key'] === 'charge.complete') {
            $charge = $data['data'];
            
            // อัปเดตสถานะในฐานข้อมูล
            $this->updatePaymentStatus($charge);
            
            return [
                'success' => true,
                'event' => 'charge.complete',
                'charge_id' => $charge['id'],
                'status' => $charge['status'],
                'order_id' => $charge['metadata']['order_id'] ?? null
            ];
        }
        
        return ['success' => true, 'event' => $data['key']];
    }
    
    private function updatePaymentStatus($charge) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            $orderId = $charge['metadata']['order_id'] ?? null;
            $status = $charge['paid'] ? 'completed' : 'failed';
            
            $stmt = $conn->prepare("
                UPDATE payments 
                SET status = ?, reference_number = ?, payment_date = NOW() 
                WHERE order_id = ? AND payment_method = 'promptpay'
            ");
            $stmt->execute([$status, $charge['id'], $orderId]);
            
            // อัปเดตสถานะออเดอร์
            if ($charge['paid']) {
                $stmt = $conn->prepare("
                    UPDATE orders 
                    SET payment_status = 'paid', status = 'confirmed' 
                    WHERE order_id = ?
                ");
                $stmt->execute([$orderId]);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Payment update error: " . $e->getMessage());
            return false;
        }
    }
}

/**
 * 2. SlipOK API Integration
 * ตรวจสอบผ่านสลิปธนาคาร
 */
class SlipOKVerification {
    
    private $apiToken;
    private $apiUrl = 'https://api.slipok.com/api/line/apikey';
    
    public function __construct($apiToken) {
        $this->apiToken = $apiToken;
    }
    
    /**
     * ตรวจสอบสลิปจากรูปภาพ
     */
    public function verifySlipFromImage($imageBase64, $expectedAmount, $promptPayId) {
        $postData = [
            'data' => $imageBase64,
            'log' => true
        ];
        
        $curl = curl_init();
        curl_setopt_array($curl, [
            CURLOPT_URL => $this->apiUrl . '/' . $this->apiToken,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($postData),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json'
            ]
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            
            if ($data['success']) {
                $slip = $data['data'];
                
                // ตรวจสอบข้อมูลสลิป
                $isValid = $this->validateSlip($slip, $expectedAmount, $promptPayId);
                
                return [
                    'success' => true,
                    'valid' => $isValid,
                    'slip_data' => $slip,
                    'amount' => $slip['amount'] ?? 0,
                    'timestamp' => $slip['transDate'] ?? null,
                    'ref_id' => $slip['transRef'] ?? null
                ];
            }
        }
        
        return ['success' => false, 'error' => 'Failed to verify slip'];
    }
    
    private function validateSlip($slip, $expectedAmount, $promptPayId) {
        // ตรวจสอบจำนวนเงิน
        if (abs(floatval($slip['amount'] ?? 0) - $expectedAmount) > 0.01) {
            return false;
        }
        
        // ตรวจสอบหมายเลข PromptPay (อาจมีหรือไม่มีก็ได้)
        // ขึ้นอยู่กับธนาคารที่แสดงข้อมูล
        
        // ตรวจสอบว่าเป็นการโอนเงินใหม่ (ไม่เกิน 5 นาที)
        if (isset($slip['transDate'])) {
            $transTime = strtotime($slip['transDate']);
            $currentTime = time();
            
            if (($currentTime - $transTime) > 300) { // 5 นาที
                return false;
            }
        }
        
        return true;
    }
}

/**
 * 3. Custom Bank Statement Monitor
 * ตรวจสอบผ่าน Bank Statement API (ต้องขอ permission จากธนาคาร)
 */
class BankStatementMonitor {
    
    private $bankAPIs = [];
    
    public function addBankAPI($bankCode, $config) {
        $this->bankAPIs[$bankCode] = $config;
    }
    
    /**
     * ตรวจสอบการเข้าเงินจากหลายธนาคาร
     */
    public function checkIncomingTransactions($accountNumber, $fromDate, $toDate) {
        $allTransactions = [];
        
        foreach ($this->bankAPIs as $bankCode => $config) {
            try {
                $transactions = $this->getBankTransactions($bankCode, $config, $accountNumber, $fromDate, $toDate);
                $allTransactions = array_merge($allTransactions, $transactions);
            } catch (Exception $e) {
                error_log("Bank API error for $bankCode: " . $e->getMessage());
            }
        }
        
        return $allTransactions;
    }
    
    private function getBankTransactions($bankCode, $config, $accountNumber, $fromDate, $toDate) {
        // ตัวอย่างสำหรับ Kbank API
        if ($bankCode === 'kbank') {
            return $this->getKbankTransactions($config, $accountNumber, $fromDate, $toDate);
        }
        
        // ตัวอย่างสำหรับ SCB API
        if ($bankCode === 'scb') {
            return $this->getSCBTransactions($config, $accountNumber, $fromDate, $toDate);
        }
        
        return [];
    }
    
    private function getKbankTransactions($config, $accountNumber, $fromDate, $toDate) {
        // Implementation สำหรับ Kbank API
        // ต้องขอ API Access จาก Kbank Business
        $curl = curl_init();
        
        curl_setopt_array($curl, [
            CURLOPT_URL => "https://openapi.kbank.com/v1/statement",
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer " . $config['access_token'],
                "Content-Type: application/json"
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode([
                'accountNumber' => $accountNumber,
                'fromDate' => $fromDate,
                'toDate' => $toDate
            ])
        ]);
        
        $response = curl_exec($curl);
        $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);
        
        if ($httpCode === 200) {
            $data = json_decode($response, true);
            return $data['transactions'] ?? [];
        }
        
        return [];
    }
    
    private function getSCBTransactions($config, $accountNumber, $fromDate, $toDate) {
        // Implementation สำหรับ SCB API
        // ต้องขอ API Access จาก SCB Business
        return [];
    }
}

/**
 * 4. QR Payment Status Checker
 * ระบบตรวจสอบสถานะหลัก
 */
class QRPaymentStatusChecker {
    
    private $omisePayment;
    private $slipVerifier;
    private $bankMonitor;
    
    public function __construct() {
        // Initialize payment services
        $this->omisePayment = new OmisePromptPayPayment(
            'pkey_test_xxxxx', // Omise Public Key
            'skey_test_xxxxx', // Omise Secret Key
            'https://yoursite.com/webhooks/omise'
        );
        
        $this->slipVerifier = new SlipOKVerification('your_slipok_token');
        $this->bankMonitor = new BankStatementMonitor();
    }
    
    /**
     * สร้างการชำระเงิน
     */
    public function createPayment($orderId, $amount, $description = '') {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // สร้าง payment record
            $stmt = $conn->prepare("
                INSERT INTO payments (order_id, amount, payment_method, status, created_at) 
                VALUES (?, ?, 'promptpay', 'pending', NOW())
            ");
            $stmt->execute([$orderId, $amount]);
            $paymentId = $conn->lastInsertId();
            
            // สร้าง Omise charge (ถ้ามี)
            $omiseResult = $this->omisePayment->createPromptPayCharge($amount, $orderId, $description);
            
            if ($omiseResult['success']) {
                // อัปเดต payment record
                $stmt = $conn->prepare("
                    UPDATE payments 
                    SET reference_number = ?, expires_at = ? 
                    WHERE payment_id = ?
                ");
                $stmt->execute([
                    $omiseResult['charge_id'],
                    date('Y-m-d H:i:s', strtotime($omiseResult['expires_at'])),
                    $paymentId
                ]);
                
                return [
                    'success' => true,
                    'payment_id' => $paymentId,
                    'charge_id' => $omiseResult['charge_id'],
                    'qr_code_url' => $omiseResult['qr_code_url'],
                    'expires_at' => $omiseResult['expires_at']
                ];
            }
            
            // Fallback: ใช้ QR Code แบบปกติ
            $settings = $this->getPaymentSettings();
            $qrUrl = PromptPayQRGenerator::generateQRImageUrl(
                $settings['promptpay_id'],
                $amount,
                $settings['promptpay_name'] ?? '',
                300
            );
            
            return [
                'success' => true,
                'payment_id' => $paymentId,
                'qr_code_url' => $qrUrl,
                'expires_at' => date('c', strtotime('+5 minutes'))
            ];
            
        } catch (Exception $e) {
            error_log("Create payment error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * ตรวจสอบสถานะการชำระเงิน
     */
    public function checkPaymentStatus($paymentId) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // ดึงข้อมูล payment
            $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_id = ?");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            
            if (!$payment) {
                return ['success' => false, 'error' => 'Payment not found'];
            }
            
            // ถ้าจ่ายแล้ว
            if ($payment['status'] === 'completed') {
                return [
                    'success' => true,
                    'status' => 'completed',
                    'payment_id' => $paymentId,
                    'paid_at' => $payment['payment_date']
                ];
            }
            
            // ตรวจสอบผ่าน Omise (ถ้ามี charge_id)
            if ($payment['reference_number'] && strpos($payment['reference_number'], 'chrg_') === 0) {
                $omiseStatus = $this->omisePayment->getChargeStatus($payment['reference_number']);
                
                if ($omiseStatus['success'] && $omiseStatus['paid']) {
                    // อัปเดตสถานะ
                    $this->updatePaymentStatus($paymentId, 'completed', $omiseStatus['transaction_id']);
                    
                    return [
                        'success' => true,
                        'status' => 'completed',
                        'payment_id' => $paymentId,
                        'transaction_id' => $omiseStatus['transaction_id']
                    ];
                }
            }
            
            // ตรวจสอบผ่าน Bank Statement (ถ้าตั้งค่าไว้)
            $bankTransactions = $this->checkBankTransactions($payment);
            if (!empty($bankTransactions)) {
                $this->updatePaymentStatus($paymentId, 'completed', $bankTransactions[0]['ref_id']);
                
                return [
                    'success' => true,
                    'status' => 'completed',
                    'payment_id' => $paymentId,
                    'transaction_details' => $bankTransactions[0]
                ];
            }
            
            // ยังไม่ได้รับการชำระเงิน
            return [
                'success' => true,
                'status' => $payment['status'],
                'payment_id' => $paymentId
            ];
            
        } catch (Exception $e) {
            error_log("Check payment status error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    /**
     * ตรวจสอบสลิปที่อัปโหลด
     */
    public function verifyUploadedSlip($paymentId, $slipImageBase64) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            // ดึงข้อมูล payment
            $stmt = $conn->prepare("SELECT * FROM payments WHERE payment_id = ?");
            $stmt->execute([$paymentId]);
            $payment = $stmt->fetch();
            
            if (!$payment) {
                return ['success' => false, 'error' => 'Payment not found'];
            }
            
            $settings = $this->getPaymentSettings();
            
            // ตรวจสอบสลิปผ่าน SlipOK API
            $slipResult = $this->slipVerifier->verifySlipFromImage(
                $slipImageBase64,
                $payment['amount'],
                $settings['promptpay_id']
            );
            
            if ($slipResult['success'] && $slipResult['valid']) {
                // อัปเดตสถานะเป็นจ่ายแล้ว
                $this->updatePaymentStatus($paymentId, 'completed', $slipResult['ref_id']);
                
                return [
                    'success' => true,
                    'verified' => true,
                    'payment_id' => $paymentId,
                    'slip_data' => $slipResult['slip_data']
                ];
            }
            
            return [
                'success' => true,
                'verified' => false,
                'error' => 'สลิปไม่ถูกต้องหรือจำนวนเงินไม่ตรงกัน'
            ];
            
        } catch (Exception $e) {
            error_log("Verify slip error: " . $e->getMessage());
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
    
    private function updatePaymentStatus($paymentId, $status, $transactionId = null) {
        try {
            $db = new Database();
            $conn = $db->getConnection();
            
            $stmt = $conn->prepare("
                UPDATE payments 
                SET status = ?, reference_number = COALESCE(?, reference_number), payment_date = NOW() 
                WHERE payment_id = ?
            ");
            $stmt->execute([$status, $transactionId, $paymentId]);
            
            // อัปเดตสถานะออเดอร์ด้วย
            if ($status === 'completed') {
                $stmt = $conn->prepare("
                    UPDATE orders o
                    JOIN payments p ON o.order_id = p.order_id
                    SET o.payment_status = 'paid', o.status = 'confirmed'
                    WHERE p.payment_id = ?
                ");
                $stmt->execute([$paymentId]);
            }
            
            return true;
        } catch (Exception $e) {
            error_log("Update payment status error: " . $e->getMessage());
            return false;
        }
    }
    
    private function checkBankTransactions($payment) {
        // Implementation สำหรับการตรวจสอบ Bank Statement
        // ต้องมีการตั้งค่าและ permission จากธนาคาร
        return [];
    }
    
    private function getPaymentSettings() {
        static $settings = null;
        
        if ($settings === null) {
            try {
                $db = new Database();
                $conn = $db->getConnection();
                
                $stmt = $conn->prepare("SELECT setting_key, setting_value FROM payment_settings");
                $stmt->execute();
                $result = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
                
                $settings = $result;
            } catch (Exception $e) {
                $settings = [];
            }
        }
        
        return $settings;
    }
}

/**
 * 5. Webhook Handler
 * จัดการ Webhook จาก Payment Gateway
 */

// ไฟล์: webhooks/omise.php
/*
<?php
require_once '../config/config.php';
require_once '../includes/functions.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $payload = file_get_contents('php://input');
    $signature = $_SERVER['HTTP_X_OMISE_SIGNATURE'] ?? '';
    
    $paymentChecker = new QRPaymentStatusChecker();
    $result = $paymentChecker->omisePayment->handleWebhook($payload, $signature);
    
    if ($result['success']) {
        http_response_code(200);
        echo json_encode(['received' => true]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => $result['error']]);
    }
} else {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
}
?>
*/

/**
 * ฟังก์ชันช่วยเหลือสำหรับการใช้งาน
 */

// สร้างการชำระเงิน
function createQRPayment($orderId, $amount, $description = '') {
    $checker = new QRPaymentStatusChecker();
    return $checker->createPayment($orderId, $amount, $description);
}

// ตรวจสอบสถานะ
function checkQRPaymentStatus($paymentId) {
    $checker = new QRPaymentStatusChecker();
    return $checker->checkPaymentStatus($paymentId);
}

// ตรวจสอบสลิป
function verifySlipPayment($paymentId, $slipImageBase64) {
    $checker = new QRPaymentStatusChecker();
    return $checker->verifyUploadedSlip($paymentId, $slipImageBase64);
}

/**
 * ตัวอย่างการใช้งาน
 */

// สร้างการชำระเงิน
$payment = createQRPayment(123, 150.75, 'Order #123');
if ($payment['success']) {
    echo "Payment created: " . $payment['payment_id'] . "\n";
    echo "QR Code URL: " . $payment['qr_code_url'] . "\n";
}

// ตรวจสอบสถานะ
$status = checkQRPaymentStatus($payment['payment_id']);
if ($status['success']) {
    echo "Payment status: " . $status['status'] . "\n";
}

?>

<!-- HTML สำหรับหน้า Checkout -->
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>ชำระเงิน PromptPay</title>
    <script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
</head>
<body>
    <div id="payment-container">
        <h3>สแกน QR Code เพื่อชำระเงิน</h3>
        <div id="qr-code"></div>
        <div id="status">รอการชำระเงิน...</div>
        <div id="upload-slip">
            <h4>หรืออัปโหลดสลิปโอนเงิน</h4>
            <input type="file" id="slip-file" accept="image/*">
            <button onclick="uploadSlip()">ตรวจสอบสลิป</button>
        </div>
    </div>

    <script>
        let paymentId = null;
        let statusInterval = null;

        // สร้างการชำระเงิน
        function createPayment(orderId, amount) {
            $.post('api/create_payment.php', {
                order_id: orderId,
                amount: amount
            }, function(response) {
                if (response.success) {
                    paymentId = response.payment_id;
                    $('#qr-code').html('<img src="' + response.qr_code_url + '" alt="QR Code">');
                    
                    // เริ่มตรวจสอบสถานะ
                    startStatusCheck();
                }
            });
        }

        // ตรวจสอบสถานะอัตโนมัติ
        function startStatusCheck() {
            statusInterval = setInterval(function() {
                $.get('api/check_payment_status.php', {
                    payment_id: paymentId
                }, function(response) {
                    if (response.success && response.status === 'completed') {
                        clearInterval(statusInterval);
                        $('#status').html('<span style="color: green;">✅ ชำระเงินสำเร็จ!</span>');
                        
                        // Redirect หรือ reload หน้า
                        setTimeout(() => {
                            window.location.href = 'order_success.php?payment_id=' + paymentId;
                        }, 2000);
                    }
                });
            }, 3000); // ตรวจสอบทุก 3 วินาที
        }

        // อัปโหลดสลิป
        function uploadSlip() {
            const fileInput = document.getElementById('slip-file');
            const file = fileInput.files[0];
            
            if (!file) {
                alert('กรุณาเลือกไฟล์สลิป');
                return;
            }

            const reader = new FileReader();
            reader.onload = function(e) {
                const base64 = e.target.result.split(',')[1];
                
                $.post('api/verify_slip.php', {
                    payment_id: paymentId,
                    slip_image: base64
                }, function(response) {
                    if (response.success && response.verified) {
                        clearInterval(statusInterval);
                        $('#status').html('<span style="color: green;">✅ ตรวจสอบสลิปสำเร็จ!</span>');
                        
                        setTimeout(() => {
                            window.location.href = 'order_success.php?payment_id=' + paymentId;
                        }, 2000);
                    } else {
                        $('#status').html('<span style="color: red;">❌ สลิปไม่ถูกต้อง</span>');
                    }
                });
            };
            
            reader.readAsDataURL(file);
        }

        // เริ่มต้น
        $(document).ready(function() {
            createPayment(123, 150.75); // ตัวอย่าง
        });
    </script>
</body>
</html>