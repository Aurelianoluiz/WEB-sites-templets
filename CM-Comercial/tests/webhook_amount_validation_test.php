<?php
declare(strict_types=1);

/** Static regression checks for signed webhook amount validation. */
$source = (string)file_get_contents(__DIR__ . '/../webhooks/webhook_handler.php');
$checks = [
    'loads_internal_payment_amount' => str_contains($source, "SELECT id, amount FROM payments WHERE order_id=? LIMIT 1"),
    'reads_gateway_transaction_amount' => str_contains($source, "event['raw']['transaction_amount']"),
    'rejects_missing_gateway_amount' => str_contains($source, '$gatewayAmount === null'),
    'rejects_amount_mismatch' => str_contains($source, 'Valor do pagamento divergente da cobrança interna.'),
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
