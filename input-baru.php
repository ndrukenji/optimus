<?php
// Mulai session dan panggil file konfigurasi
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}
require_once 'config.php';
require __DIR__ . '/vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Pastikan user sudah login dan memilih lokasi
if (!isset($_SESSION['username']) || !isset($_SESSION['location'])) {
    header('Location: login.php');
    exit;
}

$location = $_SESSION['location'];
$pdo = connectDB($location);

// ==========================================
// KONFIGURASI IPAYMU PAYMENT GATEWAY
// ==========================================
$ipaymu_va       = '1179000899'; // Isi dengan VA iPaymu Anda dari Dashboard
$ipaymu_apiKey   = 'QbGcoO0Qds9sQFDmY0MWg1Tq.xtuh1'; // Isi dengan API Key dari Dashboard
$ipaymu_sandbox  = true; // Ubah ke false untuk mode Production (Live)

/**
 * Fungsi pembantu untuk mengirim HTTP Request ke iPaymu API v2
 */
function sendIPaymuRequest($endpointUrl, $va, $apiKey, array $bodyData) {
    $jsonBody      = json_encode($bodyData, JSON_UNESCAPED_SLASHES);
    $requestBody   = strtolower(hash('sha256', $jsonBody));
    $stringToSign  = 'POST:' . $va . ':' . $requestBody . ':' . $apiKey;
    $signature     = hash_hmac('sha256', $stringToSign, $apiKey);
    $timestamp     = date('YmdHis');

    $headers = [
        'Accept: application/json',
        'Content-Type: application/json',
        'va: ' . $va,
        'signature: ' . $signature,
        'timestamp: ' . $timestamp
    ];

    $ch = curl_init($endpointUrl);
    curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // Pada production server pastikan bernilai true
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

    $response = curl_exec($ch);
    $err      = curl_error($ch);
    curl_close($ch);

    if ($err) {
        return ['status' => false, 'message' => 'cURL Error: ' . $err];
    }

    return ['status' => true, 'raw' => $response, 'data' => json_decode($response, true)];
}

// Cek dan tambahkan field email jika belum ada
try {
    $result = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='transaksi_stiker' AND column_name='email'");
    if ($result->rowCount() == 0) {
        $pdo->exec("ALTER TABLE transaksi_stiker ADD COLUMN email VARCHAR(100);");
    }
} catch (PDOException $e) {
    // Optional: log error, tapi jangan hentikan proses
}

// Cek dan tambahkan field no_kartu jika belum ada
try {
    $result = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name='transaksi_stiker' AND column_name='no_kartu'");
    if ($result->rowCount() == 0) {
        $pdo->exec("ALTER TABLE transaksi_stiker ADD COLUMN no_kartu VARCHAR(100);");
    }
} catch (PDOException $e) {
    // Optional: log error, tapi jangan hentikan proses
}

$pageTitle         = "Input Member Baru";
$error             = '';
$success           = '';
$showCetakKwitansi = false;
$last_notrans      = '';
$ipaymuResult      = null;

// Ambil daftar jenis mobil
$jenis_mobil_list = [];
try {
    $stmtJenisMobil  = $pdo->query("SELECT id, nama FROM public.jenis_mobil ORDER BY nama");
    $jenis_mobil_list = $stmtJenisMobil->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error saat mengambil data master: " . $e->getMessage();
}

// Logika untuk menyimpan data (ketika form disubmit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Data Personal
    $nama       = strtoupper($_POST['nama'] ?? '');
    $email      = strtolower($_POST['email'] ?? '');
    $alamat     = strtoupper($_POST['alamat'] ?? '');
    $telepon    = strtoupper($_POST['telepon'] ?? '');
    $no_id      = strtoupper($_POST['no_id'] ?? '');
    $no_kartu   = strtoupper($_POST['no_kartu'] ?? '');
    $unit_kerja = strtoupper($_POST['unit_kerja'] ?? '');

    // Data Kendaraan
    $nopol          = strtoupper($_POST['nopol'] ?? '');
    $jenis_mobil_id = $_POST['jenis_mobil_id'] ?? '';
    $merk           = strtoupper($_POST['merk'] ?? '');
    $tipe           = strtoupper($_POST['tipe'] ?? '');
    $tahun          = strtoupper($_POST['tahun'] ?? '');
    $warna          = strtoupper($_POST['warna'] ?? '');

    // Data Langganan & Metode Pembayaran
    $jenis_langganan = strtoupper($_POST['jenis_langganan'] ?? '');
    $harga           = $_POST['harga'] ?? '';
    $masa_berlaku    = $_POST['masa_berlaku'] ?? '';
    $cara_pembayaran  = $_POST['cara_pembayaran'] ?? 'TUNAI'; // TUNAI atau IPAYMU
    $payment_channel = $_POST['payment_channel'] ?? 'bca';     // bca, mandiri, qris, dll

    if (empty($nama) || empty($nopol) || empty($jenis_mobil_id) || empty($jenis_langganan) || empty($masa_berlaku)) {
        $error = "Nama, No. Polisi, Jenis Mobil, Produk Langganan, dan Masa Berlaku harus diisi.";
    } elseif (!is_numeric($harga) || $harga < 0) {
        $error = "Harga harus berupa angka dan tidak boleh negatif.";
    } else {
        // Check if nopol already exists
        try {
            $stmtCheck = $pdo->prepare("SELECT COUNT(*) as count FROM mergetransaksistikerdetail WHERE nopol = :nopol");
            $stmtCheck->execute([':nopol' => $nopol]);
            $result = $stmtCheck->fetch(PDO::FETCH_ASSOC);

            if ($result['count'] > 0) {
                $error = "No. Polisi sudah terdaftar sebagai member. Silakan gunakan No. Polisi yang berbeda.";
            } else {
                // Convert masa_berlaku to DateTime objects
                $awal_date  = new DateTime(date('Y-m-d'));
                $akhir_date = new DateTime($masa_berlaku);
                $akhir_date->setTime(00, 00, 00);

                $notrans      = 'STK/' . date('Ymd') . '/' . strtoupper(uniqid());
                $last_notrans = $notrans;

                if (empty($no_id)) {
                    $no_id = 'MEMBER-' . time();
                }

                $pdo->beginTransaction();

                // Insert ke transaksi_stiker
                $stmt1 = $pdo->prepare(
                    "INSERT INTO transaksi_stiker (notrans, nama, alamat, telepon, no_id, unit_kerja, awal, akhir, harga, tanggal, operator, jenis_transaksi, tgl_edited, email, no_induk) "
                    . "VALUES (:notrans, :nama, :alamat, :telepon, :no_id, :unit_kerja, :awal, :akhir, :harga, NOW(), :operator, 0, NOW(), :email, :no_kartu)"
                );
                $stmt1->execute([
                    ':notrans'    => $notrans,
                    ':nama'       => $nama,
                    ':alamat'     => $alamat,
                    ':telepon'    => $telepon,
                    ':no_id'      => $notrans,
                    ':unit_kerja' => $unit_kerja,
                    ':awal'       => $awal_date->format('Y-m-d H:i:s'),
                    ':akhir'      => $akhir_date->format('Y-m-d H:i:s'),
                    ':harga'      => $harga,
                    ':operator'   => $_SESSION['username'],
                    ':email'      => $email,
                    ':no_kartu'   => $no_kartu
                ]);

                // Insert ke detail_transaksi_stiker
                $stmt2 = $pdo->prepare(
                    "INSERT INTO detail_transaksi_stiker (notrans, nopol, jenis_mobil, merk, tipe, tahun, warna, jenis_member, status) "
                    . "VALUES (:notrans, :nopol, :jenis_mobil, :merk, :tipe, :tahun, :warna, :jenis_member, 1)"
                );
                $stmt2->execute([
                    ':notrans'      => $notrans,
                    ':nopol'        => $nopol,
                    ':jenis_mobil'  => $jenis_mobil_id,
                    ':merk'         => $merk,
                    ':tipe'         => $tipe,
                    ':tahun'        => $tahun,
                    ':warna'        => $warna,
                    ':jenis_member' => $jenis_langganan
                ]);

                $pdo->commit();

                // JIKA METODE PEMBAYARAN ADALAH IPAYMU
                if ($cara_pembayaran === 'IPAYMU' && floatval($harga) > 0) {
                    $endpointUrl = $ipaymu_sandbox 
                        ? 'https://sandbox.ipaymu.com/api/v2/payment/direct' 
                        : 'https://my.ipaymu.com/api/v2/payment/direct';

                    $paymentBody = [
                        'name'           => trim($nama),
                        'phone'          => !empty($telepon) ? trim($telepon) : "081234567890",
                        'email'          => !empty($email) ? trim($email) : "member@example.com",
                        'amount'         => floatval($harga),
                        'notifyUrl'      => 'https://' . $_SERVER['HTTP_HOST'] . '/callback-ipaymu.php', // Ubah URL Callback
                        'referenceId'    => $notrans,
                        'paymentMethod'  => 'va', // Metode default VA
                        'paymentChannel' => strtolower($payment_channel)
                    ];

                    $resIPaymu = sendIPaymuRequest($endpointUrl, $ipaymu_va, $ipaymu_apiKey, $paymentBody);

                    if ($resIPaymu['status'] && isset($resIPaymu['data']['Status']) && $resIPaymu['data']['Status'] == 200) {
                        $ipaymuResult = $resIPaymu['data']['Data'];

                        // Jika iPaymu berhasil, baru simpan data ke database
                        $pdo->beginTransaction();

                        // Insert ke transaksi_stiker
                        $stmt1 = $pdo->prepare(
                            "INSERT INTO transaksi_stiker (notrans, nama, alamat, telepon, no_id, unit_kerja, awal, akhir, harga, tanggal, operator, jenis_transaksi, tgl_edited, email, no_induk) "
                            . "VALUES (:notrans, :nama, :alamat, :telepon, :no_id, :unit_kerja, :awal, :akhir, :harga, NOW(), :operator, 0, NOW(), :email, :no_kartu)"
                        );
                        $stmt1->execute([
                            ':notrans'    => $notrans,
                            ':nama'       => $nama,
                            ':alamat'     => $alamat,
                            ':telepon'    => $telepon,
                            ':no_id'      => $notrans,
                            ':unit_kerja' => $unit_kerja,
                            ':awal'       => $awal_date->format('Y-m-d H:i:s'),
                            ':akhir'      => $akhir_date->format('Y-m-d H:i:s'),
                            ':harga'      => $harga,
                            ':operator'   => $_SESSION['username'],
                            ':email'      => $email,
                            ':no_kartu'   => $no_kartu
                        ]);

                        // Update no_induk in the new transaction if provided
                        if (!empty($no_induk)) {
                            $stmt_update_no_induk = $pdo->prepare("UPDATE transaksi_stiker SET no_induk = :no_induk WHERE notrans = :notrans");
                            $stmt_update_no_induk->execute([
                                ':no_induk' => $no_induk,
                                ':notrans' => $notrans
                            ]);
                        }

                        // Insert ke detail_transaksi_stiker
                        $stmt2 = $pdo->prepare(
                            "INSERT INTO detail_transaksi_stiker (notrans, nopol, jenis_mobil, merk, tipe, tahun, warna, jenis_member, status) "
                            . "VALUES (:notrans, :nopol, :jenis_mobil, :merk, :tipe, :tahun, :warna, :jenis_member, 1)"
                        );
                        $stmt2->execute([
                            ':notrans'      => $notrans,
                            ':nopol'        => $nopol,
                            ':jenis_mobil'  => $jenis_mobil_id,
                            ':merk'         => $merk,
                            ':tipe'         => $tipe,
                            ':tahun'        => $tahun,
                            ':warna'        => $warna,
                            ':jenis_member' => $jenis_langganan
                        ]);

                        $pdo->commit();
                        $success = "Member baru berhasil didaftarkan! Silakan lakukan pembayaran sesuai instruksi Virtual Account di bawah.";

                    } else {
                        $errorMsg = $resIPaymu['data']['Message'] ?? $resIPaymu['message'] ?? 'Gagal menghubungkan ke iPaymu.';
                        $error = "Gagal membuat tagihan pembayaran online, data tidak disimpan. Error: " . $errorMsg;
                    }
                } else {
                    // Untuk pembayaran TUNAI, simpan langsung
                    $pdo->beginTransaction();

                    // Insert ke transaksi_stiker
                    $stmt1 = $pdo->prepare(
                        "INSERT INTO transaksi_stiker (notrans, nama, alamat, telepon, no_id, unit_kerja, awal, akhir, harga, tanggal, operator, jenis_transaksi, tgl_edited, email, no_induk) "
                        . "VALUES (:notrans, :nama, :alamat, :telepon, :no_id, :unit_kerja, :awal, :akhir, :harga, NOW(), :operator, 0, NOW(), :email, :no_kartu)"
                    );
                    $stmt1->execute([
                        ':notrans'    => $notrans,
                        ':nama'       => $nama,
                        ':alamat'     => $alamat,
                        ':telepon'    => $telepon,
                        ':no_id'      => $notrans,
                        ':unit_kerja' => $unit_kerja,
                        ':awal'       => $awal_date->format('Y-m-d H:i:s'),
                        ':akhir'      => $akhir_date->format('Y-m-d H:i:s'),
                        ':harga'      => $harga,
                        ':operator'   => $_SESSION['username'],
                        ':email'      => $email,
                        ':no_kartu'   => $no_kartu
                    ]);

                    // Update no_induk in the new transaction if provided
                    if (!empty($no_induk)) {
                        $stmt_update_no_induk = $pdo->prepare("UPDATE transaksi_stiker SET no_induk = :no_induk WHERE notrans = :notrans");
                        $stmt_update_no_induk->execute([
                            ':no_induk' => $no_induk,
                            ':notrans' => $notrans
                        ]);
                    }

                    // Insert ke detail_transaksi_stiker
                    $stmt2 = $pdo->prepare(
                        "INSERT INTO detail_transaksi_stiker (notrans, nopol, jenis_mobil, merk, tipe, tahun, warna, jenis_member, status) "
                        . "VALUES (:notrans, :nopol, :jenis_mobil, :merk, :tipe, :tahun, :warna, :jenis_member, 1)"
                    );
                    $stmt2->execute([
                        ':notrans'      => $notrans,
                        ':nopol'        => $nopol,
                        ':jenis_mobil'  => $jenis_mobil_id,
                        ':merk'         => $merk,
                        ':tipe'         => $tipe,
                        ':tahun'        => $tahun,
                        ':warna'        => $warna,
                        ':jenis_member' => $jenis_langganan
                    ]);

                    $pdo->commit();
                    $success = "Member baru berhasil didaftarkan dengan No. Transaksi: " . $notrans;
                    $showCetakKwitansi = true;
                }

                // Kirim email hanya jika pendaftaran berhasil (baik tunai maupun ipaymu)
                if ($success) {
                    $_SESSION['last_kwitansi'] = [
                        'notrans'         => $notrans,
                        'nama'            => $nama,
                        'jenis_langganan' => $jenis_langganan,
                        'harga'           => $harga,
                        'awal'            => $awal_date->format('Y-m-d H:i:s'),
                        'akhir'           => $akhir_date->format('Y-m-d H:i:s'),
                        'operator'        => $_SESSION['username']
                    ];

                    // Kirim email bukti bayar/kwitansi dengan PHPMailer jika email diisi
                    if (!empty($email)) {
                        $subject = "Bukti Pendaftaran Member Parkir - " . $notrans;
                        $message = "Terima kasih telah mendaftar sebagai member parkir. Berikut detail transaksi Anda:\n\n" .
                            "No. Transaksi: $notrans\n" .
                            "Nama: $nama\n" .
                            "No. Polisi: $nopol\n" .
                            "Jenis Langganan: $jenis_langganan\n" .
                            "Harga: Rp " . number_format($harga, 0, ',', '.') . "\n" .
                            "Masa Aktif: " . $awal_date->format('d-m-Y') . " s/d " . $akhir_date->format('d-m-Y') . "\n" .
                            "Operator: " . $_SESSION['username'] . "\n\n";

                        if ($ipaymuResult) {
                            $message .= "--- DETAIL PEMBAYARAN ONLINE (iPaymu) ---\n" .
                                "Bank/Channel: " . strtoupper($payment_channel) . "\n" .
                                "Nomor VA / Kode Bayar: " . ($ipaymuResult['PaymentNo'] ?? '-') . "\n" .
                                "Total Bayar: Rp " . number_format($ipaymuResult['Total'] ?? $harga, 0, ',', '.') . "\n\n";
                        }

                        $message .= "Bukti ini dapat digunakan sebagai rujukan pembayaran.\n\nSalam,\nAdmin Parkir";

                        $mail = new PHPMailer(true);
                        try {
                            $mail->isSMTP();
                            $mail->Host       = 'royalparking@royaldivisiit.org';
                            $mail->SMTPAuth   = true;
                            $mail->Username   = 'royalparking@royaldivisiit.org';
                            $mail->Password   = 'Pr4s3ty0++';
                            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
                            $mail->Port       = 587;
                            $mail->setFrom('royalparking@royaldivisiit.org', 'Admin Parkir - ' . $location);
                            $mail->addAddress($email, $nama);
                            $mail->isHTML(false);
                            $mail->Subject = $subject;
                            $mail->Body    = $message;
                            $mail->send();
                        } catch (Exception $e) {
                            // Optional: log error
                        }
                    }
                }
            }
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $error = "Error saat memeriksa / menyimpan data: " . $e->getMessage();
        }
    }
}

ob_start();
?>

<?php if ($error): ?>
    <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($error) ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success d-flex justify-content-between align-items-center mb-3">
        <span><i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($success) ?></span>
        <?php if ($showCetakKwitansi): ?>
            <a href="cetak-kwitansi.php?source=input-baru&notrans=<?= htmlspecialchars($last_notrans) ?>" target="_blank" class="btn btn-sm btn-outline-success"> <i class="fas fa-print"></i> Cetak Kwitansi</a>
        <?php endif; ?>
    </div>

    <!-- TAMPILAN RESI / INSTRUKSI VA IPAYMU -->
    <?php if ($ipaymuResult): ?>
        <div class="card border-primary mb-4">
            <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="fas fa-credit-card me-2"></i>Instruksi Pembayaran Virtual Account (iPaymu)</h5>
                <span class="badge bg-light text-primary"><?= strtoupper(htmlspecialchars($ipaymuResult['PaymentChannel'] ?? 'VA')) ?></span>
            </div>
            <div class="card-body">
                <div class="row text-center">
                    <div class="col-md-6 mb-3 border-end">
                        <small class="text-muted d-block mb-1">Nomor Virtual Account / Kode Bayar</small>
                        <h2 class="fw-bold text-success select-all"><?= htmlspecialchars($ipaymuResult['PaymentNo'] ?? '-') ?></h2>
                    </div>
                    <div class="col-md-6 mb-3">
                        <small class="text-muted d-block mb-1">Total Harus Dibayar</small>
                        <h2 class="fw-bold text-primary">Rp <?= number_format($ipaymuResult['Total'] ?? 0, 0, ',', '.') ?></h2>
                    </div>
                </div>
                <hr>
                <div class="d-flex justify-content-between align-items-center">
                    <small class="text-muted">No. Referensi: <strong><?= htmlspecialchars($last_notrans) ?></strong></small>
                    <a href="cetak-kwitansi.php?source=input-baru&notrans=<?= htmlspecialchars($last_notrans) ?>" target="_blank" class="btn btn-sm btn-primary">
                        <i class="fas fa-print"></i> Cetak Kwitansi / Instruksi
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($showCetakKwitansi && !$ipaymuResult): ?>
    <script>
        // Automatic popup print for Cash payment
        window.open('cetak-kwitansi.php?source=input-baru&notrans=<?= htmlspecialchars($last_notrans) ?>', '_blank');
    </script>
    <?php endif; ?>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">Formulir Pendaftaran Member Baru</h3>
    </div>
    <div class="card-body">
        <form method="POST" action="input-baru.php">
            
            <h5 class="mb-3">Data Personal</h5>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="nama" class="form-label">Nama Lengkap <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="nama" name="nama" required style="text-transform: uppercase">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="email" class="form-label">Alamat Email</label>
                    <input type="email" class="form-control" id="email" name="email">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="telepon" class="form-label">Telepon</label>
                    <input type="text" class="form-control" id="telepon" name="telepon" style="text-transform: uppercase">
                </div>
            </div>
            <div class="mb-3">
                <label for="alamat" class="form-label">Alamat</label>
                <textarea class="form-control" id="alamat" name="alamat" rows="2" style="text-transform: uppercase"></textarea>
            </div>
            <div class="row">
                <div class="col-md-4 mb-3">
                    <label for="no_id" class="form-label">No. ID (Opsional)</label>
                    <input type="text" class="form-control" id="no_id" name="no_id" placeholder="Kosongkan untuk generate otomatis" style="text-transform: uppercase">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="no_kartu" class="form-label">No. Kartu</label>
                    <input type="text" class="form-control" id="no_kartu" name="no_kartu" style="text-transform: uppercase">
                </div>
                <div class="col-md-4 mb-3">
                    <label for="unit_kerja" class="form-label">Unit Kerja</label>
                    <input type="text" class="form-control" id="unit_kerja" name="unit_kerja" style="text-transform: uppercase">
                </div>
            </div>

            <hr class="my-4">

            <h5 class="mb-3">Data Kendaraan</h5>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="nopol" class="form-label">No. Polisi <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="nopol" name="nopol" required style="text-transform: uppercase">
                    <div id="nopol-check-result" class="mt-1"></div>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="jenis_mobil_id" class="form-label">Jenis Mobil <span class="text-danger">*</span></label>
                    <select class="form-select" id="jenis_mobil_id" name="jenis_mobil_id" required>
                        <option value="">-- Pilih Jenis Mobil --</option>
                        <?php foreach ($jenis_mobil_list as $jenis): ?>
                            <option value="<?= htmlspecialchars($jenis['id']) ?>"><?= htmlspecialchars($jenis['nama']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-3 mb-3">
                    <label for="merk" class="form-label">Merk</label>
                    <input type="text" class="form-control" id="merk" name="merk" style="text-transform: uppercase">
                </div>
                <div class="col-md-3 mb-3">
                    <label for="tipe" class="form-label">Tipe</label>
                    <input type="text" class="form-control" id="tipe" name="tipe" style="text-transform: uppercase">
                </div>
                <div class="col-md-3 mb-3">
                    <label for="tahun" class="form-label">Tahun</label>
                    <input type="text" class="form-control" id="tahun" name="tahun" style="text-transform: uppercase">
                </div>
                <div class="col-md-3 mb-3">
                    <label for="warna" class="form-label">Warna</label>
                    <input type="text" class="form-control" id="warna" name="warna">
                </div>
            </div>

            <hr class="my-4">

            <h5 class="mb-3">Paket Langganan & Pembayaran</h5>
            <div class="mb-3">
                <label for="jenis_langganan" class="form-label">Produk Langganan <span class="text-danger">*</span></label>
                <select class="form-select" id="jenis_langganan" name="jenis_langganan" required>
                    <option value="">-- Pilih Produk Langganan --</option>
                </select>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="harga" class="form-label">Harga (Rp)</label>
                    <input type="number" class="form-control" id="harga" name="harga" min="0" value="0" readonly>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="masa_berlaku" class="form-label">Masa Aktif s/d <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="masa_berlaku" name="masa_berlaku" required readonly>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="cara_pembayaran" class="form-label">Metode Pembayaran <span class="text-danger">*</span></label>
                    <select class="form-select" id="cara_pembayaran" name="cara_pembayaran" required>
                        <option value="TUNAI">Tunai (Cash / Bayar di Kasir)</option>
                        <option value="IPAYMU">iPaymu (Online / Virtual Account)</option>
                    </select>
                </div>
                <div class="col-md-6 mb-3" id="box-payment-channel" style="display: none;">
                    <label for="payment_channel" class="form-label">Pilihan Bank (Channel VA)</label>
                    <select class="form-select" id="payment_channel" name="payment_channel">
                        <option value="bca">Virtual Account BCA</option>
                        <option value="mandiri">Virtual Account Mandiri</option>
                        <option value="bni">Virtual Account BNI</option>
                        <option value="bri">Virtual Account BRI</option>
                        <option value="cimb">Virtual Account CIMB Niaga</option>
                        <option value="permata">Virtual Account Permata</option>
                    </select>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i> Daftarkan Member</button>
                <a href="transaksi-member.php" class="btn btn-secondary">Kembali ke Pencarian</a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const nopolInput = document.getElementById('nopol');
    const nopolCheckResult = document.getElementById('nopol-check-result');
    const jenisMobilSelect = document.getElementById('jenis_mobil_id');
    const jenisLanggananSelect = document.getElementById('jenis_langganan');
    const hargaInput = document.getElementById('harga');
    const masaBerlakuInput = document.getElementById('masa_berlaku');
    const caraPembayaranSelect = document.getElementById('cara_pembayaran');
    const boxPaymentChannel = document.getElementById('box-payment-channel');

    // Toggle Payment Channel Dropdown
    caraPembayaranSelect.addEventListener('change', function() {
        if (this.value === 'IPAYMU') {
            boxPaymentChannel.style.display = 'block';
        } else {
            boxPaymentChannel.style.display = 'none';
        }
    });

    // Function to check license plate
    function checkNopol(nopol) {
        if (nopol.length < 3) {
            nopolCheckResult.innerHTML = '';
            return;
        }
        fetch(`check_nopol.php?nopol=${encodeURIComponent(nopol)}`)
            .then(response => response.json())
            .then(data => {
                if (data.exists) {
                    nopolCheckResult.innerHTML = '<small class="text-warning"><i class="fas fa-exclamation-triangle"></i> No. Polisi sudah terdaftar sebagai member</small>';
                } else {
                    nopolCheckResult.innerHTML = '<small class="text-success"><i class="fas fa-check"></i> No. Polisi tersedia</small>';
                }
            })
            .catch(error => {
                console.error('Error checking nopol:', error);
                nopolCheckResult.innerHTML = '';
            });
    }

    // Event listener for nopol input
    nopolInput.addEventListener('input', function() {
        const nopol = this.value.trim().toUpperCase();
        checkNopol(nopol);
    });

    jenisMobilSelect.addEventListener('change', function() {
        const idMobil = this.value;
        if (idMobil) {
            fetch(`get_tarif.php?id_mobil=${idMobil}`)
                .then(response => response.json())
                .then(data => {
                    jenisLanggananSelect.innerHTML = '<option value="">-- Pilih Produk Langganan --</option>';
                    data.forEach(item => {
                        const option = document.createElement('option');
                        option.value = item.jenis_langganan;
                        option.textContent = item.jenis_langganan;
                        option.dataset.tarif = item.tarif;
                        option.dataset.tglAkhir = item.tgl_akhir;
                        option.dataset.lastMember = item.last_member;
                        jenisLanggananSelect.appendChild(option);
                    });
                })
                .catch(error => console.error('Error fetching tarif:', error));
        } else {
            jenisLanggananSelect.innerHTML = '<option value="">-- Pilih Produk Langganan --</option>';
            hargaInput.value = '0';
            masaBerlakuInput.value = '';
        }
    });

    jenisLanggananSelect.addEventListener('change', function() {
        const selectedOption = this.options[this.selectedIndex];
        if (selectedOption && selectedOption.dataset.tarif) {
            hargaInput.value = selectedOption.dataset.tarif;
            let lastMember = parseInt(selectedOption.dataset.lastMember);
            if (lastMember === 99) {
                lastMember = 365;
            }
            const currentDate = new Date();
            currentDate.setDate(currentDate.getDate() + lastMember);
            masaBerlakuInput.value = currentDate.toISOString().split('T')[0];
        } else {
            hargaInput.value = '0';
            masaBerlakuInput.value = '';
        }
    });
});
</script>

<?php
$content = ob_get_clean();

require 'layout.php';
?>