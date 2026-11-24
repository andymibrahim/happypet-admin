<?php
/**
 * HappyPet — Panel Administrator (Versi Admin)
 * Fitur Utama:
 * 1. Melihat daftar customer & waktu pembuatan akun secara real-time dari MySQL
 * 2. Melihat laporan pemesanan lengkap dengan filter status, paket, & rentang tanggal
 * 3. Cetak laporan resmi (A4 / PDF) & Export Excel (.CSV)
 * 4. Pantau log interaksi user real-time dari tabel user_activity_logs
 */

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['happypet_admin_id'])) {
    header('Location: login.php');
    exit;
}

$db = HappyPetDB::getInstance();
$adminId = (int)$_SESSION['happypet_admin_id'];
$adminUser = $db->findUserById($adminId);

if (!$adminUser || ($adminUser['role'] ?? 'customer') !== 'admin') {
    unset($_SESSION['happypet_admin_id'], $_SESSION['happypet_admin_name'], $_SESSION['happypet_admin_email']);
    header('Location: login.php');
    exit;
}

$flashMessage = '';
$flashType = 'success';

// Handle POST action: Admin update payment status
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['admin_action'] ?? '') === 'update_booking_status') {
    $bookingId = (int)($_POST['booking_id'] ?? 0);
    $newStatus = trim((string)($_POST['new_status'] ?? ''));
    $updated = $db->updateBookingStatusAdmin($bookingId, $newStatus);
    if ($updated) {
        $db->logActivity(
            $adminId,
            'admin_update_booking_status',
            'Admin mengubah status pesanan ' . $updated['invoice_number'] . ' menjadi ' . strtoupper($newStatus) . '.',
            'booking',
            (string)$bookingId,
            ['invoice_number' => $updated['invoice_number'], 'new_status' => $newStatus],
            $adminUser
        );
        $flashMessage = 'Status pesanan ' . $updated['invoice_number'] . ' berhasil diperbarui menjadi ' . strtoupper($newStatus) . '.';
    } else {
        $flashMessage = 'Gagal memperbarui status pesanan.';
        $flashType = 'error';
    }
}

$activeTab = $_GET['tab'] ?? 'overview';
$allowedTabs = ['overview', 'customers', 'bookings', 'print-center', 'activity'];
if (!in_array($activeTab, $allowedTabs, true)) {
    $activeTab = 'overview';
}

// Filters for Customer list
$custSearch = trim((string)($_GET['cust_search'] ?? ''));
$custFrom = trim((string)($_GET['cust_from'] ?? ''));
$custTo = trim((string)($_GET['cust_to'] ?? ''));

// Filters for Bookings Report
$bookStatus = trim((string)($_GET['status'] ?? 'all'));
$bookPkgId = (int)($_GET['package_id'] ?? 0);
$bookFrom = trim((string)($_GET['date_from'] ?? ''));
$bookTo = trim((string)($_GET['date_to'] ?? ''));
$bookSearch = trim((string)($_GET['search'] ?? ''));

$stats = $db->getAdminDashboardStats();
$packages = $db->getPackages();
$customers = $db->getAllCustomers($custSearch, $custFrom, $custTo);
$allCustomersPreview = $db->getAllCustomers();
$bookingsReport = $db->getAllBookingsReport([
    'status' => $bookStatus,
    'package_id' => $bookPkgId,
    'date_from' => $bookFrom,
    'date_to' => $bookTo,
    'search' => $bookSearch,
]);
$recentBookingsPreview = array_slice($db->getAllBookingsReport(), 0, 5);
$activityLogs = $db->getAllActivities(60);

// Filtered report summary totals
$filteredTotalCount = count($bookingsReport);
$filteredPaidCount = 0;
$filteredPendingCount = 0;
$filteredPaidAmount = 0;
$filteredAllAmount = 0;
foreach ($bookingsReport as $b) {
    $filteredAllAmount += (int)$b['total_amount'];
    if ($b['payment_status'] === 'settlement') {
        $filteredPaidCount++;
        $filteredPaidAmount += (int)$b['total_amount'];
    } elseif ($b['payment_status'] === 'pending') {
        $filteredPendingCount++;
    }
}

$avatarIcons = [
    'cat-sage' => ['icon' => 'fa-cat', 'bg' => '#84A98C'],
    'dog-amber' => ['icon' => 'fa-dog', 'bg' => '#D4A373'],
    'paw-forest' => ['icon' => 'fa-paw', 'bg' => '#354F52'],
    'dove-sand' => ['icon' => 'fa-feather-pointed', 'bg' => '#C59B76'],
];

// Build print query strings
$bookingsPrintQuery = http_build_query([
    'type' => 'bookings',
    'status' => $bookStatus,
    'package_id' => $bookPkgId,
    'date_from' => $bookFrom,
    'date_to' => $bookTo,
    'search' => $bookSearch,
]);
$customersPrintQuery = http_build_query([
    'type' => 'customers',
    'date_from' => $custFrom,
    'date_to' => $custTo,
    'search' => $custSearch,
]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel — HappyPet Luxury Pet Hotel & Care</title>
    <link rel="icon" type="image/png" href="assets/img/happypet-paw-badge-transparent.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <link rel="stylesheet" href="assets/css/style.css?v=<?= filemtime(__DIR__ . '/assets/css/style.css') ?>">
    <style>
        .admin-role-tag {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-size: 10.5px;
            font-weight: 800;
            letter-spacing: 0.06em;
            text-transform: uppercase;
            padding: 3px 9px;
            border-radius: 99px;
            background: rgba(212, 163, 115, 0.22);
            color: var(--sand-secondary);
            margin-top: 3px;
        }
        .filter-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: flex-end;
            background: var(--bg-alabaster);
            padding: 16px 18px;
            border-radius: 14px;
            border: 1px solid var(--border-subtle);
            margin-bottom: 18px;
        }
        .filter-field {
            display: flex;
            flex-direction: column;
            gap: 5px;
            min-width: 150px;
            flex: 1;
        }
        .filter-field label {
            font-size: 11.5px;
            font-weight: 700;
            color: var(--text-muted);
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .filter-field .form-control {
            padding: 9px 12px;
            font-size: 13px;
        }
        .summary-strip {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(175px, 1fr));
            gap: 12px;
            margin-bottom: 18px;
        }
        .summary-chip {
            background: #FFFFFF;
            border: 1px solid var(--border-subtle);
            border-left: 4px solid var(--sage-primary);
            border-radius: 12px;
            padding: 12px 16px;
        }
        .summary-chip.forest { border-left-color: var(--forest-cta); }
        .summary-chip.amber { border-left-color: var(--amber-cta); }
        .summary-chip .chip-label {
            font-size: 11.5px;
            color: var(--text-muted);
            font-weight: 600;
        }
        .summary-chip .chip-val {
            font-size: 18px;
            font-weight: 800;
            color: var(--text-slate);
            margin-top: 2px;
        }
        .cust-avatar-sm {
            width: 36px;
            height: 36px;
            border-radius: 10px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            color: #FFFFFF;
            font-size: 15px;
            flex-shrink: 0;
        }
        .print-option-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
            margin-bottom: 24px;
        }
        @media (max-width: 960px) {
            .print-option-grid {
                grid-template-columns: 1fr;
            }
        }
        .print-option-card {
            background: #FFFFFF;
            border: 1.5px solid var(--border-subtle);
            border-radius: 18px;
            padding: 24px;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            gap: 20px;
            transition: all 0.2s ease;
        }
        .print-option-card:hover {
            border-color: var(--sage-primary);
            box-shadow: var(--shadow-card);
            transform: translateY(-2px);
        }
        .print-option-card h3 {
            font-size: 16.5px;
            font-weight: 800;
            color: var(--forest-cta);
            margin-bottom: 8px;
            line-height: 1.3;
        }
        .print-option-card p {
            font-size: 13px;
            color: var(--text-muted);
            line-height: 1.55;
        }
        .print-icon-box {
            width: 48px;
            height: 48px;
            border-radius: 13px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
            margin-bottom: 14px;
        }
        .print-card-btn {
            width: 100%;
            height: 42px;
            border-radius: 11px;
            font-size: 13.5px;
            font-weight: 700;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .pkg-bar-track {
            width: 100%;
            height: 8px;
            background: var(--sage-light);
            border-radius: 99px;
            overflow: hidden;
            margin-top: 6px;
        }
        .pkg-bar-fill {
            height: 100%;
            background: linear-gradient(90deg, var(--forest-cta), var(--sage-primary));
            border-radius: 99px;
        }
        .db-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 99px;
            font-size: 12px;
            font-weight: 700;
            background: var(--success-bg);
            color: var(--success-text);
        }
        .action-pill-btn {
            height: 32px;
            padding: 0 11px;
            font-size: 12px;
            border-radius: 8px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            font-weight: 600;
            white-space: nowrap;
        }
        .two-col-overview {
            display: grid;
            grid-template-columns: 1.2fr 1fr;
            gap: 20px;
            margin-bottom: 24px;
        }
        @media (max-width: 1024px) {
            .two-col-overview { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>

<div class="app-layout">
    <!-- ==================================================================
         LEFT SIDEBAR: ADMIN NAVIGATION
         ================================================================== -->
    <aside class="left-sidebar" id="leftSidebar">
        <div class="sidebar-brand">
            <img src="assets/img/happypet-paw-badge-transparent.png" alt="HappyPet Logo" style="width:46px; height:46px; object-fit:contain; flex-shrink:0;">
            <div>
                <div class="brand-title" style="color:#FFFFFF;">HappyPet</div>
                <div class="brand-subtitle">Admin Panel</div>
            </div>
        </div>

        <a href="cetak_laporan.php?type=all&autoprint=1" class="nav-item nav-item-cta" style="text-decoration:none;">
            <i class="fa-solid fa-print"></i>
            <span>Cetak Laporan Lengkap</span>
        </a>

        <div class="sidebar-nav-label" style="margin-top:8px;">Menu Administrator</div>
        <nav class="sidebar-nav">
            <a href="index.php?tab=overview" class="nav-item <?= $activeTab === 'overview' ? 'active' : '' ?>">
                <i class="fa-solid fa-chart-pie"></i>
                <span>Ringkasan Admin</span>
            </a>
            <a href="index.php?tab=customers" class="nav-item <?= $activeTab === 'customers' ? 'active' : '' ?>">
                <i class="fa-solid fa-users"></i>
                <span>Data Customer</span>
            </a>
            <a href="index.php?tab=bookings" class="nav-item <?= $activeTab === 'bookings' ? 'active' : '' ?>">
                <i class="fa-solid fa-file-invoice-dollar"></i>
                <span>Laporan Pemesanan</span>
            </a>
            <a href="index.php?tab=print-center" class="nav-item <?= $activeTab === 'print-center' ? 'active' : '' ?>">
                <i class="fa-solid fa-print"></i>
                <span>Cetak Laporan</span>
            </a>
            <a href="index.php?tab=activity" class="nav-item <?= $activeTab === 'activity' ? 'active' : '' ?>">
                <i class="fa-solid fa-clock-rotate-left"></i>
                <span>Log Aktivitas SQL</span>
            </a>
        </nav>

        <div class="sidebar-footer">
            <div class="sidebar-user-card" style="cursor:default;">
                <div class="user-avatar-badge" style="background: #D4A373; color: #FFFFFF;">
                    <i class="fa-solid fa-user-shield"></i>
                </div>
                <div class="user-card-info">
                    <div class="user-card-name"><?= htmlspecialchars($adminUser['name']) ?></div>
                    <div class="user-card-tier">
                        <i class="fa-solid fa-database"></i> <?= strtoupper($db->getDriver()) ?> Aktif
                    </div>
                </div>
            </div>

            <a href="logout.php" class="nav-item nav-item-logout">
                <i class="fa-solid fa-right-from-bracket"></i>
                <span>Logout Admin</span>
            </a>
        </div>
    </aside>

    <!-- ==================================================================
         RIGHT DASHBOARD CONTENT
         ================================================================== -->
    <main class="right-dashboard">
        <header class="dashboard-topbar">
            <div style="display:flex; align-items:center; gap:14px;">
                <button type="button" class="mobile-menu-btn" onclick="document.getElementById('leftSidebar').classList.toggle('mobile-open')">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="topbar-greeting">
                    <h1>Panel Admin HappyPet 🐾</h1>
                    <p>Monitoring pendaftaran customer, laporan transaksi pemesanan, dan cetak laporan resmi &bull; <?= formatDateIndo(date('Y-m-d')) ?></p>
                </div>
            </div>
            <div class="topbar-actions">
                <span class="db-pill">
                    <i class="fa-solid fa-circle-check"></i> MySQL Real-Time (happypet_db)
                </span>
            </div>
        </header>

        <?php if ($flashMessage): ?>
            <div style="margin-bottom:20px; padding:13px 18px; border-radius:12px; font-size:13.5px; font-weight:600; display:flex; align-items:center; gap:10px; background:<?= $flashType === 'error' ? 'var(--danger-bg)' : 'var(--success-bg)' ?>; color:<?= $flashType === 'error' ? 'var(--danger-text)' : 'var(--success-text)' ?>; border:1px solid <?= $flashType === 'error' ? '#F5C2C7' : '#B7DFC2' ?>;">
                <i class="fa-solid <?= $flashType === 'error' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
                <span><?= htmlspecialchars($flashMessage) ?></span>
            </div>
        <?php endif; ?>

        <!-- ==============================================================
             TAB 1: RINGKASAN ADMIN (OVERVIEW)
             ============================================================== -->
        <section class="dashboard-section <?= $activeTab === 'overview' ? 'active' : '' ?>">
            <div class="stats-grid">
                <a href="index.php?tab=customers" class="stat-card" style="text-decoration:none;">
                    <div class="stat-icon sage">
                        <i class="fa-solid fa-users"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Total Customer</div>
                        <div class="stat-value"><?= $stats['total_customers'] ?> Akun</div>
                        <div class="stat-sub">+<?= $stats['new_customers_month'] ?> daftar bulan ini &bull; <?= $stats['total_pets'] ?> anabul</div>
                    </div>
                </a>

                <a href="index.php?tab=bookings" class="stat-card" style="text-decoration:none;">
                    <div class="stat-icon forest">
                        <i class="fa-solid fa-circle-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Pendapatan Lunas</div>
                        <div class="stat-value"><?= formatRupiah($stats['total_revenue']) ?></div>
                        <div class="stat-sub"><?= $stats['paid_bookings'] ?> transaksi terverifikasi lunas</div>
                    </div>
                </a>

                <a href="index.php?tab=bookings" class="stat-card" style="text-decoration:none;">
                    <div class="stat-icon sand">
                        <i class="fa-solid fa-calendar-check"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Total Pemesanan</div>
                        <div class="stat-value"><?= $stats['total_bookings'] ?> Pesanan</div>
                        <div class="stat-sub"><?= $stats['today_bookings'] ?> pesanan masuk hari ini</div>
                    </div>
                </a>

                <a href="index.php?tab=bookings&status=pending" class="stat-card" style="text-decoration:none;">
                    <div class="stat-icon amber">
                        <i class="fa-solid fa-clock"></i>
                    </div>
                    <div class="stat-info">
                        <div class="stat-label">Menunggu Pembayaran</div>
                        <div class="stat-value"><?= $stats['pending_bookings'] ?> Pending</div>
                        <div class="stat-sub">Nilai: <?= formatRupiah($stats['pending_revenue']) ?></div>
                    </div>
                </a>
            </div>

            <div class="two-col-overview">
                <!-- Customer Baru Mendaftar -->
                <div class="card-panel" style="margin-bottom:0;">
                    <div class="section-header" style="margin-bottom:14px;">
                        <div>
                            <h2 style="font-size:18px;">Customer Terbaru Mendaftar</h2>
                            <p>Waktu pembuatan akun customer tercatat otomatis di MySQL.</p>
                        </div>
                        <a href="index.php?tab=customers" class="btn btn-outline btn-sm">
                            Semua Customer <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                    <div class="table-responsive">
                        <table class="hp-table">
                            <thead>
                                <tr>
                                    <th>Customer</th>
                                    <th>Waktu Buat Akun</th>
                                    <th style="text-align:center;">Pesanan</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($allCustomersPreview)): ?>
                                    <tr><td colspan="3" style="text-align:center; color:var(--text-muted); padding:24px;">Belum ada data customer.</td></tr>
                                <?php else: ?>
                                    <?php foreach (array_slice($allCustomersPreview, 0, 5) as $c): ?>
                                        <?php $av = $avatarIcons[$c['avatar'] ?? 'cat-sage'] ?? $avatarIcons['cat-sage']; ?>
                                        <tr>
                                            <td>
                                                <div style="display:flex; align-items:center; gap:10px;">
                                                    <span class="cust-avatar-sm" style="background:<?= htmlspecialchars($av['bg']) ?>;">
                                                        <i class="fa-solid <?= htmlspecialchars($av['icon']) ?>"></i>
                                                    </span>
                                                    <div>
                                                        <strong><?= htmlspecialchars($c['name']) ?></strong>
                                                        <div style="font-size:11.5px; color:var(--text-muted);"><?= htmlspecialchars($c['email']) ?></div>
                                                    </div>
                                                </div>
                                            </td>
                                            <td>
                                                <strong style="font-size:12.5px; color:var(--forest-cta); white-space:nowrap;">
                                                    <?= formatDateIndo($c['created_at'] ?: $c['joined_at'], !empty($c['created_at'])) ?>
                                                </strong>
                                                <div style="font-size:11px; color:var(--text-muted);">ID #<?= (int)$c['id'] ?></div>
                                            </td>
                                            <td style="text-align:center;">
                                                <strong><?= (int)$c['total_bookings'] ?></strong>
                                                <div style="font-size:11px; color:var(--sage-dark);"><?= formatRupiah($c['total_spent']) ?></div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Breakdown Pendapatan per Paket -->
                <div class="card-panel" style="margin-bottom:0;">
                    <div class="section-header" style="margin-bottom:14px;">
                        <div>
                            <h2 style="font-size:18px;">Pendapatan per Paket</h2>
                            <p>Distribusi pesanan & pemasukan berdasarkan jenis layanan.</p>
                        </div>
                    </div>
                    <?php if (empty($stats['package_breakdown'])): ?>
                        <p style="color:var(--text-muted); font-size:13px; padding:18px 0;">Belum ada data transaksi.</p>
                    <?php else: ?>
                        <?php $maxRev = max(1, (int)$stats['package_breakdown'][0]['revenue']); ?>
                        <div style="display:flex; flex-direction:column; gap:14px;">
                            <?php foreach ($stats['package_breakdown'] as $pb): ?>
                                <?php $pct = min(100, round(((int)$pb['revenue'] / $maxRev) * 100)); ?>
                                <div>
                                    <div style="display:flex; justify-content:space-between; align-items:baseline; gap:8px;">
                                        <strong style="font-size:13.5px; color:var(--text-slate);"><?= htmlspecialchars($pb['name']) ?></strong>
                                        <span style="font-size:13px; font-weight:800; color:var(--forest-cta);"><?= formatRupiah($pb['revenue']) ?></span>
                                    </div>
                                    <div style="font-size:11.5px; color:var(--text-muted); margin-top:2px;">
                                        <?= (int)$pb['count'] ?> total pesanan (<?= (int)$pb['paid_count'] ?> lunas)
                                    </div>
                                    <div class="pkg-bar-track">
                                        <div class="pkg-bar-fill" style="width:<?= $pct ?>%;"></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Pemesanan Terbaru -->
            <div class="card-panel">
                <div class="section-header" style="margin-bottom:14px;">
                    <div>
                        <h2 style="font-size:18px;">Laporan Pemesanan Terbaru</h2>
                        <p>5 transaksi pemesanan penitipan terbaru dari seluruh customer.</p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="cetak_laporan.php?type=bookings" class="btn btn-forest btn-sm">
                            <i class="fa-solid fa-print"></i> Cetak Laporan
                        </a>
                        <a href="index.php?tab=bookings" class="btn btn-outline btn-sm">
                            Lihat Semua <i class="fa-solid fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="hp-table">
                        <thead>
                            <tr>
                                <th style="width:190px;">No. Invoice</th>
                                <th>Customer</th>
                                <th>Anabul & Paket</th>
                                <th style="width:150px;">Total Biaya</th>
                                <th style="width:110px; text-align:center;">Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($recentBookingsPreview)): ?>
                                <tr><td colspan="5" style="text-align:center; padding:28px; color:var(--text-muted);">Belum ada pemesanan.</td></tr>
                            <?php else: ?>
                                <?php foreach ($recentBookingsPreview as $b): ?>
                                    <tr>
                                        <td>
                                            <strong style="font-family:'JetBrains Mono',monospace; font-size:13px; color:var(--forest-cta); white-space:nowrap; display:block;">
                                                <?= htmlspecialchars($b['invoice_number']) ?>
                                            </strong>
                                            <div style="font-size:11.5px; color:var(--text-muted); white-space:nowrap;"><?= formatDateIndo($b['created_at'], true) ?></div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($b['customer_name'] ?? '-') ?></strong>
                                            <div style="font-size:11.5px; color:var(--text-muted);"><?= htmlspecialchars($b['customer_email'] ?? '-') ?></div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($b['pet_name']) ?></strong> <span style="font-size:12px; color:var(--text-muted);">(<?= htmlspecialchars($b['pet_species']) ?>)</span>
                                            <div style="font-size:12px; color:var(--sage-dark); font-weight:600;"><?= htmlspecialchars($b['package_name']) ?> &bull; <?= (int)$b['duration_days'] ?> hari</div>
                                        </td>
                                        <td>
                                            <strong style="color:var(--forest-cta); white-space:nowrap;"><?= formatRupiah($b['total_amount']) ?></strong>
                                            <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($b['payment_channel']) ?></div>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($b['payment_status'] === 'settlement'): ?>
                                                <span class="status-badge status-settlement"><i class="fa-solid fa-circle-check"></i> Lunas</span>
                                            <?php else: ?>
                                                <span class="status-badge status-pending"><i class="fa-solid fa-clock"></i> <?= ucfirst(htmlspecialchars($b['payment_status'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ==============================================================
             TAB 2: DATA CUSTOMER & WAKTU PEMBUATAN AKUN
             ============================================================== -->
        <section class="dashboard-section <?= $activeTab === 'customers' ? 'active' : '' ?>">
            <div class="card-panel">
                <div class="section-header" style="margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                    <div>
                        <h2><i class="fa-solid fa-users" style="color:var(--sage-primary);"></i> Data Customer & Waktu Pembuatan Akun</h2>
                        <p>Pantau seluruh akun pelanggan yang terdaftar beserta tanggal dan jam pembuatan akun di MySQL.</p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="cetak_laporan.php?<?= htmlspecialchars($customersPrintQuery) ?>&export=csv" class="btn btn-outline btn-sm">
                            <i class="fa-solid fa-file-excel"></i> Export CSV
                        </a>
                        <a href="cetak_laporan.php?<?= htmlspecialchars($customersPrintQuery) ?>" class="btn btn-forest btn-sm">
                            <i class="fa-solid fa-print"></i> Cetak Daftar Customer
                        </a>
                    </div>
                </div>

                <!-- Filter Customer -->
                <form method="GET" action="index.php" class="filter-bar">
                    <input type="hidden" name="tab" value="customers">
                    <div class="filter-field" style="flex:2;">
                        <label>Cari Nama / Email / No. HP</label>
                        <input type="text" name="cust_search" class="form-control" value="<?= htmlspecialchars($custSearch) ?>" placeholder="Ketik nama, email, atau nomor telepon...">
                    </div>
                    <div class="filter-field">
                        <label>Daftar Dari Tanggal</label>
                        <input type="date" name="cust_from" class="form-control" value="<?= htmlspecialchars($custFrom) ?>">
                    </div>
                    <div class="filter-field">
                        <label>Sampai Tanggal</label>
                        <input type="date" name="cust_to" class="form-control" value="<?= htmlspecialchars($custTo) ?>">
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="btn btn-forest btn-sm" style="height:39px;">
                            <i class="fa-solid fa-magnifying-glass"></i> Filter
                        </button>
                        <a href="index.php?tab=customers" class="btn btn-outline btn-sm" style="height:39px;">Reset</a>
                    </div>
                </form>

                <div class="table-responsive">
                    <table class="hp-table">
                        <thead>
                            <tr>
                                <th style="width:70px; text-align:center;">ID</th>
                                <th>Customer</th>
                                <th>Kontak & Alamat</th>
                                <th style="width:210px;">Waktu Pembuatan Akun</th>
                                <th style="width:100px; text-align:center;">Anabul</th>
                                <th style="width:165px; text-align:right;">Total Transaksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($customers)): ?>
                                <tr>
                                    <td colspan="6" style="text-align:center; padding:32px; color:var(--text-muted);">
                                        Tidak ada customer yang cocok dengan filter pencarian.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($customers as $c): ?>
                                    <?php
                                        $av = $avatarIcons[$c['avatar'] ?? 'cat-sage'] ?? $avatarIcons['cat-sage'];
                                        $registeredAt = !empty($c['created_at']) ? $c['created_at'] : ($c['joined_at'] ?? '');
                                        $hasTime = !empty($c['created_at']);
                                    ?>
                                    <tr>
                                        <td style="text-align:center;">
                                            <strong style="font-family:'JetBrains Mono',monospace; color:var(--forest-cta);">#<?= (int)$c['id'] ?></strong>
                                        </td>
                                        <td>
                                            <div style="display:flex; align-items:center; gap:10px;">
                                                <span class="cust-avatar-sm" style="background:<?= htmlspecialchars($av['bg']) ?>;">
                                                    <i class="fa-solid <?= htmlspecialchars($av['icon']) ?>"></i>
                                                </span>
                                                <div>
                                                    <strong><?= htmlspecialchars($c['name']) ?></strong>
                                                    <div style="font-size:11.5px; color:var(--sage-dark); font-weight:600;">
                                                        <i class="fa-solid fa-medal"></i> <?= htmlspecialchars($c['member_tier'] ?? 'HappyPaw Member') ?>
                                                    </div>
                                                </div>
                                            </div>
                                        </td>
                                        <td>
                                            <div style="font-weight:600; font-size:13px;"><?= htmlspecialchars($c['email']) ?></div>
                                            <div style="font-size:11.5px; color:var(--text-muted);">
                                                <i class="fa-solid fa-phone" style="font-size:10px;"></i> <?= htmlspecialchars($c['phone'] ?: '-') ?>
                                            </div>
                                            <?php if (!empty($c['address'])): ?>
                                                <div style="font-size:11px; color:var(--text-light); margin-top:2px; max-width:260px;">
                                                    <?= htmlspecialchars($c['address']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="display:inline-flex; align-items:center; gap:6px; background:var(--sage-light); color:var(--forest-cta); padding:5px 10px; border-radius:8px; font-weight:700; font-size:12.5px; white-space:nowrap;">
                                                <i class="fa-regular fa-calendar-check"></i>
                                                <?= formatDateIndo($registeredAt, $hasTime) ?>
                                            </div>
                                            <?php if (!empty($c['joined_at'])): ?>
                                                <div style="font-size:11px; color:var(--text-muted); margin-top:4px;">
                                                    Tanggal Gabung: <?= formatDateIndo($c['joined_at']) ?>
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <strong><?= (int)$c['total_pets'] ?></strong>
                                            <div style="font-size:11px; color:var(--text-muted);">Ekor</div>
                                        </td>
                                        <td style="text-align:right;">
                                            <strong style="color:var(--forest-cta); white-space:nowrap; display:block;"><?= formatRupiah($c['total_spent']) ?></strong>
                                            <div style="font-size:11.5px; color:var(--text-muted);">
                                                <?= (int)$c['total_bookings'] ?> pesan (<?= (int)$c['paid_bookings'] ?> lunas)
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>

        <!-- ==============================================================
             TAB 3: LAPORAN PEMESANAN
             ============================================================== -->
        <section class="dashboard-section <?= $activeTab === 'bookings' ? 'active' : '' ?>">
            <div class="card-panel">
                <div class="section-header" style="margin-bottom:16px; flex-wrap:wrap; gap:12px;">
                    <div>
                        <h2><i class="fa-solid fa-file-invoice-dollar" style="color:var(--sage-primary);"></i> Laporan Pemesanan Penitipan</h2>
                        <p>Rekap seluruh transaksi pemesanan dari seluruh customer secara real-time.</p>
                    </div>
                    <div style="display:flex; gap:8px; flex-wrap:wrap;">
                        <a href="cetak_laporan.php?<?= htmlspecialchars($bookingsPrintQuery) ?>&export=csv" class="btn btn-outline btn-sm">
                            <i class="fa-solid fa-file-excel"></i> Export CSV
                        </a>
                        <a href="cetak_laporan.php?<?= htmlspecialchars($bookingsPrintQuery) ?>" class="btn btn-forest btn-sm">
                            <i class="fa-solid fa-print"></i> Cetak Laporan Ini
                        </a>
                    </div>
                </div>

                <!-- Filter Laporan Pemesanan -->
                <form method="GET" action="index.php" class="filter-bar">
                    <input type="hidden" name="tab" value="bookings">
                    <div class="filter-field" style="flex:1.5;">
                        <label>Cari Invoice / Customer / Hewan</label>
                        <input type="text" name="search" class="form-control" value="<?= htmlspecialchars($bookSearch) ?>" placeholder="INV-HP-..., nama customer, hewan...">
                    </div>
                    <div class="filter-field">
                        <label>Status Pembayaran</label>
                        <select name="status" class="form-control">
                            <option value="all" <?= $bookStatus === 'all' ? 'selected' : '' ?>>Semua Status</option>
                            <option value="settlement" <?= $bookStatus === 'settlement' ? 'selected' : '' ?>>Lunas (Settlement)</option>
                            <option value="pending" <?= $bookStatus === 'pending' ? 'selected' : '' ?>>Pending (Menunggu)</option>
                            <option value="cancel" <?= $bookStatus === 'cancel' ? 'selected' : '' ?>>Cancel</option>
                            <option value="expire" <?= $bookStatus === 'expire' ? 'selected' : '' ?>>Expire</option>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label>Paket Layanan</label>
                        <select name="package_id" class="form-control">
                            <option value="0">Semua Paket</option>
                            <?php foreach ($packages as $pkg): ?>
                                <option value="<?= (int)$pkg['id'] ?>" <?= $bookPkgId === (int)$pkg['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($pkg['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="filter-field">
                        <label>Dari Tanggal</label>
                        <input type="date" name="date_from" class="form-control" value="<?= htmlspecialchars($bookFrom) ?>">
                    </div>
                    <div class="filter-field">
                        <label>Sampai Tanggal</label>
                        <input type="date" name="date_to" class="form-control" value="<?= htmlspecialchars($bookTo) ?>">
                    </div>
                    <div style="display:flex; gap:8px;">
                        <button type="submit" class="btn btn-forest btn-sm" style="height:39px;">
                            <i class="fa-solid fa-filter"></i> Terapkan
                        </button>
                        <a href="index.php?tab=bookings" class="btn btn-outline btn-sm" style="height:39px;">Reset</a>
                    </div>
                </form>

                <!-- Ringkasan Hasil Filter -->
                <div class="summary-strip">
                    <div class="summary-chip">
                        <div class="chip-label">Total Transaksi (Terfilter)</div>
                        <div class="chip-val"><?= $filteredTotalCount ?> Pesanan</div>
                    </div>
                    <div class="summary-chip forest">
                        <div class="chip-label">Transaksi Lunas</div>
                        <div class="chip-val"><?= $filteredPaidCount ?> Lunas</div>
                    </div>
                    <div class="summary-chip amber">
                        <div class="chip-label">Menunggu Pembayaran</div>
                        <div class="chip-val"><?= $filteredPendingCount ?> Pending</div>
                    </div>
                    <div class="summary-chip forest">
                        <div class="chip-label">Pendapatan Lunas (Terfilter)</div>
                        <div class="chip-val"><?= formatRupiah($filteredPaidAmount) ?></div>
                    </div>
                </div>

                <!-- Tabel Laporan Pemesanan -->
                <div class="table-responsive">
                    <table class="hp-table">
                        <thead>
                            <tr>
                                <th style="width:190px;">Invoice & Tanggal</th>
                                <th>Customer</th>
                                <th>Anabul & Paket</th>
                                <th style="width:190px;">Jadwal Menginap</th>
                                <th style="width:150px;">Total & Metode</th>
                                <th style="width:110px; text-align:center;">Status</th>
                                <th style="width:190px; text-align:center;">Aksi</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($bookingsReport)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center; padding:32px; color:var(--text-muted);">
                                        Tidak ditemukan transaksi pemesanan sesuai filter yang dipilih.
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($bookingsReport as $b): ?>
                                    <tr>
                                        <td>
                                            <strong style="font-family:'JetBrains Mono',monospace; font-size:12.5px; color:var(--forest-cta); white-space:nowrap; display:block;">
                                                <?= htmlspecialchars($b['invoice_number']) ?>
                                            </strong>
                                            <div style="font-size:11.5px; color:var(--text-muted); white-space:nowrap;">
                                                <?= formatDateIndo($b['created_at'], true) ?>
                                            </div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($b['customer_name'] ?? '-') ?></strong>
                                            <div style="font-size:11.5px; color:var(--text-muted);"><?= htmlspecialchars($b['customer_email'] ?? '-') ?></div>
                                            <div style="font-size:11px; color:var(--text-light);"><?= htmlspecialchars($b['customer_phone'] ?? '-') ?></div>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($b['pet_name']) ?></strong> <span style="font-size:12px; color:var(--text-muted);">(<?= htmlspecialchars($b['pet_species']) ?>)</span>
                                            <div style="font-size:12px; color:var(--sage-dark); font-weight:600;"><?= htmlspecialchars($b['package_name']) ?></div>
                                            <?php if (!empty($b['addons'])): ?>
                                                <div style="font-size:11px; color:var(--text-muted);">+ <?= count($b['addons']) ?> layanan tambahan</div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <div style="white-space:nowrap; font-weight:600; font-size:13px;">
                                                <?= formatDateIndo($b['check_in']) ?> &rarr; <?= formatDateIndo($b['check_out']) ?>
                                            </div>
                                            <div style="font-size:11.5px; color:var(--text-muted);"><?= (int)$b['duration_days'] ?> Hari / Malam</div>
                                        </td>
                                        <td>
                                            <strong style="color:var(--forest-cta); font-size:14px; white-space:nowrap; display:block;"><?= formatRupiah($b['total_amount']) ?></strong>
                                            <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($b['payment_channel']) ?></div>
                                        </td>
                                        <td style="text-align:center;">
                                            <?php if ($b['payment_status'] === 'settlement'): ?>
                                                <span class="status-badge status-settlement"><i class="fa-solid fa-circle-check"></i> Lunas</span>
                                            <?php elseif ($b['payment_status'] === 'pending'): ?>
                                                <span class="status-badge status-pending"><i class="fa-solid fa-clock"></i> Pending</span>
                                            <?php else: ?>
                                                <span class="status-badge status-cancel"><?= ucfirst(htmlspecialchars($b['payment_status'])) ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td style="text-align:center;">
                                            <div style="display:inline-flex; gap:6px; align-items:center; justify-content:center;">
                                                <a href="struk.php?id=<?= (int)$b['id'] ?>" class="btn btn-outline action-pill-btn" title="Lihat Struk">
                                                    <i class="fa-solid fa-receipt"></i> Struk
                                                </a>
                                                <?php if ($b['payment_status'] !== 'settlement'): ?>
                                                    <form method="POST" action="index.php?tab=bookings" style="margin:0;" onsubmit="return confirm('Tandai pesanan <?= htmlspecialchars($b['invoice_number']) ?> sebagai LUNAS?');">
                                                        <input type="hidden" name="admin_action" value="update_booking_status">
                                                        <input type="hidden" name="booking_id" value="<?= (int)$b['id'] ?>">
                                                        <input type="hidden" name="new_status" value="settlement">
                                                        <button type="submit" class="btn btn-forest action-pill-btn" title="Konfirmasi Lunas">
                                                            <i class="fa-solid fa-check"></i> Lunas
                                                        </button>
                                                    </form>
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
        </section>

        <!-- ==============================================================
             TAB 4: PUSAT CETAK LAPORAN RESMI
             ============================================================== -->
        <section class="dashboard-section <?= $activeTab === 'print-center' ? 'active' : '' ?>">
            <div class="card-panel">
                <div class="section-header" style="margin-bottom:20px;">
                    <div>
                        <h2><i class="fa-solid fa-print" style="color:var(--sage-primary);"></i> Pusat Cetak Laporan Resmi HappyPet</h2>
                        <p>Cetak laporan dalam format dokumen A4 siap print / simpan sebagai PDF, atau unduh langsung dalam format Excel (.CSV).</p>
                    </div>
                </div>

                <!-- 3 Kartu Cetak Cepat -->
                <div class="print-option-grid">
                    <div class="print-option-card">
                        <div>
                            <div class="print-icon-box" style="background:var(--forest-cta); color:#FFFFFF;">
                                <i class="fa-solid fa-book-open"></i>
                            </div>
                            <h3>Laporan Lengkap (All-in-One)</h3>
                            <p>
                                Mencakup ringkasan keuangan, seluruh daftar pemesanan penitipan, serta daftar customer beserta waktu pembuatan akun.
                            </p>
                        </div>
                        <a href="cetak_laporan.php?type=all" class="btn btn-forest print-card-btn">
                            <i class="fa-solid fa-print"></i> Cetak Laporan
                        </a>
                    </div>

                    <div class="print-option-card">
                        <div>
                            <div class="print-icon-box" style="background:var(--sage-light); color:var(--forest-cta);">
                                <i class="fa-solid fa-file-invoice-dollar"></i>
                            </div>
                            <h3>Laporan Pemesanan &amp; Pendapatan</h3>
                            <p>
                                Daftar lengkap invoice, paket layanan, jadwal menginap, status pembayaran, dan total omzet penitipan hewan.
                            </p>
                        </div>
                        <a href="cetak_laporan.php?type=bookings" class="btn btn-forest print-card-btn">
                            <i class="fa-solid fa-print"></i> Cetak Laporan
                        </a>
                    </div>

                    <div class="print-option-card">
                        <div>
                            <div class="print-icon-box" style="background:var(--sand-light); color:var(--amber-hover);">
                                <i class="fa-solid fa-users"></i>
                            </div>
                            <h3>Laporan Data Customer</h3>
                            <p>
                                Rekap data akun customer terdaftar lengkap dengan tanggal &amp; jam pembuatan akun, kontak, dan total belanja.
                            </p>
                        </div>
                        <a href="cetak_laporan.php?type=customers" class="btn btn-forest print-card-btn">
                            <i class="fa-solid fa-print"></i> Cetak Laporan
                        </a>
                    </div>
                </div>

                <!-- Form Cetak Kustom Berdasarkan Periode -->
                <div style="background:var(--bg-alabaster); border:1px solid var(--border-subtle); border-radius:16px; padding:24px;">
                    <h3 style="font-size:16px; color:var(--forest-cta); margin-bottom:4px;">
                        <i class="fa-solid fa-sliders" style="color:var(--amber-cta);"></i> Cetak Laporan Kustom (Filter Periode &amp; Status)
                    </h3>
                    <p style="font-size:13px; color:var(--text-muted); margin-bottom:18px;">
                        Atur rentang tanggal, jenis laporan, atau status pembayaran sebelum mencetak laporan.
                    </p>
                    <form method="GET" action="cetak_laporan.php">
                        <div class="form-grid-2" style="margin-bottom:14px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Jenis Laporan</label>
                                <select name="type" class="form-control">
                                    <option value="all">Laporan Lengkap (Ringkasan + Pemesanan + Customer)</option>
                                    <option value="bookings">Laporan Pemesanan Saja</option>
                                    <option value="customers">Laporan Data Customer Saja</option>
                                </select>
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Filter Status Pembayaran</label>
                                <select name="status" class="form-control">
                                    <option value="all">Semua Status</option>
                                    <option value="settlement">Hanya Lunas (Settlement)</option>
                                    <option value="pending">Hanya Pending</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-grid-2" style="margin-bottom:18px;">
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Periode Dari Tanggal (Opsional)</label>
                                <input type="date" name="date_from" class="form-control">
                            </div>
                            <div class="form-group" style="margin-bottom:0;">
                                <label class="form-label">Sampai Tanggal (Opsional)</label>
                                <input type="date" name="date_to" class="form-control">
                            </div>
                        </div>
                        <div>
                            <button type="submit" class="btn btn-forest" style="min-width:200px;">
                                <i class="fa-solid fa-print"></i> Cetak Laporan
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </section>

        <!-- ==============================================================
             TAB 5: LOG INTERAKSI REAL-TIME (MYSQL user_activity_logs)
             ============================================================== -->
        <section class="dashboard-section <?= $activeTab === 'activity' ? 'active' : '' ?>">
            <div class="card-panel">
                <div class="section-header" style="margin-bottom:16px;">
                    <div>
                        <h2><i class="fa-solid fa-clock-rotate-left" style="color:var(--sage-primary);"></i> Log Interaksi User Real-Time (MySQL)</h2>
                        <p>Menampilkan 60 aktivitas terbaru dari tabel <code>user_activity_logs</code> di database <code>happypet_db</code>.</p>
                    </div>
                    <a href="index.php?tab=activity" class="btn btn-outline btn-sm">
                        <i class="fa-solid fa-rotate"></i> Muat Ulang Log
                    </a>
                </div>
                <div class="table-responsive">
                    <table class="hp-table">
                        <thead>
                            <tr>
                                <th style="width:165px;">Waktu</th>
                                <th style="width:180px;">Pengguna</th>
                                <th style="width:160px;">Tipe Aksi</th>
                                <th>Deskripsi Interaksi</th>
                                <th style="width:120px;">IP Address</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($activityLogs)): ?>
                                <tr><td colspan="5" style="text-align:center; padding:32px; color:var(--text-muted);">Belum ada log aktivitas tercatat.</td></tr>
                            <?php else: ?>
                                <?php foreach ($activityLogs as $log): ?>
                                    <tr>
                                        <td style="white-space:nowrap; font-size:12.5px; color:var(--text-muted);">
                                            <?= formatDateIndo($log['created_at'], true) ?>
                                        </td>
                                        <td>
                                            <strong style="font-size:13px;"><?= htmlspecialchars($log['user_name'] ?? 'Guest') ?></strong>
                                            <?php if (!empty($log['user_email'])): ?>
                                                <div style="font-size:11px; color:var(--text-muted);"><?= htmlspecialchars($log['user_email']) ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <span style="display:inline-block; padding:3px 9px; border-radius:7px; font-family:'JetBrains Mono',monospace; font-size:11.5px; font-weight:700; background:var(--sage-light); color:var(--forest-cta);">
                                                <?= htmlspecialchars($log['action_type']) ?>
                                            </span>
                                        </td>
                                        <td style="font-size:13px;"><?= htmlspecialchars($log['description']) ?></td>
                                        <td style="font-family:'JetBrains Mono',monospace; font-size:12px; color:var(--text-muted);">
                                            <?= htmlspecialchars($log['ip_address'] ?? '-') ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </section>
    </main>
</div>

</body>
</html>
