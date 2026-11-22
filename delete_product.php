<?php
include("db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$id = (int)($_GET['id'] ?? 0);

if ($id <= 0) {
    header("Location: dashboard.php");
    exit();
}

/* Delete sizes first (foreign key safety) */
$stmt = $conn->prepare("DELETE FROM product_sizes WHERE product_id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

/* Delete product */
$stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$stmt->close();

header("Location: dashboard.php");
exit();