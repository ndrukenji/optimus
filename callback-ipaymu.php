<?php
/**
 * Callback & Webhook Handler iPaymu
 * Menerima notifikasi otomatis saat pembayaran berhasil/berubah status
 */

require_once __DIR__ . '/config.php';

header('Content-Type: application/json');

$location = $_SESSION['location'] ?? 'PUSAT';
$pdo = connectDB($location);

$payload = $_POST;
if (empty($payload)) {
    $rawInput = file_get_contents('php://input');
    $payload  = json_decode($rawInput, true) ?: $_GET;
}

$refId      = $payload['reference_id'] ?? ($payload['referenceId'] ?? ($payload['sid'] ?? 'UNKNOWN'));
$trxId      = $payload['trx_id'] ?? ($payload['transaction_id'] ?? null);
$status     = strtolower($payload['status'] ?? ($payload['statusCode'] ?? ''));
$statusCode = $payload['status_code'] ?? null;
$amount     = $payload['amount'] ?? 0;

// Log semua callback yang masuk ke File dan Tabel Database ipaymu_logs
logIPaymu(
    $refId, 
    'CALLBACK_WEBHOOK', 
    $_SERVER['REQUEST_URI'] ?? '/callback-ipaymu.php', 
    $payload, 
    ['processed' => true, 'timestamp' => date('Y-m-d H:i:s')], 
    200, 
    strtoupper($status ?: 'RECEIVED'), 
    $pdo
);

// Jika pembayaran berhasil (status = 'berhasil' atau status_code = 1)
if ($status === 'berhasil' || $statusCode == 1 || $status === 'success') {
    try {
        $pdo->beginTransaction();

        // 1. Update status pembayaran di transaksi_stiker
        $stmt1 = $pdo->prepare("UPDATE transaksi_stiker SET status_bayar = 'LUNAS', tgl_edited = NOW() WHERE notrans = :notrans");
        $stmt1->execute([':notrans' => $refId]);

        // 2. Aktifkan kendaraan di detail_transaksi_stiker (status = 1)
        $stmt2 = $pdo->prepare("UPDATE detail_transaksi_stiker SET status = 1 WHERE notrans = :notrans");
        $stmt2->execute([':notrans' => $refId]);

        $pdo->commit();

        echo json_encode([
            'status'  => 'success',
            'message' => 'Payment callback processed successfully for ' . $refId
        ]);
        exit;
    } catch (Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode([
            'status'  => 'error',
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit;
    }
}

echo json_encode([
    'status'  => 'acknowledged',
    'message' => 'Callback received with status: ' . $status
]);
