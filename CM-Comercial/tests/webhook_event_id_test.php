<?php
declare(strict_types=1);

/** Static regression check: provider webhook event identity must be deterministic. */
$source = (string)file_get_contents(__DIR__ . '/../integrations/mercadopago_adapter.php');

$checks = [
    'uses_type_and_data_id' => str_contains($source, "$eventId = 'mp-' . $type . '-' . $dataId;"),
    'does_not_use_request_id' => !str_contains($source, "($headers['x-request-id'] ??"),
    'does_not_use_date_created_fallback' => !str_contains($source, "$data['date_created'] ??"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
