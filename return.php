<?php
include("db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$rental_id = (int)($_GET['id'] ?? 0);

$stmt = $conn->prepare("SELECT * FROM rentals WHERE id = ?");
$stmt->bind_param("i", $rental_id);
$stmt->execute();
$rental = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rental || $rental['status'] !== 'approved') {
    header("Location: dashboard.php");
    exit();
}

$stmt = $conn->prepare("UPDATE rentals SET status='returned' WHERE id=?");
$stmt->bind_param("i", $rental_id);
$stmt->execute();
$stmt->close();

$stmt = $conn->prepare("
    UPDATE product_sizes
    SET stock = stock + 1
    WHERE product_id = ? AND size = ?
");
$stmt->bind_param("is", $rental['product_id'], $rental['size']);
$stmt->execute();
$stmt->close();

header("Location: dashboard.php");
exit();