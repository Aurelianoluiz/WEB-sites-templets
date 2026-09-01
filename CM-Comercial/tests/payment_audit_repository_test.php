<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\PaymentAuditRepository;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

final class PaymentAuditRepositoryTestFailure extends RuntimeException {}

function auditAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new PaymentAuditRepositoryTestFailure($message);
    }
}

function auditSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new PaymentAuditRepositoryTestFailure(
            $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
        );
    }
}

function auditThrows(callable $callback, string $message): void
{
    try {
        $callback();
    } catch (Throwable) {
        return;
    }
    throw new PaymentAuditRepositoryTestFailure($message);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec(<<<'SQL'
CREATE TABLE orders (
    id INTEGER PRIMARY KEY,
    customer_id INTEGER NULL,
    total_amount NUMERIC NOT NULL,
    currency TEXT NOT NULL DEFAULT 'BRL',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE payment_transactions (
    id INTEGER PRIMARY KEY,
    order_id INTEGER NOT NULL,
    provider TEXT NOT NULL,
    provider_payment_id TEXT NULL,
    external_reference TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    status TEXT NOT NULL,
    amount NUMERIC NOT NULL,
    currency TEXT NOT NULL DEFAULT 'BRL',
    pix_qr_code TEXT NULL,
    pix_qr_code_base64 TEXT NULL,
    gateway_payload TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE payment_audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    payment_transaction_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    old_status TEXT NULL,
    new_status TEXT NULL,
    actor TEXT NOT NULL DEFAULT 'system',
    idempotency_key TEXT NULL UNIQUE,
    payload TEXT NULL,
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (payment_transaction_id) REFERENCES payment_transactions(id)
);
SQL);

$pdo->exec("INSERT INTO orders (id, customer_id, total_amount, currency, created_at, updated_at) VALUES (10, 1, 100.00, 'BRL', '2026-08-31 10:00:00', '2026-08-31 10:00:00')");
$pdo->exec("INSERT INTO orders (id, customer_id, total_amount, currency, created_at, updated_at) VALUES (11, 1, 50.00, 'BRL', '2026-08-31 11:00:00', '2026-08-31 11:00:00')");
$pdo->exec("INSERT INTO payment_transactions (id, order_id, provider, provider_payment_id, external_reference, idempotency_key, status, amount, currency, created_at, updated_at) VALUES (100, 10, 'mercadopago', 'mp-100', 'order-10', 'pay-100', 'paid', 100.00, 'BRL', '2026-08-31 10:01:00', '2026-08-31 10:01:00')");
$pdo->exec("INSERT INTO payment_transactions (id, order_id, provider, provider_payment_id, external_reference, idempotency_key, status, amount, currency, created_at, updated_at) VALUES (101, 11, 'mercadopago', 'mp-101', 'order-11', 'pay-101', 'cancelled', 50.00, 'BRL', '2026-08-31 11:01:00', '2026-08-31 11:01:00')");

$repository = new PaymentAuditRepository($pdo);

$id = $repository->logEvent([
    'payment_transaction_id' => 100,
    'event_type' => 'gateway_status_received',
    'old_status' => 'pending',
    'new_status' => 'paid',
    'actor' => 'system',
    'idempotency_key' => 'event-100',
    'payload' => [
        'source' => 'gateway',
        'note' => 'safe metadata',
        'access_token' => 'SHOULD_NOT_PERSIST',
        'gateway_payload' => ['secret' => 'SHOULD_NOT_PERSIST'],
        'pix_qr_code' => 'SHOULD_NOT_PERSIST',
    ],
]);
auditAssert($id > 0, 'Audit event was not inserted.');
auditAssert($repository->isEventProcessed('event-100'), 'Idempotency key was not persisted.');

$duplicateId = $repository->logEvent([
    'payment_transaction_id' => 100,
    'event_type' => 'duplicate_attempt',
    'actor' => 'system',
    'idempotency_key' => 'event-100',
]);
auditSame($id, $duplicateId, 'Idempotent event must return the original audit id.');

auditSame(1, (int)$pdo->query("SELECT COUNT(*) FROM payment_audit_log WHERE idempotency_key = 'event-100'")->fetchColumn(), 'Duplicate idempotent event was inserted.');

$resolution = $repository->logResolution(
    100,
    'admin@example.test',
    'paid',
    'refunded',
    'Manual financial correction',
    'resolve-100'
);
auditAssert($resolution, 'Resolution audit event failed.');

auditSame(2, count($repository->getHistoryByTransactionId(100)), 'Transaction history count is incorrect.');
auditSame(1, count($repository->getHistoryByOrderId(10)), 'Order history count is incorrect.');
auditSame(0, count($repository->getHistoryByOrderId(11)), 'Unrelated order history must be empty.');

$history = $repository->getHistoryByTransactionId(100);
$historyJson = json_encode($history, JSON_THROW_ON_ERROR);
auditAssert(!str_contains($historyJson, 'SHOULD_NOT_PERSIST'), 'Sensitive values leaked into audit history.');
auditAssert(!str_contains($historyJson, 'access_token'), 'Sensitive key leaked into audit history.');
auditAssert(!str_contains($historyJson, 'gateway_payload'), 'Gateway payload leaked into audit history.');
auditAssert(!str_contains($historyJson, 'pix_qr_code'), 'PIX QR code leaked into audit history.');
auditAssert(!str_contains($historyJson, 'event-100'), 'Idempotency key must not be returned as an audit payload field.');

$logs = $repository->listAuditLogs([], 100, 0);
auditSame(2, count($logs), 'Audit log list count is incorrect.');

auditThrows(
    static fn(): int => $repository->logEvent(['event_type' => 'missing_transaction']),
    'Missing transaction id must be rejected.'
);
auditThrows(
    static fn(): bool => $repository->isEventProcessed(''),
    'Empty idempotency keys must be rejected.'
);
auditThrows(
    static fn(): array => $repository->listAuditLogs(['date_from' => '2026-09-02', 'date_to' => '2026-09-01'], 10, 0),
    'Reversed audit date range must be rejected.'
);

$source = (string)file_get_contents(__DIR__ . '/../src/Repositories/PaymentAuditRepository.php');
auditAssert((bool)preg_match('/->prepare\s*\(/', $source), 'PaymentAuditRepository must use prepared statements.');
auditAssert(!str_contains($source, 'payments '), 'Legacy payments table must not be used.');
auditAssert(str_contains($source, 'payment_audit_log'), 'Canonical audit table is missing.');
auditAssert(str_contains($source, 'idempotency_key'), 'Persistent idempotency key support is missing.');

echo "PASS: payment_audit_repository_test\n";
