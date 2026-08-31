<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\PaymentTransactionRepository;
use App\Services\FinancialService;

final class PaymentsControllerTestFailure extends \RuntimeException
{
}

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new PaymentsControllerTestFailure($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new PaymentsControllerTestFailure(
            $message
            . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true)
        );
    }
}

$controllerPath = __DIR__ . '/../admin/payments.php';
$viewPath = __DIR__ . '/../admin/views/payments.php';

$controller = (string)file_get_contents($controllerPath);
$view = (string)file_get_contents($viewPath);

assert_true(str_contains($controller, 'require_admin();'), 'Admin guard is missing.');
assert_true(
    str_contains($controller, '$container->get(FinancialService::class)'),
    'FinancialService must be resolved through the Container.'
);
assert_true(
    !preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $controller),
    'Controller must not contain SQL keywords.'
);
assert_true(!str_contains($controller, '->prepare('), 'Controller must not prepare SQL.');
assert_true(!str_contains($controller, '->query('), 'Controller must not query PDO directly.');
assert_true(!str_contains($controller, '->exec('), 'Controller must not execute PDO directly.');
assert_true(str_contains($controller, "['status' => $status"]), 'Status filter is missing.');
assert_true(str_contains($controller, "'provider' => $provider"), 'Provider filter is missing.');
assert_true(str_contains($controller, "'search' => $search"), 'Search filter is missing.');
assert_true(str_contains($controller, "'date_from' => $dateFrom"), 'Start date filter is missing.');
assert_true(str_contains($controller, "'date_to' => $dateTo"), 'End date filter is missing.');
assert_true(str_contains($controller, 'customer_id'), 'Customer id filter is missing.');
assert_true(str_contains($controller, 'order_id'), 'Order id filter is missing.');
assert_true(str_contains($controller, 'min(1)'), 'Defensive minimum pagination bound is missing.');
assert_true(str_contains($controller, 'min(') && str_contains($controller, 'PAYMENTS_LIMIT_MAX'), 'Maximum pagination bound is missing.');
assert_true(str_contains($controller, '$page = max(1'), 'Page lower bound is missing.');
assert_true(str_contains($view, 'status-pill'), 'Payment status badge is missing.');
assert_true(str_contains($view, 'payments.php?'), 'Pagination query links are missing.');
assert_true(str_contains($view, 'date_from'), 'View start date filter is missing.');
assert_true(str_contains($view, 'date_to'), 'View end date filter is missing.');

$forbidden = [
    'payload',
    'raw_payload',
    'gateway_response',
    'access_token',
    'webhook_secret',
    'client_secret',
    'authorization',
];
foreach ($forbidden as $field) {
    assert_true(
        !str_contains($controller, "['{$field}']")
        && !str_contains($controller, "['{$field}'")
        && !str_contains($view, "['{$field}']")
        && !str_contains($view, "['{$field}'"),
        "Sensitive field '{$field}' must not reach the UI."
    );
}

// Integration-level verification of the actual Service/Repository contract.
$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);

$pdo->exec(<<<'SQL'
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
SQL);

$pdo->exec("INSERT INTO orders VALUES (1, 101, 'Cliente A', 'a@example.test', 'confirmed', 'paid', 150.00, '2026-08-28 10:00:00')");
$pdo->exec("INSERT INTO orders VALUES (2, 102, 'Cliente B', 'b@example.test', 'confirmed', 'pending', 75.00, '2026-08-29 10:00:00')");
$pdo->exec("INSERT INTO payments VALUES (1, 1, 150.00, 'pix', 'paid', 'mp-001', 'mercadopago', '2026-08-28 10:05:00', '2026-08-28 10:06:00')");
$pdo->exec("INSERT INTO payments VALUES (2, 2, 75.00, 'pix', 'pending', 'mp-002', 'mercadopago', '2026-08-29 10:05:00', '2026-08-29 10:06:00')");

$service = new FinancialService(
    $pdo,
    new PaymentTransactionRepository($pdo)
);

$filtered = $service->listReconciliation(
    [
        'status' => 'paid',
        'provider' => 'mercadopago',
        'search' => 'a@example.test',
        'date_from' => '2026-08-28',
        'date_to' => '2026-08-28',
    ],
    1,
    0
);

assert_same(1, count($filtered), 'Service filter result is incorrect.');
assert_same(1, (int)$filtered[0]['id'], 'Service filter returned the wrong payment.');

$customerSearch = $service->listReconciliation(
    ['search' => 'b@example.test'],
    100,
    0
);

assert_same(1, count($customerSearch), 'Customer email search is not supported by the repository.');
assert_same(2, (int)$customerSearch[0]['id'], 'Customer email search returned the wrong payment.');

$page = $service->listReconciliation([], 1, 1);
assert_same(1, count($page), 'Pagination did not return one record.');
assert_same(1, (int)$page[0]['id'], 'Pagination ordering is incorrect.');

$summary = $service->getReconciliationSummary(['provider' => 'mercadopago']);
assert_same(2, $summary['count'], 'Summary count is incorrect.');
assert_same(225.00, $summary['total'], 'Summary total is incorrect.');

echo "PASS: payments_controller_test\n";
