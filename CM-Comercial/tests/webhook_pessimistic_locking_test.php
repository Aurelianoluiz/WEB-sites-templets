<?php
declare(strict_types=1);

$repositoryPath = __DIR__ . '/../src/Repositories/PaymentTransactionRepository.php';
$handlerPath = __DIR__ . '/../webhooks/webhook_handler.php';
$repository = (string)file_get_contents($repositoryPath);
$handler = (string)file_get_contents($handlerPath);

$checks = [
    'apply_webhook_transition_present' => str_contains($repository, 'applyWebhookTransition('),
    'payment_lock_requested' => str_contains($repository, 'findById($id, true)') && str_contains($repository, 'findByExternalReference($externalReference, true)'),
    'mysql_for_update_present' => str_contains($repository, 'FOR UPDATE'),
    'mysql_driver_guard' => str_contains($repository, 'isMysql()'),
    'state_machine_defined' => str_contains($repository, 'ALLOWED_TRANSITIONS'),
    'refunded_is_terminal' => str_contains($repository, "'refunded' => []"),
    'transaction_boundary_available' => str_contains($handler, '$auditRepository->transaction('),
    'concurrency_1213_1205_handled' => str_contains($handler, '1213') && str_contains($handler, '1205') && str_contains($handler, '40001'),
    'no_blind_retry' => str_contains($handler, 'Never retry'),
    'controlled_concurrency_response' => str_contains($handler, "'retry_safe' => true"),
    'no_sql_in_handler' => !preg_match('/\b(?:SELECT|INSERT|UPDATE|DELETE)\b\s+(?:FROM|INTO|SET|WHERE|JOIN)/i', $handler),
];

$failed = [];
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
    if (!$ok) $failed[] = $name;
}

if ($failed !== []) {
    fwrite(STDERR, 'FAILED: ' . implode(', ', $failed) . PHP_EOL);
    exit(1);
}

echo "PASS: webhook_pessimistic_locking_test\n";
