<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$id = (int)($_GET['id'] ?? 0);
if (empty($_SESSION['csrf_token'])) { $_SESSION['csrf_token'] = bin2hex(random_bytes(32)); }
$csrfToken = $_SESSION['csrf_token'];

if (isset($_POST['update'])) {
    if (!hash_equals($csrfToken, $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid request token.'); }
    $stock = max(0, (int)$_POST['stock']);

    $stmt = $conn->prepare("UPDATE product_sizes SET stock=? WHERE id=?");
    $stmt->bind_param("ii", $stock, $id);
    $stmt->execute();
    $stmt->close();

    header("Location: dashboard.php");
    exit();
}

$stmt = $conn->prepare("SELECT * FROM product_sizes WHERE id=?");
$stmt->bind_param("i", $id);
$stmt->execute();
$current = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$current) {
    die("Entrée introuvable.");
}
?>

<link rel="stylesheet" href="style.css">

<div class="container">
<div class="form-card">

<h2>Modifier le stock</h2>

<p>Stock actuel : <strong><?php echo (int)$current['stock']; ?></strong></p>

<form method="POST"><input type="hidden" name="csrf_token" value="<?php echo $csrfToken; ?>">
    <div class="form-group">
        <label>Nouveau stock</label>
        <input type="number" name="stock" min="0"
               value="<?php echo (int)$current['stock']; ?>" required>
    </div>
    <button name="update" class="btn-primary">Mettre à jour</button>
</form>

</div>
</div>