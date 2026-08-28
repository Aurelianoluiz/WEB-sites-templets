<?php
declare(strict_types=1);

/**
 * Static production-readiness audit. It does not claim that external
 * infrastructure is configured; it checks that required project artifacts
 * and safeguards are present in the repository.
 */
$root = dirname(__DIR__);
$checks = [
    'env-example' => is_file($root . '/.env.example'),
    'gitignore' => is_file($root . '/.gitignore'),
    'deploy-hardening' => is_file($root . '/DEPLOY-HARDENING.md'),
    'csrf-helper' => is_file($root . '/includes/csrf.php'),
    'session-security' => is_file($root . '/config.php'),
    'webhook-handler' => is_file($root . '/webhooks/webhook_handler.php'),
    'payment-core' => is_file($root . '/includes/payment_core.php'),
    'stock-idempotency' => is_file($root . '/includes/stock_payment.php'),
    'release-gate' => is_file(__DIR__ . '/release_gate.php'),
    'validation-runner' => is_file(__DIR__ . '/validation_runner.php'),
    'e2e-spec' => is_file(__DIR__ . '/e2e_flow_spec.php'),
];

$failed = [];
foreach ($checks as $name => $ok) {
    echo ($ok ? 'READY' : 'MISSING') . ": $name\n";
    if (!$ok) $failed[] = $name;
}

if ($failed !== []) {
    fwrite(STDERR, 'FAIL: missing production-readiness artifacts: ' . implode(', ', $failed) . "\n");
    exit(1);
}

echo "STATIC_PRODUCTION_READINESS_OK\n";
echo "EXTERNAL_VALIDATION_REQUIRED: infrastructure/gateway/E2E/backup\n";
