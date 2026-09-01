<?php
declare(strict_types=1);

/**
 * Concurrent HTTP/race-condition suite for CM-Comercial webhooks.
 *
 * The production webhook entry point, Container, services and repositories are
 * executed over HTTP. A local SQLite fixture and Mercado Pago gateway double
 * are used only to make the suite deterministic and offline.
 *
 * Linux/macOS: PHP_CLI_SERVER_WORKERS provides multiple HTTP workers.
 * Windows: PHP's built-in multi-worker mode is unavailable, so the suite starts
 * multiple independent local PHP servers against the same SQLite database.
 */
final class WebhookConcurrencyFailure extends RuntimeException {}

function raceAssert(bool $condition, string $message): void
{
    if (!$condition) throw new WebhookConcurrencyFailure($message);
}

function raceSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new WebhookConcurrencyFailure($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function racePdo(string $path): PDO
{
    $pdo = new PDO('sqlite:' . $path, null, null, [PDO::ATTR_TIMEOUT => 10]);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA busy_timeout = 10000');
    $pdo->exec('PRAGMA journal_mode = WAL');
    return $pdo;
}

function createRaceFixture(string $path): void
{
    $pdo = racePdo($path);
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

    $pdo->exec("INSERT INTO orders (id,customer_id,status,payment_status,total_amount,shipping_amount,idempotency_key,created_at,updated_at) VALUES (10,1,'pending','pending',100.00,0,'checkout-10','2026-09-01 10:00:00','2026-09-01 10:00:00')");
    $pdo->exec("INSERT INTO products (id,sku,name,price,stock_quantity,active) VALUES (50,'SKU-50','Produto Concorrente',50.00,4,1)");
    $pdo->exec("INSERT INTO order_items (id,order_id,product_id,sku,product_name,unit_price,quantity) VALUES (1,10,50,'SKU-50','Produto Concorrente',50.00,2)");
    $pdo->exec("INSERT INTO payment_transactions (id,order_id,provider,provider_payment_id,external_reference,idempotency_key,status,amount,currency,created_at,updated_at) VALUES (100,10,'mercadopago',NULL,'10','checkout-10','pending',100.00,'BRL','2026-09-01 10:01:00','2026-09-01 10:01:00')");
}

function writeRouter(string $path, string $dbPath, string $repoRoot): void
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
            $this->pdo = new \PDO('sqlite:' . getenv('CM_RACE_DB'), null, null, [\PDO::ATTR_TIMEOUT => 10]);
            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
            $this->pdo->exec('PRAGMA busy_timeout = 10000');
            $this->pdo->exec('PRAGMA journal_mode = WAL');
        }
        public static function getInstance(): self { return self::$instance ??= new self(); }
        public function pdo(): \PDO { return $this->pdo; }
    }
}

namespace App\Gateways;
if (!class_exists(MercadoPagoGateway::class, false)) {
    require_once '__INTERFACE__';
    final class MercadoPagoGateway implements PaymentGatewayInterface
    {
        public function createPixCharge(int $orderId, float $amount, string $payerEmail, string $payerName, string $idempotencyKey): array
        {
            throw new \LogicException('Concurrency test must not create gateway charges.');
        }
        public function getPayment(string $paymentId): array
        {
            $status = match ($paymentId) {
                '777002' => 'authorized',
                '777003' => 'failed',
                default => 'paid',
            };
            return [
                'provider_payment_id' => $paymentId,
                'status' => $status,
                'transaction_id' => $paymentId,
                'raw' => [
                    'external_reference' => '10',
                    'transaction_amount' => 100.00,
                    'access_token' => 'RACE-TOKEN-MUST-NOT-PERSIST',
                    'webhook_secret' => 'RACE-SECRET-MUST-NOT-PERSIST',
                    'authorization' => 'Bearer RACE-AUTH-MUST-NOT-PERSIST',
                    'point_of_interaction' => ['transaction_data' => ['qr_code_base64' => 'RACE-PIX-MUST-NOT-PERSIST']],
                ],
            ];
        }
    }
}

namespace {
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    $marker = getenv('CM_RACE_TRACE');
    if (is_string($marker) && $marker !== '') {
        file_put_contents($marker, json_encode([
            'pid' => getmypid(),
            'request_id' => $requestId,
            'time' => microtime(true),
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    if (str_starts_with($_SERVER['REQUEST_URI'] ?? '', '/webhooks/webhook_handler.php')) {
        require '__HANDLER__';
        return;
    }
    return false;
}
ROUTER;

    $content = str_replace(
        ['__INTERFACE__', '__HANDLER__'],
        [addslashes($repoRoot . '/src/Gateways/PaymentGatewayInterface.php'), addslashes($repoRoot . '/webhooks/webhook_handler.php')],
        $template
    );
    file_put_contents($path, $content);
}

function freeRacePort(): int
{
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if ($socket === false) throw new RuntimeException('Unable to allocate a local test port.');
    $name = (string)stream_socket_get_name($socket, false);
    fclose($socket);
    return (int)substr($name, strrpos($name, ':') + 1);
}

function startRaceServer(string $repoRoot, string $router, string $dbPath, string $tracePath, int $workers = 1): array
{
    $port = freeRacePort();
    $logPath = dirname($tracePath) . '/server-' . $port . '.log';
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $repoRoot, $router];
    $escaped = implode(' ', array_map('escapeshellarg', $command));
    $env = getenv();
    $env = is_array($env) ? $env : [];
    $env['CM_RACE_DB'] = $dbPath;
    $env['CM_RACE_TRACE'] = $tracePath;
    $env['MP_WEBHOOK_SECRET'] = 'race-webhook-secret';
    $env['MP_WEBHOOK_MAX_SKEW'] = '300';
    if (PHP_OS_FAMILY !== 'Windows' && $workers > 1) $env['PHP_CLI_SERVER_WORKERS'] = (string)$workers;

    $descriptors = [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'ab'], 2 => ['file', $logPath, 'ab']];
    $process = proc_open($escaped, $descriptors, $pipes, $repoRoot, $env);
    if (!is_resource($process)) throw new RuntimeException('Unable to start concurrency HTTP server.');
    fclose($pipes[0]);

    for ($i = 0; $i < 80; $i++) {
        $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);
        if (is_resource($socket)) {
            fclose($socket);
            return [$process, 'http://127.0.0.1:' . $port . '/webhooks/webhook_handler.php', $logPath];
        }
        usleep(100000);
    }
    proc_terminate($process);
    throw new RuntimeException('Concurrency HTTP server did not become ready: ' . $logPath);
}

function stopRaceServer(mixed $process): void
{
    if (is_resource($process)) {
        proc_terminate($process);
        proc_close($process);
    }
}

function signRace(array $payload, string $requestId, int $timestamp): array
{
    $manifest = 'id:' . (string)$payload['id'] . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
    return [
        'Content-Type: application/json',
        'x-request-id: ' . $requestId,
        'x-signature: ts=' . $timestamp . ',v1=' . hash_hmac('sha256', $manifest, 'race-webhook-secret'),
    ];
}

function concurrentPosts(array $urls, array $bodies, array $headers): array
{
    $multi = curl_multi_init();
    $handles = [];
    foreach ($urls as $index => $url) {
        $handle = curl_init($url);
        if ($handle === false) throw new RuntimeException('Unable to initialize concurrent cURL handle.');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bodies[$index],
            CURLOPT_HTTPHEADER => $headers[$index],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_multi_add_handle($multi, $handle);
        $handles[$index] = $handle;
    }

    $running = null;
    do {
        $status = curl_multi_exec($multi, $running);
        if ($running > 0) curl_multi_select($multi, 1.0);
    } while ($running > 0 && $status === CURLM_OK);

    $results = [];
    foreach ($handles as $index => $handle) {
        $results[$index] = [
            'status' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE),
            'body' => (string)curl_multi_getcontent($handle),
            'error' => curl_error($handle),
        ];
        curl_multi_remove_handle($multi, $handle);
        curl_close($handle);
    }
    curl_multi_close($multi);
    return $results;
}

function resetRaceState(PDO $pdo): void
{
    $pdo->exec('DELETE FROM payment_audit_log');
    $pdo->exec("UPDATE payment_transactions SET status='pending', provider_payment_id=NULL, updated_at='2026-09-01 10:01:00' WHERE id=100");
    $pdo->exec("UPDATE orders SET status='pending', payment_status='pending', updated_at='2026-09-01 10:00:00' WHERE id=10");
    $pdo->exec('UPDATE products SET stock_quantity=4 WHERE id=50');
}

$extensions = get_loaded_extensions();
raceAssert(in_array('curl', $extensions, true), 'curl extension is required');
raceAssert(in_array('pdo_sqlite', $extensions, true), 'pdo_sqlite extension is required');
raceAssert(function_exists('curl_multi_init'), 'curl_multi_* support is required');

$repoRoot = dirname(__DIR__);
$tempDir = sys_get_temp_dir() . '/cm-comercial-webhook-race-' . getmypid() . '-' . bin2hex(random_bytes(4));
raceAssert(mkdir($tempDir, 0700, true), 'unable to create race test directory');
$dbPath = $tempDir . '/race.sqlite';
$routerPath = $tempDir . '/router.php';
$tracePath = $tempDir . '/requests.log';
$serverProcesses = [];
$logFiles = [];

try {
    createRaceFixture($dbPath);
    writeRouter($routerPath, $dbPath, $repoRoot);
    $pdo = racePdo($dbPath);

    // Linux/macOS: one endpoint with multiple workers. Windows: several independent
    // endpoint processes, because PHP_CLI_SERVER_WORKERS is unsupported there.
    $serverCount = PHP_OS_FAMILY === 'Windows' ? 4 : 1;
    $workers = PHP_OS_FAMILY === 'Windows' ? 1 : 8;
    $urls = [];
    for ($i = 0; $i < $serverCount; $i++) {
        [$process, $url, $log] = startRaceServer($repoRoot, $routerPath, $dbPath, $tracePath, $workers);
        $serverProcesses[] = $process;
        $urls[] = $url;
        $logFiles[] = $log;
    }

    // Scenario 1: identical notification_id + request id in a concurrent burst.
    resetRaceState($pdo);
    $payload = ['id' => 910001, 'type' => 'payment', 'action' => 'payment.updated', 'live_mode' => false, 'data' => ['id' => '777001']];
    $body = json_encode($payload, JSON_THROW_ON_ERROR);
    $urlsBurst = [];
    $bodiesBurst = [];
    $headersBurst = [];
    for ($i = 0; $i < 16; $i++) {
        $urlsBurst[] = $urls[$i % count($urls)];
        $bodiesBurst[] = $body;
        $headersBurst[] = signRace($payload, 'race-same-request', time());
    }
    $responses = concurrentPosts($urlsBurst, $bodiesBurst, $headersBurst);

    $statuses = array_map(static fn(array $r): int => $r['status'], $responses);
    raceAssert(count(array_filter($statuses, static fn(int $s): bool => $s === 200)) >= 1, 'at least one concurrent identical request must succeed');
    raceAssert(count(array_filter($statuses, static fn(int $s): bool => !in_array($s, [200], true))) === 0, 'all concurrent idempotent losers must return HTTP 200: ' . implode(',', $statuses));
    raceSame(1, (int)$pdo->query("SELECT COUNT(*) FROM payment_audit_log WHERE idempotency_key='mp:webhook:910001'")->fetchColumn(), 'concurrent identical requests must produce exactly one audit row');
    raceSame(1, (int)$pdo->query("SELECT COUNT(DISTINCT idempotency_key) FROM payment_audit_log WHERE idempotency_key='mp:webhook:910001'")->fetchColumn(), 'idempotency key must remain unique');
    raceSame('paid', (string)$pdo->query('SELECT status FROM payment_transactions WHERE id=100')->fetchColumn(), 'only one payment transition may be applied');
    raceSame('paid', (string)$pdo->query('SELECT payment_status FROM orders WHERE id=10')->fetchColumn(), 'order confirmation must be applied exactly once');
    raceSame('confirmed', (string)$pdo->query('SELECT status FROM orders WHERE id=10')->fetchColumn(), 'order must not remain in an intermediate state');
    raceSame(4, (int)$pdo->query('SELECT stock_quantity FROM products WHERE id=50')->fetchColumn(), 'successful paid race must not duplicate stock mutation');

    // Scenario 2: distinct notifications for the same payment transaction.
    resetRaceState($pdo);
    $payloadA = ['id' => 910002, 'type' => 'payment', 'action' => 'payment.updated', 'live_mode' => false, 'data' => ['id' => '777002']];
    $payloadB = ['id' => 910003, 'type' => 'payment', 'action' => 'payment.updated', 'live_mode' => false, 'data' => ['id' => '777001']];
    $bodyA = json_encode($payloadA, JSON_THROW_ON_ERROR);
    $bodyB = json_encode($payloadB, JSON_THROW_ON_ERROR);
    $timestamp = time();
    $responses = concurrentPosts(
        [$urls[0], $urls[min(1, count($urls) - 1)]],
        [$bodyA, $bodyB],
        [signRace($payloadA, 'race-distinct-a', $timestamp), signRace($payloadB, 'race-distinct-b', $timestamp)]
    );
    foreach ($responses as $response) {
        raceAssert($response['error'] === '', 'distinct concurrent request must not have transport errors');
        raceAssert(in_array($response['status'], [200, 500], true), 'distinct concurrent events must finish with a controlled 200/500, never an unhandled HTTP status');
    }
    $payment = (string)$pdo->query('SELECT status FROM payment_transactions WHERE id=100')->fetchColumn();
    $orderPayment = (string)$pdo->query('SELECT payment_status FROM orders WHERE id=10')->fetchColumn();
    raceAssert(in_array($payment, ['authorized', 'paid'], true), 'final payment state must be a valid committed state');
    raceSame($payment, $orderPayment, 'payment and order status must remain transactionally consistent');
    raceSame(4, (int)$pdo->query('SELECT stock_quantity FROM products WHERE id=50')->fetchColumn(), 'distinct status race must not corrupt stock');
    $distinctAuditCount = (int)$pdo->query("SELECT COUNT(*) FROM payment_audit_log WHERE idempotency_key IN ('mp:webhook:910002','mp:webhook:910003')")->fetchColumn();
    raceAssert($distinctAuditCount >= 1 && $distinctAuditCount <= 2, 'distinct events must commit zero, one or two complete audit records only');

    // Scenario 3: a failed concurrent event must rollback audit + payment + order atomically.
    resetRaceState($pdo);
    $payloadFail = ['id' => 910004, 'type' => 'payment', 'action' => 'payment.updated', 'live_mode' => false, 'data' => ['id' => '777003']];
    $payloadGood = ['id' => 910005, 'type' => 'payment', 'action' => 'payment.updated', 'live_mode' => false, 'data' => ['id' => '777001']];
    $responses = concurrentPosts(
        [$urls[0], $urls[min(1, count($urls) - 1)]],
        [json_encode($payloadFail, JSON_THROW_ON_ERROR), json_encode($payloadGood, JSON_THROW_ON_ERROR)],
        [signRace($payloadFail, 'race-failure', time()), signRace($payloadGood, 'race-good', time())]
    );
    foreach ($responses as $response) raceAssert(in_array($response['status'], [200, 500], true), 'rollback race must return a controlled status');
    $orphanAudit = (int)$pdo->query("SELECT COUNT(*) FROM payment_audit_log WHERE idempotency_key='mp:webhook:910004'")->fetchColumn();
    raceSame(0, $orphanAudit, 'failed event must not leave an orphan audit record');
    $payment = (string)$pdo->query('SELECT status FROM payment_transactions WHERE id=100')->fetchColumn();
    $orderPayment = (string)$pdo->query('SELECT payment_status FROM orders WHERE id=10')->fetchColumn();
    raceSame($payment, $orderPayment, 'rollback burst must preserve payment/order consistency');
    raceAssert(in_array($payment, ['pending', 'paid'], true), 'rollback burst must leave only an original or fully committed state');
    raceSame(4, (int)$pdo->query('SELECT stock_quantity FROM products WHERE id=50')->fetchColumn(), 'rollback burst must preserve stock integrity');

    // Database-level invariants: no duplicate idempotency keys and no orphaned audit rows.
    $duplicateKeys = (int)$pdo->query('SELECT COUNT(*) FROM (SELECT idempotency_key FROM payment_audit_log WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING COUNT(*) > 1)')->fetchColumn();
    raceSame(0, $duplicateKeys, 'payment_audit_log must have no duplicate idempotency keys');
    $orphanRows = (int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log a LEFT JOIN payment_transactions p ON p.id=a.payment_transaction_id WHERE p.id IS NULL')->fetchColumn();
    raceSame(0, $orphanRows, 'audit log must not contain orphaned transaction rows');

    echo "PASS: webhook_concurrency_test\n";
    echo 'CONCURRENT_REQUESTS: 16' . PHP_EOL;
    echo 'HTTP_WORKERS: ' . ($workers * $serverCount) . PHP_EOL;
    echo 'PLATFORM_MODE: ' . PHP_OS_FAMILY . PHP_EOL;
} finally {
    foreach ($serverProcesses as $process) stopRaceServer($process);
    foreach ($logFiles as $file) if (is_file($file)) @unlink($file);
    foreach ([$routerPath, $dbPath, $tracePath] as $file) if (is_file($file)) @unlink($file);
    foreach ([$dbPath . '-wal', $dbPath . '-shm'] as $file) if (is_file($file)) @unlink($file);
    if (is_dir($tempDir)) @rmdir($tempDir);
}
