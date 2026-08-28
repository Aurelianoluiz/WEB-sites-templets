<?php
declare(strict_types=1);

/**
 * Static regression for Mercado Pago lifecycle-event identity.
 * A production/update event must distinguish action/status changes even when
 * data.id remains the same, while transport headers must not be required for
 * the event identity.
 */
$adapter = (string)file_get_contents(__DIR__ . '/../integrations/mercadopago_adapter.php');

$checks = [
    "'action'" => 'action field is considered',
    "'status'" => 'normalized status is considered',
    'eventFingerprint' => 'stable lifecycle fingerprint exists',
    "'x-request-id'" => 'transport request id appears only outside event fingerprint',
];

foreach ($checks as $needle => $label) {
    if (!str_contains($adapter, $needle)) {
        fwrite(STDERR, "FAIL: missing $label\n");
        exit(1);
    }
}

$start = strpos($adapter, '$eventFingerprint');
$end = strpos($adapter, '$eventId', $start === false ? 0 : $start);
if ($start === false || $end === false) {
    fwrite(STDERR, "FAIL: lifecycle fingerprint block not found\n");
    exit(1);
}

$fingerprint = substr($adapter, $start, $end - $start);
if (str_contains($fingerprint, 'x-request-id')) {
    fwrite(STDERR, "FAIL: transport request id is part of lifecycle identity\n");
    exit(1);
}

foreach (['$type', '$action', '$dataId', '$status', '$transactionId'] as $token) {
    if (!str_contains($fingerprint, $token)) {
        fwrite(STDERR, "FAIL: lifecycle identity missing $token\n");
        exit(1);
    }
}

echo "PASS: webhook lifecycle event identity\n";
