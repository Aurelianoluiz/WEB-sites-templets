<?php
declare(strict_types=1);

$root = __DIR__;
$gate = (string)file_get_contents($root . '/release_gate.php');
$runner = (string)file_get_contents($root . '/validation_runner.php');

$expected = [
    'payment_core_test.php', 'payment_service_test.php', 'payment_operations_test.php',
    'payment_order_policy_test.php', 'refund_stock_policy_test.php', 'payment_immutability_test.php',
    'payment_event_ownership_test.php', 'payment_event_duplicate_test.php', 'webhook_amount_validation_test.php',
    'webhook_event_id_test.php', 'webhook_lifecycle_event_test.php', 'webhook_signature_freshness_test.php',
    'webhook_signature_freshness_runtime_test.php', 'payment_event_type_validation_test.php',
    'payment_event_record_validation_test.php', 'payment_transaction_identity_test.php',
    'payment_order_atomicity_test.php', 'customer_financial_history_test.php', 'customer_identity_binding_test.php',
    'csrf_test.php', 'authentication_security_test.php', 'access_control_test.php', 'logout_security_test.php',
    'password_auth_audit.php', 'auth_surface_audit.php', 'security_audit.php', 'integration_suite.php',
    'payment_consistency_test.php', 'stock_payment_idempotency_test.php', 'stock_payment_bridge_test.php',
    'stock_reconciliation_test.php', 'stock_payment_policy_validation_test.php',
    'payment_status_normalization_test.php', 'payment_status_normalization_runtime_test.php',
    'mercadopago_status_validation_test.php', 'mercadopago_payment_input_validation_test.php',
    'checkout_idempotency_scope_test.php', 'configuration_surface_test.php',
    'order_service_test.php', 'admin_order_transition_test.php',
];

$failed = [];
foreach ($expected as $file) {
    if (!is_file($root . '/' . $file)) {
        $failed[] = 'missing:' . $file;
    }
    if (!str_contains($gate, "'/{$file}'")) {
        $failed[] = 'gate:' . $file;
    }
    if (!str_contains($runner, "'{$file}'")) {
        $failed[] = 'runner:' . $file;
    }
}

if (!str_contains($gate, 'ENVIRONMENT_GATES')) {
    $failed[] = 'gate:environment-marker';
}

if ($failed !== []) {
    fwrite(STDERR, 'FAIL: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo 'PASS: release consistency' . PHP_EOL;
