<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

/* ── Gender filter ── */
$gender = $_GET['gender'] ?? '';
$gender = $conn->real_escape_string($gender);

/* ── Active tab ── */
$tab = $_GET['tab'] ?? 'overview';

/* ── STATS ── */
// Total products
$totalProducts = $conn->query("SELECT COUNT(*) AS c FROM products")->fetch_assoc()['c'];

// Total users
$totalUsers = $conn->query("SELECT COUNT(*) AS c FROM users WHERE role='user'")->fetch_assoc()['c'];

// Rentals by status
$rentalStats = $conn->query("SELECT status, COUNT(*) AS c FROM rentals GROUP BY status")->fetch_all(MYSQLI_ASSOC);
$rCount = ['pending'=>0,'approved'=>0,'returned'=>0,'rejected'=>0];
foreach ($rentalStats as $rs) { if (isset($rCount[$rs['status']])) $rCount[$rs['status']] = $rs['c']; }
$totalRentals = array_sum($rCount);

// Revenue
$revenueQuery = "SELECT SUM(rentals.total_price) AS total FROM rentals JOIN products ON rentals.product_id=products.id WHERE rentals.status='approved'";
if ($gender !== '') $revenueQuery .= " AND products.gender='$gender'";
$revenue = (float)($conn->query($revenueQuery)->fetch_assoc()['total'] ?? 0);

// Pending revenue (potential)
$pendingRev = (float)($conn->query("SELECT SUM(total_price) AS t FROM rentals WHERE status='pending'")->fetch_assoc()['t'] ?? 0);

/* ── PRODUCTS ── */
$productQuery = "SELECT * FROM products WHERE 1";
if ($gender !== '') $productQuery .= " AND gender='$gender'";
$productQuery .= " ORDER BY id DESC";
$products = $conn->query($productQuery);

/* ── RENTALS ── */
$statusFilter = $_GET['status'] ?? '';
$statusFilter = $conn->real_escape_string($statusFilter);

$rentalQuery = "
    SELECT rentals.*, users.name AS client_name, users.email AS client_email,
           products.name AS product_name, products.gender AS product_gender,
           products.image AS product_image, products.category AS product_category
    FROM rentals
    JOIN users ON rentals.user_id = users.id
    JOIN products ON rentals.product_id = products.id
    WHERE 1
";
if ($gender !== '')       $rentalQuery .= " AND products.gender='$gender'";
if ($statusFilter !== '') $rentalQuery .= " AND rentals.status='$statusFilter'";
$rentalQuery .= " ORDER BY rentals.id DESC";
$rentals = $conn->query($rentalQuery);

$adminName = $_SESSION['user_name'] ?? 'Admin';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin — Élara</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --cream:    #F5F0E8;
            --sand:     #E8DDD0;
            --clay:     #C4A882;
            --terra:    #8B5E3C;
            --espresso: #3D2314;
            --dark:     #1A0F08;
            --white:    #FDFAF6;
            --error:    #C0392B;
            --success:  #27AE60;
            --nav-h:    68px;

            /* Admin panel tones */
            --panel-bg:   #22110A;
            --panel-card: #2C160D;
            --panel-border: rgba(196,168,130,0.12);
        }

        html { scroll-behavior: smooth; }
        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--panel-bg);
            color: var(--cream);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ══ TOP NAV ══ */
        .topnav {
            position: fixed; top: 0; left: 0; right: 0; z-index: 200;
            height: var(--nav-h);
            background: var(--dark);
            border-bottom: 1px solid var(--panel-border);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 2rem;
        }
        .topnav-brand { display: flex; flex-direction: column; text-decoration: none; }
        .topnav-brand .sublabel { font-size: 0.52rem; letter-spacing: 0.28em; text-transform: uppercase; color: var(--clay); line-height: 1; }
        .topnav-brand .wordmark { font-family: 'Playfair Display', serif; font-size: 1.4rem; font-weight: 700; color: var(--white); line-height: 1; }
        .topnav-brand .admin-tag {
            display: inline-block; font-size: 0.52rem; letter-spacing: 0.2em;
            text-transform: uppercase; background: var(--clay); color: var(--espresso);
            padding: 0.12rem 0.5rem; border-radius: 2px; font-weight: 500; margin-top: 0.2rem;
        }

        .topnav-right { display: flex; align-items: center; gap: 0.5rem; }
        .topnav-right a {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.45rem 1rem; font-size: 0.76rem; letter-spacing: 0.08em;
            text-transform: uppercase; color: rgba(253,250,246,0.5); text-decoration: none;
            border-radius: 2px; transition: color 0.2s, background 0.2s;
        }
        .topnav-right a:hover { color: var(--white); background: rgba(255,255,255,0.05); }
        .topnav-right .btn-add {
            background: var(--clay); color: var(--espresso) !important; font-weight: 500;
        }
        .topnav-right .btn-add:hover { background: var(--terra); color: var(--white) !important; }
        .topnav-right .btn-logout {
            border: 1px solid rgba(196,168,130,0.2); color: rgba(196,168,130,0.6) !important;
        }
        .topnav-right .btn-logout:hover { border-color: var(--clay); color: var(--clay) !important; }
        .admin-greeting { font-size: 0.74rem; color: rgba(196,168,130,0.5); font-style: italic; font-family: 'Playfair Display', serif; padding: 0 0.5rem; }

        /* ══ LAYOUT ══ */
        .layout { display: block; min-height: 100vh; padding-top: var(--nav-h); }

        /* ══ SIDEBAR ══ */
        .sidebar {
            position: fixed; top: var(--nav-h); left: 0; bottom: 0; width: 220px;
            background: var(--dark);
            border-right: 1px solid var(--panel-border);
            padding: 1.5rem 0;
            overflow-y: auto;
            z-index: 100;
        }
        .sidebar-section { padding: 0 1rem 1rem; }
        .sidebar-label {
            font-size: 0.6rem; letter-spacing: 0.25em; text-transform: uppercase;
            color: rgba(196,168,130,0.35); padding: 0 0.75rem 0.5rem;
        }
        .sidebar-link {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.65rem 0.75rem; border-radius: 2px;
            color: rgba(253,250,246,0.45); text-decoration: none;
            font-size: 0.82rem; letter-spacing: 0.04em;
            transition: color 0.2s, background 0.2s;
            margin-bottom: 0.1rem;
        }
        .sidebar-link .sl-icon { font-size: 1rem; width: 20px; text-align: center; }
        .sidebar-link:hover { color: var(--white); background: rgba(255,255,255,0.04); }
        .sidebar-link.active { color: var(--clay); background: rgba(196,168,130,0.08); border-left: 2px solid var(--clay); }
        .sidebar-divider { height: 1px; background: var(--panel-border); margin: 0.75rem 1rem; }

        /* Status filter pills in sidebar */
        .status-filters { padding: 0 1rem; }
        .sf-label { font-size: 0.6rem; letter-spacing: 0.25em; text-transform: uppercase; color: rgba(196,168,130,0.35); padding: 0 0.75rem 0.5rem; display: block; }
        .sf-pill {
            display: flex; align-items: center; gap: 0.6rem;
            padding: 0.55rem 0.75rem; border-radius: 2px;
            color: rgba(253,250,246,0.45); text-decoration: none;
            font-size: 0.78rem; transition: color 0.2s, background 0.2s;
            margin-bottom: 0.1rem;
        }
        .sf-pill:hover { color: var(--white); background: rgba(255,255,255,0.04); }
        .sf-pill.active { color: var(--clay); background: rgba(196,168,130,0.08); }
        .sf-dot { width: 7px; height: 7px; border-radius: 50%; flex-shrink: 0; }
        .sf-count { margin-left: auto; font-size: 0.7rem; background: rgba(196,168,130,0.12); color: var(--clay); padding: 0.1rem 0.45rem; border-radius: 100px; }
        .dot-all      { background: var(--clay); }
        .dot-pending  { background: #E67E22; }
        .dot-approved { background: var(--success); }
        .dot-returned { background: #3498DB; }
        .dot-rejected { background: var(--error); }

        /* Gender filter in sidebar */
        .gender-filters { padding: 0 1rem; }

        /* ══ MAIN CONTENT ══ */
        .main { margin-left: 220px; padding: 2rem 2.5rem; min-width: 0; overflow-x: hidden; }

        /* ══ PAGE HEADER ══ */
        .page-header {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 2rem; gap: 1rem; flex-wrap: wrap;
        }
        .page-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2rem; font-weight: 700; color: var(--white); line-height: 1.15;
        }
        .page-header h1 em { font-style: italic; color: var(--clay); }
        .page-header .sub { font-size: 0.82rem; color: rgba(196,168,130,0.5); margin-top: 0.2rem; }

        /* Gender filter bar */
        .gender-bar {
            display: flex; gap: 0.5rem; align-items: center; flex-wrap: wrap;
        }
        .gender-bar span { font-size: 0.72rem; color: rgba(196,168,130,0.4); letter-spacing: 0.1em; text-transform: uppercase; }
        .g-btn {
            padding: 0.4rem 1rem; border-radius: 100px;
            border: 1.5px solid var(--panel-border);
            background: transparent; color: rgba(253,250,246,0.4);
            font-family: 'DM Sans', sans-serif; font-size: 0.74rem;
            letter-spacing: 0.06em; text-transform: uppercase;
            text-decoration: none; transition: all 0.2s; cursor: pointer;
        }
        .g-btn:hover, .g-btn.active { background: var(--espresso); color: var(--clay); border-color: var(--espresso); }

        /* ══ KPI CARDS ══ */
        .kpi-grid {
            display: grid; grid-template-columns: repeat(4, minmax(0, 1fr));
            gap: 1rem; margin-bottom: 2rem;
        }
        .kpi-card {
            background: var(--panel-card);
            border: 1px solid var(--panel-border);
            border-radius: 2px; padding: 1.2rem;
            position: relative; min-width: 0;
            animation: fadeUp 0.4s ease both;
        }
        .kpi-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 2px;
        }
        .kpi-card.kpi-revenue::before { background: var(--clay); }
        .kpi-card.kpi-products::before { background: var(--terra); }
        .kpi-card.kpi-rentals::before { background: #3498DB; }
        .kpi-card.kpi-users::before { background: var(--success); }

        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .kpi-card:nth-child(1) { animation-delay: 0.05s; }
        .kpi-card:nth-child(2) { animation-delay: 0.10s; }
        .kpi-card:nth-child(3) { animation-delay: 0.15s; }
        .kpi-card:nth-child(4) { animation-delay: 0.20s; }

        .kpi-icon { font-size: 1.3rem; margin-bottom: 0.6rem; opacity: 0.7; line-height: 1; }
        .kpi-label {
            font-size: 0.6rem; letter-spacing: 0.15em; text-transform: uppercase;
            color: rgba(196,168,130,0.45); margin-bottom: 0.4rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .kpi-value {
            font-family: 'Playfair Display', serif;
            font-size: 1.65rem; font-weight: 700; color: var(--white); line-height: 1;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .kpi-value .unit { font-family: 'DM Sans', sans-serif; font-size: 0.75rem; font-weight: 400; color: var(--clay); }
        .kpi-sub {
            font-size: 0.68rem; color: rgba(196,168,130,0.35); margin-top: 0.4rem;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }

        /* ══ SECTION HEADER ══ */
        .section-hd {
            display: flex; align-items: center; justify-content: space-between;
            margin-bottom: 1rem; margin-top: 2rem;
        }
        .section-hd h2 {
            font-family: 'Playfair Display', serif;
            font-size: 1.15rem; font-weight: 600; color: var(--white);
        }
        .section-hd .count-tag {
            font-size: 0.7rem; letter-spacing: 0.1em; text-transform: uppercase;
            color: var(--clay); background: rgba(196,168,130,0.1);
            padding: 0.2rem 0.75rem; border-radius: 100px;
        }
        .section-divider { height: 1px; background: var(--panel-border); margin-bottom: 1.25rem; }

        /* ══ PRODUCTS TABLE ══ */
        .products-table-wrap { overflow-x: auto; border-radius: 2px; border: 1px solid var(--panel-border); }
        .products-table {
            width: 100%; border-collapse: collapse;
            font-size: 0.83rem;
        }
        .products-table thead tr {
            background: rgba(196,168,130,0.07);
            border-bottom: 1px solid var(--panel-border);
        }
        .products-table th {
            padding: 0.75rem 1rem; text-align: left;
            font-size: 0.65rem; letter-spacing: 0.18em; text-transform: uppercase;
            color: rgba(196,168,130,0.5); font-weight: 400; white-space: nowrap;
        }
        .products-table td {
            padding: 0.85rem 1rem;
            border-bottom: 1px solid var(--panel-border);
            color: rgba(253,250,246,0.75); vertical-align: middle;
        }
        .products-table tbody tr { transition: background 0.15s; }
        .products-table tbody tr:hover { background: rgba(196,168,130,0.04); }
        .products-table tbody tr:last-child td { border-bottom: none; }

        .prod-thumb { width: 48px; height: 60px; object-fit: cover; border-radius: 2px; display: block; background: var(--panel-border); }
        .prod-name { font-weight: 500; color: var(--white); }
        .prod-cat { font-size: 0.7rem; color: rgba(196,168,130,0.5); margin-top: 0.15rem; }
        .gender-chip {
            display: inline-flex; align-items: center;
            padding: 0.2rem 0.6rem; border-radius: 100px;
            font-size: 0.66rem; letter-spacing: 0.1em; text-transform: uppercase;
            border: 1px solid var(--panel-border); color: rgba(196,168,130,0.6);
        }

        .size-list { display: flex; flex-wrap: wrap; gap: 0.3rem; }
        .size-chip {
            padding: 0.15rem 0.5rem; border-radius: 2px;
            background: rgba(196,168,130,0.08); border: 1px solid var(--panel-border);
            font-size: 0.68rem; color: rgba(253,250,246,0.6);
            white-space: nowrap;
        }
        .size-chip.low { border-color: rgba(192,57,43,0.4); color: #E07060; }

        .price-cell {
            font-family: 'Playfair Display', serif;
            font-size: 1rem; color: var(--clay);
        }
        .price-cell .unit { font-family: 'DM Sans', sans-serif; font-size: 0.65rem; color: rgba(196,168,130,0.4); }

        .action-btns { display: flex; gap: 0.4rem; }
        .btn-edit, .btn-delete {
            padding: 0.35rem 0.85rem; border-radius: 2px;
            font-family: 'DM Sans', sans-serif; font-size: 0.72rem;
            letter-spacing: 0.06em; text-transform: uppercase;
            text-decoration: none; transition: background 0.2s, color 0.2s;
            white-space: nowrap;
        }
        .btn-edit { background: rgba(196,168,130,0.1); color: var(--clay); border: 1px solid rgba(196,168,130,0.2); }
        .btn-edit:hover { background: var(--clay); color: var(--espresso); }
        .btn-delete { background: rgba(192,57,43,0.08); color: #C0614A; border: 1px solid rgba(192,57,43,0.2); }
        .btn-delete:hover { background: rgba(192,57,43,0.18); color: #E07060; }

        /* ══ RENTAL CARDS ══ */
        .rentals-grid { display: flex; flex-direction: column; gap: 0.75rem; }

        .rental-row {
            background: var(--panel-card);
            border: 1px solid var(--panel-border);
            border-radius: 2px;
            display: grid; grid-template-columns: 5px 56px 1fr auto;
            overflow: hidden;
            animation: fadeUp 0.35s ease both;
            min-width: 0;
        }
        .rental-row:hover { border-color: rgba(196,168,130,0.22); }

        .r-strip { align-self: stretch; }
        .r-thumb { width: 56px; background: rgba(196,168,130,0.06); display: flex; align-items: center; justify-content: center; }
        .r-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .r-thumb .r-no-img { font-size: 1.4rem; opacity: 0.25; }

        .r-body { padding: 1rem 1.25rem; display: flex; flex-direction: column; gap: 0.5rem; min-width: 0; overflow: hidden; }
        .r-top { display: flex; align-items: baseline; gap: 0.5rem; flex-wrap: nowrap; overflow: hidden; }
        .r-top .client-name { font-weight: 500; color: var(--white); font-size: 0.9rem; white-space: nowrap; flex-shrink: 0; }
        .r-top .product-name { font-size: 0.78rem; color: rgba(196,168,130,0.6); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; min-width: 0; }

        .r-meta { display: flex; gap: 0.75rem 1.25rem; flex-wrap: wrap; align-items: center; }
        .r-meta-item { display: flex; align-items: center; gap: 0.35rem; font-size: 0.75rem; color: rgba(253,250,246,0.35); white-space: nowrap; }
        .r-meta-item strong { color: rgba(253,250,246,0.65); font-weight: 500; }
        .r-meta-item .rmi-icon { font-size: 0.8rem; line-height: 1; }

        .r-right {
            padding: 1rem 1.25rem; display: flex; flex-direction: column;
            align-items: flex-end; justify-content: space-between;
            border-left: 1px solid var(--panel-border); min-width: 190px; max-width: 220px;
            gap: 0.75rem;
        }
        .r-price {
            font-family: 'Playfair Display', serif;
            font-size: 1.1rem; font-weight: 700; color: var(--clay);
        }
        .r-price .ru { font-family: 'DM Sans', sans-serif; font-size: 0.68rem; color: rgba(196,168,130,0.4); }

        /* Status badge */
        .s-badge {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.25rem 0.75rem; border-radius: 100px;
            font-size: 0.68rem; font-weight: 500; letter-spacing: 0.07em; text-transform: uppercase;
        }
        .s-badge .bdot { width: 5px; height: 5px; border-radius: 50%; }
        .s-pending  { background: rgba(230,126,34,0.12); color: #D4770A; }
        .s-pending  .bdot { background: #E67E22; }
        .s-approved { background: rgba(39,174,96,0.12); color: #2E9E57; }
        .s-approved .bdot { background: var(--success); }
        .s-returned { background: rgba(52,152,219,0.12); color: #3498DB; }
        .s-returned .bdot { background: #3498DB; }
        .s-rejected { background: rgba(192,57,43,0.1); color: #C0392B; }
        .s-rejected .bdot { background: var(--error); }

        /* Action buttons for rentals */
        .r-actions { display: flex; gap: 0.4rem; flex-wrap: wrap; justify-content: flex-end; margin-top: 0.25rem; }
        .btn-approve, .btn-reject, .btn-return {
            padding: 0.4rem 0.9rem; border-radius: 2px;
            font-family: 'DM Sans', sans-serif; font-size: 0.72rem;
            letter-spacing: 0.06em; text-transform: uppercase;
            text-decoration: none; transition: background 0.2s, color 0.2s, border-color 0.2s;
            white-space: nowrap; display: inline-flex; align-items: center; gap: 0.3rem;
            cursor: pointer;
        }
        .btn-approve { background: rgba(39,174,96,0.15); color: #3DC870; border: 1px solid rgba(39,174,96,0.35); }
        .btn-approve:hover { background: rgba(39,174,96,0.3); border-color: rgba(39,174,96,0.6); color: #5EE08A; }
        .btn-reject  { background: rgba(192,57,43,0.12); color: #E07060; border: 1px solid rgba(192,57,43,0.3); }
        .btn-reject:hover  { background: rgba(192,57,43,0.25); border-color: rgba(192,57,43,0.55); color: #FF8070; }
        .btn-return  { background: rgba(52,152,219,0.12); color: #5EB4E8; border: 1px solid rgba(52,152,219,0.3); }
        .btn-return:hover  { background: rgba(52,152,219,0.25); border-color: rgba(52,152,219,0.55); color: #7ECBF5; }

        /* ══ EMPTY STATE ══ */
        .empty { text-align: center; padding: 3.5rem 1rem; color: rgba(196,168,130,0.3); }
        .empty .e-icon { font-size: 2.5rem; margin-bottom: 0.75rem; opacity: 0.4; }
        .empty p { font-size: 0.85rem; }

        /* ══ FOOTER ══ */
        .admin-footer {
            text-align: center; padding: 1.5rem;
            color: rgba(196,168,130,0.2); font-size: 0.72rem;
            letter-spacing: 0.08em; border-top: 1px solid var(--panel-border);
            margin-top: 3rem;
        }

        /* ══ RESPONSIVE ══ */
        @media (max-width: 1100px) {
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 900px) {
            .layout { grid-template-columns: 1fr; }
            .sidebar { display: none; }
            .main { margin-left: 0; }
            .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        }
        @media (max-width: 560px) {
            .kpi-grid { grid-template-columns: 1fr 1fr; }
            .topnav { padding: 0 1.25rem; }
            .main { padding: 1.25rem; }
            .rental-row { grid-template-columns: 5px 1fr; }
            .r-thumb { display: none; }
            .r-right { grid-column: 1/-1; flex-direction: row; align-items: center; border-left: none; border-top: 1px solid var(--panel-border); }
        }
    </style>
</head>
<body>

<!-- TOP NAV -->
<nav class="topnav">
    <a href="index.php" class="topnav-brand">
        <span class="sublabel">Location Vêtements</span>
        <span class="wordmark">Élara</span>
        <span class="admin-tag">Admin</span>
    </a>
    <div class="topnav-right">
        <span class="admin-greeting"><?php echo htmlspecialchars($adminName); ?></span>
        <a href="index.php">🏪 Boutique</a>
        <a href="add_product.php" class="btn-add">+ Ajouter</a>
        <a href="logout.php" class="btn-logout">Déconnexion</a>
    </div>
</nav>

<div class="layout">

    <!-- SIDEBAR -->
    <aside class="sidebar">

        <div class="sidebar-section">
            <p class="sidebar-label">Navigation</p>
            <a href="dashboard.php" class="sidebar-link <?php echo $statusFilter === '' ? 'active' : ''; ?>">
                <span class="sl-icon">📊</span> Tableau de bord
            </a>
            <a href="add_product.php" class="sidebar-link">
                <span class="sl-icon">➕</span> Ajouter produit
            </a>
            <a href="index.php" class="sidebar-link">
                <span class="sl-icon">🏪</span> Voir la boutique
            </a>
        </div>

        <div class="sidebar-divider"></div>

        <!-- Status filters -->
        <div class="status-filters">
            <span class="sf-label">Filtrer locations</span>
            <a href="dashboard.php?status=&gender=<?php echo urlencode($gender); ?>"
               class="sf-pill <?php echo $statusFilter === '' ? 'active' : ''; ?>">
                <span class="sf-dot dot-all"></span> Toutes
                <span class="sf-count"><?php echo $totalRentals; ?></span>
            </a>
            <a href="dashboard.php?status=pending&gender=<?php echo urlencode($gender); ?>#rentals"
               class="sf-pill <?php echo $statusFilter === 'pending' ? 'active' : ''; ?>">
                <span class="sf-dot dot-pending"></span> En attente
                <span class="sf-count"><?php echo $rCount['pending']; ?></span>
            </a>
            <a href="dashboard.php?status=approved&gender=<?php echo urlencode($gender); ?>#rentals"
               class="sf-pill <?php echo $statusFilter === 'approved' ? 'active' : ''; ?>">
                <span class="sf-dot dot-approved"></span> Approuvées
                <span class="sf-count"><?php echo $rCount['approved']; ?></span>
            </a>
            <a href="dashboard.php?status=returned&gender=<?php echo urlencode($gender); ?>#rentals"
               class="sf-pill <?php echo $statusFilter === 'returned' ? 'active' : ''; ?>">
                <span class="sf-dot dot-returned"></span> Retournées
                <span class="sf-count"><?php echo $rCount['returned']; ?></span>
            </a>
            <a href="dashboard.php?status=rejected&gender=<?php echo urlencode($gender); ?>#rentals"
               class="sf-pill <?php echo $statusFilter === 'rejected' ? 'active' : ''; ?>">
                <span class="sf-dot dot-rejected"></span> Annulées
                <span class="sf-count"><?php echo $rCount['rejected']; ?></span>
            </a>
        </div>

        <div class="sidebar-divider"></div>

        <!-- Gender filters -->
        <div class="gender-filters">
            <span class="sf-label">Filtrer par genre</span>
            <a href="dashboard.php?gender=&status=<?php echo urlencode($statusFilter); ?>"
               class="sf-pill <?php echo $gender === '' ? 'active' : ''; ?>">
                <span class="sf-dot dot-all"></span> Tous
            </a>
            <a href="dashboard.php?gender=Men&status=<?php echo urlencode($statusFilter); ?>"
               class="sf-pill <?php echo $gender === 'Men' ? 'active' : ''; ?>">
                <span class="sf-dot" style="background:var(--clay)"></span> Homme
            </a>
            <a href="dashboard.php?gender=Women&status=<?php echo urlencode($statusFilter); ?>"
               class="sf-pill <?php echo $gender === 'Women' ? 'active' : ''; ?>">
                <span class="sf-dot" style="background:var(--terra)"></span> Femme
            </a>
            <a href="dashboard.php?gender=Unisex&status=<?php echo urlencode($statusFilter); ?>"
               class="sf-pill <?php echo $gender === 'Unisex' ? 'active' : ''; ?>">
                <span class="sf-dot" style="background:#3498DB"></span> Unisexe
            </a>
        </div>

    </aside>

    <!-- MAIN -->
    <main class="main">

        <!-- Page header -->
        <div class="page-header">
            <div>
                <h1>Tableau de <em>Bord</em></h1>
                <p class="sub">Vue d'ensemble de l'activité — <?php echo date('d F Y'); ?></p>
            </div>
        </div>

        <!-- KPI CARDS -->
        <div class="kpi-grid">
            <div class="kpi-card kpi-revenue">
                <div class="kpi-icon">💰</div>
                <div class="kpi-label">Revenus approuvés</div>
                <div class="kpi-value"><?php echo number_format($revenue, 0, ',', ' '); ?> <span class="unit">DA</span></div>
                <div class="kpi-sub">+<?php echo number_format($pendingRev, 0, ',', ' '); ?> DA en attente</div>
            </div>
            <div class="kpi-card kpi-products">
                <div class="kpi-icon">👗</div>
                <div class="kpi-label">Produits</div>
                <div class="kpi-value"><?php echo $totalProducts; ?></div>
                <div class="kpi-sub">Articles en catalogue</div>
            </div>
            <div class="kpi-card kpi-rentals">
                <div class="kpi-icon">📦</div>
                <div class="kpi-label">Locations totales</div>
                <div class="kpi-value"><?php echo $totalRentals; ?></div>
                <div class="kpi-sub"><?php echo $rCount['pending']; ?> en attente de validation</div>
            </div>
            <div class="kpi-card kpi-users">
                <div class="kpi-icon">👤</div>
                <div class="kpi-label">Clients</div>
                <div class="kpi-value"><?php echo $totalUsers; ?></div>
                <div class="kpi-sub">Comptes enregistrés</div>
            </div>
        </div>

        <!-- PRODUCTS SECTION -->
        <div class="section-hd">
            <h2>Produits</h2>
            <span class="count-tag"><?php echo $products->num_rows; ?> article<?php echo $products->num_rows != 1 ? 's' : ''; ?></span>
        </div>
        <div class="section-divider"></div>

        <?php if ($products->num_rows === 0): ?>
        <div class="empty">
            <div class="e-icon">👗</div>
            <p>Aucun produit disponible.</p>
        </div>
        <?php else: ?>
        <div class="products-table-wrap">
            <table class="products-table">
                <thead>
                    <tr>
                        <th>Photo</th>
                        <th>Produit</th>
                        <th>Prix / jour</th>
                        <th>Genre</th>
                        <th>Tailles & stock</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php while ($row = $products->fetch_assoc()): ?>
                <?php
                    $sizes = $conn->query("SELECT * FROM product_sizes WHERE product_id=" . (int)$row['id'] . " ORDER BY size");
                ?>
                <tr>
                    <td>
                        <img src="uploads/<?php echo htmlspecialchars($row['image']); ?>"
                             alt="" class="prod-thumb"
                             onerror="this.style.opacity=0">
                    </td>
                    <td>
                        <div class="prod-name"><?php echo htmlspecialchars($row['name']); ?></div>
                        <div class="prod-cat"><?php echo htmlspecialchars($row['category'] ?? ''); ?></div>
                    </td>
                    <td>
                        <div class="price-cell">
                            <?php echo number_format($row['price_per_day'], 0, ',', ' '); ?>
                            <span class="unit">DA</span>
                        </div>
                    </td>
                    <td>
                        <span class="gender-chip"><?php echo htmlspecialchars($row['gender']); ?></span>
                    </td>
                    <td>
                        <div class="size-list">
                        <?php while ($s = $sizes->fetch_assoc()): ?>
                            <span class="size-chip <?php echo $s['stock'] <= 2 ? 'low' : ''; ?>">
                                <?php echo htmlspecialchars($s['size']); ?> · <?php echo $s['stock']; ?>
                            </span>
                        <?php endwhile; ?>
                        </div>
                    </td>
                    <td>
                        <div class="action-btns">
                            <a href="edit_product.php?id=<?php echo (int)$row['id']; ?>" class="btn-edit">✏ Éditer</a>
                            <a href="delete_product.php?id=<?php echo (int)$row['id']; ?>" class="btn-delete"
                               onclick="return confirm('Supprimer ce produit ?')">✕ Suppr.</a>
                        </div>
                    </td>
                </tr>
                <?php endwhile; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>

        <!-- RENTALS SECTION -->
        <div class="section-hd" id="rentals" style="margin-top:2.5rem;">
            <h2>
                Locations
                <?php if ($statusFilter !== ''): ?>
                <span style="font-size:0.8rem;font-weight:400;color:var(--clay);font-style:italic;">
                    — <?php
                    $sfLabels = ['pending'=>'En attente','approved'=>'Approuvées','returned'=>'Retournées','rejected'=>'Annulées'];
                    echo $sfLabels[$statusFilter] ?? $statusFilter;
                    ?>
                </span>
                <?php endif; ?>
            </h2>
            <span class="count-tag"><?php echo $rentals->num_rows; ?> location<?php echo $rentals->num_rows != 1 ? 's' : ''; ?></span>
        </div>
        <div class="section-divider"></div>

        <?php
        $rentalRows = [];
        while ($r = $rentals->fetch_assoc()) $rentalRows[] = $r;
        ?>

        <?php if (count($rentalRows) === 0): ?>
        <div class="empty">
            <div class="e-icon">📦</div>
            <p>Aucune location pour ce filtre.</p>
        </div>
        <?php else: ?>
        <div class="rentals-grid">
        <?php
        $sLabels = ['pending'=>'En attente','approved'=>'Approuvée','returned'=>'Retournée','rejected'=>'Annulée'];
        foreach ($rentalRows as $r):
            $st = $r['status'];
            $startDt = new DateTime($r['start_date']);
            $endDt   = new DateTime($r['end_date']);
            $days    = max(1, $startDt->diff($endDt)->days);
            $imgSrc  = !empty($r['product_image']) ? 'uploads/' . htmlspecialchars($r['product_image']) : null;
        ?>
        <div class="rental-row">
            <!-- Strip -->
            <div class="r-strip strip-<?php echo htmlspecialchars($st); ?>"
                 style="background:<?php echo ['pending'=>'#E67E22','approved'=>'#27AE60','returned'=>'#3498DB','rejected'=>'#C0392B'][$st] ?? '#888'; ?>">
            </div>

            <!-- Thumb -->
            <div class="r-thumb">
                <?php if ($imgSrc): ?>
                    <img src="<?php echo $imgSrc; ?>" alt="">
                <?php else: ?>
                    <span class="r-no-img">👗</span>
                <?php endif; ?>
            </div>

            <!-- Body -->
            <div class="r-body">
                <div class="r-top">
                    <span class="client-name">👤 <?php echo htmlspecialchars($r['client_name']); ?></span>
                    <span class="product-name">— <?php echo htmlspecialchars($r['product_name']); ?></span>
                </div>
                <div class="r-meta">
                    <div class="r-meta-item">
                        <span class="rmi-icon">📐</span>
                        Taille <strong><?php echo htmlspecialchars($r['size']); ?></strong>
                    </div>
                    <div class="r-meta-item">
                        <span class="rmi-icon">📅</span>
                        <strong><?php echo date('d/m/Y', strtotime($r['start_date'])); ?></strong>
                        → <strong><?php echo date('d/m/Y', strtotime($r['end_date'])); ?></strong>
                    </div>
                    <div class="r-meta-item">
                        <span class="rmi-icon">🕐</span>
                        <strong><?php echo $days; ?></strong> jour<?php echo $days > 1 ? 's' : ''; ?>
                    </div>
                    <?php if (!empty($r['client_email'])): ?>
                    <div class="r-meta-item">
                        <span class="rmi-icon">✉</span>
                        <?php echo htmlspecialchars($r['client_email']); ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Right -->
            <div class="r-right">
                <div class="r-price">
                    <?php echo number_format((int)$r['total_price'], 0, ',', ' '); ?>
                    <span class="ru">DA</span>
                </div>
                <div style="display:flex;flex-direction:column;align-items:flex-end;gap:0.5rem;">
                    <div class="s-badge s-<?php echo htmlspecialchars($st); ?>">
                        <span class="bdot"></span>
                        <?php echo $sLabels[$st] ?? ucfirst($st); ?>
                    </div>
                    <div class="r-actions">
                        <?php if ($st === 'pending'): ?>
                            <a href="update_rental.php?id=<?php echo (int)$r['id']; ?>&action=approve&redirect_status=<?php echo urlencode($statusFilter); ?>&redirect_gender=<?php echo urlencode($gender); ?>"
                               class="btn-approve"
                               onclick="return confirm('Approuver cette location pour <?php echo htmlspecialchars(addslashes($r['client_name'])); ?> ?')">✓ Approuver</a>
                            <a href="update_rental.php?id=<?php echo (int)$r['id']; ?>&action=reject&redirect_status=<?php echo urlencode($statusFilter); ?>&redirect_gender=<?php echo urlencode($gender); ?>"
                               class="btn-reject"
                               onclick="return confirm('Rejeter cette location ?')">✕ Rejeter</a>
                        <?php elseif ($st === 'approved'): ?>
                            <a href="update_rental.php?id=<?php echo (int)$r['id']; ?>&action=return&redirect_status=<?php echo urlencode($statusFilter); ?>&redirect_gender=<?php echo urlencode($gender); ?>"
                               class="btn-return"
                               onclick="return confirm('Marquer comme retourné ?')">↩ Retour</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <div class="admin-footer">
            © <?php echo date('Y'); ?> Élara · Panneau d'administration · Tous droits réservés
        </div>

    </main>
</div>

</body>
</html>