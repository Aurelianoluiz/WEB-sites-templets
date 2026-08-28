<?php
declare(strict_types=1);

$read = static fn(string $path): string => is_file($path) ? (string)file_get_contents($path) : '';
$financial = $read(__DIR__ . '/../financial_history.php');
$customer = $read(__DIR__ . '/../customer_financial_history.php');
$csrf = $read(__DIR__ . '/../includes/csrf.php');
$checkout = $read(__DIR__ . '/../includes/checkout_payment.php');
$config = $read(__DIR__ . '/../config.php');
$logout = $read(__DIR__ . '/../logout.php');
$access = $read(__DIR__ . '/access_control_test.php');
$session = $read(__DIR__ . '/authentication_security_test.php');

$checks = [
    'php_strict_types' => str_contains($financial, 'declare(strict_types=1);'),
    'customer_identity_from_authenticated_session' => str_contains($customer, "$_SESSION['user']['id']"),
    'customer_does_not_use_request_identity' => !str_contains($customer, "$_GET['customer_id']") && !str_contains($customer, "$_POST['customer_id']"),
    'prepared_statement' => str_contains($financial, '$pdo->prepare('),
    'bounded_pagination' => str_contains($financial, 'min(100'),
    'csrf_shared_helper' => str_contains($config, "require_once __DIR__ . '/includes/csrf.php';"),
    'csrf_verify_alias' => str_contains($csrf, 'function verify_csrf(): void'),
    'checkout_no_raw_gateway_return' => str_contains($checkout, "'gateway_data' => $safeGateway"),
    'webhook_present' => file_exists(__DIR__ . '/../webhooks/webhook_handler.php'),
    'htaccess_present' => file_exists(__DIR__ . '/../.htaccess'),
    'logout_present' => file_exists(__DIR__ . '/../logout.php') && str_contains($logout, 'POST'),
    'access_regression_test_present' => $access !== '',
    'session_regression_test_present' => $session !== '',
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
