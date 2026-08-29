<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/payment_core.php';
require_once __DIR__ . '/../integrations/payment_service.php';

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensure_payment_schema($pdo);

$pdo->exec("CREATE TABLE orders (
    id INTEGER PRIMARY KEY,
    status TEXT NOT NULL,
    payment_status TEXT
)");
$pdo->exec("INSERT INTO orders (id,status,payment_status) VALUES (501,'pending','pending')");

$paymentId = upsert_payment($pdo, 501, 80.00, 'pix');

try {
    apply_gateway_event(
        $pdo,
        $paymentId,
        'evt-atomic-1',
        'payment.updated',
        'paid',
        'tx-501',
        ['transaction_amount' => 80.00],
        static function (PDO $transactionalPdo): void {
            $transactionalPdo->prepare("UPDATE orders SET status='confirmed', payment_status='paid' WHERE id=501")->execute();
            throw new RuntimeException('simulated downstream failure');
        }
    );
    fwrite(STDERR, "FAIL: callback exception should abort transaction\n");
    exit(1);
} catch (RuntimeException $e) {
    // expected
}

$paymentStatus = $pdo->query("SELECT status FROM payments WHERE id={$paymentId}")->fetchColumn();
$order = $pdo->query("SELECT status, payment_status FROM orders WHERE id=501")->fetch(PDO::FETCH_ASSOC);
$eventCount = (int)$pdo->query("SELECT COUNT(*) FROM payment_events WHERE event_id='evt-atomic-1'")->fetchColumn();

if ($paymentStatus !== 'pending' || $order['status'] !== 'pending' || $order['payment_status'] !== 'pending' || $eventCount !== 0) {
    fwrite(STDERR, "FAIL: payment/order/event state was not rolled back atomically\n");
    exit(1);
}

echo "PASS: payment/order atomicity\n";
