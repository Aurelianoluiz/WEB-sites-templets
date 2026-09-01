<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\OrderService;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec(<<<'SQL'
CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, email TEXT);
CREATE TABLE products (id INTEGER PRIMARY KEY, name TEXT, stock INTEGER);
CREATE TABLE stock_movements (id INTEGER PRIMARY KEY AUTOINCREMENT, product_id INTEGER, type TEXT, qty INTEGER, reason TEXT, user_id INTEGER);
CREATE TABLE orders (id INTEGER PRIMARY KEY AUTOINCREMENT, customer_id INTEGER, customer_name TEXT, email TEXT, total_amount REAL, status TEXT, payment_status TEXT, created_at TEXT);
CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, product_id INTEGER, qty INTEGER, price REAL);
CREATE TABLE order_status_history (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER, from_status TEXT, to_status TEXT, actor_user_id INTEGER, note TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP);
SQL);

$pdo->exec("INSERT INTO users VALUES (1, 'Cliente Teste', 'cliente@teste.com')");
$pdo->exec("INSERT INTO products VALUES (10, 'Tijolo 8F', 50)");
$pdo->exec("INSERT INTO orders VALUES (100, 1, 'Cliente Teste', 'cliente@teste.com', 100.0, 'pending', 'pending', '2026-08-31 10:00:00')");
$pdo->exec("INSERT INTO order_items VALUES (1, 100, 10, 5, 20.0)");

$service = new OrderService($pdo, new OrderRepository($pdo), new ProductRepository($pdo));
$result = $service->cancelByCustomer(100, 1);
if (!$result['success']) {
    fwrite(STDERR, 'FAIL: cancelByCustomer: ' . $result['message'] . PHP_EOL);
    exit(1);
}

$stock = (int)$pdo->query('SELECT stock FROM products WHERE id = 10')->fetchColumn();
if ($stock !== 55) {
    fwrite(STDERR, "FAIL: expected stock 55, got {$stock}" . PHP_EOL);
    exit(1);
}

$status = (string)$pdo->query('SELECT status FROM orders WHERE id = 100')->fetchColumn();
if ($status !== 'cancelled') {
    fwrite(STDERR, "FAIL: expected cancelled, got {$status}" . PHP_EOL);
    exit(1);
}

$history = (int)$pdo->query('SELECT COUNT(*) FROM order_status_history WHERE order_id = 100')->fetchColumn();
if ($history !== 1) {
    fwrite(STDERR, "FAIL: expected one history row, got {$history}" . PHP_EOL);
    exit(1);
}

$repeat = $service->cancelByCustomer(100, 1);
if ($repeat['success']) {
    fwrite(STDERR, 'FAIL: duplicate cancellation was accepted.' . PHP_EOL);
    exit(1);
}

$pdo->exec("INSERT INTO orders VALUES (101, 1, 'Cliente Teste', 'cliente@teste.com', 50.0, 'confirmed', 'paid', '2026-08-31 10:00:00')");
$paid = $service->cancelByCustomer(101, 1);
if ($paid['success']) {
    fwrite(STDERR, 'FAIL: paid order cancellation was accepted.' . PHP_EOL);
    exit(1);
}

echo "PASS: order_service_test\n";
