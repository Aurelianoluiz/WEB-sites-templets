<?php
declare(strict_types=1);

/**
 * Static regression for Mercado Pago lifecycle-event identity.
 * Lifecycle identity must include provider type, action, data id, status and
 * transaction id; transport request headers must not define event identity.
 */
$adapter = (string)file_get_contents(__DIR__ . '/../integrations/mercadopago_adapter.php');

$checks = [
    'action_is_present' => str_contains($adapter, '$action = (string)($data[\'action\'] ?? \'\');'),
    'status_is_normalized' => str_contains($adapter, '$status = $this->normalizeStatus'),
    'event_fingerprint_exists' => str_contains($adapter, '$eventFingerprint = implode'),
    'request_id_not_used' => !str_contains($adapter, 'x-request-id'),
];

$start = strpos($adapter, '$eventFingerprint');
$end = strpos($adapter, '$eventId', $start === false ? 0 : $start);
if ($start === false || $end === false) {
    fwrite(STDERR, 'FAIL: lifecycle fingerprint block not found' . PHP_EOL);
    exit(1);
}

$fingerprint = substr($adapter, $start, $end - $start);
foreach (['$type', '$action', '$dataId', '$status', '$transactionId'] as $token) {
    $checks['fingerprint_' . ltrim($token, '$')] = str_contains($fingerprint, $token);
}

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name" . PHP_EOL;
exit($failed ? 1 : 0);
