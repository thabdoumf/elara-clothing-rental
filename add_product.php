<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$adminName = $_SESSION['user_name'] ?? 'Admin';
$error   = "";
$success = "";

$categories = $conn->query("
    SELECT DISTINCT category FROM products
    WHERE category IS NOT NULL AND category != ''
    ORDER BY category
");
$catList = [];
while ($c = $categories->fetch_assoc()) $catList[] = $c['category'];

if (isset($_POST['add'])) {

    $name     = trim($_POST['name']);
    $desc     = trim($_POST['description']);
    $price    = (float)$_POST['price'];
    $category = trim($_POST['category']);
    $gender   = trim($_POST['gender']);
    $allowed_genders = ['Men', 'Women', 'Unisex'];

    if (empty($name) || $price <= 0 || empty($category)) {
        $error = "Nom, catégorie et prix sont obligatoires.";
    } elseif (!in_array($gender, $allowed_genders)) {
        $error = "Genre invalide.";
    } elseif (empty($_FILES['image']['name'])) {
        $error = "Une image est requise.";
    } else {
        $imageName = basename($_FILES['image']['name']);
        $ext       = strtolower(pathinfo($imageName, PATHINFO_EXTENSION));
        $allowed   = ['jpg', 'jpeg', 'png', 'webp'];

        if (!in_array($ext, $allowed)) {
            $error = "Format d'image invalide (jpg, jpeg, png, webp).";
        } else {
            $safeImage = uniqid('img_', true) . '.' . $ext;
            move_uploaded_file($_FILES['image']['tmp_name'], "uploads/" . $safeImage);

            $stmt = $conn->prepare(
                "INSERT INTO products (name, description, price_per_day, category, image, gender)
                 VALUES (?, ?, ?, ?, ?, ?)"
            );
            $stmt->bind_param("ssdsss", $name, $desc, $price, $category, $safeImage, $gender);
            $stmt->execute();
            $product_id = $conn->insert_id;
            $stmt->close();

            $sizeStmt = $conn->prepare(
                "INSERT INTO product_sizes (product_id, size, stock) VALUES (?, ?, ?)"
            );
            foreach (['S', 'M', 'L', 'XL'] as $sz) {
                $stock = max(0, (int)($_POST[$sz] ?? 0));
                $sizeStmt->bind_param("isi", $product_id, $sz, $stock);
                $sizeStmt->execute();
            }
            $sizeStmt->close();

            header("Location: dashboard.php");
            exit();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ajouter un produit — Élara</title>
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
            padding: 1.5rem 0;
            overflow-y: auto;
            z-index: 100;
        }
        .sidebar-section { padding: 0 1rem 1rem; }
        .sidebar-label { font-size: 0.6rem; letter-spacing: 0.25em; text-transform: uppercase; color: rgba(196,168,130,0.35); padding: 0 0.75rem 0.5rem; }
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

        /* ══ MAIN ══ */
        .main { margin-left: 220px; padding: 2rem 2.5rem; min-width: 0; overflow-x: hidden; }

        /* ══ PAGE HEADER ══ */
        .page-header { margin-bottom: 2rem; }
        .page-header h1 {
            font-family: 'Playfair Display', serif;
            font-size: 2rem; font-weight: 700; color: var(--white); line-height: 1.15;
        }
        .page-header h1 em { font-style: italic; color: var(--clay); }
        .page-header .sub { font-size: 0.82rem; color: rgba(196,168,130,0.5); margin-top: 0.3rem; }

        /* ══ FORM CARD ══ */
        .form-card {
            background: var(--panel-card);
            border: 1px solid var(--panel-border);
            border-radius: 2px;
            max-width: 760px;
            padding: 2.5rem;
            animation: fadeUp 0.4s ease both;
        }
        @keyframes fadeUp {
            from { opacity: 0; transform: translateY(12px); }
            to   { opacity: 1; transform: translateY(0); }
        }

        /* ══ SECTION LABELS ══ */
        .form-section-label {
            font-size: 0.6rem; letter-spacing: 0.25em; text-transform: uppercase;
            color: rgba(196,168,130,0.4); margin-bottom: 1.25rem;
            padding-bottom: 0.6rem;
            border-bottom: 1px solid var(--panel-border);
        }
        .form-section { margin-bottom: 2rem; }

        /* ══ FORM ELEMENTS ══ */
        .form-group { display: flex; flex-direction: column; gap: 0.4rem; }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1.25rem; }
        .form-row-4 { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }

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
            border-radius: 2px;
            color: var(--white);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.88rem;
            padding: 0.7rem 0.9rem;
            transition: border-color 0.2s, background 0.2s;
            outline: none;
            width: 100%;
        }
        input[type="text"]:focus,
        input[type="number"]:focus,
        textarea:focus,
        select:focus {
            border-color: rgba(196,168,130,0.4);
            background: rgba(196,168,130,0.08);
        }
        input::placeholder, textarea::placeholder { color: rgba(196,168,130,0.2); }
        textarea { resize: vertical; min-height: 90px; }
        select option { background: var(--panel-card); color: var(--white); }

        /* ══ FILE INPUT ══ */
        .file-upload-wrap { position: relative; }
        input[type="file"] {
            background: rgba(196,168,130,0.04);
            border: 1px dashed rgba(196,168,130,0.2);
            border-radius: 2px;
            color: rgba(196,168,130,0.5);
            font-family: 'DM Sans', sans-serif;
            font-size: 0.82rem;
            padding: 0.9rem 1rem;
            width: 100%;
            cursor: pointer;
            transition: border-color 0.2s, background 0.2s;
        }
        input[type="file"]:hover {
            border-color: rgba(196,168,130,0.35);
            background: rgba(196,168,130,0.07);
        }
        .file-hint { font-size: 0.65rem; color: rgba(196,168,130,0.3); margin-top: 0.35rem; }

        /* ══ STOCK INPUTS ══ */
        .size-stock-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 1rem; }
        .size-stock-item {
            background: rgba(196,168,130,0.04);
            border: 1px solid var(--panel-border);
            border-radius: 2px;
            padding: 1rem;
            display: flex; flex-direction: column; align-items: center; gap: 0.6rem;
            transition: border-color 0.2s;
        }
        .size-stock-item:hover { border-color: rgba(196,168,130,0.25); }
        .size-badge {
            font-family: 'Playfair Display', serif;
            font-size: 1.1rem; font-weight: 700; color: var(--clay);
        }
        .size-stock-item input[type="number"] {
            text-align: center; font-size: 1rem;
            padding: 0.5rem; font-weight: 500;
            color: var(--white);
        }

        /* ══ GENDER SELECTOR ══ */
        .gender-toggle { display: flex; gap: 0.5rem; }
        .gender-option { display: none; }
        .gender-label {
            flex: 1; text-align: center;
            padding: 0.65rem 1rem;
            border: 1px solid var(--panel-border);
            border-radius: 2px;
            font-size: 0.75rem; letter-spacing: 0.08em; text-transform: uppercase;
            color: rgba(253,250,246,0.4);
            cursor: pointer;
            transition: all 0.2s;
        }
        .gender-option:checked + .gender-label {
            background: var(--espresso);
            border-color: var(--clay);
            color: var(--clay);
        }
        .gender-label:hover { color: var(--white); background: rgba(255,255,255,0.04); }

        /* ══ ALERT ══ */
        .alert {
            display: flex; align-items: center; gap: 0.75rem;
            padding: 0.85rem 1.1rem; border-radius: 2px;
            font-size: 0.82rem; margin-bottom: 1.75rem;
        }
        .alert-error { background: rgba(192,57,43,0.1); border: 1px solid rgba(192,57,43,0.25); color: #E07060; }
        .alert-success { background: rgba(39,174,96,0.1); border: 1px solid rgba(39,174,96,0.25); color: #5EE08A; }

        /* ══ SUBMIT ROW ══ */
        .submit-row {
            display: flex; align-items: center; gap: 1rem;
            padding-top: 1.5rem;
            border-top: 1px solid var(--panel-border);
            margin-top: 0.5rem;
        }
        .btn-submit {
            background: var(--clay); color: var(--espresso);
            border: none; border-radius: 2px;
            font-family: 'DM Sans', sans-serif;
            font-size: 0.8rem; font-weight: 500;
            letter-spacing: 0.1em; text-transform: uppercase;
            padding: 0.75rem 2rem;
            cursor: pointer;
            transition: background 0.2s, color 0.2s;
        }
        .btn-submit:hover { background: var(--terra); color: var(--white); }
        .btn-cancel {
            color: rgba(196,168,130,0.45); text-decoration: none;
            font-size: 0.78rem; letter-spacing: 0.08em; text-transform: uppercase;
            transition: color 0.2s;
        }
        .btn-cancel:hover { color: var(--clay); }

        /* ══ RESPONSIVE ══ */
        @media (max-width: 900px) {
            .sidebar { display: none; }
            .main { margin-left: 0; }
        }
        @media (max-width: 600px) {
            .form-row { grid-template-columns: 1fr; }
            .size-stock-grid { grid-template-columns: repeat(2, 1fr); }
            .main { padding: 1.25rem; }
            .form-card { padding: 1.5rem; }
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
            <a href="add_product.php" class="sidebar-link active">
                <span class="sl-icon">➕</span> Ajouter produit
            </a>
            <a href="index.php" class="sidebar-link">
                <span class="sl-icon">🏪</span> Voir la boutique
            </a>
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
            <h1>Ajouter un <em>Produit</em></h1>
            <p class="sub">Remplissez les informations du nouvel article à louer</p>
        </div>

        <div class="form-card">

            <?php if ($error): ?>
            <div class="alert alert-error">
                ⚠ <?php echo htmlspecialchars($error); ?>
            </div>
            <?php endif; ?>

            <form method="POST" enctype="multipart/form-data">

                <!-- INFOS GÉNÉRALES -->
                <div class="form-section">
                    <p class="form-section-label">Informations générales</p>
                    <div style="display:flex; flex-direction:column; gap:1.25rem;">

                        <div class="form-group">
                            <label>Nom du produit</label>
                            <input type="text" name="name"
                                   placeholder="Ex: Abaya Noir, Veste en cuir…"
                                   value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                                   required>
                        </div>

                        <div class="form-group">
                            <label>Description</label>
                            <textarea name="description" placeholder="Décrivez le produit, matière, style…"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="form-row">
                            <div class="form-group">
                                <label>Prix par jour (DA)</label>
                                <input type="number" name="price" min="1" step="any"
                                       placeholder="0"
                                       value="<?php echo htmlspecialchars($_POST['price'] ?? ''); ?>"
                                       required>
                            </div>
                            <div class="form-group">
                                <label>Catégorie</label>
                                <select name="category" required>
                                    <option value="">— Sélectionner —</option>
                                    <?php foreach ($catList as $cat): ?>
                                    <option value="<?php echo htmlspecialchars($cat); ?>"
                                        <?php echo (($_POST['category'] ?? '') === $cat) ? 'selected' : ''; ?>>
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
                        <?php
                        $selectedGender = $_POST['gender'] ?? 'Women';
                        foreach (['Men' => 'Homme', 'Women' => 'Femme', 'Unisex' => 'Unisexe'] as $val => $lbl):
                        ?>
                        <input class="gender-option" type="radio" name="gender"
                               id="g_<?php echo $val; ?>" value="<?php echo $val; ?>"
                               <?php echo $selectedGender === $val ? 'checked' : ''; ?>>
                        <label class="gender-label" for="g_<?php echo $val; ?>"><?php echo $lbl; ?></label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- IMAGE -->
                <div class="form-section">
                    <p class="form-section-label">Image du produit</p>
                    <div class="form-group">
                        <input type="file" name="image" accept=".jpg,.jpeg,.png,.webp" required>
                        <span class="file-hint">Formats acceptés : JPG, PNG, WEBP · Taille recommandée : 600×800px</span>
                    </div>
                </div>

                <!-- STOCK PAR TAILLE -->
                <div class="form-section">
                    <p class="form-section-label">Stock par taille</p>
                    <div class="size-stock-grid">
                        <?php foreach (['S', 'M', 'L', 'XL'] as $sz): ?>
                        <div class="size-stock-item">
                            <span class="size-badge"><?php echo $sz; ?></span>
                            <input type="number" name="<?php echo $sz; ?>"
                                   min="0" value="<?php echo (int)($_POST[$sz] ?? 0); ?>"
                                   required>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- SUBMIT -->
                <div class="submit-row">
                    <button type="submit" name="add" class="btn-submit">✓ Ajouter le produit</button>
                    <a href="dashboard.php" class="btn-cancel">← Annuler</a>
                </div>

            </form>
        </div>

    </main>
</div>

</body>
</html>