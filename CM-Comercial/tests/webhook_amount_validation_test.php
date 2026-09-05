<?php
declare(strict_types=1);

$source=(string)file_get_contents(__DIR__.'/../webhooks/webhook_handler.php');
$repo=(string)file_get_contents(__DIR__.'/../src/Repositories/PaymentTransactionRepository.php');
$checks=[
 'locks_internal_payment_amount'=>str_contains($repo,'SELECT id,order_id,status,provider_payment_id,amount'),
 'reads_gateway_transaction_amount'=>str_contains($source,"gatewayPayment['raw']['transaction_amount']"),
 'rejects_invalid_gateway_amount'=>str_contains($source,'Payment amount mismatch.'),
 'uses_database_transaction'=>str_contains($source,'$auditRepository->transaction('),
 'locks_payment_row'=>str_contains($repo,'FOR UPDATE')&&str_contains($repo,'lockPaymentForWebhook('),
];
$failed=array_keys(array_filter($checks,static fn(bool $ok):bool=>!$ok));foreach($checks as $name=>$ok)echo($ok?'PASS':'FAIL').': '.$name.PHP_EOL;exit($failed?1:0);
