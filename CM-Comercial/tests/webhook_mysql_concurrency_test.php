<?php
declare(strict_types=1);

/**
 * MySQL 8 / InnoDB webhook concurrency integration gate.
 *
 * Uses a dedicated integration fixture. It never resets application data.
 * Requires the webhook endpoint and MySQL 8/InnoDB environment variables.
 */
final class WebhookMySqlConcurrencyFailure extends RuntimeException {}

function mysqlAssert(bool $condition, string $message): void
{
    if (!$condition) throw new WebhookMySqlConcurrencyFailure($message);
}

function mysqlSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new WebhookMySqlConcurrencyFailure($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
    }
}

function envRequired(array $names): bool
{
    foreach ($names as $name) {
        if (getenv($name) === false || trim((string)getenv($name)) === '') return false;
    }
    return true;
}

function mysqlConnection(): PDO
{
    $dsn = trim((string)(getenv('CM_MYSQL_DSN') ?: ''));
    if ($dsn === '') {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            trim((string)getenv('CM_MYSQL_HOST')),
            (int)(getenv('CM_MYSQL_PORT') ?: 3306),
            trim((string)getenv('CM_MYSQL_DATABASE')),
            trim((string)(getenv('CM_MYSQL_CHARSET') ?: 'utf8mb4'))
        );
    }
    return new PDO($dsn, (string)getenv('CM_MYSQL_USER'), (string)(getenv('CM_MYSQL_PASSWORD') ?: ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 10,
    ]);
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function columns(PDO $pdo, string $table): array
{
    $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $stmt->execute([$table]);
    return array_map('strtolower', array_column($stmt->fetchAll(), 'COLUMN_NAME'));
}

function engine(PDO $pdo, string $table): string
{
    return strtolower((string)scalar($pdo, 'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?', [$table]));
}

function signature(array $payload, string $requestId, string $secret, int $timestamp): array
{
    $manifest = 'id:' . $payload['id'] . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';
    return [
        'Content-Type: application/json',
        'Accept: application/json',
        'x-request-id: ' . $requestId,
        'x-signature: ts=' . $timestamp . ',v1=' . hash_hmac('sha256', $manifest, $secret),
    ];
}

function postConcurrent(string $url, array $requests, int $timeout): array
{
    $multi = curl_multi_init();
    mysqlAssert($multi !== false, 'curl_multi_init failed');
    $handles = [];
    foreach ($requests as $i => $request) {
        $ch = curl_init($url);
        mysqlAssert($ch !== false, 'curl_init failed');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $request['body'],
            CURLOPT_HTTPHEADER => $request['headers'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }
    $running = 0;
    do {
        $code = curl_multi_exec($multi, $running);
        if ($running > 0 && curl_multi_select($multi, 1.0) === -1) usleep(1000);
    } while ($running > 0 && $code === CURLM_OK);

    $results = [];
    foreach ($handles as $i => $ch) {
        $results[$i] = [
            'status' => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
            'body' => (string)curl_multi_getcontent($ch),
            'error' => curl_error($ch),
        ];
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $results;
}

function payload(string $notificationId, string $paymentId): array
{
    return ['id'=>$notificationId, 'type'=>'payment', 'action'=>'payment.updated', 'live_mode'=>false, 'data'=>['id'=>$paymentId]];
}

function stockSnapshot(PDO $pdo, int $orderId, int $paymentId): array
{
    $cols = columns($pdo, 'stock_movements');
    $count = null;
    $quantity = null;
    if (in_array('order_id', $cols, true)) {
        $count = (int)scalar($pdo, 'SELECT COUNT(*) FROM stock_movements WHERE order_id=?', [$orderId]);
        if (in_array('quantity', $cols, true)) $quantity = (float)scalar($pdo, 'SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE order_id=?', [$orderId]);
    } elseif (in_array('payment_transaction_id', $cols, true)) {
        $count = (int)scalar($pdo, 'SELECT COUNT(*) FROM stock_movements WHERE payment_transaction_id=?', [$paymentId]);
        if (in_array('quantity', $cols, true)) $quantity = (float)scalar($pdo, 'SELECT COALESCE(SUM(quantity),0) FROM stock_movements WHERE payment_transaction_id=?', [$paymentId]);
    }
    return ['count'=>$count, 'quantity'=>$quantity];
}

$required = [
    'CM_WEBHOOK_URL','CM_WEBHOOK_SECRET','CM_MYSQL_USER','CM_MYSQL_PAYMENT_ID','CM_MYSQL_ORDER_ID',
    'CM_MYSQL_EXTERNAL_REFERENCE','CM_MYSQL_PROVIDER_PAYMENT_ID_PAID','CM_MYSQL_PROVIDER_PAYMENT_ID_REFUNDED',
];
if (!envRequired($required) || (getenv('CM_MYSQL_DSN') === false && !envRequired(['CM_MYSQL_HOST','CM_MYSQL_DATABASE']))) {
    echo "SKIP: MySQL 8/InnoDB HTTP integration environment is not configured.\n";
    exit(0);
}

mysqlAssert(extension_loaded('pdo_mysql'), 'pdo_mysql extension is required');
mysqlAssert(extension_loaded('curl') && function_exists('curl_multi_init'), 'curl/curl_multi support is required');
$pdo = mysqlConnection();
$version = (string)$pdo->query('SELECT VERSION()')->fetchColumn();
mysqlAssert(preg_match('/^8\\./', $version) === 1, 'MySQL 8.x is required: ' . $version);
foreach (['payment_transactions','orders','order_status_history','stock_movements','payment_audit_log'] as $table) {
    mysqlSame('innodb', engine($pdo, $table), $table . ' must use InnoDB');
}

$paymentId = (int)getenv('CM_MYSQL_PAYMENT_ID');
$orderId = (int)getenv('CM_MYSQL_ORDER_ID');
$url = trim((string)getenv('CM_WEBHOOK_URL'));
$secret = (string)getenv('CM_WEBHOOK_SECRET');
$paidId = trim((string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID'));
$refundedId = trim((string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_REFUNDED'));
$requests = max(32, (int)(getenv('CM_MYSQL_STRESS_REQUESTS') ?: 32));
$timeout = max(10, (int)(getenv('CM_MYSQL_CONCURRENCY_TIMEOUT') ?: 45));

mysqlSame((string)getenv('CM_MYSQL_EXTERNAL_REFERENCE'), (string)scalar($pdo, 'SELECT external_reference FROM payment_transactions WHERE id=?', [$paymentId]), 'payment fixture external_reference mismatch');
$before = stockSnapshot($pdo, $orderId, $paymentId);
$historyBefore = (int)scalar($pdo, 'SELECT COUNT(*) FROM order_status_history WHERE order_id=?', [$orderId]);
$auditBefore = (int)scalar($pdo, 'SELECT COUNT(*) FROM payment_audit_log WHERE payment_transaction_id=?', [$paymentId]);

// Baseline: 32+ identical notifications. Persistent idempotency must collapse them to one audit event.
$notificationId = 'mysql-stress-' . time() . '-' . bin2hex(random_bytes(4));
$stress = [];
$timestamp = time();
for ($i=0; $i<$requests; $i++) {
    $body = json_encode(payload($notificationId, $paidId), JSON_THROW_ON_ERROR);
    $stress[] = ['body'=>$body, 'headers'=>signature(json_decode($body, true, 512, JSON_THROW_ON_ERROR), 'mysql-stress-'.$i.'-'.bin2hex(random_bytes(4)), $secret, $timestamp)];
}
foreach (postConcurrent($url, $stress, $timeout) as $i=>$result) {
    mysqlAssert($result['error']==='', 'identical request '.$i.' transport error: '.$result['error']);
    mysqlSame(200, $result['status'], 'identical request '.$i.' must not return HTTP 500');
}
mysqlSame(1, (int)scalar($pdo, 'SELECT COUNT(*) FROM payment_audit_log WHERE idempotency_key=?', ['mp:webhook:'.$notificationId]), 'identical notifications must create exactly one audit row');

// Conflict: 32+ requests for the same payment, mixing paid and refunded events.
// With payment row -> order row locking, exactly one paid->refunded transition may mutate stock.
$conflictRequests = [];
for ($i=0; $i<$requests; $i++) {
    $isRefund = $i >= intdiv($requests, 2);
    $id = 'mysql-conflict-' . ($isRefund ? 'refunded' : 'paid') . '-' . time() . '-' . $i . '-' . bin2hex(random_bytes(2));
    $provider = $isRefund ? $refundedId : $paidId;
    $body = json_encode(payload($id, $provider), JSON_THROW_ON_ERROR);
    $conflictRequests[] = ['body'=>$body, 'headers'=>signature(json_decode($body, true, 512, JSON_THROW_ON_ERROR), 'mysql-conflict-'.$i.'-'.bin2hex(random_bytes(4)), $secret, $timestamp)];
}
$conflicts = postConcurrent($url, $conflictRequests, $timeout);
foreach ($conflicts as $i=>$result) {
    mysqlAssert($result['error']==='', 'conflict request '.$i.' transport error: '.$result['error']);
    mysqlAssert($result['status'] !== 500, 'conflict request '.$i.' returned HTTP 500');
    mysqlAssert(in_array($result['status'], [200, 422], true), 'unexpected conflict HTTP status '.$result['status']);
}

$finalPayment = (string)scalar($pdo, 'SELECT status FROM payment_transactions WHERE id=?', [$paymentId]);
$finalOrderPayment = (string)scalar($pdo, 'SELECT payment_status FROM orders WHERE id=?', [$orderId]);
mysqlAssert(in_array($finalPayment, ['paid','refunded'], true), 'final payment state must be paid or refunded, got '.$finalPayment);
mysqlSame($finalPayment, $finalOrderPayment, 'payment/order payment status diverged');

$historyAfter = (int)scalar($pdo, 'SELECT COUNT(*) FROM order_status_history WHERE order_id=?', [$orderId]);
$stockAfter = stockSnapshot($pdo, $orderId, $paymentId);
$auditAfter = (int)scalar($pdo, 'SELECT COUNT(*) FROM payment_audit_log WHERE payment_transaction_id=?', [$paymentId]);
mysqlAssert($historyAfter >= $historyBefore, 'order status history regressed');
mysqlAssert($auditAfter >= $auditBefore + 1, 'audit trail did not retain concurrent webhook events');

if ($stockAfter['count'] !== null && $before['count'] !== null) {
    $delta = $stockAfter['count'] - $before['count'];
    $expected = (int)(getenv('CM_MYSQL_EXPECTED_STOCK_MOVEMENT_DELTA') ?: 1);
    if ($finalPayment === 'refunded') mysqlSame($expected, $delta, 'exactly one refund stock movement must win');
    else mysqlSame(0, $delta, 'paid winner must not create a refund stock movement');
}
if ($stockAfter['quantity'] !== null && $before['quantity'] !== null && $finalPayment === 'refunded') {
    mysqlAssert($stockAfter['quantity'] >= $before['quantity'], 'refund stock quantity invariant violated');
}

$dupKeys = (int)scalar($pdo, 'SELECT COUNT(*) FROM (SELECT idempotency_key FROM payment_audit_log WHERE idempotency_key IS NOT NULL GROUP BY idempotency_key HAVING COUNT(*)>1) d');
mysqlSame(0, $dupKeys, 'duplicate payment_audit_log idempotency keys detected');
$historyCols = columns($pdo, 'order_status_history');
if (in_array('from_status',$historyCols,true) && in_array('to_status',$historyCols,true)) {
    $stmt=$pdo->prepare('SELECT from_status,to_status FROM order_status_history WHERE order_id=?');
    $stmt->execute([$orderId]);
    $allowed=['pending'=>['confirmed','cancelled'],'confirmed'=>['preparing','cancelled'],'preparing'=>['shipped','cancelled'],'shipped'=>['delivered'],'delivered'=>[],'cancelled'=>[]];
    foreach ($stmt->fetchAll() as $row) mysqlAssert($row['from_status']===$row['to_status'] || in_array($row['to_status'],$allowed[$row['from_status']]??[],true), 'illegal order history transition detected');
}

echo "PASS: MySQL 8 / InnoDB 32+ conflicting webhook concurrency\n";
echo 'MYSQL_VERSION: '.$version.PHP_EOL;
echo 'CONCURRENT_REQUESTS: '.$requests.PHP_EOL;
echo 'FINAL_PAYMENT_STATUS: '.$finalPayment.PHP_EOL;
echo 'FINAL_ORDER_PAYMENT_STATUS: '.$finalOrderPayment.PHP_EOL;
echo "PASS: webhook_mysql_concurrency_test\n";
