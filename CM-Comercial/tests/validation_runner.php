<?php
declare(strict_types=1);

/**
 * Runs deterministic, environment-independent validation scripts in isolation.
 * Environment-dependent E2E/gateway checks are intentionally excluded.
 */
$tests = [
    'payment_core_test.php',
    'payment_service_test.php',
    'payment_operations_test.php',
    'payment_order_policy_test.php',
    'refund_stock_policy_test.php',
    'payment_immutability_test.php',
    'payment_event_ownership_test.php',
    'payment_event_duplicate_test.php',
    'webhook_amount_validation_test.php',
    'webhook_event_id_test.php',
    'webhook_lifecycle_event_test.php',
    'webhook_signature_freshness_test.php',
    'webhook_signature_freshness_runtime_test.php',
    'payment_event_type_validation_test.php',
    'payment_event_record_validation_test.php',
    'payment_transaction_identity_test.php',
    'payment_order_atomicity_test.php',
    'customer_financial_history_test.php',
    'customer_identity_binding_test.php',
    'csrf_test.php',
    'authentication_security_test.php',
    'access_control_test.php',
    'logout_security_test.php',
    'password_auth_audit.php',
    'auth_surface_audit.php',
    'security_audit.php',
    'integration_suite.php',
    'payment_consistency_test.php',
    'stock_payment_idempotency_test.php',
    'stock_payment_bridge_test.php',
    'stock_reconciliation_test.php',
    'stock_payment_policy_validation_test.php',
    'payment_status_normalization_test.php',
    'payment_status_normalization_runtime_test.php',
    'mercadopago_status_validation_test.php',
    'mercadopago_payment_input_validation_test.php',
    'configuration_surface_test.php',
];

$php = PHP_BINARY;
$failed = [];

foreach ($tests as $test) {
    $path = __DIR__ . '/' . $test;
    if (!is_file($path)) {
        echo "MISSING: $test\n";
        $failed[] = $test;
        continue;
    }

    $command = escapeshellarg($php) . ' ' . escapeshellarg($path) . ' 2>&1';
    $output = [];
    $exitCode = 0;
    exec($command, $output, $exitCode);

    echo "=== $test ===\n";
    echo implode("\n", $output) . "\n";
    echo ($exitCode === 0 ? "RESULT: PASS\n" : "RESULT: FAIL ($exitCode)\n");

    if ($exitCode !== 0) $failed[] = $test;
}

if ($failed !== []) {
    echo "FAILED_TESTS: " . implode(', ', $failed) . "\n";
    exit(1);
}

echo "ALL_DETERMINISTIC_TESTS_PASSED\n";
