<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\PaymentTransactionRepository;
use App\Services\FinancialService;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class FinancialServiceTestFailure extends RuntimeException
{
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new FinancialServiceTestFailure($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new FinancialServiceTestFailure(
            $message . ' Expected: ' . var_export($expected, true)
            . ' Actual: ' . var_export($actual, true)
        );
    }
}

function assert_throws(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }

    throw new FinancialServiceTestFailure($message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

$pdo->exec(<<<'SQL'
CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL,
    email TEXT NOT NULL
);
CREATE TABLE orders (
    id INTEGER PRIMARY KEY,
    user_id INTEGER NULL,
    customer_name TEXT NOT NULL,
    email TEXT NOT NULL,
    status TEXT NOT NULL,
    payment_status TEXT NOT NULL,
    total REAL NOT NULL,
    created_at TEXT NOT NULL
);
CREATE TABLE payments (
    id INTEGER PRIMARY KEY,
    order_id INTEGER NOT NULL,
    amount REAL NOT NULL,
    method TEXT NOT NULL,
    status TEXT NOT NULL,
    transaction_id TEXT NULL,
    provider TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE financial_transaction_probe (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    value TEXT NOT NULL
);
SQL);

$pdo->exec("INSERT INTO users (id, name, email) VALUES (1, 'Cliente Um', 'cliente1@example.test')");
$pdo->exec("INSERT INTO users (id, name, email) VALUES (2, 'Cliente Dois', 'cliente2@example.test')");

$orders = [
    [101, 1, 'Cliente Um', 'cliente1@example.test', 'confirmed', 'paid', 120.00, '2026-08-01 10:00:00'],
    [102, 1, 'Cliente Um', 'cliente1@example.test', 'pending', 'pending', 80.00, '2026-08-02 10:00:00'],
    [103, 1, 'Cliente Um', 'cliente1@example.test', 'cancelled', 'refunded', 50.00, '2026-08-03 10:00:00'],
    [104, 1, 'Cliente Um', 'cliente1@example.test', 'confirmed', 'authorized', 75.00, '2026-08-04 10:00:00'],
    [105, 2, 'Cliente Dois', 'cliente2@example.test', 'confirmed', 'paid', 999.00, '2026-08-05 10:00:00'],
];

$orderStmt = $pdo->prepare(
    'INSERT INTO orders (id, user_id, customer_name, email, status, payment_status, total, created_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($orders as $order) {
    $orderStmt->execute($order);
}

$payments = [
    [201, 101, 120.00, 'pix', 'paid', 'mp-201', 'mercadopago', '2026-08-01 10:05:00', '2026-08-01 10:06:00'],
    [202, 102, 80.00, 'pix', 'pending', null, 'mercadopago', '2026-08-02 10:05:00', '2026-08-02 10:05:00'],
    [203, 103, 50.00, 'pix', 'refunded', 'mp-203', 'mercadopago', '2026-08-03 10:05:00', '2026-08-03 10:06:00'],
    [204, 104, 75.00, 'credit_card', 'authorized', 'mp-204', 'mercadopago', '2026-08-04 10:05:00', '2026-08-04 10:06:00'],
    [205, 105, 999.00, 'pix', 'paid', 'mp-205', 'mercadopago', '2026-08-05 10:05:00', '2026-08-05 10:06:00'],
];

$paymentStmt = $pdo->prepare(
    'INSERT INTO payments
        (id, order_id, amount, method, status, transaction_id, provider, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
foreach ($payments as $payment) {
    $paymentStmt->execute($payment);
}

$repository = new PaymentTransactionRepository($pdo);
$service = new FinancialService($pdo, $repository);

// 1. Customer aggregation.
$summary = $service->getCustomerFinancialSummary(1);
assert_same(4, $summary['count'], 'Customer summary count is incorrect.');
assert_same(325.00, $summary['total'], 'Customer gross total is incorrect.');
assert_same(120.00, $summary['paid'], 'Paid aggregation is incorrect.');
assert_same(50.00, $summary['refunded'], 'Refunded aggregation is incorrect.');
assert_same(80.00, $summary['pending'], 'Pending aggregation is incorrect.');
assert_same(75.00, $summary['authorized'], 'Authorized aggregation is incorrect.');
assert_same(0.00, $summary['failed'], 'Failed aggregation is incorrect.');
assert_same(0.00, $summary['cancelled'], 'Cancelled aggregation is incorrect.');

// 2. Customer and period filters.
$history = $service->getCustomerFinancialHistory(1, 100, 0);
assert_same(4, count($history), 'Customer history must exclude another customer.');

$periodRows = $service->listReconciliation([
    'customer_id' => 1,
    'date_from' => '2026-08-02',
    'date_to' => '2026-08-03',
], 100, 0);
assert_same(2, count($periodRows), 'Period filter returned an incorrect number of rows.');
assert_same(203, (int)$periodRows[0]['id'], 'Period filter ordering/result is incorrect.');
assert_same(202, (int)$periodRows[1]['id'], 'Period filter second result is incorrect.');

// 3. Status/provider filters.
$paidRows = $service->listReconciliation([
    'status' => 'paid',
    'provider' => 'mercadopago',
], 100, 0);
assert_same(2, count($paidRows), 'Status/provider filters are incorrect.');

// 4. Safe pagination.
$pageOne = $service->getCustomerFinancialHistory(1, 2, 0);
$pageTwo = $service->getCustomerFinancialHistory(1, 2, 2);
assert_same(2, count($pageOne), 'First page must contain two records.');
assert_same(2, count($pageTwo), 'Second page must contain two records.');
assert_true(
    (int)$pageOne[0]['id'] !== (int)$pageTwo[0]['id'],
    'Pagination pages must not overlap at the first record.'
);
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
    'Unknown financial status must be rejected.'
);
assert_throws(
    static fn (): array => $service->listReconciliation(['date_from' => '2026-08-04', 'date_to' => '2026-08-02']),
    'Reversed date range must be rejected.'
);

// 5. Transaction commit.
$committed = $service->transaction(
    static function (PDO $db): string {
        $stmt = $db->prepare('INSERT INTO financial_transaction_probe (value) VALUES (?)');
        $stmt->execute(['committed']);
        return 'committed';
    }
);
assert_same('committed', $committed, 'Committed transaction must return its result.');
$committedCount = (int)$pdo->query("SELECT COUNT(*) FROM financial_transaction_probe WHERE value = 'committed'")->fetchColumn();
assert_same(1, $committedCount, 'Committed transaction must persist its write.');

// 6. Transaction rollback.
assert_throws(
    static function () use ($service): void {
        $service->transaction(
            static function (PDO $db): never {
                $stmt = $db->prepare('INSERT INTO financial_transaction_probe (value) VALUES (?)');
                $stmt->execute(['rolled-back']);
                throw new RuntimeException('forced rollback');
            }
        );
    },
    'A failed transaction must throw.'
);
$rolledBackCount = (int)$pdo->query("SELECT COUNT(*) FROM financial_transaction_probe WHERE value = 'rolled-back'")->fetchColumn();
assert_same(0, $rolledBackCount, 'Rolled-back transaction must not persist its write.');

// 7. Nested transaction must be rejected defensively.
$pdo->beginTransaction();
try {
    assert_throws(
        static fn (): mixed => $service->transaction(static fn (PDO $db): string => 'nested'),
        'Nested FinancialService transactions must be rejected.'
    );
} finally {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
}

echo "PASS: financial_service_test\n";
