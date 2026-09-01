<?php
declare(strict_types=1);

/**
 * Real HTTP integration suite for CM-Comercial/webhooks/webhook_handler.php.
 *
 * The production entry point, Container, WebhookValidator, PaymentService and
 * repositories are executed through PHP's built-in HTTP server. Only the
 * database and Mercado Pago gateway are test doubles injected by the router;
 * no production credentials or external gateway calls are used.
 */
final class WebhookHttpE2EFailure extends RuntimeException {}

function e2eAssert(bool $condition, string $message): void
{
    if (!$condition) throw new WebhookHttpE2EFailure($message);
}

function e2eSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new WebhookHttpE2EFailure($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
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
    $pdo->exec("INSERT INTO orders (id,customer_id,status,payment_status,total_amount,shipping_amount,idempotency_key,created_at,updated_at) VALUES (10,1,'pending','pending',100.00,0,'checkout-10','2026-08-31 10:00:00','2026-08-31 10:00:00')");
    $pdo->exec("INSERT INTO products (id,sku,name,price,stock_quantity,active) VALUES (50,'SKU-50','Produto E2E',50.00,4,1)");
    $pdo->exec("INSERT INTO order_items (id,order_id,product_id,sku,product_name,unit_price,quantity) VALUES (1,10,50,'SKU-50','Produto E2E',50.00,2)");
    $pdo->exec("INSERT INTO payment_transactions (id,order_id,provider,provider_payment_id,external_reference,idempotency_key,status,amount,currency,created_at,updated_at) VALUES (100,10,'mercadopago',NULL,'10','checkout-10','pending',100.00,'BRL','2026-08-31 10:01:00','2026-08-31 10:01:00')");
}

function createRouter(string $path, string $dbPath, string $tracePath, string $interfacePath): void
{
    $template = <<<'ROUTER'
<?php
declare(strict_types=1);

namespace App\Config;
if (!class_exists(Database::class, false)) {
    final class Database
    {
        private static ?self $instance = null;
        private \PDO $pdo;
        private function __construct()
        {
            $this->pdo = new \PDO('sqlite:' . (getenv('CM_E2E_DB') ?: '__DB__'));
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
        }
        public static function getInstance(): self { return self::$instance ??= new self(); }
        public function pdo(): \PDO { return $this->pdo; }
        public function transaction(callable $callback): mixed
        {
            $this->pdo->beginTransaction();
            try {
                $result = $callback($this->pdo);
                $this->pdo->commit();
                return $result;
            } catch (\Throwable $e) {
                if ($this->pdo->inTransaction()) $this->pdo->rollBack();
                throw $e;
            }
        }
    }
}

namespace App\Gateways;
if (!class_exists(MercadoPagoGateway::class, false)) {
    require_once '__INTERFACE__';
    final class MercadoPagoGateway implements PaymentGatewayInterface
    {
        public function createPixCharge(int $orderId, float $amount, string $payerEmail, string $payerName, string $idempotencyKey): array
        {
            throw new \LogicException('HTTP E2E gateway must not create charges.');
        }
        public function getPayment(string $paymentId): array
        {
            $calls = getenv('CM_E2E_GATEWAY_CALLS');
            if (is_string($calls) && $calls !== '') {
                $count = is_file($calls) ? (int)file_get_contents($calls) : 0;
                file_put_contents($calls, (string)($count + 1), LOCK_EX);
            }
            return [
                'provider_payment_id' => $paymentId,
                'status' => 'paid',
                'transaction_id' => $paymentId,
                'pix_qr_code' => 'RAW-PIX-QR-MUST-NOT-BE-PERSISTED',
                'pix_qr_code_base64' => 'UkFXLVBJWC1RUi1NVVNUQkUtU0FOSVRJWkVE',
                'pix_expires_at' => '2026-09-01T04:00:00+00:00',
                'raw' => [
                    'id' => (int)$paymentId,
                    'status' => 'approved',
                    'external_reference' => '10',
                    'transaction_amount' => 100.00,
                    'access_token' => 'E2E-ACCESS-TOKEN-MUST-NOT-BE-PERSISTED',
                    'webhook_secret' => 'E2E-WEBHOOK-SECRET-MUST-NOT-BE-PERSISTED',
                    'authorization' => 'Bearer E2E-AUTH-MUST-NOT-BE-PERSISTED',
                    'payload' => '{"raw":"body-must-not-be-persisted"}',
                    'point_of_interaction' => ['transaction_data' => [
                        'qr_code' => 'RAW-PIX-QR-MUST-NOT-BE-PERSISTED',
                        'qr_code_base64' => 'UkFXLVBJWC1RUi1NVVNUQkUtU0FOSVRJWkVE',
                    ]],
                ],
            ];
        }
    }
}

namespace {
file_put_contents('__TRACE__', json_encode([
    'method' => $_SERVER['REQUEST_METHOD'] ?? '',
    'request_id' => $_SERVER['HTTP_X_REQUEST_ID'] ?? '',
    'signature_present' => isset($_SERVER['HTTP_X_SIGNATURE']),
], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
return false;
}
ROUTER;
    $router = str_replace(
        ['__DB__', '__INTERFACE__', '__TRACE__'],
        [addslashes($dbPath), addslashes($interfacePath), addslashes($tracePath)],
        $template
    );
    file_put_contents($path, $router);
}

function freePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException('Unable to allocate local test port.');
    $name = (string)stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr($name, strrpos($name, ':') + 1);
}

function startServer(string $repoRoot, string $router, int $port, string $dbPath, string $callsPath): array
{
    $logPath = sys_get_temp_dir() . '/cm-comercial-webhook-http-' . getmypid() . '.log';
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $repoRoot, $router];
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $descriptor = [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'ab'], 2 => ['file', $logPath, 'ab']];
    $baseEnv = getenv();
    $env = is_array($baseEnv) ? $baseEnv : [];
    $env['CM_E2E_DB'] = $dbPath;
    $env['CM_E2E_GATEWAY_CALLS'] = $callsPath;
    $env['MP_ACCESS_TOKEN'] = 'e2e-non-production-placeholder';
    $env['MP_WEBHOOK_SECRET'] = 'e2e-webhook-secret';
    $env['MP_WEBHOOK_MAX_SKEW'] = '300';
    $process = proc_open($escaped, $descriptor, $pipes, $repoRoot, $env);
    if (!is_resource($process)) throw new RuntimeException('Unable to start PHP HTTP server.');
    fclose($pipes[0]);
    for ($i = 0; $i < 50; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if (is_resource($socket)) { fclose($socket); return [$process, $logPath, $port]; }
        usleep(100000);
    }
    proc_terminate($process);
    throw new RuntimeException('PHP HTTP server did not become ready: ' . (is_file($logPath) ? file_get_contents($logPath) : ''));
}

function stopServer(mixed $process): void
{
    if (is_resource($process)) { proc_terminate($process); proc_close($process); }
}

function httpPost(string $url, string $body, array $headers): array
{
    $handle = curl_init($url);
    if ($handle === false) throw new RuntimeException('Unable to initialize cURL.');
    $headerLines = [];
    foreach ($headers as $key => $value) $headerLines[] = $key . ': ' . $value;
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => $headerLines,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    $response = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
    curl_close($handle);
    if ($response === false || $error !== '') throw new RuntimeException('HTTP request failed: ' . $error);
    return [$status, (string)$response];
}

function signPayload(array $payload, string $secret, int $timestamp, string $requestId): array
{
    $manifest = 'id:' . (string)$payload['id'] . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
    return [
        'Content-Type' => 'application/json',
        'x-signature' => 'ts=' . $timestamp . ',v1=' . hash_hmac('sha256', $manifest, $secret),
        'x-request-id' => $requestId,
    ];
}

function responseJson(string $body): array
{
    $decoded = json_decode($body, true);
    return is_array($decoded) ? $decoded : [];
}

$extensions = get_loaded_extensions();
e2eAssert(in_array('curl', $extensions, true), 'curl extension is required for HTTP E2E');
e2eAssert(in_array('pdo_sqlite', $extensions, true), 'pdo_sqlite extension is required for HTTP E2E');

$repoRoot = dirname(__DIR__);
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
    createRouter($routerPath, $dbPath, $tracePath, $repoRoot . '/src/Gateways/PaymentGatewayInterface.php');
    [$server, $logPath, $port] = startServer($repoRoot, $routerPath, freePort(), $dbPath, $callsPath);
    $url = 'http://127.0.0.1:' . $port . '/webhooks/webhook_handler.php';

    $payload = ['id' => 900001, 'type' => 'payment', 'action' => 'payment.updated', 'live_mode' => false, 'data' => ['id' => '777001']];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);

    [$status, $response] = httpPost($url, $body, signPayload($payload, $secret, time(), 'e2e-request-1'));
    e2eSame(200, $status, 'valid webhook must return HTTP 200');
    e2eSame(true, responseJson($response)['received'] ?? false, 'success response must confirm receipt');

    $pdo = e2ePdo($dbPath);
    e2eSame('paid', (string)$pdo->query('SELECT status FROM payment_transactions WHERE id=100')->fetchColumn(), 'payment status must transition');
    e2eSame('paid', (string)$pdo->query('SELECT payment_status FROM orders WHERE id=10')->fetchColumn(), 'order payment status must transition');
    $stockAfterSuccess = (int)$pdo->query('SELECT stock_quantity FROM products WHERE id=50')->fetchColumn();
    e2eAssert($stockAfterSuccess !== 4, 'successful payment must apply stock transition');
    e2eSame(1, (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(), 'success must create one audit record');
    e2eAssert(str_contains((string)file_get_contents($tracePath), 'e2e-request-1'), 'x-request-id must reach the HTTP entry point');

    [$status, $response] = httpPost($url, $body, signPayload($payload, $secret, time(), 'e2e-request-1'));
    e2eSame(200, $status, 'replayed webhook must return HTTP 200');
    e2eSame(true, responseJson($response)['idempotent'] ?? false, 'replay must be idempotent');
    e2eSame(1, (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(), 'replay must not duplicate audit');
    e2eSame($stockAfterSuccess, (int)$pdo->query('SELECT stock_quantity FROM products WHERE id=50')->fetchColumn(), 'replay must not change stock');

    $beforeStatus = (string)$pdo->query('SELECT status FROM payment_transactions WHERE id=100')->fetchColumn();
    $beforeAudit = (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn();
    $invalidHeaders = signPayload($payload, $secret, time(), 'e2e-invalid');
    $invalidHeaders['x-signature'] = 'ts=' . time() . ',v1=' . str_repeat('0', 64);
    [$status] = httpPost($url, $body, $invalidHeaders);
    e2eSame(401, $status, 'invalid HMAC must return HTTP 401');
    [$status] = httpPost($url, $body, ['Content-Type' => 'application/json', 'x-request-id' => 'e2e-missing-signature']);
    e2eSame(401, $status, 'missing HMAC must return HTTP 401');
    e2eSame($beforeStatus, (string)$pdo->query('SELECT status FROM payment_transactions WHERE id=100')->fetchColumn(), 'bad signature must not alter payment');
    e2eSame($beforeAudit, (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(), 'bad signature must not create audit');

    [$status] = httpPost($url, $body, signPayload($payload, $secret, time() - 301, 'e2e-expired'));
    e2eAssert(in_array($status, [401, 403], true), 'expired timestamp must return HTTP 401 or 403');

    $invalidPayload = $payload;
    $invalidPayload['id'] = 900002;
    $invalidPayload['type'] = '';
    [$status] = httpPost($url, json_encode($invalidPayload, JSON_THROW_ON_ERROR), signPayload($invalidPayload, $secret, time(), 'e2e-invalid-payload'));
    e2eSame(422, $status, 'signed semantic payload error must return HTTP 422');

    $malformed = '{"id":900003,"type":"payment","data":';
    [$status] = httpPost($url, $malformed, ['Content-Type' => 'application/json', 'x-request-id' => 'e2e-malformed', 'x-signature' => 'ts=' . time() . ',v1=' . str_repeat('0', 64)]);
    e2eAssert(in_array($status, [400, 401, 422], true), 'malformed JSON must return defensive 4xx');

    $pdo->exec("UPDATE payment_transactions SET status='pending', provider_payment_id=NULL WHERE id=100");
    $pdo->exec("UPDATE orders SET status='pending', payment_status='pending' WHERE id=10");
    $pdo->exec("UPDATE products SET stock_quantity=4 WHERE id=50");
    $pdo->exec('DELETE FROM payment_audit_log');
    $pdo->exec("CREATE TRIGGER fail_webhook_transition BEFORE UPDATE OF status ON payment_transactions WHEN NEW.status='paid' BEGIN SELECT RAISE(ABORT, 'forced E2E transition failure'); END");
    $rollbackPayload = $payload;
    $rollbackPayload['id'] = 900004;
    [$status] = httpPost($url, json_encode($rollbackPayload, JSON_THROW_ON_ERROR), signPayload($rollbackPayload, $secret, time(), 'e2e-rollback'));
    e2eSame(500, $status, 'forced intermediate failure must return HTTP 500');
    e2eSame('pending', (string)$pdo->query('SELECT status FROM payment_transactions WHERE id=100')->fetchColumn(), 'payment transition must rollback');
    e2eSame('pending', (string)$pdo->query('SELECT payment_status FROM orders WHERE id=10')->fetchColumn(), 'order state must rollback');
    e2eSame(4, (int)$pdo->query('SELECT stock_quantity FROM products WHERE id=50')->fetchColumn(), 'stock must rollback');
    e2eSame(0, (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(), 'audit insert must rollback');

    $pdo->exec('DROP TRIGGER fail_webhook_transition');
    $sanitizedPayload = $payload;
    $sanitizedPayload['id'] = 900005;
    [$status] = httpPost($url, json_encode($sanitizedPayload, JSON_THROW_ON_ERROR), signPayload($sanitizedPayload, $secret, time(), 'e2e-sanitization'));
    e2eSame(200, $status, 'sanitization fixture must process successfully');
    $auditJson = json_encode($pdo->query('SELECT * FROM payment_audit_log')->fetchAll(), JSON_THROW_ON_ERROR);
    foreach (['E2E-ACCESS-TOKEN-MUST-NOT-BE-PERSISTED','E2E-WEBHOOK-SECRET-MUST-NOT-BE-PERSISTED','Bearer E2E-AUTH-MUST-NOT-BE-PERSISTED','RAW-PIX-QR-MUST-NOT-BE-PERSISTED','UkFXLVBJWC1RUi1NVVNUQkUtU0FOSVRJWkVE','body-must-not-be-persisted'] as $forbidden) {
        e2eAssert(!str_contains($auditJson, $forbidden), 'sensitive value leaked into audit: ' . $forbidden);
    }

    echo "PASS: webhook_http_integration_test\n";
} finally {
    stopServer($server);
    foreach ([$routerPath, $dbPath, $tracePath, $callsPath, $logPath] as $file) {
        if (is_string($file) && is_file($file)) @unlink($file);
    }
    if (is_dir($tempDir)) @rmdir($tempDir);
}
