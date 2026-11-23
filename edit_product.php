<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$adminName = $_SESSION['user_name'] ?? 'Admin';
$id        = (int)($_GET['id'] ?? 0);
$error     = "";

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

$stmt = $conn->prepare("SELECT * FROM products WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: dashboard.php");
    exit();
}

if (isset($_POST['update'])) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid request token.'); }

    $name     = trim($_POST['name']);
    $desc     = trim($_POST['description']);
    $price    = (float)$_POST['price'];
    $category = trim($_POST['category']);
    $gender   = trim($_POST['gender']);
    $allowed_genders = ['Men', 'Women', 'Unisex'];

    if (empty($name) || $price <= 0) {
        $error = "Nom et prix sont obligatoires.";
    } elseif (!in_array($gender, $allowed_genders)) {
        $error = "Genre invalide.";
    } else {

        $stmt = $conn->prepare("
            UPDATE products
            SET name=?, description=?, price_per_day=?, category=?, gender=?
            WHERE id=?
        ");
        $stmt->bind_param("ssdssi", $name, $desc, $price, $category, $gender, $id);
        $stmt->execute();
        $stmt->close();

        // Update product variable for display after save
        $product['name']          = $name;
        $product['description']   = $desc;
        $product['price_per_day'] = $price;
        $product['category']      = $category;
        $product['gender']        = $gender;

        if (isset($_FILES['image']) && ($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $ext = strtolower(pathinfo($_FILES['image']['name'] ?? '', PATHINFO_EXTENSION));
            $allowed = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
            $tmp = $_FILES['image']['tmp_name'] ?? '';
            $validUpload = $tmp !== '' && is_uploaded_file($tmp);
            $mime = $validUpload ? (new finfo(FILEINFO_MIME_TYPE))->file($tmp) : '';
            $imageInfo = $validUpload ? @getimagesize($tmp) : false;
            if (($_FILES['image']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK ||
                ($_FILES['image']['size'] ?? 0) > 5242880 ||
                !isset($allowed[$ext]) || $mime !== ($allowed[$ext] ?? '') || !$imageInfo) {
                $error = "Image invalide. JPG, PNG ou WEBP, 5 Mo maximum.";
            } else {
                $safeImage = bin2hex(random_bytes(16)) . '.' . $ext;
                if (!move_uploaded_file($tmp, __DIR__ . "/uploads/" . $safeImage)) {
                    $error = "Impossible d'enregistrer l'image.";
                } else {
                    $imgStmt = $conn->prepare("UPDATE products SET image=? WHERE id=?");
                    $imgStmt->bind_param("si", $safeImage, $id);
                    $imgStmt->execute();
                    $imgStmt->close();
                    $product['image'] = $safeImage;
                }
            }
        }

        $checkStmt  = $conn->prepare("SELECT id FROM product_sizes WHERE product_id=? AND size=?");
        $updateStmt = $conn->prepare("UPDATE product_sizes SET stock=? WHERE product_id=? AND size=?");
        $insertStmt = $conn->prepare("INSERT INTO product_sizes(product_id,size,stock) VALUES(?,?,?)");

        foreach (['S', 'M', 'L', 'XL'] as $sz) {
            $stock = max(0, (int)($_POST['stock_' . $sz] ?? 0));
            $checkStmt->bind_param("is", $id, $sz);
            $checkStmt->execute();
            $checkStmt->store_result();
            if ($checkStmt->num_rows > 0) {
                $updateStmt->bind_param("iis", $stock, $id, $sz);
                $updateStmt->execute();
            } else {
                $insertStmt->bind_param("isi", $id, $sz, $stock);
                $insertStmt->execute();
            }
            $checkStmt->free_result();
        }

        $checkStmt->close();
        $updateStmt->close();
        $insertStmt->close();

        header("Location: dashboard.php");
        exit();
    }
}

/* Fetch current sizes */
$sizeData = [];
$sStmt = $conn->prepare("SELECT size, stock FROM product_sizes WHERE product_id=?");
$sStmt->bind_param("i", $id);
$sStmt->execute();
$sRes = $sStmt->get_result();
while ($row = $sRes->fetch_assoc()) $sizeData[$row['size']] = $row['stock'];
$sStmt->close();

/* Categories */
$categories = $conn->query("SELECT DISTINCT category FROM products ORDER BY category");
$catList = [];
while ($c = $categories->fetch_assoc()) $catList[] = $c['category'];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifier le produit — Élara</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,600;0,700;1,400&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        :root {
            --cream:        #F5F0E8;
            --sand:         #E8DDD0;
            --clay:         #C4A882;
            --terra:        #8B5E3C;
            --espresso:     #3D2314;
            --dark:         #1A0F08;
            --white:        #FDFAF6;
            --error:        #C0392B;
            --success:      #27AE60;
            --nav-h:        68px;
            --panel-bg:     #22110A;
            --panel-card:   #2C160D;
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
        .topnav-right .btn-add { background: var(--clay); color: var(--espresso) !important; font-weight: 500; }
        .topnav-right .btn-add:hover { background: var(--terra); color: var(--white) !important; }
        .topnav-right .btn-logout { border: 1px solid rgba(196,168,130,0.2); color: rgba(196,168,130,0.6) !important; }
        .topnav-right .btn-logout:hover { border-color: var(--clay); color: var(--clay) !important; }
        .admin-greeting { font-size: 0.74rem; color: rgba(196,168,130,0.5); font-style: italic; font-family: 'Playfair Display', serif; padding: 0 0.5rem; }

        /* ══ LAYOUT ══ */
        .layout { display: block; min-height: 100vh; padding-top: var(--nav-h); }

        /* ══ SIDEBAR ══ */
        .sidebar {
            position: fixed; top: var(--nav-h); left: 0; bottom: 0; width: 220px;
            background: var(--dark);
            border-right: 1px solid var(--panel-border);
            padding: 1.5rem 0; overflow-y: auto; z-index: 100;
        }
        .sidebar-section { padding: 0 1rem 1rem; }
        .sidebar-label { font-size: 0.6rem; letter-spacing: 0.25em; text-transform: uppercase; color: rgba(196,168,130,0.35); padding: 0 0.75rem 0.5rem; }
        .sidebar-link {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.65rem 0.75rem; border-radius: 2px;
            color: rgba(253,250,246,0.45); text-decoration: none;
            font-size: 0.82rem; letter-spacing: 0.04em;
            transition: color 0.2s, background 0.2s; margin-bottom: 0.1rem;
        }
        .sidebar-link .sl-icon { font-size: 1rem; width: 20px; text-align: center; }
        .sidebar-link:hover { color: var(--white); background: rgba(255,255,255,0.04); }
        .sidebar-link.active { color: var(--clay); background: rgba(196,168,130,0.08); border-left: 2px solid var(--clay); }
        .sidebar-divider { height: 1px; background: var(--panel-border); margin: 0.75rem 1rem; }

        /* ══ MAIN ══ */
        .main { margin-left: 220px; padding: 2rem 2.5rem; min-width: 0; overflow-x: hidden; }

        /* ══ PAGE HEADER ══ */
        .page-header { margin-bottom: 2rem; display: flex; align-items: flex-start; justify-content: space-between; gap: 1rem; flex-wrap: wrap; }
        .page-header h1 { font-family: 'Playfair Display', serif; font-size: 2rem; font-weight: 700; color: var(--white); line-height: 1.15; }
        .page-header h1 em { font-style: italic; color: var(--clay); }
        .page-header .sub { font-size: 0.82rem; color: rgba(196,168,130,0.5); margin-top: 0.3rem; }

        /* ══ TWO-COL LAYOUT ══ */
        .edit-layout { display: grid; grid-template-columns: 1fr 260px; gap: 1.5rem; align-items: start; max-width: 1000px; }

        /* ══ FORM CARD ══ */
        .form-card {
            background: var(--panel-card);
            border: 1px solid var(--panel-border);
            border-radius: 2px; padding: 2rem;
            animation: fadeUp 0.4s ease both;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ══ PRODUCT PREVIEW CARD ══ */
        .preview-card {
            background: var(--panel-card);
            border: 1px solid var(--panel-border);
            border-radius: 2px;
            animation: fadeUp 0.4s ease 0.1s both;
            overflow: hidden;
            position: sticky; top: calc(var(--nav-h) + 1.5rem);
        }
        .preview-card .preview-label {
            font-size: 0.58rem; letter-spacing: 0.22em; text-transform: uppercase;
            color: rgba(196,168,130,0.35); padding: 0.85rem 1rem 0.6rem;
            border-bottom: 1px solid var(--panel-border);
        }
        .preview-img-wrap {
            width: 100%; aspect-ratio: 3/4; background: rgba(196,168,130,0.05);
            display: flex; align-items: center; justify-content: center; overflow: hidden;
        }
        .preview-img-wrap img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .preview-img-wrap .no-img { font-size: 3rem; opacity: 0.15; }
        .preview-info { padding: 1rem; }
        .preview-name { font-family: 'Playfair Display', serif; font-size: 1rem; font-weight: 600; color: var(--white); margin-bottom: 0.25rem; }
        .preview-cat  { font-size: 0.7rem; color: rgba(196,168,130,0.45); text-transform: uppercase; letter-spacing: 0.1em; }
        .preview-price { font-family: 'Playfair Display', serif; font-size: 1.2rem; color: var(--clay); margin-top: 0.5rem; }
        .preview-price span { font-family: 'DM Sans', sans-serif; font-size: 0.65rem; color: rgba(196,168,130,0.4); }

        /* ══ FORM SECTIONS ══ */
        .form-section { margin-bottom: 1.75rem; }
        .form-section-label {
            font-size: 0.6rem; letter-spacing: 0.25em; text-transform: uppercase;
            color: rgba(196,168,130,0.4); margin-bottom: 1.1rem;
            padding-bottom: 0.55rem; border-bottom: 1px solid var(--panel-border);
        }
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.1rem; }

        label {
            font-size: 0.68rem; letter-spacing: 0.12em; text-transform: uppercase;
            color: rgba(196,168,130,0.55); font-weight: 500;
        }

        input[type="text"],
        input[type="number"],
        textarea,
        select {
            background: rgba(196,168,130,0.05);
            border: 1px solid var(--panel-border);
            border-radius: 2px; color: var(--white);
            font-family: 'DM Sans', sans-serif; font-size: 0.88rem;
            padding: 0.7rem 0.9rem;
            transition: border-color 0.2s, background 0.2s;
            outline: none; width: 100%;
        }
        input[type="text"]:focus,
        input[type="number"]:focus,
        textarea:focus,
        select:focus {
            border-color: rgba(196,168,130,0.4);
            background: rgba(196,168,130,0.08);
        }
        input::placeholder, textarea::placeholder { color: rgba(196,168,130,0.2); }
        textarea { resize: vertical; min-height: 80px; }
        select option { background: var(--panel-card); color: var(--white); }

        /* ══ FILE INPUT ══ */
        input[type="file"] {
            background: rgba(196,168,130,0.04);
            border: 1px dashed rgba(196,168,130,0.2);
            border-radius: 2px; color: rgba(196,168,130,0.5);
            font-family: 'DM Sans', sans-serif; font-size: 0.82rem;
            padding: 0.9rem 1rem; width: 100%; cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        input[type="file"]:hover { border-color: rgba(196,168,130,0.35); background: rgba(196,168,130,0.07); }
        .file-hint { font-size: 0.65rem; color: rgba(196,168,130,0.3); margin-top: 0.3rem; }

        /* ══ CURRENT IMAGE THUMB ══ */
        .current-img {
            display: flex; align-items: center; gap: 0.75rem;
            background: rgba(196,168,130,0.04); border: 1px solid var(--panel-border);
            border-radius: 2px; padding: 0.6rem 0.8rem; margin-bottom: 0.6rem;
        }
        .current-img img { width: 36px; height: 46px; object-fit: cover; border-radius: 1px; }
        .current-img-label { font-size: 0.7rem; color: rgba(196,168,130,0.45); }
        .current-img-name  { font-size: 0.75rem; color: rgba(253,250,246,0.6); margin-top: 0.1rem; }

        /* ══ GENDER TOGGLE ══ */
        .gender-toggle { display: flex; gap: 0.5rem; }
        .gender-option { display: none; }
        .gender-label {
            flex: 1; text-align: center; padding: 0.65rem 1rem;
            border: 1px solid var(--panel-border); border-radius: 2px;
            font-size: 0.75rem; letter-spacing: 0.08em; text-transform: uppercase;
            color: rgba(253,250,246,0.4); cursor: pointer; transition: all 0.2s;
        }
        .gender-option:checked + .gender-label { background: var(--espresso); border-color: var(--clay); color: var(--clay); }
        .gender-label:hover { color: var(--white); background: rgba(255,255,255,0.04); }

        /* ══ STOCK GRID ══ */
        .size-stock-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 0.85rem; }
        .size-stock-item {
            background: rgba(196,168,130,0.04); border: 1px solid var(--panel-border);
            border-radius: 2px; padding: 0.85rem 0.5rem;
            display: flex; flex-direction: column; align-items: center; gap: 0.5rem;
            transition: border-color 0.2s;
        }
        .size-stock-item:hover { border-color: rgba(196,168,130,0.25); }
        .size-badge { font-family: 'Playfair Display', serif; font-size: 1.05rem; font-weight: 700; color: var(--clay); }
        .size-stock-item input[type="number"] { text-align: center; font-size: 1rem; padding: 0.45rem; font-weight: 500; }

        /* ══ ALERT ══ */
        .alert {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.85rem 1.1rem; border-radius: 2px; font-size: 0.82rem; margin-bottom: 1.5rem;
        }
        .alert-error { background: rgba(192,57,43,0.1); border: 1px solid rgba(192,57,43,0.25); color: #E07060; }

        /* ══ SUBMIT ROW ══ */
        .submit-row {
            display: flex; align-items: center; gap: 1rem;
            padding-top: 1.25rem; border-top: 1px solid var(--panel-border); margin-top: 0.25rem;
        }
        .btn-submit {
            background: var(--clay); color: var(--espresso);
            border: none; border-radius: 2px;
            font-family: 'DM Sans', sans-serif; font-size: 0.8rem; font-weight: 500;
            letter-spacing: 0.1em; text-transform: uppercase;
            padding: 0.75rem 2rem; cursor: pointer; transition: background 0.2s, color 0.2s;
        }
        .btn-submit:hover { background: var(--terra); color: var(--white); }
        .btn-cancel { color: rgba(196,168,130,0.45); text-decoration: none; font-size: 0.78rem; letter-spacing: 0.08em; text-transform: uppercase; transition: color 0.2s; }
        .btn-cancel:hover { color: var(--clay); }
        .btn-delete-product {
            margin-left: auto;
            color: rgba(192,57,43,0.5); text-decoration: none;
            font-size: 0.72rem; letter-spacing: 0.08em; text-transform: uppercase;
            border: 1px solid rgba(192,57,43,0.2); padding: 0.45rem 0.9rem;
            border-radius: 2px; transition: all 0.2s;
        }
        .btn-delete-product:hover { background: rgba(192,57,43,0.12); color: #E07060; border-color: rgba(192,57,43,0.4); }

        /* ══ RESPONSIVE ══ */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; }
            .edit-layout { grid-template-columns: 1fr; }
            .preview-card { position: static; }
        }
        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
            .size-stock-grid { grid-template-columns: repeat(2, 1fr); }
            .main { padding: 1.25rem; }
            .form-card { padding: 1.25rem; }
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
            <a href="dashboard.php" class="sidebar-link">
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
        <div class="sidebar-section">
            <p class="sidebar-label">En cours d'édition</p>
            <div class="sidebar-link active" style="cursor:default;">
                <span class="sl-icon">✏</span>
                <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                    <?php echo htmlspecialchars($product['name']); ?>
                </span>
            </div>
        </div>
        <div class="sidebar-divider"></div>
        <div class="sidebar-section">
            <p class="sidebar-label">Accès rapide</p>
            <a href="dashboard.php?status=pending" class="sidebar-link">
                <span class="sl-icon">🕐</span> En attente
            </a>
            <a href="dashboard.php?status=approved" class="sidebar-link">
                <span class="sl-icon">✓</span> Approuvées
            </a>
        </div>
    </aside>

    <!-- MAIN -->
    <main class="main">

        <div class="page-header">
            <div>
                <h1>Modifier le <em>Produit</em></h1>
                <p class="sub">ID #<?php echo $id; ?> · Dernière modification enregistrée à la sauvegarde</p>
            </div>
        </div>

        <div class="edit-layout">

            <!-- FORM -->
            <div class="form-card">

                <?php if ($error): ?>
                <div class="alert alert-error">⚠ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>

                <form method="POST" enctype="multipart/form-data">
<input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">

                    <!-- INFOS GÉNÉRALES -->
                    <div class="form-section">
                        <p class="form-section-label">Informations générales</p>
                        <div style="display:flex;flex-direction:column;gap:1.1rem;">

                            <div class="form-group">
                                <label>Nom du produit</label>
                                <input type="text" name="name"
                                       value="<?php echo htmlspecialchars($product['name']); ?>"
                                       required>
                            </div>

                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description"><?php echo htmlspecialchars($product['description']); ?></textarea>
                            </div>

                            <div class="form-row">
                                <div class="form-group">
                                    <label>Prix par jour (DA)</label>
                                    <input type="number" name="price" min="1" step="any"
                                           value="<?php echo (int)$product['price_per_day']; ?>"
                                           required>
                                </div>
                                <div class="form-group">
                                    <label>Catégorie</label>
                                    <select name="category" required>
                                        <?php foreach ($catList as $cat): ?>
                                        <option value="<?php echo htmlspecialchars($cat); ?>"
                                            <?php echo ($cat === $product['category']) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($cat); ?>
                                        </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </div>

                        </div>
                    </div>

                    <!-- GENRE -->
                    <div class="form-section">
                        <p class="form-section-label">Genre</p>
                        <div class="gender-toggle">
                            <?php foreach (['Men' => 'Homme', 'Women' => 'Femme', 'Unisex' => 'Unisexe'] as $val => $lbl): ?>
                            <input class="gender-option" type="radio" name="gender"
                                   id="g_<?php echo $val; ?>" value="<?php echo $val; ?>"
                                   <?php echo ($product['gender'] === $val) ? 'checked' : ''; ?>>
                            <label class="gender-label" for="g_<?php echo $val; ?>"><?php echo $lbl; ?></label>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- IMAGE -->
                    <div class="form-section">
                        <p class="form-section-label">Image du produit</p>
                        <?php if (!empty($product['image'])): ?>
                        <div class="current-img">
                            <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>" alt=""
                                 onerror="this.style.display='none'">
                            <div>
                                <div class="current-img-label">Image actuelle</div>
                                <div class="current-img-name"><?php echo htmlspecialchars($product['image']); ?></div>
                            </div>
                        </div>
                        <?php endif; ?>
                        <div class="form-group">
                            <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp">
                            <span class="file-hint">Laisser vide pour conserver l'image actuelle · JPG, PNG, WEBP</span>
                        </div>
                    </div>

                    <!-- STOCK PAR TAILLE -->
                    <div class="form-section">
                        <p class="form-section-label">Stock par taille</p>
                        <div class="size-stock-grid">
                            <?php foreach (['S', 'M', 'L', 'XL'] as $sz): ?>
                            <div class="size-stock-item">
                                <span class="size-badge"><?php echo $sz; ?></span>
                                <input type="number" name="stock_<?php echo $sz; ?>"
                                       min="0" value="<?php echo (int)($sizeData[$sz] ?? 0); ?>">
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- SUBMIT -->
                    <div class="submit-row">
                        <button type="submit" name="update" class="btn-submit">✓ Enregistrer</button>
                        <a href="dashboard.php" class="btn-cancel">← Annuler</a>
                        <form method="POST" action="delete_product.php" style="margin-left:auto;" onsubmit="return confirm('Supprimer définitivement ce produit ?')">
                            <input type="hidden" name="id" value="<?php echo (int)$id; ?>">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                            <button type="submit" class="btn-delete-product" style="background:transparent;cursor:pointer;">✕ Supprimer</button>
                        </form>
                    </div>

                </form>
            </div>

            <!-- PREVIEW -->
            <div class="preview-card">
                <div class="preview-label">Aperçu du produit</div>
                <div class="preview-img-wrap">
                    <?php if (!empty($product['image'])): ?>
                    <img src="uploads/<?php echo htmlspecialchars($product['image']); ?>"
                         alt="" onerror="this.style.display='none'">
                    <?php else: ?>
                    <span class="no-img">👗</span>
                    <?php endif; ?>
                </div>
                <div class="preview-info">
                    <div class="preview-name"><?php echo htmlspecialchars($product['name']); ?></div>
                    <div class="preview-cat"><?php echo htmlspecialchars($product['category'] ?? ''); ?></div>
                    <div class="preview-price">
                        <?php echo number_format((int)$product['price_per_day'], 0, ',', ' '); ?>
                        <span>DA / jour</span>
                    </div>
                </div>
            </div>

        </div>
    </main>
</div>

</body>
</html>