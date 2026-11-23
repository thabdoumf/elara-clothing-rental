<?php
session_start();
include("db.php");
if (empty($_SESSION["csrf_token"])) { $_SESSION["csrf_token"] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION["csrf_token"];

/* Sanitize input */
$search   = $_GET['search']    ?? '';
$category = $_GET['category']  ?? '';
$gender   = $_GET['gender']    ?? '';
$sort     = $_GET['sort']      ?? 'newest';
$priceMin = $_GET['price_min'] ?? '';
$priceMax = $_GET['price_max'] ?? '';

$search   = $conn->real_escape_string($search);
$category = $conn->real_escape_string($category);
$gender   = $conn->real_escape_string($gender);

/* Build query */
$query = "SELECT * FROM products WHERE available = 1";
if ($search   !== '') $query .= " AND name LIKE '%$search%'";
if ($category !== '') $query .= " AND category='$category'";
if ($gender   !== '') $query .= " AND gender='$gender'";
if ($priceMin !== '' && is_numeric($priceMin)) $query .= " AND price_per_day >= " . (float)$priceMin;
if ($priceMax !== '' && is_numeric($priceMax)) $query .= " AND price_per_day <= " . (float)$priceMax;

if ($sort === 'price_asc')  $query .= " ORDER BY price_per_day ASC";
elseif ($sort === 'price_desc') $query .= " ORDER BY price_per_day DESC";
else $query .= " ORDER BY id DESC";

$products   = $conn->query($query);
$totalCount = $products->num_rows;
$userName   = $_SESSION['user_name'] ?? '';

/* Cart count for logged-in users */
$cartCount = 0;
if (isset($_SESSION['user_id'])) {
    $uid = (int)$_SESSION['user_id'];
    $cr  = $conn->query("SELECT COUNT(*) as cnt FROM cart WHERE user_id=$uid");
    if ($cr) $cartCount = (int)$cr->fetch_assoc()['cnt'];
}

/* IDs already in cart (to show "Déjà ajouté" state) */
$inCart = [];
if (isset($_SESSION['user_id'])) {
    $cr2 = $conn->query("SELECT product_id, size FROM cart WHERE user_id=$uid");
    while ($ci = $cr2->fetch_assoc()) $inCart[$ci['product_id']][] = $ci['size'];
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Élara — Location de Vêtements</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --cream: #F5F0E8; --sand: #E8DDD0; --clay: #C4A882;
            --terra: #8B5E3C; --espresso: #3D2314; --dark: #1A0F08;
            --white: #FDFAF6; --error: #C0392B; --nav-h: 72px;
        }
        html { scroll-behavior: smooth; }
        body { font-family: 'DM Sans', sans-serif; background: var(--cream); color: var(--espresso); min-height: 100vh; overflow-x: hidden; }

        /* NAVBAR */
        .navbar { position: fixed; top: 0; left: 0; right: 0; z-index: 100; height: var(--nav-h); background: var(--espresso); display: flex; align-items: center; justify-content: space-between; padding: 0 2.5rem; box-shadow: 0 2px 30px rgba(0,0,0,0.3); }
        .nav-brand { display: flex; flex-direction: column; text-decoration: none; }
        .nav-brand .sublabel { font-size: 0.58rem; letter-spacing: 0.28em; text-transform: uppercase; color: var(--clay); line-height: 1; }
        .nav-brand .wordmark { font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 700; color: var(--white); line-height: 1; }
        .nav-links { display: flex; align-items: center; gap: 0.25rem; }
        .nav-links a { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; font-size: 0.8rem; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(253,250,246,0.6); text-decoration: none; border-radius: 2px; transition: color 0.2s, background 0.2s; }
        .nav-links a:hover { color: var(--white); background: rgba(255,255,255,0.07); }
        .nav-links .nav-sep { width: 1px; height: 16px; background: rgba(196,168,130,0.25); margin: 0 0.25rem; }
        .nav-links .btn-logout { background: rgba(196,168,130,0.12); border: 1px solid rgba(196,168,130,0.25); color: var(--clay); }
        .nav-links .btn-logout:hover { background: rgba(196,168,130,0.22); }
        .nav-links .btn-primary { background: var(--clay); color: var(--espresso) !important; font-weight: 500; }
        .nav-links .btn-primary:hover { background: var(--terra); color: var(--white) !important; }
        .nav-greeting { font-size: 0.78rem; color: var(--clay); font-style: italic; font-family: 'Playfair Display', serif; padding: 0.5rem 0.75rem; }

        /* Hamburger */
        .hamburger { display: none; flex-direction: column; gap: 5px; background: none; border: none; cursor: pointer; padding: 4px; }
        .hamburger span { display: block; width: 24px; height: 2px; background: var(--clay); transition: all 0.3s; border-radius: 2px; }
        .hamburger.open span:nth-child(1) { transform: rotate(45deg) translate(5px, 5px); }
        .hamburger.open span:nth-child(2) { opacity: 0; }
        .hamburger.open span:nth-child(3) { transform: rotate(-45deg) translate(5px, -5px); }
        .mobile-menu { display: none; position: fixed; top: var(--nav-h); left: 0; right: 0; background: var(--dark); z-index: 99; padding: 1.5rem 2rem; flex-direction: column; gap: 0.25rem; box-shadow: 0 10px 40px rgba(0,0,0,0.4); }
        .mobile-menu.open { display: flex; }
        .mobile-menu a { display: block; padding: 0.75rem 1rem; color: rgba(253,250,246,0.7); text-decoration: none; font-size: 0.9rem; letter-spacing: 0.08em; text-transform: uppercase; border-bottom: 1px solid rgba(196,168,130,0.1); transition: color 0.2s; }
        .mobile-menu a:hover { color: var(--clay); }

        /* HERO */
        .hero { margin-top: var(--nav-h); background: var(--espresso); padding: 4rem 2.5rem 3.5rem; position: relative; overflow: hidden; }
        .hero::before { content: 'ÉLARA'; position: absolute; font-family: 'Playfair Display', serif; font-size: clamp(80px, 18vw, 220px); font-weight: 700; color: rgba(196,168,130,0.05); top: 50%; left: 50%; transform: translate(-50%,-50%); white-space: nowrap; pointer-events: none; }
        .hero::after { content: ''; position: absolute; inset: 0; background: repeating-linear-gradient(-55deg, transparent, transparent 30px, rgba(196,168,130,0.03) 30px, rgba(196,168,130,0.03) 31px); pointer-events: none; }
        .hero-inner { position: relative; z-index: 1; max-width: 1200px; margin: 0 auto; display: flex; align-items: flex-end; justify-content: space-between; gap: 2rem; flex-wrap: wrap; }
        .hero-text .tag { font-size: 0.68rem; letter-spacing: 0.3em; text-transform: uppercase; color: var(--clay); margin-bottom: 0.75rem; }
        .hero-text h1 { font-family: 'Playfair Display', serif; font-size: clamp(1.8rem,4vw,3rem); font-weight: 700; color: var(--white); line-height: 1.1; margin-bottom: 0.75rem; }
        .hero-text h1 em { font-style: italic; color: var(--clay); }
        .hero-text p { font-size: 0.9rem; color: rgba(253,250,246,0.45); font-weight: 300; max-width: 420px; }
        .hero-stat { text-align: right; }
        .hero-stat .num { font-family: 'Playfair Display', serif; font-size: 3rem; font-weight: 700; color: var(--clay); line-height: 1; }
        .hero-stat .lbl { font-size: 0.7rem; letter-spacing: 0.2em; text-transform: uppercase; color: rgba(196,168,130,0.5); margin-top: 0.25rem; }

        /* FILTER BAR */
        .filter-section { background: var(--white); border-bottom: 1px solid var(--sand); position: sticky; top: var(--nav-h); z-index: 50; box-shadow: 0 2px 20px rgba(61,35,20,0.06); }
        .filter-inner { max-width: 1200px; margin: 0 auto; padding: 1rem 2.5rem; display: flex; align-items: center; gap: 0.75rem; flex-wrap: wrap; }
        .search-wrap { position: relative; flex: 1; min-width: 200px; }
        .search-wrap .search-icon { position: absolute; left: 0.9rem; top: 50%; transform: translateY(-50%); color: var(--clay); font-size: 0.9rem; pointer-events: none; }
        .filter-inner input[type="text"], .filter-inner select { padding: 0.65rem 1rem; border: 1.5px solid var(--sand); border-radius: 2px; background: var(--cream); color: var(--espresso); font-family: 'DM Sans', sans-serif; font-size: 0.85rem; outline: none; transition: border-color 0.2s, box-shadow 0.2s; appearance: none; }
        .filter-inner input[type="text"] { width: 100%; padding-left: 2.4rem; }
        .filter-inner input:focus, .filter-inner select:focus { border-color: var(--terra); box-shadow: 0 0 0 3px rgba(139,94,60,0.08); background: var(--white); }
        .select-wrap { position: relative; min-width: 150px; }
        .select-wrap::after { content: '▾'; position: absolute; right: 0.8rem; top: 50%; transform: translateY(-50%); color: var(--clay); pointer-events: none; font-size: 0.8rem; }
        .select-wrap select { width: 100%; padding-right: 2rem; }

        /* Price range */
        .price-range-wrap { display: flex; align-items: center; gap: 0.4rem; }
        .price-range-wrap input[type="number"] { width: 90px; padding: 0.65rem 0.6rem; border: 1.5px solid var(--sand); border-radius: 2px; background: var(--cream); color: var(--espresso); font-family: 'DM Sans', sans-serif; font-size: 0.82rem; outline: none; transition: border-color 0.2s; -moz-appearance: textfield; }
        .price-range-wrap input[type="number"]::-webkit-outer-spin-button, .price-range-wrap input[type="number"]::-webkit-inner-spin-button { -webkit-appearance: none; }
        .price-range-wrap input:focus { border-color: var(--terra); }
        .price-range-wrap .sep { font-size: 0.75rem; color: var(--clay); }

        .btn-filter { padding: 0.65rem 1.5rem; background: var(--espresso); color: var(--white); border: none; border-radius: 2px; font-family: 'DM Sans', sans-serif; font-size: 0.8rem; font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; cursor: pointer; transition: background 0.2s; white-space: nowrap; }
        .btn-filter:hover { background: var(--terra); }
        .btn-reset { padding: 0.65rem 1rem; background: transparent; color: var(--clay); border: 1.5px solid var(--sand); border-radius: 2px; font-family: 'DM Sans', sans-serif; font-size: 0.8rem; cursor: pointer; text-decoration: none; display: inline-flex; align-items: center; gap: 0.3rem; transition: border-color 0.2s, color 0.2s; white-space: nowrap; }
        .btn-reset:hover { border-color: var(--terra); color: var(--terra); }

        /* Sort bar */
        .sort-bar { max-width: 1200px; margin: 0 auto; padding: 0.5rem 2.5rem 0.75rem; display: flex; align-items: center; gap: 0.5rem; flex-wrap: wrap; }
        .sort-bar span { font-size: 0.72rem; color: var(--clay); letter-spacing: 0.1em; text-transform: uppercase; }
        .sort-btn { padding: 0.3rem 0.9rem; border: 1.5px solid var(--sand); border-radius: 100px; background: transparent; color: rgba(61,35,20,0.5); font-family: 'DM Sans', sans-serif; font-size: 0.72rem; letter-spacing: 0.06em; text-transform: uppercase; text-decoration: none; transition: all 0.2s; }
        .sort-btn:hover, .sort-btn.active { background: var(--espresso); color: var(--clay); border-color: var(--espresso); }

        .active-filters { display: flex; gap: 0.5rem; flex-wrap: wrap; padding: 0 2.5rem 0.75rem; }
        .filter-tag { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.3rem 0.75rem; background: var(--espresso); color: var(--clay); border-radius: 100px; font-size: 0.72rem; letter-spacing: 0.06em; text-transform: uppercase; }

        /* MAIN */
        .main { max-width: 1200px; margin: 0 auto; padding: 2.5rem 2.5rem 5rem; }
        .results-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 2rem; }
        .results-header h2 { font-family: 'Playfair Display', serif; font-size: 1.4rem; font-weight: 600; }
        .results-header span { font-size: 0.78rem; color: var(--clay); letter-spacing: 0.08em; text-transform: uppercase; }

        /* GRID */
        .grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 1.75rem; }

        /* CARD */
        .card { background: var(--white); border-radius: 2px; overflow: hidden; box-shadow: 0 2px 8px rgba(61,35,20,0.06); transition: transform 0.3s ease, box-shadow 0.3s ease; display: flex; flex-direction: column; animation: cardIn 0.5s ease both; }
        @keyframes cardIn { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
        <?php for ($i = 1; $i <= 20; $i++) echo ".grid .card:nth-child($i){animation-delay:".($i*0.06)."s}\n"; ?>
        .card:hover { transform: translateY(-6px); box-shadow: 0 16px 48px rgba(61,35,20,0.14); }

        /* Card image — now an <a> */
        .card-img { position: relative; overflow: hidden; aspect-ratio: 3/4; background: var(--sand); display: block; text-decoration: none; }
        .card-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.6s ease; display: block; }
        .card:hover .card-img img { transform: scale(1.06); }

        /* Badges */
        .badge-wrap { position: absolute; top: 0.75rem; left: 0.75rem; display: flex; flex-direction: column; gap: 0.4rem; }
        .badge { display: inline-block; padding: 0.25rem 0.6rem; font-size: 0.62rem; font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; border-radius: 1px; }
        .badge-cat { background: var(--espresso); color: var(--clay); }
        .badge-low { background: #E67E22; color: var(--white); }
        .badge-out { background: var(--error); color: var(--white); }
        .badge-gender { background: rgba(253,250,246,0.9); color: var(--espresso); }

        /* Wishlist heart */
        .btn-wishlist { position: absolute; top: 0.75rem; right: 0.75rem; width: 36px; height: 36px; background: rgba(253,250,246,0.9); border: none; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1rem; transition: all 0.2s; backdrop-filter: blur(4px); z-index: 2; }
        .btn-wishlist:hover { transform: scale(1.15); background: var(--white); }
        .btn-wishlist.liked { background: #ffe4e4; }
        .btn-wishlist .heart { transition: transform 0.2s; display: block; }
        .btn-wishlist.liked .heart { transform: scale(1.2); }

        /* Overlay — a <span> inside an <a>, so the whole image is clickable */
        .card-overlay { position: absolute; inset: 0; background: rgba(26,15,8,0.45); display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity 0.3s; }
        .card:hover .card-overlay { opacity: 1; }
        .overlay-label { font-size: 0.72rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--white); border: 1px solid rgba(253,250,246,0.5); padding: 0.6rem 1.5rem; backdrop-filter: blur(4px); }

        /* Card body */
        .card-body { padding: 1.25rem 1.25rem 0; flex: 1; }
        .card-meta { display: flex; align-items: center; justify-content: space-between; margin-bottom: 0.4rem; }
        .card-cat-label { font-size: 0.62rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--clay); }
        .card-gender-label { font-size: 0.62rem; color: rgba(61,35,20,0.4); }
        .card-body h3 { font-family: 'Playfair Display', serif; font-size: 1.15rem; font-weight: 600; color: var(--espresso); margin-bottom: 0.4rem; line-height: 1.25; }
        .card-body .desc { font-size: 0.82rem; color: rgba(61,35,20,0.55); line-height: 1.5; margin-bottom: 0.75rem; font-weight: 300; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
        .card-price { font-family: 'Playfair Display', serif; font-size: 1.3rem; font-weight: 700; color: var(--terra); }

        /* Star ratings */
        .card-rating { display:flex; align-items:center; gap:0.4rem; margin-bottom:0.6rem; }
        .stars { display:flex; gap:1px; }
        .star-filled { color:#F4C542; font-size:0.8rem; line-height:1; }
        .star-empty  { color:var(--sand); font-size:0.8rem; line-height:1; }
        .rating-count { font-size:0.72rem; color:rgba(61,35,20,0.45); }
        .rating-avg { font-size:0.78rem; font-weight:500; color:var(--terra); }
        .card-price span { font-family: 'DM Sans', sans-serif; font-size: 0.72rem; font-weight: 400; color: var(--clay); margin-left: 0.2rem; }

        /* Card footer */
        .card-footer { padding: 1rem 1.25rem 1.25rem; border-top: 1px solid var(--sand); margin-top: 1rem; display: flex; flex-direction: column; gap: 0.6rem; }
        .card-footer form { display: flex; flex-direction: column; gap: 0.6rem; }
        .size-select-wrap { position: relative; }
        .size-select-wrap::after { content: '▾'; position: absolute; right: 0.7rem; top: 50%; transform: translateY(-50%); color: var(--clay); pointer-events: none; font-size: 0.75rem; }
        .card-footer select { width: 100%; padding: 0.6rem 1.8rem 0.6rem 0.8rem; border: 1.5px solid var(--sand); border-radius: 2px; background: var(--cream); color: var(--espresso); font-family: 'DM Sans', sans-serif; font-size: 0.82rem; outline: none; appearance: none; cursor: pointer; transition: border-color 0.2s; }
        .card-footer select:focus { border-color: var(--terra); }

        .btn-rent { width: 100%; padding: 0.75rem; background: var(--espresso); color: var(--white); border: none; border-radius: 2px; font-family: 'DM Sans', sans-serif; font-size: 0.8rem; font-weight: 500; letter-spacing: 0.12em; text-transform: uppercase; cursor: pointer; position: relative; overflow: hidden; transition: transform 0.15s; }
        .btn-rent::before { content: ''; position: absolute; inset: 0; background: var(--terra); transform: translateX(-100%); transition: transform 0.3s ease; }
        .btn-rent:hover::before { transform: translateX(0); }
        .btn-rent:active { transform: scale(0.98); }
        .btn-rent span { position: relative; z-index: 1; }
        .btn-rent:disabled { background: var(--sand); color: var(--clay); cursor: not-allowed; opacity: 0.7; }
        .btn-rent:disabled::before { display: none; }

        /* "Voir les détails" button in footer */
        .btn-details { display: block; width: 100%; padding: 0.65rem; background: transparent; border: 1.5px solid var(--sand); border-radius: 2px; color: var(--terra); text-align: center; text-decoration: none; font-family: 'DM Sans', sans-serif; font-size: 0.78rem; font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; transition: all 0.2s; }
        .btn-details:hover { background: var(--espresso); border-color: var(--espresso); color: var(--clay); }

        /* Add to cart button */
        .btn-cart { width: 100%; padding: 0.75rem; background: var(--clay); color: var(--espresso); border: none; border-radius: 2px; font-family: 'DM Sans', sans-serif; font-size: 0.8rem; font-weight: 500; letter-spacing: 0.12em; text-transform: uppercase; cursor: pointer; position: relative; overflow: hidden; transition: transform 0.15s; display: flex; align-items: center; justify-content: center; gap: 0.45rem; }
        .btn-cart::before { content: ''; position: absolute; inset: 0; background: var(--terra); transform: translateX(-100%); transition: transform 0.3s ease; }
        .btn-cart:hover::before { transform: translateX(0); }
        .btn-cart:hover { color: var(--white); }
        .btn-cart:active { transform: scale(0.98); }
        .btn-cart span { position: relative; z-index: 1; }
        .btn-cart.in-cart { background: var(--sand); color: rgba(61,35,20,0.45); cursor: default; }
        .btn-cart.in-cart::before { display: none; }

        /* Cart nav badge */
        .nav-cart { position: relative; display: inline-flex; align-items: center; gap: 0.4rem; }
        .cart-badge { position: absolute; top: -6px; right: -8px; width: 18px; height: 18px; background: var(--clay); color: var(--espresso); font-size: 0.62rem; font-weight: 700; border-radius: 50%; display: flex; align-items: center; justify-content: center; line-height: 1; }

        .btn-login-rent { display: block; width: 100%; padding: 0.75rem; background: transparent; border: 1.5px solid var(--clay); border-radius: 2px; color: var(--terra); text-decoration: none; font-family: 'DM Sans', sans-serif; font-size: 0.8rem; font-weight: 500; letter-spacing: 0.12em; text-transform: uppercase; text-align: center; transition: background 0.2s, color 0.2s, border-color 0.2s; }
        .btn-login-rent:hover { background: var(--espresso); border-color: var(--espresso); color: var(--white); }

        .out-of-stock-label { text-align: center; padding: 0.6rem; font-size: 0.75rem; letter-spacing: 0.1em; text-transform: uppercase; color: var(--error); background: #FCF0EF; border-radius: 2px; }
        .avail-date { text-align: center; padding: 0.45rem 0.75rem; font-size: 0.75rem; color: var(--terra); background: #FEF9F0; border: 1px solid #F0D9B0; border-radius: 2px; }

        .empty-state { grid-column: 1/-1; text-align: center; padding: 5rem 2rem; }
        .empty-state .empty-icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }
        .empty-state h3 { font-family: 'Playfair Display', serif; font-size: 1.5rem; color: var(--espresso); margin-bottom: 0.5rem; }
        .empty-state p { font-size: 0.9rem; color: rgba(61,35,20,0.45); }

        /* FOOTER */
        .site-footer { background: var(--espresso); padding: 2.5rem; text-align: center; }
        .site-footer .footer-brand { font-family: 'Playfair Display', serif; font-size: 1.5rem; color: var(--white); margin-bottom: 0.4rem; }
        .site-footer p { font-size: 0.75rem; color: rgba(196,168,130,0.4); letter-spacing: 0.1em; }

        /* TOAST */
        .toast { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%) translateY(20px); background: var(--espresso); color: var(--clay); padding: 0.75rem 1.5rem; border-radius: 2px; font-size: 0.8rem; letter-spacing: 0.08em; opacity: 0; transition: all 0.3s; pointer-events: none; z-index: 999; white-space: nowrap; border: 1px solid rgba(196,168,130,0.2); }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }
        .toast.toast-cart { background: var(--terra); color: var(--white); border-color: rgba(255,255,255,0.15); }

        /* RESPONSIVE */
        @media (max-width: 768px) {
            .navbar { padding: 0 1.25rem; }
            .nav-links { display: none; }
            .hamburger { display: flex; }
            .hero { padding: 2.5rem 1.25rem 2rem; }
            .hero::before { display: none; }
            .filter-inner { padding: 1rem 1.25rem; }
            .active-filters, .sort-bar { padding-left: 1.25rem; padding-right: 1.25rem; }
            .main { padding: 1.5rem 1.25rem 4rem; }
            .grid { grid-template-columns: repeat(auto-fill, minmax(260px, 1fr)); gap: 1.25rem; }
            .hero-stat { display: none; }
            .price-range-wrap input[type="number"] { width: 75px; }
        }
        @media (max-width: 480px) {
            .grid { grid-template-columns: 1fr; }
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
        <?php if (isset($_SESSION['user_id'])): ?>
            <?php if (!empty($userName)): ?>
            <span class="nav-greeting">Bonjour, <?php echo htmlspecialchars(explode(' ', $userName)[0]); ?> ✦</span>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'admin'): ?>
            <a href="dashboard.php">⚙ Dashboard</a>
            <div class="nav-sep"></div>
            <?php endif; ?>
            <?php if ($_SESSION['role'] === 'user'): ?>
            <a href="my_rentals.php">🧾 Mes locations</a>
            <div class="nav-sep"></div>
            <?php endif; ?>
            <a href="cart.php" class="nav-cart">
                🛒
                <?php if ($cartCount > 0): ?>
                <span class="cart-badge"><?php echo $cartCount; ?></span>
                <?php endif; ?>
            </a>
            <div class="nav-sep"></div>
            <a href="logout.php" class="btn-logout">Déconnexion</a>
        <?php else: ?>
            <a href="login.php">Se connecter</a>
            <div class="nav-sep"></div>
            <a href="register.php" class="btn-primary">Créer un compte</a>
        <?php endif; ?>
    </div>
    <button class="hamburger" id="hamburgerBtn" aria-label="Menu">
        <span></span><span></span><span></span>
    </button>
</nav>

<!-- MOBILE MENU -->
<div class="mobile-menu" id="mobileMenu">
    <?php if (isset($_SESSION['user_id'])): ?>
        <?php if (!empty($userName)): ?>
        <a style="color:var(--clay);font-style:italic;pointer-events:none;">Bonjour, <?php echo htmlspecialchars(explode(' ',$userName)[0]); ?></a>
        <?php endif; ?>
        <?php if ($_SESSION['role'] === 'admin'): ?><a href="dashboard.php">⚙ Dashboard</a><?php endif; ?>
        <?php if ($_SESSION['role'] === 'user'): ?><a href="my_rentals.php">🧾 Mes locations</a><?php endif; ?>
        <a href="cart.php">🛒 Mon panier<?php if ($cartCount > 0) echo " ($cartCount)"; ?></a>
        <a href="logout.php">Déconnexion</a>
    <?php else: ?>
        <a href="login.php">Se connecter</a>
        <a href="register.php">Créer un compte</a>
    <?php endif; ?>
</div>

<!-- HERO -->
<section class="hero">
    <div class="hero-inner">
        <div class="hero-text">
            <p class="tag">✦ Collection disponible</p>
            <h1>La mode à votre<br><em>portée</em></h1>
            <p>Louez les tenues de vos rêves pour chaque occasion. Elegance, style, et liberté.</p>
        </div>
        <div class="hero-stat">
            <div class="num"><?php echo $totalCount; ?></div>
            <div class="lbl">Articles<br>disponibles</div>
        </div>
    </div>
</section>

<!-- FILTER BAR -->
<div class="filter-section">
    <form method="GET" class="filter-inner">
        <input type="hidden" name="sort" value="<?php echo htmlspecialchars($sort); ?>">

        <div class="search-wrap">
            <span class="search-icon">🔍</span>
            <input type="text" name="search" placeholder="Rechercher un article..."
                   value="<?php echo htmlspecialchars($search); ?>">
        </div>

        <div class="select-wrap">
            <select name="category">
                <option value="">Toutes catégories</option>
                <?php
                $cats = $conn->query("SELECT DISTINCT category FROM products");
                while ($cat = $cats->fetch_assoc()):
                ?>
                <option value="<?php echo htmlspecialchars($cat['category']); ?>"
                    <?php if ($category === $cat['category']) echo 'selected'; ?>>
                    <?php echo htmlspecialchars($cat['category']); ?>
                </option>
                <?php endwhile; ?>
            </select>
        </div>

        <div class="select-wrap">
            <select name="gender">
                <option value="">Tout genre</option>
                <option value="Men"    <?php if ($gender==='Men')    echo 'selected'; ?>>Homme</option>
                <option value="Women"  <?php if ($gender==='Women')  echo 'selected'; ?>>Femme</option>
                <option value="Unisex" <?php if ($gender==='Unisex') echo 'selected'; ?>>Unisexe</option>
            </select>
        </div>

        <div class="price-range-wrap">
            <input type="number" name="price_min" placeholder="Min DA"
                   value="<?php echo htmlspecialchars($priceMin); ?>" min="0">
            <span class="sep">—</span>
            <input type="number" name="price_max" placeholder="Max DA"
                   value="<?php echo htmlspecialchars($priceMax); ?>" min="0">
        </div>

        <button type="submit" class="btn-filter">Filtrer</button>
        <?php if ($search || $category || $gender || $priceMin || $priceMax): ?>
        <a href="index.php" class="btn-reset">✕ Réinitialiser</a>
        <?php endif; ?>
    </form>

    <!-- Sort bar -->
    <div class="sort-bar">
        <span>Trier :</span>
        <?php
        $bp = array_filter(['search'=>$search,'category'=>$category,'gender'=>$gender,'price_min'=>$priceMin,'price_max'=>$priceMax]);
        foreach(['newest'=>'Nouveautés','price_asc'=>'Prix ↑','price_desc'=>'Prix ↓'] as $val=>$label):
            $p = array_merge($bp,['sort'=>$val]);
            $active = ($sort===$val) ? ' active' : '';
        ?>
        <a href="index.php?<?php echo http_build_query($p); ?>" class="sort-btn<?php echo $active; ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>

    <?php if ($search || $category || $gender || $priceMin || $priceMax): ?>
    <div class="active-filters">
        <?php if ($search):   ?><span class="filter-tag">🔍 <?php echo htmlspecialchars($search); ?></span><?php endif; ?>
        <?php if ($category): ?><span class="filter-tag">📁 <?php echo htmlspecialchars($category); ?></span><?php endif; ?>
        <?php if ($gender):   ?><span class="filter-tag">👤 <?php echo htmlspecialchars($gender); ?></span><?php endif; ?>
        <?php if ($priceMin): ?><span class="filter-tag">≥ <?php echo htmlspecialchars($priceMin); ?> DA</span><?php endif; ?>
        <?php if ($priceMax): ?><span class="filter-tag">≤ <?php echo htmlspecialchars($priceMax); ?> DA</span><?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<!-- PRODUCT GRID -->
<main class="main">
    <div class="results-header">
        <h2><?php echo ($search||$category||$gender||$priceMin||$priceMax) ? 'Résultats de recherche' : 'Notre Collection'; ?></h2>
        <span><?php echo $totalCount; ?> article<?php echo $totalCount!==1?'s':''; ?></span>
    </div>

    <div class="grid">
    <?php if ($totalCount === 0): ?>
        <div class="empty-state">
            <div class="empty-icon">👗</div>
            <h3>Aucun article trouvé</h3>
            <p>Essayez d'autres filtres ou <a href="index.php" style="color:var(--terra);">voir tous les articles</a>.</p>
        </div>
    <?php endif; ?>

    <?php
    $products->data_seek(0);
    $gmap = ['Men'=>'Homme','Women'=>'Femme','Unisex'=>'Unisexe'];
    while ($row = $products->fetch_assoc()):
        $pid = (int)$row['id'];
        $sizes = $conn->query("SELECT size, SUM(stock) as stock FROM product_sizes WHERE product_id=$pid GROUP BY size");
        $hasStock = false; $lowStock = false; $sizeRows = []; $allSizeRows = [];
        while ($s = $sizes->fetch_assoc()) {
            $allSizeRows[] = $s;
            if ($s['stock'] > 0) {
                $hasStock = true;
                if ($s['stock'] <= 2) $lowStock = true;
                $sizeRows[] = $s;
            }
        }

        /* For out-of-stock sizes, find the earliest return date from approved rentals */
        $availDates = [];
        if (!$hasStock || count($sizeRows) < count($allSizeRows)) {
            foreach ($allSizeRows as $s) {
                if ($s['stock'] <= 0) {
                    $sz = $conn->real_escape_string($s['size']);
                    $dr = $conn->query("SELECT MIN(end_date) as earliest FROM rentals WHERE product_id=$pid AND size='$sz' AND status='approved'");
                    if ($dr) {
                        $drow = $dr->fetch_assoc();
                        if (!empty($drow['earliest'])) $availDates[$s['size']] = $drow['earliest'];
                    }
                }
            }
        }
        $nextAvail = !empty($availDates) ? min($availDates) : null;

        /* Rating stats */
        $ratingRes = $conn->query("SELECT AVG(rating) as avg_r, COUNT(*) as total_r FROM reviews WHERE product_id=$pid");
        $ratingRow = $ratingRes ? $ratingRes->fetch_assoc() : ['avg_r'=>0,'total_r'=>0];
        $avgRating   = round((float)$ratingRow['avg_r'], 1);
        $totalRating = (int)$ratingRow['total_r'];
    ?>
    <div class="card" data-id="<?php echo $pid; ?>">

        <!-- Image — THE FIX: entire image area is now a link to product.php -->
        <a href="product.php?id=<?php echo $pid; ?>" class="card-img">
            <img src="uploads/<?php echo htmlspecialchars($row['image']); ?>"
                 alt="<?php echo htmlspecialchars($row['name']); ?>" loading="lazy">

            <div class="badge-wrap">
                <?php if (!empty($row['category'])): ?><span class="badge badge-cat"><?php echo htmlspecialchars($row['category']); ?></span><?php endif; ?>
                <?php if (!$hasStock): ?><span class="badge badge-out">Épuisé</span>
                <?php elseif ($lowStock): ?><span class="badge badge-low">Stock faible</span><?php endif; ?>
                <?php if (!empty($row['gender'])): ?><span class="badge badge-gender"><?php echo $gmap[$row['gender']] ?? htmlspecialchars($row['gender']); ?></span><?php endif; ?>
            </div>

            <!-- Wishlist heart — preventDefault so it doesn't navigate -->
            <button class="btn-wishlist" data-id="<?php echo $pid; ?>"
                    onclick="event.preventDefault(); event.stopPropagation(); toggleWishlist(<?php echo $pid; ?>, this)"
                    title="Ajouter aux favoris">
                <span class="heart">🤍</span>
            </button>

            <!-- Overlay (purely visual, the <a> handles the click) -->
            <span class="card-overlay">
                <span class="overlay-label">Voir les détails →</span>
            </span>
        </a>

        <!-- Body -->
        <div class="card-body">
            <div class="card-meta">
                <span class="card-cat-label"><?php echo htmlspecialchars($row['category'] ?? ''); ?></span>
                <span class="card-gender-label"><?php echo $gmap[$row['gender']] ?? ''; ?></span>
            </div>
            <h3><?php echo htmlspecialchars($row['name']); ?></h3>
            <p class="desc"><?php echo htmlspecialchars($row['description']); ?></p>
            <?php if ($totalRating > 0): ?>
            <div class="card-rating">
                <div class="stars">
                    <?php for ($si=1;$si<=5;$si++): ?>
                    <span class="<?php echo $si <= round($avgRating) ? 'star-filled' : 'star-empty'; ?>">★</span>
                    <?php endfor; ?>
                </div>
                <span class="rating-avg"><?php echo number_format($avgRating,1); ?></span>
                <span class="rating-count">(<?php echo $totalRating; ?> avis)</span>
            </div>
            <?php else: ?>
            <div class="card-rating">
                <span class="rating-count" style="font-style:italic;">Aucun avis pour le moment</span>
            </div>
            <?php endif; ?>
            <div class="card-price">
                <?php echo number_format($row['price_per_day'], 0, ',', ' '); ?>
                <span>DA / jour</span>
            </div>
        </div>

        <!-- Footer -->
        <div class="card-footer">
            <?php if (!$hasStock): ?>
                <div class="out-of-stock-label">⚠ Article épuisé</div>
                <?php if ($nextAvail): ?>
                <div class="avail-date">
                    📅 Disponible à partir du <strong><?php echo date('d/m/Y', strtotime($nextAvail)); ?></strong>
                </div>
                <?php endif; ?>
                <button class="btn-rent" disabled><span>Indisponible</span></button>
            <?php else: ?>
                <form action="add_cart.php" method="POST" class="cart-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
                    <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
                    <div class="size-select-wrap">
                        <select name="size" class="size-sel" data-pid="<?php echo $pid; ?>">
                            <?php foreach ($sizeRows as $s): ?>
                            <option value="<?php echo htmlspecialchars($s['size']); ?>">
                                Taille <?php echo htmlspecialchars($s['size']); ?> — <?php echo $s['stock']; ?> dispo<?php if($s['stock']<=2) echo ' ⚠'; ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if (!isset($_SESSION['user_id'])): ?>
                        <a href="login.php" class="btn-login-rent">🔑 Connectez-vous pour louer</a>
                    <?php else: ?>
                        <?php
                        /* Check if first size is already in cart */
                        $firstSize = $sizeRows[0]['size'] ?? '';
                        $alreadyInCart = isset($inCart[$pid]) && in_array($firstSize, $inCart[$pid]);
                        ?>
                        <button type="submit" class="btn-cart <?php echo $alreadyInCart ? 'in-cart' : ''; ?>"
                                <?php echo $alreadyInCart ? 'disabled' : ''; ?>>
                            <span><?php echo $alreadyInCart ? '✓ Déjà dans le panier' : '🛒 Ajouter au panier'; ?></span>
                        </button>
                        <form action="rent.php" method="POST" style="margin-top:0;">
                            <input type="hidden" name="product_id" value="<?php echo $pid; ?>">
                            <input type="hidden" name="size" value="<?php echo htmlspecialchars($firstSize); ?>" class="rent-size-hidden">
                            <button type="submit" class="btn-rent"><span>Louer maintenant</span></button>
                        </form>
                    <?php endif; ?>
                </form>
            <?php endif; ?>
            <!-- Always-visible "Voir les détails" button -->
            <a href="product.php?id=<?php echo $pid; ?>" class="btn-details">Voir les détails</a>
        </div>

    </div>
    <?php endwhile; ?>
    </div>
</main>

<!-- FOOTER -->
<footer class="site-footer">
    <div class="footer-brand">Élara</div>
    <p>© <?php echo date('Y'); ?> Élara · Location de Vêtements · Tous droits réservés</p>
</footer>

<div class="toast" id="toast"></div>

<script>
// Wishlist
const WL_KEY = 'elara_wishlist';
const getWL  = () => { try { return JSON.parse(localStorage.getItem(WL_KEY)) || []; } catch(e) { return []; } };
const saveWL = (l) => localStorage.setItem(WL_KEY, JSON.stringify(l));

function toggleWishlist(id, btn) {
    let list = getWL();
    const idx = list.indexOf(id);
    const heart = btn.querySelector('.heart');
    if (idx === -1) {
        list.push(id); heart.textContent = '❤️'; btn.classList.add('liked');
        showToast('Ajouté aux favoris ❤️');
    } else {
        list.splice(idx,1); heart.textContent = '🤍'; btn.classList.remove('liked');
        showToast('Retiré des favoris');
    }
    saveWL(list);
}

(function applyWL() {
    const list = getWL();
    document.querySelectorAll('.btn-wishlist').forEach(btn => {
        if (list.includes(parseInt(btn.dataset.id))) {
            btn.querySelector('.heart').textContent = '❤️';
            btn.classList.add('liked');
        }
    });
})();

// Toast
let _tt;
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast show' + (type === 'cart' ? ' toast-cart' : '');
    clearTimeout(_tt); _tt = setTimeout(() => t.classList.remove('show'), 2800);
}

// Show "added to cart" toast if redirected back with ?added=1
<?php if (isset($_GET['added']) && $_GET['added'] === '1'): ?>
window.addEventListener('DOMContentLoaded', () => showToast('🛒 Article ajouté au panier !', 'cart'));
<?php endif; ?>

// Sync size selector → hidden rent size input
document.querySelectorAll('.size-sel').forEach(sel => {
    const card = sel.closest('.card-footer');
    sel.addEventListener('change', () => {
        const hiddenRentSize = card?.querySelector('.rent-size-hidden');
        if (hiddenRentSize) hiddenRentSize.value = sel.value;
    });
});

// Mobile hamburger
const hbBtn = document.getElementById('hamburgerBtn');
const mMenu = document.getElementById('mobileMenu');
hbBtn.addEventListener('click', () => {
    hbBtn.classList.toggle('open'); mMenu.classList.toggle('open');
});
document.addEventListener('click', e => {
    if (!hbBtn.contains(e.target) && !mMenu.contains(e.target)) {
        hbBtn.classList.remove('open'); mMenu.classList.remove('open');
    }
});
</script>
</body>
</html>