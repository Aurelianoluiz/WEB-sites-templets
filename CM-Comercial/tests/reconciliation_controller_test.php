<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\PaymentTransactionRepository;
use App\Repositories\OrderRepository;
use App\Services\ReconciliationService;

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        throw new \RuntimeException($message);
    }
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new \RuntimeException(
            $message
            . ' expected=' . var_export($expected, true)
            . ' actual=' . var_export($actual, true)
        );
    }
}

$controllerPath = __DIR__ . '/../admin/reconciliation.php';
$viewPath = __DIR__ . '/../admin/views/reconciliation.php';
$controller = (string)file_get_contents($controllerPath);
$view = (string)file_get_contents($viewPath);

assert_true(str_contains($controller, 'require_admin();'), 'Admin guard is missing.');
assert_true(
    str_contains($controller, '$container->get(ReconciliationService::class)'),
    'ReconciliationService must be resolved through the Container.'
);
assert_true(!preg_match('/\b(SELECT|INSERT|UPDATE|DELETE)\b/i', $controller), 'Controller must not contain SQL keywords.');
assert_true(!str_contains($controller, '->prepare('), 'Controller must not prepare SQL statements.');
assert_true(!str_contains($controller, '->query('), 'Controller must not query the database directly.');
assert_true(!str_contains($controller, '->exec('), 'Controller must not execute database statements directly.');
assert_true(str_contains($controller, "header('Content-Type: text/csv; charset=UTF-8');"), 'CSV content type header is missing.');
assert_true(str_contains($controller, 'Content-Disposition'), 'CSV content disposition header is missing.');
assert_true(str_contains($controller, "['=', '+', '-', '@']"), 'CSV injection guard is missing.');
assert_true(!str_contains($controller, 'paymentRepository'), 'Controller must not resolve repositories directly.');
assert_true(!str_contains($controller, 'payload'), 'Controller must not expose raw payload fields.');
assert_true(!str_contains($controller, 'access_token'), 'Controller must not expose access tokens.');
assert_true(!str_contains($controller, 'webhook_secret'), 'Controller must not expose webhook secrets.');

assert_true(str_contains($view, 'status-pill'), 'Status badge rendering is missing.');
assert_true(str_contains($view, 'Exportar CSV'), 'CSV export control is missing.');
assert_true(str_contains($view, 'date_from'), 'Start date filter is missing.');
assert_true(str_contains($view, 'date_to'), 'End date filter is missing.');
assert_true(str_contains($view, 'customer_id'), 'Customer filter is missing.');
assert_true(str_contains($view, 'order_id'), 'Order filter is missing.');
assert_true(str_contains($view, 'provider'), 'Provider filter is missing.');
assert_true(str_contains($view, 'page'), 'Pagination controls are missing.');

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
    amount REAL NOT NULL,
    method TEXT NOT NULL,
    status TEXT NOT NULL,
    provider_payment_id TEXT NULL,
    external_reference TEXT NOT NULL,
    idempotency_key TEXT NOT NULL,
    provider TEXT NOT NULL,
    currency TEXT NOT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

$pdo->exec("INSERT INTO users VALUES (10, 'Cliente A', 'cliente@example.test')");
$pdo->exec("INSERT INTO users VALUES (20, 'Cliente Dois', 'dois@example.test')");
$pdo->exec("INSERT INTO orders VALUES (1, 10, 'confirmed', 'paid', 100.00, '2026-08-20 10:00:00')");
$pdo->exec("INSERT INTO orders VALUES (2, 20, 'confirmed', 'pending', 200.00, '2026-08-21 10:00:00')");
$pdo->exec("INSERT INTO payment_transactions VALUES (1, 1, 100.00, 'pix', 'paid', 'mp-1', 'order-1', 'idem-1', 'mercadopago', 'BRL', '2026-08-20 10:00:00', '2026-08-20 10:01:00')");
$pdo->exec("INSERT INTO payment_transactions VALUES (2, 2, 200.00, 'pix', 'pending', 'mp-2', 'order-2', 'idem-2', 'mercadopago', 'BRL', '2026-08-21 10:00:00', '2026-08-21 10:01:00')");

$service = new ReconciliationService(
    $pdo,
    new PaymentTransactionRepository($pdo),
    new OrderRepository($pdo)
);

$filtered = $service->getPage(
    [
        'customer_id' => 10,
        'date_from' => '2026-08-20',
        'date_to' => '2026-08-20',
        'provider' => 'mercadopago',
        'status' => 'paid',
    ],
    1,
    0
);

assert_same(1, count($filtered['items']), 'ReconciliationService filter flow is incorrect.');
assert_same(1, (int)$filtered['items'][0]['id'], 'Filtered transaction id is incorrect.');

$nextPage = $service->getPage([], 1, 1);
assert_same(1, count($nextPage['items']), 'ReconciliationService pagination did not return the requested page.');
assert_same(1, (int)$nextPage['items'][0]['id'], 'ReconciliationService pagination ordering is incorrect.');

$summary = $service->getSummary(['provider' => 'mercadopago']);
assert_same(2, $summary['total'], 'Reconciliation summary count is incorrect.');
assert_same(300.00, $summary['total_amount'], 'Reconciliation summary total is incorrect.');

$forbiddenCsvFields = [
    'payload',
    'raw_payload',
    'access_token',
    'webhook_secret',
    'qr_code_base64',
    'gateway_response',
];
foreach ($forbiddenCsvFields as $field) {
    assert_true(
        !str_contains($controller, "['{$field}']")
        && !str_contains($controller, "['{$field}'"),
        "Sensitive CSV field '{$field}' must not be exported."
    );
}

$csvSafe = static function (mixed $value): string {
    $text = is_scalar($value) || $value === null ? (string)$value : '';
    if ($text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
        $text = "'" . $text;
    }
    return $text;
};

assert_same("'=2+2", $csvSafe('=2+2'), 'CSV formula prefix must be escaped.');
assert_same("'+CMD", $csvSafe('+CMD'), 'CSV plus prefix must be escaped.');
assert_same("'-10", $csvSafe('-10'), 'CSV minus prefix must be escaped.');
assert_same("'@SUM", $csvSafe('@SUM'), 'CSV at prefix must be escaped.');
assert_same('Cliente normal', $csvSafe('Cliente normal'), 'Normal CSV values must remain unchanged.');

$guardPos = strpos($controller, 'require_admin();');
$servicePos = strpos($controller, '$reconciliationService = $container->get(ReconciliationService::class);');
assert_true(
    $guardPos !== false
    && $servicePos !== false
    && $guardPos < $servicePos,
    'Admin guard must run before ReconciliationService access.'
);

echo "PASS: reconciliation_controller_test\n";
