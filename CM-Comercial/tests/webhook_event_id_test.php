<?php
declare(strict_types=1);

/** Static regression check for stable enterprise webhook event identity. */
$source = (string)file_get_contents(__DIR__ . '/../webhooks/webhook_handler.php');
$checks = [
    'uses_provider_event_fields' => str_contains($source, "['mp', $eventType, $action, $dataId, $gatewayPayment['status'], $gatewayPayment['transaction_id']]")
        || str_contains($source, "['mp', $eventType, $action, $dataId, $gatewayPayment['status']"),
    'hashes_fingerprint' => str_contains($source, "hash('sha256', implode('|', ['mp'"),
    'does_not_use_request_id_for_business_identity' => !str_contains($source, '$requestId') && !str_contains($source, 'x-request-id'),
    'does_not_use_transport_timestamp' => !str_contains($source, '$timestampPart'),
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
exit($failed ? 1 : 0);
