<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$user_id = (int)$_SESSION['user_id'];
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION['csrf_token'];
$error   = '';

// ── Handle checkout atomically ───────────────────────────────────────────────
if (isset($_POST['checkout'])) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid request token.'); }
    $start = $_POST['start_date'] ?? '';
    $end = $_POST['end_date'] ?? '';
    $startDate = DateTime::createFromFormat('!Y-m-d', $start);
    $endDate = DateTime::createFromFormat('!Y-m-d', $end);
    $today = new DateTime('today');

    if (!$startDate || !$endDate || $startDate->format('Y-m-d') !== $start || $endDate->format('Y-m-d') !== $end) {
        $error = "Dates invalides.";
    } elseif ($startDate < $today) {
        $error = "La date de début ne peut pas être dans le passé.";
    } elseif ($endDate <= $startDate) {
        $error = "La date de fin doit être après la date de début.";
    } else {
        $days = $startDate->diff($endDate)->days;
        $conn->begin_transaction();
        try {
            $stmt = $conn->prepare("SELECT c.id AS cart_id, c.product_id, c.size, p.price_per_day FROM cart c JOIN products p ON p.id=c.product_id WHERE c.user_id=? FOR UPDATE");
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();

            if (!$items) {
                throw new RuntimeException("Votre panier est vide.");
            }

            $stockStmt = $conn->prepare("SELECT stock FROM product_sizes WHERE product_id=? AND size=? FOR UPDATE");
            $reserveStmt = $conn->prepare("UPDATE product_sizes SET stock=stock-1 WHERE product_id=? AND size=? AND stock>0");
            $rentalStmt = $conn->prepare("INSERT INTO rentals (user_id, product_id, size, start_date, end_date, total_price, status) VALUES (?, ?, ?, ?, ?, ?, 'pending')");
            $deleteStmt = $conn->prepare("DELETE FROM cart WHERE id=? AND user_id=?");

            foreach ($items as $item) {
                $productId = (int)$item['product_id'];
                $size = $item['size'];
                $stockStmt->bind_param("is", $productId, $size);
                $stockStmt->execute();
                $stockRow = $stockStmt->get_result()->fetch_assoc();
                if (!$stockRow || (int)$stockRow['stock'] < 1) {
                    throw new RuntimeException("Un article n'est plus disponible. Veuillez actualiser votre panier.");
                }

                $reserveStmt->bind_param("is", $productId, $size);
                $reserveStmt->execute();
                if ($reserveStmt->affected_rows !== 1) {
                    throw new RuntimeException("Le stock a changé. Veuillez réessayer.");
                }

                $total = $days * (float)$item['price_per_day'];
                $rentalStmt->bind_param("iisssd", $user_id, $productId, $size, $start, $end, $total);
                $rentalStmt->execute();

                $cartId = (int)$item['cart_id'];
                $deleteStmt->bind_param("ii", $cartId, $user_id);
                $deleteStmt->execute();
            }

            $stockStmt->close();
            $reserveStmt->close();
            $rentalStmt->close();
            $deleteStmt->close();
            $conn->commit();
            header("Location: my_rentals.php?booked=1");
            exit();
        } catch (Throwable $e) {
            $conn->rollback();
            error_log('Cart checkout failed: ' . $e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : "Impossible de traiter votre panier. Veuillez réessayer.";
        }
    }
}

// ── Fetch cart items ─────────────────────────────────────────────────────────
$cartItems = $conn->query("
    SELECT c.id as cart_id, c.size, p.id as product_id,
           p.name, p.image, p.category, p.gender, p.price_per_day,
           ps.stock
    FROM cart c
    JOIN products p ON p.id = c.product_id
    LEFT JOIN product_sizes ps ON ps.product_id = c.product_id AND ps.size = c.size
    WHERE c.user_id = $user_id
    ORDER BY c.created_at DESC
");

$cartCount = $cartItems->num_rows;
$gmap = ['Men'=>'Homme','Women'=>'Femme','Unisex'=>'Unisexe'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mon Panier — Élara</title>
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
        }
        .nav-brand { display: flex; flex-direction: column; text-decoration: none; }
        .nav-brand .sublabel { font-size: 0.58rem; letter-spacing: 0.28em; text-transform: uppercase; color: var(--clay); }
        .nav-brand .wordmark { font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 700; color: var(--white); line-height: 1; }
        .nav-back {
            color: rgba(253,250,246,0.55); text-decoration: none;
            font-size: 0.8rem; letter-spacing: 0.08em; text-transform: uppercase;
            transition: color 0.2s;
        }
        .nav-back:hover { color: var(--clay); }

        /* ══ PAGE ══ */
        .page-header {
            margin-top: var(--nav-h);
            background: var(--espresso);
            padding: 3rem 2.5rem 2.5rem;
            position: relative; overflow: hidden;
        }
        .page-header::before {
            content: 'Panier';
            position: absolute;
            font-family: 'Playfair Display', serif;
            font-size: 12vw; font-weight: 700;
            color: rgba(196,168,130,0.04);
            right: 2rem; top: 50%; transform: translateY(-50%);
            pointer-events: none; white-space: nowrap;
        }
        .page-header-inner { position: relative; z-index: 1; max-width: 1100px; margin: 0 auto; }
        .page-header .tag {
            font-size: 0.65rem; letter-spacing: 0.28em;
            text-transform: uppercase; color: var(--clay); margin-bottom: 0.6rem;
        }
        .page-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2.5rem; font-weight: 700; color: var(--white); line-height: 1.1;
        }
        .page-header p {
            font-size: 0.9rem; color: rgba(253,250,246,0.45);
            margin-top: 0.5rem;
        }

        /* ══ MAIN LAYOUT ══ */
        .main {
            max-width: 1100px; margin: 0 auto;
            padding: 3rem 2.5rem 5rem;
            display: grid;
            grid-template-columns: 1fr 360px;
            gap: 2.5rem;
            align-items: start;
        }

        /* ══ CART ITEMS ══ */
        .cart-list { display: flex; flex-direction: column; gap: 1rem; }

        .cart-item {
            background: var(--white);
            border: 1px solid var(--sand);
            border-radius: 3px;
            display: grid;
            grid-template-columns: 100px 1fr auto;
            gap: 1.25rem;
            overflow: hidden;
            transition: border-color 0.2s, box-shadow 0.2s;
            animation: fadeIn 0.4s ease both;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateX(-12px); }
            to   { opacity: 1; transform: translateX(0); }
        }

        .cart-item:hover {
            border-color: var(--clay);
            box-shadow: 0 4px 20px rgba(61,35,20,0.08);
        }

        .cart-item.out-of-stock {
            opacity: 0.6; border-color: var(--error);
        }

        .item-img {
            aspect-ratio: 3/4;
            background: var(--sand);
            overflow: hidden;
        }
        .item-img img {
            width: 100%; height: 100%;
            object-fit: cover;
        }

        .item-info {
            padding: 1rem 0;
            display: flex; flex-direction: column; justify-content: center;
            gap: 0.4rem;
        }

        .item-category {
            font-size: 0.62rem; letter-spacing: 0.18em;
            text-transform: uppercase; color: var(--clay);
        }

        .item-name {
            font-family: 'Playfair Display', serif;
            font-size: 1.05rem; font-weight: 600;
            color: var(--espresso); line-height: 1.2;
        }

        .item-chips {
            display: flex; gap: 0.4rem; flex-wrap: wrap; margin-top: 0.25rem;
        }
        .item-chip {
            padding: 0.2rem 0.6rem;
            background: var(--sand); border-radius: 100px;
            font-size: 0.68rem; color: var(--terra);
            font-weight: 500;
        }
        .item-chip.danger {
            background: #FDECEA; color: var(--error);
        }

        .item-price {
            font-family: 'Playfair Display', serif;
            font-size: 1.1rem; font-weight: 700;
            color: var(--espresso); margin-top: 0.5rem;
        }
        .item-price span {
            font-family: 'DM Sans', sans-serif;
            font-size: 0.72rem; font-weight: 400;
            color: rgba(61,35,20,0.45);
        }

        .item-actions {
            padding: 1rem;
            display: flex; flex-direction: column;
            align-items: flex-end; justify-content: center;
            gap: 0.5rem;
        }

        .btn-remove {
            display: inline-flex; align-items: center; gap: 0.35rem;
            padding: 0.45rem 0.9rem;
            background: transparent;
            border: 1.5px solid var(--sand);
            border-radius: 2px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.72rem; font-weight: 500;
            letter-spacing: 0.06em; text-transform: uppercase;
            color: rgba(61,35,20,0.5);
            text-decoration: none;
            transition: border-color 0.2s, color 0.2s, background 0.2s;
            white-space: nowrap;
        }
        .btn-remove:hover {
            border-color: var(--error);
            color: var(--error);
            background: #FDECEA;
        }

        /* ══ EMPTY STATE ══ */
        .empty-cart {
            background: var(--white);
            border: 1px solid var(--sand);
            border-radius: 3px;
            text-align: center;
            padding: 5rem 2rem;
        }
        .empty-cart .icon { font-size: 3rem; margin-bottom: 1rem; opacity: 0.3; }
        .empty-cart h3 {
            font-family: 'Playfair Display', serif;
            font-size: 1.5rem; color: var(--espresso); margin-bottom: 0.5rem;
        }
        .empty-cart p { font-size: 0.9rem; color: rgba(61,35,20,0.5); margin-bottom: 1.5rem; }
        .btn-shop {
            display: inline-block; padding: 0.8rem 2rem;
            background: var(--espresso); color: var(--white);
            border-radius: 2px; text-decoration: none;
            font-size: 0.82rem; font-weight: 500;
            letter-spacing: 0.12em; text-transform: uppercase;
            transition: background 0.2s;
        }
        .btn-shop:hover { background: var(--terra); }

        /* ══ SUMMARY / CHECKOUT ══ */
        .summary-card {
            background: var(--white);
            border: 1px solid var(--sand);
            border-radius: 3px;
            overflow: hidden;
            position: sticky;
            top: calc(var(--nav-h) + 1.5rem);
        }

        .summary-header {
            background: var(--espresso);
            padding: 1.25rem 1.5rem;
        }
        .summary-header h2 {
            font-family: 'Playfair Display', serif;
            font-size: 1.1rem; font-weight: 600; color: var(--white);
        }
        .summary-header p {
            font-size: 0.75rem; color: var(--clay); margin-top: 0.2rem;
        }

        .summary-body { padding: 1.5rem; }

        .summary-rows { margin-bottom: 1.25rem; }
        .summary-row {
            display: flex; justify-content: space-between;
            align-items: baseline;
            padding: 0.5rem 0;
            border-bottom: 1px solid var(--sand);
            font-size: 0.82rem;
        }
        .summary-row:last-child { border-bottom: none; }
        .summary-row .s-name {
            color: var(--espresso); font-weight: 500;
            max-width: 160px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .summary-row .s-price { color: var(--terra); font-weight: 500; white-space: nowrap; }

        .summary-total {
            background: var(--cream);
            border-radius: 2px;
            padding: 1rem;
            margin-bottom: 1.5rem;
        }
        .summary-total .label {
            font-size: 0.68rem; letter-spacing: 0.15em;
            text-transform: uppercase; color: var(--clay); margin-bottom: 0.3rem;
        }
        .summary-total .amount {
            font-family: 'Playfair Display', serif;
            font-size: 1.8rem; font-weight: 700; color: var(--espresso);
        }
        .summary-total .amount span {
            font-family: 'DM Sans', sans-serif;
            font-size: 0.85rem; font-weight: 400;
            color: rgba(61,35,20,0.45);
        }
        .summary-total .per-day-note {
            font-size: 0.72rem; color: rgba(61,35,20,0.4);
            margin-top: 0.2rem;
        }

        /* Date inputs */
        .date-section { margin-bottom: 1.25rem; }
        .date-section .date-label {
            font-size: 0.68rem; letter-spacing: 0.14em;
            text-transform: uppercase; color: var(--terra);
            margin-bottom: 0.4rem; display: block;
        }
        .date-input {
            width: 100%;
            padding: 0.65rem 0.9rem;
            border: 1.5px solid var(--sand);
            border-radius: 2px;
            background: var(--cream);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.875rem; color: var(--espresso);
            outline: none;
            transition: border-color 0.2s, box-shadow 0.2s;
        }
        .date-input:focus {
            border-color: var(--terra);
            box-shadow: 0 0 0 3px rgba(139,94,60,0.08);
        }

        .duration-badge {
            display: flex; align-items: center; gap: 0.5rem;
            padding: 0.6rem 0.9rem;
            background: var(--cream); border-radius: 2px;
            font-size: 0.8rem; color: var(--terra);
            margin-top: 0.5rem;
            opacity: 0; transition: opacity 0.3s;
        }
        .duration-badge.show { opacity: 1; }

        .alert-error {
            padding: 0.75rem 1rem;
            background: #FDECEA; border-left: 3px solid var(--error);
            border-radius: 2px; font-size: 0.82rem;
            color: var(--error); margin-bottom: 1.25rem;
        }

        /* Checkout button */
        .btn-checkout {
            width: 100%; padding: 1rem;
            background: var(--espresso); color: var(--white);
            border: none; border-radius: 2px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.875rem; font-weight: 500;
            letter-spacing: 0.14em; text-transform: uppercase;
            cursor: pointer; position: relative; overflow: hidden;
            transition: transform 0.15s;
        }
        .btn-checkout::before {
            content: ''; position: absolute; inset: 0;
            background: var(--terra); transform: translateX(-100%);
            transition: transform 0.35s ease;
        }
        .btn-checkout:hover::before { transform: translateX(0); }
        .btn-checkout:active { transform: scale(0.98); }
        .btn-checkout span { position: relative; z-index: 1; }
        .btn-checkout:disabled {
            background: var(--sand); color: rgba(61,35,20,0.4);
            cursor: not-allowed;
        }
        .btn-checkout:disabled::before { display: none; }

        .checkout-note {
            display: flex; align-items: flex-start; gap: 0.5rem;
            margin-top: 1rem; font-size: 0.75rem;
            color: rgba(61,35,20,0.45); line-height: 1.5;
        }

        /* ══ RESPONSIVE ══ */
        @media (max-width: 860px) {
            .main { grid-template-columns: 1fr; }
            .summary-card { position: static; }
        }
        @media (max-width: 600px) {
            .navbar { padding: 0 1.25rem; }
            .page-header { padding: 2.5rem 1.25rem 2rem; }
            .main { padding: 1.5rem 1.25rem 4rem; gap: 1.5rem; }
            .cart-item { grid-template-columns: 80px 1fr; }
            .item-actions { display: none; }
            .item-info { padding: 0.75rem 0.75rem 0.75rem 0; }
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
    <a href="index.php" class="nav-back">← Continuer les achats</a>
</nav>

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="page-header-inner">
        <p class="tag">Mon espace</p>
        <h1>Mon Panier</h1>
        <p><?php echo $cartCount; ?> article<?php echo $cartCount !== 1 ? 's' : ''; ?> sélectionné<?php echo $cartCount !== 1 ? 's' : ''; ?></p>
    </div>
</div>

<!-- MAIN -->
<div class="main">

    <!-- LEFT: Cart items -->
    <div>
        <?php if ($cartCount === 0): ?>
        <div class="empty-cart">
            <div class="icon">🛒</div>
            <h3>Votre panier est vide</h3>
            <p>Parcourez notre collection et ajoutez des articles à votre panier.</p>
            <a href="index.php" class="btn-shop">Voir la collection</a>
        </div>
        <?php else: ?>
        <div class="cart-list">
            <?php while ($item = $cartItems->fetch_assoc()):
                $outOfStock = (!$item['stock'] || $item['stock'] <= 0);
            ?>
            <div class="cart-item <?php echo $outOfStock ? 'out-of-stock' : ''; ?>">
                <div class="item-img">
                    <img src="uploads/<?php echo htmlspecialchars($item['image']); ?>"
                         alt="<?php echo htmlspecialchars($item['name']); ?>"
                         onerror="this.style.background='var(--sand)';this.style.display='block'">
                </div>
                <div class="item-info">
                    <p class="item-category"><?php echo htmlspecialchars($item['category'] ?? ''); ?></p>
                    <h3 class="item-name"><?php echo htmlspecialchars($item['name']); ?></h3>
                    <div class="item-chips">
                        <span class="item-chip">Taille <?php echo htmlspecialchars($item['size']); ?></span>
                        <?php if (!empty($item['gender'])): ?>
                        <span class="item-chip"><?php echo $gmap[$item['gender']] ?? htmlspecialchars($item['gender']); ?></span>
                        <?php endif; ?>
                        <?php if ($outOfStock): ?>
                        <span class="item-chip danger">Rupture de stock</span>
                        <?php elseif ($item['stock'] <= 2): ?>
                        <span class="item-chip">Stock limité (<?php echo $item['stock']; ?>)</span>
                        <?php endif; ?>
                    </div>
                    <div class="item-price">
                        <?php echo number_format($item['price_per_day'], 0, ',', ' '); ?>
                        <span>DA / jour</span>
                    </div>
                </div>
                <div class="item-actions">
                    <form method="POST" action="cart_remove.php" onsubmit="return confirm('Retirer cet article ?')">
<input type="hidden" name="id" value="<?php echo (int)$item['cart_id']; ?>">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
<button type="submit" class="btn-remove">× Retirer</button>
</form>
                </div>
            </div>
            <?php endwhile; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- RIGHT: Summary + Checkout -->
    <?php if ($cartCount > 0): ?>
    <div class="summary-card">
        <div class="summary-header">
            <h2>Récapitulatif</h2>
            <p>Choisissez vos dates de location</p>
        </div>
        <div class="summary-body">

            <?php if ($error): ?>
            <div class="alert-error">⚠ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" id="checkoutForm">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                <!-- Date pickers -->
                <div class="date-section">
                    <label class="date-label" for="start_date">Date de début</label>
                    <input type="date" name="start_date" id="start_date" class="date-input"
                           min="<?php echo date('Y-m-d'); ?>"
                           value="<?php echo htmlspecialchars($_POST['start_date'] ?? ''); ?>"
                           required>
                </div>

                <div class="date-section">
                    <label class="date-label" for="end_date">Date de fin</label>
                    <input type="date" name="end_date" id="end_date" class="date-input"
                           min="<?php echo date('Y-m-d', strtotime('+1 day')); ?>"
                           value="<?php echo htmlspecialchars($_POST['end_date'] ?? ''); ?>"
                           required>
                </div>

                <div class="duration-badge" id="durationBadge">
                    📅 <span id="durationText">—</span>
                </div>

                <!-- Items breakdown -->
                <div class="summary-rows" id="summaryRows" style="margin-top:1.25rem;">
                    <?php
                    // Re-fetch for summary
                    $cartItems2 = $conn->query("
                        SELECT c.size, p.name, p.price_per_day
                        FROM cart c JOIN products p ON p.id = c.product_id
                        WHERE c.user_id = $user_id
                    ");
                    $totalPerDay = 0;
                    while ($ci = $cartItems2->fetch_assoc()):
                        $totalPerDay += $ci['price_per_day'];
                    ?>
                    <div class="summary-row">
                        <span class="s-name"><?php echo htmlspecialchars($ci['name']); ?> (<?php echo $ci['size']; ?>)</span>
                        <span class="s-price"><?php echo number_format($ci['price_per_day'], 0, ',', ' '); ?> DA/j</span>
                    </div>
                    <?php endwhile; ?>
                </div>

                <!-- Total -->
                <div class="summary-total">
                    <div class="label">Total estimé</div>
                    <div class="amount" id="totalAmount">— <span>DA</span></div>
                    <div class="per-day-note" id="perDayNote">
                        Sélectionnez vos dates pour calculer le total
                    </div>
                </div>

                <button type="submit" name="checkout" class="btn-checkout" id="checkoutBtn">
                    <span>✦ Confirmer la location</span>
                </button>

                <div class="checkout-note">
                    ℹ️ Votre réservation sera en statut <em>«&nbsp;en attente&nbsp;»</em> jusqu'à validation. Aucun paiement immédiat requis.
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

</div>

<script>
const totalPerDay = <?php echo (float)$totalPerDay; ?>;
const startEl = document.getElementById('start_date');
const endEl   = document.getElementById('end_date');
const badge   = document.getElementById('durationBadge');
const durTxt  = document.getElementById('durationText');
const totalEl = document.getElementById('totalAmount');
const noteEl  = document.getElementById('perDayNote');

function fmt(n) { return n.toLocaleString('fr-DZ') + ' DA'; }

function calcTotal() {
    const s = startEl?.value;
    const e = endEl?.value;
    if (!s || !e) {
        badge?.classList.remove('show');
        if (totalEl) totalEl.innerHTML = '— <span>DA</span>';
        if (noteEl) noteEl.textContent = 'Sélectionnez vos dates pour calculer le total';
        return;
    }
    const diff = Math.round((new Date(e) - new Date(s)) / 86400000);
    if (diff <= 0) return;

    const total = diff * totalPerDay;
    const word  = diff === 1 ? 'jour' : 'jours';

    durTxt.textContent = diff + ' ' + word;
    badge.classList.add('show');

    totalEl.innerHTML = total.toLocaleString('fr-DZ') + ' <span>DA</span>';
    noteEl.textContent = diff + ' ' + word + ' × ' + totalPerDay.toLocaleString('fr-DZ') + ' DA/jour';
}

startEl?.addEventListener('change', () => {
    if (startEl.value) {
        const next = new Date(startEl.value);
        next.setDate(next.getDate() + 1);
        endEl.min = next.toISOString().split('T')[0];
        if (endEl.value && endEl.value <= startEl.value) endEl.value = '';
    }
    calcTotal();
});
endEl?.addEventListener('change', calcTotal);
calcTotal();
</script>

</body>
</html>