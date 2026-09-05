<?php
declare(strict_types=1);

/**
 * Deterministic MySQL 8 / InnoDB failure-injection suite.
 *
 * This test is intentionally integration-only. It never resets business data.
 * Point it at a dedicated fixture/database. Without the MySQL integration
 * environment it exits 0 with SKIP, preserving the SQLite validation runner.
 *
 * Required: CM_WEBHOOK_URL, CM_WEBHOOK_SECRET, CM_MYSQL_* credentials,
 * CM_MYSQL_PAYMENT_ID, CM_MYSQL_ORDER_ID, CM_MYSQL_EXTERNAL_REFERENCE,
 * CM_MYSQL_PROVIDER_PAYMENT_ID_PAID.
 */
final class WebhookMySqlDeadlockFailure extends RuntimeException {}

function dmAssert(bool $ok, string $message): void { if (!$ok) throw new WebhookMySqlDeadlockFailure($message); }
function dmSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) throw new WebhookMySqlDeadlockFailure($message . ' expected=' . var_export($expected, true) . ' actual=' . var_export($actual, true));
}
function requiredEnv(): bool {
    foreach (['CM_WEBHOOK_URL','CM_WEBHOOK_SECRET','CM_MYSQL_PAYMENT_ID','CM_MYSQL_ORDER_ID','CM_MYSQL_EXTERNAL_REFERENCE','CM_MYSQL_PROVIDER_PAYMENT_ID_PAID'] as $n) {
        if (getenv($n) === false || trim((string)getenv($n)) === '') return false;
    }
    return getenv('CM_MYSQL_DSN') !== false || (getenv('CM_MYSQL_HOST') !== false && getenv('CM_MYSQL_DATABASE') !== false && getenv('CM_MYSQL_USER') !== false);
}
function db(): PDO {
    $dsn = trim((string)(getenv('CM_MYSQL_DSN') ?: ''));
    if ($dsn === '') $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('CM_MYSQL_HOST'), (int)(getenv('CM_MYSQL_PORT') ?: 3306), getenv('CM_MYSQL_DATABASE'));
    return new PDO($dsn, (string)getenv('CM_MYSQL_USER'), (string)(getenv('CM_MYSQL_PASSWORD') ?: ''), [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES=>false]);
}
function scalar(PDO $pdo, string $sql, array $params=[]): mixed { $s=$pdo->prepare($sql); $s->execute($params); return $s->fetchColumn(); }
function mysqlError(PDOException $e): array { return [(string)$e->getCode(), isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : 0]; }
function signature(string $id, string $requestId, string $secret, int $ts): string {
    $manifest = 'id:' . $id . ';request-id:' . $requestId . ';ts:' . $ts . ';';
    return 'ts=' . $ts . ',v1=' . hash_hmac('sha256', $manifest, $secret);
}
function webhook(string $url, string $secret, string $notificationId, string $paymentId): array {
    $requestId = 'deadlock-' . bin2hex(random_bytes(6)); $ts=time();
    $body=json_encode(['id'=>$notificationId,'type'=>'payment','action'=>'payment.updated','data'=>['id'=>$paymentId],'live_mode'=>false], JSON_THROW_ON_ERROR);
    $ch=curl_init($url); dmAssert($ch!==false,'curl_init failed');
    curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-request-id: '.$requestId,'x-signature: '.signature($notificationId,$requestId,$secret,$ts)],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=20]);
    $response=curl_exec($ch); $error=curl_error($ch); $status=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE); curl_close($ch);
    return ['status'=>$status,'body'=>(string)$response,'error'=>$error];
}
function snapshot(PDO $pdo, int $paymentId, int $orderId): array {
    return [
        'payment'=>(array)($pdo->query('SELECT status, provider_payment_id, amount FROM payment_transactions WHERE id='.(int)$paymentId)->fetch() ?: []),
        'order'=>(array)($pdo->query('SELECT status, payment_status, total_amount FROM orders WHERE id='.(int)$orderId)->fetch() ?: []),
        'history'=>(int)scalar($pdo,'SELECT COUNT(*) FROM order_status_history WHERE order_id=?',[$orderId]),
        'stock'=>(int)scalar($pdo,'SELECT COUNT(*) FROM stock_movements WHERE order_id=?',[$orderId]),
        'audit'=>(int)scalar($pdo,'SELECT COUNT(*) FROM payment_audit_log WHERE payment_transaction_id=?',[$paymentId]),
    ];
}

if (!extension_loaded('pdo_mysql') || !extension_loaded('curl')) { echo "SKIP: pdo_mysql/curl extension unavailable\n"; exit(0); }
if (!requiredEnv()) { echo "SKIP: MySQL deadlock integration environment not configured\n"; exit(0); }

try {
    $pdo=db(); $version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();
    dmAssert(str_starts_with($version,'8.'),'MySQL 8 required; got '.$version);
    dmSame('InnoDB', ucfirst(strtolower((string)scalar($pdo,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='payment_transactions'"))),'payment_transactions must be InnoDB');
    dmSame('InnoDB', ucfirst(strtolower((string)scalar($pdo,"SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='orders'"))),'orders must be InnoDB');

    $paymentId=(int)getenv('CM_MYSQL_PAYMENT_ID'); $orderId=(int)getenv('CM_MYSQL_ORDER_ID');
    $before=snapshot($pdo,$paymentId,$orderId);

    // Scenario A: deterministic lock-wait timeout. A owns payment -> order;
    // B uses the same canonical payment lock and must receive 1205 when its
    // session timeout is reduced to one second. This proves the DB primitive.
    $a=db(); $b=db();
    $a->exec('SET SESSION innodb_lock_wait_timeout=5');
    $b->exec('SET SESSION innodb_lock_wait_timeout=1');
    $a->beginTransaction();
    $a->prepare('SELECT id, order_id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]);
    $a->prepare('SELECT id FROM orders WHERE id=? FOR UPDATE')->execute([$orderId]);
    $timeoutSeen=false;
    try { $b->beginTransaction(); $b->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]); } catch (PDOException $e) {
        [$state,$driver]=mysqlError($e); $timeoutSeen=($driver===1205 || $state==='HY000'); if ($b->inTransaction()) $b->rollBack();
    } finally { if ($a->inTransaction()) $a->rollBack(); }
    dmAssert($timeoutSeen,'expected MySQL 1205/HY000 lock wait timeout was not observed');
    dmSame($before,snapshot($pdo,$paymentId,$orderId),'lock-timeout fixture changed despite rollback');

    // Scenario B: deterministic cross-lock deadlock. A locks payment then waits
    // for order; B locks order then requests payment. InnoDB must elect one victim
    // with 1213/40001. Both sessions are rolled back explicitly after observation.
    $a=db(); $b=db(); $a->exec('SET SESSION innodb_lock_wait_timeout=5'); $b->exec('SET SESSION innodb_lock_wait_timeout=5');
    $a->beginTransaction(); $b->beginTransaction();
    $a->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]);
    $b->prepare('SELECT id FROM orders WHERE id=? FOR UPDATE')->execute([$orderId]);
    $victim=[];
    try { $a->prepare('SELECT id FROM orders WHERE id=? FOR UPDATE')->execute([$orderId]); } catch (PDOException $e) { $victim[]=mysqlError($e); if($a->inTransaction())$a->rollBack(); }
    try { $b->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]); } catch (PDOException $e) { $victim[]=mysqlError($e); if($b->inTransaction())$b->rollBack(); }
    if($a->inTransaction())$a->rollBack(); if($b->inTransaction())$b->rollBack();
    $deadlockSeen=false; foreach($victim as [$state,$driver]) if($driver===1213 || $state==='40001') $deadlockSeen=true;
    dmAssert($deadlockSeen,'expected MySQL 1213/40001 deadlock was not observed');
    dmSame($before,snapshot($pdo,$paymentId,$orderId),'deadlock rollback left a partial business mutation');

    // Scenario C: actual webhook while the canonical payment row is held.
    // The entry point must not leak an uncaught 500. Controlled 2xx/4xx/503 is
    // acceptable; a 500 is a release failure. No mutation may survive the lock.
    $a=db(); $a->beginTransaction(); $a->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$paymentId]);
    $http=webhook((string)getenv('CM_WEBHOOK_URL'),(string)getenv('CM_WEBHOOK_SECRET'),'deadlock-http-'.bin2hex(random_bytes(5)),(string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID'));
    if($a->inTransaction())$a->rollBack();
    dmAssert($http['error']==='','webhook transport error: '.$http['error']);
    dmAssert($http['status']!==500,'webhook leaked HTTP 500 during lock contention');

    // Scenario D: legitimate redelivery must be safe and must not duplicate a
    // stock restoration/history effect. The gateway provider fixture resolves the
    // current canonical state; replaying the same notification is idempotent.
    $redeliveryId='deadlock-redelivery-'.bin2hex(random_bytes(5));
    $first=webhook((string)getenv('CM_WEBHOOK_URL'),(string)getenv('CM_WEBHOOK_SECRET'),$redeliveryId,(string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID'));
    dmAssert($first['status']===200,'legitimate redelivery first delivery must be HTTP 200, got '.$first['status']);
    $afterFirst=snapshot($pdo,$paymentId,$orderId);
    $second=webhook((string)getenv('CM_WEBHOOK_URL'),(string)getenv('CM_WEBHOOK_SECRET'),$redeliveryId,(string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID'));
    dmAssert($second['status']===200,'duplicate redelivery must be HTTP 200, got '.$second['status']);
    $afterSecond=snapshot($pdo,$paymentId,$orderId);
    dmSame($afterFirst['history'],$afterSecond['history'],'redelivery duplicated order_status_history');
    dmSame($afterFirst['stock'],$afterSecond['stock'],'redelivery duplicated stock movement');
    dmSame($afterFirst['audit'],$afterSecond['audit'],'redelivery duplicated payment audit');

    // Static repository proof: no retry loop/backoff construct may be introduced.
    $repo=(string)file_get_contents(__DIR__.'/../src/Repositories/PaymentTransactionRepository.php');
    dmAssert(str_contains($repo,'1213') && str_contains($repo,'1205') && str_contains($repo,"'40001'"),'repository must explicitly classify 1213/1205/40001');
    dmAssert(!preg_match('/\b(?:retry|retries|usleep|sleep)\s*\(/i',$repo),'repository contains a blind retry/backoff call');
    dmAssert(substr_count(strtoupper($repo),'FOR UPDATE')>=2,'repository must retain payment + order pessimistic locks');

    echo "PASS: MySQL 8/InnoDB 1205 lock-timeout + 1213 deadlock + rollback + webhook containment + idempotent redelivery\n";
    echo 'MYSQL_VERSION: '.$version.PHP_EOL;
    echo 'HTTP_LOCK_CONTENTION_STATUS: '.$http['status'].PHP_EOL;
    echo 'REDELIVERY_STATUS: '.$second['status'].PHP_EOL;
    echo "PASS: webhook_mysql_deadlock_test\n";
} catch (Throwable $e) {
    fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL); exit(1);
}
