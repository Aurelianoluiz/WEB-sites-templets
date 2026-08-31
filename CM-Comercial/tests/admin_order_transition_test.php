<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\AdminOrderService;
use PDO;

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new AdminOrderService($pdo, new OrderRepository($pdo), new ProductRepository($pdo));

$valid = [
    ['pending', 'confirmed'],
    ['confirmed', 'preparing'],
    ['preparing', 'shipped'],
    ['shipped', 'delivered'],
    ['pending', 'cancelled'],
];
foreach ($valid as [$from, $to]) {
    if (!$service->isValidTransition($from, $to)) {
        fwrite(STDERR, "FAIL: valid transition {$from} -> {$to} rejected" . PHP_EOL);
        exit(1);
    }
}

$invalid = [
    ['delivered', 'cancelled'],
    ['shipped', 'pending'],
    ['cancelled', 'confirmed'],
    ['preparing', 'pending'],
];
foreach ($invalid as [$from, $to]) {
    if ($service->isValidTransition($from, $to)) {
        fwrite(STDERR, "FAIL: invalid transition {$from} -> {$to} accepted" . PHP_EOL);
        exit(1);
    }
}

if (!$service->isValidTransition('pending', 'pending')) {
    fwrite(STDERR, 'FAIL: idempotent same-state transition rejected.' . PHP_EOL);
    exit(1);
}

echo "PASS: admin_order_transition_test\n";
