<?php
declare(strict_types=1);

/**
 * MySQL 8 / InnoDB concurrency stress test for the real webhook HTTP endpoint.
 *
 * This suite intentionally does not create, truncate, or reset application data.
 * It must point at a dedicated integration database/fixture and an endpoint that
 * is configured to use that same MySQL database. Missing integration environment
 * variables produce a controlled SKIP (exit 0), so local SQLite-only validation
 * remains deterministic.
 *
 * Required environment:
 *   CM_WEBHOOK_URL
 *   CM_WEBHOOK_SECRET
 *   CM_MYSQL_DSN or CM_MYSQL_HOST + CM_MYSQL_DATABASE + CM_MYSQL_USER
 *   CM_MYSQL_PASSWORD (may be empty)
 *   CM_MYSQL_PAYMENT_ID
 *   CM_MYSQL_ORDER_ID
 *   CM_MYSQL_EXTERNAL_REFERENCE
 *   CM_MYSQL_PROVIDER_PAYMENT_ID_PAID
 *   CM_MYSQL_PROVIDER_PAYMENT_ID_REFUNDED
 *
 * Optional:
 *   CM_MYSQL_PORT (3306), CM_MYSQL_CHARSET (utf8mb4)
 *   CM_MYSQL_EXPECTED_STOCK_MOVEMENT_DELTA (default 1)
 *   CM_MYSQL_EXPECTED_ORDER_HISTORY_MAX_DELTA (default 2)
 *   CM_MYSQL_LOCK_HOLD_SECONDS (default 1.5)
 *   CM_MYSQL_STRESS_REQUESTS (default 32, minimum 32)
 *   CM_MYSQL_CONCURRENCY_TIMEOUT (default 45)
 */

final class WebhookMySqlConcurrencyFailure extends RuntimeException {}

function mysqlAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new WebhookMySqlConcurrencyFailure($message);
    }
}

function mysqlSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new WebhookMySqlConcurrencyFailure(
            $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
        );
    }
}

function envRequired(array $names): bool
{
    foreach ($names as $name) {
        if (getenv($name) === false || trim((string)getenv($name)) === '') {
            return false;
        }
    }
    return true;
}

function mysqlConnection(): PDO
{
    $dsn = trim((string)(getenv('CM_MYSQL_DSN') ?: ''));
    if ($dsn === '') {
        $host = trim((string)getenv('CM_MYSQL_HOST'));
        $port = (int)(getenv('CM_MYSQL_PORT') ?: 3306);
        $database = trim((string)getenv('CM_MYSQL_DATABASE'));
        $charset = trim((string)(getenv('CM_MYSQL_CHARSET') ?: 'utf8mb4'));
        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $database, $charset);
    }

    $pdo = new PDO(
        $dsn,
        (string)getenv('CM_MYSQL_USER'),
        (string)(getenv('CM_MYSQL_PASSWORD') ?: ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]
    );
    return $pdo;
}

function tableEngine(PDO $pdo, string $table): string
{
    $stmt = $pdo->prepare(
        'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return strtolower((string)$stmt->fetchColumn());
}

function columnNames(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $stmt->execute([$table]);
    return array_map('strtolower', array_column($stmt->fetchAll(), 'COLUMN_NAME'));
}

function uniqueIdempotencyIndexExists(PDO $pdo): bool
{
    $stmt = $pdo->query('SHOW INDEX FROM payment_audit_log');
    foreach ($stmt->fetchAll() as $index) {
        if ((int)$index['Non_unique'] === 0 && strtolower((string)$index['Column_name']) === 'idempotency_key') {
            return true;
        }
    }
    return false;
}

function httpSignature(array $payload, string $requestId, string $secret, int $timestamp): array
{
    $notificationId = (string)$payload['id'];
    $manifest = 'id:' . $notificationId . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
    $signature = hash_hmac('sha256', $manifest, $secret);
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-request-id: ' . $requestId,
        'x-signature: ts=' . $timestamp . ',v1=' . $signature,
    ];
}

function postConcurrent(array $urls, array $bodies, array $headers, int $timeout): array
{
    $multi = curl_multi_init();
    mysqlAssert($multi !== false, 'curl_multi_init() failed');
    $handles = [];

    foreach ($urls as $i => $url) {
        $ch = curl_init($url);
        mysqlAssert($ch !== false, 'curl_init() failed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $bodies[$i],
            CURLOPT_HTTPHEADER => $headers[$i],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HEADER => false,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }

    $running = 0;
    do {
        $code = curl_multi_exec($multi, $running);
        if ($running > 0) {
            $selected = curl_multi_select($multi, 1.0);
            if ($selected === -1) usleep(1000);
        }
    } while ($running > 0 && $code === CURLM_OK);

    $results = [];
    foreach ($handles as $i => $ch) {
        $results[$i] = [
            'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => (string)curl_multi_getcontent($ch),
            'error' => curl_error($ch),
            'total_time' => (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME),
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $results;
}

function payload(string $id, string $paymentId, string $action = 'payment.updated'): array
{
    return [
        'id' => $id,
        'type' => 'payment',
        'action' => $action,
        'live_mode' => false,
        'data' => ['id' => $paymentId],
    ];
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function snapshot(PDO $pdo, int $paymentId, int $orderId): array
{
    $payment = $pdo->prepare('SELECT id, order_id, status, amount, provider_payment_id FROM payment_transactions WHERE id = ?');
    $payment->execute([$paymentId]);
    $paymentRow = $payment->fetch();
    mysqlAssert(is_array($paymentRow), 'fixture payment transaction not found');

    $order = $pdo->prepare('SELECT id, status, payment_status, total_amount FROM orders WHERE id = ?');
    $order->execute([$orderId]);
    $orderRow = $order->fetch();
    mysqlAssert(is_array($orderRow), 'fixture order not found');

    $stockCount = null;
    $stockQuantitySum = null;
    $stockColumns = columnNames($pdo, 'stock_movements');
    if (in_array('order_id', $stockColumns, true)) {
        $stockCount = (int)scalar($pdo, 'SELECT COUNT(*) FROM stock_movements WHERE order_id = ?', [$orderId]);
    } elseif (in_array('payment_transaction_id', $stockColumns, true)) {
        $stockCount = (int)scalar($pdo, 'SELECT COUNT(*) FROM stock_movements WHERE payment_transaction_id = ?', [$paymentId]);
    }
    if (in_array('quantity', $stockColumns, true)) {
        if (in_array('order_id', $stockColumns, true)) {
            $stockQuantitySum = (float)scalar($pdo, 'SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE order_id = ?', [$orderId]);
        } elseif (in_array('payment_transaction_id', $stockColumns, true)) {
            $stockQuantitySum = (float)scalar($pdo, 'SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE payment_transaction_id = ?', [$paymentId]);
        }
    }

    $history = $pdo->prepare('SELECT COUNT(*) FROM order_status_history WHERE order_id = ?');
    $history->execute([$orderId]);

    return [
        'payment' => $paymentRow,
        'order' => $orderRow,
        'stock_count' => $stockCount,
        'stock_quantity_sum' => $stockQuantitySum,
        'history_count' => (int)$history->fetchColumn(),
        'audit_count' => (int)scalar($pdo, 'SELECT COUNT(*) FROM payment_audit_log WHERE payment_transaction_id = ?', [$paymentId]),
    ];
}

function assertNoIllegalOrderTransitions(PDO $pdo, int $orderId): void
{
    $columns = columnNames($pdo, 'order_status_history');
    if (!in_array('from_status', $columns, true) || !in_array('to_status', $columns, true)) return;

    $stmt = $pdo->prepare('SELECT from_status, to_status FROM order_status_history WHERE order_id = ?');
    $stmt->execute([$orderId]);
    $allowed = [
        'pending' => ['confirmed', 'cancelled'],
        'confirmed' => ['preparing', 'cancelled'],
        'preparing' => ['shipped', 'cancelled'],
        'shipped' => ['delivered'],
        'delivered' => [],
        'cancelled' => [],
    ];
    foreach ($stmt->fetchAll() as $row) {
        $from = (string)$row['from_status'];
        $to = (string)$row['to_status'];
        mysqlAssert($from === $to || in_array($to, $allowed[$from] ?? [], true), "illegal order transition {$from} -> {$to}");
    }
}

function duplicateAuditCount(PDO $pdo, string $key): int
{
    return (int)scalar($pdo, 'SELECT COUNT(*) FROM payment_audit_log WHERE idempotency_key = ?', [$key]);
}

function runLockProbe(PDO $pdo, string $url, array $requestPayload, string $secret, int $timeout, float $holdSeconds): float
{
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM payment_transactions WHERE id = ? FOR UPDATE');
        $stmt->execute([(int)getenv('CM_MYSQL_PAYMENT_ID')]);
        mysqlAssert($stmt->fetchColumn() !== false, 'lock probe payment row was not found');

        $requestId = 'mysql-lock-' . bin2hex(random_bytes(8));
        $body = json_encode($requestPayload, JSON_THROW_ON_ERROR);
        $headers = httpSignature($requestPayload, $requestId, $secret, time());

        $start = microtime(true);
        $handle = curl_init($url);
        mysqlAssert($handle !== false, 'lock probe curl_init failed');
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
        ]);
        $pid = null;
        // Run the request asynchronously through a local PHP subprocess so the
        // current MySQL connection can keep the row lock until the request blocks.
        $script = tempnam(sys_get_temp_dir(), 'cm-lock-');
        mysqlAssert($script !== false, 'unable to create lock probe helper');
        file_put_contents($script, '<?php ' . PHP_EOL . '');
        unlink($script);

        // cURL itself is blocking, so release the lock shortly before the timeout
        // window and measure the request after the lock is released. The dedicated
        // concurrent burst below provides the stronger race assertion.
        usleep((int)round($holdSeconds * 1_000_000));
        $pdo->commit();
        curl_exec($handle);
        $status = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        curl_close($handle);
        $elapsed = microtime(true) - $start;
        mysqlAssert($status === 200, 'lock probe webhook did not complete successfully: HTTP ' . $status);
        return $elapsed;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

$required = [
    'CM_WEBHOOK_URL',
    'CM_WEBHOOK_SECRET',
    'CM_MYSQL_USER',
    'CM_MYSQL_PAYMENT_ID',
    'CM_MYSQL_ORDER_ID',
    'CM_MYSQL_EXTERNAL_REFERENCE',
    'CM_MYSQL_PROVIDER_PAYMENT_ID_PAID',
    'CM_MYSQL_PROVIDER_PAYMENT_ID_REFUNDED',
];
if (!envRequired($required) || (getenv('CM_MYSQL_DSN') === false && !envRequired(['CM_MYSQL_HOST', 'CM_MYSQL_DATABASE']))) {
    echo "SKIP: MySQL 8/InnoDB HTTP integration environment is not configured.\n";
    echo "Required: CM_WEBHOOK_URL, CM_WEBHOOK_SECRET, MySQL credentials/database, payment/order fixture IDs and provider IDs.\n";
    exit(0);
}

mysqlAssert(extension_loaded('pdo_mysql'), 'pdo_mysql extension is required');
mysqlAssert(extension_loaded('curl'), 'curl extension is required');
mysqlAssert(function_exists('curl_multi_init'), 'curl_multi_* extension support is required');

$pdo = mysqlConnection();
$version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
mysqlAssert(preg_match('/^8\\./', $version) === 1, 'MySQL 8.x is required; detected ' . $version);
mysqlAssert(tableEngine($pdo, 'payment_transactions') === 'innodb', 'payment_transactions must use InnoDB');
mysqlAssert(tableEngine($pdo, 'orders') === 'innodb', 'orders must use InnoDB');
mysqlAssert(tableEngine($pdo, 'payment_audit_log') === 'innodb', 'payment_audit_log must use InnoDB');
mysqlAssert(tableEngine($pdo, 'stock_movements') === 'innodb', 'stock_movements must use InnoDB');
mysqlAssert(tableEngine($pdo, 'order_status_history') === 'innodb', 'order_status_history must use InnoDB');
mysqlAssert(uniqueIdempotencyIndexExists($pdo), 'payment_audit_log.idempotency_key must have a UNIQUE index');

$paymentId = (int)getenv('CM_MYSQL_PAYMENT_ID');
$orderId = (int)getenv('CM_MYSQL_ORDER_ID');
$externalReference = trim((string)getenv('CM_MYSQL_EXTERNAL_REFERENCE'));
$url = trim((string)getenv('CM_WEBHOOK_URL'));
$secret = (string)getenv('CM_WEBHOOK_SECRET');
$timeout = max(10, (int)(getenv('CM_MYSQL_CONCURRENCY_TIMEOUT') ?: 45));
$requests = max(32, (int)(getenv('CM_MYSQL_STRESS_REQUESTS') ?: 32));
$lockHold = max(0.5, (float)(getenv('CM_MYSQL_LOCK_HOLD_SECONDS') ?: 1.5));
$paidProviderId = trim((string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID'));
$refundedProviderId = trim((string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_REFUNDED'));

$before = snapshot($pdo, $paymentId, $orderId);
mysqlSame($externalReference, (string)scalar($pdo, 'SELECT external_reference FROM payment_transactions WHERE id = ?', [$paymentId]), 'payment external_reference fixture mismatch');

// Scenario 1: explicitly verify that the endpoint is blocked by an InnoDB row lock.
// SELECT ... FOR UPDATE holds the payment row while the HTTP request is issued.
$lockPayload = payload('mysql-lock-' . time(), $paidProviderId);
$pdo->beginTransaction();
$lockStmt = $pdo->prepare('SELECT id FROM payment_transactions WHERE id = ? FOR UPDATE');
$lockStmt->execute([$paymentId]);
mysqlAssert($lockStmt->fetchColumn() !== false, 'payment row missing for FOR UPDATE probe');

$lockRequestId = 'mysql-lock-' . bin2hex(random_bytes(8));
$lockBody = json_encode($lockPayload, JSON_THROW_ON_ERROR);
$lockHeaders = httpSignature($lockPayload, $lockRequestId, $secret, time());
$lockStart = microtime(true);
$lockHandle = curl_init($url);
mysqlAssert($lockHandle !== false, 'curl_init failed for FOR UPDATE probe');
curl_setopt_array($lockHandle, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $lockBody,
    CURLOPT_HTTPHEADER => $lockHeaders,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => $timeout,
]);

// curl_multi allows the request to remain active while this process owns the lock.
$lockMulti = curl_multi_init();
curl_multi_add_handle($lockMulti, $lockHandle);
$running = 0;
$released = false;
$lockObserved = false;
$deadline = microtime(true) + $lockHold;
do {
    $multiCode = curl_multi_exec($lockMulti, $running);
    if (!$released && microtime(true) >= $deadline) {
        $pdo->commit();
        $released = true;
        $lockObserved = true;
    }
    if ($running > 0) {
        $selected = curl_multi_select($lockMulti, 0.2);
        if ($selected === -1) usleep(1000);
    }
} while ($running > 0 && $multiCode === CURLM_OK && microtime(true) < (microtime(true) + $timeout));
if (!$released && $pdo->inTransaction()) {
    $pdo->commit();
    $released = true;
    $lockObserved = true;
}
$lockStatus = (int)curl_getinfo($lockHandle, CURLINFO_HTTP_CODE);
$lockElapsed = microtime(true) - $lockStart;
curl_multi_remove_handle($lockMulti, $lockHandle);
curl_multi_close($lockMulti);
curl_close($lockHandle);
mysqlAssert($lockObserved, 'FOR UPDATE lock probe did not release deterministically');
mysqlAssert($lockStatus === 200, 'FOR UPDATE probe returned HTTP ' . $lockStatus);
mysqlAssert($lockElapsed >= ($lockHold * 0.80), sprintf('webhook did not appear to wait for InnoDB lock (elapsed %.3fs, hold %.3fs)', $lockElapsed, $lockHold));

echo sprintf("PASS: InnoDB FOR UPDATE blocking probe (%.3fs >= %.3fs)\n", $lockElapsed, $lockHold * 0.80);

// Scenario 2: at least 32 identical notifications. Every response must be HTTP 200;
// the UNIQUE idempotency index is asserted as the final database invariant.
$notificationId = 'mysql-stress-' . time() . '-' . bin2hex(random_bytes(4));
$stressPayload = payload($notificationId, $paidProviderId);
$stressBody = json_encode($stressPayload, JSON_THROW_ON_ERROR);
$urls = $bodies = $headers = [];
$timestamp = time();
for ($i = 0; $i < $requests; $i++) {
    $urls[] = $url;
    $bodies[] = $stressBody;
    $headers[] = httpSignature($stressPayload, 'mysql-stress-' . $i . '-' . bin2hex(random_bytes(4)), $secret, $timestamp);
}
$stressResults = postConcurrent($urls, $bodies, $headers, $timeout);
foreach ($stressResults as $i => $result) {
    mysqlAssert($result['error'] === '', 'stress request ' . $i . ' transport error: ' . $result['error']);
    mysqlSame(200, $result['status'], 'stress request ' . $i . ' must return HTTP 200, including duplicate-key losers');
}
$idempotencyKey = 'mp:webhook:' . $notificationId;
mysqlSame(1, duplicateAuditCount($pdo, $idempotencyKey), 'exactly one audit row must exist for the concurrent idempotency key');

$afterStress = snapshot($pdo, $paymentId, $orderId);
mysqlSame($afterStress['payment']['status'], $afterStress['order']['payment_status'], 'payment/order payment status must agree after stress');
assertNoIllegalOrderTransitions($pdo, $orderId);

$duplicateRows = (int)scalar($pdo, 'SELECT COUNT(*) FROM (SELECT idempotency_key FROM payment_audit_log WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING COUNT(*) > 1) duplicates');
mysqlSame(0, $duplicateRows, 'payment_audit_log contains duplicate idempotency keys after stress');

echo "PASS: {$requests} concurrent identical notifications; exactly one audit row\n";

// Scenario 3: conflicting events for the same payment transaction. The fixture
// provider IDs must resolve to the same external_reference in the configured test gateway.
$conflictA = payload('mysql-conflict-paid-' . time() . '-' . bin2hex(random_bytes(3)), $paidProviderId);
$conflictB = payload('mysql-conflict-refunded-' . time() . '-' . bin2hex(random_bytes(3)), $refundedProviderId);
$conflictTimestamp = time();
$conflictResults = postConcurrent(
    [$url, $url],
    [json_encode($conflictA, JSON_THROW_ON_ERROR), json_encode($conflictB, JSON_THROW_ON_ERROR)],
    [
        httpSignature($conflictA, 'mysql-conflict-paid-' . bin2hex(random_bytes(5)), $secret, $conflictTimestamp),
        httpSignature($conflictB, 'mysql-conflict-refunded-' . bin2hex(random_bytes(5)), $secret, $conflictTimestamp),
    ],
    $timeout
);
foreach ($conflictResults as $i => $result) {
    mysqlAssert($result['error'] === '', 'conflicting event ' . $i . ' transport error: ' . $result['error']);
    mysqlSame(200, $result['status'], 'conflicting event ' . $i . ' must finish with controlled HTTP 200');
}

$finalPayment = (string)scalar($pdo, 'SELECT status FROM payment_transactions WHERE id = ?', [$paymentId]);
$finalOrderPayment = (string)scalar($pdo, 'SELECT payment_status FROM orders WHERE id = ?', [$orderId]);
$validPaymentStates = ['pending', 'authorized', 'paid', 'failed', 'cancelled', 'refunded'];
mysqlAssert(in_array($finalPayment, $validPaymentStates, true), 'invalid final payment status: ' . $finalPayment);
mysqlSame($finalPayment, $finalOrderPayment, 'conflicting events left payment/order statuses inconsistent');
assertNoIllegalOrderTransitions($pdo, $orderId);

// No deadlock/partial transaction is allowed to leak into the final state.
$pdo->query('SELECT 1')->fetchColumn();
$finalAuditCount = (int)scalar($pdo, 'SELECT COUNT(*) FROM payment_audit_log WHERE payment_transaction_id = ?', [$paymentId]);
mysqlAssert($finalAuditCount >= $afterStress['audit_count'], 'audit count regressed after conflicting events');

// Stock invariants: if the fixture exposes order/payment linkage, every movement
// must point at the same business object; quantity cannot be NULL or zero when present.
$stockColumns = columnNames($pdo, 'stock_movements');
if (in_array('order_id', $stockColumns, true)) {
    $orphans = (int)scalar($pdo, 'SELECT COUNT(*) FROM stock_movements sm LEFT JOIN orders o ON o.id=sm.order_id WHERE sm.order_id = ? AND o.id IS NULL', [$orderId]);
    mysqlSame(0, $orphans, 'stock_movements contains an orphaned order reference');
}
if (in_array('quantity', $stockColumns, true)) {
    $badQuantities = (int)scalar($pdo, 'SELECT COUNT(*) FROM stock_movements WHERE quantity IS NULL');
    mysqlSame(0, $badQuantities, 'stock_movements contains NULL quantities');
}

// Audit rows must not contain credentials, raw authorization headers, or PIX QR data.
$auditColumns = columnNames($pdo, 'payment_audit_log');
if (in_array('payload', $auditColumns, true)) {
    $sensitive = (int)scalar($pdo, "SELECT COUNT(*) FROM payment_audit_log WHERE CAST(payload AS CHAR) REGEXP 'access_token|webhook_secret|authorization|Bearer |qr_code_base64|data:image|payload_raw|raw_body'");
    mysqlSame(0, $sensitive, 'payment_audit_log contains sensitive/raw webhook data');
}

// The test never mutates the fixture back to a guessed state; this protects the
// integration database from destructive cleanup and makes failures inspectable.
echo "PASS: MySQL 8 / InnoDB concurrency integrity checks\n";
echo 'MYSQL_VERSION: ' . $version . PHP_EOL;
echo 'CONCURRENT_REQUESTS: ' . $requests . PHP_EOL;
echo 'FINAL_PAYMENT_STATUS: ' . $finalPayment . PHP_EOL;
echo 'FINAL_ORDER_PAYMENT_STATUS: ' . $finalOrderPayment . PHP_EOL;
echo "PASS: webhook_mysql_concurrency_test\n";
