<?php
// Mulai session dan panggil file konfigurasi
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';

// Pastikan user sudah login dan memilih lokasi
if (!isset($_SESSION['username']) || !isset($_SESSION['location'])) {
    header('Location: login.php');
    exit;
}

$location = $_SESSION['location'];
$pdo = connectDB($location);

$pageTitle = "Perpanjangan Masa Aktif Member";
$notrans = $_GET['notrans'] ?? '';
$memberData = null;
$error = '';
$success = '';
$showCetakKwitansi = false;

if (empty($notrans)) {
    die("Error: No Transaksi tidak ditemukan.");
}

// Ambil data member dari database
try {
    $stmt = $pdo->prepare("SELECT * FROM transaksi_stiker WHERE notrans = :notrans");
    $stmt->execute([':notrans' => $notrans]);
    $memberData = $stmt->fetch(PDO::FETCH_ASSOC);

    // Ambil data detail kendaraan
    $stmt_detail = $pdo->prepare("SELECT * FROM detail_transaksi_stiker WHERE notrans = :notrans");
    $stmt_detail->execute([':notrans' => $notrans]);
    $detailData = $stmt_detail->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error saat mengambil data member: " . $e->getMessage();
}

if (!$memberData) {
    die("Error: Data dengan No Transaksi '{$notrans}' tidak ditemukan.");
}

$jenisMobilId = isset($detailData['jenis_mobil']) ? $detailData['jenis_mobil'] : null;
$jenisMobilName = $jenisMobilId; // Default to ID if name lookup fails

if (empty($jenisMobilId) || $jenisMobilId === 'N/A') {
    $error = "Error: Jenis Kendaraan tidak ditemukan atau tidak valid untuk transaksi ini. Pastikan data kendaraan sudah benar.";
} else {
    // Get the name from jenis_mobil id for display
    try {
        $stmt_name = $pdo->prepare("SELECT nama FROM jenis_mobil WHERE id = :id");
        $stmt_name->execute([':id' => $jenisMobilId]);
        $name_data = $stmt_name->fetch(PDO::FETCH_ASSOC);
        if ($name_data) {
            $jenisMobilName = $name_data['nama'];
        }
    } catch (PDOException $e) {
        // Keep $jenisMobilName as $jenisMobilId
    }
}

$tarifProduk = [];
if ($jenisMobilId) {
    try {
        $stmt_tarif = $pdo->prepare("SELECT jenis_langganan, tarif, last_member FROM tarif_stiker WHERE id_mobil = :id_mobil");
        $stmt_tarif->execute([':id_mobil' => $jenisMobilId]);
        $tarifProduk = $stmt_tarif->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        $error .= " Error saat mengambil data tarif: " . $e->getMessage();
    }
}

// Logika untuk insert data baru (ketika form disubmit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $produk_manual  = strtoupper($_POST['produk_manual'] ?? '');
    $harga_manual   = $_POST['harga_manual'] ?? '';
    $no_induk       = strtoupper($_POST['no_induk'] ?? '');
    $metode_bayar   = $_POST['metode_pembayaran'] ?? 'tunai';
    $payment_channel = $_POST['payment_channel'] ?? 'qris';
    $duration       = (int)($_POST['durasi'] ?? 0);
    
    if ($duration === 99) {
        $duration = 365;
    }

    if (empty($produk_manual)) {
        $error = "Produk langganan harus diisi.";
    } elseif (!is_numeric($harga_manual) || $harga_manual < 0) {
        $error = "Harga harus diisi dengan nilai valid.";
    } elseif ($duration <= 0) {
        $error = "Durasi periode harus lebih dari 0.";
    } else {
        try {
            // Generate No Transaksi baru
            $new_notrans = 'STK/' . date('Ymd') . '/' . strtoupper(uniqid());
                
            // Persiapkan tanggal untuk perbandingan
            $current_akhir_date = new DateTime($memberData['akhir']);
            $current_akhir_date->setTime(0, 0, 0);
            $today = new DateTime();
            $today->setTime(0, 0, 0);

            $new_awal_date_str = $memberData['akhir']; // Start from current end date for extension
            
            // Cek apakah masa aktif sudah berakhir
            if ($current_akhir_date < $today) {
                // KASUS 1: Masa aktif sudah berakhir, periode baru dimulai dari hari ini
                $new_awal_date = clone $today;
                $new_akhir_date = clone $today;
                $new_akhir_date->add(new DateInterval("P{$duration}D"));
                $new_awal_date_str = $new_awal_date->format('Y-m-d H:i:s');
            } else {
                // KASUS 2: Masa aktif masih berlaku, perpanjang dari tanggal akhir saat ini
                $new_akhir_date = clone $current_akhir_date;
                $new_akhir_date->add(new DateInterval("P{$duration}D"));
                $new_awal_date_str = $memberData['akhir'];
            }

            $new_akhir_date->setTime(0, 0, 0);
            $new_akhir_date_str = $new_akhir_date->format('Y-m-d H:i:s');

            if ($metode_bayar === 'ipaymu') {
                // Integrasi iPaymu untuk perpanjangan online
                $ipaymuService = new IPaymuService($pdo);
                $resIPaymu = $ipaymuService->createDirectPayment([
                    'referenceId'    => $new_notrans,
                    'name'           => $memberData['nama'],
                    'phone'          => $memberData['telepon'] ?? '',
                    'email'          => $memberData['email'] ?? '',
                    'amount'         => floatval($harga_manual),
                    'paymentChannel' => $payment_channel,
                    'productName'    => 'Perpanjangan ' . $produk_manual . ' (' . ($detailData['nopol'] ?? '') . ')'
                ]);

                if ($resIPaymu['success'] && !empty($resIPaymu['data'])) {
                    $ipaymuResult = $resIPaymu['data'];
                    
                    $pdo->beginTransaction();

                    // 1. Simpan ke transaksi_stiker (Status PENDING)
                    $stmt1 = $pdo->prepare(
                        "INSERT INTO transaksi_stiker (notrans, nama, alamat, telepon, no_id, unit_kerja, awal, akhir, harga, tanggal, operator, jenis_transaksi, tgl_edited, email, no_induk, no_kartu, status_bayar, payment_url, payment_no, payment_channel, payment_trx_id, qr_data_uri) "
                        . "VALUES (:notrans, :nama, :alamat, :telepon, :no_id, :unit_kerja, :awal, :akhir, :harga, NOW(), :operator, 1, NOW(), :email, :no_induk, :no_kartu, 'PENDING', :payment_url, :payment_no, :payment_channel, :payment_trx_id, :qr_data_uri)"
                    );
                    $stmt1->execute([
                        ':notrans'         => $new_notrans,
                        ':nama'            => $memberData['nama'],
                        ':alamat'          => $memberData['alamat'],
                        ':telepon'         => $memberData['telepon'],
                        ':no_id'           => $memberData['no_id'],
                        ':unit_kerja'      => $memberData['unit_kerja'],
                        ':awal'            => $new_awal_date_str,
                        ':akhir'           => $new_akhir_date_str,
                        ':harga'           => $harga_manual,
                        ':operator'        => $_SESSION['username'],
                        ':email'           => $memberData['email'],
                        ':no_induk'        => $no_induk,
                        ':no_kartu'        => $memberData['no_kartu'] ?? '',
                        ':payment_url'     => $ipaymuResult['QrTemplate'] ?? ($ipaymuResult['QrImage'] ?? ''),
                        ':payment_no'      => $ipaymuResult['PaymentNo'] ?? '',
                        ':payment_channel' => $payment_channel,
                        ':payment_trx_id'  => (string)($ipaymuResult['TransactionId'] ?? ''),
                        ':qr_data_uri'     => $ipaymuResult['QrDataUri'] ?? ''
                    ]);

                    // 2. Simpan ke detail_transaksi_stiker (Status 0: Menunggu Bayar)
                    $stmt2 = $pdo->prepare(
                        "INSERT INTO detail_transaksi_stiker (notrans, nopol, jenis_mobil, merk, tipe, tahun, warna, jenis_member, status) "
                        . "VALUES (:notrans, :nopol, :jenis_mobil, :merk, :tipe, :tahun, :warna, :jenis_member, 0)"
                    );
                    $stmt2->execute([
                        ':notrans'      => $new_notrans,
                        ':nopol'        => $detailData['nopol'],
                        ':jenis_mobil'  => $detailData['jenis_mobil'],
                        ':merk'         => $detailData['merk'],
                        ':tipe'         => $detailData['tipe'],
                        ':tahun'        => $detailData['tahun'],
                        ':warna'        => $detailData['warna'],
                        ':jenis_member' => $produk_manual
                    ]);

                    $pdo->commit();

                    // Redirect langsung ke halaman pembayaran & QRIS
                    header('Location: bayar-member.php?notrans=' . urlencode($new_notrans));
                    exit;
                } else {
                    $errorMsg = $resIPaymu['message'] ?? 'Gagal menghubungi server iPaymu.';
                    $error = "Gagal membuat tagihan perpanjangan online. Error: " . $errorMsg;
                }
            } else {
                // Pembayaran TUNAI (Langsung Lunas & Aktif)
                $pdo->beginTransaction();

                // Insert new transaction in transaksi_stiker
                $stmt1 = $pdo->prepare(
                    "INSERT INTO transaksi_stiker (notrans, nama, alamat, telepon, no_id, unit_kerja, awal, akhir, harga, tanggal, operator, jenis_transaksi, tgl_edited, email, no_induk, no_kartu, status_bayar, payment_channel) "
                    . "VALUES (:notrans, :nama, :alamat, :telepon, :no_id, :unit_kerja, :awal, :akhir, :harga, NOW(), :operator, 1, NOW(), :email, :no_induk, :no_kartu, 'LUNAS', 'TUNAI')"
                );
                $stmt1->execute([
                    ':notrans'         => $new_notrans,
                    ':nama'            => $memberData['nama'],
                    ':alamat'          => $memberData['alamat'],
                    ':telepon'         => $memberData['telepon'],
                    ':no_id'           => $memberData['no_id'],
                    ':unit_kerja'      => $memberData['unit_kerja'],
                    ':awal'            => $new_awal_date_str,
                    ':akhir'           => $new_akhir_date_str,
                    ':harga'           => $harga_manual,
                    ':operator'        => $_SESSION['username'],
                    ':email'           => $memberData['email'],
                    ':no_induk'        => $no_induk,
                    ':no_kartu'        => $memberData['no_kartu'] ?? ''
                ]);

                // Insert new detail in detail_transaksi_stiker
                $stmt2 = $pdo->prepare(
                    "INSERT INTO detail_transaksi_stiker (notrans, nopol, jenis_mobil, merk, tipe, tahun, warna, jenis_member, status) "
                    . "VALUES (:notrans, :nopol, :jenis_mobil, :merk, :tipe, :tahun, :warna, :jenis_member, 1)"
                );
                $stmt2->execute([
                    ':notrans'      => $new_notrans,
                    ':nopol'        => $detailData['nopol'],
                    ':jenis_mobil'  => $detailData['jenis_mobil'],
                    ':merk'         => $detailData['merk'],
                    ':tipe'         => $detailData['tipe'],
                    ':tahun'        => $detailData['tahun'],
                    ':warna'        => $detailData['warna'],
                    ':jenis_member' => $produk_manual
                ]);

                $pdo->commit();

                // Kirim email bukti perpanjangan dengan PHPMailer jika email member diisi
                if (!empty($memberData['email'])) {
                    $subject = "Bukti Perpanjangan Member Parkir";
                    $message = "Terima kasih telah melakukan perpanjangan member parkir. Berikut detail transaksi Anda:\n\n" .
                        "No. Transaksi: $new_notrans\n" .
                        "Nama: {$memberData['nama']}\n" .
                        "No. Polisi: {$detailData['nopol']}\n" .
                        "Jenis Langganan: $produk_manual\n" .
                        "Harga: Rp " . number_format($harga_manual, 0, ',', '.') . "\n" .
                        "Masa Aktif: " . date('d-m-Y', strtotime($new_awal_date_str)) . " s/d " . date('d-m-Y', strtotime($new_akhir_date_str)) . "\n" .
                        "Operator: " . $_SESSION['username'] . "\n\n" .
                        "Bukti ini dapat digunakan sebagai kwitansi pembayaran.\n\nSalam,\nAdmin Parkir - $location";
                    $mail = new PHPMailer(true);
                    try {
                        $mail->isSMTP();
                        $mail->Host       = 'smtp.gmail.com';
                        $mail->SMTPAuth   = true;
                        $emailConfig = [
                            'username' => getenv('EMAIL_USERNAME') ?: 'your_email@gmail.com',
                            'password' => getenv('EMAIL_PASSWORD') ?: 'your_email_password'
                        ];
                        $mail->Username   = $emailConfig['username'];
                        $mail->Password   = $emailConfig['password'];
                        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                        $mail->Port       = 587;
                        $mail->setFrom($emailConfig['username'], 'Admin Parkir - ' . $location);
                        $mail->addAddress($memberData['email'], $memberData['nama']);
                        $mail->isHTML(false);
                        $mail->Subject = $subject;
                        $mail->Body    = $message;
                        $mail->send();
                    } catch (Exception $e) {
                        // Optional: log error
                    }
                }

                $_SESSION['last_kwitansi'] = [
                    'notrans' => $new_notrans,
                    'nama' => $memberData['nama'],
                    'jenis_langganan' => $produk_manual,
                    'harga' => $harga_manual,
                    'awal' => $new_awal_date_str,
                    'akhir' => $new_akhir_date_str,
                    'operator' => $_SESSION['username']
                ];

                // Redirect to print receipt
                header('Location: cetak-kwitansi.php?notrans=' . urlencode($new_notrans));
                exit;
            }

        } catch (Exception $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error saat memperbarui data: " . $e->getMessage();
        }
    }
}

ob_start();
?>

<?php if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Perpanjang Masa Aktif: <?= htmlspecialchars($memberData['nama'] ?? '') ?></h3>
    </div>
    <div class="card-body">
        <form method="POST" action="perpanjang-member.php?notrans=<?= htmlspecialchars($notrans ?? '') ?>">
            <div class="mb-3">
                <label class="form-label">No. Transaksi</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($memberData['notrans'] ?? '') ?>" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">Nama Member</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($memberData['nama'] ?? '') ?>" readonly>
            </div>
            <div class="mb-3">
                <label class="form-label">Telepon</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($memberData['telepon'] ?? '') ?>" readonly>
            </div>
            <div class="mb-3">
                <label for="no_induk" class="form-label">No. Kartu</label>
                <input type="text" class="form-control" id="no_induk" name="no_induk" value="<?= htmlspecialchars($memberData['no_induk'] ?? '') ?>" style="text-transform: uppercase">
            </div>
            
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label">Masa Aktif Awal</label>
                    <input type="text" class="form-control" value="<?= $memberData['awal'] ? date('d F Y', strtotime($memberData['awal'])) : '' ?>" readonly>
                </div>
                <div class="col-md-6 mb-3">
                    <label class="form-label">Masa Aktif Akhir (Saat Ini)</label>
                    <input type="text" class="form-control" id="current_akhir" value="<?= $memberData['akhir'] ? date('d F Y', strtotime($memberData['akhir'])) : '' ?>" readonly>
                </div>
            </div>

            <hr class="my-4">

            <h5 class="mb-3">Perpanjangan</h5>
            <div class="mb-3">
                <label for="produk_manual" class="form-label">Produk Langganan <span class="text-danger">*</span></label>
                <select class="form-select" id="produk_manual" name="produk_manual" required>
                    <option value="">-- Pilih Produk Langganan --</option>
                    <?php foreach ($tarifProduk as $produk): ?>
                        <option value="<?= htmlspecialchars($produk['jenis_langganan']) ?>" data-tarif="<?= htmlspecialchars($produk['tarif']) ?>" data-last-member="<?= htmlspecialchars($produk['last_member']) ?>">
                            <?= htmlspecialchars($produk['jenis_langganan']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted">Jenis Mobil (nama): <span id="debug-jenis-mobil"><?= htmlspecialchars($jenisMobilName ?? 'N/A') ?></span> | ID: <span id="debug-id-kendaraan"><?= htmlspecialchars($jenisMobilId ?? 'N/A') ?></span></small>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="harga_manual" class="form-label">Harga (Rp)</label>
                    <input type="number" class="form-control fw-bold fs-5 text-primary" id="harga_manual" name="harga_manual" min="0" value="0" readonly>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="durasi" class="form-label">Durasi Periode (hari)</label>
                    <input type="number" class="form-control" id="durasi" name="durasi" min="1" value="0" readonly>
                </div>
            </div>

            <!-- Metode Pembayaran -->
            <div class="card mb-4 border bg-light">
                <div class="card-header bg-white py-2">
                    <h6 class="mb-0 fw-bold"><i class="fas fa-credit-card me-2 text-primary"></i>Metode Pembayaran</h6>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <div class="form-check p-3 border rounded bg-white h-100">
                                <input class="form-check-input" type="radio" name="metode_pembayaran" id="metode_tunai" value="tunai" checked>
                                <label class="form-check-label fw-bold d-block cursor-pointer" for="metode_tunai">
                                    <i class="fas fa-money-bill-wave text-success me-1"></i> Tunai (Cash di Kasir)
                                    <span class="d-block small text-muted fw-normal mt-1">Pembayaran langsung di loket kasir & aktif seketika.</span>
                                </label>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="form-check p-3 border rounded bg-white h-100">
                                <input class="form-check-input" type="radio" name="metode_pembayaran" id="metode_ipaymu" value="ipaymu">
                                <label class="form-check-label fw-bold d-block cursor-pointer" for="metode_ipaymu">
                                    <i class="fas fa-qrcode text-primary me-1"></i> iPaymu (QRIS & Virtual Account)
                                    <span class="d-block small text-muted fw-normal mt-1">Bayar online via QRIS (BCA, GoPay, OVO, dll) atau Transfer VA.</span>
                                </label>
                            </div>
                        </div>
                    </div>

                    <!-- Pilihan Channel iPaymu -->
                    <div class="mt-3 p-3 bg-white border rounded d-none" id="ipaymu_channel_container">
                        <label class="form-label fw-semibold mb-2">Pilih Jalur Pembayaran iPaymu:</label>
                        <select name="payment_channel" id="payment_channel" class="form-select">
                            <optgroup label="QRIS">
                                <option value="qris" selected>QRIS (Semua Bank & E-Wallet: BCA, Mandiri, GoPay, OVO, Dana, ShopeePay)</option>
                            </optgroup>
                            <optgroup label="Virtual Account (Transfer Bank)">
                                <option value="bca">Virtual Account BCA</option>
                                <option value="mandiri">Virtual Account Mandiri</option>
                                <option value="bni">Virtual Account BNI</option>
                                <option value="bri">Virtual Account BRI</option>
                                <option value="cimb">Virtual Account CIMB Niaga</option>
                                <option value="permata">Virtual Account Permata</option>
                            </optgroup>
                        </select>
                        <small class="text-muted mt-2 d-block">
                            <i class="fas fa-info-circle me-1"></i> Status perpanjangan akan tersimpan sebagai <strong>Pending</strong> sampai pembayaran diselesaikan.
                        </small>
                    </div>
                </div>
            </div>

            <div class="mt-4 d-flex gap-2">
                <button type="submit" class="btn btn-primary px-4">
                    <i class="fas fa-calendar-check me-1"></i> Proses Perpanjangan Sekarang
                </button>
                <a href="transaksi-member.php" class="btn btn-outline-secondary">
                    <i class="fas fa-arrow-left me-1"></i> Kembali ke Pencarian
                </a>
            </div>
        </form>
    </div>
</div>

<?php
// Format tanggal untuk JavaScript
$currentEndDate = $memberData['akhir'] ? date('Y-m-d', strtotime($memberData['akhir'])): '';
$today = date('Y-m-d');

$content = ob_get_clean();
?>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const produkManualSelect = document.getElementById('produk_manual');
    const hargaManualInput = document.getElementById('harga_manual');
    const durasiInput = document.getElementById('durasi');
    const metodeTunai = document.getElementById('metode_tunai');
    const metodeIPaymu = document.getElementById('metode_ipaymu');
    const ipaymuChannelContainer = document.getElementById('ipaymu_channel_container');

    produkManualSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption && selectedOption.dataset.tarif) {
            hargaManualInput.value = selectedOption.dataset.tarif;
            let lastMember = parseInt(selectedOption.dataset.lastMember);
            if (lastMember === 99) {
                lastMember = 365;
            }
            durasiInput.value = lastMember;
        } else {
            hargaManualInput.value = '0';
            durasiInput.value = '0';
        }
    });

    function togglePaymentChannel() {
        if (metodeIPaymu.checked) {
            ipaymuChannelContainer.classList.remove('d-none');
        } else {
            ipaymuChannelContainer.classList.add('d-none');
        }
    }

    metodeTunai.addEventListener('change', togglePaymentChannel);
    metodeIPaymu.addEventListener('change', togglePaymentChannel);
});
</script>

<?php
require 'layout.php';
?>