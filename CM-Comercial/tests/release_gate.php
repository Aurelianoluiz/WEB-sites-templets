<?php
declare(strict_types=1);

/**
 * Release gate aggregator. It verifies that the full validation asset set
 * exists; environment-dependent checks remain pending until run against
 * a configured deployment.
 */
$required = [
    __DIR__ . '/payment_core_test.php',
    __DIR__ . '/payment_service_test.php',
    __DIR__ . '/payment_operations_test.php',
    __DIR__ . '/payment_order_policy_test.php',
    __DIR__ . '/refund_stock_policy_test.php',
    __DIR__ . '/customer_financial_history_test.php',
    __DIR__ . '/customer_identity_binding_test.php',
    __DIR__ . '/csrf_test.php',
    __DIR__ . '/authentication_security_test.php',
    __DIR__ . '/access_control_test.php',
    __DIR__ . '/logout_security_test.php',
    __DIR__ . '/password_auth_audit.php',
    __DIR__ . '/auth_surface_audit.php',
    __DIR__ . '/security_audit.php',
    __DIR__ . '/integration_suite.php',
    __DIR__ . '/payment_consistency_test.php',
    __DIR__ . '/stock_payment_idempotency_test.php',
    __DIR__ . '/stock_payment_bridge_test.php',
];

$missing = array_values(array_filter($required, static fn(string $path): bool => !is_file($path)));

foreach ($required as $path) {
    $label = basename($path);
    echo (is_file($path) ? 'READY' : 'MISSING') . ": $label\n";
}

echo "ENVIRONMENT_GATES: pending (database, HTTPS, gateway sandbox, webhook, E2E browser)\n";

if ($missing !== []) {
    fwrite(STDERR, 'FAIL: missing validation assets: ' . implode(', ', array_map('basename', $missing)) . "\n");
    exit(1);
}

echo "RELEASE_GATE_ASSETS_READY\n";
