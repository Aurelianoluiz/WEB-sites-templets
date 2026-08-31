<?php
declare(strict_types=1);

/** Static regression check for stable enterprise webhook event identity. */
$source = (string)file_get_contents(__DIR__ . '/../webhooks/webhook_handler.php');

$checks = [
    'uses_provider_event_fields' => preg_match('/\[\s*\'mp\',\s*\$eventType,\s*\$action,\s*\$dataId,\s*\$gatewayPayment\[\'status\'\],\s*\$gatewayPayment\[\'transaction_id\'\]\s*\]/', $source) === 1,
    'hashes_stable_identity' => str_contains($source, "hash('sha256', implode('|', ['mp'"),
    'request_id_not_in_business_identity' => !str_contains($source, 'requestId') && !str_contains($source, 'x-request-id'),
    'timestamp_not_in_business_identity' => !str_contains($source, 'timestampPart'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
exit($failed ? 1 : 0);
