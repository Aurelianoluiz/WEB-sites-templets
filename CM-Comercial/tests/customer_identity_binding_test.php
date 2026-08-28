<?php
declare(strict_types=1);

/** Regression test for customer financial history identity binding. */
$source = (string)file_get_contents(__DIR__ . '/../customer_financial_history.php');

$checks = [
    'uses_authenticated_user_session' => str_contains($source, "$_SESSION['user']['id']"),
    'does_not_accept_request_customer_id' => !str_contains($source, "$_GET['customer_id']") && !str_contains($source, "$_POST['customer_id']"),
    'rejects_missing_identity' => str_contains($source, 'http_response_code(401)'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
