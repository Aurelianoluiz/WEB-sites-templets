<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/stock_payment.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (register_stock_payment_operation($pdo, 100, 'paid') !== true) {
    fwrite(STDERR, "FAIL: first paid stock operation was not registered\n");
    exit(1);
}
if (register_stock_payment_operation($pdo, 100, 'paid') !== false) {
    fwrite(STDERR, "FAIL: duplicate paid stock operation was registered twice\n");
    exit(1);
}
if (register_stock_payment_operation($pdo, 100, 'failed') !== true) {
    fwrite(STDERR, "FAIL: failed/release operation was not registered\n");
    exit(1);
}
if (mark_stock_payment_operation_applied($pdo, 100, 'commit_reservation') !== true) {
    fwrite(STDERR, "FAIL: first operation apply was not marked\n");
    exit(1);
}
if (mark_stock_payment_operation_applied($pdo, 100, 'commit_reservation') !== false) {
    fwrite(STDERR, "FAIL: applied operation was marked twice\n");
    exit(1);
}

echo "PASS: stock payment idempotency\n";
