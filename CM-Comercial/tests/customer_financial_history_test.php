<?php
declare(strict_types=1);

require_once __DIR__ . '/../financial_history.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER)');
$pdo->exec('CREATE TABLE payments (id INTEGER PRIMARY KEY, order_id INTEGER, amount REAL, method TEXT, status TEXT, transaction_id TEXT, created_at TEXT, updated_at TEXT)');
$pdo->exec("INSERT INTO orders VALUES (1, 10), (2, 20)");
$pdo->exec("INSERT INTO payments VALUES (1, 1, 100.00, 'pix', 'paid', 'tx-1', '2026-08-27 10:00:00', '2026-08-27 10:01:00'), (2, 2, 200.00, 'card', 'paid', 'tx-2', '2026-08-27 10:00:00', '2026-08-27 10:01:00')");

$items = customer_financial_history($pdo, 10);
if (count($items) !== 1 || (int)$items[0]['order_id'] !== 1) {
    fwrite(STDERR, "FAIL: customer isolation\n");
    exit(1);
}
if (customer_financial_history($pdo, 10, 0, 0) === []) {
    fwrite(STDERR, "FAIL: bounded limit normalization\n");
    exit(1);
}

echo "PASS: customer financial history isolation\n";
