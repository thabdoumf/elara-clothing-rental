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
if ($id > 0) {
    // Archive instead of hard-delete so past rentals and reviews keep their product reference.
    $stmt = $conn->prepare("UPDATE products SET available = 0 WHERE id = ?");
    $stmt->bind_param("i", $id);
    $stmt->execute();
    $stmt->close();
}

header("Location: dashboard.php");
exit();
