<?php
declare(strict_types=1);

/**
 * MySQL 8 / InnoDB deterministic 1205/1213 failure-injection suite.
 *
 * This suite requires a dedicated integration fixture and never resets
 * application data. It is intentionally skipped when MySQL/cURL/pcntl or
 * the required CM_* environment is unavailable.
 */
final class WebhookMySqlDeadlockFailure extends RuntimeException
{
}

function dmAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new WebhookMySqlDeadlockFailure($message);
    }
}

function dmSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new WebhookMySqlDeadlockFailure(
            $message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true)
        );
    }
}

function envOk(): bool
{
    foreach (['CM_WEBHOOK_URL', 'CM_WEBHOOK_SECRET', 'CM_MYSQL_PAYMENT_ID', 'CM_MYSQL_ORDER_ID', 'CM_MYSQL_PROVIDER_PAYMENT_ID_PAID'] as $name) {
        if (getenv($name) === false || trim((string) getenv($name)) === '') {
            return false;
        }
    }

    return getenv('CM_MYSQL_DSN') !== false
        || (getenv('CM_MYSQL_HOST') !== false && getenv('CM_MYSQL_DATABASE') !== false && getenv('CM_MYSQL_USER') !== false);
}

function db(): PDO
{
    $dsn = trim((string) (getenv('CM_MYSQL_DSN') ?: ''));
    if ($dsn === '') {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            getenv('CM_MYSQL_HOST'),
            (int) (getenv('CM_MYSQL_PORT') ?: 3306),
            getenv('CM_MYSQL_DATABASE')
        );
    }

    return new PDO(
        $dsn,
        (string) getenv('CM_MYSQL_USER'),
        (string) (getenv('CM_MYSQL_PASSWORD') ?: ''),
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]
    );
}

function scalar(PDO $pdo, string $sql, array $params = []): mixed
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchColumn();
}

function row(PDO $pdo, string $sql, array $params = []): array
{
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    return is_array($result) ? $result : [];
}

function errorCode(PDOException $exception): array
{
    return [
        (string) $exception->getCode(),
        isset($exception->errorInfo[1]) ? (int) $exception->errorInfo[1] : 0,
    ];
}

function snapshot(PDO $pdo, int $paymentId, int $orderId): array
{
    return [
        'payment' => row(
            $pdo,
            'SELECT status, provider_payment_id, amount FROM payment_transactions WHERE id=?',
            [$paymentId]
        ),
        'order' => row(
            $pdo,
            'SELECT status, payment_status, total_amount FROM orders WHERE id=?',
            [$orderId]
        ),
        'history' => (int) scalar(
            $pdo,
            'SELECT COUNT(*) FROM order_status_history WHERE order_id=?',
            [$orderId]
        ),
        'stock' => (int) scalar(
            $pdo,
            'SELECT COUNT(*) FROM stock_movements WHERE order_id=?',
            [$orderId]
        ),
        'audit' => (int) scalar(
            $pdo,
            'SELECT COUNT(*) FROM payment_audit_log WHERE payment_transaction_id=?',
            [$paymentId]
        ),
    ];
}

function webhook(string $url, string $secret, string $notificationId, string $paymentId): array
{
    $requestId = 'deadlock-' . bin2hex(random_bytes(6));
    $timestamp = time();
    $body = json_encode(
        [
            'id' => $notificationId,
            'type' => 'payment',
            'action' => 'payment.updated',
            'data' => ['id' => $paymentId],
            'live_mode' => false,
        ],
        JSON_THROW_ON_ERROR
    );
    $manifest = 'id:' . $notificationId . ';request-id:' . $requestId . ';ts:' . $timestamp . ';';

    $handle = curl_init($url);
    dmAssert($handle !== false, 'curl_init failed');
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'x-request-id: ' . $requestId,
            'x-signature: ts=' . $timestamp . ',v1=' . hash_hmac('sha256', $manifest, $secret),
        ],
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_FOLLOWLOCATION => false,
    ]);

    $output = curl_exec($handle);
    $result = [
        'status' => (int) curl_getinfo($handle, CURLINFO_HTTP_CODE),
        'body' => (string) $output,
        'error' => curl_error($handle),
    ];
    curl_close($handle);

    return $result;
}

function waitFile(string $file, int $seconds = 10): void
{
    $deadline = microtime(true) + $seconds;
    while (!is_file($file) && microtime(true) < $deadline) {
        usleep(10000);
    }
    dmAssert(is_file($file), 'worker synchronization timeout');
}

if (!extension_loaded('pdo_mysql') || !extension_loaded('curl') || !function_exists('pcntl_fork')) {
    echo "SKIP: pdo_mysql, curl and pcntl_fork are required for deterministic integration\n";
    exit(0);
}

if (!envOk()) {
    echo "SKIP: MySQL deadlock integration environment not configured\n";
    exit(0);
}

try {
    $pdo = db();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    dmAssert(preg_match('/^8\./', $version) === 1, 'MySQL 8 required: ' . $version);

    $paymentId = (int) getenv('CM_MYSQL_PAYMENT_ID');
    $orderId = (int) getenv('CM_MYSQL_ORDER_ID');
    $before = snapshot($pdo, $paymentId, $orderId);

    // 1205: A holds the payment row; B independently waits with a one-second timeout.
    $connectionA = db();
    $connectionA->exec('SET SESSION innodb_lock_wait_timeout=5');
    $connectionA->beginTransaction();
    $connectionA->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]);

    $result1205 = tempnam(sys_get_temp_dir(), 'cm1205-result-');
    dmAssert($result1205 !== false, 'temp file creation failed');
    $child = pcntl_fork();
    dmAssert($child !== -1, 'pcntl_fork failed');

    if ($child === 0) {
        $observed = false;
        try {
            $connectionB = db();
            $connectionB->exec('SET SESSION innodb_lock_wait_timeout=1');
            $connectionB->beginTransaction();
            $connectionB->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]);
        } catch (PDOException $exception) {
            [$sqlState, $vendorCode] = errorCode($exception);
            $observed = $vendorCode === 1205 && $sqlState === 'HY000';
        } finally {
            if (isset($connectionB) && $connectionB->inTransaction()) {
                $connectionB->rollBack();
            }
            file_put_contents($result1205, $observed ? 'PASS' : 'FAIL');
        }
        exit($observed ? 0 : 1);
    }

    sleep(2);
    if ($connectionA->inTransaction()) {
        $connectionA->rollBack();
    }
    $waitStatus = 0;
    pcntl_waitpid($child, $waitStatus);
    dmAssert(pcntl_wexitstatus($waitStatus) === 0, '1205 worker did not observe ER_LOCK_WAIT_TIMEOUT/HY000');
    dmSame($before, snapshot($pdo, $paymentId, $orderId), '1205 scenario mutated fixture');
    @unlink($result1205);

    // 1213: A locks payment, B locks order, then each asks for the opposite row.
    $connectionA = db();
    $connectionA->exec('SET SESSION innodb_lock_wait_timeout=5');
    $connectionA->beginTransaction();
    $connectionA->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]);

    $ready = tempnam(sys_get_temp_dir(), 'cm1213-ready-');
    $result1213 = tempnam(sys_get_temp_dir(), 'cm1213-result-');
    dmAssert($ready !== false && $result1213 !== false, 'temp file creation failed');

    $child = pcntl_fork();
    dmAssert($child !== -1, 'pcntl_fork failed');

    if ($child === 0) {
        $observed = false;
        try {
            $connectionB = db();
            $connectionB->exec('SET SESSION innodb_lock_wait_timeout=5');
            $connectionB->beginTransaction();
            $connectionB->prepare('SELECT id FROM orders WHERE id=? FOR UPDATE')->execute([$orderId]);
            file_put_contents($ready, 'READY');
            $connectionB->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]);
        } catch (PDOException $exception) {
            [$sqlState, $vendorCode] = errorCode($exception);
            $observed = $vendorCode === 1213 && $sqlState === '40001';
        } finally {
            if (isset($connectionB) && $connectionB->inTransaction()) {
                $connectionB->rollBack();
            }
            file_put_contents($result1213, $observed ? 'PASS' : 'FAIL');
        }
        exit($observed ? 0 : 1);
    }

    waitFile($ready);
    $deadlockSeen = false;
    try {
        $connectionA->prepare('SELECT id FROM orders WHERE id=? FOR UPDATE')->execute([$orderId]);
    } catch (PDOException $exception) {
        [$sqlState, $vendorCode] = errorCode($exception);
        $deadlockSeen = $vendorCode === 1213 && $sqlState === '40001';
    } finally {
        if ($connectionA->inTransaction()) {
            $connectionA->rollBack();
        }
    }

    dmAssert($deadlockSeen, '1213/40001 was not observed by either concurrent transaction');
    $waitStatus = 0;
    pcntl_waitpid($child, $waitStatus);
    dmAssert(
        pcntl_wexitstatus($waitStatus) === 0 && trim((string) @file_get_contents($result1213)) === 'PASS',
        'deadlock worker did not observe 1213/40001'
    );
    dmSame($before, snapshot($pdo, $paymentId, $orderId), '1213 rollback left a partial business mutation');
    @unlink($ready);
    @unlink($result1213);

    // Actual webhook under the payment lock: controlled response, never uncaught HTTP 500.
    $connectionA = db();
    $connectionA->beginTransaction();
    $connectionA->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]);
    $http = webhook(
        (string) getenv('CM_WEBHOOK_URL'),
        (string) getenv('CM_WEBHOOK_SECRET'),
        'deadlock-http-' . bin2hex(random_bytes(5)),
        (string) getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID')
    );
    $connectionA->rollBack();

    dmAssert($http["error"] === '', 'webhook transport error: ' . $http["error"]);
    dmAssert($http["status"] !== 500, 'webhook returned uncaught HTTP 500 during lock contention');

    // Legitimate delivery + exact replay: history, stock and audit cannot increase on replay.
    $notificationId = 'deadlock-redelivery-' . bin2hex(random_bytes(5));
    $first = webhook(
        (string) getenv('CM_WEBHOOK_URL'),
        (string) getenv('CM_WEBHOOK_SECRET'),
        $notificationId,
        (string) getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID')
    );
    dmAssert($first["status"] === 200, 'legitimate delivery failed: HTTP ' . $first["status"]);

    $afterFirst = snapshot($pdo, $paymentId, $orderId);
    $second = webhook(
        (string) getenv('CM_WEBHOOK_URL'),
        (string) getenv('CM_WEBHOOK_SECRET'),
        $notificationId,
        (string) getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID')
    );
    dmAssert($second["status"] === 200, 'idempotent replay failed: HTTP ' . $second["status"]);

    $afterReplay = snapshot($pdo, $paymentId, $orderId);
    dmSame($afterFirst['history'], $afterReplay['history'], 'replay duplicated order history');
    dmSame($afterFirst['stock'], $afterReplay['stock'], 'replay duplicated stock movement');
    dmSame($afterFirst['audit'], $afterReplay['audit'], 'replay duplicated audit');

    $repository = (string) file_get_contents(__DIR__ . '/../src/Repositories/PaymentTransactionRepository.php');
    dmAssert(
        str_contains($repository, '1213')
        && str_contains($repository, '1205')
        && str_contains($repository, "'40001'"),
        'repository must classify 1213/1205/40001'
    );
    dmAssert(
        !preg_match('/\b(?:retry|retries|usleep|sleep)\s*\(/i', $repository),
        'repository contains blind retry/backoff'
    );

    echo "PASS: deterministic MySQL 8/InnoDB 1205 + 1213 + rollback + webhook containment + idempotent redelivery\n";
    echo 'MYSQL_VERSION: ' . $version . PHP_EOL;
    echo 'HTTP_LOCK_CONTENTION_STATUS: ' . $http["status"] . PHP_EOL;
    echo 'REDELIVERY_STATUS: ' . $second["status"] . PHP_EOL;
    echo "PASS: webhook_mysql_deadlock_test\n";
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}
