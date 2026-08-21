<?php
/**
 * Layout Utama Aplikasi Optimus Parking
 * Variabel yang digunakan:
 * - $pageTitle (string)
 * - $content (string HTML yang di-render dari ob_get_clean())
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$currentUser = $_SESSION['username'] ?? 'User';
$currentLoc  = $_SESSION['location'] ?? 'PUSAT';
$pageTitle   = $pageTitle ?? 'Optimus Parking Management';
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> - Optimus Parking</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- FontAwesome 6 -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <!-- Google Fonts -->
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            --primary: #2563eb;
            --primary-hover: #1d4ed8;
            --sidebar-bg: #0f172a;
            --sidebar-hover: #1e293b;
            --bg-main: #f1f5f9;
        }
        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--bg-main);
            color: #334155;
            min-height: 100vh;
        }
        .navbar-custom {
            background-color: #ffffff;
            box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
            padding: 0.75rem 1.5rem;
        }
        .brand-logo {
            font-weight: 700;
            color: #0f172a;
            display: flex;
            align-items: center;
            gap: 10px;
            text-decoration: none;
            font-size: 1.25rem;
        }
        .brand-logo i {
            color: var(--primary);
        }
        .badge-location {
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            color: #fff;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: 0.5px;
        }
        .card {
            border: none;
            border-radius: 12px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05), 0 2px 4px -2px rgba(0, 0, 0, 0.05);
            background: #ffffff;
        }
        .card-header {
            background-color: transparent;
            border-bottom: 1px solid #e2e8f0;
            padding: 1.25rem 1.5rem;
        }
        .card-body {
            padding: 1.5rem;
        }
        .nav-link-custom {
            color: #64748b;
            font-weight: 500;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.2s;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        .nav-link-custom:hover {
            color: var(--primary);
            background-color: #eff6ff;
        }
        .nav-link-custom.active {
            color: var(--primary);
            background-color: #dbeafe;
            font-weight: 600;
        }
        .btn-primary {
            background-color: var(--primary);
            border-color: var(--primary);
        }
        .btn-primary:hover {
            background-color: var(--primary-hover);
            border-color: var(--primary-hover);
        }
    </style>
</head>
<body>

<!-- Navbar -->
<nav class="navbar navbar-expand-lg navbar-custom sticky-top">
    <div class="container-fluid">
        <a class="brand-logo" href="input-baru.php">
            <i class="fas fa-parking fa-lg"></i>
            <span>Optimus Parking</span>
        </a>

        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarContent">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="navbarContent">
            <div class="navbar-nav me-auto ms-lg-4 gap-1">
                <a class="nav-link-custom <?= (basename($_SERVER['PHP_SELF']) === 'transaksi-member.php') ? 'active' : '' ?>" href="transaksi-member.php">
                    <i class="fas fa-id-card"></i> Cari / Daftar Member
                </a>
                <a class="nav-link-custom <?= (basename($_SERVER['PHP_SELF']) === 'input-baru.php') ? 'active' : '' ?>" href="input-baru.php">
                    <i class="fas fa-user-plus"></i> Input Member Baru
                </a>
            </div>

            <div class="d-flex align-items-center gap-3 mt-3 mt-lg-0">
                <span class="badge-location">
                    <i class="fas fa-location-dot me-1"></i> <?= htmlspecialchars($currentLoc) ?>
                </span>
                
                <div class="dropdown">
                    <button class="btn btn-sm btn-light dropdown-toggle d-flex align-items-center gap-2 border" type="button" data-bs-toggle="dropdown">
                        <i class="fas fa-circle-user text-primary fa-lg"></i>
                        <span><?= htmlspecialchars($currentUser) ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end shadow-sm">
                        <li><a class="dropdown-item text-danger" href="logout.php"><i class="fas fa-right-from-bracket me-2"></i> Logout</a></li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</nav>

<!-- Main Container -->
<div class="container py-4">
    <?= $content ?? '' ?>
</div>

<!-- Bootstrap 5 JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
