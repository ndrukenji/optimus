<?php
/**
 * Halaman Instruksi Pembayaran & Cek Status iPaymu
 */
require_once 'config.php';

if (!isset($_SESSION['username']) || !isset($_SESSION['location'])) {
    header('Location: login.php');
    exit;
}

$location = $_SESSION['location'];
$pdo = connectDB($location);
$notrans = trim($_GET['notrans'] ?? '');

if (empty($notrans)) {
    die("No. Transaksi tidak valid.");
}

$stmt = $pdo->prepare("
    SELECT t.*, d.nopol, d.jenis_mobil, d.merk, d.tipe, d.warna, d.jenis_member, d.status as status_kendaraan
    FROM transaksi_stiker t
    LEFT JOIN detail_transaksi_stiker d ON t.notrans = d.notrans
    WHERE t.notrans = :notrans
");
$stmt->execute([':notrans' => $notrans]);
$member = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$member) {
    die("Data transaksi member tidak ditemukan.");
}

$message = '';
$error = '';
$ipaymuService = new IPaymuService($pdo);

// Aksi 1: Cek Status Pembayaran ke iPaymu
if (isset($_POST['action']) && $_POST['action'] === 'check_status') {
    $trxId = $member['payment_trx_id'] ?? '';
    if (!empty($trxId)) {
        $checkRes = $ipaymuService->checkTransaction($trxId);
        if ($checkRes['success'] && isset($checkRes['data']['Data']['Status'])) {
            $apiStatus = (int)$checkRes['data']['Data']['Status'];
            // Status iPaymu: 1 = Berhasil, 0 = Pending, 2 = Batal, 3 = Expired
            if ($apiStatus === 1) {
                $pdo->beginTransaction();
                $stmt1 = $pdo->prepare("UPDATE transaksi_stiker SET status_bayar = 'LUNAS', tgl_edited = NOW() WHERE notrans = :notrans");
                $stmt1->execute([':notrans' => $notrans]);
                $stmt2 = $pdo->prepare("UPDATE detail_transaksi_stiker SET status = 1 WHERE notrans = :notrans");
                $stmt2->execute([':notrans' => $notrans]);
                $pdo->commit();

                $member['status_bayar'] = 'LUNAS';
                $member['status_kendaraan'] = 1;
                $message = "Pembayaran BERHASIL! Status member telah otomatis aktif.";
            } elseif ($apiStatus === 0) {
                $message = "Status saat ini: MENUNGGU PEMBAYARAN. Silakan selesaikan pembayaran.";
            } elseif ($apiStatus === 3) {
                $error = "Tagihan pembayaran telah KADALUARSA (Expired). Silakan klik 'Generate Ulang Tagihan' di bawah.";
            } else {
                $message = "Status iPaymu: " . ($checkRes['data']['Data']['StatusDesc'] ?? 'Belum terbayar');
            }
        } else {
            $error = "Gagal memeriksa status ke iPaymu: " . ($checkRes['message'] ?? 'Koneksi error');
        }
    } else {
        $error = "Transaction ID iPaymu tidak ditemukan pada transaksi ini.";
    }
}

// Aksi 2: Generate Ulang Tagihan Baru
if (isset($_POST['action']) && $_POST['action'] === 'regenerate') {
    $channel = $_POST['payment_channel'] ?? ($member['payment_channel'] ?? 'qris');
    $newRef = 'STK/' . date('Ymd') . '/' . strtoupper(uniqid());

    $res = $ipaymuService->createDirectPayment([
        'referenceId'    => $newRef,
        'name'           => $member['nama'],
        'phone'          => $member['telepon'],
        'email'          => $member['email'],
        'amount'         => floatval($member['harga']),
        'paymentChannel' => $channel,
        'productName'    => $member['jenis_member'] . ' (' . $member['nopol'] . ')'
    ]);

    if ($res['success'] && !empty($res['data'])) {
        $newResult = $res['data'];
        $pdo->beginTransaction();
        
        $stmtUpdate = $pdo->prepare("
            UPDATE transaksi_stiker 
            SET status_bayar = 'PENDING',
                payment_url = :purl,
                payment_no = :pno,
                payment_channel = :pchan,
                payment_trx_id = :ptstatus,
                qr_data_uri = :qrdata,
                tgl_edited = NOW()
            WHERE notrans = :notrans
        ");
        $stmtUpdate->execute([
            ':purl'     => $newResult['QrTemplate'] ?? ($newResult['QrImage'] ?? ''),
            ':pno'      => $newResult['PaymentNo'] ?? '',
            ':pchan'    => $channel,
            ':ptstatus' => (string)($newResult['TransactionId'] ?? ''),
            ':qrdata'   => $newResult['QrDataUri'] ?? '',
            ':notrans'  => $notrans
        ]);
        $pdo->commit();

        // Refresh data member
        $stmt->execute([':notrans' => $notrans]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        $message = "Tagihan baru berhasil dibuat!";
    } else {
        $error = "Gagal membuat tagihan baru: " . ($res['message'] ?? 'Error iPaymu');
    }
}

$pageTitle = "Pembayaran Member - " . htmlspecialchars($member['notrans']);
$isLunas = strtoupper($member['status_bayar'] ?? 'LUNAS') === 'LUNAS';
$isQris = strtolower($member['payment_channel'] ?? '') === 'qris' || strtolower($member['payment_channel'] ?? '') === 'mpm';

ob_start();
?>

<div class="row justify-content-center">
    <div class="col-lg-8">

        <?php if ($message): ?>
            <div class="alert alert-success d-flex align-items-center mb-3">
                <i class="fas fa-check-circle fs-4 me-2"></i>
                <div><?= htmlspecialchars($message) ?></div>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger d-flex align-items-center mb-3">
                <i class="fas fa-exclamation-triangle fs-4 me-2"></i>
                <div><?= htmlspecialchars($error) ?></div>
            </div>
        <?php endif; ?>

        <div class="card shadow-sm border-0 mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
                <h5 class="mb-0 fw-bold">
                    <i class="fas fa-wallet me-2"></i>Status & Instruksi Pembayaran
                </h5>
                <span class="badge bg-<?= $isLunas ? 'success' : 'warning text-dark' ?> fs-6 px-3 py-2">
                    <?= $isLunas ? '<i class="fas fa-check-circle me-1"></i> LUNAS / AKTIF' : '<i class="fas fa-clock me-1"></i> MENUNGGU PEMBAYARAN' ?>
                </span>
            </div>
            <div class="card-body p-4">
                
                <!-- Ringkasan Transaksi -->
                <div class="row g-3 mb-4 p-3 bg-light rounded border">
                    <div class="col-md-6 border-end">
                        <small class="text-muted d-block">Nomor Transaksi</small>
                        <div class="fw-bold fs-5 text-primary"><?= htmlspecialchars($member['notrans']) ?></div>
                        
                        <small class="text-muted d-block mt-2">Nama Member / Unit Kerja</small>
                        <div class="fw-semibold"><?= htmlspecialchars($member['nama']) ?> (<?= htmlspecialchars($member['unit_kerja'] ?: '-') ?>)</div>
                    </div>
                    <div class="col-md-6 ps-md-4">
                        <small class="text-muted d-block">Kendaraan & Plat Nomor</small>
                        <div class="fw-bold font-monospace fs-5 text-dark"><?= htmlspecialchars($member['nopol'] ?? '-') ?></div>
                        
                        <small class="text-muted d-block mt-2">Paket Langganan & Masa Aktif</small>
                        <div class="fw-semibold"><?= htmlspecialchars($member['jenis_member'] ?? '-') ?> <span class="text-muted small">(s/d <?= date('d/m/Y', strtotime($member['akhir'])) ?>)</span></div>
                    </div>
                </div>

                <?php if ($isLunas): ?>
                    <div class="text-center py-4">
                        <div class="mb-3">
                            <i class="fas fa-badge-check text-success fa-5x"></i>
                        </div>
                        <h3 class="fw-bold text-success mb-2">Pembayaran Telah Lunas</h3>
                        <p class="text-muted mb-4">Kendaraan dengan plat nomor <strong><?= htmlspecialchars($member['nopol']) ?></strong> telah aktif dan dapat digunakan pada gerbang parkir.</p>
                        
                        <div class="d-flex justify-content-center gap-2">
                            <a href="cetak-kwitansi.php?source=input-baru&notrans=<?= urlencode($member['notrans']) ?>" target="_blank" class="btn btn-outline-success">
                                <i class="fas fa-print me-1"></i> Cetak Kwitansi
                            </a>
                            <a href="transaksi-member.php" class="btn btn-primary">
                                <i class="fas fa-arrow-left me-1"></i> Kembali ke Daftar Member
                            </a>
                        </div>
                    </div>
                <?php else: ?>
                    
                    <!-- BOX PEMBAYARAN PENDING -->
                    <div class="row align-items-center my-3">
                        <div class="col-md-5 text-center border-end py-2">
                            <?php if ($isQris): ?>
                                <?php if (!empty($member['qr_data_uri'])): ?>
                                    <img src="<?= $member['qr_data_uri'] ?>" alt="QR Code QRIS" class="img-fluid border p-2 rounded shadow-sm bg-white" style="max-width: 220px;">
                                <?php elseif (!empty($member['payment_no'])): ?>
                                    <img src="https://api.qrserver.com/v1/create-qr-code/?size=250x250&data=<?= urlencode($member['payment_no']) ?>" alt="QR Code QRIS" class="img-fluid border p-2 rounded shadow-sm bg-white" style="max-width: 220px;">
                                <?php else: ?>
                                    <div class="p-4 bg-light border rounded">
                                        <i class="fas fa-qrcode fa-4x text-secondary mb-2"></i>
                                        <div class="small text-muted font-monospace">QRIS Belum Tersedia</div>
                                    </div>
                                <?php endif; ?>
                                <div class="mt-2 text-muted small"><i class="fas fa-camera me-1"></i> Scan QRIS dengan m-Banking / e-Wallet</div>
                                
                                <?php if (!empty($member['payment_url'])): ?>
                                    <div class="mt-2">
                                        <a href="<?= htmlspecialchars($member['payment_url']) ?>" target="_blank" class="btn btn-sm btn-outline-primary py-0 px-2" style="font-size: 11px;">
                                            <i class="fas fa-external-link-alt me-1"></i> Buka Template Asli iPaymu
                                        </a>
                                    </div>
                                <?php endif; ?>

                            <?php else: ?>
                                <small class="text-muted d-block mb-1">Nomor Virtual Account</small>
                                <h3 class="fw-bold text-success font-monospace select-all mb-2"><?= htmlspecialchars($member['payment_no'] ?: '-') ?></h3>
                                <span class="badge bg-secondary"><?= strtoupper(htmlspecialchars($member['payment_channel'] ?? 'VA')) ?></span>
                            <?php endif; ?>
                        </div>

                        <div class="col-md-7 ps-md-4">
                            <small class="text-muted d-block mb-1">Total yang Harus Dibayar:</small>
                            <h2 class="fw-bold text-primary mb-3">Rp <?= number_format($member['harga'], 0, ',', '.') ?></h2>
                            
                            <ul class="list-unstyled small text-secondary mb-4">
                                <li class="mb-1"><i class="fas fa-check-circle text-success me-2"></i>Gunakan m-Banking atau e-Wallet apa saja untuk scan QRIS.</li>
                                <li class="mb-1"><i class="fas fa-check-circle text-success me-2"></i>Setelah berhasil transfer, klik tombol <strong>Cek Status Pembayaran</strong> di bawah jika sistem belum otomatis update.</li>
                            </ul>

                            <form method="POST" class="d-inline-block me-2">
                                <input type="hidden" name="action" value="check_status">
                                <button type="submit" class="btn btn-success">
                                    <i class="fas fa-sync-alt me-1"></i> Cek Status Pembayaran
                                </button>
                            </form>

                            <button type="button" class="btn btn-outline-secondary" data-bs-toggle="collapse" data-bs-target="#boxRegenerate">
                                <i class="fas fa-redo me-1"></i> Generate Ulang Tagihan
                            </button>
                        </div>
                    </div>

                    <!-- Panel Generate Ulang (Collapse) -->
                    <div class="collapse mt-4 border-top pt-3" id="boxRegenerate">
                        <div class="card card-body bg-light border">
                            <h6 class="fw-bold mb-2"><i class="fas fa-redo-alt me-1"></i> Buat Tagihan Baru untuk Transaksi Ini</h6>
                            <p class="small text-muted mb-3">Gunakan opsi ini jika QRIS sebelumnya telah kadaluarsa atau ingin mengganti metode pembayaran.</p>
                            
                            <form method="POST" class="row g-2 align-items-center">
                                <input type="hidden" name="action" value="regenerate">
                                <div class="col-md-7">
                                    <select name="payment_channel" class="form-select form-select-sm">
                                        <optgroup label="QRIS">
                                            <option value="qris" selected>QRIS (Semua Bank & E-Wallet)</option>
                                        </optgroup>
                                        <optgroup label="Virtual Account">
                                            <option value="bca">Virtual Account BCA</option>
                                            <option value="mandiri">Virtual Account Mandiri</option>
                                            <option value="bni">Virtual Account BNI</option>
                                            <option value="bri">Virtual Account BRI</option>
                                            <option value="cimb">Virtual Account CIMB Niaga</option>
                                            <option value="permata">Virtual Account Permata</option>
                                        </optgroup>
                                    </select>
                                </div>
                                <div class="col-md-5">
                                    <button type="submit" class="btn btn-sm btn-primary w-100">
                                        <i class="fas fa-qrcode me-1"></i> Buat Tagihan Baru
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                <?php endif; ?>

            </div>
            <div class="card-footer bg-white py-3 d-flex justify-content-between align-items-center">
                <a href="transaksi-member.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Kembali ke Pencarian Member
                </a>
                <a href="cetak-kwitansi.php?source=input-baru&notrans=<?= urlencode($member['notrans']) ?>" target="_blank" class="btn btn-outline-primary">
                    <i class="fas fa-print me-1"></i> Cetak Instruksi / Kwitansi
                </a>
            </div>
        </div>

    </div>
</div>

<?php
$content = ob_get_clean();
require 'layout.php';
?>
