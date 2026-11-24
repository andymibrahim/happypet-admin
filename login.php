<?php
/**
 * HappyPet - Halaman Login Administrator
 */
require_once __DIR__ . '/config/database.php';

if (!empty($_SESSION['happypet_admin_id'])) {
    header('Location: index.php');
    exit;
}

$db = HappyPetDB::getInstance();
$error = '';
$success = '';

if (isset($_GET['logged_out'])) {
    $success = 'Anda telah berhasil keluar dari Panel Admin HappyPet.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $user = $db->findUserByEmail($email);

    if ($user && ($user['role'] ?? 'customer') === 'admin' && password_verify($password, $user['password'])) {
        $_SESSION['happypet_admin_id'] = (int)$user['id'];
        $_SESSION['happypet_admin_name'] = $user['name'];
        $_SESSION['happypet_admin_email'] = $user['email'];
        $db->logActivity(
            (int)$user['id'],
            'admin_login',
            'Administrator berhasil masuk ke Panel Admin HappyPet.',
            'admin_auth',
            (string)$user['id'],
            ['email' => $user['email']],
            $user
        );
        header('Location: index.php');
        exit;
    } elseif ($user && ($user['role'] ?? 'customer') !== 'admin') {
        $error = 'Akun tersebut adalah akun customer, bukan akun Administrator.';
    } else {
        $db->logActivity(
            null,
            'admin_login_failed',
            'Percobaan login admin gagal untuk email: ' . ($email ?: '(kosong)'),
            'admin_auth',
            null,
            ['email' => $email]
        );
        $error = 'Email atau kata sandi Admin tidak sesuai.';
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login — HappyPet Luxury Pet Hotel & Care</title>
    <link rel="icon" type="image/png" href="assets/img/happypet-paw-badge-transparent.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <style>
        .admin-auth-wrap {
            min-height: 100vh;
            background-color: var(--bg-alabaster);
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px;
            background-image:
                radial-gradient(at 15% 15%, rgba(53, 79, 82, 0.14) 0px, transparent 45%),
                radial-gradient(at 85% 85%, rgba(212, 163, 115, 0.25) 0px, transparent 50%);
        }
        .admin-auth-card {
            width: 100%;
            max-width: 460px;
            background: #FFFFFF;
            border-radius: 26px;
            padding: 40px 36px;
            border: 1px solid var(--border-subtle);
            box-shadow: var(--shadow-modal);
        }
        .admin-brand-head {
            text-align: center;
            margin-bottom: 28px;
        }
        .admin-brand-head img {
            width: 62px;
            height: 62px;
            margin-bottom: 12px;
        }
        .admin-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            border-radius: 99px;
            background: var(--forest-cta);
            color: var(--sand-secondary);
            font-size: 11.5px;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            margin-bottom: 10px;
        }
        .admin-brand-head h1 {
            font-family: 'Fraunces', serif;
            font-size: 26px;
            color: var(--forest-cta);
            margin-bottom: 6px;
        }
        .admin-brand-head p {
            font-size: 13.5px;
            color: var(--text-muted);
        }
        .alert-box {
            padding: 12px 15px;
            border-radius: 12px;
            font-size: 13px;
            font-weight: 600;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .alert-error { background: var(--danger-bg); color: var(--danger-text); border: 1px solid #F5C2C7; }
        .alert-success { background: var(--success-bg); color: var(--success-text); border: 1px solid #B7DFC2; }
        .demo-admin-box {
            margin-top: 20px;
            padding: 14px 16px;
            border-radius: 14px;
            background: var(--sage-light);
            border: 1px dashed var(--sage-primary);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            font-size: 12.5px;
            color: var(--forest-cta);
        }
        .back-customer-link {
            display: block;
            text-align: center;
            margin-top: 20px;
            font-size: 13px;
            font-weight: 600;
            color: var(--text-muted);
        }
        .back-customer-link:hover {
            color: var(--forest-cta);
        }
    </style>
</head>
<body>
<div class="admin-auth-wrap">
    <div class="admin-auth-card">
        <div class="admin-brand-head">
            <img src="assets/img/happypet-paw-badge-transparent.png" alt="HappyPet Logo">
            <div>
                <span class="admin-badge-pill"><i class="fa-solid fa-shield-halved"></i> Admin Portal</span>
            </div>
            <h1>Panel Admin HappyPet</h1>
            <p>Kelola data pendaftaran customer, pantau laporan pemesanan, dan cetak laporan resmi.</p>
        </div>

        <?php if ($error): ?>
            <div class="alert-box alert-error">
                <i class="fa-solid fa-circle-exclamation"></i>
                <span><?= htmlspecialchars($error) ?></span>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert-box alert-success">
                <i class="fa-solid fa-circle-check"></i>
                <span><?= htmlspecialchars($success) ?></span>
            </div>
        <?php endif; ?>

        <form method="POST" action="login.php">
            <div class="form-group">
                <label class="form-label">Email Administrator</label>
                <input type="email" name="email" id="adminEmail" class="form-control" placeholder="admin@happypet.id" required autocomplete="username">
            </div>
            <div class="form-group">
                <label class="form-label">Kata Sandi</label>
                <input type="password" name="password" id="adminPassword" class="form-control" placeholder="••••••••" required autocomplete="current-password">
            </div>
            <button type="submit" class="btn btn-forest" style="width:100%; padding:13px; font-size:14.5px; margin-top:4px;">
                <i class="fa-solid fa-right-to-bracket"></i> Masuk ke Panel Admin
            </button>
        </form>

        <div class="demo-admin-box">
            <div>
                <strong>Akun Admin Default:</strong><br>
                <code>admin@happypet.id</code> / <code>admin123</code>
            </div>
            <button type="button" class="btn btn-sand btn-sm" onclick="fillAdminDemo()">
                <i class="fa-solid fa-wand-magic-sparkles"></i> Isi Otomatis
            </button>
        </div>

        <div class="back-customer-link" style="cursor:default;">
            <i class="fa-solid fa-lock"></i> Portal Khusus Administrator HappyPet
        </div>
    </div>
</div>
<script>
function fillAdminDemo() {
    document.getElementById('adminEmail').value = 'admin@happypet.id';
    document.getElementById('adminPassword').value = 'admin123';
}
</script>
</body>
</html>
