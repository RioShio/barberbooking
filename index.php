<?php
// ============================================================
//  BARBERSHOP PRO — Enterprise Management System
//  Database: MySQL via PDO
// ============================================================

$pdo = new PDO("mysql:host=localhost;dbname=barbershop_db", "root", "", [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);

// Auto-create tables if not exist
$pdo->exec("
    CREATE TABLE IF NOT EXISTS bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        kode VARCHAR(10) UNIQUE,
        nama VARCHAR(100) NOT NULL,
        telepon VARCHAR(20),
        potongan VARCHAR(50) NOT NULL,
        kapster VARCHAR(50) DEFAULT 'Any',
        tanggal DATE NOT NULL,
        waktu TIME NOT NULL,
        produk TEXT,
        catatan TEXT,
        status ENUM('pending','confirmed','in_progress','done','cancelled') DEFAULT 'pending',
        total_harga INT DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS kapsters (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nama VARCHAR(100) NOT NULL,
        spesialisasi VARCHAR(200),
        rating DECIMAL(2,1) DEFAULT 5.0,
        foto_inisial VARCHAR(5),
        aktif TINYINT DEFAULT 1
    );
");

// Seed kapsters if empty
$count = $pdo->query("SELECT COUNT(*) FROM kapsters")->fetchColumn();
if ($count == 0) {
    $pdo->exec("INSERT INTO kapsters (nama, spesialisasi, rating, foto_inisial) VALUES
        ('Rizki Andika','Wolf Cut, Curly, Fade',4.9,'RA'),
        ('Dimas Pratama','French Crop, Classic, Mullet',4.8,'DP'),
        ('Fajar Nugroho','Fade, Undercut, Pompadour',4.7,'FN')
    ");
}

// ============================================================
//  PRICE MAP
// ============================================================
$harga_potong = [
    'Wolf Cut'       => 75000,
    'Curly Layered'  => 85000,
    'French Crop'    => 65000,
    'Mullet'         => 70000,
    'Undercut'       => 60000,
    'Pompadour'      => 80000,
    'Fade Classic'   => 65000,
    'Buzz Cut'       => 45000,
];
$harga_produk = [
    'Rosemary Hair Oil'  => 85000,
    'Ginseng Hair Tonic' => 65000,
    'Clay Pomade'        => 95000,
    'Matte Wax'          => 75000,
    'Sea Salt Spray'     => 55000,
];

// ============================================================
//  GENERATE BOOKING CODE
// ============================================================
function generateKode($pdo) {
    do {
        $kode = 'BS' . date('md') . strtoupper(substr(md5(uniqid()), 0, 4));
        $exists = $pdo->prepare("SELECT id FROM bookings WHERE kode=?");
        $exists->execute([$kode]);
    } while ($exists->fetch());
    return $kode;
}

// ============================================================
//  HANDLE ACTIONS
// ============================================================
$msg = '';
$msg_type = '';

// Handle booking submission
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['booking'])) {
    $nama     = htmlspecialchars(trim($_POST['nama']));
    $telepon  = htmlspecialchars(trim($_POST['telepon'] ?? ''));
    $potongan = $_POST['potongan'];
    $kapster  = $_POST['kapster'] ?? 'Any';
    $tanggal  = $_POST['tanggal'];
    $waktu    = $_POST['waktu'];
    $produk   = isset($_POST['produk']) ? implode(", ", $_POST['produk']) : '';
    $catatan  = htmlspecialchars(trim($_POST['catatan'] ?? ''));

    // Conflict check
    $conflict = $pdo->prepare("SELECT id FROM bookings WHERE tanggal=? AND waktu=? AND kapster=? AND kapster!='Any' AND status NOT IN ('cancelled','done')");
    $conflict->execute([$tanggal, $waktu, $kapster]);

    if ($conflict->fetch() && $kapster !== 'Any') {
        $msg = "Kapster <strong>$kapster</strong> sudah terbooking pada waktu tersebut. Pilih waktu atau kapster lain.";
        $msg_type = 'error';
    } elseif (empty($nama)) {
        $msg = "Nama pelanggan wajib diisi.";
        $msg_type = 'error';
    } else {
        $total = ($harga_potong[$potongan] ?? 0);
        if (!empty($_POST['produk'])) {
            foreach ($_POST['produk'] as $p) $total += ($harga_produk[$p] ?? 0);
        }
        $kode = generateKode($pdo);
        $stmt = $pdo->prepare("INSERT INTO bookings (kode,nama,telepon,potongan,kapster,tanggal,waktu,produk,catatan,status,total_harga) VALUES (?,?,?,?,?,?,?,?,?,'pending',?)");
        $stmt->execute([$kode, $nama, $telepon, $potongan, $kapster, $tanggal, $waktu, $produk, $catatan, $total]);
        header("Location: index.php?success=1&kode=$kode");
        exit;
    }
}

// Handle status update
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['update_status'])) {
    $id     = (int) $_POST['booking_id'];
    $status = $_POST['status'];
    $valid  = ['pending','confirmed','in_progress','done','cancelled'];
    if (in_array($status, $valid)) {
        $pdo->prepare("UPDATE bookings SET status=? WHERE id=?")->execute([$status, $id]);
    }
    header("Location: index.php?tab=antrian");
    exit;
}

// Handle delete
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $pdo->prepare("DELETE FROM bookings WHERE id=?")->execute([(int)$_GET['delete']]);
    header("Location: index.php?tab=antrian");
    exit;
}

// ============================================================
//  FETCH DATA
// ============================================================
$tab = $_GET['tab'] ?? 'booking';
$bookings = $pdo->query("SELECT * FROM bookings ORDER BY tanggal ASC, waktu ASC")->fetchAll();
$kapsters = $pdo->query("SELECT * FROM kapsters WHERE aktif=1")->fetchAll();

// Stats
$total_today    = $pdo->query("SELECT COUNT(*) FROM bookings WHERE tanggal=CURDATE()")->fetchColumn();
$total_pending  = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='pending'")->fetchColumn();
$total_done     = $pdo->query("SELECT COUNT(*) FROM bookings WHERE status='done'")->fetchColumn();
$revenue_today  = $pdo->query("SELECT SUM(total_harga) FROM bookings WHERE tanggal=CURDATE() AND status='done'")->fetchColumn() ?? 0;
$revenue_month  = $pdo->query("SELECT SUM(total_harga) FROM bookings WHERE MONTH(tanggal)=MONTH(CURDATE()) AND status='done'")->fetchColumn() ?? 0;

// Filter for antrian tab
$filter_status = $_GET['filter'] ?? 'all';
$filter_date   = $_GET['date'] ?? '';
$search_q      = $_GET['q'] ?? '';

$where = ["1=1"];
$params = [];
if ($filter_status !== 'all') { $where[] = "status=?"; $params[] = $filter_status; }
if ($filter_date)             { $where[] = "tanggal=?"; $params[] = $filter_date; }
if ($search_q)                { $where[] = "(nama LIKE ? OR kode LIKE ? OR telepon LIKE ?)"; $params[] = "%$search_q%"; $params[] = "%$search_q%"; $params[] = "%$search_q%"; }

$sql_where = implode(' AND ', $where);
$stmt = $pdo->prepare("SELECT * FROM bookings WHERE $sql_where ORDER BY tanggal ASC, waktu ASC");
$stmt->execute($params);
$filtered_bookings = $stmt->fetchAll();

// Status badge config
function statusBadge($status) {
    $map = [
        'pending'     => ['label'=>'Pending',      'class'=>'badge-pending'],
        'confirmed'   => ['label'=>'Confirmed',     'class'=>'badge-confirmed'],
        'in_progress' => ['label'=>'In Progress',   'class'=>'badge-progress'],
        'done'        => ['label'=>'Selesai',        'class'=>'badge-done'],
        'cancelled'   => ['label'=>'Dibatalkan',     'class'=>'badge-cancelled'],
    ];
    $d = $map[$status] ?? $map['pending'];
    return "<span class='badge {$d['class']}'>{$d['label']}</span>";
}

function formatRp($n) {
    return 'Rp ' . number_format($n, 0, ',', '.');
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>BarberPro — Enterprise Management</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Syne:wght@400;600;700;800&family=DM+Sans:ital,opsz,wght@0,9..40,300;0,9..40,400;0,9..40,500;1,9..40,300&display=swap" rel="stylesheet">
<style>
/* ============================================================
   TOKENS & RESET
   ============================================================ */
:root {
    --bg:        #0f0f0f;
    --bg-2:      #161616;
    --bg-3:      #1e1e1e;
    --bg-4:      #252525;
    --border:    rgba(255,255,255,.07);
    --border-2:  rgba(255,255,255,.13);
    --gold:      #D4A843;
    --gold-light:#F0C75A;
    --gold-dim:  rgba(212,168,67,.15);
    --gold-dim2: rgba(212,168,67,.08);
    --text:      #EFEFEF;
    --text-2:    #A0A0A0;
    --text-3:    #6A6A6A;
    --red:       #E05050;
    --green:     #4CAF80;
    --blue:      #4A90D9;
    --orange:    #E07A30;
    --font-head: 'Syne', sans-serif;
    --font-body: 'DM Sans', sans-serif;
    --radius:    10px;
    --radius-lg: 16px;
    --radius-xl: 22px;
    --shadow:    0 4px 24px rgba(0,0,0,.4);
    --shadow-lg: 0 8px 48px rgba(0,0,0,.6);
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
html { font-size: 16px; }
body {
    background: var(--bg);
    color: var(--text);
    font-family: var(--font-body);
    min-height: 100vh;
    line-height: 1.6;
}
a { color: inherit; text-decoration: none; }
input, select, textarea, button { font-family: var(--font-body); }

/* ============================================================
   SCROLLBAR
   ============================================================ */
::-webkit-scrollbar { width: 6px; }
::-webkit-scrollbar-track { background: var(--bg-2); }
::-webkit-scrollbar-thumb { background: var(--bg-4); border-radius: 3px; }

/* ============================================================
   LAYOUT
   ============================================================ */
.wrapper { display: flex; min-height: 100vh; }

/* SIDEBAR */
.sidebar {
    width: 240px;
    min-width: 240px;
    background: var(--bg-2);
    border-right: 1px solid var(--border);
    display: flex;
    flex-direction: column;
    padding: 0;
    position: sticky;
    top: 0;
    height: 100vh;
    overflow-y: auto;
}
.sidebar-logo {
    padding: 28px 24px 24px;
    border-bottom: 1px solid var(--border);
}
.sidebar-logo .brand {
    font-family: var(--font-head);
    font-size: 22px;
    font-weight: 800;
    color: var(--gold);
    letter-spacing: -.5px;
    display: flex;
    align-items: center;
    gap: 8px;
}
.sidebar-logo .brand .scissors {
    font-size: 20px;
    transform: rotate(-45deg);
    display: inline-block;
}
.sidebar-logo .sub {
    font-size: 11px;
    color: var(--text-3);
    text-transform: uppercase;
    letter-spacing: 1.5px;
    margin-top: 2px;
}
.sidebar-nav {
    flex: 1;
    padding: 16px 12px;
}
.nav-section {
    font-size: 10px;
    text-transform: uppercase;
    letter-spacing: 1.5px;
    color: var(--text-3);
    padding: 0 12px;
    margin: 16px 0 6px;
}
.nav-item {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 10px 12px;
    border-radius: var(--radius);
    font-size: 14px;
    color: var(--text-2);
    cursor: pointer;
    transition: all .18s;
    text-decoration: none;
    border: none;
    background: none;
    width: 100%;
    text-align: left;
}
.nav-item:hover { background: var(--bg-3); color: var(--text); }
.nav-item.active { background: var(--gold-dim); color: var(--gold); font-weight: 500; }
.nav-item svg { width: 18px; height: 18px; flex-shrink: 0; }
.nav-badge {
    margin-left: auto;
    background: var(--gold);
    color: #000;
    font-size: 10px;
    font-weight: 700;
    padding: 2px 7px;
    border-radius: 20px;
    min-width: 20px;
    text-align: center;
}

.sidebar-footer {
    padding: 16px 24px;
    border-top: 1px solid var(--border);
    font-size: 12px;
    color: var(--text-3);
}
.sidebar-footer strong { color: var(--text-2); }

/* MAIN */
.main { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.topbar {
    background: var(--bg-2);
    border-bottom: 1px solid var(--border);
    padding: 14px 32px;
    display: flex;
    align-items: center;
    justify-content: space-between;
    position: sticky;
    top: 0;
    z-index: 10;
}
.topbar-title { font-family: var(--font-head); font-size: 18px; font-weight: 700; }
.topbar-right { display: flex; align-items: center; gap: 12px; }
.avatar {
    width: 36px; height: 36px;
    border-radius: 50%;
    background: var(--gold-dim);
    border: 2px solid var(--gold);
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-head);
    font-size: 13px;
    font-weight: 700;
    color: var(--gold);
}
.time-badge {
    background: var(--bg-3);
    border: 1px solid var(--border);
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 12px;
    color: var(--text-2);
    font-variant-numeric: tabular-nums;
}

.content { padding: 32px; flex: 1; }

/* ============================================================
   STAT CARDS
   ============================================================ */
.stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 16px;
    margin-bottom: 32px;
}
.stat-card {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    position: relative;
    overflow: hidden;
    transition: border-color .2s;
}
.stat-card:hover { border-color: var(--border-2); }
.stat-card::before {
    content: '';
    position: absolute;
    top: 0; left: 0; right: 0;
    height: 2px;
}
.stat-card.gold::before  { background: var(--gold); }
.stat-card.green::before { background: var(--green); }
.stat-card.blue::before  { background: var(--blue); }
.stat-card.orange::before{ background: var(--orange); }
.stat-label { font-size: 11px; text-transform: uppercase; letter-spacing: 1px; color: var(--text-3); margin-bottom: 8px; }
.stat-value { font-family: var(--font-head); font-size: 26px; font-weight: 800; letter-spacing: -1px; }
.stat-card.gold  .stat-value { color: var(--gold); }
.stat-card.green .stat-value { color: var(--green); }
.stat-card.blue  .stat-value { color: var(--blue); }
.stat-card.orange .stat-value { color: var(--orange); }
.stat-sub { font-size: 12px; color: var(--text-3); margin-top: 4px; }
.stat-icon {
    position: absolute;
    bottom: 14px; right: 16px;
    width: 40px; height: 40px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
    opacity: .4;
}
.stat-card.gold  .stat-icon { background: var(--gold-dim); }
.stat-card.green .stat-icon { background: rgba(76,175,128,.12); }
.stat-card.blue  .stat-icon { background: rgba(74,144,217,.12); }
.stat-card.orange .stat-icon { background: rgba(224,122,48,.12); }

/* ============================================================
   SECTION HEADERS
   ============================================================ */
.section-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    margin-bottom: 20px;
}
.section-title {
    font-family: var(--font-head);
    font-size: 20px;
    font-weight: 700;
    letter-spacing: -.3px;
}
.section-sub { font-size: 13px; color: var(--text-3); margin-top: 2px; }

/* ============================================================
   PANEL / CARD
   ============================================================ */
.panel {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    overflow: hidden;
}

/* ============================================================
   FORM STYLES
   ============================================================ */
.booking-layout {
    display: grid;
    grid-template-columns: 1fr 380px;
    gap: 24px;
    align-items: start;
}
.form-panel {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: 32px;
}
.form-group { margin-bottom: 20px; }
.form-label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--text-3);
    margin-bottom: 8px;
}
.form-label span.req { color: var(--gold); margin-left: 2px; }
.form-input, .form-select, .form-textarea {
    width: 100%;
    background: var(--bg-3);
    border: 1px solid var(--border-2);
    border-radius: var(--radius);
    padding: 12px 14px;
    color: var(--text);
    font-size: 14px;
    transition: border-color .18s, box-shadow .18s;
    outline: none;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    border-color: var(--gold);
    box-shadow: 0 0 0 3px var(--gold-dim2);
}
.form-select { appearance: none; cursor: pointer; }
.form-grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-textarea { resize: vertical; min-height: 80px; }

/* Produk checkbox grid */
.produk-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.produk-item {
    display: flex;
    align-items: flex-start;
    gap: 10px;
    background: var(--bg-3);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 12px;
    cursor: pointer;
    transition: all .18s;
    position: relative;
}
.produk-item:hover { border-color: var(--border-2); }
.produk-item input[type="checkbox"] {
    width: 16px; height: 16px;
    accent-color: var(--gold);
    flex-shrink: 0;
    margin-top: 2px;
    cursor: pointer;
}
.produk-item input[type="checkbox"]:checked ~ .produk-detail { color: var(--text); }
.produk-item:has(input:checked) {
    border-color: var(--gold);
    background: var(--gold-dim2);
}
.produk-name { font-size: 13px; font-weight: 500; }
.produk-price { font-size: 12px; color: var(--gold); margin-top: 1px; }

/* Submit button */
.btn-primary {
    background: var(--gold);
    color: #000;
    font-family: var(--font-head);
    font-size: 14px;
    font-weight: 700;
    letter-spacing: 1px;
    text-transform: uppercase;
    border: none;
    border-radius: var(--radius);
    padding: 14px 28px;
    cursor: pointer;
    width: 100%;
    transition: background .18s, transform .1s;
}
.btn-primary:hover { background: var(--gold-light); }
.btn-primary:active { transform: scale(.99); }
.btn-sm {
    padding: 6px 14px;
    font-size: 12px;
    border-radius: 6px;
    border: 1px solid var(--border-2);
    background: var(--bg-3);
    color: var(--text-2);
    cursor: pointer;
    transition: all .18s;
    font-family: var(--font-body);
}
.btn-sm:hover { border-color: var(--border-2); color: var(--text); background: var(--bg-4); }

/* ============================================================
   SUMMARY SIDEBAR
   ============================================================ */
.summary-panel {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: 24px;
    position: sticky;
    top: 80px;
}
.summary-title {
    font-family: var(--font-head);
    font-size: 16px;
    font-weight: 700;
    margin-bottom: 16px;
    color: var(--gold);
    text-transform: uppercase;
    letter-spacing: 1px;
}
.summary-item {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    padding: 10px 0;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    gap: 12px;
}
.summary-item:last-of-type { border-bottom: none; }
.summary-key { color: var(--text-3); }
.summary-val { color: var(--text); text-align: right; font-weight: 500; }
.summary-total {
    background: var(--gold-dim);
    border: 1px solid rgba(212,168,67,.3);
    border-radius: var(--radius);
    padding: 14px 16px;
    margin-top: 16px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.summary-total .label { font-size: 13px; color: var(--gold); font-weight: 500; text-transform: uppercase; letter-spacing: .5px; }
.summary-total .amount { font-family: var(--font-head); font-size: 20px; color: var(--gold); font-weight: 800; }

/* Kapster cards */
.kapster-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 4px; }
.kapster-card {
    background: var(--bg-3);
    border: 2px solid var(--border);
    border-radius: var(--radius);
    padding: 12px;
    cursor: pointer;
    transition: all .18s;
    text-align: center;
}
.kapster-card:hover { border-color: var(--border-2); }
.kapster-card.selected { border-color: var(--gold); background: var(--gold-dim2); }
.kapster-card input[type="radio"] { display: none; }
.kapster-initial {
    width: 44px; height: 44px;
    border-radius: 50%;
    background: var(--gold-dim);
    border: 2px solid rgba(212,168,67,.3);
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-head);
    font-size: 13px;
    font-weight: 700;
    color: var(--gold);
    margin: 0 auto 8px;
}
.kapster-name { font-size: 12px; font-weight: 600; }
.kapster-rating { font-size: 11px; color: var(--gold); margin-top: 2px; }

/* ============================================================
   ALERT / MESSAGES
   ============================================================ */
.alert {
    padding: 14px 18px;
    border-radius: var(--radius);
    margin-bottom: 24px;
    font-size: 14px;
    display: flex;
    align-items: flex-start;
    gap: 10px;
}
.alert-success {
    background: rgba(76,175,128,.12);
    border: 1px solid rgba(76,175,128,.3);
    color: var(--green);
}
.alert-error {
    background: rgba(224,80,80,.1);
    border: 1px solid rgba(224,80,80,.3);
    color: var(--red);
}
.alert-icon { font-size: 18px; flex-shrink: 0; margin-top: 1px; }

/* ============================================================
   ANTRIAN TABLE
   ============================================================ */
.filter-bar {
    display: flex;
    align-items: center;
    gap: 10px;
    flex-wrap: wrap;
    margin-bottom: 20px;
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 14px 16px;
}
.filter-bar input, .filter-bar select {
    background: var(--bg-3);
    border: 1px solid var(--border-2);
    border-radius: var(--radius);
    padding: 8px 12px;
    color: var(--text);
    font-size: 13px;
    outline: none;
    transition: border-color .18s;
}
.filter-bar input:focus, .filter-bar select:focus { border-color: var(--gold); }
.filter-bar input[type="text"] { flex: 1; min-width: 180px; }
.filter-bar .filter-label { font-size: 12px; color: var(--text-3); white-space: nowrap; }

.table-wrap { overflow-x: auto; }
table { width: 100%; border-collapse: collapse; font-size: 13px; }
thead th {
    padding: 12px 16px;
    text-align: left;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .8px;
    color: var(--text-3);
    border-bottom: 1px solid var(--border);
    white-space: nowrap;
}
tbody td {
    padding: 14px 16px;
    border-bottom: 1px solid var(--border);
    vertical-align: middle;
}
tbody tr:last-child td { border-bottom: none; }
tbody tr:hover td { background: var(--bg-3); }
.td-name { font-weight: 600; font-size: 14px; }
.td-code { font-family: monospace; font-size: 12px; color: var(--text-3); }
.td-phone { font-size: 12px; color: var(--text-2); }

/* BADGES */
.badge {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 600;
    letter-spacing: .3px;
    white-space: nowrap;
}
.badge-pending    { background: rgba(212,168,67,.15); color: var(--gold); border: 1px solid rgba(212,168,67,.25); }
.badge-confirmed  { background: rgba(74,144,217,.15); color: var(--blue); border: 1px solid rgba(74,144,217,.25); }
.badge-progress   { background: rgba(224,122,48,.15);  color: var(--orange); border: 1px solid rgba(224,122,48,.25); }
.badge-done       { background: rgba(76,175,128,.15); color: var(--green); border: 1px solid rgba(76,175,128,.25); }
.badge-cancelled  { background: rgba(224,80,80,.1);   color: var(--red); border: 1px solid rgba(224,80,80,.2); }

/* Status form */
.status-form { display: flex; align-items: center; gap: 6px; }
.status-select {
    background: var(--bg-3);
    border: 1px solid var(--border-2);
    border-radius: 6px;
    padding: 5px 8px;
    color: var(--text);
    font-size: 12px;
    outline: none;
    cursor: pointer;
}
.btn-update {
    background: var(--gold);
    color: #000;
    border: none;
    border-radius: 6px;
    padding: 5px 12px;
    font-size: 11px;
    font-weight: 700;
    cursor: pointer;
    text-transform: uppercase;
    letter-spacing: .3px;
    transition: background .18s;
}
.btn-update:hover { background: var(--gold-light); }
.btn-delete {
    background: rgba(224,80,80,.1);
    color: var(--red);
    border: 1px solid rgba(224,80,80,.2);
    border-radius: 6px;
    padding: 5px 12px;
    font-size: 11px;
    cursor: pointer;
    transition: all .18s;
}
.btn-delete:hover { background: rgba(224,80,80,.2); }

/* ============================================================
   KAPSTER PAGE
   ============================================================ */
.kapster-full-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 20px;
}
.kapster-full-card {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-xl);
    padding: 28px 20px;
    text-align: center;
    transition: border-color .2s;
}
.kapster-full-card:hover { border-color: var(--gold); }
.kapster-big-initial {
    width: 72px; height: 72px;
    border-radius: 50%;
    background: var(--gold-dim);
    border: 3px solid rgba(212,168,67,.4);
    display: flex; align-items: center; justify-content: center;
    font-family: var(--font-head);
    font-size: 22px;
    font-weight: 800;
    color: var(--gold);
    margin: 0 auto 16px;
}
.kapster-full-name { font-family: var(--font-head); font-size: 18px; font-weight: 700; margin-bottom: 4px; }
.kapster-full-spec { font-size: 12px; color: var(--text-3); margin-bottom: 12px; line-height: 1.5; }
.kapster-stars { color: var(--gold); font-size: 14px; letter-spacing: 2px; }
.kapster-stat-row { display: flex; gap: 8px; margin-top: 16px; }
.kapster-mini-stat {
    flex: 1;
    background: var(--bg-3);
    border-radius: var(--radius);
    padding: 10px;
    font-size: 11px;
    color: var(--text-3);
}
.kapster-mini-stat strong { display: block; font-size: 16px; font-family: var(--font-head); color: var(--text); }

/* ============================================================
   KATALOG PAGE
   ============================================================ */
.katalog-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 16px;
}
.katalog-card {
    background: var(--bg-2);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    padding: 20px;
    transition: border-color .2s;
}
.katalog-card:hover { border-color: var(--border-2); }
.katalog-emoji { font-size: 36px; margin-bottom: 12px; }
.katalog-name { font-family: var(--font-head); font-size: 16px; font-weight: 700; margin-bottom: 4px; }
.katalog-price { font-size: 18px; color: var(--gold); font-family: var(--font-head); font-weight: 800; }
.katalog-desc { font-size: 12px; color: var(--text-3); margin-top: 6px; line-height: 1.5; }

/* ============================================================
   EMPTY STATE
   ============================================================ */
.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: var(--text-3);
}
.empty-icon { font-size: 48px; margin-bottom: 12px; opacity: .5; }
.empty-title { font-size: 16px; font-weight: 600; color: var(--text-2); }
.empty-sub { font-size: 13px; margin-top: 4px; }

/* ============================================================
   SUCCESS MODAL
   ============================================================ */
.modal-overlay {
    position: fixed; inset: 0;
    background: rgba(0,0,0,.8);
    display: flex; align-items: center; justify-content: center;
    z-index: 999;
}
.modal {
    background: var(--bg-2);
    border: 1px solid rgba(212,168,67,.3);
    border-radius: var(--radius-xl);
    padding: 40px 36px;
    max-width: 440px;
    width: 90%;
    text-align: center;
    animation: modalIn .3s ease;
}
@keyframes modalIn { from { opacity: 0; transform: translateY(20px) scale(.97); } to { opacity: 1; transform: none; } }
.modal-icon { font-size: 56px; margin-bottom: 16px; }
.modal-title { font-family: var(--font-head); font-size: 24px; font-weight: 800; color: var(--gold); margin-bottom: 8px; }
.modal-sub { font-size: 14px; color: var(--text-2); line-height: 1.6; }
.modal-kode {
    background: var(--bg-3);
    border: 1px solid var(--border-2);
    border-radius: var(--radius);
    padding: 10px 20px;
    font-family: monospace;
    font-size: 22px;
    letter-spacing: 4px;
    color: var(--gold);
    margin: 20px auto;
    display: inline-block;
}
.modal-close {
    margin-top: 20px;
    background: var(--gold);
    color: #000;
    font-family: var(--font-head);
    font-weight: 700;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 1px;
    border: none;
    border-radius: var(--radius);
    padding: 12px 28px;
    cursor: pointer;
    transition: background .18s;
}
.modal-close:hover { background: var(--gold-light); }

/* ============================================================
   RESPONSIVE
   ============================================================ */
@media (max-width: 1024px) {
    .booking-layout { grid-template-columns: 1fr; }
    .summary-panel { position: static; }
}
@media (max-width: 768px) {
    .sidebar { display: none; }
    .content { padding: 16px; }
    .topbar { padding: 12px 16px; }
    .stat-grid { grid-template-columns: 1fr 1fr; }
    .form-grid-2 { grid-template-columns: 1fr; }
    .produk-grid { grid-template-columns: 1fr; }
    .kapster-grid { grid-template-columns: repeat(2, 1fr); }
}
</style>
</head>
<body>

<?php if(isset($_GET['success'])): ?>
<div class="modal-overlay" id="successModal">
    <div class="modal">
        <div class="modal-icon">✂️</div>
        <div class="modal-title">Booking Berhasil!</div>
        <div class="modal-sub">Jadwal Anda telah terdaftar. Tunjukkan kode booking ini saat tiba.</div>
        <div class="modal-kode"><?= htmlspecialchars($_GET['kode'] ?? '') ?></div>
        <div class="modal-sub" style="font-size:12px;">Simpan kode ini sebagai tanda konfirmasi.</div>
        <button class="modal-close" onclick="document.getElementById('successModal').remove(); window.location='index.php'">
            Tutup &amp; Kembali
        </button>
    </div>
</div>
<?php endif; ?>

<div class="wrapper">
    <!-- SIDEBAR -->
    <aside class="sidebar">
        <div class="sidebar-logo">
            <div class="brand"><span class="scissors">✂</span> BarberPro</div>
            <div class="sub">Management System</div>
        </div>
        <nav class="sidebar-nav">
            <div class="nav-section">Menu Utama</div>
            <a href="?tab=dashboard" class="nav-item <?= $tab=='dashboard'?'active':'' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 12h16M4 18h16"/></svg>
                Dashboard
            </a>
            <a href="?tab=booking" class="nav-item <?= $tab=='booking'?'active':'' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M8 7V3m8 4V3m-9 8h10M5 21h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v12a2 2 0 002 2z"/></svg>
                Booking Baru
            </a>
            <a href="?tab=antrian" class="nav-item <?= $tab=='antrian'?'active':'' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/></svg>
                Antrian
                <?php if($total_pending > 0): ?><span class="nav-badge"><?= $total_pending ?></span><?php endif; ?>
            </a>
            <div class="nav-section">Manajemen</div>
            <a href="?tab=kapster" class="nav-item <?= $tab=='kapster'?'active':'' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M17 20h5v-2a3 3 0 00-5.356-1.857M17 20H7m10 0v-2c0-.656-.126-1.283-.356-1.857M7 20H2v-2a3 3 0 015.356-1.857M7 20v-2c0-.656.126-1.283.356-1.857m0 0a5.002 5.002 0 019.288 0"/></svg>
                Kapster
            </a>
            <a href="?tab=katalog" class="nav-item <?= $tab=='katalog'?'active':'' ?>">
                <svg fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                Katalog &amp; Produk
            </a>
        </nav>
        <div class="sidebar-footer">
            <strong>BarberPro v2.0</strong><br>
            <?= date('d M Y') ?>
        </div>
    </aside>

    <!-- MAIN AREA -->
    <div class="main">
        <header class="topbar">
            <div class="topbar-title">
                <?php
                $titles = ['dashboard'=>'Dashboard','booking'=>'Booking Baru','antrian'=>'Antrian Jadwal','kapster'=>'Data Kapster','katalog'=>'Katalog & Produk'];
                echo $titles[$tab] ?? 'Dashboard';
                ?>
            </div>
            <div class="topbar-right">
                <div class="time-badge" id="clock">--:--:--</div>
                <div class="avatar">BP</div>
            </div>
        </header>

        <div class="content">

        <!-- ========================================================
             TAB: DASHBOARD
             ======================================================== -->
        <?php if($tab == 'dashboard'): ?>

        <div class="stat-grid">
            <div class="stat-card gold">
                <div class="stat-label">Booking Hari Ini</div>
                <div class="stat-value"><?= $total_today ?></div>
                <div class="stat-sub">Total reservasi</div>
                <div class="stat-icon">📅</div>
            </div>
            <div class="stat-card blue">
                <div class="stat-label">Pending</div>
                <div class="stat-value"><?= $total_pending ?></div>
                <div class="stat-sub">Menunggu konfirmasi</div>
                <div class="stat-icon">⏳</div>
            </div>
            <div class="stat-card green">
                <div class="stat-label">Selesai</div>
                <div class="stat-value"><?= $total_done ?></div>
                <div class="stat-sub">Semua waktu</div>
                <div class="stat-icon">✅</div>
            </div>
            <div class="stat-card orange">
                <div class="stat-label">Revenue Hari Ini</div>
                <div class="stat-value"><?= $revenue_today > 0 ? 'Rp'.number_format($revenue_today/1000,0).'K' : '0' ?></div>
                <div class="stat-sub"><?= formatRp($revenue_month) ?> / bulan</div>
                <div class="stat-icon">💰</div>
            </div>
        </div>

        <!-- Recent bookings -->
        <div class="section-header">
            <div>
                <div class="section-title">Reservasi Terbaru</div>
                <div class="section-sub">Booking yang masuk baru-baru ini</div>
            </div>
            <a href="?tab=antrian" class="btn-sm">Lihat Semua →</a>
        </div>
        <div class="panel">
            <div class="table-wrap">
            <?php
            $recent = array_slice(array_reverse($bookings), 0, 8);
            if(empty($recent)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📋</div>
                    <div class="empty-title">Belum ada booking</div>
                    <div class="empty-sub">Booking baru akan muncul di sini</div>
                </div>
            <?php else: ?>
            <table>
                <thead><tr>
                    <th>Kode</th><th>Pelanggan</th><th>Potongan</th><th>Kapster</th><th>Jadwal</th><th>Total</th><th>Status</th>
                </tr></thead>
                <tbody>
                <?php foreach($recent as $b): ?>
                <tr>
                    <td><span class="td-code"><?= $b['kode'] ?></span></td>
                    <td>
                        <div class="td-name"><?= htmlspecialchars($b['nama']) ?></div>
                        <div class="td-phone"><?= htmlspecialchars($b['telepon']) ?></div>
                    </td>
                    <td><?= htmlspecialchars($b['potongan']) ?></td>
                    <td><?= htmlspecialchars($b['kapster']) ?></td>
                    <td><?= date('d/m/Y', strtotime($b['tanggal'])) ?><br><span style="color:var(--text-3);font-size:12px"><?= substr($b['waktu'],0,5) ?></span></td>
                    <td style="color:var(--gold);font-weight:600"><?= formatRp($b['total_harga']) ?></td>
                    <td><?= statusBadge($b['status']) ?></td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            </div>
        </div>

        <!-- Kapster summary -->
        <div class="section-header" style="margin-top:32px">
            <div>
                <div class="section-title">Tim Kapster</div>
            </div>
        </div>
        <div class="kapster-full-grid">
        <?php foreach($kapsters as $k):
            $k_done  = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE kapster=? AND status='done'"); $k_done->execute([$k['nama']]); $kd = $k_done->fetchColumn();
            $k_today = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE kapster=? AND tanggal=CURDATE()"); $k_today->execute([$k['nama']]); $kt = $k_today->fetchColumn();
        ?>
        <div class="kapster-full-card">
            <div class="kapster-big-initial"><?= $k['foto_inisial'] ?></div>
            <div class="kapster-full-name"><?= htmlspecialchars($k['nama']) ?></div>
            <div class="kapster-full-spec"><?= htmlspecialchars($k['spesialisasi']) ?></div>
            <div class="kapster-stars">
                <?= str_repeat('★', floor($k['rating'])) ?><?= ($k['rating'] - floor($k['rating'])) >= 0.5 ? '☆' : '' ?>
                <span style="color:var(--text-3);font-size:11px;margin-left:4px"><?= $k['rating'] ?></span>
            </div>
            <div class="kapster-stat-row">
                <div class="kapster-mini-stat"><strong><?= $kt ?></strong>Hari ini</div>
                <div class="kapster-mini-stat"><strong><?= $kd ?></strong>Total done</div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- ========================================================
             TAB: BOOKING
             ======================================================== -->
        <?php elseif($tab == 'booking'): ?>

        <?php if($msg && $msg_type == 'error'): ?>
        <div class="alert alert-error"><span class="alert-icon">⚠️</span><div><?= $msg ?></div></div>
        <?php endif; ?>

        <div class="booking-layout">
            <div class="form-panel">
                <div style="margin-bottom:28px">
                    <h2 style="font-family:var(--font-head);font-size:22px;font-weight:800">Form Reservasi</h2>
                    <p style="font-size:13px;color:var(--text-3);margin-top:4px">Isi data pelanggan untuk membuat jadwal baru</p>
                </div>

                <form method="POST" id="bookingForm">
                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Nama Pelanggan <span class="req">*</span></label>
                            <input type="text" name="nama" class="form-input" placeholder="e.g. Ahmad Fauzi" required>
                        </div>
                        <div class="form-group">
                            <label class="form-label">No. Telepon / WA</label>
                            <input type="text" name="telepon" class="form-input" placeholder="08xxxxxxxxxx">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Model Potongan <span class="req">*</span></label>
                        <select name="potongan" class="form-select" id="selectPotong" required>
                            <?php foreach($harga_potong as $nama => $harga): ?>
                            <option value="<?= $nama ?>" data-harga="<?= $harga ?>"><?= $nama ?> — <?= formatRp($harga) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Pilih Kapster <span class="req">*</span></label>
                        <div class="kapster-grid">
                            <label class="kapster-card" id="kc-any">
                                <input type="radio" name="kapster" value="Any" checked>
                                <div class="kapster-initial" style="background:var(--bg-4);border-color:var(--border-2);color:var(--text-2)">✦</div>
                                <div class="kapster-name">Any Kapster</div>
                                <div class="kapster-rating">Tersedia</div>
                            </label>
                            <?php foreach($kapsters as $k): ?>
                            <label class="kapster-card" id="kc-<?= $k['id'] ?>">
                                <input type="radio" name="kapster" value="<?= htmlspecialchars($k['nama']) ?>">
                                <div class="kapster-initial"><?= $k['foto_inisial'] ?></div>
                                <div class="kapster-name"><?= htmlspecialchars($k['nama']) ?></div>
                                <div class="kapster-rating">★ <?= $k['rating'] ?></div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-grid-2">
                        <div class="form-group">
                            <label class="form-label">Tanggal <span class="req">*</span></label>
                            <input type="date" name="tanggal" class="form-input" min="<?= date('Y-m-d') ?>" required style="color-scheme:dark">
                        </div>
                        <div class="form-group">
                            <label class="form-label">Waktu <span class="req">*</span></label>
                            <input type="time" name="waktu" class="form-input" min="08:00" max="20:00" step="1800" required style="color-scheme:dark">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Produk Perawatan (Opsional)</label>
                        <div class="produk-grid">
                            <?php foreach($harga_produk as $pnama => $pharga): ?>
                            <label class="produk-item">
                                <input type="checkbox" name="produk[]" value="<?= $pnama ?>" data-harga="<?= $pharga ?>" class="produk-chk">
                                <div class="produk-detail">
                                    <div class="produk-name"><?= $pnama ?></div>
                                    <div class="produk-price"><?= formatRp($pharga) ?></div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Catatan Tambahan</label>
                        <textarea name="catatan" class="form-textarea form-input" placeholder="e.g. Preferensi panjang rambah, alergi produk, dll."></textarea>
                    </div>

                    <button type="submit" name="booking" class="btn-primary">
                        ✂ Konfirmasi Booking
                    </button>
                </form>
            </div>

            <!-- Summary sidebar -->
            <div class="summary-panel">
                <div class="summary-title">📋 Ringkasan</div>
                <div class="summary-item">
                    <span class="summary-key">Pelanggan</span>
                    <span class="summary-val" id="s-nama">—</span>
                </div>
                <div class="summary-item">
                    <span class="summary-key">Potongan</span>
                    <span class="summary-val" id="s-potong">Wolf Cut</span>
                </div>
                <div class="summary-item">
                    <span class="summary-key">Kapster</span>
                    <span class="summary-val" id="s-kapster">Any Kapster</span>
                </div>
                <div class="summary-item">
                    <span class="summary-key">Jadwal</span>
                    <span class="summary-val" id="s-jadwal">—</span>
                </div>
                <div class="summary-item">
                    <span class="summary-key">Produk</span>
                    <span class="summary-val" id="s-produk" style="font-size:12px">Tidak ada</span>
                </div>
                <div class="summary-total">
                    <span class="label">Total Estimasi</span>
                    <span class="amount" id="s-total">Rp 0</span>
                </div>

                <div style="margin-top:20px;padding-top:16px;border-top:1px solid var(--border)">
                    <div style="font-size:11px;color:var(--text-3);margin-bottom:8px;text-transform:uppercase;letter-spacing:.8px">Jam Operasional</div>
                    <?php
                    $days = ['Senin–Jumat','Sabtu','Minggu'];
                    $jams = ['08:00 – 20:00','08:00 – 21:00','09:00 – 18:00'];
                    foreach($days as $i=>$d): ?>
                    <div style="display:flex;justify-content:space-between;font-size:12px;color:var(--text-2);margin-bottom:4px">
                        <span><?= $d ?></span><span style="color:var(--text-3)"><?= $jams[$i] ?></span>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ========================================================
             TAB: ANTRIAN
             ======================================================== -->
        <?php elseif($tab == 'antrian'): ?>

        <form method="GET" action="">
            <input type="hidden" name="tab" value="antrian">
            <div class="filter-bar">
                <span class="filter-label">Filter:</span>
                <input type="text" name="q" placeholder="Cari nama / kode / telepon…" value="<?= htmlspecialchars($search_q) ?>">
                <select name="filter">
                    <option value="all" <?= $filter_status=='all'?'selected':'' ?>>Semua Status</option>
                    <option value="pending" <?= $filter_status=='pending'?'selected':'' ?>>Pending</option>
                    <option value="confirmed" <?= $filter_status=='confirmed'?'selected':'' ?>>Confirmed</option>
                    <option value="in_progress" <?= $filter_status=='in_progress'?'selected':'' ?>>In Progress</option>
                    <option value="done" <?= $filter_status=='done'?'selected':'' ?>>Selesai</option>
                    <option value="cancelled" <?= $filter_status=='cancelled'?'selected':'' ?>>Dibatalkan</option>
                </select>
                <input type="date" name="date" value="<?= htmlspecialchars($filter_date) ?>" style="color-scheme:dark">
                <button type="submit" class="btn-sm">Terapkan</button>
                <a href="?tab=antrian" class="btn-sm">Reset</a>
                <span style="margin-left:auto;font-size:12px;color:var(--text-3)"><?= count($filtered_bookings) ?> hasil</span>
            </div>
        </form>

        <div class="panel">
            <div class="table-wrap">
            <?php if(empty($filtered_bookings)): ?>
                <div class="empty-state">
                    <div class="empty-icon">📭</div>
                    <div class="empty-title">Tidak ada data</div>
                    <div class="empty-sub">Coba ubah filter atau tambah booking baru</div>
                </div>
            <?php else: ?>
            <table>
                <thead><tr>
                    <th>Kode</th><th>Pelanggan</th><th>Potongan</th><th>Kapster</th><th>Jadwal</th><th>Produk</th><th>Total</th><th>Status</th><th>Aksi</th>
                </tr></thead>
                <tbody>
                <?php foreach($filtered_bookings as $b): ?>
                <tr>
                    <td><span class="td-code"><?= $b['kode'] ?></span></td>
                    <td>
                        <div class="td-name"><?= htmlspecialchars($b['nama']) ?></div>
                        <?php if($b['telepon']): ?><div class="td-phone">📱 <?= htmlspecialchars($b['telepon']) ?></div><?php endif; ?>
                    </td>
                    <td><?= htmlspecialchars($b['potongan']) ?></td>
                    <td style="font-size:13px"><?= htmlspecialchars($b['kapster']) ?></td>
                    <td>
                        <div style="font-size:13px;font-weight:500"><?= date('d/m/Y', strtotime($b['tanggal'])) ?></div>
                        <div style="font-size:12px;color:var(--text-3)"><?= substr($b['waktu'],0,5) ?></div>
                    </td>
                    <td style="font-size:12px;color:var(--text-3);max-width:140px">
                        <?= $b['produk'] ? htmlspecialchars($b['produk']) : '<em>—</em>' ?>
                    </td>
                    <td style="color:var(--gold);font-weight:600;font-size:13px"><?= formatRp($b['total_harga']) ?></td>
                    <td><?= statusBadge($b['status']) ?></td>
                    <td>
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="booking_id" value="<?= $b['id'] ?>">
                            <div class="status-form">
                                <select name="status" class="status-select">
                                    <?php foreach(['pending','confirmed','in_progress','done','cancelled'] as $s): ?>
                                    <option value="<?= $s ?>" <?= $b['status']==$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="submit" name="update_status" class="btn-update">✓</button>
                            </div>
                        </form>
                        <a href="?delete=<?= $b['id'] ?>&tab=antrian" class="btn-delete" onclick="return confirm('Hapus booking ini?')" style="margin-top:4px;display:inline-block">✕</a>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            </div>
        </div>

        <!-- ========================================================
             TAB: KAPSTER
             ======================================================== -->
        <?php elseif($tab == 'kapster'): ?>

        <div class="kapster-full-grid">
        <?php foreach($kapsters as $k):
            $k_done  = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE kapster=? AND status='done'"); $k_done->execute([$k['nama']]); $kd = $k_done->fetchColumn();
            $k_today = $pdo->prepare("SELECT COUNT(*) FROM bookings WHERE kapster=? AND tanggal=CURDATE()"); $k_today->execute([$k['nama']]); $kt = $k_today->fetchColumn();
            $k_rev   = $pdo->prepare("SELECT SUM(total_harga) FROM bookings WHERE kapster=? AND status='done'"); $k_rev->execute([$k['nama']]); $kr = $k_rev->fetchColumn() ?? 0;
        ?>
        <div class="kapster-full-card">
            <div class="kapster-big-initial"><?= $k['foto_inisial'] ?></div>
            <div class="kapster-full-name"><?= htmlspecialchars($k['nama']) ?></div>
            <div class="kapster-full-spec"><?= htmlspecialchars($k['spesialisasi']) ?></div>
            <div class="kapster-stars">
                <?php for($i=1;$i<=5;$i++) echo $i <= $k['rating'] ? '★' : '☆'; ?>
                <span style="font-family:var(--font-head);font-size:14px;margin-left:4px;color:var(--gold)"><?= $k['rating'] ?></span>
            </div>
            <div class="kapster-stat-row">
                <div class="kapster-mini-stat"><strong><?= $kt ?></strong>Hari ini</div>
                <div class="kapster-mini-stat"><strong><?= $kd ?></strong>Total selesai</div>
            </div>
            <div style="margin-top:10px;background:var(--bg-3);border-radius:var(--radius);padding:10px;font-size:12px;color:var(--text-3)">
                Total Revenue<br>
                <strong style="font-family:var(--font-head);font-size:16px;color:var(--gold)"><?= formatRp($kr) ?></strong>
            </div>
        </div>
        <?php endforeach; ?>
        </div>

        <!-- ========================================================
             TAB: KATALOG
             ======================================================== -->
        <?php elseif($tab == 'katalog'): ?>

        <div class="section-header">
            <div>
                <div class="section-title">Katalog Potongan</div>
                <div class="section-sub">Pilihan model rambut dan harga</div>
            </div>
        </div>
        <div class="katalog-grid" style="margin-bottom:32px">
            <?php
            $emojis = ['Wolf Cut'=>'🐺','Curly Layered'=>'🌀','French Crop'=>'✂️','Mullet'=>'🎸','Undercut'=>'⚡','Pompadour'=>'💈','Fade Classic'=>'🔱','Buzz Cut'=>'🎯'];
            $descs  = ['Wolf Cut'=>'Potongan bertekstur dengan layer wolf-style','Curly Layered'=>'Layered khusus rambut keriting alami','French Crop'=>'Fringe pendek gaya Prancis modern','Mullet'=>'Panjang belakang, pendek depan retro','Undercut'=>'Sisi bersih dengan top panjang','Pompadour'=>'Klasik vol tinggi elegan','Fade Classic'=>'Fade bersih tanpa efek','Buzz Cut'=>'Pendek rapi serba guna'];
            foreach($harga_potong as $n => $h): ?>
            <div class="katalog-card">
                <div class="katalog-emoji"><?= $emojis[$n] ?? '✂' ?></div>
                <div class="katalog-name"><?= $n ?></div>
                <div class="katalog-price"><?= formatRp($h) ?></div>
                <div class="katalog-desc"><?= $descs[$n] ?? '' ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="section-header">
            <div>
                <div class="section-title">Produk Perawatan</div>
                <div class="section-sub">Produk add-on yang tersedia</div>
            </div>
        </div>
        <div class="katalog-grid">
            <?php
            $pemojis = ['Rosemary Hair Oil'=>'🌿','Ginseng Hair Tonic'=>'🍃','Clay Pomade'=>'🔶','Matte Wax'=>'⬛','Sea Salt Spray'=>'🌊'];
            $pdescs  = ['Rosemary Hair Oil'=>'Minyak rambut herbal stimulasi pertumbuhan','Ginseng Hair Tonic'=>'Tonik rambut ginseng, sehat & kuat','Clay Pomade'=>'Hold kuat, finish natural clay','Matte Wax'=>'Wax matte ringan tanpa kilap','Sea Salt Spray'=>'Spray tekstur pantai, tampilan natural'];
            foreach($harga_produk as $pn => $ph): ?>
            <div class="katalog-card">
                <div class="katalog-emoji"><?= $pemojis[$pn] ?? '🧴' ?></div>
                <div class="katalog-name"><?= $pn ?></div>
                <div class="katalog-price"><?= formatRp($ph) ?></div>
                <div class="katalog-desc"><?= $pdescs[$pn] ?? '' ?></div>
            </div>
            <?php endforeach; ?>
        </div>

        <?php else: ?>
        <!-- Default redirect to dashboard -->
        <?php header("Location: index.php?tab=dashboard"); exit; ?>
        <?php endif; ?>

        </div><!-- /content -->
    </div><!-- /main -->
</div><!-- /wrapper -->

<script>
// ============================================================
//  LIVE CLOCK
// ============================================================
(function clock() {
    const el = document.getElementById('clock');
    function tick() {
        const now = new Date();
        el.textContent = now.toLocaleTimeString('id-ID');
    }
    tick(); setInterval(tick, 1000);
})();

// ============================================================
//  BOOKING FORM: LIVE SUMMARY
// ============================================================
(function summary() {
    const form = document.getElementById('bookingForm');
    if (!form) return;

    const hargaPotong = <?= json_encode($harga_potong) ?>;
    const hargaProduk = <?= json_encode($harga_produk) ?>;

    function fmt(n) { return 'Rp ' + n.toLocaleString('id-ID'); }

    function update() {
        const nama   = form.querySelector('[name=nama]').value || '—';
        const potong = form.querySelector('[name=potongan]').value;
        const jadwal = (() => {
            const t = form.querySelector('[name=tanggal]').value;
            const w = form.querySelector('[name=waktu]').value;
            if (!t || !w) return '—';
            const d = new Date(t);
            return d.toLocaleDateString('id-ID',{day:'2-digit',month:'short',year:'numeric'}) + ' · ' + w.slice(0,5);
        })();
        const kapsterEl = form.querySelector('[name=kapster]:checked');
        const kapster = kapsterEl ? kapsterEl.value : 'Any';
        const produkChk = [...form.querySelectorAll('.produk-chk:checked')];
        const produkNames = produkChk.map(c => c.value);
        let total = hargaPotong[potong] || 0;
        produkChk.forEach(c => { total += hargaProduk[c.value] || 0; });

        document.getElementById('s-nama').textContent    = nama;
        document.getElementById('s-potong').textContent  = potong;
        document.getElementById('s-kapster').textContent = kapster;
        document.getElementById('s-jadwal').textContent  = jadwal;
        document.getElementById('s-produk').textContent  = produkNames.length ? produkNames.join(', ') : 'Tidak ada';
        document.getElementById('s-total').textContent   = fmt(total);
    }

    form.addEventListener('input', update);
    form.addEventListener('change', update);
    update();

    // Kapster card visual toggle
    document.querySelectorAll('.kapster-card').forEach(card => {
        card.addEventListener('click', () => {
            document.querySelectorAll('.kapster-card').forEach(c => c.classList.remove('selected'));
            card.classList.add('selected');
        });
    });
    // Default select "Any"
    const anyCard = document.querySelector('#kc-any');
    if (anyCard) anyCard.classList.add('selected');
})();
</script>
</body>
</html>