<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if (!isset($_POST['product_id'], $_POST['size'])) {
    header("Location: index.php");
    exit();
}

$product_id = (int)$_POST['product_id'];
$size       = trim($_POST['size']);

/* Get product */
$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: index.php");
    exit();
}

/* Check stock */
$stmt = $conn->prepare("SELECT stock FROM product_sizes WHERE product_id = ? AND size = ?");
$stmt->bind_param("is", $product_id, $size);
$stmt->execute();
$stockRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$stockRow || $stockRow['stock'] <= 0) {
    header("Location: index.php?error=stock");
    exit();
}

$error = "";

/* Handle confirmation */
if (isset($_POST['confirm'])) {
    $start = $_POST['start_date'] ?? '';
    $end   = $_POST['end_date']   ?? '';

    $startDate = DateTime::createFromFormat('Y-m-d', $start);
    $endDate   = DateTime::createFromFormat('Y-m-d', $end);
    $today     = new DateTime('today');

    if (!$startDate || !$endDate) {
        $error = "Dates invalides.";
    } elseif ($startDate < $today) {
        $error = "La date de début ne peut pas être dans le passé.";
    } elseif ($endDate <= $startDate) {
        $error = "La date de fin doit être après la date de début.";
    } else {
        $days  = $startDate->diff($endDate)->days;
        $total = $days * $product['price_per_day'];

        $stmt = $conn->prepare("
            INSERT INTO rentals (user_id, product_id, size, start_date, end_date, total_price, status)
            VALUES (?, ?, ?, ?, ?, ?, 'pending')
        ");
        $stmt->bind_param("iisssd", $_SESSION['user_id'], $product_id, $size, $start, $end, $total);
        $stmt->execute();
        $stmt->close();

        /* Decrease stock */
        $conn->query("UPDATE product_sizes SET stock = stock - 1 WHERE product_id = $product_id AND size = '" . $conn->real_escape_string($size) . "'");

        header("Location: my_rentals.php");
        exit();
    }
}

$userName = $_SESSION['user_name'] ?? '';
$gmap = ['Men'=>'Homme','Women'=>'Femme','Unisex'=>'Unisexe'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirmer la location — Élara</title>
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
        .nav-back {
            display: inline-flex; align-items: center; gap: 0.5rem;
            color: rgba(253,250,246,0.55); text-decoration: none;
            font-size: 0.8rem; letter-spacing: 0.08em; text-transform: uppercase;
            transition: color 0.2s;
        }
        .nav-back:hover { color: var(--clay); }

        /* ══ BREADCRUMB ══ */
        .breadcrumb {
            margin-top: var(--nav-h);
            background: var(--white);
            border-bottom: 1px solid var(--sand);
            padding: 0.75rem 2.5rem;
        }
        .breadcrumb-inner {
            max-width: 1000px; margin: 0 auto;
            display: flex; align-items: center; gap: 0.5rem;
            font-size: 0.78rem; color: rgba(61,35,20,0.4);
        }
        .breadcrumb-inner a { color: var(--terra); text-decoration: none; }
        .breadcrumb-inner a:hover { text-decoration: underline; }
        .breadcrumb-inner .sep { color: rgba(61,35,20,0.2); }
        .breadcrumb-inner .current { color: var(--espresso); }

        /* ══ PAGE WRAPPER ══ */
        .page-wrap {
            max-width: 1000px; margin: 0 auto;
            padding: 3rem 2.5rem 5rem;
            display: grid;
            grid-template-columns: 1fr 420px;
            gap: 3rem;
            align-items: start;
        }

        /* ══ LEFT — PRODUCT SUMMARY ══ */
        .product-summary {
            position: sticky; top: calc(var(--nav-h) + 1.5rem);
        }

        .product-image-wrap {
            aspect-ratio: 3/4;
            background: var(--sand);
            border-radius: 2px;
            overflow: hidden;
            margin-bottom: 1.5rem;
            position: relative;
        }
        .product-image-wrap img {
            width: 100%; height: 100%;
            object-fit: cover; display: block;
        }
        .product-image-wrap .img-overlay {
            position: absolute; inset: 0;
            background: linear-gradient(to top, rgba(26,15,8,0.6) 0%, transparent 50%);
        }
        .product-image-wrap .img-label {
            position: absolute; bottom: 1.25rem; left: 1.25rem; right: 1.25rem;
        }
        .product-image-wrap .img-label h2 {
            font-family: 'Playfair Display', serif;
            font-size: 1.3rem; font-weight: 700;
            color: var(--white); line-height: 1.2;
        }
        .product-image-wrap .img-label .cat-tag {
            font-size: 0.62rem; letter-spacing: 0.2em;
            text-transform: uppercase; color: var(--clay);
            margin-bottom: 0.3rem; display: block;
        }

        .summary-chips {
            display: flex; flex-wrap: wrap; gap: 0.5rem;
            margin-bottom: 1rem;
        }
        .chip {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.35rem 0.85rem;
            background: var(--white); border: 1px solid var(--sand);
            border-radius: 100px; font-size: 0.75rem; color: var(--espresso);
        }
        .chip .chip-icon { font-size: 0.85rem; }

        .price-tag {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem; font-weight: 700; color: var(--terra);
        }
        .price-tag span {
            font-family: 'DM Sans', sans-serif;
            font-size: 0.85rem; font-weight: 400; color: var(--clay);
            margin-left: 0.25rem;
        }

        /* ══ RIGHT — BOOKING FORM ══ */
        .booking-card {
            background: var(--white);
            border: 1px solid var(--sand);
            border-radius: 2px;
            padding: 2rem;
            box-shadow: 0 4px 24px rgba(61,35,20,0.07);
        }

        .booking-card h1 {
            font-family: 'Playfair Display', serif;
            font-size: 1.4rem; font-weight: 700;
            color: var(--espresso);
            margin-bottom: 0.35rem;
        }
        .booking-card .subtitle {
            font-size: 0.82rem; color: rgba(61,35,20,0.45);
            font-weight: 300; margin-bottom: 1.75rem;
        }

        /* Error */
        .error-box {
            display: flex; align-items: flex-start; gap: 0.75rem;
            padding: 0.9rem 1rem;
            background: #FDF0EF; border: 1px solid #F5C6C3; border-radius: 2px;
            margin-bottom: 1.5rem;
            font-size: 0.83rem; color: var(--error); line-height: 1.4;
        }
        .error-box .err-icon { font-size: 1rem; flex-shrink: 0; margin-top: 0.05rem; }

        /* Divider */
        .divider { height: 1px; background: var(--sand); margin: 1.5rem 0; }

        /* Form groups */
        .form-group { margin-bottom: 1.25rem; }
        .form-group label {
            display: block;
            font-size: 0.72rem; letter-spacing: 0.15em; text-transform: uppercase;
            color: rgba(61,35,20,0.55); font-weight: 500;
            margin-bottom: 0.5rem;
        }
        .form-group input[type="date"] {
            width: 100%;
            padding: 0.75rem 1rem;
            border: 1.5px solid var(--sand);
            border-radius: 2px;
            background: var(--cream);
            color: var(--espresso);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.9rem;
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s, background 0.2s;
            cursor: pointer;
        }
        .form-group input[type="date"]:focus {
            border-color: var(--terra);
            box-shadow: 0 0 0 3px rgba(139,94,60,0.1);
            background: var(--white);
        }
        .form-group input[type="date"]:valid {
            border-color: var(--clay);
        }

        /* Date row */
        .date-row {
            display: grid; grid-template-columns: 1fr 1fr; gap: 1rem;
            margin-bottom: 1.25rem;
        }
        .date-row .form-group { margin-bottom: 0; }

        /* Duration hint */
        .duration-hint {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            padding: 0.6rem 1rem;
            background: var(--cream); border: 1px dashed var(--clay);
            border-radius: 2px; margin-bottom: 1.5rem;
            font-size: 0.8rem; color: var(--terra);
            opacity: 0; transform: translateY(4px);
            transition: opacity 0.3s, transform 0.3s;
        }
        .duration-hint.visible { opacity: 1; transform: translateY(0); }
        .duration-hint strong { font-weight: 600; }

        /* Price breakdown */
        .price-breakdown {
            background: var(--cream); border-radius: 2px;
            padding: 1.25rem; margin-bottom: 1.5rem;
            opacity: 0; transform: translateY(6px);
            transition: opacity 0.35s, transform 0.35s;
        }
        .price-breakdown.visible { opacity: 1; transform: translateY(0); }

        .breakdown-row {
            display: flex; justify-content: space-between; align-items: center;
            font-size: 0.83rem; color: rgba(61,35,20,0.6);
            margin-bottom: 0.5rem;
        }
        .breakdown-row:last-child { margin-bottom: 0; }
        .breakdown-total {
            display: flex; justify-content: space-between; align-items: center;
            padding-top: 0.75rem; margin-top: 0.75rem;
            border-top: 1px solid var(--sand);
        }
        .breakdown-total .label {
            font-size: 0.8rem; letter-spacing: 0.1em;
            text-transform: uppercase; font-weight: 500; color: var(--espresso);
        }
        .breakdown-total .amount {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem; font-weight: 700; color: var(--terra);
        }
        .breakdown-total .amount span {
            font-family: 'DM Sans', sans-serif;
            font-size: 0.75rem; font-weight: 400; color: var(--clay);
        }

        /* Submit button */
        .btn-confirm {
            width: 100%; padding: 1rem;
            background: var(--espresso); color: var(--white);
            border: none; border-radius: 2px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.88rem; font-weight: 500;
            letter-spacing: 0.15em; text-transform: uppercase;
            cursor: pointer; position: relative; overflow: hidden;
            transition: transform 0.15s;
        }
        .btn-confirm::before {
            content: ''; position: absolute; inset: 0;
            background: var(--terra);
            transform: translateX(-100%);
            transition: transform 0.35s ease;
        }
        .btn-confirm:hover::before { transform: translateX(0); }
        .btn-confirm:active { transform: scale(0.98); }
        .btn-confirm span { position: relative; z-index: 1; }

        /* Info note */
        .info-note {
            display: flex; align-items: flex-start; gap: 0.65rem;
            margin-top: 1rem;
            font-size: 0.77rem; color: rgba(61,35,20,0.45); line-height: 1.5;
        }
        .info-note .icon { font-size: 0.9rem; flex-shrink: 0; margin-top: 0.05rem; }

        /* Step indicator */
        .steps {
            display: flex; align-items: center; gap: 0;
            margin-bottom: 1.75rem;
        }
        .step {
            display: flex; flex-direction: column; align-items: center; gap: 0.25rem;
            flex: 1;
        }
        .step-dot {
            width: 28px; height: 28px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: 0.7rem; font-weight: 600;
            border: 2px solid var(--sand); background: var(--cream);
            color: rgba(61,35,20,0.35);
        }
        .step.done .step-dot { background: var(--espresso); border-color: var(--espresso); color: var(--white); }
        .step.active .step-dot { background: var(--terra); border-color: var(--terra); color: var(--white); }
        .step-label { font-size: 0.6rem; letter-spacing: 0.1em; text-transform: uppercase; color: rgba(61,35,20,0.35); text-align: center; }
        .step.done .step-label, .step.active .step-label { color: var(--espresso); }
        .step-line { flex: 1; height: 1px; background: var(--sand); margin: 0 0.25rem; margin-bottom: 1.1rem; }
        .step-line.done { background: var(--espresso); }

        /* ══ FOOTER ══ */
        .site-footer { background: var(--espresso); padding: 2rem 2.5rem; text-align: center; }
        .site-footer .footer-brand { font-family: 'Playfair Display', serif; font-size: 1.3rem; color: var(--white); margin-bottom: 0.3rem; }
        .site-footer p { font-size: 0.72rem; color: rgba(196,168,130,0.35); letter-spacing: 0.1em; }

        /* ══ RESPONSIVE ══ */
        @media (max-width: 860px) {
            .page-wrap { grid-template-columns: 1fr; gap: 2rem; }
            .product-summary { position: static; max-width: 480px; }
            .product-image-wrap { aspect-ratio: 16/9; }
        }
        @media (max-width: 600px) {
            .navbar { padding: 0 1.25rem; }
            .breadcrumb { padding: 0.75rem 1.25rem; }
            .page-wrap { padding: 1.5rem 1.25rem 4rem; }
            .booking-card { padding: 1.5rem; }
            .date-row { grid-template-columns: 1fr; }
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
    <a href="javascript:history.back()" class="nav-back">← Retour</a>
</nav>

<!-- BREADCRUMB -->
<div class="breadcrumb">
    <div class="breadcrumb-inner">
        <a href="index.php">Accueil</a>
        <span class="sep">›</span>
        <a href="product.php?id=<?php echo $product_id; ?>"><?php echo htmlspecialchars($product['name']); ?></a>
        <span class="sep">›</span>
        <span class="current">Confirmer la location</span>
    </div>
</div>

<!-- PAGE -->
<div class="page-wrap">

    <!-- LEFT: Product summary -->
    <div class="product-summary">
        <div class="product-image-wrap">
            <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>"
                 alt="<?php echo htmlspecialchars($product['name']); ?>">
            <div class="img-overlay"></div>
            <div class="img-label">
                <span class="cat-tag"><?php echo htmlspecialchars($product['category'] ?? ''); ?></span>
                <h2><?php echo htmlspecialchars($product['name']); ?></h2>
            </div>
        </div>

        <div class="summary-chips">
            <span class="chip"><span class="chip-icon">📐</span> Taille <?php echo htmlspecialchars($size); ?></span>
            <?php if (!empty($product['gender'])): ?>
            <span class="chip"><span class="chip-icon">👤</span> <?php echo $gmap[$product['gender']] ?? htmlspecialchars($product['gender']); ?></span>
            <?php endif; ?>
            <span class="chip"><span class="chip-icon">📦</span> <?php echo $stockRow['stock']; ?> en stock</span>
        </div>

        <div class="price-tag">
            <?php echo number_format($product['price_per_day'], 0, ',', ' '); ?>
            <span>DA / jour</span>
        </div>
    </div>

    <!-- RIGHT: Booking form -->
    <div class="booking-card">

        <!-- Step indicator -->
        <div class="steps">
            <div class="step done">
                <div class="step-dot">✓</div>
                <div class="step-label">Sélection</div>
            </div>
            <div class="step-line done"></div>
            <div class="step active">
                <div class="step-dot">2</div>
                <div class="step-label">Dates</div>
            </div>
            <div class="step-line"></div>
            <div class="step">
                <div class="step-dot">3</div>
                <div class="step-label">Confirmation</div>
            </div>
        </div>

        <h1>Choisir les dates</h1>
        <p class="subtitle">Sélectionnez votre période de location ci-dessous.</p>

        <?php if ($error): ?>
        <div class="error-box">
            <span class="err-icon">⚠</span>
            <span><?php echo htmlspecialchars($error); ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" id="rentForm">
            <input type="hidden" name="product_id" value="<?php echo $product_id; ?>">
            <input type="hidden" name="size" value="<?php echo htmlspecialchars($size); ?>">

            <div class="date-row">
                <div class="form-group">
                    <label>Date de début</label>
                    <input type="date" name="start_date" id="startDate"
                           min="<?php echo date('Y-m-d'); ?>"
                           value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>"
                           required>
                </div>
                <div class="form-group">
                    <label>Date de fin</label>
                    <input type="date" name="end_date" id="endDate"
                           min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                           value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>"
                           required>
                </div>
            </div>

            <!-- Duration hint -->
            <div class="duration-hint" id="durationHint">
                <span>📅</span>
                <span>Durée : <strong id="durationText"></strong></span>
            </div>

            <!-- Live price breakdown -->
            <div class="price-breakdown" id="priceBreakdown">
                <div class="breakdown-row">
                    <span id="breakdownLabel">— jours × <?php echo number_format($product['price_per_day'], 0, ',', ' '); ?> DA</span>
                    <span id="breakdownSub">0 DA</span>
                </div>
                <div class="breakdown-total">
                    <span class="label">Total estimé</span>
                    <span class="amount" id="breakdownTotal">0 <span>DA</span></span>
                </div>
            </div>

            <button type="submit" name="confirm" class="btn-confirm">
                <span>✦ Confirmer la location</span>
            </button>

            <div class="info-note">
                <span class="icon">ℹ️</span>
                <span>Votre réservation sera en statut <em>«&nbsp;en attente&nbsp;»</em> jusqu'à validation par notre équipe. Aucun paiement immédiat requis.</span>
            </div>
        </form>
    </div>
</div>

<!-- FOOTER -->
<footer class="site-footer">
    <div class="footer-brand">Élara</div>
    <p>© <?php echo date('Y'); ?> Élara · Location de Vêtements · Tous droits réservés</p>
</footer>

<script>
const pricePerDay = <?php echo (float)$product['price_per_day']; ?>;
const startInput  = document.getElementById('startDate');
const endInput    = document.getElementById('endDate');
const hint        = document.getElementById('durationHint');
const breakdown   = document.getElementById('priceBreakdown');
const durationTxt = document.getElementById('durationText');
const subTxt      = document.getElementById('breakdownSub');
const labelTxt    = document.getElementById('breakdownLabel');
const totalTxt    = document.getElementById('breakdownTotal');

function fmt(n) {
    return n.toLocaleString('fr-DZ') + ' DA';
}

function calcPrice() {
    const s = startInput.value;
    const e = endInput.value;
    if (!s || !e) { hint.classList.remove('visible'); breakdown.classList.remove('visible'); return; }

    const start = new Date(s);
    const end   = new Date(e);
    const diff  = Math.round((end - start) / (1000 * 60 * 60 * 24));

    if (diff <= 0) { hint.classList.remove('visible'); breakdown.classList.remove('visible'); return; }

    const total = diff * pricePerDay;
    const dayWord = diff === 1 ? 'jour' : 'jours';

    durationTxt.textContent = diff + ' ' + dayWord;
    hint.classList.add('visible');

    labelTxt.textContent = diff + ' ' + dayWord + ' × ' + pricePerDay.toLocaleString('fr-DZ') + ' DA';
    subTxt.textContent   = fmt(total);
    totalTxt.innerHTML   = total.toLocaleString('fr-DZ') + ' <span>DA</span>';
    breakdown.classList.add('visible');
}

// Auto-advance end date min when start changes
startInput.addEventListener('change', () => {
    if (startInput.value) {
        const next = new Date(startInput.value);
        next.setDate(next.getDate() + 1);
        endInput.min = next.toISOString().split('T')[0];
        if (endInput.value && endInput.value <= startInput.value) endInput.value = '';
    }
    calcPrice();
});

endInput.addEventListener('change', calcPrice);

// Init if values pre-filled (validation error re-render)
calcPrice();
</script>

</body>
</html>