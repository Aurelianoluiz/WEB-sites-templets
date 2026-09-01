<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\ReconciliationRepository;

final class ReconciliationRepositoryTestFailure extends RuntimeException
{
}

function rrAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new ReconciliationRepositoryTestFailure($message);
    }
}

function rrAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new ReconciliationRepositoryTestFailure(
            $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
        );
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec(<<<'SQL'
CREATE TABLE orders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    customer_id INTEGER NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    payment_status TEXT NOT NULL DEFAULT 'pending',
    total_amount NUMERIC NOT NULL,
    currency TEXT NOT NULL DEFAULT 'BRL',
    shipping_amount NUMERIC NOT NULL DEFAULT 0,
    idempotency_key TEXT UNIQUE,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);

CREATE TABLE payment_transactions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    provider TEXT NOT NULL,
    provider_payment_id TEXT,
    external_reference TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending',
    amount NUMERIC NOT NULL,
    currency TEXT NOT NULL DEFAULT 'BRL',
    pix_qr_code TEXT,
    pix_qr_code_base64 TEXT,
    pix_expires_at TEXT,
    gateway_payload TEXT,
    webhook_event_id TEXT,
    last_webhook_at TEXT,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL,
    UNIQUE(provider, idempotency_key),
    FOREIGN KEY(order_id) REFERENCES orders(id)
);
SQL);

$pdo->exec("INSERT INTO orders (id, customer_id, status, payment_status, total_amount, currency, created_at, updated_at) VALUES
    (1, 10, 'confirmed', 'paid', 100.00, 'BRL', '2026-08-31 09:00:00', '2026-08-31 09:01:00'),
    (2, 10, 'confirmed', 'paid', 120.00, 'BRL', '2026-08-31 09:05:00', '2026-08-31 09:06:00'),
    (3, 11, 'cancelled', 'paid', 90.00, 'BRL', '2026-08-31 09:10:00', '2026-08-31 09:11:00'),
    (4, 11, 'confirmed', 'pending', 75.00, 'BRL', '2026-08-31 09:15:00', '2026-08-31 09:16:00'),
    (5, 12, 'confirmed', 'pending', 60.00, 'BRL', '2026-08-31 09:20:00', '2026-08-31 09:21:00')");

$pdo->exec("INSERT INTO payment_transactions
    (id, order_id, provider, provider_payment_id, external_reference, idempotency_key, status, amount, currency, gateway_payload, created_at, updated_at)
    VALUES
    (1, 1, 'mercadopago', 'mp-1', 'order-1', 'idem-1', 'paid', 100.00, 'BRL', '{\"secret\":\"must-not-leak\"}', '2026-08-31 09:02:00', '2026-08-31 09:03:00'),
    (2, 2, 'mercadopago', 'mp-2', 'order-2', 'idem-2', 'paid', 125.00, 'BRL', '{\"token\":\"must-not-leak\"}', '2026-08-31 09:07:00', '2026-08-31 09:08:00'),
    (3, 3, 'mercadopago', 'mp-3', 'order-3', 'idem-3', 'paid', 90.00, 'BRL', '{\"credential\":\"must-not-leak\"}', '2026-08-31 09:12:00', '2026-08-31 09:13:00'),
    (4, 4, 'mercadopago', 'mp-4', 'order-4', 'idem-4', 'pending', 75.00, 'BRL', '{\"raw\":\"must-not-leak\"}', '2026-08-31 09:17:00', '2026-08-31 09:18:00')");

// Simulate corrupted legacy data while retaining the canonical FK definition.
$pdo->exec('PRAGMA foreign_keys = OFF');
$pdo->exec("INSERT INTO payment_transactions
    (id, order_id, provider, provider_payment_id, external_reference, idempotency_key, status, amount, currency, created_at, updated_at)
    VALUES (99, 999, 'mercadopago', 'mp-orphan', 'orphan-order', 'idem-orphan', 'paid', 33.00, 'BRL', '2026-08-31 09:30:00', '2026-08-31 09:31:00')");
$pdo->exec('PRAGMA foreign_keys = ON');

$repository = new ReconciliationRepository($pdo);

$perfect = $repository->list(['order_id' => 1], 10, 0)[0] ?? [];
rrAssertSame('reconciled', $perfect['reconciliation_status'] ?? null, 'Perfect match classification failed.');
rrAssertSame('payment_transaction', $perfect['record_type'] ?? null, 'Perfect match record type failed.');
rrAssert(!array_key_exists('gateway_payload', $perfect), 'Sensitive gateway payload leaked into result.');
rrAssert(!array_key_exists('pix_qr_code_base64', $perfect), 'Sensitive QR payload leaked into result.');

$amountMismatch = $repository->list(['order_id' => 2], 10, 0)[0] ?? [];
rrAssertSame('divergent', $amountMismatch['reconciliation_status'] ?? null, 'Amount mismatch was not detected.');
rrAssertSame('amount_mismatch', $amountMismatch['divergence_reason'] ?? null, 'Amount mismatch reason failed.');

$statusMismatch = $repository->list(['order_id' => 3], 10, 0)[0] ?? [];
rrAssertSame('divergent', $statusMismatch['reconciliation_status'] ?? null, 'Status mismatch was not detected.');
rrAssertSame('status_mismatch', $statusMismatch['divergence_reason'] ?? null, 'Status mismatch reason failed.');

$orphan = $repository->list(['order_id' => 999], 10, 0)[0] ?? [];
rrAssertSame('inconsistent', $orphan['reconciliation_status'] ?? null, 'Orphan transaction classification failed.');
rrAssertSame('orphan_transaction', $orphan['divergence_reason'] ?? null, 'Orphan transaction reason failed.');

$missing = $repository->list(['order_id' => 5], 10, 0)[0] ?? [];
rrAssertSame('inconsistent', $missing['reconciliation_status'] ?? null, 'Order without transaction classification failed.');
rrAssertSame('missing_payment_transaction', $missing['divergence_reason'] ?? null, 'Missing transaction reason failed.');
rrAssertSame('order_without_transaction', $missing['record_type'] ?? null, 'Missing transaction record type failed.');

$summary = $repository->summarize();
rrAssertSame(6, $summary['total'], 'Aggregate total must include all reconciliation rows.');
rrAssertSame(1, $summary['reconciled'], 'Aggregate reconciled count failed.');
rrAssertSame(2, $summary['divergent'], 'Aggregate divergent count failed.');
rrAssertSame(1, $summary['pending'], 'Aggregate pending count failed.');
rrAssertSame(2, $summary['inconsistent'], 'Aggregate inconsistent count failed.');
rrAssertSame(1, $summary['amount_mismatches'], 'Aggregate amount mismatch count failed.');
rrAssertSame(1, $summary['status_mismatches'], 'Aggregate status mismatch count failed.');
rrAssertSame(1, $summary['orphan_transactions'], 'Aggregate orphan count failed.');
rrAssertSame(1, $summary['missing_transactions'], 'Aggregate missing transaction count failed.');
rrAssertSame(543.00, $summary['total_amount'], 'Aggregate amount failed.');

$providerRows = $repository->list(['provider' => 'mercadopago'], 2, 0);
rrAssertSame(2, count($providerRows), 'Pagination limit was not respected.');
rrAssertSame(4, $repository->count(['provider' => 'mercadopago']), 'Filtered count failed.');
rrAssertSame(2, count($repository->list(['provider' => 'mercadopago'], 2, 2)), 'Pagination offset failed.');

$dateRows = $repository->list([
    'date_from' => '2026-08-31',
    'date_to' => '2026-08-31',
], 100, 0);
rrAssertSame(6, count($dateRows), 'Inclusive date filtering failed.');

$customerRows = $repository->list(['customer_id' => 10], 100, 0);
rrAssertSame(4, count($customerRows), 'Customer filter failed.');

$source = (string)file_get_contents(__DIR__ . '/../src/Repositories/ReconciliationRepository.php');
rrAssert((bool)preg_match('/->prepare\s*\(/i', $source), 'Repository must use prepared statements.');
rrAssert(!str_contains($source, 'gateway_payload\n'), 'Repository must not select raw gateway payload.');
rrAssert(!str_contains($source, 'pix_qr_code_base64'), 'Repository must not expose QR payload.');

// Canonical schema uses prepared queries; the repository itself must not reference legacy `payments`.
rrAssert(!preg_match('/\bpayments\b/i', $source), 'Legacy payments table must not be referenced.');
rrAssert((bool)preg_match('/payment_transactions/i', $source), 'Canonical payment_transactions table must be used.');
rrAssert((bool)preg_match('/total_amount/i', $source), 'Canonical orders.total_amount must be used.');
rrAssert((bool)preg_match('/customer_id/i', $source), 'Canonical orders.customer_id must be used.');

echo "PASS: reconciliation_repository_test\n";
