<?php
declare(strict_types=1);

/**
 * Real HTTP integration suite for CM-Comercial/webhooks/webhook_handler.php.
 *
 * The test starts PHP's built-in HTTP server and injects only a test-double
 * Database/Gateway through the server router. The production webhook handler,
 * Container, WebhookValidator, PaymentService and repositories are executed
 * unchanged. No production credentials or external Mercado Pago calls are made.
 */

final class WebhookHttpE2EFailure extends RuntimeException
{
}

function e2eAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new WebhookHttpE2EFailure($message);
    }
}

function e2eSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new WebhookHttpE2EFailure(
            $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
        );
    }
}

function e2ePdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
}

function createFixture(string $path): void
{
    $pdo = e2ePdo($path);
    $pdo->exec(<<<'SQL'
CREATE TABLE orders (
    id INTEGER PRIMARY KEY,
    customer_id INTEGER NULL,
    status TEXT NOT NULL,
    payment_status TEXT NOT NULL,
    total_amount NUMERIC NOT NULL,
    currency TEXT NOT NULL DEFAULT 'BRL',
    shipping_amount NUMERIC NOT NULL DEFAULT 0,
    idempotency_key TEXT NULL,
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
CREATE TABLE products (
    id INTEGER PRIMARY KEY,
    sku TEXT NULL,
    name TEXT NULL,
    price NUMERIC NOT NULL DEFAULT 0,
    stock_quantity INTEGER NOT NULL,
    active INTEGER NOT NULL DEFAULT 1
);
CREATE TABLE order_items (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    order_id INTEGER NOT NULL,
    product_id INTEGER NOT NULL,
    sku TEXT NULL,
    product_name TEXT NULL,
    unit_price NUMERIC NOT NULL DEFAULT 0,
    quantity INTEGER NOT NULL
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
    pix_expires_at TEXT NULL,
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
    created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);
SQL);

    $pdo->exec("INSERT INTO orders (id, customer_id, status, payment_status, total_amount, shipping_amount, idempotency_key, created_at, updated_at) VALUES (10, 1, 'pending', 'pending', 100.00, 0, 'checkout-10', '2026-08-31 10:00:00', '2026-08-31 10:00:00')");
    $pdo->exec("INSERT INTO products (id, sku, name, price, stock_quantity, active) VALUES (50, 'SKU-50', 'Produto E2E', 50.00, 4, 1)");
    $pdo->exec("INSERT INTO order_items (id, order_id, product_id, sku, product_name, unit_price, quantity) VALUES (1, 10, 50, 'SKU-50', 'Produto E2E', 50.00, 2)");
    $pdo->exec("INSERT INTO payment_transactions (id, order_id, provider, provider_payment_id, external_reference, idempotency_key, status, amount, currency, created_at, updated_at) VALUES (100, 10, 'mercadopago', NULL, '10', 'checkout-10', 'pending', 100.00, 'BRL', '2026-08-31 10:01:00', '2026-08-31 10:01:00')");
}

function createRouter(string $path, string $dbPath, string $tracePath, string $repoRoot): void
{
    $interfacePath = addslashes($repoRoot . '/src/Gateways/PaymentGatewayInterface.php');
    $handlerPath = addslashes($repoRoot . '/webhooks/webhook_handler.php');
    $dbLiteral = addslashes($dbPath);
    $traceLiteral = addslashes($tracePath);

    $router = <<<'PHP'
<?php
declare(strict_types=1);

if (!class_exists('App\\Config\\Database', false)) {
    final class_alias_placeholder {}
}
PHP;

    $router = str_replace(
        "if (!class_exists('App\\\\Config\\\\Database', false)) {\n    final class_alias_placeholder {}\n}\n",
        '',
        $router
    );

    $router = <<<PHP
<?php
declare(strict_types=1);

if (!class_exists('App\\Config\\Database', false)) {
    eval(<<<'E2EDB'
namespace App\\Config;
final class Database
{
    private static ?self \\$instance = null;
    private PDO \\$pdo;

    private function __construct()
    {
        \\$path = getenv('CM_E2E_DB') ?: '{$dbLiteral}';
        \\$this->pdo = new PDO('sqlite:' . \\$path);
        \\$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        \\$this->pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    }

    public static function getInstance(): self
    {
        return self::\\$instance ??= new self();
    }

    public function pdo(): PDO
    {
        return \\$this->pdo;
    }

    public function transaction(callable \\$callback): mixed
    {
        \\$this->pdo->beginTransaction();
        try {
            \\$result = \\$callback(\\$this->pdo);
            \\$this->pdo->commit();
            return \\$result;
        } catch (Throwable \\$e) {
            if (\\$this->pdo->inTransaction()) {
                \\$this->pdo->rollBack();
            }
            throw \\$e;
        }
    }
}
E2EDB
    );
}

if (!class_exists('App\\Gateways\\MercadoPagoGateway', false)) {
    require_once '{$interfacePath}';
    eval(<<<'E2EGW'
namespace App\\Gateways;
final class MercadoPagoGateway implements PaymentGatewayInterface
{
    public function createPixCharge(int \\$orderId, float \\$amount, string \\$payerEmail, string \\$payerName, string \\$idempotencyKey): array
    {
        throw new \\LogicException('HTTP E2E gateway must not create charges.');
    }

    public function getPayment(string \\$paymentId): array
    {
        \\$countFile = getenv('CM_E2E_GATEWAY_CALLS');
        if (is_string(\\$countFile) && \\$countFile !== '') {
            \\$count = is_file(\\$countFile) ? (int)file_get_contents(\\$countFile) : 0;
            file_put_contents(\\$countFile, (string)(\\$count + 1), LOCK_EX);
        }

        if ((string)(getenv('CM_E2E_GATEWAY_MODE') ?: 'paid') === 'fail') {
            throw new \\RuntimeException('forced gateway failure');
        }

        return [
            'provider_payment_id' => \\$paymentId,
            'status' => 'paid',
            'transaction_id' => \\$paymentId,
            'pix_qr_code' => 'RAW-PIX-QR-MUST-NOT-BE-PERSISTED',
            'pix_qr_code_base64' => 'UkFXLVBJWC1RUi1NVVNUQkUtU0FOSVRJWkVE',
            'pix_expires_at' => '2026-09-01T04:00:00+00:00',
            'raw' => [
                'id' => (int)\\$paymentId,
                'status' => 'approved',
                'external_reference' => '10',
                'transaction_amount' => 100.00,
                'access_token' => 'E2E-ACCESS-TOKEN-MUST-NOT-BE-PERSISTED',
                'webhook_secret' => 'E2E-WEBHOOK-SECRET-MUST-NOT-BE-PERSISTED',
                'authorization' => 'Bearer E2E-AUTH-MUST-NOT-BE-PERSISTED',
                'payload' => '{"raw":"body-must-not-be-persisted"}',
                'point_of_interaction' => [
                    'transaction_data' => [
                        'qr_code' => 'RAW-PIX-QR-MUST-NOT-BE-PERSISTED',
                        'qr_code_base64' => 'UkFXLVBJWC1RUi1NVVNUQkUtU0FOSVRJWkVE',
                    ],
                ],
            ],
        ];
    }
}
E2EGW
    );
}

file_put_contents('{$traceLiteral}', json_encode([
    'method' => \\SERVER['REQUEST_METHOD'] ?? '',
    'request_id' => \\SERVER['HTTP_X_REQUEST_ID'] ?? '',
    'signature_present' => isset(\$_SERVER['HTTP_X_SIGNATURE']),
], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);

return false;
PHP;

    file_put_contents($path, $router);
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) {
        throw new RuntimeException('Unable to allocate a local test port.');
    }
    $name = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr((string)$name, strrpos((string)$name, ':') + 1);
    if ($port < 1) {
        throw new RuntimeException('Unable to resolve local test port.');
    }
    return $port;
}

function startServer(string $repoRoot, string $router, int $port, string $dbPath, string $callsPath): array
{
    $logPath = sys_get_temp_dir() . '/cm-comercial-webhook-http-' . getmypid() . '.log';
    $command = [
        PHP_BINARY,
        '-S',
        '127.0.0.1:' . $port,
        '-t',
        $repoRoot,
        $router,
    ];

    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['file', $logPath, 'ab'],
        2 => ['file', $logPath, 'ab'],
    ];
    $env = $_ENV;
    $env['CM_E2E_DB'] = $dbPath;
    $env['CM_E2E_GATEWAY_CALLS'] = $callsPath;
    $env['CM_E2E_GATEWAY_MODE'] = 'paid';
    $env['MP_ACCESS_TOKEN'] = 'e2e-non-production-placeholder';
    $env['MP_WEBHOOK_SECRET'] = 'e2e-webhook-secret';
    $env['MP_WEBHOOK_MAX_SKEW'] = '300';

    $process = proc_open($escaped, $descriptor, $pipes, $repoRoot, $env);
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP built-in HTTP server.');
    }
    fclose($pipes[0]);

    $ready = false;
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            $ready = true;
            break;
        }
        usleep(100000);
    }

    if (!$ready) {
        proc_terminate($process);
        $log = is_file($logPath) ? (string)file_get_contents($logPath) : '';
        throw new RuntimeException('PHP HTTP server did not become ready. ' . $log);
    }

    return [$process, $logPath];
}

function stopServer($process): void
{
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}

function httpPost(string $url, string $body, array $headers): array
{
    $handle = curl_init($url);
    if ($handle === false) {
        throw new RuntimeException('Unable to initialize cURL.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => array_map(static fn(string $key, string $value): string => $key . ': ' . $value, array_keys($headers), array_values($headers)),
        CURLOPT_HEADER => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $responseBody = curl_exec($handle);
    $curlError = curl_error($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);

    if ($responseBody === false || $curlError !== '') {
        throw new RuntimeException('HTTP request failed: ' . $curlError);
    }

    return [$status, (string)$responseBody];
}

function signedHeaders(array $payload, string $secret, int $timestamp, string $requestId): array
{
    $notificationId = (string)$payload['id'];
    $manifest = 'id:' . $notificationId . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
    return [
        'Content-Type' => 'application/json',
        'x-signature' => 'ts=' . $timestamp . ',v1=' . hash_hmac('sha256', $manifest, $secret),
        'x-request-id' => $requestId,
    ];
}

function decodeResponse(string $body): array
{
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

$extensions = get_loaded_extensions();
e2eAssert(in_array('curl', $extensions, true), 'curl extension is required for HTTP E2E');
e2eAssert(in_array('pdo_sqlite', $extensions, true), 'pdo_sqlite extension is required for HTTP E2E');

e2ePdoPath:
$root = dirname(__DIR__);
$secret = 'e2e-webhook-secret';
$tempDir = sys_get_temp_dir() . '/cm-comercial-webhook-e2e-' . getmypid() . '-' . bin2hex(random_bytes(4));
e2eAssert(mkdir($tempDir, 0700, true), 'unable to create temporary E2E directory');
$dbPath = $tempDir . '/fixture.sqlite';
$routerPath = $tempDir . '/router.php';
$tracePath = $tempDir . '/requests.log';
$callsPath = $tempDir . '/gateway.calls';

$server = null;
$logPath = null;
try {
    createFixture($dbPath);
    createRouter($routerPath, $dbPath, $tracePath, $root);
    [$server, $logPath] = startServer($root, $routerPath, freePort(), $dbPath, $callsPath);

    // The port is reconstructed from the server log to keep the server lifecycle
    // helper simple and avoid exposing a global mutable port variable.
    $port = null;
    for ($i = 0; $i < 30; $i++) {
        $log = is_file($logPath) ? (string)file_get_contents($logPath) : '';
        if (preg_match('/127\.0\.0\.1:(\d+)/', $log, $match)) {
            $port = (int)$match[1];
            break;
        }
        usleep(100000);
    }
    e2eAssert(is_int($port) && $port > 0, 'unable to discover HTTP test server port');

    $url = 'http://127.0.0.1:' . $port . '/webhooks/webhook_handler.php';
    $payload = [
        'id' => 900001,
        'type' => 'payment',
        'action' => 'payment.updated',
        'live_mode' => false,
        'data' => ['id' => '777001'],
    ];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    [$status, $response] = httpPost($url, $body, signedHeaders($payload, $secret, time(), 'e2e-request-1'));
    e2eSame(200, $status, 'valid webhook must return HTTP 200');
    e2eSame(true, decodeResponse($response)['received'] ?? false, 'success response must confirm receipt');

    $pdo = e2ePdo($dbPath);
    e2eSame('paid', (string)$pdo->query('SELECT status FROM payment_transactions WHERE id = 100')->fetchColumn(), 'payment status must transition');
    e2eSame('paid', (string)$pdo->query('SELECT payment_status FROM orders WHERE id = 10')->fetchColumn(), 'order payment status must transition');
    $stockAfterSuccess = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 50')->fetchColumn();
    e2eAssert($stockAfterSuccess !== 4, 'successful payment must apply stock state transition');
    e2eSame(1, (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(), 'success must create one audit record');

    $trace = is_file($tracePath) ? (string)file_get_contents($tracePath) : '';
    e2eAssert(str_contains($trace, 'e2e-request-1'), 'x-request-id must reach the HTTP entry point');

    // Replay: same notification id, same request-id. Database state and audit
    // count must remain unchanged even though the HTTP endpoint is hit again.
    [$status, $response] = httpPost($url, $body, signedHeaders($payload, $secret, time(), 'e2e-request-1'));
    e2eSame(200, $status, 'replayed webhook must return HTTP 200');
    e2eSame(true, decodeResponse($response)['idempotent'] ?? false, 'replayed webhook must be marked idempotent');
    e2eSame(1, (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(), 'replay must not duplicate audit');
    e2eSame($stockAfterSuccess, (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 50')->fetchColumn(), 'replay must not change stock');

    // Invalid HMAC and missing signature must be rejected before business logic.
    $before = [
        'payment' => (string)$pdo->query('SELECT status FROM payment_transactions WHERE id = 100')->fetchColumn(),
        'audit' => (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(),
    ];
    $invalid = signedHeaders($payload, $secret, time(), 'e2e-invalid');
    $invalid['x-signature'] = 'ts=' . time() . ',v1=' . str_repeat('0', 64);
    [$status] = httpPost($url, $body, $invalid);
    e2eSame(401, $status, 'invalid HMAC must return HTTP 401');
    [$status] = httpPost($url, $body, ['Content-Type' => 'application/json', 'x-request-id' => 'e2e-missing-signature']);
    e2eSame(401, $status, 'missing HMAC must return HTTP 401');
    e2eSame($before['payment'], (string)$pdo->query('SELECT status FROM payment_transactions WHERE id = 100')->fetchColumn(), 'invalid signature must not alter payment');
    e2eSame($before['audit'], (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(), 'invalid signature must not create audit');

    // Expired timestamp.
    $expired = signedHeaders($payload, $secret, time() - 301, 'e2e-expired');
    [$status] = httpPost($url, $body, $expired);
    e2eAssert(in_array($status, [401, 403], true), 'expired signature must return HTTP 401 or 403');

    // Validly signed but semantically invalid payload reaches the defensive 422 path.
    $invalidPayload = $payload;
    $invalidPayload['id'] = 900002;
    $invalidPayload['type'] = '';
    $invalidBody = json_encode($invalidPayload, JSON_THROW_ON_ERROR);
    [$status] = httpPost($url, $invalidBody, signedHeaders($invalidPayload, $secret, time(), 'e2e-invalid-payload'));
    e2eSame(422, $status, 'semantically invalid signed payload must return HTTP 422');

    // Malformed JSON cannot produce a valid manifest id and therefore must be
    // rejected defensively by the signature validator without an uncaught error.
    $malformed = '{"id":900003,"type":"payment","data":';
    $malformedHeaders = [
        'Content-Type' => 'application/json',
        'x-request-id' => 'e2e-malformed',
        'x-signature' => 'ts=' . time() . ',v1=' . str_repeat('0', 64),
    ];
    [$status] = httpPost($url, $malformed, $malformedHeaders);
    e2eAssert(in_array($status, [400, 401, 422], true), 'malformed JSON must return a defensive 4xx response');

    // Rollback: reset fixture state, then force the payment UPDATE to fail inside
    // the repository transaction after the audit INSERT. The audit and state must
    // both roll back.
    $pdo->exec("UPDATE payment_transactions SET status='pending', provider_payment_id=NULL WHERE id=100");
    $pdo->exec("UPDATE orders SET status='pending', payment_status='pending' WHERE id=10");
    $pdo->exec("UPDATE products SET stock_quantity=4 WHERE id=50");
    $pdo->exec('DELETE FROM payment_audit_log');
    $pdo->exec("CREATE TRIGGER fail_webhook_transition BEFORE UPDATE OF status ON payment_transactions WHEN NEW.status='paid' BEGIN SELECT RAISE(ABORT, 'forced E2E transition failure'); END");

    $rollbackPayload = $payload;
    $rollbackPayload['id'] = 900004;
    $rollbackBody = json_encode($rollbackPayload, JSON_THROW_ON_ERROR);
    [$status] = httpPost($url, $rollbackBody, signedHeaders($rollbackPayload, $secret, time(), 'e2e-rollback'));
    e2eSame(500, $status, 'forced intermediate failure must return HTTP 500');
    e2eSame('pending', (string)$pdo->query('SELECT status FROM payment_transactions WHERE id = 100')->fetchColumn(), 'payment transition must rollback');
    e2eSame('pending', (string)$pdo->query('SELECT payment_status FROM orders WHERE id = 10')->fetchColumn(), 'order state must rollback');
    e2eSame(4, (int)$pdo->query('SELECT stock_quantity FROM products WHERE id = 50')->fetchColumn(), 'stock must rollback');
    e2eSame(0, (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(), 'audit insert must rollback');

    // Sanitization: the successful audit path was executed before rollback. Run a
    // fresh successful event and inspect every audit payload for forbidden values.
    $pdo->exec('DROP TRIGGER fail_webhook_transition');
    $sanitizedPayload = $payload;
    $sanitizedPayload['id'] = 900005;
    $sanitizedBody = json_encode($sanitizedPayload, JSON_THROW_ON_ERROR);
    [$status] = httpPost($url, $sanitizedBody, signedHeaders($sanitizedPayload, $secret, time(), 'e2e-sanitization'));
    e2eSame(200, $status, 'sanitization fixture must process successfully');
    $auditJson = json_encode($pdo->query('SELECT * FROM payment_audit_log')->fetchAll(), JSON_THROW_ON_ERROR);
    foreach ([
        'E2E-ACCESS-TOKEN-MUST-NOT-BE-PERSISTED',
        'E2E-WEBHOOK-SECRET-MUST-NOT-BE-PERSISTED',
        'Bearer E2E-AUTH-MUST-NOT-BE-PERSISTED',
        'RAW-PIX-QR-MUST-NOT-BE-PERSISTED',
        'UkFXLVBJWC1RUi1NVVNUQkUtU0FOSVRJWkVE',
        'body-must-not-be-persisted',
    ] as $forbidden) {
        e2eAssert(!str_contains($auditJson, $forbidden), 'sensitive value leaked into audit: ' . $forbidden);
    }

    echo "PASS: webhook_http_integration_test\n";
} finally {
    stopServer($server);
    foreach ([$routerPath, $dbPath, $tracePath, $callsPath, $logPath] as $file) {
        if (is_string($file) && is_file($file)) {
            @unlink($file);
        }
    }
    $dir = dirname($dbPath);
    if (is_dir($dir)) {
        @rmdir($dir);
    }
}
