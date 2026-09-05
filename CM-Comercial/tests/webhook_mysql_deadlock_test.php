<?php
declare(strict_types=1);

/** MySQL 8/InnoDB deterministic 1205/1213 failure-injection suite. */
final class WebhookMySqlDeadlockFailure extends RuntimeException {}
function dmAssert(bool $ok, string $message): void { if (!$ok) throw new WebhookMySqlDeadlockFailure($message); }
function dmSame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) throw new WebhookMySqlDeadlockFailure($message); }
function envOk(): bool {
    foreach (['CM_WEBHOOK_URL','CM_WEBHOOK_SECRET','CM_MYSQL_PAYMENT_ID','CM_MYSQL_ORDER_ID','CM_MYSQL_PROVIDER_PAYMENT_ID_PAID'] as $n) if (getenv($n) === false || trim((string)getenv($n)) === '') return false;
    return getenv('CM_MYSQL_DSN') !== false || (getenv('CM_MYSQL_HOST') !== false && getenv('CM_MYSQL_DATABASE') !== false && getenv('CM_MYSQL_USER') !== false);
}
function db(): PDO {
    $dsn=trim((string)(getenv('CM_MYSQL_DSN')?:''));
    if($dsn==='') $dsn=sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',getenv('CM_MYSQL_HOST'),(int)(getenv('CM_MYSQL_PORT')?:3306),getenv('CM_MYSQL_DATABASE'));
    return new PDO($dsn,(string)getenv('CM_MYSQL_USER'),(string)(getenv('CM_MYSQL_PASSWORD')?:''),[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
}
function scalar(PDO $p,string $sql,array $params=[]):mixed{$s=$p->prepare($sql);$s->execute($params);return $s->fetchColumn();}
function err(PDOException $e):array{return[(string)$e->getCode(),isset($e->errorInfo[1])?(int)$e->errorInfo[1]:0];}
function snapshot(PDO $p,int $pid,int $oid):array{return[
 'payment'=>(array)($p->prepare('SELECT status,provider_payment_id,amount FROM payment_transactions WHERE id=?')->execute([$pid])?:[]),
 'order'=>(array)scalar($p,'SELECT status,payment_status,total_amount FROM orders WHERE id=?',[$oid]),
 'history'=>(int)scalar($p,'SELECT COUNT(*) FROM order_status_history WHERE order_id=?',[$oid]),
 'stock'=>(int)scalar($p,'SELECT COUNT(*) FROM stock_movements WHERE order_id=?',[$oid]),
 'audit'=>(int)scalar($p,'SELECT COUNT(*) FROM payment_audit_log WHERE payment_transaction_id=?',[$pid]),
];}
function webhook(string $url,string $secret,string $nid,string $paymentId):array{
 $rid='deadlock-'.bin2hex(random_bytes(6));$ts=time();$body=json_encode(['id'=>$nid,'type'=>'payment','action'=>'payment.updated','data'=>['id'=>$paymentId],'live_mode'=>false],JSON_THROW_ON_ERROR);
 $manifest='id:'.$nid.';request-id:'.$rid.';ts:'.$ts.';';$ch=curl_init($url);dmAssert($ch!==false,'curl_init failed');curl_setopt_array($ch,[CURLOPT_RETURNTRANSFER=>true,CURLOPT_POST=>true,CURLOPT_POSTFIELDS=>$body,CURLOPT_HTTPHEADER=>['Content-Type: application/json','x-request-id: '.$rid,'x-signature: ts='.$ts.',v1='.hash_hmac('sha256',$manifest,$secret)],CURLOPT_CONNECTTIMEOUT=>5,CURLOPT_TIMEOUT=>20]);$out=curl_exec($ch);$r=['status'=>(int)curl_getinfo($ch,CURLINFO_HTTP_CODE),'body'=>(string)$out,'error'=>curl_error($ch)];curl_close($ch);return $r;
}
function waitFile(string $file,int $seconds=10):void{$end=microtime(true)+$seconds;while(!is_file($file)&&microtime(true)<$end)usleep(10000);dmAssert(is_file($file),'worker synchronization timeout');}

if(!extension_loaded('pdo_mysql')||!extension_loaded('curl')||!function_exists('pcntl_fork')){echo "SKIP: pdo_mysql, curl and pcntl_fork are required for deterministic integration\n";exit(0);}
if(!envOk()){echo "SKIP: MySQL deadlock integration environment not configured\n";exit(0);}
try{
 $pdo=db();$version=(string)$pdo->query('SELECT VERSION()')->fetchColumn();dmAssert(str_starts_with($version,'8.'),'MySQL 8 required');$pid=(int)getenv('CM_MYSQL_PAYMENT_ID');$oid=(int)getenv('CM_MYSQL_ORDER_ID');$before=snapshot($pdo,$pid,$oid);

 // 1205: A holds payment; B is a separate process with one-second wait timeout.
 $a=db();$a->exec('SET SESSION innodb_lock_wait_timeout=5');$bFile=tempnam(sys_get_temp_dir(),'cm1205-');$a->beginTransaction();$a->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$pid]);
 $child=pcntl_fork();dmAssert($child!==-1,'pcntl_fork failed');
 if($child===0){$ok=false;try{$b=db();$b->exec('SET SESSION innodb_lock_wait_timeout=1');$b->beginTransaction();$b->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$pid]);}catch(PDOException $e){[, $code]=err($e);$ok=($code===1205||$e->getCode()==='HY000');}finally{if(isset($b)&&$b->inTransaction())$b->rollBack();file_put_contents($bFile,$ok?'PASS':'FAIL');exit($ok?0:1);}}
 sleep(2);if($a->inTransaction())$a->rollBack();$status=0;pcntl_waitpid($child,$status);dmAssert(pcntl_wexitstatus($status)===0,'1205 worker did not observe ER_LOCK_WAIT_TIMEOUT');dmSame($before,snapshot($pdo,$pid,$oid),'1205 scenario mutated fixture');@unlink($bFile);

 // 1213: A locks payment, B locks order; both then request the opposite lock concurrently.
 $a=db();$a->exec('SET SESSION innodb_lock_wait_timeout=5');$a->beginTransaction();$a->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$pid]);$ready=tempnam(sys_get_temp_dir(),'cm1213-ready-');$result=tempnam(sys_get_temp_dir(),'cm1213-result-');
 $child=pcntl_fork();dmAssert($child!==-1,'pcntl_fork failed');
 if($child===0){$ok=false;try{$b=db();$b->exec('SET SESSION innodb_lock_wait_timeout=5');$b->beginTransaction();$b->prepare('SELECT id FROM orders WHERE id=? FOR UPDATE')->execute([$oid]);file_put_contents($ready,'READY');$b->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$pid]);}catch(PDOException $e){[$state,$code]=err($e);$ok=($code===1213||$state==='40001');}finally{if(isset($b)&&$b->inTransaction())$b->rollBack();file_put_contents($result,$ok?'PASS':'FAIL');exit($ok?0:1);}}
 waitFile($ready);$deadlockSeen=false;try{$a->prepare('SELECT id FROM orders WHERE id=? FOR UPDATE')->execute([$oid]);}catch(PDOException $e){[$state,$code]=err($e);$deadlockSeen=($code===1213||$state==='40001');}finally{if($a->inTransaction())$a->rollBack();}dmAssert($deadlockSeen,'1213/40001 was not observed by either concurrent transaction');$status=0;pcntl_waitpid($child,$status);dmAssert(pcntl_wexitstatus($status)===0&&trim((string)@file_get_contents($result))==='PASS','deadlock worker did not observe 1213/40001');dmSame($before,snapshot($pdo,$pid,$oid),'1213 rollback left a partial business mutation');@unlink($ready);@unlink($result);

 // Entry point under contention: no uncaught 500 is allowed.
 $a=db();$a->beginTransaction();$a->prepare('SELECT id FROM payment_transactions WHERE id=? FOR UPDATE')->execute([$pid]);$http=webhook((string)getenv('CM_WEBHOOK_URL'),(string)getenv('CM_WEBHOOK_SECRET),'deadlock-http-'.bin2hex(random_bytes(5)),(string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID'));$a->rollBack();dmAssert($http['error']==='','webhook transport error: '.$http['error']);dmAssert($http['status']!==500,'webhook returned uncaught HTTP 500 during lock contention');

 // Legitimate delivery followed by exact replay: no duplicated history/stock/audit effects.
 $nid='deadlock-redelivery-'.bin2hex(random_bytes(5));$first=webhook((string)getenv('CM_WEBHOOK_URL'),(string)getenv('CM_WEBHOOK_SECRET'),$nid,(string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID));dmAssert($first['status']===200,'legitimate delivery failed');$one=snapshot($pdo,$pid,$oid);$second=webhook((string)getenv('CM_WEBHOOK_URL'),(string)getenv('CM_WEBHOOK_SECRET'),$nid,(string)getenv('CM_MYSQL_PROVIDER_PAYMENT_ID_PAID));dmAssert($second['status']===200,'idempotent replay failed');$two=snapshot($pdo,$pid,$oid);dmSame($one['history'],$two['history'],'replay duplicated order history');dmSame($one['stock'],$two['stock'],'replay duplicated stock movement');dmSame($one['audit'],$two['audit'],'replay duplicated audit');

 $repo=(string)file_get_contents(__DIR__.'/../src/Repositories/PaymentTransactionRepository.php');dmAssert(str_contains($repo,'1213')&&str_contains($repo,'1205')&&str_contains($repo,"'40001'"),'repository must classify 1213/1205/40001');dmAssert(!preg_match('/\\b(?:retry|retries|usleep|sleep)\\s*\\(/i',$repo),'repository contains blind retry/backoff');
 echo "PASS: deterministic MySQL 8/InnoDB 1205 + 1213 + rollback + webhook containment + idempotent redelivery\nMYSQL_VERSION: {$version}\nHTTP_LOCK_CONTENTION_STATUS: {$http['status']}\nREDELIVERY_STATUS: {$second['status']}\nPASS: webhook_mysql_deadlock_test\n";
}catch(Throwable $e){fwrite(STDERR,'FAIL: '.$e->getMessage().PHP_EOL);exit(1);}
