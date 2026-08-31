<?php
declare(strict_types=1);

/** Static regression checks for signed webhook amount validation. */
$source = (string)file_get_contents(__DIR__ . '/../webhooks/webhook_handler.php');
$checks = [
    'locks_internal_payment_amount' => str_contains($source, 'SELECT id,order_id,status,amount,webhook_event_id FROM payment_transactions'),
    'reads_gateway_transaction_amount' => str_contains($source, "gatewayPayment['raw']['transaction_amount']"),
    'rejects_invalid_gateway_amount' => str_contains($source, 'Payment amount mismatch.'),
    'uses_database_transaction' => str_contains($source, '$database->transaction('),
    'locks_payment_row' => str_contains($source, 'FOR UPDATE'),
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
exit($failed ? 1 : 0);
