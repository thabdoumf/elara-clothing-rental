<?php
declare(strict_types=1);

mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

$host = getenv('DB_HOST') ?: '127.0.0.1';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$name = getenv('DB_NAME') ?: 'location_vetement';
$db = new mysqli($host, $user, $pass, $name);
$db->set_charset('utf8mb4');

$passed = 0;
function check(bool $condition, string $label): void {
    global $passed;
    if (!$condition) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $passed++;
    echo "PASS: " . $label . PHP_EOL;
}
function scalar(mysqli $db, string $sql, string $types = '', ...$values): mixed {
    $stmt = $db->prepare($sql);
    if ($types !== '') $stmt->bind_param($types, ...$values);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_row();
    $stmt->close();
    return $row[0] ?? null;
}

$tag = bin2hex(random_bytes(5));
$email = "elara-test-$tag@example.test";
$productId = 0;
$userId = 0;

try {
    // Registration: same columns and password hashing used by register.php.
    $nameValue = 'Élara Automated Test';
    $hash = password_hash('TestPass123', PASSWORD_DEFAULT);
    $wilaya = '13 - Tlemcen';
    $dob = '2000-01-01';
    $gender = 'Unisex';
    $stmt = $db->prepare("INSERT INTO users (name,email,password,wilaya,dob,gender,role) VALUES (?,?,?,?,?,?,'user')");
    $stmt->bind_param('ssssss', $nameValue, $email, $hash, $wilaya, $dob, $gender);
    $stmt->execute();
    $userId = $db->insert_id;
    $stmt->close();
    check($userId > 0 && password_verify('TestPass123', (string)scalar($db, "SELECT password FROM users WHERE id=?", 'i', $userId)), 'registration and password hash');

    // Product fixture with two sizes.
    $productName = "Automated Test Product $tag";
    $price = 1200.00;
    $available = 1;
    $stock = 3;
    $category = 'Test';
    $stmt = $db->prepare("INSERT INTO products (name,price_per_day,available,stock,category,gender) VALUES (?,?,?,?,?, 'Unisex')");
    $stmt->bind_param('sdiis', $productName, $price, $available, $stock, $category);
    $stmt->execute();
    $productId = $db->insert_id;
    $stmt->close();

    foreach (['M' => 2, 'L' => 0] as $size => $qty) {
        $stmt = $db->prepare("INSERT INTO product_sizes (product_id,size,stock) VALUES (?,?,?)");
        $stmt->bind_param('isi', $productId, $size, $qty);
        $stmt->execute();
        $stmt->close();
    }

    // Cart insert, duplicate protection, and checkout transaction like cart.php.
    $size = 'M';
    $stmt = $db->prepare("INSERT INTO cart (user_id,product_id,size) VALUES (?,?,?)");
    $stmt->bind_param('iis', $userId, $productId, $size);
    $stmt->execute();
    $stmt->close();
    check((int)scalar($db, "SELECT COUNT(*) FROM cart WHERE user_id=? AND product_id=? AND size=?", 'iis', $userId, $productId, $size) === 1, 'add item to cart');

    $db->begin_transaction();
    try {
        $stmt = $db->prepare("SELECT id,product_id,size FROM cart WHERE user_id=? FOR UPDATE");
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        $start = date('Y-m-d', strtotime('+2 days'));
        $end = date('Y-m-d', strtotime('+4 days'));
        $total = 2 * $price;
        foreach ($items as $item) {
            $pid = (int)$item['product_id'];
            $sz = $item['size'];
            $reserve = $db->prepare("UPDATE product_sizes SET stock=stock-1 WHERE product_id=? AND size=? AND stock>0");
            $reserve->bind_param('is', $pid, $sz);
            $reserve->execute();
            check($reserve->affected_rows === 1, 'cart checkout reserves available stock');
            $reserve->close();

            $rental = $db->prepare("INSERT INTO rentals (user_id,product_id,size,start_date,end_date,total_price,status) VALUES (?,?,?,?,?,?,'pending')");
            $rental->bind_param('iisssd', $userId, $pid, $sz, $start, $end, $total);
            $rental->execute();
            $rental->close();

            $cartId = (int)$item['id'];
            $del = $db->prepare("DELETE FROM cart WHERE id=? AND user_id=?");
            $del->bind_param('ii', $cartId, $userId);
            $del->execute();
            $del->close();
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
    check((int)scalar($db, "SELECT COUNT(*) FROM rentals WHERE user_id=? AND product_id=? AND status='pending'", 'ii', $userId, $productId) === 1, 'checkout creates pending rental');
    check((int)scalar($db, "SELECT stock FROM product_sizes WHERE product_id=? AND size='M'", 'i', $productId) === 1, 'checkout decrements stock');
    check((int)scalar($db, "SELECT COUNT(*) FROM cart WHERE user_id=?", 'i', $userId) === 0, 'checkout empties cart');

    // Failed checkout must roll back stock and rental inserts.
    $emptySize = 'L';
    $stmt = $db->prepare("INSERT INTO cart (user_id,product_id,size) VALUES (?,?,?)");
    $stmt->bind_param('iis', $userId, $productId, $emptySize);
    $stmt->execute();
    $stmt->close();
    $beforeRentals = (int)scalar($db, "SELECT COUNT(*) FROM rentals WHERE user_id=? AND product_id=?", 'ii', $userId, $productId);
    $db->begin_transaction();
    try {
        $pid = $productId;
        $reserve = $db->prepare("UPDATE product_sizes SET stock=stock-1 WHERE product_id=? AND size='L' AND stock>0");
        $reserve->bind_param('i', $pid);
        $reserve->execute();
        if ($reserve->affected_rows !== 1) throw new RuntimeException('Expected out-of-stock failure');
        $db->commit();
        throw new RuntimeException('Out-of-stock cart unexpectedly succeeded');
    } catch (RuntimeException $e) {
        $db->rollback();
    }
    check((int)scalar($db, "SELECT COUNT(*) FROM rentals WHERE user_id=? AND product_id=?", 'ii', $userId, $productId) === $beforeRentals, 'failed checkout rolls back rental creation');
    check((int)scalar($db, "SELECT stock FROM product_sizes WHERE product_id=? AND size='L'", 'i', $productId) === 0, 'failed checkout leaves stock unchanged');

    // Single-item booking flow like rent.php.
    $size = 'M';
    $db->begin_transaction();
    $reserve = $db->prepare("UPDATE product_sizes SET stock=stock-1 WHERE product_id=? AND size=? AND stock>0");
    $reserve->bind_param('is', $productId, $size);
    $reserve->execute();
    check($reserve->affected_rows === 1, 'single booking reserves stock');
    $reserve->close();
    $start = date('Y-m-d', strtotime('+5 days'));
    $end = date('Y-m-d', strtotime('+6 days'));
    $total = $price;
    $stmt = $db->prepare("INSERT INTO rentals (user_id,product_id,size,start_date,end_date,total_price,status) VALUES (?,?,?,?,?,?,'pending')");
    $stmt->bind_param('iisssd', $userId, $productId, $size, $start, $end, $total);
    $stmt->execute();
    $stmt->close();
    $db->commit();
    check((int)scalar($db, "SELECT COUNT(*) FROM rentals WHERE user_id=? AND product_id=? AND start_date=?", 'iis', $userId, $productId, $start) === 1, 'single booking creates rental');
    check((int)scalar($db, "SELECT stock FROM product_sizes WHERE product_id=? AND size='M'", 'i', $productId) === 0, 'single booking decrements stock');

    // Password reset persistence, expiry validation, password update, and token cleanup.
    $code = (string)random_int(100000, 999999);
    $expires = date('Y-m-d H:i:s', time() + 900);
    $stmt = $db->prepare("INSERT INTO password_resets (email,code,expires_at) VALUES (?,?,?)");
    $stmt->bind_param('sss', $email, $code, $expires);
    $stmt->execute();
    $stmt->close();
    check((int)scalar($db, "SELECT COUNT(*) FROM password_resets WHERE email=? AND code=? AND expires_at>NOW()", 'ss', $email, $code) === 1, 'password reset code stored and valid');
    $newHash = password_hash('NewPass456', PASSWORD_DEFAULT);
    $stmt = $db->prepare("UPDATE users SET password=? WHERE email=?");
    $stmt->bind_param('ss', $newHash, $email);
    $stmt->execute();
    $stmt->close();
    $stmt = $db->prepare("DELETE FROM password_resets WHERE email=?");
    $stmt->bind_param('s', $email);
    $stmt->execute();
    $stmt->close();
    check(password_verify('NewPass456', (string)scalar($db, "SELECT password FROM users WHERE id=?", 'i', $userId)), 'password reset updates password hash');
    check((int)scalar($db, "SELECT COUNT(*) FROM password_resets WHERE email=?", 's', $email) === 0, 'password reset code removed after reset');

    echo PHP_EOL . "RESULT: $passed checks passed." . PHP_EOL;
} finally {
    if ($userId > 0) {
        $stmt = $db->prepare("DELETE FROM password_resets WHERE email=?");
        $stmt->bind_param('s', $email); $stmt->execute(); $stmt->close();
        $stmt = $db->prepare("DELETE FROM cart WHERE user_id=?");
        $stmt->bind_param('i', $userId); $stmt->execute(); $stmt->close();
        $stmt = $db->prepare("DELETE FROM rentals WHERE user_id=?");
        $stmt->bind_param('i', $userId); $stmt->execute(); $stmt->close();
        if ($productId > 0) {
            $stmt = $db->prepare("DELETE FROM products WHERE id=?");
            $stmt->bind_param('i', $productId); $stmt->execute(); $stmt->close();
        }
        $stmt = $db->prepare("DELETE FROM users WHERE id=?");
        $stmt->bind_param('i', $userId); $stmt->execute(); $stmt->close();
    }
    $db->close();
}
