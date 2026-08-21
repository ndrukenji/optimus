<?php
/**
 * =========================================================================
 * FILE KONFIGURASI SISTEM OPTIMUS PARKING MEMBER
 * =========================================================================
 */

// 1. Inisialisasi Session jika belum berjalan
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 2. Set Timezone default (WIB)
date_default_timezone_set('Asia/Jakarta');

// 3. Inisialisasi Token CSRF untuk keamanan form
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// 4. Load Composer Autoloader jika tersedia
if (file_exists(__DIR__ . '/vendor/autoload.php')) {
    require_once __DIR__ . '/vendor/autoload.php';
}

// Alias kelas PHPMailer untuk fallback jika file tidak meng-import namespace
if (class_exists('PHPMailer\PHPMailer\PHPMailer') && !class_exists('PHPMailer')) {
    class_alias('PHPMailer\PHPMailer\PHPMailer', 'PHPMailer');
}
if (class_exists('PHPMailer\PHPMailer\Exception') && !class_exists('Exception\PHPMailerException')) {
    class_alias('PHPMailer\PHPMailer\Exception', 'PHPMailerException');
}

// 4. Konfigurasi Database
// Mendukung multi-lokasi (multi-branch) atau koneksi tunggal
define('DB_DRIVER', 'pgsql'); // 'pgsql' (PostgreSQL) atau 'mysql'
define('DB_HOST', '127.0.0.1');
define('DB_PORT', '5432'); // 5432 untuk PostgreSQL, 3306 untuk MySQL
define('DB_USER', 'postgres');
define('DB_PASS', 'root');
define('DB_NAME_DEFAULT', 'optimus_parking');

// Daftar database per lokasi jika menggunakan database terpisah per cabang/lokasi
$locationDatabases = [
    'PUSAT' => 'optimus_parking',
    'CABANG_1' => 'optimus_parking_c1',
    'CABANG_2' => 'optimus_parking_c2',
];

/**
 * Fungsi koneksi database (PDO) berdasarkan lokasi user
 *
 * @param string $location Nama lokasi/cabang yang sedang aktif
 * @return PDO
 */
function connectDB($location = 'PUSAT')
{
    global $locationDatabases;

    // Tentukan nama database berdasarkan lokasi jika tersedia di daftar
    $dbName = $locationDatabases[$location] ?? DB_NAME_DEFAULT;

    $driver = DB_DRIVER;
    $host = DB_HOST;
    $port = DB_PORT;
    $user = DB_USER;
    $pass = DB_PASS;

    try {
        if ($driver === 'pgsql') {
            $dsn = "pgsql:host={$host};port={$port};dbname={$dbName}";
        } else {
            $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
        }

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        $pdo = new PDO($dsn, $user, $pass, $options);
        return $pdo;
    } catch (PDOException $e) {
        die("Koneksi database gagal untuk lokasi [{$location}]: " . $e->getMessage());
    }
}

// 5. Konfigurasi Email / SMTP (digunakan oleh PHPMailer untuk notifikasi & kwitansi)
define('MAIL_HOST', 'smtp.gmail.com');
define('MAIL_PORT', 587);
define('MAIL_USERNAME', getenv('EMAIL_USERNAME') ?: 'your_email@gmail.com');
define('MAIL_PASSWORD', getenv('EMAIL_PASSWORD') ?: 'your_email_app_password');
define('MAIL_FROM_NAME', 'Admin Parkir');

// 6. Konfigurasi iPaymu Payment Gateway
define('IPAYMU_VA', '0000007861189600');
define('IPAYMU_API_KEY', 'SANDBOX87CC2DFA-FB00-42AB-9051-902CBA8E1E7E');
define('IPAYMU_SANDBOX', true); // Ubah ke false untuk Live / Production

// 7. Load IPaymuService
require_once __DIR__ . '/services/IPaymuService.php';

/**
 * Wrapper fungsi logging untuk kompatibilitas
 */
function logIPaymu($referenceId, $actionType, $endpoint, $requestPayload, $responsePayload, $httpCode = 0, $status = 'UNKNOWN', $pdo = null)
{
    $service = new IPaymuService($pdo);
    $service->log($referenceId, $actionType, $endpoint, $requestPayload, $responsePayload, $httpCode, $status);
}

/**
 * Wrapper fungsi pengiriman request untuk kompatibilitas
 */
function sendIPaymuRequest($endpointUrl, $va, $apiKey, array $bodyData, $pdo = null)
{
    $service = new IPaymuService($pdo, $va, $apiKey);
    $res = $service->createDirectPayment([
        'referenceId' => $bodyData['referenceId'] ?? '',
        'name' => $bodyData['name'] ?? '',
        'phone' => $bodyData['phone'] ?? '',
        'email' => $bodyData['email'] ?? '',
        'amount' => $bodyData['amount'] ?? 0,
        'paymentChannel' => $bodyData['paymentChannel'] ?? 'qris',
        'productName' => $bodyData['product'][0] ?? 'Langganan Member Parkir',
        'notifyUrl' => $bodyData['notifyUrl'] ?? null
    ]);

    return [
        'status' => $res['success'],
        'message' => $res['message'],
        'data' => $res['raw'] ?? ['Status' => $res['success'] ? 200 : 400, 'Data' => $res['data']],
        'raw' => json_encode($res['raw'] ?? $res['data'])
    ];
}
