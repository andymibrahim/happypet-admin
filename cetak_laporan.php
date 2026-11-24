<?php
ob_start();
/**
 * HappyPet — Halaman Cetak Laporan Resmi Admin (A4 Print / Save PDF & Export Excel CSV)
 * Mendukung 3 jenis laporan:
 *  - type=all       : Laporan Lengkap (Ringkasan + Laporan Pemesanan + Data Customer)
 *  - type=bookings  : Laporan Pemesanan & Pendapatan
 *  - type=customers : Laporan Data Customer & Waktu Pembuatan Akun
 */

require_once __DIR__ . '/config/database.php';

if (empty($_SESSION['happypet_admin_id'])) {
    header('Location: login.php');
    exit;
}

$db = HappyPetDB::getInstance();
$dbInfo = $db->getDbInfo();
$adminId = (int)$_SESSION['happypet_admin_id'];
$adminUser = $db->findUserById($adminId);

if (!$adminUser || ($adminUser['role'] ?? 'customer') !== 'admin') {
    header('Location: login.php');
    exit;
}

$type = $_GET['type'] ?? 'all';
if (!in_array($type, ['all', 'bookings', 'customers'], true)) {
    $type = 'all';
}

$status = trim((string)($_GET['status'] ?? 'all'));
$packageId = (int)($_GET['package_id'] ?? 0);
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$export = trim((string)($_GET['export'] ?? ''));
$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';

$bookings = ($type === 'all' || $type === 'bookings')
    ? $db->getAllBookingsReport([
        'status' => $status,
        'package_id' => $packageId,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'search' => $search,
    ])
    : [];

$customers = ($type === 'all' || $type === 'customers')
    ? $db->getAllCustomers($search, $dateFrom, $dateTo)
    : [];

// Compute totals
$totalBookings = count($bookings);
$paidBookings = 0;
$pendingBookings = 0;
$paidRevenue = 0;
$totalNominalAll = 0;

foreach ($bookings as $b) {
    $totalNominalAll += (int)$b['total_amount'];
    if ($b['payment_status'] === 'settlement') {
        $paidBookings++;
        $paidRevenue += (int)$b['total_amount'];
    } elseif ($b['payment_status'] === 'pending') {
        $pendingBookings++;
    }
}

$totalCustomers = count($customers);

// =========================================================================
// EXPORT CSV (EXCEL) MODE
// =========================================================================
if ($export === 'csv') {
    $db->logActivity(
        $adminId,
        'admin_export_csv',
        'Admin mengekspor laporan CSV (' . $type . ').',
        'report',
        $type,
        ['type' => $type, 'status' => $status, 'date_from' => $dateFrom, 'date_to' => $dateTo],
        $adminUser
    );

    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    $filename = 'Laporan-HappyPet-' . $type . '-' . date('Ymd-His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo "\xEF\xBB\xBF"; // UTF-8 BOM for Microsoft Excel

    $out = fopen('php://output', 'w');

    if ($type === 'customers') {
        fputcsv($out, ['ID', 'Nama Customer', 'Email', 'No. Telepon', 'Alamat', 'Tier', 'Waktu Pembuatan Akun', 'Jumlah Hewan', 'Total Pesanan', 'Pesanan Lunas', 'Total Belanja Lunas (Rp)']);
        foreach ($customers as $c) {
            fputcsv($out, [
                $c['id'],
                $c['name'],
                $c['email'],
                $c['phone'] ?? '-',
                $c['address'] ?? '-',
                $c['member_tier'] ?? '-',
                !empty($c['created_at']) ? $c['created_at'] : ($c['joined_at'] ?? '-'),
                $c['total_pets'],
                $c['total_bookings'],
                $c['paid_bookings'],
                $c['total_spent'],
            ]);
        }
    } else {
        fputcsv($out, ['No. Invoice', 'Tanggal Pesan', 'Nama Customer', 'Email Customer', 'No. HP', 'Nama Hewan', 'Jenis Hewan', 'Paket Layanan', 'Check-In', 'Check-Out', 'Durasi (Hari)', 'Total Biaya (Rp)', 'Metode Pembayaran', 'Status', 'Tanggal Bayar']);
        foreach ($bookings as $b) {
            fputcsv($out, [
                $b['invoice_number'],
                $b['created_at'],
                $b['customer_name'] ?? '-',
                $b['customer_email'] ?? '-',
                $b['customer_phone'] ?? '-',
                $b['pet_name'],
                $b['pet_species'],
                $b['package_name'],
                $b['check_in'],
                $b['check_out'],
                $b['duration_days'],
                $b['total_amount'],
                $b['payment_channel'],
                strtoupper($b['payment_status']),
                $b['paid_at'] ?: '-',
            ]);
        }
        if ($type === 'all' && !empty($customers)) {
            fputcsv($out, []);
            fputcsv($out, ['=== DATA CUSTOMER & WAKTU PEMBUATAN AKUN ===']);
            fputcsv($out, ['ID', 'Nama Customer', 'Email', 'No. Telepon', 'Waktu Pembuatan Akun', 'Jumlah Hewan', 'Total Pesanan', 'Total Belanja Lunas (Rp)']);
            foreach ($customers as $c) {
                fputcsv($out, [
                    $c['id'],
                    $c['name'],
                    $c['email'],
                    $c['phone'] ?? '-',
                    !empty($c['created_at']) ? $c['created_at'] : ($c['joined_at'] ?? '-'),
                    $c['total_pets'],
                    $c['total_bookings'],
                    $c['total_spent'],
                ]);
            }
        }
    }

    fclose($out);
    exit;
}

// Log report print/preview in MySQL
$db->logActivity(
    $adminId,
    'admin_print_report',
    'Admin membuka halaman cetak laporan (' . $type . ').',
    'report',
    $type,
    ['type' => $type, 'status' => $status, 'date_from' => $dateFrom, 'date_to' => $dateTo, 'autoprint' => $autoPrint],
    $adminUser
);

$reportTitles = [
    'all' => 'Laporan Lengkap Pemesanan & Data Customer',
    'bookings' => 'Laporan Pemesanan & Pendapatan Penitipan',
    'customers' => 'Laporan Data Customer & Waktu Pembuatan Akun',
];
$reportTitle = $reportTitles[$type];
$docNumber = 'LAP-HP-' . date('Ymd') . '-' . strtoupper(substr(md5($type . date('His')), 0, 4));

$periodLabel = 'Semua Periode';
if ($dateFrom && $dateTo) {
    $periodLabel = formatDateIndo($dateFrom) . ' s/d ' . formatDateIndo($dateTo);
} elseif ($dateFrom) {
    $periodLabel = 'Mulai ' . formatDateIndo($dateFrom);
} elseif ($dateTo) {
    $periodLabel = 'Sampai ' . formatDateIndo($dateTo);
}

$currentQueryBase = http_build_query([
    'type' => $type,
    'status' => $status,
    'package_id' => $packageId,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
    'search' => $search,
]);
?>
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($reportTitle) ?> — HappyPet Admin</title>
    <link rel="icon" type="image/png" href="assets/img/happypet-paw-badge-transparent.png">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=JetBrains+Mono:wght@500;700&family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: 'Plus Jakarta Sans', -apple-system, BlinkMacSystemFont, sans-serif;
            background: #EBF2ED;
            color: #1E293B;
            padding: 24px 16px 48px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .print-toolbar {
            max-width: 1020px;
            margin: 0 auto 20px;
            background: #354F52;
            color: #FFFFFF;
            padding: 14px 20px;
            border-radius: 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            box-shadow: 0 10px 26px rgba(53, 79, 82, 0.22);
        }
        .toolbar-group {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .tbtn {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 9px 15px;
            border-radius: 10px;
            font-size: 13px;
            font-weight: 700;
            text-decoration: none;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
        }
        .tbtn-amber { background: #D4A373; color: #FFFFFF; }
        .tbtn-amber:hover { background: #C08B57; }
        .tbtn-sand { background: #E6CCB2; color: #354F52; }
        .tbtn-sand:hover { background: #DEC0A3; }
        .tbtn-ghost { background: rgba(255,255,255,0.12); color: #FFFFFF; border-color: rgba(255,255,255,0.22); }
        .tbtn-ghost:hover { background: rgba(255,255,255,0.22); }
        .tbtn-ghost.active { background: #84A98C; border-color: #84A98C; color: #FFFFFF; }

        .report-sheet {
            max-width: 1020px;
            margin: 0 auto;
            background: #FFFFFF;
            border-radius: 18px;
            padding: 38px 42px;
            box-shadow: 0 14px 40px rgba(30, 41, 59, 0.10);
            border: 1px solid #D8E4DC;
        }
        .report-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            gap: 20px;
            padding-bottom: 22px;
            border-bottom: 2.5px solid #354F52;
            margin-bottom: 24px;
        }
        .company-block {
            display: flex;
            align-items: center;
            gap: 14px;
        }
        .company-block img {
            width: 56px;
            height: 56px;
            object-fit: contain;
        }
        .company-block h1 {
            font-family: 'Fraunces', serif;
            font-size: 25px;
            color: #354F52;
            line-height: 1.15;
        }
        .company-block .sub {
            font-size: 11px;
            font-weight: 700;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #84A98C;
            margin-bottom: 4px;
        }
        .company-block p {
            font-size: 12px;
            color: #5A6B7C;
            line-height: 1.45;
        }
        .doc-meta {
            text-align: right;
            font-size: 12px;
            color: #475569;
            line-height: 1.65;
        }
        .doc-meta .doc-title {
            font-size: 16px;
            font-weight: 800;
            color: #354F52;
            text-transform: uppercase;
            letter-spacing: 0.03em;
            margin-bottom: 4px;
        }
        .doc-meta code {
            font-family: 'JetBrains Mono', monospace;
            font-weight: 700;
            color: #354F52;
            background: #EBF2ED;
            padding: 2px 7px;
            border-radius: 5px;
        }

        .kpi-row {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-bottom: 26px;
        }
        .kpi-box {
            background: #F8FAFC;
            border: 1px solid #E2E8F0;
            border-top: 3px solid #84A98C;
            border-radius: 10px;
            padding: 12px 14px;
        }
        .kpi-box.forest { border-top-color: #354F52; background: #F4F9F5; }
        .kpi-box.amber { border-top-color: #D4A373; background: #FDF9F4; }
        .kpi-box .k-label {
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
            color: #64748B;
        }
        .kpi-box .k-val {
            font-size: 18px;
            font-weight: 800;
            color: #1E293B;
            margin-top: 3px;
        }
        .kpi-box .k-sub {
            font-size: 11px;
            color: #64748B;
            margin-top: 2px;
        }

        .section-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            margin: 26px 0 12px;
            padding-bottom: 8px;
            border-bottom: 1.5px solid #E2E8F0;
        }
        .section-heading h2 {
            font-size: 15px;
            font-weight: 800;
            color: #354F52;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        .section-heading span {
            font-size: 12px;
            color: #64748B;
            font-weight: 600;
        }

        table.rep-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
            margin-bottom: 18px;
        }
        table.rep-table th {
            background: #354F52;
            color: #FFFFFF;
            text-align: left;
            padding: 10px 10px;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            font-weight: 700;
            border: 1px solid #354F52;
        }
        table.rep-table td {
            padding: 9px 10px;
            border: 1px solid #DCE5DF;
            vertical-align: top;
            line-height: 1.45;
        }
        table.rep-table tbody tr:nth-child(even) td {
            background: #F9FBF9;
        }
        table.rep-table tbody tr {
            page-break-inside: avoid;
        }
        table.rep-table tfoot td {
            background: #EBF2ED;
            font-weight: 800;
            color: #354F52;
            border: 1px solid #CAD8CE;
            padding: 10px;
        }
        .badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 99px;
            font-size: 10.5px;
            font-weight: 700;
            text-transform: uppercase;
        }
        .badge-lunas { background: #E6F4EA; color: #1E6F43; }
        .badge-pending { background: #FEF5E7; color: #9C6515; }
        .badge-other { background: #FEECEC; color: #B42318; }

        .signature-row {
            margin-top: 34px;
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            gap: 24px;
            page-break-inside: avoid;
        }
        .report-note {
            font-size: 11.5px;
            color: #64748B;
            max-width: 440px;
            line-height: 1.55;
        }
        .signature-box {
            text-align: center;
            width: 240px;
            font-size: 12px;
            color: #1E293B;
        }
        .signature-space {
            height: 62px;
            border-bottom: 1.5px solid #354F52;
            margin: 8px 0 8px;
        }

        @page {
            size: A4 portrait;
            margin: 12mm;
        }
        @media print {
            body { background: #FFFFFF; padding: 0; }
            .no-print { display: none !important; }
            .report-sheet {
                box-shadow: none;
                border: none;
                border-radius: 0;
                padding: 0;
                max-width: 100%;
            }
            .kpi-row { grid-template-columns: repeat(4, 1fr); }
        }
    </style>
</head>
<body>

<!-- TOOLBAR (TERSEMBUNYI SAAT PRINT) -->
<div class="print-toolbar no-print">
    <div class="toolbar-group">
        <a href="index.php?tab=print-center" class="tbtn tbtn-ghost">
            <i class="fa-solid fa-arrow-left"></i> Kembali ke Admin
        </a>
        <a href="cetak_laporan.php?type=all" class="tbtn tbtn-ghost <?= $type === 'all' ? 'active' : '' ?>">Lengkap</a>
        <a href="cetak_laporan.php?type=bookings" class="tbtn tbtn-ghost <?= $type === 'bookings' ? 'active' : '' ?>">Pemesanan</a>
        <a href="cetak_laporan.php?type=customers" class="tbtn tbtn-ghost <?= $type === 'customers' ? 'active' : '' ?>">Customer</a>
    </div>
    <div class="toolbar-group">
        <a href="cetak_laporan.php?<?= htmlspecialchars($currentQueryBase) ?>&export=csv" class="tbtn tbtn-sand">
            <i class="fa-solid fa-file-excel"></i> Download Excel (.CSV)
        </a>
        <button type="button" class="tbtn tbtn-amber" onclick="triggerPrintReport()">
            <i class="fa-solid fa-print"></i> Cetak / Simpan PDF
        </button>
    </div>
</div>

<!-- DOKUMEN LAPORAN A4 -->
<div class="report-sheet">
    <!-- KOP SURAT -->
    <div class="report-header">
        <div class="company-block">
            <img src="assets/img/happypet-paw-badge-transparent.png" alt="HappyPet Logo">
            <div>
                <div class="sub">Luxury Pet Hotel &amp; Organic Spa</div>
                <h1>HappyPet Indonesia</h1>
                <p>
                    Jl. Kemang Raya No. 88, Jakarta Selatan &bull; Telp: (021) 8899-0011<br>
                    Email: admin@happypet.id &bull; Database: <?= strtoupper(htmlspecialchars($dbInfo['driver'] ?? 'mysql')) ?> (<?= htmlspecialchars($dbInfo['database'] ?? 'happypet_db') ?>)
                </p>
            </div>
        </div>
        <div class="doc-meta">
            <div class="doc-title"><?= htmlspecialchars($reportTitle) ?></div>
            <div>No. Dokumen: <code><?= htmlspecialchars($docNumber) ?></code></div>
            <div>Periode: <strong><?= htmlspecialchars($periodLabel) ?></strong></div>
            <?php if ($type !== 'customers' && $status !== 'all'): ?>
                <div>Filter Status: <strong><?= strtoupper(htmlspecialchars($status)) ?></strong></div>
            <?php endif; ?>
            <div>Dicetak: <?= formatDateIndo(date('Y-m-d H:i:s'), true) ?></div>
        </div>
    </div>

    <!-- RINGKASAN KPI -->
    <div class="kpi-row">
        <?php if ($type === 'customers'): ?>
            <div class="kpi-box forest">
                <div class="k-label">Total Customer</div>
                <div class="k-val"><?= $totalCustomers ?> Akun</div>
                <div class="k-sub">Terdaftar di MySQL</div>
            </div>
            <?php
                $sumPets = 0; $sumCustBook = 0; $sumCustSpent = 0;
                foreach ($customers as $c) {
                    $sumPets += (int)$c['total_pets'];
                    $sumCustBook += (int)$c['total_bookings'];
                    $sumCustSpent += (int)$c['total_spent'];
                }
            ?>
            <div class="kpi-box">
                <div class="k-label">Total Anabul Terdaftar</div>
                <div class="k-val"><?= $sumPets ?> Ekor</div>
                <div class="k-sub">Data hewan peliharaan</div>
            </div>
            <div class="kpi-box">
                <div class="k-label">Total Transaksi Customer</div>
                <div class="k-val"><?= $sumCustBook ?> Pesanan</div>
                <div class="k-sub">Seluruh riwayat booking</div>
            </div>
            <div class="kpi-box amber">
                <div class="k-label">Total Belanja Lunas</div>
                <div class="k-val"><?= formatRupiah($sumCustSpent) ?></div>
                <div class="k-sub">Akumulasi lunas</div>
            </div>
        <?php else: ?>
            <div class="kpi-box">
                <div class="k-label">Total Pemesanan</div>
                <div class="k-val"><?= $totalBookings ?> Pesanan</div>
                <div class="k-sub">Sesuai filter aktif</div>
            </div>
            <div class="kpi-box forest">
                <div class="k-label">Transaksi Lunas</div>
                <div class="k-val"><?= $paidBookings ?> Lunas</div>
                <div class="k-sub">Terverifikasi</div>
            </div>
            <div class="kpi-box amber">
                <div class="k-label">Menunggu Bayar</div>
                <div class="k-val"><?= $pendingBookings ?> Pending</div>
                <div class="k-sub">Belum dibayar</div>
            </div>
            <div class="kpi-box forest">
                <div class="k-label">Total Pendapatan Lunas</div>
                <div class="k-val"><?= formatRupiah($paidRevenue) ?></div>
                <div class="k-sub">Total semua: <?= formatRupiah($totalNominalAll) ?></div>
            </div>
        <?php endif; ?>
    </div>

    <!-- BAGIAN LAPORAN PEMESANAN -->
    <?php if ($type === 'all' || $type === 'bookings'): ?>
        <div class="section-heading">
            <h2><i class="fa-solid fa-file-invoice-dollar"></i> Rincian Laporan Pemesanan</h2>
            <span>Menampilkan <?= $totalBookings ?> transaksi</span>
        </div>

        <table class="rep-table">
            <thead>
                <tr>
                    <th style="width:28px; text-align:center;">No</th>
                    <th style="width:145px;">No. Invoice &amp; Tgl</th>
                    <th>Customer</th>
                    <th>Hewan &amp; Paket</th>
                    <th style="width:135px;">Jadwal Menginap</th>
                    <th style="width:105px; text-align:right;">Total Biaya</th>
                    <th style="width:72px; text-align:center;">Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($bookings)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:20px; color:#64748B;">Tidak ada data pemesanan pada periode/filter ini.</td></tr>
                <?php else: ?>
                    <?php foreach ($bookings as $idx => $b): ?>
                        <tr>
                            <td style="text-align:center;"><?= $idx + 1 ?></td>
                            <td>
                                <strong style="font-family:'JetBrains Mono',monospace; font-size:11px; color:#354F52;"><?= htmlspecialchars($b['invoice_number']) ?></strong><br>
                                <span style="font-size:10.5px; color:#64748B;"><?= formatDateIndo($b['created_at'], true) ?></span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($b['customer_name'] ?? '-') ?></strong><br>
                                <span style="font-size:10.5px; color:#64748B;"><?= htmlspecialchars($b['customer_email'] ?? '-') ?></span>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($b['pet_name']) ?></strong> (<?= htmlspecialchars($b['pet_species']) ?>)<br>
                                <span style="color:#52796F; font-weight:600;"><?= htmlspecialchars($b['package_name']) ?></span>
                            </td>
                            <td>
                                <?= formatDateIndo($b['check_in']) ?> &rarr; <?= formatDateIndo($b['check_out']) ?><br>
                                <span style="font-size:10.5px; color:#64748B;"><?= (int)$b['duration_days'] ?> Hari &bull; <?= htmlspecialchars($b['payment_channel']) ?></span>
                            </td>
                            <td style="text-align:right; font-weight:700; white-space:nowrap;">
                                <?= formatRupiah($b['total_amount']) ?>
                            </td>
                            <td style="text-align:center;">
                                <?php if ($b['payment_status'] === 'settlement'): ?>
                                    <span class="badge badge-lunas">Lunas</span>
                                <?php elseif ($b['payment_status'] === 'pending'): ?>
                                    <span class="badge badge-pending">Pending</span>
                                <?php else: ?>
                                    <span class="badge badge-other"><?= htmlspecialchars($b['payment_status']) ?></span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="5" style="text-align:right;">TOTAL PENDAPATAN LUNAS (<?= $paidBookings ?> TRANSAKSI LUNAS)</td>
                    <td colspan="2" style="text-align:right; font-size:13.5px;"><?= formatRupiah($paidRevenue) ?></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>

    <!-- BAGIAN LAPORAN DATA CUSTOMER & TANGGAL PEMBUATAN AKUN -->
    <?php if ($type === 'all' || $type === 'customers'): ?>
        <div class="section-heading">
            <h2><i class="fa-solid fa-users"></i> Daftar Customer &amp; Waktu Pembuatan Akun</h2>
            <span>Total <?= $totalCustomers ?> akun terdaftar</span>
        </div>

        <table class="rep-table">
            <thead>
                <tr>
                    <th style="width:28px; text-align:center;">No</th>
                    <th>Nama Customer</th>
                    <th>Email &amp; No. Telepon</th>
                    <th style="width:155px;">Waktu Pembuatan Akun</th>
                    <th style="width:65px; text-align:center;">Anabul</th>
                    <th style="width:80px; text-align:center;">Pesanan</th>
                    <th style="width:115px; text-align:right;">Total Belanja</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:20px; color:#64748B;">Tidak ada data customer pada periode/filter ini.</td></tr>
                <?php else: ?>
                    <?php $grandCustSpent = 0; ?>
                    <?php foreach ($customers as $idx => $c): ?>
                        <?php
                            $grandCustSpent += (int)$c['total_spent'];
                            $regTime = !empty($c['created_at']) ? $c['created_at'] : ($c['joined_at'] ?? '');
                        ?>
                        <tr>
                            <td style="text-align:center;"><?= $idx + 1 ?></td>
                            <td>
                                <strong><?= htmlspecialchars($c['name']) ?></strong><br>
                                <span style="font-size:10.5px; color:#52796F;"><?= htmlspecialchars($c['member_tier'] ?? 'HappyPaw Member') ?> (ID #<?= (int)$c['id'] ?>)</span>
                            </td>
                            <td>
                                <?= htmlspecialchars($c['email']) ?><br>
                                <span style="font-size:10.5px; color:#64748B;"><?= htmlspecialchars($c['phone'] ?: '-') ?></span>
                            </td>
                            <td style="font-weight:700; color:#354F52; white-space:nowrap;">
                                <?= formatDateIndo($regTime, !empty($c['created_at'])) ?>
                            </td>
                            <td style="text-align:center;"><?= (int)$c['total_pets'] ?></td>
                            <td style="text-align:center;"><?= (int)$c['total_bookings'] ?> (<?= (int)$c['paid_bookings'] ?> lunas)</td>
                            <td style="text-align:right; font-weight:700; white-space:nowrap;"><?= formatRupiah($c['total_spent']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
            <?php if (!empty($customers)): ?>
                <tfoot>
                    <tr>
                        <td colspan="6" style="text-align:right;">AKUMULASI BELANJA LUNAS SELURUH CUSTOMER</td>
                        <td style="text-align:right; font-size:13px;"><?= formatRupiah($grandCustSpent) ?></td>
                    </tr>
                </tfoot>
            <?php endif; ?>
        </table>
    <?php endif; ?>

    <!-- BLOK PENGESAHAN / TANDA TANGAN -->
    <div class="signature-row">
        <div class="report-note">
            <strong>Catatan Sistem:</strong><br>
            Laporan ini dihasilkan secara otomatis dan real-time dari database <code><?= htmlspecialchars($dbInfo['database'] ?? 'happypet_db') ?></code> HappyPet Indonesia.
            Data pembuatan akun customer dan status transaksi mencerminkan kondisi aktual pada saat dokumen dicetak.
        </div>
        <div class="signature-box">
            <div>Jakarta, <?= formatDateIndo(date('Y-m-d')) ?></div>
            <div style="font-weight:700; color:#354F52; margin-top:2px;">Disahkan Oleh,</div>
            <div class="signature-space"></div>
            <div style="font-weight:800; color:#1E293B;"><?= htmlspecialchars($adminUser['name']) ?></div>
            <div style="font-size:11px; color:#64748B;">Administrator HappyPet</div>
        </div>
    </div>
</div>

<script>
    var hasAutoPrinted = false;
    function triggerPrintReport() {
        try {
            window.focus();
            window.print();
        } catch (e) {
            alert('Browser membatasi cetak otomatis. Silakan tekan Ctrl + P pada keyboard Anda untuk mencetak atau menyimpan sebagai PDF.');
        }
    }
    <?php if ($autoPrint): ?>
    function runAutoPrintOnce() {
        if (hasAutoPrinted) return;
        hasAutoPrinted = true;
        setTimeout(triggerPrintReport, 500);
    }
    if (document.readyState === 'interactive' || document.readyState === 'complete') {
        runAutoPrintOnce();
    } else {
        document.addEventListener('DOMContentLoaded', runAutoPrintOnce);
    }
    <?php endif; ?>
</script>
</body>
</html>
