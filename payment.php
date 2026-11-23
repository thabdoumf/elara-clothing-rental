<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$rentalId = (int)($_GET['rental_id'] ?? 0);
$userId = (int)$_SESSION['user_id'];
if ($rentalId <= 0) {
    header("Location: my_rentals.php");
    exit();
}

$stmt = $conn->prepare("SELECT r.id, r.total_price, r.status, p.name AS product_name FROM rentals r JOIN products p ON p.id = r.product_id WHERE r.id = ? AND r.user_id = ?");
$stmt->bind_param("ii", $rentalId, $userId);
$stmt->execute();
$rental = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rental) {
    header("Location: my_rentals.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Paiement — Élara</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:24px;background:#F5F0E8;color:#3D2314;font-family:Arial,sans-serif}.card{max-width:560px;width:100%;background:#FDFAF6;border:1px solid #E8DDD0;padding:36px;border-radius:6px}h1{font-family:Georgia,serif;margin-top:0}p{line-height:1.7;color:#70543E}.amount{font-size:24px;font-weight:bold;color:#8B5E3C;margin:20px 0}.status{padding:12px;background:#F5F0E8;border-left:3px solid #C4A882}.link{display:inline-block;margin-top:20px;padding:12px 18px;background:#3D2314;color:#fff;text-decoration:none;border-radius:3px}
</style>
</head>
<body>
<main class="card">
<h1>Paiement Élara</h1>
<p>Réservation #<?php echo (int)$rental['id']; ?> — <?php echo htmlspecialchars($rental['product_name'], ENT_QUOTES, 'UTF-8'); ?></p>
<div class="amount"><?php echo number_format((float)$rental['total_price'], 0, ',', ' '); ?> DA</div>
<div class="status">
<strong>Paiement en ligne indisponible.</strong>
<p>Cette boutique ne dispose pas encore d'une passerelle de paiement connectée. Aucun numéro de carte, CVV ou mot de passe ne doit être saisi sur cette page. Votre réservation conserve son statut actuel.</p>
</div>
<a class="link" href="my_rentals.php">Retour à mes locations</a>
</main>
</body>
</html>