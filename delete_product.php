<?php
session_start();
include("db.php");

if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: dashboard.php");
    exit();
}

$sessionToken = $_SESSION['csrf_token'] ?? '';
$postedToken = $_POST['csrf_token'] ?? '';

if (!$sessionToken || !$postedToken || !hash_equals($sessionToken, $postedToken)) {
    http_response_code(403);
    exit('Invalid request token.');
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header("Location: dashboard.php");
    exit();
}

$conn->begin_transaction();
try {
    $stmt = $conn->prepare("DELETE FROM product_sizes WHERE product_id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $stmt = $conn->prepare("DELETE FROM products WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();

    $conn->commit();
} catch (Throwable $e) {
    $conn->rollback();
    http_response_code(500);
    exit('Unable to delete product.');
}

header("Location: dashboard.php");
exit();
