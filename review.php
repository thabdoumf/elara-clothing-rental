<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'user') {
    header("Location: login.php"); exit();
}

$user_id   = (int)$_SESSION['user_id'];
$rental_id = (int)($_GET['rental_id'] ?? 0);

if ($rental_id <= 0) { header("Location: my_rentals.php"); exit(); }

// Load rental — must belong to this user AND be returned/approved
$stmt = $conn->prepare("
    SELECT r.*, p.name AS product_name, p.image AS product_image, p.category, p.id AS product_id
    FROM rentals r
    JOIN products p ON p.id = r.product_id
    WHERE r.id = ? AND r.user_id = ? AND r.status IN ('returned','approved')
");
$stmt->bind_param("ii", $rental_id, $user_id);
$stmt->execute();
$rental = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rental) { header("Location: my_rentals.php"); exit(); }

// Check if already reviewed
$chk = $conn->prepare("SELECT id FROM reviews WHERE rental_id = ? AND user_id = ?");
$chk->bind_param("ii", $rental_id, $user_id);
$chk->execute();
$chk->store_result();
$alreadyReviewed = $chk->num_rows > 0;
$chk->close();

$error   = '';
$success = false;

if (!$alreadyReviewed && isset($_POST['submit_review'])) {
    $rating  = (int)($_POST['rating'] ?? 0);
    $comment = trim($_POST['comment'] ?? '');

    if ($rating < 1 || $rating > 5) {
        $error = "Veuillez sélectionner une note entre 1 et 5 étoiles.";
    } elseif (empty($comment)) {
        $error = "Veuillez écrire un commentaire.";
    } elseif (strlen($comment) < 10) {
        $error = "Votre commentaire doit contenir au moins 10 caractères.";
    } else {
        $stmt = $conn->prepare("
            INSERT INTO reviews (product_id, user_id, rental_id, rating, comment)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->bind_param("iiiis", $rental['product_id'], $user_id, $rental_id, $rating, $comment);
        $stmt->execute();
        $stmt->close();
        $success = true;
    }
}

$userName = $_SESSION['user_name'] ?? '';
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laisser un avis — Élara</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        :root {
            --cream:#F5F0E8; --sand:#E8DDD0; --clay:#C4A882;
            --terra:#8B5E3C; --espresso:#3D2314; --white:#FDFAF6;
            --error:#C0392B; --success:#27AE60; --star:#F4C542; --nav-h:72px;
        }
        body { font-family:'DM Sans',sans-serif; background:var(--cream); color:var(--espresso); min-height:100vh; }

        .navbar { position:fixed; top:0; left:0; right:0; z-index:100; height:var(--nav-h); background:var(--espresso); display:flex; align-items:center; justify-content:space-between; padding:0 2.5rem; box-shadow:0 2px 30px rgba(0,0,0,0.3); }
        .nav-brand { display:flex; flex-direction:column; text-decoration:none; }
        .nav-brand .sublabel { font-size:0.58rem; letter-spacing:0.28em; text-transform:uppercase; color:var(--clay); }
        .nav-brand .wordmark { font-family:'Playfair Display',serif; font-size:1.6rem; font-weight:700; color:var(--white); line-height:1; }
        .nav-back { color:rgba(253,250,246,0.55); text-decoration:none; font-size:0.8rem; letter-spacing:0.08em; text-transform:uppercase; transition:color 0.2s; }
        .nav-back:hover { color:var(--clay); }

        .breadcrumb { margin-top:var(--nav-h); background:var(--white); border-bottom:1px solid var(--sand); padding:0.75rem 2.5rem; }
        .breadcrumb-inner { max-width:760px; margin:0 auto; display:flex; align-items:center; gap:0.5rem; font-size:0.78rem; color:rgba(61,35,20,0.4); }
        .breadcrumb-inner a { color:var(--terra); text-decoration:none; }
        .breadcrumb-inner .sep { color:rgba(61,35,20,0.2); }

        .page-wrap { max-width:760px; margin:0 auto; padding:3rem 2rem 6rem; }

        /* Product recap */
        .product-recap {
            display:flex; gap:1.25rem; align-items:center;
            background:var(--white); border:1px solid var(--sand);
            border-radius:2px; padding:1.25rem; margin-bottom:2rem;
        }
        .recap-img { width:72px; aspect-ratio:3/4; border-radius:2px; overflow:hidden; flex-shrink:0; background:var(--sand); }
        .recap-img img { width:100%; height:100%; object-fit:cover; display:block; }
        .recap-info .cat { font-size:0.62rem; letter-spacing:0.18em; text-transform:uppercase; color:var(--clay); margin-bottom:0.25rem; }
        .recap-info h3 { font-family:'Playfair Display',serif; font-size:1.1rem; font-weight:600; color:var(--espresso); margin-bottom:0.3rem; }
        .recap-info .dates { font-size:0.78rem; color:rgba(61,35,20,0.5); }

        /* Review card */
        .review-card { background:var(--white); border:1px solid var(--sand); border-radius:2px; overflow:hidden; }
        .review-card .rc-head { background:var(--espresso); padding:1.75rem 2rem; }
        .review-card .rc-head h1 { font-family:'Playfair Display',serif; font-size:1.5rem; color:var(--white); margin-bottom:0.25rem; }
        .review-card .rc-head p { font-size:0.82rem; color:var(--clay); }
        .review-card .rc-body { padding:2rem; }

        .alert { padding:0.85rem 1rem; border-radius:2px; margin-bottom:1.5rem; font-size:0.85rem; font-weight:500; }
        .alert-error   { background:#FCF0EF; border-left:3px solid var(--error); color:var(--error); }
        .alert-warning { background:#FEF9EC; border-left:3px solid #E67E22; color:#7a4a00; }

        /* Star rating */
        .star-section { margin-bottom:1.75rem; }
        .star-label { font-size:0.7rem; font-weight:500; letter-spacing:0.15em; text-transform:uppercase; color:var(--terra); margin-bottom:0.75rem; display:block; }
        .star-group { display:flex; gap:0.35rem; flex-direction:row-reverse; justify-content:flex-end; }
        .star-group input { display:none; }
        .star-group label {
            font-size:2.2rem; cursor:pointer; color:var(--sand);
            transition:color 0.15s, transform 0.15s;
            line-height:1;
        }
        .star-group label:hover,
        .star-group label:hover ~ label,
        .star-group input:checked ~ label { color:var(--star); }
        .star-group label:hover { transform:scale(1.15); }

        .star-hint { font-size:0.75rem; color:rgba(61,35,20,0.4); margin-top:0.5rem; min-height:1em; font-style:italic; }

        /* Comment */
        .comment-section { margin-bottom:1.75rem; }
        .comment-label { font-size:0.7rem; font-weight:500; letter-spacing:0.15em; text-transform:uppercase; color:var(--terra); margin-bottom:0.5rem; display:block; }
        textarea {
            width:100%; min-height:130px; padding:0.9rem 1rem;
            border:1.5px solid var(--sand); border-radius:2px;
            background:var(--cream); color:var(--espresso);
            font-family:'DM Sans',sans-serif; font-size:0.9rem;
            resize:vertical; outline:none;
            transition:border-color 0.2s, box-shadow 0.2s;
        }
        textarea:focus { border-color:var(--terra); box-shadow:0 0 0 3px rgba(139,94,60,0.08); background:var(--white); }
        textarea::placeholder { color:rgba(61,35,20,0.3); }
        .char-count { font-size:0.72rem; color:rgba(61,35,20,0.4); margin-top:0.35rem; text-align:right; }

        /* Submit */
        .btn-submit {
            width:100%; padding:0.95rem; background:var(--espresso); color:var(--white);
            border:none; border-radius:2px; font-family:'DM Sans',sans-serif;
            font-size:0.875rem; font-weight:500; letter-spacing:0.14em; text-transform:uppercase;
            cursor:pointer; position:relative; overflow:hidden; transition:transform 0.15s;
        }
        .btn-submit::before { content:''; position:absolute; inset:0; background:var(--terra); transform:translateX(-100%); transition:transform 0.35s; }
        .btn-submit:hover::before { transform:translateX(0); }
        .btn-submit span { position:relative; z-index:1; }
        .btn-submit:active { transform:scale(0.98); }

        /* Success */
        .success-wrap { text-align:center; padding:3rem 2rem; }
        .s-icon { font-size:3.5rem; margin-bottom:1.25rem; animation:popIn 0.5s cubic-bezier(0.175,0.885,0.32,1.275) both; }
        @keyframes popIn { from{transform:scale(0);opacity:0} to{transform:scale(1);opacity:1} }
        .success-wrap h2 { font-family:'Playfair Display',serif; font-size:1.6rem; color:var(--espresso); margin-bottom:0.75rem; }
        .success-wrap p { font-size:0.88rem; color:rgba(61,35,20,0.5); line-height:1.7; margin-bottom:2rem; }
        .btn-back { display:inline-flex; align-items:center; gap:0.5rem; padding:0.85rem 2rem; background:var(--espresso); color:var(--white); border-radius:2px; text-decoration:none; font-size:0.85rem; font-weight:500; letter-spacing:0.12em; text-transform:uppercase; transition:background 0.2s; }
        .btn-back:hover { background:var(--terra); }

        /* Already reviewed */
        .already-box { background:var(--white); border:1px solid var(--sand); border-radius:2px; padding:2.5rem; text-align:center; }
        .already-box .icon { font-size:2.5rem; margin-bottom:1rem; }
        .already-box h2 { font-family:'Playfair Display',serif; font-size:1.3rem; color:var(--espresso); margin-bottom:0.5rem; }
        .already-box p { font-size:0.85rem; color:rgba(61,35,20,0.5); margin-bottom:1.5rem; }

        .site-footer { background:var(--espresso); padding:2rem 2.5rem; text-align:center; margin-top:3rem; }
        .footer-brand { font-family:'Playfair Display',serif; font-size:1.3rem; color:var(--white); margin-bottom:0.25rem; }
        .site-footer p { font-size:0.72rem; color:rgba(196,168,130,0.35); }

        @media(max-width:600px) { .page-wrap{padding:1.5rem 1.25rem 4rem;} .review-card .rc-body{padding:1.5rem;} .navbar{padding:0 1.25rem;} }
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
        <span>Laisser un avis</span>
    </div>
</div>

<div class="page-wrap">

    <!-- Product recap -->
    <div class="product-recap">
        <div class="recap-img">
            <?php if (!empty($rental['product_image'])): ?>
            <img src="uploads/<?php echo htmlspecialchars($rental['product_image']); ?>" alt="">
            <?php endif; ?>
        </div>
        <div class="recap-info">
            <div class="cat"><?php echo htmlspecialchars($rental['category'] ?? ''); ?></div>
            <h3><?php echo htmlspecialchars($rental['product_name']); ?></h3>
            <div class="dates">
                📅 <?php echo date('d/m/Y', strtotime($rental['start_date'])); ?>
                → <?php echo date('d/m/Y', strtotime($rental['end_date'])); ?>
                · Taille <?php echo htmlspecialchars($rental['size']); ?>
            </div>
        </div>
    </div>

    <?php if ($alreadyReviewed && !$success): ?>
    <!-- Already reviewed -->
    <div class="already-box">
        <div class="icon">⭐</div>
        <h2>Avis déjà publié</h2>
        <p>Vous avez déjà laissé un avis pour cette location. Merci pour votre retour !</p>
        <a href="my_rentals.php" class="btn-back">← Retour à mes locations</a>
    </div>

    <?php elseif ($success): ?>
    <!-- Success -->
    <div class="review-card">
        <div class="rc-body">
            <div class="success-wrap">
                <div class="s-icon">🌟</div>
                <h2>Merci pour votre avis !</h2>
                <p>Votre commentaire a été publié avec succès. Il aide les autres clients à faire leur choix.</p>
                <a href="my_rentals.php" class="btn-back">← Retour à mes locations</a>
            </div>
        </div>
    </div>

    <?php else: ?>
    <!-- Review form -->
    <div class="review-card">
        <div class="rc-head">
            <h1>Votre avis</h1>
            <p>Partagez votre expérience avec cet article</p>
        </div>
        <div class="rc-body">

            <?php if ($error): ?>
            <div class="alert alert-error">⚠ <?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" novalidate>

                <!-- Star rating -->
                <div class="star-section">
                    <span class="star-label">Note globale *</span>
                    <div class="star-group">
                        <?php for ($i = 5; $i >= 1; $i--): ?>
                        <input type="radio" name="rating" id="star<?php echo $i; ?>" value="<?php echo $i; ?>"
                               <?php echo (isset($_POST['rating']) && (int)$_POST['rating'] === $i) ? 'checked' : ''; ?>>
                        <label for="star<?php echo $i; ?>" title="<?php echo $i; ?> étoile<?php echo $i>1?'s':''; ?>">★</label>
                        <?php endfor; ?>
                    </div>
                    <div class="star-hint" id="starHint">Cliquez pour noter</div>
                </div>

                <!-- Comment -->
                <div class="comment-section">
                    <span class="comment-label">Votre commentaire *</span>
                    <textarea name="comment" id="commentArea" maxlength="500"
                              placeholder="Décrivez votre expérience avec cet article — qualité, taille, confort..."
                              oninput="updateCount(this)"><?php echo isset($_POST['comment']) ? htmlspecialchars($_POST['comment']) : ''; ?></textarea>
                    <div class="char-count"><span id="charNum"><?php echo strlen($_POST['comment'] ?? ''); ?></span>/500</div>
                </div>

                <button type="submit" name="submit_review" class="btn-submit">
                    <span>✦ Publier mon avis</span>
                </button>

            </form>
        </div>
    </div>
    <?php endif; ?>

</div>

<footer class="site-footer">
    <div class="footer-brand">Élara</div>
    <p>© <?php echo date('Y'); ?> Élara · Location de Vêtements · Tous droits réservés</p>
</footer>

<script>
const hints = ['','Très mauvais','Décevant','Correct','Bien','Excellent !'];
document.querySelectorAll('.star-group input').forEach(input => {
    input.addEventListener('change', () => {
        document.getElementById('starHint').textContent = hints[input.value] || '';
    });
});
// Init hint if already selected
const checked = document.querySelector('.star-group input:checked');
if (checked) document.getElementById('starHint').textContent = hints[checked.value];

function updateCount(el) {
    document.getElementById('charNum').textContent = el.value.length;
}
</script>
</body>
</html>