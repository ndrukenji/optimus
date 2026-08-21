<?php
/**
 * AJAX Endpoint - Check if Nopol already registered as active member
 */
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username']) || !isset($_SESSION['location'])) {
    echo json_encode(['exists' => false]);
    exit;
}

$nopol = strtoupper(trim($_GET['nopol'] ?? ''));

if (empty($nopol)) {
    echo json_encode(['exists' => false]);
    exit;
}

try {
    $pdo = connectDB($_SESSION['location']);
    
    // Periksa tabel detail / view mergetransaksistikerdetail
    $stmt = $pdo->prepare("SELECT COUNT(*) as count FROM detail_transaksi_stiker WHERE UPPER(nopol) = :nopol AND status = 1");
    $stmt->execute([':nopol' => $nopol]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);

    $exists = ($result && intval($result['count']) > 0);
    echo json_encode(['exists' => $exists]);
} catch (Exception $e) {
    // Fallback jika query error
    echo json_encode(['exists' => false, 'error' => $e->getMessage()]);
}
