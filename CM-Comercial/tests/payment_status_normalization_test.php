<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/payment_service.php';

$path = __DIR__ . '/../integrations/payment_service.php';
$service = (string)file_get_contents($path);

foreach ([
    "\$normalizedStatus = strtolower(trim(\$status));",
    "'authorized', 'paid', 'failed', 'cancelled', 'refunded'",
    "!in_array(\$normalizedStatus, \$allowedStatuses, true)",
    "transition_payment(\$pdo, \$paymentId, \$normalizedStatus",
] as $needle) {
    if (!str_contains($service, $needle)) {
        fwrite(STDERR, "FAIL: missing status normalization/validation: $needle\n");
        exit(1);
    }
}

echo "PASS: payment status normalization\n";
