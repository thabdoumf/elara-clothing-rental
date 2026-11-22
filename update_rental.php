<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: index.php");
    exit();
}

$id     = (int)($_GET['id']     ?? 0);
$action = trim($_GET['action']  ?? '');

$redirectStatus = $_GET['redirect_status'] ?? '';
$redirectGender = $_GET['redirect_gender'] ?? '';

$stmt = $conn->prepare("SELECT * FROM rentals WHERE id = ?");
$stmt->bind_param("i", $id);
$stmt->execute();
$rental = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$rental) {
    header("Location: dashboard.php");
    exit();
}

/* APPROVE */
if ($action === 'approve' && $rental['status'] === 'pending') {

    $stmt = $conn->prepare("
        UPDATE product_sizes
        SET stock = stock - 1
        WHERE product_id = ? AND size = ? AND stock > 0
    ");
    $stmt->bind_param("is", $rental['product_id'], $rental['size']);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE rentals SET status='approved' WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

/* RETURN */
if ($action === 'return' && $rental['status'] === 'approved') {

    $stmt = $conn->prepare("
        UPDATE product_sizes
        SET stock = stock + 1
        WHERE product_id = ? AND size = ?
    ");
    $stmt->bind_param("is", $rental['product_id'], $rental['size']);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("UPDATE rentals SET status='returned' WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

/* REJECT */
if ($action === 'reject' && $rental['status'] === 'pending') {

    $stmt = $conn->prepare("UPDATE rentals SET status='rejected' WHERE id=?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

/* Redirect back to dashboard, jump straight to rentals section */
$qs = http_build_query(array_filter(['status' => $redirectStatus, 'gender' => $redirectGender]));
header("Location: dashboard.php" . ($qs ? "?$qs" : "") . "#rentals");
exit();
?>