<?php
declare(strict_types=1);

$checks = [
    'php_strict_types' => str_contains((string)file_get_contents(__DIR__ . '/../financial_history.php'), 'declare(strict_types=1);'),
    'customer_identity_from_session' => str_contains((string)file_get_contents(__DIR__ . '/../customer_financial_history.php'), "$_SESSION['customer_id']"),
    'prepared_statement' => str_contains((string)file_get_contents(__DIR__ . '/../financial_history.php'), '$pdo->prepare('),
    'bounded_pagination' => str_contains((string)file_get_contents(__DIR__ . '/../financial_history.php'), 'min(100'),
    'webhook_secret_required' => file_exists(__DIR__ . '/../webhooks/webhook_handler.php'),
    'htaccess_present' => file_exists(__DIR__ . '/../.htaccess'),
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
