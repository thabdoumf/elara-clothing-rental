<?php
session_start();
include("db.php");

// Legacy endpoint retained for old links. Rental returns must go through
// the CSRF-protected admin action in update_rental.php.
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    header("Location: index.php");
    exit();
}
header("Location: dashboard.php");
exit();
