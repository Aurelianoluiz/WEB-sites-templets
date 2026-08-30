<?php
declare(strict_types=1);

/** Static regression check: provider webhook event identity must be deterministic. */
$source = (string)file_get_contents(__DIR__ . '/../integrations/mercadopago_adapter.php');

$checks = [
    'uses_type_action_data_id' => str_contains($source, "'mp',\n            $type,\n            $action,\n            $dataId,"),
    'uses_status_and_transaction' => str_contains($source, "$status,\n            $transactionId,"),
    'hashes_fingerprint' => str_contains($source, '$eventId = hash(\'sha256\', $eventFingerprint);'),
    'does_not_use_request_id' => !str_contains($source, 'x-request-id'),
    'does_not_use_date_created_fallback' => !str_contains($source, 'date_created'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
