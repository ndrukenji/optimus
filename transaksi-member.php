<?php
/**
 * Halaman Pencarian & Daftar Member Parkir
 */
require_once 'config.php';

if (!isset($_SESSION['username']) || !isset($_SESSION['location'])) {
    header('Location: login.php');
    exit;
}

$location = $_SESSION['location'];
$pdo = connectDB($location);

$pageTitle = "Data & Pencarian Member Parkir";
$keyword   = trim($_GET['q'] ?? '');
$filter    = trim($_GET['status'] ?? 'all'); // all, active, expired

$query = "SELECT t.*, d.nopol, d.jenis_mobil, d.merk, d.tipe, d.warna, d.jenis_member, d.status as status_kendaraan 
          FROM transaksi_stiker t 
          LEFT JOIN detail_transaksi_stiker d ON t.notrans = d.notrans 
          WHERE 1=1";
$params = [];

if (!empty($keyword)) {
    $query .= " AND (UPPER(t.nama) LIKE :kw OR UPPER(d.nopol) LIKE :kw OR UPPER(t.notrans) LIKE :kw OR UPPER(t.no_id) LIKE :kw OR UPPER(t.no_induk) LIKE :kw)";
    $params[':kw'] = '%' . strtoupper($keyword) . '%';
}

if ($filter === 'active') {
    $query .= " AND (t.status_bayar IS NULL OR t.status_bayar = 'LUNAS') AND t.akhir >= NOW() AND d.status = 1";
} elseif ($filter === 'pending') {
    $query .= " AND t.status_bayar = 'PENDING'";
} elseif ($filter === 'expired') {
    $query .= " AND t.akhir < NOW() AND (t.status_bayar IS NULL OR t.status_bayar = 'LUNAS')";
}

$query .= " ORDER BY t.tanggal DESC LIMIT 50";

try {
    $stmt = $pdo->prepare($query);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $error = "Error saat mengambil data: " . $e->getMessage();
    $members = [];
}

ob_start();
?>

<div class="row mb-4">
    <div class="col-md-8">
        <h2 class="fw-bold mb-1"><i class="fas fa-id-card text-primary me-2"></i>Pencarian Data Member</h2>
        <p class="text-muted">Cari member berdasarkan Plat Nomor, Nama, No. Transaksi, atau No. Kartu.</p>
    </div>
    <div class="col-md-4 text-md-end align-self-center">
        <a href="input-baru.php" class="btn btn-primary">
            <i class="fas fa-plus-circle me-1"></i> Registrasi Member Baru
        </a>
    </div>
</div>

<!-- Form Pencarian & Filter -->
<div class="card mb-4">
    <div class="card-body">
        <form method="GET" action="transaksi-member.php" class="row g-3 align-items-center">
            <div class="col-md-6">
                <div class="input-group">
                    <span class="input-group-text bg-white"><i class="fas fa-search text-muted"></i></span>
                    <input type="text" class="form-control" name="q" placeholder="Ketik Plat Nomor, Nama, No. Transaksi..." value="<?= htmlspecialchars($keyword) ?>" autofocus>
                </div>
            </div>
            <div class="col-md-3">
                <select name="status" class="form-select">
                    <option value="all" <?= ($filter === 'all') ? 'selected' : '' ?>>Semua Status</option>
                    <option value="active" <?= ($filter === 'active') ? 'selected' : '' ?>>Aktif / Lunas</option>
                    <option value="pending" <?= ($filter === 'pending') ? 'selected' : '' ?>>Menunggu Pembayaran (Pending)</option>
                    <option value="expired" <?= ($filter === 'expired') ? 'selected' : '' ?>>Kadaluwarsa</option>
                </select>
            </div>
            <div class="col-md-3 d-flex gap-2">
                <button type="submit" class="btn btn-dark w-100"><i class="fas fa-filter me-1"></i> Cari</button>
                <?php if (!empty($keyword) || $filter !== 'all'): ?>
                    <a href="transaksi-member.php" class="btn btn-outline-secondary"><i class="fas fa-rotate-left"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Tabel Daftar Member -->
<div class="card">
    <div class="card-header bg-white py-3 d-flex justify-content-between align-items-center">
        <h5 class="card-title mb-0 fw-bold">Hasil Pencarian (<?= count($members) ?> data)</h5>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>No. Transaksi</th>
                        <th>Member</th>
                        <th>Kendaraan & Plat</th>
                        <th>Masa Berlaku</th>
                        <th>Status Pembayaran</th>
                        <th class="text-center" style="width: 250px;">Aksi</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($members)): ?>
                        <tr>
                            <td colspan="6" class="text-center py-5 text-muted">
                                <i class="fas fa-folder-open fa-3x mb-3 text-secondary"></i>
                                <p class="mb-0">Tidak ada data member yang ditemukan.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($members as $m): 
                            $isPending = strtoupper($m['status_bayar'] ?? '') === 'PENDING';
                            $isExpired = strtotime($m['akhir']) < time();
                        ?>
                            <tr>
                                <td>
                                    <span class="fw-semibold text-primary"><?= htmlspecialchars($m['notrans']) ?></span><br>
                                    <small class="text-muted"><i class="fas fa-calendar-day me-1"></i><?= date('d/m/Y', strtotime($m['tanggal'])) ?></small>
                                </td>
                                <td>
                                    <div class="fw-bold"><?= htmlspecialchars($m['nama']) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($m['telepon'] ?: '-') ?> | <?= htmlspecialchars($m['unit_kerja'] ?: '-') ?></small>
                                </td>
                                <td>
                                    <span class="badge bg-dark fs-6 font-monospace"><?= htmlspecialchars($m['nopol'] ?? '-') ?></span><br>
                                    <small class="text-muted"><?= htmlspecialchars($m['merk'] ?? '') ?> <?= htmlspecialchars($m['tipe'] ?? '') ?> (<?= htmlspecialchars($m['warna'] ?? '-') ?>)</small>
                                </td>
                                <td>
                                    <div><i class="fas fa-clock me-1 text-muted"></i><?= date('d/m/Y', strtotime($m['akhir'])) ?></div>
                                    <small class="text-muted"><?= htmlspecialchars($m['jenis_member'] ?? '-') ?></small>
                                </td>
                                <td>
                                    <?php if ($isPending): ?>
                                        <span class="badge bg-warning text-dark"><i class="fas fa-clock me-1"></i> Menunggu Bayar</span><br>
                                        <small><a href="bayar-member.php?notrans=<?= urlencode($m['notrans']) ?>" class="text-primary fw-semibold"><i class="fas fa-qrcode me-1"></i>Instruksi Bayar</a></small>
                                    <?php elseif ($isExpired): ?>
                                        <span class="badge bg-danger"><i class="fas fa-exclamation-circle me-1"></i> Kadaluwarsa</span>
                                    <?php else: ?>
                                        <span class="badge bg-success"><i class="fas fa-check-circle me-1"></i> Aktif / Lunas</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <div class="btn-group btn-group-sm">
                                        <?php if ($isPending): ?>
                                            <a href="bayar-member.php?notrans=<?= urlencode($m['notrans']) ?>" class="btn btn-warning fw-semibold" title="Buka Link Pembayaran">
                                                <i class="fas fa-wallet me-1"></i> Bayar
                                            </a>
                                            <a href="cetak-kwitansi.php?source=input-baru&notrans=<?= urlencode($m['notrans']) ?>" target="_blank" class="btn btn-outline-secondary" title="Cetak Instruksi">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        <?php else: ?>
                                            <a href="perpanjang-member.php?notrans=<?= urlencode($m['notrans']) ?>" class="btn btn-outline-primary" title="Perpanjang Masa Aktif">
                                                <i class="fas fa-calendar-plus me-1"></i> Perpanjang
                                            </a>
                                            <a href="mutasi-kendaraan.php?notrans=<?= urlencode($m['notrans']) ?>" class="btn btn-outline-warning" title="Mutasi Plat Nomor">
                                                <i class="fas fa-exchange-alt me-1"></i> Mutasi
                                            </a>
                                            <a href="cetak-kwitansi.php?source=input-baru&notrans=<?= urlencode($m['notrans']) ?>" target="_blank" class="btn btn-outline-success" title="Cetak Kwitansi">
                                                <i class="fas fa-print"></i>
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
require 'layout.php';
?>
