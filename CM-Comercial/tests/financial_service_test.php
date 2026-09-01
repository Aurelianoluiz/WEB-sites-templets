<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\PaymentTransactionRepository;
use App\Services\FinancialService;

final class FinancialServiceTestFailure extends \RuntimeException
{
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new FinancialServiceTestFailure(
            $message . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true)
        );
    }
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new FinancialServiceTestFailure($message);
    }
}

function assert_throws(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (\Throwable) {
        return;
    }

    throw new FinancialServiceTestFailure($message);
}

$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

$pdo->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL
);
CREATE TABLE orders (
    id INTEGER PRIMARY KEY,
    customer_id INTEGER NULL,
    status TEXT NOT NULL,
    payment_status TEXT NOT NULL,
    total_amount REAL NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE payment_transactions (
    id INTEGER PRIMARY KEY,
    order_id INTEGER NOT NULL,
    provider TEXT NOT NULL,
    provider_payment_id TEXT NULL,
    external_reference TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    status TEXT NOT NULL,
    amount REAL NOT NULL,
    currency TEXT NOT NULL,
    method TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE transaction_probe (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    value TEXT NOT NULL
);
SQL);

$pdo->exec("INSERT INTO users (id, name, email) VALUES (1, 'Cliente Um', 'cliente1@example.test')");
$pdo->exec("INSERT INTO users (id, name, email) VALUES (2, 'Cliente Dois', 'cliente2@example.test')");

$orderStatement = $pdo->prepare(
    'INSERT INTO orders (id, customer_id, status, payment_status, total_amount, created_at)
     VALUES (?, ?, ?, ?, ?, ?)'
);
foreach ([
    [101, 1, 'confirmed', 'paid', 120.00, '2026-08-01 10:00:00'],
    [102, 1, 'pending', 'pending', 80.00, '2026-08-02 10:00:00'],
    [103, 1, 'cancelled', 'refunded', 50.00, '2026-08-03 10:00:00'],
    [104, 1, 'confirmed', 'authorized', 75.00, '2026-08-04 10:00:00'],
    [105, 2, 'confirmed', 'paid', 999.00, '2026-08-05 10:00:00'],
] as $order) {
    $orderStatement->execute($order);
}

$paymentStatement = $pdo->prepare(
    'INSERT INTO payment_transactions
        (id, order_id, provider, provider_payment_id, external_reference, idempotency_key, status, amount, currency, method, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ([
    [201, 101, 'mercadopago', 'mp-201', 'order-101', 'idem-201', 'paid', 120.00, 'BRL', 'pix', '2026-08-01 10:05:00', '2026-08-01 10:06:00'],
    [202, 102, 'mercadopago', null, 'order-102', 'idem-202', 'pending', 80.00, 'BRL', 'pix', '2026-08-02 10:05:00', '2026-08-02 10:05:00'],
    [203, 103, 'mercadopago', 'mp-203', 'order-103', 'idem-203', 'refunded', 50.00, 'BRL', 'pix', '2026-08-03 10:05:00', '2026-08-03 10:06:00'],
    [204, 104, 'mercadopago', 'mp-204', 'order-104', 'idem-204', 'authorized', 75.00, 'BRL', 'credit_card', '2026-08-04 10:05:00', '2026-08-04 10:06:00'],
    [205, 105, 'mercadopago', 'mp-205', 'order-105', 'idem-205', 'paid', 999.00, 'BRL', 'pix', '2026-08-05 10:05:00', '2026-08-05 10:06:00'],
] as $payment) {
    $paymentStatement->execute($payment);
}

$service = new FinancialService($pdo, new PaymentTransactionRepository($pdo));

$summary = $service->getCustomerFinancialSummary(1);
assert_same(4, $summary['count'], 'Customer aggregation count is incorrect.');
assert_same(325.00, $summary['total'], 'Customer aggregation total is incorrect.');
assert_same(120.00, $summary['paid'], 'Paid aggregation is incorrect.');
assert_same(50.00, $summary['refunded'], 'Refunded aggregation is incorrect.');
assert_same(80.00, $summary['pending'], 'Pending aggregation is incorrect.');
assert_same(75.00, $summary['authorized'], 'Authorized aggregation is incorrect.');
assert_same(0.00, $summary['failed'], 'Failed aggregation is incorrect.');
assert_same(0.00, $summary['cancelled'], 'Cancelled aggregation is incorrect.');

$history = $service->getCustomerFinancialHistory(1, 100, 0);
assert_same(4, count($history), 'Customer history must exclude another customer.');

$periodRows = $service->listReconciliation([
    'customer_id' => 1,
    'date_from' => '2026-08-02',
    'date_to' => '2026-08-03',
], 100, 0);
assert_same(2, count($periodRows), 'Period filter returned an incorrect number of rows.');
assert_same(203, (int)$periodRows[0]['id'], 'Period filter first result is incorrect.');
assert_same(202, (int)$periodRows[1]['id'], 'Period filter second result is incorrect.');

$paidRows = $service->listReconciliation([
    'status' => 'paid',
    'provider' => 'mercadopago',
], 100, 0);
assert_same(2, count($paidRows), 'Status/provider filter is incorrect.');

$pageOne = $service->getCustomerFinancialHistory(1, 2, 0);
$pageTwo = $service->getCustomerFinancialHistory(1, 2, 2);
assert_same(2, count($pageOne), 'First pagination page size is incorrect.');
assert_same(2, count($pageTwo), 'Second pagination page size is incorrect.');
assert_true((int)$pageOne[0]['id'] !== (int)$pageTwo[0]['id'], 'Pagination pages overlap.');

assert_throws(
    static fn (): array => $service->getCustomerFinancialHistory(1, 0, 0),
    'Zero pagination limit must be rejected.'
);
assert_throws(
    static fn (): array => $service->getCustomerFinancialHistory(1, 20, -1),
    'Negative pagination offset must be rejected.'
);
assert_throws(
    static fn (): array => $service->listReconciliation(['status' => 'unknown']),
    'Unknown status must be rejected.'
);
assert_throws(
    static fn (): array => $service->listReconciliation([
        'date_from' => '2026-08-04',
        'date_to' => '2026-08-02',
    ]),
    'Reversed date range must be rejected.'
);

$committedResult = $service->transaction(
    static function (\PDO $db): string {
        $stmt = $db->prepare('INSERT INTO transaction_probe (value) VALUES (?)');
        $stmt->execute(['committed']);
        return 'committed';
    }
);
assert_same('committed', $committedResult, 'Commit result is incorrect.');
assert_same(
    1,
    (int)$pdo->query("SELECT COUNT(*) FROM transaction_probe WHERE value = 'committed'")->fetchColumn(),
    'Committed transaction did not persist.'
);

assert_throws(
    static function () use ($service): void {
        $service->transaction(
            static function (\PDO $db): never {
                $stmt = $db->prepare('INSERT INTO transaction_probe (value) VALUES (?)');
                $stmt->execute(['rolled-back']);
                throw new \RuntimeException('forced rollback');
            }
        );
    },
    'Rollback transaction must rethrow the underlying error.'
);
assert_same(
    0,
    (int)$pdo->query("SELECT COUNT(*) FROM transaction_probe WHERE value = 'rolled-back'")->fetchColumn(),
    'Rolled-back transaction persisted data.'
);

$pdo->beginTransaction();
try {
    assert_throws(
        static fn (): mixed => $service->transaction(static fn (\PDO $db): string => 'nested'),
        'Nested transactions must be rejected.'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "PASS: financial_service_test\n";
