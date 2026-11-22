<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php"); exit();
}

$rental_id = (int)($_GET['rental_id'] ?? 0);
$user_id   = (int)$_SESSION['user_id'];

if ($rental_id <= 0) { header("Location: my_rentals.php"); exit(); }

// Load rental (must belong to this user, must be pending)
$stmt = $conn->prepare("
    SELECT r.*, p.name as product_name, p.image as product_image, p.category
    FROM rentals r JOIN products p ON p.id = r.product_id
    WHERE r.id = ? AND r.user_id = ?
");
$stmt->bind_param("ii", $rental_id, $user_id);
$stmt->execute();
$rental = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rental) { header("Location: my_rentals.php"); exit(); }

$success = false;
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $method   = $_POST['payment_method'] ?? '';
    $cardNum  = preg_replace('/\s+/', '', $_POST['card_number'] ?? '');
    $cardName = trim($_POST['card_name'] ?? '');
    $expiry   = trim($_POST['expiry'] ?? '');
    $phone    = trim($_POST['phone'] ?? '');

    $validMethods = ['baridi', 'cib', 'dahabia'];

    if (!in_array($method, $validMethods)) {
        $error = "Veuillez choisir un moyen de paiement.";
    } elseif ($method === 'baridi') {
        if (strlen($phone) < 9) $error = "Numéro de téléphone invalide.";
    } else {
        if (strlen($cardNum) < 16) $error = "Numéro de carte invalide.";
        elseif (empty($cardName))   $error = "Nom du titulaire requis.";
        elseif (empty($expiry))     $error = "Date d'expiration requise.";
    }

    if (!$error) {
        // Record payment (store method + masked card, no real processing)
        $masked = $method === 'baridi' ? substr($phone, -4) : substr($cardNum, -4);
        $note   = $method . ':****' . $masked;
        $conn->query("UPDATE rentals SET payment_method='$note' WHERE id=$rental_id AND user_id=$user_id");
        $success = true;
    }
}

$start = new DateTime($rental['start_date']);
$end   = new DateTime($rental['end_date']);
$days  = max(1, $start->diff($end)->days);
$userName = $_SESSION['user_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Paiement — Élara</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --cream: #F5F0E8; --sand: #E8DDD0; --clay: #C4A882;
            --terra: #8B5E3C; --espresso: #3D2314; --dark: #1A0F08;
            --white: #FDFAF6; --error: #C0392B; --success: #27AE60;
            --nav-h: 72px;
        }
        body { font-family: 'DM Sans', sans-serif; background: var(--cream); color: var(--espresso); min-height: 100vh; }

        .navbar { position: fixed; top: 0; left: 0; right: 0; z-index: 100; height: var(--nav-h); background: var(--espresso); display: flex; align-items: center; justify-content: space-between; padding: 0 2.5rem; box-shadow: 0 2px 30px rgba(0,0,0,0.3); }
        .nav-brand { display: flex; flex-direction: column; text-decoration: none; }
        .nav-brand .sublabel { font-size: 0.58rem; letter-spacing: 0.28em; text-transform: uppercase; color: var(--clay); }
        .nav-brand .wordmark { font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 700; color: var(--white); line-height: 1; }
        .nav-back { color: rgba(253,250,246,0.55); text-decoration: none; font-size: 0.8rem; letter-spacing: 0.08em; text-transform: uppercase; transition: color 0.2s; }
        .nav-back:hover { color: var(--clay); }

        .breadcrumb { margin-top: var(--nav-h); background: var(--white); border-bottom: 1px solid var(--sand); padding: 0.75rem 2.5rem; }
        .breadcrumb-inner { max-width: 960px; margin: 0 auto; display: flex; align-items: center; gap: 0.5rem; font-size: 0.78rem; color: rgba(61,35,20,0.4); }
        .breadcrumb-inner a { color: var(--terra); text-decoration: none; }
        .breadcrumb-inner .sep { color: rgba(61,35,20,0.2); }

        .page-wrap { max-width: 960px; margin: 0 auto; padding: 3rem 2.5rem 5rem; display: grid; grid-template-columns: 1fr 380px; gap: 3rem; align-items: start; }

        /* ── Order summary ── */
        .order-summary { position: sticky; top: calc(var(--nav-h) + 1rem); }
        .summary-card { background: var(--white); border: 1px solid var(--sand); border-radius: 2px; overflow: hidden; }
        .summary-card .sc-head { background: var(--espresso); padding: 1.25rem 1.5rem; }
        .summary-card .sc-head h3 { font-family: 'Playfair Display', serif; font-size: 1rem; color: var(--white); }
        .summary-card .sc-head p { font-size: 0.72rem; color: var(--clay); margin-top: 0.2rem; }
        .summary-card .sc-body { padding: 1.5rem; }

        .order-product { display: flex; gap: 1rem; margin-bottom: 1.25rem; padding-bottom: 1.25rem; border-bottom: 1px solid var(--sand); }
        .op-img { width: 72px; aspect-ratio: 3/4; background: var(--sand); border-radius: 2px; overflow: hidden; flex-shrink: 0; }
        .op-img img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .op-info { flex: 1; }
        .op-cat { font-size: 0.6rem; letter-spacing: 0.18em; text-transform: uppercase; color: var(--clay); margin-bottom: 0.25rem; }
        .op-name { font-family: 'Playfair Display', serif; font-size: 1rem; font-weight: 600; color: var(--espresso); line-height: 1.2; margin-bottom: 0.35rem; }
        .op-chips { display: flex; gap: 0.4rem; flex-wrap: wrap; }
        .op-chip { padding: 0.18rem 0.55rem; background: var(--sand); border-radius: 100px; font-size: 0.66rem; color: var(--terra); }

        .order-rows { margin-bottom: 1rem; }
        .order-row { display: flex; justify-content: space-between; font-size: 0.82rem; padding: 0.4rem 0; color: rgba(61,35,20,0.6); border-bottom: 1px solid var(--sand); }
        .order-row:last-child { border-bottom: none; }
        .order-row strong { color: var(--espresso); }

        .order-total { display: flex; justify-content: space-between; align-items: center; padding: 1rem; background: var(--cream); border-radius: 2px; }
        .ot-label { font-size: 0.7rem; letter-spacing: 0.15em; text-transform: uppercase; color: var(--clay); }
        .ot-amount { font-family: 'Playfair Display', serif; font-size: 1.6rem; font-weight: 700; color: var(--terra); }
        .ot-amount span { font-family: 'DM Sans', sans-serif; font-size: 0.8rem; font-weight: 400; color: var(--clay); }

        .secure-note { display: flex; align-items: center; gap: 0.5rem; margin-top: 1rem; font-size: 0.72rem; color: rgba(61,35,20,0.4); }

        /* ── Payment form ── */
        .payment-card { background: var(--white); border: 1px solid var(--sand); border-radius: 2px; overflow: hidden; }
        .payment-card .pc-head { padding: 1.75rem 2rem 0; }
        .payment-card .pc-head h1 { font-family: 'Playfair Display', serif; font-size: 1.5rem; font-weight: 700; color: var(--espresso); margin-bottom: 0.35rem; }
        .payment-card .pc-head p { font-size: 0.82rem; color: rgba(61,35,20,0.45); }
        .payment-card .pc-body { padding: 1.75rem 2rem 2rem; }

        /* Method tabs */
        .method-tabs { display: flex; gap: 0.75rem; margin-bottom: 2rem; flex-wrap: wrap; }
        .method-tab { flex: 1; min-width: 100px; display: flex; flex-direction: column; align-items: center; gap: 0.5rem; padding: 1rem 0.75rem; border: 2px solid var(--sand); border-radius: 2px; cursor: pointer; transition: all 0.2s; background: var(--cream); text-align: center; }
        .method-tab:hover { border-color: var(--clay); }
        .method-tab.selected { border-color: var(--espresso); background: var(--white); }
        .method-tab input[type="radio"] { display: none; }
        .method-tab .mt-logo { font-size: 1.5rem; line-height: 1; }
        .method-tab .mt-name { font-size: 0.72rem; font-weight: 500; letter-spacing: 0.06em; text-transform: uppercase; color: var(--espresso); }
        .method-tab .mt-sub { font-size: 0.62rem; color: rgba(61,35,20,0.4); }
        .method-tab.selected .mt-name { color: var(--terra); }

        /* Color accents per method */
        .method-tab[data-method="baridi"].selected { border-color: #1a7a4a; }
        .method-tab[data-method="baridi"].selected .mt-name { color: #1a7a4a; }
        .method-tab[data-method="cib"].selected { border-color: #0057A8; }
        .method-tab[data-method="cib"].selected .mt-name { color: #0057A8; }
        .method-tab[data-method="dahabia"].selected { border-color: #C8960C; }
        .method-tab[data-method="dahabia"].selected .mt-name { color: #C8960C; }

        /* Form panels */
        .form-panel { display: none; }
        .form-panel.active { display: block; animation: fadeIn 0.25s ease; }
        @keyframes fadeIn { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }

        .form-group { margin-bottom: 1.25rem; }
        .form-group label { display: block; font-size: 0.7rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(61,35,20,0.5); margin-bottom: 0.5rem; font-weight: 500; }
        .form-group input { width: 100%; padding: 0.8rem 1rem; border: 1.5px solid var(--sand); border-radius: 2px; background: var(--cream); color: var(--espresso); font-family: 'DM Sans', sans-serif; font-size: 0.9rem; outline: none; transition: border-color 0.2s, box-shadow 0.2s; }
        .form-group input:focus { border-color: var(--terra); box-shadow: 0 0 0 3px rgba(139,94,60,0.08); background: var(--white); }
        .form-group input::placeholder { color: rgba(61,35,20,0.25); }

        .input-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }

        /* Card preview */
        .card-preview { background: linear-gradient(135deg, var(--espresso) 0%, var(--terra) 100%); border-radius: 8px; padding: 1.5rem; margin-bottom: 1.5rem; position: relative; overflow: hidden; min-height: 110px; }
        .card-preview::before { content: ''; position: absolute; top: -30px; right: -30px; width: 120px; height: 120px; border-radius: 50%; background: rgba(196,168,130,0.08); }
        .card-preview::after { content: ''; position: absolute; bottom: -20px; right: 20px; width: 80px; height: 80px; border-radius: 50%; background: rgba(196,168,130,0.05); }
        .cp-chip { width: 36px; height: 28px; background: var(--clay); border-radius: 4px; margin-bottom: 0.75rem; opacity: 0.8; }
        .cp-number { font-family: 'Playfair Display', serif; font-size: 1rem; color: rgba(253,250,246,0.8); letter-spacing: 0.2em; margin-bottom: 0.75rem; }
        .cp-bottom { display: flex; justify-content: space-between; align-items: flex-end; }
        .cp-label { font-size: 0.55rem; letter-spacing: 0.15em; text-transform: uppercase; color: rgba(196,168,130,0.5); margin-bottom: 0.15rem; }
        .cp-val { font-size: 0.82rem; color: rgba(253,250,246,0.7); letter-spacing: 0.08em; }
        .cp-brand { font-family: 'Playfair Display', serif; font-size: 1rem; color: var(--clay); font-style: italic; }

        /* BaridiMob panel */
        .baridi-info { background: #EBF7F0; border: 1px solid #A8DAC0; border-radius: 2px; padding: 1rem 1.25rem; margin-bottom: 1.5rem; font-size: 0.82rem; color: #1a5c38; line-height: 1.6; }
        .baridi-info strong { display: block; margin-bottom: 0.35rem; font-size: 0.9rem; }
        .baridi-steps { margin: 0; padding-left: 1.2rem; }
        .baridi-steps li { margin-bottom: 0.25rem; }

        /* Error */
        .error-box { display: flex; align-items: flex-start; gap: 0.75rem; padding: 0.9rem 1rem; background: #FDF0EF; border: 1px solid #F5C6C3; border-radius: 2px; margin-bottom: 1.5rem; font-size: 0.83rem; color: var(--error); line-height: 1.4; }

        /* Submit */
        .btn-pay { width: 100%; padding: 1rem; background: var(--espresso); color: var(--white); border: none; border-radius: 2px; font-family: 'DM Sans', sans-serif; font-size: 0.9rem; font-weight: 500; letter-spacing: 0.15em; text-transform: uppercase; cursor: pointer; position: relative; overflow: hidden; transition: transform 0.15s; margin-top: 0.5rem; }
        .btn-pay::before { content: ''; position: absolute; inset: 0; background: var(--terra); transform: translateX(-100%); transition: transform 0.35s ease; }
        .btn-pay:hover::before { transform: translateX(0); }
        .btn-pay span { position: relative; z-index: 1; }
        .btn-pay:active { transform: scale(0.98); }

        /* ── SUCCESS STATE ── */
        .success-wrap { max-width: 600px; margin: 5rem auto; padding: 0 2rem; text-align: center; }
        .success-icon { font-size: 4rem; margin-bottom: 1.5rem; animation: popIn 0.5s cubic-bezier(0.175,0.885,0.32,1.275) both; }
        @keyframes popIn { from { transform: scale(0); opacity: 0; } to { transform: scale(1); opacity: 1; } }
        .success-wrap h1 { font-family: 'Playfair Display', serif; font-size: 2rem; font-weight: 700; color: var(--espresso); margin-bottom: 0.75rem; }
        .success-wrap p { font-size: 0.9rem; color: rgba(61,35,20,0.55); line-height: 1.7; margin-bottom: 2rem; }
        .success-details { background: var(--white); border: 1px solid var(--sand); border-radius: 2px; padding: 1.5rem; margin-bottom: 2rem; text-align: left; }
        .sd-row { display: flex; justify-content: space-between; padding: 0.5rem 0; border-bottom: 1px solid var(--sand); font-size: 0.85rem; }
        .sd-row:last-child { border-bottom: none; }
        .sd-row .label { color: rgba(61,35,20,0.5); }
        .sd-row .val { font-weight: 500; color: var(--espresso); }
        .btn-rentals { display: inline-flex; align-items: center; gap: 0.5rem; padding: 0.85rem 2rem; background: var(--espresso); color: var(--white); border-radius: 2px; text-decoration: none; font-size: 0.85rem; font-weight: 500; letter-spacing: 0.12em; text-transform: uppercase; transition: background 0.2s; }
        .btn-rentals:hover { background: var(--terra); }

        .site-footer { background: var(--espresso); padding: 2rem 2.5rem; text-align: center; margin-top: 3rem; }
        .site-footer .footer-brand { font-family: 'Playfair Display', serif; font-size: 1.3rem; color: var(--white); margin-bottom: 0.3rem; }
        .site-footer p { font-size: 0.72rem; color: rgba(196,168,130,0.35); letter-spacing: 0.1em; }

        @media (max-width: 820px) { .page-wrap { grid-template-columns: 1fr; gap: 2rem; } .order-summary { position: static; } }
        @media (max-width: 540px) { .page-wrap { padding: 1.5rem 1.25rem 4rem; } .payment-card .pc-head, .payment-card .pc-body { padding-left: 1.25rem; padding-right: 1.25rem; } .input-row { grid-template-columns: 1fr; } .navbar { padding: 0 1.25rem; } .breadcrumb { padding: 0.75rem 1.25rem; } }
    </style>
</head>
<body>

<nav class="navbar">
    <a href="index.php" class="nav-brand">
        <span class="sublabel">Location Vêtements</span>
        <span class="wordmark">Élara</span>
    </a>
    <a href="my_rentals.php" class="nav-back">← Mes locations</a>
</nav>

<div class="breadcrumb">
    <div class="breadcrumb-inner">
        <a href="index.php">Accueil</a>
        <span class="sep">›</span>
        <a href="my_rentals.php">Mes locations</a>
        <span class="sep">›</span>
        <span>Paiement</span>
    </div>
</div>

<?php if ($success): ?>
<!-- ── SUCCESS ── -->
<div style="margin-top: var(--nav-h);">
    <div class="success-wrap">
        <div class="success-icon">✅</div>
        <h1>Paiement enregistré !</h1>
        <p>Votre demande de paiement a bien été reçue. Elle sera traitée par notre équipe dans les plus brefs délais. Votre réservation reste <strong>en attente</strong> jusqu'à validation finale.</p>

        <div class="success-details">
            <div class="sd-row">
                <span class="label">Article</span>
                <span class="val"><?php echo htmlspecialchars($rental['product_name']); ?></span>
            </div>
            <div class="sd-row">
                <span class="label">Taille</span>
                <span class="val"><?php echo htmlspecialchars($rental['size']); ?></span>
            </div>
            <div class="sd-row">
                <span class="label">Période</span>
                <span class="val"><?php echo date('d/m/Y', strtotime($rental['start_date'])); ?> → <?php echo date('d/m/Y', strtotime($rental['end_date'])); ?></span>
            </div>
            <div class="sd-row">
                <span class="label">Durée</span>
                <span class="val"><?php echo $days; ?> jour<?php echo $days > 1 ? 's' : ''; ?></span>
            </div>
            <div class="sd-row">
                <span class="label">Montant</span>
                <span class="val"><?php echo number_format((int)$rental['total_price'], 0, ',', ' '); ?> DA</span>
            </div>
            <div class="sd-row">
                <span class="label">Statut</span>
                <span class="val" style="color:#E67E22;">⏳ En attente de validation</span>
            </div>
        </div>

        <a href="my_rentals.php" class="btn-rentals">Voir mes locations →</a>
    </div>
</div>

<?php else: ?>
<!-- ── PAYMENT FORM ── -->
<div class="page-wrap">

    <!-- LEFT: Form -->
    <div class="payment-card">
        <div class="pc-head">
            <h1>Paiement</h1>
            <p>Choisissez votre moyen de paiement et remplissez vos informations.</p>
        </div>
        <div class="pc-body">

            <?php if ($error): ?>
            <div class="error-box">⚠ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" id="payForm">

                <!-- Method selector -->
                <div class="method-tabs" id="methodTabs">
                    <label class="method-tab <?php echo ($_POST['payment_method'] ?? '') === 'baridi' ? 'selected' : ''; ?>" data-method="baridi">
                        <input type="radio" name="payment_method" value="baridi" <?php echo ($_POST['payment_method'] ?? '') === 'baridi' ? 'checked' : ''; ?>>
                        <span class="mt-logo">📱</span>
                        <span class="mt-name">BaridiMob</span>
                        <span class="mt-sub">Paiement mobile</span>
                    </label>
                    <label class="method-tab <?php echo ($_POST['payment_method'] ?? '') === 'cib' ? 'selected' : ''; ?>" data-method="cib">
                        <input type="radio" name="payment_method" value="cib" <?php echo ($_POST['payment_method'] ?? '') === 'cib' ? 'checked' : ''; ?>>
                        <span class="mt-logo">💳</span>
                        <span class="mt-name">CIB</span>
                        <span class="mt-sub">Carte interbancaire</span>
                    </label>
                    <label class="method-tab <?php echo ($_POST['payment_method'] ?? '') === 'dahabia' ? 'selected' : ''; ?>" data-method="dahabia">
                        <input type="radio" name="payment_method" value="dahabia" <?php echo ($_POST['payment_method'] ?? '') === 'dahabia' ? 'checked' : ''; ?>>
                        <span class="mt-logo">🥇</span>
                        <span class="mt-name">Dahabia</span>
                        <span class="mt-sub">Carte de paiement</span>
                    </label>
                </div>

                <!-- BARIDI panel -->
                <div class="form-panel" id="panel-baridi">
                    <div class="baridi-info">
                        <strong>📱 Comment payer avec BaridiMob :</strong>
                        <ol class="baridi-steps">
                            <li>Ouvrez l'application <strong>BaridiMob</strong> sur votre téléphone.</li>
                            <li>Allez dans <strong>Paiement en ligne</strong> → <strong>Nouveau paiement</strong>.</li>
                            <li>Saisissez le montant : <strong><?php echo number_format((int)$rental['total_price'], 0, ',', ' '); ?> DA</strong>.</li>
                            <li>Confirmez et entrez votre numéro ci-dessous.</li>
                        </ol>
                    </div>
                    <div class="form-group">
                        <label>Votre numéro de téléphone (BaridiMob)</label>
                        <input type="tel" name="phone" placeholder="0x xx xx xx xx"
                               value="<?php echo htmlspecialchars($_POST['phone'] ?? ''); ?>">
                    </div>
                </div>

                <!-- CIB panel -->
                <div class="form-panel" id="panel-cib">
                    <div class="card-preview" id="cardPreview-cib">
                        <div class="cp-chip"></div>
                        <div class="cp-number" id="cib-num-display">•••• •••• •••• ••••</div>
                        <div class="cp-bottom">
                            <div>
                                <div class="cp-label">Titulaire</div>
                                <div class="cp-val" id="cib-name-display">VOTRE NOM</div>
                            </div>
                            <div>
                                <div class="cp-label">Expire</div>
                                <div class="cp-val" id="cib-exp-display">MM/AA</div>
                            </div>
                            <div class="cp-brand">CIB</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Numéro de carte</label>
                        <input type="text" name="card_number" id="cib_card" maxlength="19"
                               placeholder="0000 0000 0000 0000"
                               value="<?php echo htmlspecialchars($_POST['card_number'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Nom du titulaire</label>
                        <input type="text" name="card_name" id="cib_name" placeholder="TEL QU'IL APPARAÎT SUR LA CARTE"
                               style="text-transform:uppercase"
                               value="<?php echo htmlspecialchars($_POST['card_name'] ?? ''); ?>">
                    </div>
                    <div class="input-row">
                        <div class="form-group">
                            <label>Date d'expiration</label>
                            <input type="text" name="expiry" id="cib_exp" maxlength="5" placeholder="MM/AA"
                                   value="<?php echo htmlspecialchars($_POST['expiry'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Code CVV</label>
                            <input type="password" maxlength="3" placeholder="•••">
                        </div>
                    </div>
                </div>

                <!-- DAHABIA panel -->
                <div class="form-panel" id="panel-dahabia">
                    <div class="card-preview" id="cardPreview-dahabia" style="background: linear-gradient(135deg, #8B6914 0%, #C8960C 100%);">
                        <div class="cp-chip" style="background: #F5D98B;"></div>
                        <div class="cp-number" id="dah-num-display">•••• •••• •••• ••••</div>
                        <div class="cp-bottom">
                            <div>
                                <div class="cp-label">Titulaire</div>
                                <div class="cp-val" id="dah-name-display">VOTRE NOM</div>
                            </div>
                            <div>
                                <div class="cp-label">Expire</div>
                                <div class="cp-val" id="dah-exp-display">MM/AA</div>
                            </div>
                            <div class="cp-brand" style="color:#F5D98B;">Dahabia</div>
                        </div>
                    </div>
                    <div class="form-group">
                        <label>Numéro de carte Dahabia</label>
                        <input type="text" name="card_number" id="dah_card" maxlength="19"
                               placeholder="0000 0000 0000 0000"
                               value="<?php echo htmlspecialchars($_POST['card_number'] ?? ''); ?>">
                    </div>
                    <div class="form-group">
                        <label>Nom du titulaire</label>
                        <input type="text" name="card_name" id="dah_name" placeholder="TEL QU'IL APPARAÎT SUR LA CARTE"
                               style="text-transform:uppercase"
                               value="<?php echo htmlspecialchars($_POST['card_name'] ?? ''); ?>">
                    </div>
                    <div class="input-row">
                        <div class="form-group">
                            <label>Date d'expiration</label>
                            <input type="text" name="expiry" id="dah_exp" maxlength="5" placeholder="MM/AA"
                                   value="<?php echo htmlspecialchars($_POST['expiry'] ?? ''); ?>">
                        </div>
                        <div class="form-group">
                            <label>Code CVV</label>
                            <input type="password" maxlength="3" placeholder="•••">
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn-pay">
                    <span>🔒 Confirmer le paiement — <?php echo number_format((int)$rental['total_price'], 0, ',', ' '); ?> DA</span>
                </button>

            </form>
        </div>
    </div>

    <!-- RIGHT: Order summary -->
    <div class="order-summary">
        <div class="summary-card">
            <div class="sc-head">
                <h3>Récapitulatif</h3>
                <p>Réservation #<?php echo $rental_id; ?></p>
            </div>
            <div class="sc-body">
                <div class="order-product">
                    <div class="op-img">
                        <?php if (!empty($rental['product_image'])): ?>
                        <img src="uploads/<?php echo htmlspecialchars($rental['product_image']); ?>"
                             alt="<?php echo htmlspecialchars($rental['product_name']); ?>">
                        <?php endif; ?>
                    </div>
                    <div class="op-info">
                        <div class="op-cat"><?php echo htmlspecialchars($rental['category'] ?? ''); ?></div>
                        <div class="op-name"><?php echo htmlspecialchars($rental['product_name']); ?></div>
                        <div class="op-chips">
                            <span class="op-chip">Taille <?php echo htmlspecialchars($rental['size']); ?></span>
                        </div>
                    </div>
                </div>
                <div class="order-rows">
                    <div class="order-row">
                        <span>Date de début</span>
                        <strong><?php echo date('d/m/Y', strtotime($rental['start_date'])); ?></strong>
                    </div>
                    <div class="order-row">
                        <span>Date de fin</span>
                        <strong><?php echo date('d/m/Y', strtotime($rental['end_date'])); ?></strong>
                    </div>
                    <div class="order-row">
                        <span>Durée</span>
                        <strong><?php echo $days; ?> jour<?php echo $days > 1 ? 's' : ''; ?></strong>
                    </div>
                    <div class="order-row">
                        <span>Prix / jour</span>
                        <strong><?php echo number_format(round($rental['total_price'] / $days), 0, ',', ' '); ?> DA</strong>
                    </div>
                </div>
                <div class="order-total">
                    <div>
                        <div class="ot-label">Total</div>
                    </div>
                    <div class="ot-amount"><?php echo number_format((int)$rental['total_price'], 0, ',', ' '); ?> <span>DA</span></div>
                </div>
                <div class="secure-note">🔒 Paiement sécurisé — vos données sont protégées</div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<footer class="site-footer">
    <div class="footer-brand">Élara</div>
    <p>© <?php echo date('Y'); ?> Élara · Location de Vêtements · Tous droits réservés</p>
</footer>

<script>
// Method tab selection
const tabs = document.querySelectorAll('.method-tab');
const panels = document.querySelectorAll('.form-panel');

function activateMethod(method) {
    tabs.forEach(t => t.classList.toggle('selected', t.dataset.method === method));
    panels.forEach(p => p.classList.toggle('active', p.id === 'panel-' + method));
}

tabs.forEach(tab => {
    tab.addEventListener('click', () => {
        const radio = tab.querySelector('input[type="radio"]');
        radio.checked = true;
        activateMethod(tab.dataset.method);
    });
});

// Init from PHP post-back or default first
const checked = document.querySelector('input[name="payment_method"]:checked');
if (checked) activateMethod(checked.value);
else activateMethod('baridi');

// Card number formatting
function formatCard(input) {
    input.addEventListener('input', () => {
        let v = input.value.replace(/\D/g,'').slice(0,16);
        input.value = v.replace(/(.{4})/g,'$1 ').trim();
    });
}
['cib_card','dah_card'].forEach(id => {
    const el = document.getElementById(id);
    if (el) formatCard(el);
});

// Expiry formatting
function formatExpiry(input) {
    input.addEventListener('input', () => {
        let v = input.value.replace(/\D/g,'');
        if (v.length >= 2) v = v.slice(0,2) + '/' + v.slice(2,4);
        input.value = v;
    });
}
['cib_exp','dah_exp'].forEach(id => {
    const el = document.getElementById(id);
    if (el) formatExpiry(el);
});

// Live card preview update
function bindPreview(cardInput, nameInput, expInput, numDisplay, nameDisplay, expDisplay) {
    if (cardInput) cardInput.addEventListener('input', () => {
        numDisplay.textContent = (cardInput.value || '•••• •••• •••• ••••').padEnd(19, '•').slice(0,19) || '•••• •••• •••• ••••';
    });
    if (nameInput) nameInput.addEventListener('input', () => {
        nameDisplay.textContent = nameInput.value.toUpperCase() || 'VOTRE NOM';
    });
    if (expInput) expInput.addEventListener('input', () => {
        expDisplay.textContent = expInput.value || 'MM/AA';
    });
}

bindPreview(
    document.getElementById('cib_card'),
    document.getElementById('cib_name'),
    document.getElementById('cib_exp'),
    document.getElementById('cib-num-display'),
    document.getElementById('cib-name-display'),
    document.getElementById('cib-exp-display')
);
bindPreview(
    document.getElementById('dah_card'),
    document.getElementById('dah_name'),
    document.getElementById('dah_exp'),
    document.getElementById('dah-num-display'),
    document.getElementById('dah-name-display'),
    document.getElementById('dah-exp-display')
);
</script>
</body>
</html>