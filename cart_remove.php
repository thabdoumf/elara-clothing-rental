<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: cart.php'); exit(); }
$token = $_SESSION['csrf_token'] ?? '';
if (!$token || !hash_equals($token, $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid request token.'); }
$cart_id = (int)($_POST['id'] ?? 0);
$user_id = (int)$_SESSION['user_id'];

if ($cart_id > 0) {
    $stmt = $conn->prepare("DELETE FROM cart WHERE id = ? AND user_id = ?");
    $stmt->bind_param("ii", $cart_id, $user_id);
    $stmt->execute();
    $stmt->close();
}

header("Location: cart.php");
exit();