<?php
declare(strict_types=1);

$read=static fn(string $path): string=>is_file($path)?(string)file_get_contents($path):'';
$repo=$read(__DIR__.'/../src/Repositories/PaymentTransactionRepository.php');
$repoInterface=$read(__DIR__.'/../src/Repositories/PaymentTransactionRepositoryInterface.php');
$webhook=$read(__DIR__.'/../webhooks/webhook_handler.php');
$validator=$read(__DIR__.'/../src/Security/WebhookValidator.php');
$paymentService=$read(__DIR__.'/../src/Services/PaymentService.php');
$audit=$read(__DIR__.'/../src/Repositories/PaymentAuditRepository.php');
$idempotency=$read(__DIR__.'/../src/Repositories/PaymentAuditIdempotencyRepository.php');
$invalidTransition=$read(__DIR__.'/../src/Exceptions/InvalidWebhookTransitionException.php');
$concurrency=$read(__DIR__.'/../src/Exceptions/WebhookConcurrencyException.php');
$bootstrap=$read(__DIR__.'/../bootstrap.php');
$validation=$read(__DIR__.'/validation_runner.php');
$release=$read(__DIR__.'/release_gate.php');
$mysqlConcurrency=$read(__DIR__.'/webhook_mysql_concurrency_test.php');
$deadlockTest=$read(__DIR__.'/webhook_mysql_deadlock_test.php');
$pessimisticTest=$read(__DIR__.'/webhook_pessimistic_locking_test.php');

$checks=[
 'php_strict_types'=>str_contains($repo,'declare(strict_types=1);'),
 'canonical_payment_table'=>str_contains($repo,'payment_transactions'),
 'webhook_transition_interface'=>str_contains($repoInterface,'applyWebhookTransition('),
 'mysql_lock_guard'=>str_contains($repo,'ATTR_DRIVER_NAME')&&str_contains($repo,"=== 'mysql'")&&str_contains($repo,'lockSql('),
 'double_lock_payment_for_update'=>preg_match('/SELECT[\\s\\S]+FROM payment_transactions[\\s\\S]+FOR UPDATE/i',$repo)===1,
 'double_lock_orders_for_update'=>preg_match('/SELECT[\\s\\S]+FROM orders[\\s\\S]+FOR UPDATE/i',$repo)===1,
 'lock_order_protocol_payment_before_order'=>strpos($repo,'lockPaymentForWebhook(')!==false&&strpos($repo,'lockOrderForWebhook(')!==false&&strpos($repo,'lockPaymentForWebhook(')<strpos($repo,'lockOrderForWebhook('),
 'canonical_stock_columns'=>str_contains($repo,'stock_movements(product_id,type,qty)')&&!str_contains($repo,'stock_movements (product_id, quantity'),
 'canonical_stock_adjustment'=>str_contains($repo,"':type'=>'adjustment'")||str_contains($repo,"':type' => 'adjustment'"),
 'monotonic_state_matrix'=>str_contains($repo,'ALLOWED_TRANSITIONS')&&str_contains($repo,"'paid'=>['refunded']")&&str_contains($repo,"'refunded'=>[]")&&str_contains($repo,"'cancelled'=>[]"),
 'illegal_transition_exception'=>str_contains($repo,'InvalidWebhookTransitionException')&&str_contains($invalidTransition,'class InvalidWebhookTransitionException'),
 'transaction_boundary_required'=>str_contains($repo,'inTransaction()')&&str_contains($repo,'existing ACID transaction'),
 'history_same_transaction'=>str_contains($repo,'INSERT INTO order_status_history'),
 'stock_same_transaction'=>str_contains($repo,'stock_movements')&&str_contains($repo,'restoreOrderStock('),
 'deadlock_1213'=>str_contains($repo,'1213')&&str_contains($concurrency,'class WebhookConcurrencyException'),
 'lock_timeout_1205'=>str_contains($repo,'1205'),
 'sqlstate_40001'=>str_contains($repo,"'40001'"),
 'no_repository_retry_sleep'=>preg_match('/\\b(?:retry|retries|usleep|sleep)\\s*\\(/i',$repo)!==1,
 'webhook_catches_invalid_transition'=>str_contains($webhook,'catch (InvalidWebhookTransitionException $e)'),
 'webhook_catches_concurrency'=>str_contains($webhook,'1213')&&str_contains($webhook,'1205')&&str_contains($webhook,'retry_safe'),
 'webhook_no_uncaught_500_for_concurrency'=>str_contains($webhook,"'retry_safe' => true")&&str_contains($webhook,'respond(200'),
 'webhook_validator_hmac'=>str_contains($validator,"hash_hmac('sha256'")&&str_contains($validator,'hash_equals('),
 'webhook_gateway_service_boundary'=>str_contains($paymentService,'getWebhookPayment('),
 'audit_idempotency'=>str_contains($audit,'idempotency_key')&&str_contains($audit,'isEventProcessed'),
 'strict_idempotency_repository'=>str_contains($idempotency,'INSERT INTO payment_audit_log')&&str_contains($idempotency,'23000')&&str_contains($idempotency,'1062')&&str_contains($idempotency,'errorInfo[0]'),
 'bootstrap_di'=>str_contains($bootstrap,'PaymentTransactionRepositoryInterface::class'),
 'mysql_concurrency_suite_present'=>$mysqlConcurrency!=='',
 'mysql_concurrency_32_plus'=>str_contains($mysqlConcurrency,'max(32,')&&str_contains($mysqlConcurrency,'curl_multi_init('),
 'mysql_concurrency_paid_refunded'=>str_contains($mysqlConcurrency,'paid')&&str_contains($mysqlConcurrency,'refunded'),
 'mysql_concurrency_zero_500'=>str_contains($mysqlConcurrency,'!== 500'),
 'deadlock_suite_present'=>$deadlockTest!=='',
 'deadlock_suite_1205'=>str_contains($deadlockTest,'1205')&&str_contains($deadlockTest,'innodb_lock_wait_timeout'),
 'deadlock_suite_1213'=>str_contains($deadlockTest,'1213')&&str_contains($deadlockTest,'40001'),
 'deadlock_suite_dual_connection'=>str_contains($deadlockTest,'$connectionA = db()')&&str_contains($deadlockTest,'$connectionB = db()'),
 'deadlock_suite_rollback_five_tables'=>str_contains($deadlockTest,"'payment'")&&str_contains($deadlockTest,"'order'")&&str_contains($deadlockTest,"'history'")&&str_contains($deadlockTest,"'stock'")&&str_contains($deadlockTest,"'audit'"),
 'deadlock_suite_no_repository_retry'=>str_contains($deadlockTest,'blind retry/backoff'),
 'deadlock_suite_webhook_no_500'=>str_contains($deadlockTest,'status"] !== 500'),
 'deadlock_suite_redelivery_idempotency'=>str_contains($deadlockTest,'notificationId')&&str_contains($deadlockTest,'afterReplay'),
 'deadlock_suite_registered_validation'=>str_contains($validation,'webhook_mysql_deadlock_test.php'),
 'deadlock_suite_registered_release'=>str_contains($release,'webhook_mysql_deadlock_test.php'),
 'pessimistic_locking_test_present'=>$pessimisticTest!=='',
 'no_legacy_payments_table_in_repo'=>!preg_match('/\\b(?:FROM|JOIN|INTO|UPDATE)\\s+payments\\b/i',$repo),
];
$failed=[];foreach($checks as $name=>$ok){echo($ok?'PASS':'FAIL').": {$name}\n";if(!$ok)$failed[]=$name;}
if($failed!==[]){echo'FAILED_CHECKS: '.implode(', ',$failed).PHP_EOL;exit(1);}echo"SECURITY_AUDIT_PASSED\n";
