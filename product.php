<?php
session_start();
include("db.php");

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { header("Location: index.php"); exit; }

$result = $conn->query("SELECT * FROM products WHERE id=$id LIMIT 1");
if (!$result || $result->num_rows === 0) { header("Location: index.php"); exit; }

$p    = $result->fetch_assoc();
$gmap = ['Men'=>'Homme','Women'=>'Femme','Unisex'=>'Unisexe'];

// Sizes + availability dates for out-of-stock sizes
$sizes    = $conn->query("SELECT size, SUM(stock) as stock FROM product_sizes WHERE product_id=$id GROUP BY size ORDER BY size");
$sizeRows = []; $hasStock = false; $lowStock = false;

while ($s = $sizes->fetch_assoc()) {
    if ($s['stock'] > 0) {
        $hasStock = true;
        if ($s['stock'] <= 2) $lowStock = true;
        $s['avail_date'] = null;
    } else {
        // Find earliest end_date from approved rentals for this size
        $sz = $conn->real_escape_string($s['size']);
        $dr = $conn->query("SELECT MIN(end_date) as earliest FROM rentals WHERE product_id=$id AND size='$sz' AND status='approved'");
        $s['avail_date'] = ($dr && $row = $dr->fetch_assoc()) ? $row['earliest'] : null;
    }
    $sizeRows[] = $s;
}

// Related products
$related = $conn->query("SELECT * FROM products WHERE category='" . $conn->real_escape_string($p['category']) . "' AND id!=$id LIMIT 4");
$userName = $_SESSION['user_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($p['name']); ?> — Élara</title>
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
        body { font-family: 'DM Sans', sans-serif; background: var(--cream); color: var(--espresso); min-height: 100vh; }

        .navbar { position: fixed; top: 0; left: 0; right: 0; z-index: 100; height: var(--nav-h); background: var(--espresso); display: flex; align-items: center; justify-content: space-between; padding: 0 2.5rem; box-shadow: 0 2px 30px rgba(0,0,0,0.3); }
        .nav-brand { display: flex; flex-direction: column; text-decoration: none; }
        .nav-brand .sublabel { font-size: 0.58rem; letter-spacing: 0.28em; text-transform: uppercase; color: var(--clay); line-height: 1; }
        .nav-brand .wordmark { font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 700; color: var(--white); line-height: 1; }
        .nav-links { display: flex; align-items: center; gap: 0.25rem; }
        .nav-links a { display: inline-flex; align-items: center; gap: 0.4rem; padding: 0.5rem 1rem; font-size: 0.8rem; letter-spacing: 0.06em; text-transform: uppercase; color: rgba(253,250,246,0.6); text-decoration: none; border-radius: 2px; transition: color 0.2s, background 0.2s; }
        .nav-links a:hover { color: var(--white); background: rgba(255,255,255,0.07); }
        .nav-links .nav-sep { width: 1px; height: 16px; background: rgba(196,168,130,0.25); margin: 0 0.25rem; }
        .nav-links .btn-logout { background: rgba(196,168,130,0.12); border: 1px solid rgba(196,168,130,0.25); color: var(--clay); }
        .nav-links .btn-primary { background: var(--clay); color: var(--espresso) !important; font-weight: 500; }
        .nav-links .btn-primary:hover { background: var(--terra); color: var(--white) !important; }

        .breadcrumb { margin-top: var(--nav-h); background: var(--white); border-bottom: 1px solid var(--sand); padding: 0.75rem 2.5rem; }
        .breadcrumb-inner { max-width: 1200px; margin: 0 auto; display: flex; align-items: center; gap: 0.5rem; font-size: 0.78rem; color: rgba(61,35,20,0.45); }
        .breadcrumb-inner a { color: var(--terra); text-decoration: none; }
        .breadcrumb-inner a:hover { text-decoration: underline; }
        .breadcrumb-inner .sep { color: rgba(61,35,20,0.25); }
        .breadcrumb-inner .current { color: var(--espresso); }

        .detail-wrap { max-width: 1200px; margin: 0 auto; padding: 3rem 2.5rem; display: grid; grid-template-columns: 1fr 1fr; gap: 4rem; align-items: start; }

        .detail-gallery { position: sticky; top: calc(var(--nav-h) + 1rem); }
        .main-image { aspect-ratio: 3/4; background: var(--sand); border-radius: 2px; overflow: hidden; position: relative; }
        .main-image img { width: 100%; height: 100%; object-fit: cover; display: block; animation: fadeIn 0.4s ease; }
        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }

        .gallery-badge-wrap { position: absolute; top: 1rem; left: 1rem; display: flex; flex-direction: column; gap: 0.4rem; }
        .badge { display: inline-block; padding: 0.3rem 0.7rem; font-size: 0.65rem; font-weight: 500; letter-spacing: 0.1em; text-transform: uppercase; border-radius: 1px; }
        .badge-cat { background: var(--espresso); color: var(--clay); }
        .badge-low { background: #E67E22; color: var(--white); }
        .badge-out { background: var(--error); color: var(--white); }
        .badge-gender { background: rgba(253,250,246,0.9); color: var(--espresso); }

        .btn-wishlist-lg { position: absolute; top: 1rem; right: 1rem; width: 44px; height: 44px; background: rgba(253,250,246,0.9); border: none; border-radius: 50%; cursor: pointer; display: flex; align-items: center; justify-content: center; font-size: 1.2rem; transition: all 0.2s; backdrop-filter: blur(4px); }
        .btn-wishlist-lg:hover { transform: scale(1.1); background: var(--white); }
        .btn-wishlist-lg.liked { background: #ffe4e4; }

        .detail-info { padding-top: 0.5rem; }
        .detail-tag { font-size: 0.65rem; letter-spacing: 0.3em; text-transform: uppercase; color: var(--clay); margin-bottom: 0.75rem; }
        .detail-name { font-family: 'Playfair Display', serif; font-size: clamp(1.6rem,3vw,2.2rem); font-weight: 700; color: var(--espresso); line-height: 1.1; margin-bottom: 1rem; }
        .detail-price { font-family: 'Playfair Display', serif; font-size: 2rem; font-weight: 700; color: var(--terra); margin-bottom: 0.25rem; }
        .detail-price span { font-family: 'DM Sans', sans-serif; font-size: 0.9rem; font-weight: 400; color: var(--clay); margin-left: 0.3rem; }
        .detail-divider { height: 1px; background: var(--sand); margin: 1.5rem 0; }
        .detail-desc { font-size: 0.95rem; color: rgba(61,35,20,0.65); line-height: 1.7; font-weight: 300; margin-bottom: 1.5rem; }

        .detail-attrs { display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem; margin-bottom: 1.5rem; }
        .attr-item { background: var(--white); border: 1px solid var(--sand); border-radius: 2px; padding: 0.75rem 1rem; }
        .attr-label { font-size: 0.6rem; letter-spacing: 0.2em; text-transform: uppercase; color: var(--clay); margin-bottom: 0.25rem; }
        .attr-value { font-size: 0.88rem; font-weight: 500; color: var(--espresso); }

        /* SIZE PICKER */
        .size-label { font-size: 0.72rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--espresso); margin-bottom: 0.75rem; font-weight: 500; }
        .size-grid { display: flex; flex-wrap: wrap; gap: 0.75rem; margin-bottom: 0.75rem; align-items: flex-start; }

        .size-option { display: flex; flex-direction: column; align-items: center; gap: 0.35rem; }
        .size-option input[type="radio"] { position: absolute; opacity: 0; width: 0; height: 0; }

        /* In-stock label */
        .size-option label {
            display: flex; flex-direction: column; align-items: center; justify-content: center;
            min-width: 68px; padding: 0.65rem 0.75rem;
            border: 1.5px solid var(--sand); border-radius: 2px;
            cursor: pointer; transition: all 0.2s;
            font-size: 0.85rem; font-weight: 600; color: var(--espresso); text-align: center;
        }
        .size-option label .stock-hint { font-size: 0.58rem; color: var(--clay); margin-top: 0.2rem; font-weight: 400; }
        .size-option input:checked + label { border-color: var(--espresso); background: var(--espresso); color: var(--white); }
        .size-option input:checked + label .stock-hint { color: var(--clay); }
        .size-option label:hover { border-color: var(--terra); }

        /* Out-of-stock label */
        .size-option.out-of-stock label {
            cursor: not-allowed; border-color: rgba(192,57,43,0.2);
            background: #FCF0EF; color: rgba(61,35,20,0.3);
            text-decoration: line-through;
        }
        .size-option.out-of-stock label::after {
            content: 'Épuisé'; display: block;
            font-size: 0.5rem; letter-spacing: 0.08em; text-transform: uppercase;
            color: var(--error); text-decoration: none; margin-top: 0.15rem; font-weight: 400;
        }

        /* Availability date chip under a size button */
        .avail-chip {
            font-size: 0.58rem; color: var(--terra);
            background: #FEF9F0; border: 1px solid #F0D9B0; border-radius: 2px;
            padding: 0.2rem 0.45rem; text-align: center; line-height: 1.4;
            max-width: 80px; white-space: nowrap;
        }
        .avail-chip .ac-label { color: rgba(139,94,60,0.6); text-transform: uppercase; letter-spacing: 0.05em; font-size: 0.52rem; }
        .avail-chip .ac-date { font-weight: 600; font-size: 0.62rem; }

        /* Full product out-of-stock banner */
        .out-of-stock-msg { text-align: center; padding: 1rem; background: #FCF0EF; border-radius: 2px; color: var(--error); font-size: 0.82rem; letter-spacing: 0.08em; margin-bottom: 0.75rem; }
        .avail-box { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.9rem 1rem; background: #FEF9F0; border: 1px solid #F0D9B0; border-radius: 2px; font-size: 0.82rem; color: var(--terra); line-height: 1.5; margin-bottom: 1rem; }
        .avail-box .ab-icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 0.05rem; }

        .rent-form { display: flex; flex-direction: column; gap: 0.75rem; }
        .btn-rent-lg { width: 100%; padding: 1rem; background: var(--espresso); color: var(--white); border: none; border-radius: 2px; font-family: 'DM Sans', sans-serif; font-size: 0.88rem; font-weight: 500; letter-spacing: 0.15em; text-transform: uppercase; cursor: pointer; position: relative; overflow: hidden; transition: transform 0.15s; }
        .btn-rent-lg::before { content: ''; position: absolute; inset: 0; background: var(--terra); transform: translateX(-100%); transition: transform 0.35s ease; }
        .btn-rent-lg:hover::before { transform: translateX(0); }
        .btn-rent-lg span { position: relative; z-index: 1; }
        .btn-rent-lg:disabled { background: var(--sand); color: var(--clay); cursor: not-allowed; }
        .btn-rent-lg:disabled::before { display: none; }
        .btn-login-lg { display: block; width: 100%; padding: 1rem; background: transparent; border: 1.5px solid var(--clay); border-radius: 2px; color: var(--terra); text-decoration: none; font-size: 0.88rem; font-weight: 500; letter-spacing: 0.15em; text-transform: uppercase; text-align: center; transition: all 0.2s; }
        .btn-login-lg:hover { background: var(--espresso); border-color: var(--espresso); color: var(--white); }

        .rental-note { display: flex; align-items: flex-start; gap: 0.75rem; padding: 1rem; background: var(--white); border: 1px solid var(--sand); border-radius: 2px; font-size: 0.8rem; color: rgba(61,35,20,0.55); line-height: 1.5; }
        .rental-note .icon { font-size: 1.1rem; flex-shrink: 0; margin-top: 0.05rem; }

        .related-section { max-width: 1200px; margin: 0 auto; padding: 0 2.5rem 5rem; }
        .related-title { font-family: 'Playfair Display', serif; font-size: 1.3rem; font-weight: 600; margin-bottom: 1.5rem; }
        .related-grid { display: grid; grid-template-columns: repeat(auto-fill,minmax(240px,1fr)); gap: 1.25rem; }
        .rel-card { background: var(--white); border-radius: 2px; overflow: hidden; box-shadow: 0 2px 8px rgba(61,35,20,0.06); transition: transform 0.3s, box-shadow 0.3s; text-decoration: none; display: block; }
        .rel-card:hover { transform: translateY(-4px); box-shadow: 0 12px 32px rgba(61,35,20,0.12); }
        .rel-img { aspect-ratio: 3/4; background: var(--sand); overflow: hidden; }
        .rel-img img { width: 100%; height: 100%; object-fit: cover; transition: transform 0.5s; display: block; }
        .rel-card:hover .rel-img img { transform: scale(1.05); }
        .rel-body { padding: 1rem; }
        .rel-name { font-family: 'Playfair Display', serif; font-size: 1rem; color: var(--espresso); margin-bottom: 0.25rem; }
        .rel-price { font-size: 0.85rem; color: var(--terra); font-weight: 500; }

        .site-footer { background: var(--espresso); padding: 2.5rem; text-align: center; }
        .site-footer .footer-brand { font-family: 'Playfair Display', serif; font-size: 1.5rem; color: var(--white); margin-bottom: 0.4rem; }
        .site-footer p { font-size: 0.75rem; color: rgba(196,168,130,0.4); letter-spacing: 0.1em; }

        .toast { position: fixed; bottom: 2rem; left: 50%; transform: translateX(-50%) translateY(20px); background: var(--espresso); color: var(--clay); padding: 0.75rem 1.5rem; border-radius: 2px; font-size: 0.8rem; opacity: 0; transition: all 0.3s; pointer-events: none; z-index: 999; white-space: nowrap; border: 1px solid rgba(196,168,130,0.2); }
        .toast.show { opacity: 1; transform: translateX(-50%) translateY(0); }

        @media (max-width: 900px) { .detail-wrap { grid-template-columns: 1fr; gap: 2rem; } .detail-gallery { position: static; } }
        @media (max-width: 600px) { .detail-wrap { padding: 1.5rem 1.25rem; } .breadcrumb { padding: 0.75rem 1.25rem; } .related-section { padding: 0 1.25rem 3rem; } .navbar { padding: 0 1.25rem; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="index.php" class="nav-brand">
        <span class="sublabel">Location Vêtements</span>
        <span class="wordmark">Élara</span>
    </a>
    <div class="nav-links">
        <?php if (isset($_SESSION['user_id'])): ?>
            <?php if ($_SESSION['role'] === 'admin'): ?><a href="dashboard.php">⚙ Dashboard</a><div class="nav-sep"></div><?php endif; ?>
            <?php if ($_SESSION['role'] === 'user'): ?><a href="my_rentals.php">🧾 Mes locations</a><div class="nav-sep"></div><?php endif; ?>
            <a href="logout.php" class="btn-logout">Déconnexion</a>
        <?php else: ?>
            <a href="login.php">Se connecter</a>
            <div class="nav-sep"></div>
            <a href="register.php" class="btn-primary">Créer un compte</a>
        <?php endif; ?>
    </div>
</nav>

<div class="breadcrumb">
    <div class="breadcrumb-inner">
        <a href="index.php">Accueil</a>
        <span class="sep">›</span>
        <a href="index.php?category=<?php echo urlencode($p['category']); ?>"><?php echo htmlspecialchars($p['category']); ?></a>
        <span class="sep">›</span>
        <span class="current"><?php echo htmlspecialchars($p['name']); ?></span>
    </div>
</div>

<div class="detail-wrap">

    <div class="detail-gallery">
        <div class="main-image">
            <img src="uploads/<?php echo htmlspecialchars($p['image']); ?>"
                 alt="<?php echo htmlspecialchars($p['name']); ?>">
            <div class="gallery-badge-wrap">
                <?php if (!empty($p['category'])): ?><span class="badge badge-cat"><?php echo htmlspecialchars($p['category']); ?></span><?php endif; ?>
                <?php if (!$hasStock): ?><span class="badge badge-out">Épuisé</span>
                <?php elseif ($lowStock): ?><span class="badge badge-low">Stock faible</span><?php endif; ?>
                <?php if (!empty($p['gender'])): ?><span class="badge badge-gender"><?php echo $gmap[$p['gender']] ?? htmlspecialchars($p['gender']); ?></span><?php endif; ?>
            </div>
            <button class="btn-wishlist-lg" id="wishlistBtn"
                    onclick="toggleWishlist(<?php echo (int)$p['id']; ?>, this)"
                    title="Ajouter aux favoris">🤍</button>
        </div>
    </div>

    <div class="detail-info">
        <p class="detail-tag">✦ <?php echo htmlspecialchars($p['category']); ?></p>
        <h1 class="detail-name"><?php echo htmlspecialchars($p['name']); ?></h1>
        <div class="detail-price"><?php echo number_format($p['price_per_day'], 0, ',', ' '); ?><span>DA / jour</span></div>
        <div class="detail-divider"></div>
        <p class="detail-desc"><?php echo nl2br(htmlspecialchars($p['description'])); ?></p>

        <div class="detail-attrs">
            <?php if (!empty($p['gender'])): ?>
            <div class="attr-item"><div class="attr-label">Genre</div><div class="attr-value"><?php echo $gmap[$p['gender']] ?? htmlspecialchars($p['gender']); ?></div></div>
            <?php endif; ?>
            <?php if (!empty($p['category'])): ?>
            <div class="attr-item"><div class="attr-label">Catégorie</div><div class="attr-value"><?php echo htmlspecialchars($p['category']); ?></div></div>
            <?php endif; ?>
            <div class="attr-item"><div class="attr-label">Disponibilité</div><div class="attr-value"><?php echo $hasStock ? '✓ En stock' : '✗ Épuisé'; ?></div></div>
            <div class="attr-item"><div class="attr-label">Tailles dispo</div><div class="attr-value"><?php echo count(array_filter($sizeRows, fn($s)=>$s['stock']>0)); ?> taille(s)</div></div>
        </div>

        <div class="detail-divider"></div>

        <?php
        $allAvailDates   = array_filter(array_column($sizeRows, 'avail_date'));
        $earliestOverall = !empty($allAvailDates) ? min($allAvailDates) : null;
        $firstAvailIdx   = -1;
        foreach ($sizeRows as $i => $s) { if ($s['stock'] > 0 && $firstAvailIdx === -1) $firstAvailIdx = $i; }
        ?>

        <?php if (!$hasStock): ?>
            <div class="out-of-stock-msg">⚠ Cet article est actuellement épuisé</div>
            <?php if ($earliestOverall): ?>
            <div class="avail-box">
                <span class="ab-icon">📅</span>
                <span>La prochaine disponibilité est prévue à partir du <strong><?php echo date('d/m/Y', strtotime($earliestOverall)); ?></strong>. Revenez à cette date pour louer cet article.</span>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <p class="size-label">Choisir une taille</p>
            <form action="rent.php" method="POST" class="rent-form">
                <input type="hidden" name="product_id" value="<?php echo (int)$p['id']; ?>">
                <div class="size-grid">
                    <?php foreach ($sizeRows as $idx => $s): $oos = $s['stock'] <= 0; ?>
                    <div class="size-option <?php echo $oos ? 'out-of-stock' : ''; ?>">
                        <input type="radio" name="size"
                               id="sz_<?php echo htmlspecialchars($s['size']); ?>"
                               value="<?php echo htmlspecialchars($s['size']); ?>"
                               <?php if ($idx === $firstAvailIdx) echo 'checked'; ?>
                               <?php if ($oos) echo 'disabled'; ?>>
                        <label for="sz_<?php echo htmlspecialchars($s['size']); ?>">
                            <?php echo htmlspecialchars($s['size']); ?>
                            <?php if (!$oos): ?>
                            <span class="stock-hint"><?php echo $s['stock']; ?> dispo</span>
                            <?php endif; ?>
                        </label>
                        <?php if ($oos): ?>
                        <div class="avail-chip">
                            <div class="ac-label">Dispo le</div>
                            <div class="ac-date"><?php echo $s['avail_date'] ? date('d/m/Y', strtotime($s['avail_date'])) : '—'; ?></div>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>

                <?php if (!isset($_SESSION['user_id'])): ?>
                    <a href="login.php" class="btn-login-lg">🔑 Connectez-vous pour louer</a>
                <?php else: ?>
                    <button type="submit" class="btn-rent-lg"><span>Louer maintenant</span></button>
                <?php endif; ?>
            </form>

            <div class="rental-note" style="margin-top:1rem;">
                <span class="icon">ℹ️</span>
                <span>Le prix est par jour de location. La durée et les dates seront confirmées à l'étape suivante.</span>
            </div>
        <?php endif; ?>

        <div style="margin-top:1.5rem;">
            <a href="index.php" style="color:var(--clay);font-size:0.82rem;text-decoration:none;letter-spacing:0.08em;">← Retour à la collection</a>
        </div>
    </div>
</div>

<?php if ($related && $related->num_rows > 0): ?>
<div class="related-section">
    <h2 class="related-title">Articles similaires</h2>
    <div class="related-grid">
        <?php while ($r = $related->fetch_assoc()): ?>
        <a href="product.php?id=<?php echo (int)$r['id']; ?>" class="rel-card">
            <div class="rel-img"><img src="uploads/<?php echo htmlspecialchars($r['image']); ?>" alt="<?php echo htmlspecialchars($r['name']); ?>" loading="lazy"></div>
            <div class="rel-body">
                <div class="rel-name"><?php echo htmlspecialchars($r['name']); ?></div>
                <div class="rel-price"><?php echo number_format($r['price_per_day'], 0, ',', ' '); ?> DA / jour</div>
            </div>
        </a>
        <?php endwhile; ?>
    </div>
</div>
<?php endif; ?>

<footer class="site-footer">
    <div class="footer-brand">Élara</div>
    <p>© <?php echo date('Y'); ?> Élara · Location de Vêtements · Tous droits réservés</p>
</footer>

<div class="toast" id="toast"></div>

<script>
const WL_KEY = 'elara_wishlist';
const getWL  = () => { try { return JSON.parse(localStorage.getItem(WL_KEY)) || []; } catch(e) { return []; } };
const saveWL = (l) => localStorage.setItem(WL_KEY, JSON.stringify(l));

function toggleWishlist(id, btn) {
    let list = getWL();
    const idx = list.indexOf(id);
    if (idx === -1) { list.push(id); btn.textContent = '❤️'; btn.classList.add('liked'); showToast('Ajouté aux favoris ❤️'); }
    else { list.splice(idx,1); btn.textContent = '🤍'; btn.classList.remove('liked'); showToast('Retiré des favoris'); }
    saveWL(list);
}
(function() {
    const btn = document.getElementById('wishlistBtn');
    if (btn && getWL().includes(<?php echo (int)$p['id']; ?>)) { btn.textContent = '❤️'; btn.classList.add('liked'); }
})();

let _tt;
function showToast(msg) {
    const t = document.getElementById('toast');
    t.textContent = msg; t.classList.add('show');
    clearTimeout(_tt); _tt = setTimeout(() => t.classList.remove('show'), 2500);
}
</script>
</body>
</html>