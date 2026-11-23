<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: index.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];

if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION['csrf_token'];

/* Cancel pending rental via POST, validate CSRF, and release its reserved stock. */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel'])) {
    $postedToken = $_POST['csrf_token'] ?? '';
    if (!$postedToken || !hash_equals($csrfToken, $postedToken)) {
        http_response_code(403);
        exit('Invalid request token.');
    }
    $cancel_id = (int)$_POST['cancel'];
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT product_id, size FROM rentals WHERE id=? AND user_id=? AND status='pending' FOR UPDATE");
        $stmt->bind_param("ii", $cancel_id, $user_id);
        $stmt->execute();
        $rentalToCancel = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        if ($rentalToCancel) {
            $stmt = $conn->prepare("UPDATE rentals SET status='rejected' WHERE id=? AND user_id=? AND status='pending'");
            $stmt->bind_param("ii", $cancel_id, $user_id);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("UPDATE product_sizes SET stock=stock+1 WHERE product_id=? AND size=?");
            $stmt->bind_param("is", $rentalToCancel['product_id'], $rentalToCancel['size']);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Rental cancellation failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Unable to cancel rental.');
    }
    header("Location: my_rentals.php");
    exit();
}

$stmt = $conn->prepare("
    SELECT rentals.*, products.name AS product_name, products.image AS product_image,
           products.category AS product_category
    FROM rentals
    JOIN products ON rentals.product_id = products.id
    WHERE rentals.user_id = ?
    ORDER BY rentals.id DESC
");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$rentals = $stmt->get_result();

$userName = $_SESSION['user_name'] ?? '';

/* Collect all rows + count by status */
$counts = ['pending' => 0, 'approved' => 0, 'returned' => 0, 'rejected' => 0];
$allRows = [];
while ($r = $rentals->fetch_assoc()) {
    $allRows[] = $r;
    if (isset($counts[$r['status']])) $counts[$r['status']]++;
}
$total = count($allRows);
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mes Locations — Élara</title>
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
            --nav-h:    72px;
        }

        html { scroll-behavior: smooth; }

        body {
            font-family: 'DM Sans', sans-serif;
            background: var(--cream);
            color: var(--espresso);
            min-height: 100vh;
            overflow-x: hidden;
        }

        /* ══ NAVBAR ══ */
        .navbar {
            position: fixed; top: 0; left: 0; right: 0; z-index: 100;
            height: var(--nav-h);
            background: var(--espresso);
            display: flex; align-items: center; justify-content: space-between;
            padding: 0 2.5rem;
            box-shadow: 0 2px 30px rgba(0,0,0,0.3);
        }
        .nav-brand { display: flex; flex-direction: column; text-decoration: none; }
        .nav-brand .sublabel { font-size: 0.58rem; letter-spacing: 0.28em; text-transform: uppercase; color: var(--clay); line-height: 1; }
        .nav-brand .wordmark { font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 700; color: var(--white); line-height: 1; }
        .nav-links { display: flex; align-items: center; gap: 0.25rem; }
        .nav-links a {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.5rem 1rem;
            font-size: 0.8rem; letter-spacing: 0.06em; text-transform: uppercase;
            color: rgba(253,250,246,0.6); text-decoration: none;
            border-radius: 2px; transition: color 0.2s, background 0.2s;
        }
        .nav-links a:hover { color: var(--white); background: rgba(255,255,255,0.07); }
        .nav-links .btn-primary { background: var(--clay); color: var(--espresso) !important; font-weight: 500; }
        .nav-links .btn-primary:hover { background: var(--terra); color: var(--white) !important; }
        .nav-greeting { font-size: 0.78rem; color: var(--clay); font-style: italic; font-family: 'Playfair Display', serif; padding: 0.5rem 0.75rem; }

        /* Hamburger */
        .hamburger { display: none; flex-direction: column; gap: 5px; background: none; border: none; cursor: pointer; padding: 4px; }
        .hamburger span { display: block; width: 24px; height: 2px; background: var(--clay); transition: all 0.3s; border-radius: 2px; }
        .hamburger.open span:nth-child(1) { transform: rotate(45deg) translate(5px,5px); }
        .hamburger.open span:nth-child(2) { opacity: 0; }
        .hamburger.open span:nth-child(3) { transform: rotate(-45deg) translate(5px,-5px); }
        .mobile-menu { display: none; position: fixed; top: var(--nav-h); left: 0; right: 0; background: var(--dark); z-index: 99; padding: 1.5rem 2rem; flex-direction: column; gap: 0.25rem; box-shadow: 0 10px 40px rgba(0,0,0,0.4); }
        .mobile-menu.open { display: flex; }
        .mobile-menu a { display: block; padding: 0.75rem 1rem; color: rgba(253,250,246,0.7); text-decoration: none; font-size: 0.9rem; letter-spacing: 0.08em; text-transform: uppercase; border-bottom: 1px solid rgba(196,168,130,0.1); transition: color 0.2s; }
        .mobile-menu a:hover { color: var(--clay); }

        /* ══ HERO / PAGE HEADER ══ */
        .page-hero {
            margin-top: var(--nav-h);
            background: var(--espresso);
            padding: 3rem 2.5rem 2.5rem;
            position: relative; overflow: hidden;
        }
        .page-hero::before {
            content: 'LOCATIONS';
            position: absolute;
            font-family: 'Playfair Display', serif;
            font-size: clamp(50px, 12vw, 160px);
            font-weight: 700;
            color: rgba(196,168,130,0.05);
            top: 50%; left: 50%; transform: translate(-50%, -50%);
            white-space: nowrap; pointer-events: none;
        }
        .page-hero::after {
            content: '';
            position: absolute; inset: 0;
            background: repeating-linear-gradient(-55deg, transparent, transparent 30px, rgba(196,168,130,0.03) 30px, rgba(196,168,130,0.03) 31px);
            pointer-events: none;
        }
        .hero-inner {
            position: relative; z-index: 1;
            max-width: 1100px; margin: 0 auto;
            display: flex; align-items: flex-end; justify-content: space-between;
            gap: 2rem; flex-wrap: wrap;
        }
        .hero-text .tag { font-size: 0.68rem; letter-spacing: 0.3em; text-transform: uppercase; color: var(--clay); margin-bottom: 0.6rem; }
        .hero-text h1 { font-family: 'Playfair Display', serif; font-size: clamp(1.6rem, 3.5vw, 2.6rem); font-weight: 700; color: var(--white); line-height: 1.1; }
        .hero-text h1 em { font-style: italic; color: var(--clay); }
        .hero-text p { margin-top: 0.5rem; font-size: 0.88rem; color: rgba(253,250,246,0.4); font-weight: 300; max-width: 360px; }
        .hero-stat { text-align: right; }
        .hero-stat .num { font-family: 'Playfair Display', serif; font-size: 2.8rem; font-weight: 700; color: var(--clay); line-height: 1; }
        .hero-stat .lbl { font-size: 0.68rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(196,168,130,0.5); margin-top: 0.2rem; }

        /* ══ BREADCRUMB ══ */
        .breadcrumb { background: var(--white); border-bottom: 1px solid var(--sand); padding: 0.75rem 2.5rem; }
        .breadcrumb-inner { max-width: 1100px; margin: 0 auto; display: flex; align-items: center; gap: 0.5rem; font-size: 0.78rem; color: rgba(61,35,20,0.4); }
        .breadcrumb-inner a { color: var(--terra); text-decoration: none; }
        .breadcrumb-inner a:hover { text-decoration: underline; }
        .breadcrumb-inner .sep { color: rgba(61,35,20,0.2); }
        .breadcrumb-inner .current { color: var(--espresso); }

        /* ══ STATS STRIP ══ */
        .stats-strip { background: var(--white); border-bottom: 1px solid var(--sand); }
        .stats-inner { max-width: 1100px; margin: 0 auto; padding: 1rem 2.5rem; display: flex; gap: 1rem; flex-wrap: wrap; }
        .stat-pill { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.45rem 1rem; border-radius: 100px; border: 1.5px solid var(--sand); font-size: 0.78rem; color: rgba(61,35,20,0.6); background: var(--cream); }
        .stat-pill .sp-dot { width: 8px; height: 8px; border-radius: 50%; }
        .stat-pill .sp-num { font-weight: 500; color: var(--espresso); }
        .dot-pending  { background: #E67E22; }
        .dot-approved { background: var(--success); }
        .dot-returned { background: #3498DB; }
        .dot-rejected { background: var(--error); }

        /* ══ MAIN ══ */
        .main-wrap { max-width: 1100px; margin: 0 auto; padding: 2.5rem 2.5rem 5rem; }

        /* ══ EMPTY STATE ══ */
        .empty-state { text-align: center; padding: 5rem 2rem; }
        .empty-icon { font-size: 3.5rem; margin-bottom: 1.25rem; opacity: 0.5; }
        .empty-state h2 { font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 600; color: var(--espresso); margin-bottom: 0.75rem; }
        .empty-state p { font-size: 0.9rem; color: rgba(61,35,20,0.5); margin-bottom: 2rem; }
        .btn-shop {
            display: inline-flex; align-items: center; gap: 0.5rem;
            padding: 0.85rem 2rem; background: var(--espresso); color: var(--white);
            text-decoration: none; font-size: 0.82rem; letter-spacing: 0.12em; text-transform: uppercase;
            border-radius: 2px; font-weight: 500; position: relative; overflow: hidden; transition: transform 0.15s;
        }
        .btn-shop::before { content: ''; position: absolute; inset: 0; background: var(--terra); transform: translateX(-100%); transition: transform 0.35s ease; }
        .btn-shop:hover::before { transform: translateX(0); }
        .btn-shop:active { transform: scale(0.98); }
        .btn-shop span { position: relative; z-index: 1; }

        /* ══ RENTAL CARDS ══ */
        .rentals-list { display: flex; flex-direction: column; gap: 1.25rem; }

        .rental-card {
            background: var(--white);
            border: 1px solid var(--sand);
            border-radius: 2px;
            display: grid;
            grid-template-columns: 5px 90px 1fr auto;
            overflow: hidden;
            transition: box-shadow 0.25s, transform 0.25s;
            animation: fadeSlideUp 0.4s ease both;
        }
        .rental-card:hover { box-shadow: 0 8px 40px rgba(61,35,20,0.1); transform: translateY(-2px); }

        @keyframes fadeSlideUp {
            from { opacity: 0; transform: translateY(16px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .rental-card:nth-child(1) { animation-delay: 0.05s; }
        .rental-card:nth-child(2) { animation-delay: 0.10s; }
        .rental-card:nth-child(3) { animation-delay: 0.15s; }
        .rental-card:nth-child(4) { animation-delay: 0.20s; }
        .rental-card:nth-child(5) { animation-delay: 0.25s; }

        /* Left status strip */
        .card-strip { align-self: stretch; }
        .strip-pending  { background: #E67E22; }
        .strip-approved { background: var(--success); }
        .strip-returned { background: #3498DB; }
        .strip-rejected { background: var(--error); }

        /* Thumbnail */
        .card-thumb { width: 90px; background: var(--sand); overflow: hidden; display: flex; align-items: center; justify-content: center; }
        .card-thumb img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .card-thumb .no-img { font-size: 2rem; opacity: 0.3; }

        /* Card body */
        .card-body { padding: 1.25rem 1.5rem; display: flex; flex-direction: column; gap: 0.6rem; flex: 1; min-width: 0; }
        .card-top { display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .card-top h3 { font-family: 'Playfair Display', serif; font-size: 1.05rem; font-weight: 600; color: var(--espresso); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .cat-label { font-size: 0.62rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--clay); padding: 0.2rem 0.6rem; border: 1px solid rgba(196,168,130,0.4); border-radius: 2px; flex-shrink: 0; }

        .card-meta { display: flex; gap: 1.5rem; flex-wrap: wrap; }
        .meta-item { display: flex; align-items: center; gap: 0.4rem; font-size: 0.8rem; color: rgba(61,35,20,0.55); }
        .meta-item .mi-icon { font-size: 0.9rem; }
        .meta-item strong { color: var(--espresso); font-weight: 500; }

        /* Right panel */
        .card-right { padding: 1.25rem 1.5rem; display: flex; flex-direction: column; align-items: flex-end; justify-content: space-between; gap: 1rem; border-left: 1px solid var(--sand); min-width: 160px; }

        .price-block { text-align: right; }
        .price-block .amount { font-family: 'Playfair Display', serif; font-size: 1.3rem; font-weight: 700; color: var(--terra); line-height: 1; }
        .price-block .amount span { font-family: 'DM Sans', sans-serif; font-size: 0.72rem; font-weight: 400; color: var(--clay); }
        .price-block .days-label { font-size: 0.72rem; color: rgba(61,35,20,0.4); margin-top: 0.2rem; }

        /* Status badge */
        .status-badge { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.85rem; border-radius: 100px; font-size: 0.72rem; font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase; }
        .status-badge .bdot { width: 6px; height: 6px; border-radius: 50%; }
        .status-pending  { background: rgba(230,126,34,0.12);  color: #C05E00; }
        .status-pending  .bdot { background: #E67E22; }
        .status-approved { background: rgba(39,174,96,0.12);   color: #1E7B45; }
        .status-approved .bdot { background: var(--success); }
        .status-returned { background: rgba(52,152,219,0.12);  color: #1A6FA0; }
        .status-returned .bdot { background: #3498DB; }
        .status-rejected { background: rgba(192,57,43,0.1);    color: #9B2B1D; }
        .status-rejected .bdot { background: var(--error); }

        /* Cancel button */
        .btn-cancel {
            display: inline-flex; align-items: center; gap: 0.4rem;
            padding: 0.45rem 1rem; background: transparent; color: var(--error);
            border: 1.5px solid rgba(192,57,43,0.3); border-radius: 2px;
            font-family: 'DM Sans', sans-serif; font-size: 0.72rem;
            letter-spacing: 0.08em; text-transform: uppercase; text-decoration: none;
            transition: background 0.2s, border-color 0.2s;
        }
        .btn-cancel:hover { background: rgba(192,57,43,0.08); border-color: var(--error); }

        /* ══ FOOTER ══ */
        .site-footer { background: var(--espresso); text-align: center; padding: 2rem; color: rgba(196,168,130,0.5); font-size: 0.78rem; letter-spacing: 0.08em; }
        .site-footer .footer-brand { font-family: 'Playfair Display', serif; font-size: 1.2rem; font-weight: 700; color: var(--clay); margin-bottom: 0.35rem; }

        .btn-pay-now {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.5rem 1rem;
            background: var(--clay); color: var(--espresso);
            border-radius: 2px; text-decoration: none;
            font-size: 0.75rem; font-weight: 500;
            letter-spacing: 0.08em; text-transform: uppercase;
            transition: background 0.2s, color 0.2s; white-space: nowrap;
        }
        .btn-pay-now:hover { background: var(--terra); color: var(--white); }
        .pending-note {
            font-size: 0.72rem; color: rgba(61,35,20,0.45);
            background: #FEF9F0; border: 1px solid #F0D9B0;
            border-radius: 2px; padding: 0.5rem 0.75rem;
            margin-top: 0.4rem; line-height: 1.45; max-width: 220px; text-align: right;
        }
        /* ══ RESPONSIVE ══ */
        @media (max-width: 768px) {
            .nav-links { display: none; }
            .hamburger { display: flex; }
            .rental-card { grid-template-columns: 5px 70px 1fr; grid-template-rows: auto auto; }
            .card-right { grid-column: 2 / -1; flex-direction: row; align-items: center; justify-content: space-between; border-left: none; border-top: 1px solid var(--sand); padding: 1rem 1.25rem; min-width: unset; }
            .price-block { text-align: left; }
        }
        @media (max-width: 560px) {
            .navbar { padding: 0 1.25rem; }
            .page-hero { padding: 2rem 1.25rem; }
            .breadcrumb, .stats-inner { padding: 0.75rem 1.25rem; }
            .main-wrap { padding: 1.5rem 1.25rem 4rem; }
            .card-body { padding: 1rem 1.25rem; }
            .card-meta { gap: 0.75rem; }
        }
    </style>
</head>
<body>

<!-- NAVBAR -->
<nav class="navbar">
    <a href="index.php" class="nav-brand">
        <span class="sublabel">Location Vêtements</span>
        <span class="wordmark">Élara</span>
    </a>
    <div class="nav-links">
        <?php if ($userName): ?>
            <span class="nav-greeting">Bonjour, <?php echo htmlspecialchars($userName); ?></span>
        <?php endif; ?>
        <a href="index.php">Boutique</a>
        <a href="my_rentals.php" class="btn-primary">Mes locations</a>
        <a href="logout.php">Déconnexion</a>
    </div>
    <button class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</nav>

<!-- Mobile menu -->
<div class="mobile-menu" id="mobileMenu">
    <?php if ($userName): ?>
    <a style="color:var(--clay);font-style:italic;font-family:'Playfair Display',serif;text-transform:none;letter-spacing:0;pointer-events:none;">
        Bonjour, <?php echo htmlspecialchars($userName); ?>
    </a>
    <?php endif; ?>
    <a href="index.php">Boutique</a>
    <a href="my_rentals.php">Mes locations</a>
    <a href="logout.php">Déconnexion</a>
</div>

<!-- PAGE HERO -->
<div class="page-hero">
    <div class="hero-inner">
        <div class="hero-text">
            <p class="tag">Espace client</p>
            <h1>Mes <em>Locations</em></h1>
            <p>Suivez vos réservations en cours et passées.</p>
        </div>
        <?php if ($total > 0): ?>
        <div class="hero-stat">
            <div class="num"><?php echo $total; ?></div>
            <div class="lbl">Location<?php echo $total > 1 ? 's' : ''; ?> au total</div>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- BREADCRUMB -->
<div class="breadcrumb">
    <div class="breadcrumb-inner">
        <a href="index.php">Accueil</a>
        <span class="sep">›</span>
        <span class="current">Mes locations</span>
    </div>
</div>

<!-- STATS STRIP -->
<?php if ($total > 0): ?>
<div class="stats-strip">
    <div class="stats-inner">
        <div class="stat-pill">
            <span class="sp-dot dot-pending"></span>
            En attente <span class="sp-num"><?php echo $counts['pending']; ?></span>
        </div>
        <div class="stat-pill">
            <span class="sp-dot dot-approved"></span>
            Approuvées <span class="sp-num"><?php echo $counts['approved']; ?></span>
        </div>
        <div class="stat-pill">
            <span class="sp-dot dot-returned"></span>
            Retournées <span class="sp-num"><?php echo $counts['returned']; ?></span>
        </div>
        <div class="stat-pill">
            <span class="sp-dot dot-rejected"></span>
            Annulées <span class="sp-num"><?php echo $counts['rejected']; ?></span>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- MAIN -->
<main class="main-wrap">

    <?php if ($total === 0): ?>
    <div class="empty-state">
        <div class="empty-icon">🧺</div>
        <h2>Aucune location pour le moment</h2>
        <p>Parcourez notre collection et réservez votre première tenue.</p>
        <a href="index.php" class="btn-shop"><span>Découvrir la boutique →</span></a>
    </div>

    <?php else: ?>
    <div class="rentals-list">

    <?php
    $statusLabels = [
        'pending'  => 'En attente',
        'approved' => 'Approuvée',
        'returned' => 'Retournée',
        'rejected' => 'Annulée',
    ];

    foreach ($allRows as $r):
        $status = $r['status'];
        $label  = $statusLabels[$status] ?? ucfirst($status);

        $start = new DateTime($r['start_date']);
        $end   = new DateTime($r['end_date']);
        $days  = max(1, $start->diff($end)->days);

        $pricePerDay = round((int)$r['total_price'] / $days);

        $imgSrc = !empty($r['product_image'])
            ? 'uploads/' . htmlspecialchars($r['product_image'])
            : null;
    ?>

    <div class="rental-card">

        <!-- Status strip -->
        <div class="card-strip strip-<?php echo htmlspecialchars($status); ?>"></div>

        <!-- Thumbnail -->
        <div class="card-thumb">
            <?php if ($imgSrc): ?>
                <img src="<?php echo $imgSrc; ?>" alt="<?php echo htmlspecialchars($r['product_name']); ?>">
            <?php else: ?>
                <span class="no-img">👗</span>
            <?php endif; ?>
        </div>

        <!-- Body -->
        <div class="card-body">
            <div class="card-top">
                <h3><?php echo htmlspecialchars($r['product_name']); ?></h3>
                <?php if (!empty($r['product_category'])): ?>
                    <span class="cat-label"><?php echo htmlspecialchars($r['product_category']); ?></span>
                <?php endif; ?>
            </div>
            <div class="card-meta">
                <div class="meta-item">
                    <span class="mi-icon">📐</span>
                    Taille <strong><?php echo htmlspecialchars($r['size']); ?></strong>
                </div>
                <div class="meta-item">
                    <span class="mi-icon">📅</span>
                    Du <strong><?php echo date('d/m/Y', strtotime($r['start_date'])); ?></strong>
                    au <strong><?php echo date('d/m/Y', strtotime($r['end_date'])); ?></strong>
                </div>
                <div class="meta-item">
                    <span class="mi-icon">🕐</span>
                    <strong><?php echo $days; ?></strong>&nbsp;jour<?php echo $days > 1 ? 's' : ''; ?>
                </div>
            </div>
        </div>

        <!-- Right panel -->
        <div class="card-right">
            <div class="price-block">
                <div class="amount">
                    <?php echo number_format((int)$r['total_price'], 0, ',', ' '); ?>
                    <span>DA</span>
                </div>
                <div class="days-label"><?php echo $days; ?> j × <?php echo number_format($pricePerDay, 0, ',', ' '); ?> DA/j</div>
            </div>

            <div style="display:flex;flex-direction:column;align-items:flex-end;gap:0.6rem;">
                <div class="status-badge status-<?php echo htmlspecialchars($status); ?>">
                    <span class="bdot"></span>
                    <?php echo $label; ?>
                </div>

                <?php if ($status === 'pending'): ?>
                <a href="payment.php?rental_id=<?php echo (int)$r['id']; ?>" class="btn-pay-now">
                    💳 Payer
                </a>
                <form method="POST" onsubmit="return confirm('Confirmer l\'annulation de cette réservation ?')">
                    <input type="hidden" name="cancel" value="<?php echo (int)$r['id']; ?>">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <button type="submit" class="btn-cancel">✕ Annuler</button>
                </form>
                <div class="pending-note">⏳ En attente de validation admin</div>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <?php endforeach; ?>
    </div>
    <?php endif; ?>

</main>

<!-- FOOTER -->
<footer class="site-footer">
    <div class="footer-brand">Élara</div>
    <p>© <?php echo date('Y'); ?> Élara · Location de Vêtements · Tous droits réservés</p>
</footer>

<script>
const hbBtn = document.getElementById('hamburgerBtn');
const mMenu = document.getElementById('mobileMenu');
hbBtn.addEventListener('click', () => {
    hbBtn.classList.toggle('open');
    mMenu.classList.toggle('open');
});
document.addEventListener('click', e => {
    if (!hbBtn.contains(e.target) && !mMenu.contains(e.target)) {
        hbBtn.classList.remove('open');
        mMenu.classList.remove('open');
    }
});
</script>

</body>
</html>