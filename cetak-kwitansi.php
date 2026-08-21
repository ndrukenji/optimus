<?php
/**
 * Halaman Cetak Kwitansi Transaksi Member
 */
require_once 'config.php';

if (!isset($_SESSION['username']) || !isset($_SESSION['location'])) {
    header('Location: login.php');
    exit;
}

$location = $_SESSION['location'];
$notrans  = trim($_GET['notrans'] ?? '');

if (empty($notrans)) {
    die("Error: Nomor Transaksi tidak ditemukan.");
}

$pdo = connectDB($location);
$stmt = $pdo->prepare("SELECT t.*, d.nopol, d.jenis_mobil, d.merk, d.tipe, d.warna, d.jenis_member 
                       FROM transaksi_stiker t 
                       LEFT JOIN detail_transaksi_stiker d ON t.notrans = d.notrans 
                       WHERE t.notrans = :notrans");
$stmt->execute([':notrans' => $notrans]);
$data = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$data) {
    die("Error: Data transaksi [{$notrans}] tidak ditemukan di database.");
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kwitansi - <?= htmlspecialchars($data['notrans']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body {
            background-color: #f8fafc;
            color: #1e293b;
            font-family: Arial, sans-serif;
            padding: 30px 15px;
        }
        .kwitansi-box {
            max-width: 680px;
            margin: 0 auto;
            background: #fff;
            padding: 35px;
            border-radius: 12px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.08);
            border: 1px solid #e2e8f0;
        }
        .header-title {
            font-weight: 800;
            letter-spacing: -0.5px;
            color: #0f172a;
        }
        .table-custom th {
            width: 35%;
            color: #64748b;
            font-weight: 600;
        }
        .total-box {
            background-color: #f1f5f9;
            border-radius: 8px;
            padding: 15px 20px;
        }
        @media print {
            body {
                background: #fff;
                padding: 0;
            }
            .kwitansi-box {
                box-shadow: none;
                border: none;
                padding: 0;
            }
            .no-print {
                display: none !important;
            }
        }
    </style>
</head>
<body>

<div class="container">
    <div class="kwitansi-box">
        <!-- Tombol Aksi Print -->
        <div class="d-flex justify-content-between align-items-center mb-4 no-print">
            <a href="transaksi-member.php" class="btn btn-sm btn-secondary"><i class="fas fa-arrow-left me-1"></i> Kembali</a>
            <button onclick="window.print()" class="btn btn-sm btn-primary"><i class="fas fa-print me-1"></i> Cetak Kwitansi</button>
        </div>

        <!-- Header -->
        <div class="row border-bottom pb-3 mb-4">
            <div class="col-8">
                <h4 class="header-title mb-1"><i class="fas fa-parking text-primary me-2"></i>OPTIMUS PARKING</h4>
                <div class="text-muted small">Lokasi: <strong><?= htmlspecialchars($location) ?></strong></div>
                <div class="text-muted small">Tanda Bukti Pembayaran Member Parkir</div>
            </div>
            <div class="col-4 text-end align-self-center">
                <span class="badge bg-success-subtle text-success border border-success px-3 py-2 fs-6">LUNAS</span>
            </div>
        </div>

        <!-- Detail Transaksi -->
        <table class="table table-borderless table-custom mb-4">
            <tbody>
                <tr>
                    <th>No. Transaksi</th>
                    <td>: <strong class="text-primary font-monospace"><?= htmlspecialchars($data['notrans']) ?></strong></td>
                </tr>
                <tr>
                    <th>Tanggal Transaksi</th>
                    <td>: <?= date('d F Y, H:i', strtotime($data['tanggal'])) ?> WIB</td>
                </tr>
                <tr>
                    <th>Nama Member</th>
                    <td>: <strong class="text-uppercase"><?= htmlspecialchars($data['nama']) ?></strong></td>
                </tr>
                <tr>
                    <th>No. Telepon / ID</th>
                    <td>: <?= htmlspecialchars($data['telepon'] ?: '-') ?> / <?= htmlspecialchars($data['no_id'] ?: '-') ?></td>
                </tr>
                <tr>
                    <th>Nomor Polisi (Plat)</th>
                    <td>: <span class="badge bg-dark fs-6"><?= htmlspecialchars($data['nopol']) ?></span></td>
                </tr>
                <tr>
                    <th>Kendaraan</th>
                    <td>: <?= htmlspecialchars($data['merk'] ?? '') ?> <?= htmlspecialchars($data['tipe'] ?? '') ?> (<?= htmlspecialchars($data['warna'] ?? '-') ?>)</td>
                </tr>
                <tr>
                    <th>Produk / Paket</th>
                    <td>: <?= htmlspecialchars($data['jenis_member'] ?? '-') ?></td>
                </tr>
                <tr>
                    <th>Masa Berlaku</th>
                    <td>: <strong><?= date('d/m/Y', strtotime($data['awal'])) ?></strong> s/d <strong><?= date('d/m/Y', strtotime($data['akhir'])) ?></strong></td>
                </tr>
                <tr>
                    <th>Operator</th>
                    <td>: <?= htmlspecialchars($data['operator']) ?></td>
                </tr>
            </tbody>
        </table>

        <!-- Total Pembayaran -->
        <div class="total-box d-flex justify-content-between align-items-center mb-4">
            <span class="fs-5 fw-bold text-secondary">TOTAL PEMBAYARAN</span>
            <span class="fs-4 fw-bold text-primary">Rp <?= number_format($data['harga'], 0, ',', '.') ?></span>
        </div>

        <!-- Footer -->
        <div class="border-top pt-3 text-center text-muted small">
            <p class="mb-1">Simpan kwitansi ini sebagai bukti pembayaran dan perpanjangan member yang sah.</p>
            <p class="mb-0">Terima kasih atas kepercayaan Anda menggunakan layanan parkir kami.</p>
        </div>
    </div>
</div>

</body>
</html>
