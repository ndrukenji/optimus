<?php
/**
 * AJAX Endpoint - Get Tarif by ID Mobil
 */
require_once 'config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['username']) || !isset($_SESSION['location'])) {
    echo json_encode([]);
    exit;
}

$idMobil = $_GET['id_mobil'] ?? '';

if (empty($idMobil)) {
    echo json_encode([]);
    exit;
}

try {
    $pdo = connectDB($_SESSION['location']);
    $stmt = $pdo->prepare("SELECT jenis_langganan, tarif, tgl_akhir, last_member FROM tarif_stiker WHERE id_mobil = :id_mobil ORDER BY tarif ASC");
    $stmt->execute([':id_mobil' => $idMobil]);
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode($results ?: []);
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
