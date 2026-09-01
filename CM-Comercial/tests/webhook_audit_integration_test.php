<?php
declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Repositories\PaymentAuditRepository;
use App\Repositories\PaymentTransactionRepository;
use App\Security\WebhookValidator;

final class WebhookAuditIntegrationFailure extends RuntimeException {}
function webhookAssert(bool $condition, string $message): void { if (!$condition) throw new WebhookAuditIntegrationFailure($message); }
function webhookSame(mixed $expected, mixed $actual, string $message): void { if ($expected !== $actual) throw new WebhookAuditIntegrationFailure($message . ' expected=' . var_export($expected,true) . ' actual=' . var_export($actual,true)); }

function fixturePdo(): PDO
{
    $pdo=new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
    $pdo->exec(<<<'SQL'
CREATE TABLE orders (id INTEGER PRIMARY KEY, customer_id INTEGER NULL, status TEXT NOT NULL, payment_status TEXT NOT NULL, total_amount NUMERIC NOT NULL, currency TEXT NOT NULL DEFAULT 'BRL', created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE products (id INTEGER PRIMARY KEY, stock_quantity INTEGER NOT NULL);
CREATE TABLE order_items (id INTEGER PRIMARY KEY AUTOINCREMENT, order_id INTEGER NOT NULL, product_id INTEGER NOT NULL, quantity INTEGER NOT NULL);
CREATE TABLE payment_transactions (id INTEGER PRIMARY KEY, order_id INTEGER NOT NULL, provider TEXT NOT NULL, provider_payment_id TEXT NULL, external_reference TEXT NOT NULL, idempotency_key TEXT NOT NULL, status TEXT NOT NULL, amount NUMERIC NOT NULL, currency TEXT NOT NULL DEFAULT 'BRL', created_at TEXT NOT NULL, updated_at TEXT NOT NULL);
CREATE TABLE payment_audit_log (id INTEGER PRIMARY KEY AUTOINCREMENT, payment_transaction_id INTEGER NOT NULL, event_type TEXT NOT NULL, old_status TEXT NULL, new_status TEXT NULL, actor TEXT NOT NULL DEFAULT 'system', idempotency_key TEXT NULL UNIQUE, payload TEXT NULL, created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP);
SQL);
    $pdo->exec("INSERT INTO orders (id,customer_id,status,payment_status,total_amount,created_at,updated_at) VALUES (10,1,'pending','pending',100.00,'2026-08-31 10:00:00','2026-08-31 10:00:00')");
    $pdo->exec("INSERT INTO products (id,stock_quantity) VALUES (50,4)");
    $pdo->exec("INSERT INTO order_items (id,order_id,product_id,quantity) VALUES (1,10,50,2)");
    $pdo->exec("INSERT INTO payment_transactions (id,order_id,provider,provider_payment_id,external_reference,idempotency_key,status,amount,created_at,updated_at) VALUES (100,10,'mercadopago',NULL,'10','checkout-10','pending',100.00,'2026-08-31 10:01:00','2026-08-31 10:01:00')");
    return $pdo;
}

function signedHeaders(array $payload,string $secret,int $timestamp,string $requestId='fixture-request'): array
{
    $manifestId=(string)($payload['id'] ?? $payload['data']['id']);
    $manifest='id:'.$manifestId.';request-id:'.$requestId.';ts:'.$timestamp.';';
    return ['x-signature'=>'ts='.$timestamp.',v1='.hash_hmac('sha256',$manifest,$secret),'x-request-id'=>$requestId];
}

function processFixtureWebhook(PDO $pdo,array $payload,array $gatewayPayment,array $headers,string $secret): array
{
    $validator=new WebhookValidator($secret,300); webhookAssert($validator->validate(json_encode($payload,JSON_THROW_ON_ERROR),$headers),'fixture signature must validate');
    $audit=new PaymentAuditRepository($pdo); $payments=new PaymentTransactionRepository($pdo);
    $eventId=trim((string)($payload['id']??'')); webhookAssert($eventId!=='','fixture event id must exist');
    $idempotencyKey='mp:webhook:'.$eventId; $externalReference=(string)$gatewayPayment['raw']['external_reference']; $gatewayAmount=round((float)$gatewayPayment['raw']['transaction_amount'],2); $gatewayStatus=(string)$gatewayPayment['status'];
    return $audit->transaction(static function() use($audit,$payments,$idempotencyKey,$externalReference,$gatewayAmount,$gatewayStatus,$gatewayPayment,$payload): array {
        if($audit->isEventProcessed($idempotencyKey)) return ['duplicate'=>true];
        $payment=$payments->findByExternalReference($externalReference,true); webhookAssert($payment!==null,'payment must exist'); webhookSame(round((float)$payment['amount'],2),$gatewayAmount,'gateway amount must match internal amount');
        $transactionId=(int)$payment['id']; $orderId=(int)$payment['order_id']; $oldStatus=(string)$payment['status'];
        $auditId=$audit->logEvent(['payment_transaction_id'=>$transactionId,'event_type'=>'webhook.payment_updated','old_status'=>$oldStatus,'new_status'=>$gatewayStatus,'actor'=>'webhook:mercadopago','idempotency_key'=>$idempotencyKey,'payload'=>['notification_id'=>(string)$payload['id'],'action'=>(string)$payload['action'],'type'=>(string)$payload['type'],'data_id'=>(string)$payload['data']['id'],'transaction_id'=>$transactionId,'order_id'=>$orderId]]);
        $transition=$oldStatus!==$gatewayStatus ? $payments->applyWebhookTransition($transactionId,(string)$gatewayPayment['provider_payment_id'],$gatewayStatus) : ['transaction_id'=>$transactionId,'order_id'=>$orderId,'old_status'=>$oldStatus,'new_status'=>$gatewayStatus];
        return ['duplicate'=>false,'audit_id'=>$auditId,'transition'=>$transition];
    });
}

$secret='fixture-secret';
$payload=['id'=>900001,'type'=>'payment','action'=>'payment.updated','live_mode'=>false,'data'=>['id'=>'777001']];
$gatewayPayment=['provider_payment_id'=>'777001','status'=>'paid','raw'=>['id'=>777001,'external_reference'=>'10','transaction_amount'=>100.00,'status'=>'approved','access_token'=>'MUST_NOT_BE_PERSISTED','point_of_interaction'=>['transaction_data'=>['qr_code'=>'RAW-QR-MUST-NOT-BE-PERSISTED']]]];
$now=time(); $headers=signedHeaders($payload,$secret,$now); $pdo=fixturePdo();
$result=processFixtureWebhook($pdo,$payload,$gatewayPayment,$headers,$secret);
webhookAssert(($result['duplicate']??true)===false,'new event must not be marked duplicate'); webhookSame('paid',(string)$pdo->query('SELECT status FROM payment_transactions WHERE id=100')->fetchColumn(),'payment status must transition to paid'); webhookSame('paid',(string)$pdo->query('SELECT payment_status FROM orders WHERE id=10')->fetchColumn(),'order payment status must transition to paid'); webhookSame(1,(int)$pdo->query('SELECT stock_quantity FROM products WHERE id=50')->fetchColumn(),'stock reservation must remain consumed after payment'); webhookSame(1,(int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(),'one audit record must be created');
$duplicate=processFixtureWebhook($pdo,$payload,$gatewayPayment,$headers,$secret); webhookAssert(($duplicate['duplicate']??false)===true,'replayed event must be idempotent'); webhookSame(1,(int)$pdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(),'replay must not create another audit record'); webhookSame(1,(int)$pdo->query('SELECT stock_quantity FROM products WHERE id=50')->fetchColumn(),'replay must not alter stock');
$invalidHeaders=$headers; $invalidHeaders['x-signature']='ts='.$now.',v1='.str_repeat('0',64); $validator=new WebhookValidator($secret,300); webhookAssert(!$validator->validate(json_encode($payload,JSON_THROW_ON_ERROR),$invalidHeaders),'invalid HMAC must be rejected'); webhookAssert(!$validator->validate(json_encode($payload,JSON_THROW_ON_ERROR),['x-request-id'=>'fixture-request']),'missing signature must be rejected'); webhookAssert(!$validator->validate(json_encode($payload,JSON_THROW_ON_ERROR),signedHeaders($payload,$secret,$now-301)),'expired timestamp must be rejected');
$rollbackPdo=fixturePdo(); $rollbackAudit=new PaymentAuditRepository($rollbackPdo); $rollbackPayments=new PaymentTransactionRepository($rollbackPdo);
try { $rollbackAudit->transaction(static function() use($rollbackAudit,$rollbackPayments): void { $payment=$rollbackPayments->findByExternalReference('10',true); webhookAssert($payment!==null,'rollback fixture payment must exist'); $rollbackAudit->logEvent(['payment_transaction_id'=>100,'event_type'=>'webhook.payment_updated','old_status'=>'pending','new_status'=>'paid','actor'=>'webhook:mercadopago','idempotency_key'=>'mp:webhook:rollback-1','payload'=>['notification_id'=>'rollback-1']]); $rollbackPayments->applyWebhookTransition(100,'777001','paid'); throw new RuntimeException('forced intermediate failure'); }); } catch(RuntimeException $e) { webhookAssert($e->getMessage()==='forced intermediate failure','forced rollback failure must propagate'); }
webhookSame('pending',(string)$rollbackPdo->query('SELECT status FROM payment_transactions WHERE id=100')->fetchColumn(),'payment status must rollback'); webhookSame('pending',(string)$rollbackPdo->query('SELECT payment_status FROM orders WHERE id=10')->fetchColumn(),'order payment status must rollback'); webhookSame(4,(int)$rollbackPdo->query('SELECT stock_quantity FROM products WHERE id=50')->fetchColumn(),'stock must rollback'); webhookSame(0,(int)$rollbackPdo->query('SELECT COUNT(*) FROM payment_audit_log')->fetchColumn(),'audit insert must rollback');
$auditJson=json_encode($pdo->query('SELECT * FROM payment_audit_log')->fetchAll(PDO::FETCH_ASSOC),JSON_THROW_ON_ERROR); webhookAssert(!str_contains($auditJson,'MUST_NOT_BE_PERSISTED'),'access token must never reach audit storage'); webhookAssert(!str_contains($auditJson,'RAW-QR-MUST-NOT-BE-PERSISTED'),'PIX QR code must never reach audit storage'); webhookAssert(!str_contains($auditJson,'gateway_payload'),'raw gateway payload key must never reach audit storage');
$handlerSource=(string)file_get_contents(__DIR__.'/../webhooks/webhook_handler.php'); webhookAssert(!preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b\s+(?:FROM|INTO|SET|WHERE|JOIN)/i',$handlerSource),'webhook handler must contain zero SQL'); webhookAssert(str_contains($handlerSource,'PaymentAuditRepositoryInterface::class'),'handler must resolve audit repository from container'); webhookAssert(str_contains($handlerSource,'PaymentTransactionRepositoryInterface::class'),'handler must resolve transaction repository from container'); webhookAssert(str_contains($handlerSource,'WebhookValidator::class'),'handler must resolve validator from container'); webhookAssert(str_contains($handlerSource,'PaymentService::class'),'handler must resolve PaymentService from container'); webhookAssert(str_contains($handlerSource,'idempotency_key'),'handler must pass persistent idempotency key to audit repository');
$source=(string)file_get_contents(__DIR__.'/../src/Repositories/PaymentAuditRepository.php'); webhookAssert(str_contains($source,'SENSITIVE_KEYS'),'audit repository must define sensitive-key sanitization'); webhookAssert(str_contains($source,'access_token'),'audit sanitizer must remove access tokens'); webhookAssert(str_contains($source,'pix_qr_code'),'audit sanitizer must remove PIX QR data');
echo "PASS: webhook_audit_integration_test\n";
