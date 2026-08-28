<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/stock_payment_bridge.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$calls = 0;
$claimed = apply_payment_stock_effect(
    $pdo,
    100,
    'paid',
    static function (PDO $pdo, int $orderId, string $action) use (&$calls): void {
        $calls++;
        if ($orderId !== 100 || $action !== 'commit_reservation') {
            throw new RuntimeException('Unexpected stock mutation arguments.');
        }
    }
);

if ($claimed !== true || $calls !== 1) {
    fwrite(STDERR, "FAIL: first stock effect was not applied exactly once\n");
    exit(1);
}

$second = apply_payment_stock_effect(
    $pdo,
    100,
    'paid',
    static function () use (&$calls): void { $calls++; }
);

if ($second !== false || $calls !== 1) {
    fwrite(STDERR, "FAIL: duplicate stock effect was applied\n");
    exit(1);
}

echo "PASS: stock payment bridge idempotency\n";
