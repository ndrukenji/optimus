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

$pageTitle = "Mutasi Kendaraan";
$notrans = $_GET['notrans'] ?? '';
$memberData = null;
$error = '';
$success = '';

if (empty($notrans)) {
    die("Error: No Transaksi tidak ditemukan.");
}

// Ambil data dari database
try {
    $stmt = $pdo->prepare("SELECT t.*, d.nopol, d.jenis_mobil, d.status as status_kendaraan FROM transaksi_stiker t LEFT JOIN detail_transaksi_stiker d ON t.notrans = d.notrans WHERE t.notrans = :notrans");
    $stmt->execute([':notrans' => $notrans]);
    $memberData = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error saat mengambil data: " . $e->getMessage();
}

if (!$memberData) {
    die("Error: Data dengan No Transaksi '{$notrans}' tidak ditemukan.");
}

// Logika untuk update data (ketika form disubmit)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Ambil data dari form
    $submitted_token = $_POST['csrf_token'] ?? '';
    if (!isset($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $submitted_token)) {
        $error = "Sesi tidak valid atau telah kedaluwarsa. Silakan coba lagi.";
        // Hentikan eksekusi lebih lanjut jika token tidak valid
        goto end_of_post_logic;
    }
    $nopol_baru = trim($_POST['nopol_baru'] ?? '');
    $konfirmasi_nopol = trim($_POST['konfirmasi_nopol'] ?? '');

    // Validasi
    if (empty($nopol_baru)) {
        $error = "No. Polisi baru tidak boleh kosong.";
    } elseif ($nopol_baru !== $konfirmasi_nopol) {
        $error = "Konfirmasi No. Polisi tidak cocok.";
    } elseif ($nopol_baru === $memberData['nopol']) {
        $error = "No. Polisi baru tidak boleh sama dengan No. Polisi lama.";
    } else {
        try {
            $pdo->beginTransaction();

            // Update tabel detail_transaksi_stiker
            $stmt = $pdo->prepare("UPDATE detail_transaksi_stiker SET nopol = :nopol_baru WHERE notrans = :notrans");
            $stmt->execute([
                ':nopol_baru' => $nopol_baru,
                ':notrans' => $notrans
            ]);

            // Insert log mutasi ke tabel history_mutasi (jika ada)
            // Jika tabel tidak ada, bisa di-comment atau dibuat tabel baru
            /*
            $stmt_log = $pdo->prepare("INSERT INTO history_mutasi (notrans, nopol_lama, nopol_baru, tgl_mutasi, user_mutasi) VALUES (:notrans, :nopol_lama, :nopol_baru, NOW(), :user)");
            $stmt_log->execute([
                ':notrans' => $notrans,
                ':nopol_lama' => $memberData['nopol'],
                ':nopol_baru' => $nopol_baru,
                ':user' => $_SESSION['username']
            ]);
            */

            $pdo->commit();
            $success = "Mutasi kendaraan berhasil dilakukan. No. Polisi berhasil diubah dari '" . htmlspecialchars($memberData['nopol']) . "' menjadi '" . htmlspecialchars($nopol_baru) . "'.";

            // Refresh data setelah update
            $stmt = $pdo->prepare("SELECT t.*, d.nopol, d.jenis_mobil, d.status as status_kendaraan FROM transaksi_stiker t LEFT JOIN detail_transaksi_stiker d ON t.notrans = d.notrans WHERE t.notrans = :notrans");
            $stmt->execute([':notrans' => $notrans]);
            $memberData = $stmt->fetch(PDO::FETCH_ASSOC);

        } catch (PDOException $e) {
            $pdo->rollBack();
            $error = "Error saat melakukan mutasi: " . $e->getMessage();
        }
    }

    end_of_post_logic:
}

ob_start();
?>

<style>
.mutasi-form {
    max-width: 600px;
    margin: 0 auto;
}

.current-info {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 2rem;
}

.warning-box {
    background: #fff3cd;
    border: 1px solid #ffeaa7;
    border-radius: 8px;
    padding: 1rem;
    margin-bottom: 2rem;
}

.warning-box .fas {
    color: #856404;
}
</style>

<?php if ($error): ?>
    <div class="alert alert-danger">
        <i class="fas fa-exclamation-triangle me-2"></i>
        <?= $error ?>
    </div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle me-2"></i>
        <?= $success ?>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">
            <i class="fas fa-exchange-alt me-2"></i>
            Mutasi Kendaraan - <?= htmlspecialchars($memberData['nama'] ?? '') ?>
        </h3>
    </div>
    <div class="card-body">
        <!-- Info Kendaraan Saat Ini -->
        <div class="current-info">
            <h5 class="mb-3">
                <i class="fas fa-info-circle me-2"></i>
                Informasi Kendaraan Saat Ini
            </h5>
            <div class="row">
                <div class="col-md-6">
                    <strong>No. Transaksi:</strong><br>
                    <span class="text-primary"><?= htmlspecialchars($memberData['notrans'] ?? '') ?></span>
                </div>
                <div class="col-md-6">
                    <strong>Nama Member:</strong><br>
                    <span class="text-primary"><?= htmlspecialchars($memberData['nama'] ?? '') ?></span>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-6">
                    <strong>No. Polisi Saat Ini:</strong><br>
                    <span class="badge bg-primary fs-6"><?= htmlspecialchars($memberData['nopol'] ?? '') ?></span>
                </div>
                <div class="col-md-6">
                    <strong>Jenis Kendaraan:</strong><br>
                    <span class="badge bg-secondary"><?= htmlspecialchars($memberData['jenis_mobil'] ?? '') ?></span>
                </div>
            </div>
        </div>

        <!-- Warning Box -->
        <div class="warning-box">
            <i class="fas fa-exclamation-triangle me-2"></i>
            <strong>Peringatan:</strong> Mutasi kendaraan akan mengubah nomor polisi secara permanen.
            Pastikan data yang dimasukkan sudah benar dan sesuai dengan kondisi kendaraan yang sebenarnya.
        </div>

        <!-- Form Mutasi -->
        <form method="POST" action="mutasi-kendaraan.php?notrans=<?= htmlspecialchars($notrans) ?>" class="mutasi-form">
            <h5 class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token']) ?>">
                <i class="fas fa-edit me-2"></i>
                Data Mutasi Kendaraan
            </h5>

            <div class="row">
                <div class="col-md-6 mb-3">
                    <label for="nopol_baru" class="form-label">
                        <i class="fas fa-car me-1"></i>
                        No. Polisi Baru <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control form-control-lg" id="nopol_baru" name="nopol_baru"
                           placeholder="Masukkan No. Polisi baru..."
                           value="<?= htmlspecialchars($_POST['nopol_baru'] ?? '') ?>"
                           required
                           style="text-transform: uppercase; font-weight: bold;">
                    <div class="form-text">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Format: AB 1234 CD atau B 1234 ABC
                        </small>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <label for="konfirmasi_nopol" class="form-label">
                        <i class="fas fa-check-circle me-1"></i>
                        Konfirmasi No. Polisi <span class="text-danger">*</span>
                    </label>
                    <input type="text" class="form-control form-control-lg" id="konfirmasi_nopol" name="konfirmasi_nopol"
                           placeholder="Konfirmasi No. Polisi baru..."
                           value="<?= htmlspecialchars($_POST['konfirmasi_nopol'] ?? '') ?>"
                           required
                           style="text-transform: uppercase;">
                    <div class="form-text">
                        <small class="text-muted">
                            <i class="fas fa-info-circle me-1"></i>
                            Masukkan ulang No. Polisi untuk konfirmasi
                        </small>
                    </div>
                </div>
            </div>

            <div class="mt-4">
                <button type="submit" class="btn btn-success btn-lg">
                    <i class="fas fa-exchange-alt me-2"></i>
                    Lakukan Mutasi Kendaraan
                </button>
                <a href="transaksi-member.php" class="btn btn-secondary btn-lg ms-2">
                    <i class="fas fa-arrow-left me-1"></i>
                    Kembali ke Pencarian
                </a>
            </div>
        </form>
    </div>
</div>

<script>
// Auto-uppercase untuk input nomor polisi
document.getElementById('nopol_baru').addEventListener('input', function(e) {
    e.target.value = e.target.value.toUpperCase();
});

document.getElementById('konfirmasi_nopol').addEventListener('input', function(e) {
    e.target.value = e.target.value.toUpperCase();
});

// Validasi real-time
document.getElementById('konfirmasi_nopol').addEventListener('input', function() {
    const nopolBaru = document.getElementById('nopol_baru').value;
    const konfirmasi = this.value;

    if (konfirmasi && nopolBaru !== konfirmasi) {
        this.setCustomValidity('Konfirmasi No. Polisi tidak cocok');
    } else {
        this.setCustomValidity('');
    }
});
</script>

<?php
$content = ob_get_clean();

// Menggabungkan layout
require 'layout.php';
?>