<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id'])) {
    header("Location: login.php");
    exit();
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
if (!$sessionToken || !hash_equals($sessionToken, $_POST['csrf_token'] ?? '')) { http_response_code(403); exit('Invalid request token.'); }

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['product_id'], $_POST['size'])) {
    header("Location: index.php");
    exit();
}

$user_id    = (int)$_SESSION['user_id'];
$product_id = (int)$_POST['product_id'];
$size       = trim($conn->real_escape_string($_POST['size']));

// Check product exists
$stmt = $conn->prepare("SELECT id, name FROM products WHERE id = ?");
$stmt->bind_param("i", $product_id);
$stmt->execute();
$product = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$product) {
    header("Location: index.php");
    exit();
}

// Check stock
$stmt = $conn->prepare("SELECT stock FROM product_sizes WHERE product_id = ? AND size = ?");
$stmt->bind_param("is", $product_id, $size);
$stmt->execute();
$stockRow = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$stockRow || $stockRow['stock'] <= 0) {
    header("Location: index.php?error=stock");
    exit();
}

// Check if already in cart
$stmt = $conn->prepare("SELECT id FROM cart WHERE user_id = ? AND product_id = ? AND size = ?");
$stmt->bind_param("iis", $user_id, $product_id, $size);
$stmt->execute();
$stmt->store_result();
$already = $stmt->num_rows > 0;
$stmt->close();

if (!$already) {
    $stmt = $conn->prepare("INSERT INTO cart (user_id, product_id, size) VALUES (?, ?, ?)");
    $stmt->bind_param("iis", $user_id, $product_id, $size);
    $stmt->execute();
    $stmt->close();
}

header("Location: index.php?added=1");
exit();