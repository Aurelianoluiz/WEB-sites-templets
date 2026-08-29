<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/stock_payment.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ensure_stock_operation_schema($pdo);
if (!register_stock_payment_operation($pdo, 900, 'paid')) {
    fwrite(STDERR, "FAIL: initial stock operation claim\n");
    exit(1);
}

$pending = list_unresolved_stock_payment_operations($pdo);
if (count($pending) !== 1 || (int)$pending[0]['order_id'] !== 900) {
    fwrite(STDERR, "FAIL: unresolved operation was not listed\n");
    exit(1);
}

if (!mark_stock_payment_operation_reviewed($pdo, 900, 'commit_reservation')) {
    fwrite(STDERR, "FAIL: unresolved operation was not reviewable\n");
    exit(1;
}

if (list_unresolved_stock_payment_operations($pdo) !== []) {
    fwrite(STDERR, "FAIL: reviewed operation remained unresolved\n");
    exit(1);
}

echo "PASS: stock reconciliation\n";
