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
$action = trim($_POST['action'] ?? '');
$redirectStatus = $_POST['redirect_status'] ?? '';
$redirectGender = $_POST['redirect_gender'] ?? '';

if ($id > 0 && in_array($action, ['approve', 'reject', 'return'], true)) {
    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare("SELECT * FROM rentals WHERE id = ? FOR UPDATE");
        $stmt->bind_param("i", $id);
        $stmt->execute();
        $rental = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if ($rental && $action === 'approve' && $rental['status'] === 'pending') {
            // Stock is reserved when the rental is created.
            $stmt = $conn->prepare("UPDATE rentals SET status='approved' WHERE id=? AND status='pending'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
        } elseif ($rental && $action === 'reject' && $rental['status'] === 'pending') {
            $stmt = $conn->prepare("UPDATE rentals SET status='rejected' WHERE id=? AND status='pending'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("UPDATE product_sizes SET stock=stock+1 WHERE product_id=? AND size=?");
            $stmt->bind_param("is", $rental['product_id'], $rental['size']);
            $stmt->execute();
            $stmt->close();
        } elseif ($rental && $action === 'return' && $rental['status'] === 'approved') {
            $stmt = $conn->prepare("UPDATE rentals SET status='returned' WHERE id=? AND status='approved'");
            $stmt->bind_param("i", $id);
            $stmt->execute();
            $stmt->close();
            $stmt = $conn->prepare("UPDATE product_sizes SET stock=stock+1 WHERE product_id=? AND size=?");
            $stmt->bind_param("is", $rental['product_id'], $rental['size']);
            $stmt->execute();
            $stmt->close();
        }
        $conn->commit();
    } catch (Throwable $e) {
        $conn->rollback();
        error_log('Rental status update failed: ' . $e->getMessage());
        http_response_code(500);
        exit('Unable to update rental.');
    }
}
$allowedStatuses = ['pending', 'approved', 'returned', 'rejected'];
if (!in_array($redirectStatus, $allowedStatuses, true)) $redirectStatus = '';
if (!in_array($redirectGender, ['Men', 'Women', 'Unisex'], true)) $redirectGender = '';
$qs = http_build_query(array_filter(['status' => $redirectStatus, 'gender' => $redirectGender]));
header("Location: dashboard.php" . ($qs ? "?$qs" : "") . "#rentals");
exit();
