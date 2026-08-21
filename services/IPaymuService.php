<?php

/**
 * Class IPaymuService
 * Service khusus untuk integrasi Payment Gateway iPaymu API v2.
 * 
 * Fitur:
 * - Direct Payment (QRIS MPM & Virtual Account)
 * - Automatic HMAC-SHA256 Signature Generation
 * - Auto-extraction Data URI Base64 untuk QRIS Barcode
 * - Dual Logging (File system logs/ipaymu.log & Database table ipaymu_logs)
 * - Check Transaction Status & Check Balance
 * - Webhook / Callback Handler
 */
class IPaymuService
{
    private string $va;
    private string $apiKey;
    private bool $isSandbox;
    private string $baseUrl;
    private ?PDO $pdo;
    private string $logDir;

    /**
     * Konstruktor
     * @param ?PDO $pdo Database connection (opsional untuk logging ke tabel database)
     * @param ?string $va Nomor Virtual Account iPaymu (default mengambil dari konstanta IPAYMU_VA)
     * @param ?string $apiKey API Key iPaymu (default mengambil dari konstanta IPAYMU_API_KEY)
     * @param ?bool $isSandbox Mode Sandbox (default mengambil dari konstanta IPAYMU_SANDBOX)
     */
    public function __construct(?PDO $pdo = null, ?string $va = null, ?string $apiKey = null, ?bool $isSandbox = null)
    {
        $this->pdo       = $pdo;
        $this->va        = $va ?? (defined('IPAYMU_VA') ? IPAYMU_VA : '0000007861189600');
        $this->apiKey    = $apiKey ?? (defined('IPAYMU_API_KEY') ? IPAYMU_API_KEY : 'SANDBOX87CC2DFA-FB00-42AB-9051-902CBA8E1E7E');
        $this->isSandbox = $isSandbox ?? (defined('IPAYMU_SANDBOX') ? IPAYMU_SANDBOX : true);

        $this->baseUrl   = $this->isSandbox ? 'https://sandbox.ipaymu.com' : 'https://my.ipaymu.com';
        $this->logDir    = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'logs';
    }

    /**
     * Membuat tagihan Direct Payment (QRIS atau Virtual Account)
     * 
     * @param array $params [
     *    'referenceId'    => string,
     *    'name'           => string,
     *    'phone'          => string,
     *    'email'          => string,
     *    'amount'         => float|int,
     *    'paymentChannel' => 'qris'|'mpm'|'bca'|'mandiri'|'bni'|'bri'|'cimb'|'permata',
     *    'productName'    => string,
     *    'notifyUrl'      => string (opsional)
     * ]
     * @return array [
     *    'success' => bool,
     *    'message' => string,
     *    'data'    => array|null,
     *    'raw'     => array|null
     * ]
     */
    public function createDirectPayment(array $params): array
    {
        $endpoint = $this->baseUrl . '/api/v2/payment/direct';
        $channel  = strtolower($params['paymentChannel'] ?? 'qris');

        // Pemetaan Payment Method & Channel
        if ($channel === 'qris' || $channel === 'mpm') {
            $method  = 'qris';
            $channel = 'mpm';
        } else {
            $method  = 'va';
        }

        $notifyUrl = $params['notifyUrl'] ?? (
            (isset($_SERVER['HTTP_HOST']) ? (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://') . $_SERVER['HTTP_HOST'] : 'https://localhost') . '/callback-ipaymu.php'
        );

        $amount      = floatval($params['amount'] ?? 0);
        $productName = $params['productName'] ?? 'Pembayaran Langganan Member Parkir';

        // Sanitasi Nomor Telepon (hanya ambil digit angka, hapus +, -, spasi, huruf)
        $cleanPhone = preg_replace('/[^0-9]/', '', (string)($params['phone'] ?? ''));
        if (strlen($cleanPhone) < 8) {
            $cleanPhone = '081234567890';
        }

        // Sanitasi Email
        $cleanEmail = trim((string)($params['email'] ?? ''));
        if (empty($cleanEmail) || !filter_var($cleanEmail, FILTER_VALIDATE_EMAIL)) {
            $cleanEmail = 'member@example.com';
        }

        $body = [
            'name'           => trim($params['name'] ?? 'Member Parkir'),
            'phone'          => $cleanPhone,
            'email'          => $cleanEmail,
            'amount'         => $amount,
            'notifyUrl'      => $notifyUrl,
            'referenceId'    => $params['referenceId'] ?? ('STK-' . time()),
            'paymentMethod'  => $method,
            'paymentChannel' => $channel,
            'product'        => [$productName],
            'qty'            => [1],
            'price'          => [$amount]
        ];

        $res = $this->sendRequest($endpoint, 'POST', $body, $body['referenceId'], 'DIRECT_' . strtoupper($method));

        if (!$res['success']) {
            return [
                'success' => false,
                'message' => $res['message'],
                'data'    => null
            ];
        }

        $responseData = $res['data']['Data'] ?? [];

        // Jika metode adalah QRIS, ekstrak data:image base64 dari QrImage agar bisa langsung dirender tajam di browser
        if ($method === 'qris' && !empty($responseData['QrImage'])) {
            $responseData['QrDataUri'] = $this->extractBase64FromQrUrl($responseData['QrImage']);
        }

        return [
            'success' => true,
            'message' => $res['data']['Message'] ?? 'Success',
            'data'    => $responseData,
            'raw'     => $res['data']
        ];
    }

    /**
     * Memeriksa Status Transaksi dari iPaymu
     * @param int|string $transactionId
     * @return array
     */
    public function checkTransaction($transactionId): array
    {
        $endpoint = $this->baseUrl . '/api/v2/transaction';
        $body = ['transactionId' => $transactionId];
        return $this->sendRequest($endpoint, 'POST', $body, (string)$transactionId, 'CHECK_TRANSACTION');
    }

    /**
     * Memeriksa Saldo Merchant iPaymu
     * @return array
     */
    public function checkBalance(): array
    {
        $endpoint = $this->baseUrl . '/api/v2/balance';
        $body = ['account' => $this->va];
        return $this->sendRequest($endpoint, 'POST', $body, $this->va, 'CHECK_BALANCE');
    }

    /**
     * Ekstraksi Data URI Base64 dari link QrImage HTML iPaymu
     */
    private function extractBase64FromQrUrl(string $qrUrl): ?string
    {
        $ch = curl_init($qrUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_USERAGENT, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64)');
        curl_setopt($ch, CURLOPT_TIMEOUT, 6);
        $html = curl_exec($ch);
        curl_close($ch);

        if ($html && preg_match('/src="([^"]+)"/i', $html, $matches)) {
            return $matches[1];
        }

        return null;
    }

    /**
     * Eksekusi HTTP Request ke iPaymu API v2 dengan Signature Calculation & Auto Logging
     */
    private function sendRequest(string $endpoint, string $httpMethod, array $bodyData, string $referenceId, string $actionType): array
    {
        $jsonBody     = json_encode($bodyData, JSON_UNESCAPED_SLASHES);
        $requestBody  = strtolower(hash('sha256', $jsonBody));
        $stringToSign = strtoupper($httpMethod) . ':' . $this->va . ':' . $requestBody . ':' . $this->apiKey;
        $signature    = hash_hmac('sha256', $stringToSign, $this->apiKey);
        $timestamp    = date('YmdHis');

        $headers = [
            'Accept: application/json',
            'Content-Type: application/json',
            'va: ' . $this->va,
            'signature: ' . $signature,
            'timestamp: ' . $timestamp
        ];

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_HEADER, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $httpMethod);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $rawResponse = curl_exec($ch);
        $curlError   = curl_error($ch);
        $httpCode    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlError) {
            $this->log($referenceId, $actionType, $endpoint, $bodyData, ['error' => $curlError], $httpCode, 'CURL_ERROR');
            return [
                'success' => false,
                'message' => 'Koneksi cURL Gagal: ' . $curlError,
                'data'    => null
            ];
        }

        $decoded = json_decode($rawResponse, true);
        $isSuccess = ($httpCode == 200 && isset($decoded['Status']) && $decoded['Status'] == 200);
        $statusText = $isSuccess ? 'SUCCESS' : 'FAILED';

        $this->log($referenceId, $actionType, $endpoint, $bodyData, $decoded ?? $rawResponse, $httpCode, $statusText);

        if (!$isSuccess) {
            $msg = $decoded['Message'] ?? ('HTTP Error ' . $httpCode);
            return [
                'success' => false,
                'message' => $msg,
                'data'    => $decoded
            ];
        }

        return [
            'success' => true,
            'message' => $decoded['Message'] ?? 'Success',
            'data'    => $decoded
        ];
    }

    /**
     * Dual Logging: Menulis ke File (logs/ipaymu.log) & Database (ipaymu_logs)
     */
    public function log(string $referenceId, string $actionType, string $endpoint, $requestPayload, $responsePayload, int $httpCode = 0, string $status = 'UNKNOWN'): void
    {
        // 1. Log ke File
        if (!is_dir($this->logDir)) {
            @mkdir($this->logDir, 0777, true);
        }

        $logFile   = $this->logDir . DIRECTORY_SEPARATOR . 'ipaymu.log';
        $timestamp = date('Y-m-d H:i:s');

        $reqStr = is_array($requestPayload) ? json_encode($requestPayload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : $requestPayload;
        $resStr = is_array($responsePayload) ? json_encode($responsePayload, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) : $responsePayload;

        $logEntry  = "=================================================================\n";
        $logEntry .= "[{$timestamp}] ACTION: {$actionType} | REF: {$referenceId} | HTTP: {$httpCode} | STATUS: {$status}\n";
        $logEntry .= "ENDPOINT: {$endpoint}\n";
        $logEntry .= "REQUEST:\n{$reqStr}\n";
        $logEntry .= "RESPONSE:\n{$resStr}\n";
        $logEntry .= "=================================================================\n\n";

        @file_put_contents($logFile, $logEntry, FILE_APPEND);

        // 2. Log ke Database PostgreSQL (Tabel ipaymu_logs)
        if ($this->pdo instanceof PDO) {
            try {
                $stmt = $this->pdo->prepare("
                    INSERT INTO ipaymu_logs (reference_id, action_type, endpoint, request_payload, response_payload, http_code, status)
                    VALUES (:ref, :action, :endpoint, :req, :res, :code, :status)
                ");
                $stmt->execute([
                    ':ref'      => $referenceId,
                    ':action'   => $actionType,
                    ':endpoint' => $endpoint,
                    ':req'      => is_array($requestPayload) ? json_encode($requestPayload, JSON_UNESCAPED_SLASHES) : $requestPayload,
                    ':res'      => is_array($responsePayload) ? json_encode($responsePayload, JSON_UNESCAPED_SLASHES) : $responsePayload,
                    ':code'     => $httpCode,
                    ':status'   => $status
                ]);
            } catch (Exception $e) {
                // Jangan gagalkan alur utama jika insert log gagal
            }
        }
    }
}
