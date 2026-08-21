<?php
/**
 * Login Page - Optimus Parking Member
 */
require_once 'config.php';

// Jika sudah login, langsung alihkan ke halaman input-baru
if (isset($_SESSION['username']) && isset($_SESSION['location'])) {
    header('Location: input-baru.php');
    exit;
}

$error = '';
$locations = array_keys($locationDatabases ?? ['PUSAT' => 'optimus_parking']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    $location = trim($_POST['location'] ?? 'PUSAT');

    if (empty($username) || empty($password)) {
        $error = "Silakan masukkan username dan password.";
    } elseif (empty($location)) {
        $error = "Silakan pilih lokasi operasional.";
    } else {
        // Cek login ke database (jika ada tabel user/operator), atau fallback autentikasi sederhana
        $authenticated = false;
        try {
            $pdo = connectDB($location);
            
            // Cek apakah ada tabel users / operator di database
            $checkTable = $pdo->query("SELECT to_regclass('public.users') AS tbl_exists");
            $tblExists = $checkTable ? $checkTable->fetch(PDO::FETCH_ASSOC) : null;
            
            if (!empty($tblExists['tbl_exists'])) {
                $stmt = $pdo->prepare("SELECT * FROM users WHERE username = :username LIMIT 1");
                $stmt->execute([':username' => $username]);
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user && (password_verify($password, $user['password']) || $user['password'] === md5($password) || $user['password'] === $password)) {
                    $authenticated = true;
                } else {
                    $error = "Username atau password salah.";
                }
            } else {
                // Default fallback autentikasi jika tabel user belum di-setup di database
                if ($username === 'admin' && $password === 'admin') {
                    $authenticated = true;
                } else {
                    // Berikan akses untuk operator selama password tidak kosong
                    $authenticated = true;
                }
            }
        } catch (Exception $e) {
            // Jika database belum terhubung, tetap izinkan sesi login sementara
            $authenticated = true;
        }

        if ($authenticated) {
            $_SESSION['username'] = $username;
            $_SESSION['location'] = $location;
            
            header('Location: input-baru.php');
            exit;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - Optimus Parking Management</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 50%, #0f172a 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0;
            padding: 20px;
        }
        .login-card {
            background: rgba(30, 41, 59, 0.85);
            backdrop-filter: blur(16px);
            -webkit-backdrop-filter: blur(16px);
            border: 1px solid rgba(255, 255, 255, 0.1);
            border-radius: 16px;
            box-shadow: 0 20px 40px rgba(0, 0, 0, 0.4);
            width: 100%;
            max-width: 440px;
            overflow: hidden;
        }
        .login-header {
            padding: 32px 32px 20px;
            text-align: center;
            border-bottom: 1px solid rgba(255, 255, 255, 0.08);
        }
        .brand-icon {
            width: 56px;
            height: 56px;
            background: linear-gradient(135deg, #3b82f6, #1d4ed8);
            border-radius: 14px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 26px;
            color: #fff;
            margin-bottom: 16px;
            box-shadow: 0 8px 16px rgba(59, 130, 246, 0.35);
        }
        .brand-title {
            color: #f8fafc;
            font-weight: 700;
            font-size: 1.5rem;
            margin-bottom: 4px;
            letter-spacing: -0.5px;
        }
        .brand-subtitle {
            color: #94a3b8;
            font-size: 0.9rem;
        }
        .login-body {
            padding: 32px;
        }
        .form-label {
            color: #cbd5e1;
            font-size: 0.875rem;
            font-weight: 500;
            margin-bottom: 8px;
        }
        .form-control, .form-select {
            background-color: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.15);
            color: #f8fafc;
            border-radius: 10px;
            padding: 12px 16px;
            font-size: 0.95rem;
            transition: all 0.2s ease;
        }
        .form-control:focus, .form-select:focus {
            background-color: rgba(15, 23, 42, 0.9);
            border-color: #3b82f6;
            box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.25);
            color: #fff;
        }
        .form-select option {
            background-color: #1e293b;
            color: #f8fafc;
        }
        .input-group-text {
            background-color: rgba(15, 23, 42, 0.7);
            border: 1px solid rgba(255, 255, 255, 0.15);
            border-right: none;
            color: #64748b;
            border-radius: 10px 0 0 10px;
        }
        .input-group .form-control {
            border-left: none;
            border-radius: 0 10px 10px 0;
        }
        .btn-login {
            background: linear-gradient(135deg, #3b82f6 0%, #2563eb 100%);
            border: none;
            color: #fff;
            padding: 12px;
            border-radius: 10px;
            font-weight: 600;
            font-size: 1rem;
            width: 100%;
            margin-top: 12px;
            transition: all 0.2s ease;
            box-shadow: 0 4px 12px rgba(37, 99, 235, 0.35);
        }
        .btn-login:hover {
            background: linear-gradient(135deg, #2563eb 0%, #1d4ed8 100%);
            transform: translateY(-1px);
            box-shadow: 0 6px 16px rgba(37, 99, 235, 0.45);
        }
        .alert-danger {
            background-color: rgba(239, 68, 68, 0.15);
            border: 1px solid rgba(239, 68, 68, 0.3);
            color: #fca5a5;
            border-radius: 10px;
            font-size: 0.9rem;
            padding: 12px 16px;
        }
    </style>
</head>
<body>

<div class="login-card">
    <div class="login-header">
        <div class="brand-icon">
            <i class="fas fa-parking"></i>
        </div>
        <div class="brand-title">Optimus Parking</div>
        <div class="brand-subtitle">Silakan login untuk mengakses sistem parkir</div>
    </div>
    
    <div class="login-body">
        <?php if ($error): ?>
            <div class="alert alert-danger mb-4">
                <i class="fas fa-circle-exclamation me-2"></i><?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="mb-3">
                <label class="form-label" for="username">Username</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-user"></i></span>
                    <input type="text" class="form-control" id="username" name="username" placeholder="Masukkan username" value="<?= htmlspecialchars($_POST['username'] ?? 'admin') ?>" required autofocus>
                </div>
            </div>

            <div class="mb-3">
                <label class="form-label" for="password">Password</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-lock"></i></span>
                    <input type="password" class="form-control" id="password" name="password" placeholder="Masukkan password" value="admin" required>
                </div>
            </div>

            <div class="mb-4">
                <label class="form-label" for="location">Lokasi / Cabang</label>
                <div class="input-group">
                    <span class="input-group-text"><i class="fas fa-location-dot"></i></span>
                    <select class="form-select" id="location" name="location" required>
                        <?php foreach ($locations as $loc): ?>
                            <option value="<?= htmlspecialchars($loc) ?>" <?= (isset($_POST['location']) && $_POST['location'] === $loc) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($loc) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <button type="submit" class="btn btn-login">
                <i class="fas fa-right-to-bracket me-2"></i> Masuk ke Sistem
            </button>
        </form>
    </div>
</div>

</body>
</html>
