<?php
declare(strict_types=1);

$read = static fn(string $path): string => is_file($path) ? (string)file_get_contents($path) : '';
$financial = $read(__DIR__ . '/../financial_history.php');
$customer = $read(__DIR__ . '/../customer_financial_history.php');
$reconciliation = $read(__DIR__ . '/../admin/reconciliation.php');
$reconciliationView = $read(__DIR__ . '/../admin/views/reconciliation.php');
$reconciliationService = $read(__DIR__ . '/../src/Services/ReconciliationService.php');
$paymentsController = $read(__DIR__ . '/../admin/payments.php');
$paymentsView = $read(__DIR__ . '/../admin/views/payments.php');
$csrf = $read(__DIR__ . '/../includes/csrf.php');
$checkout = $read(__DIR__ . '/../includes/checkout_payment.php');
$config = $read(__DIR__ . '/../config.php');
$logout = $read(__DIR__ . '/../logout.php');
$repository = $read(__DIR__ . '/../src/Repositories/PaymentTransactionRepository.php');
$service = $read(__DIR__ . '/../src/Services/FinancialService.php');

$literalUserId = '$_SESSION[\'user\'][\'id\']';
$logoutPostGuard = 'if ($_SERVER[\'REQUEST_METHOD\'] === \'POST\')';
$sqlKeywordPattern = '/\b(?:SELECT|INSERT|UPDATE|DELETE)\b\s+(?:FROM|INTO|SET|WHERE|JOIN)/i';
$pdoCallPattern = '/->(?:prepare|query|exec)\s*\(/i';

$checks = [
    'php_strict_types' => str_contains($financial, 'declare(strict_types=1);'),
    'customer_identity_from_authenticated_session' => str_contains($customer, $literalUserId),
    'customer_does_not_use_request_identity' => !str_contains($customer, '$_GET[\'customer_id\']') && !str_contains($customer, '$_POST[\'customer_id\']'),
    'prepared_statement_repository' => (bool)preg_match($pdoCallPattern, $repository),
    'financial_service_no_inline_sql' => !preg_match($sqlKeywordPattern, $service),
    'reconciliation_service_present' => $reconciliationService !== '',
    'reconciliation_service_namespace' => str_contains($reconciliationService, 'namespace App\\Services;'),
    'reconciliation_service_no_sql' => !preg_match($sqlKeywordPattern, $reconciliationService),
    'reconciliation_service_repository_injection' => str_contains($reconciliationService, 'PaymentTransactionRepositoryInterface') && str_contains($reconciliationService, 'OrderRepositoryInterface'),
    'bounded_pagination' => str_contains($service, 'MAX_PAGE_SIZE') && str_contains($repository, 'min(100'),
    'reconciliation_controller_present' => $reconciliation !== '',
    'reconciliation_view_present' => $reconciliationView !== '',
    'reconciliation_admin_guard' => str_contains($reconciliation, 'require_admin();'),
    'reconciliation_resolves_service' => str_contains($reconciliation, '$container->get(ReconciliationService::class)'),
    'reconciliation_no_sql' => !preg_match($sqlKeywordPattern, $reconciliation),
    'reconciliation_no_pdo_calls' => !preg_match($pdoCallPattern, $reconciliation),
    'reconciliation_pagination_limit' => str_contains($reconciliation, 'RECONCILIATION_LIMIT_MAX = 100') && str_contains($reconciliation, 'max(1'),
    'reconciliation_csv_content_type' => str_contains($reconciliation, "header('Content-Type: text/csv; charset=UTF-8');"),
    'reconciliation_csv_disposition' => str_contains($reconciliation, 'Content-Disposition'),
    'reconciliation_csv_injection_guard' => str_contains($reconciliation, "['=', '+', '-', '@']"),
    'reconciliation_csv_allow_list' => str_contains($reconciliation, "'transaction_id'") && str_contains($reconciliation, "'customer'") && str_contains($reconciliation, "'amount'"),
    'reconciliation_no_sensitive_export' => !str_contains($reconciliation, 'payload') && !str_contains($reconciliation, 'access_token') && !str_contains($reconciliation, 'webhook_secret') && !str_contains($reconciliation, 'qr_code_base64'),
    'reconciliation_view_no_sql' => !preg_match($sqlKeywordPattern, $reconciliationView) && !preg_match($pdoCallPattern, $reconciliationView),
    'payments_controller_present' => $paymentsController !== '',
    'payments_view_present' => $paymentsView !== '',
    'payments_admin_guard' => str_contains($paymentsController, 'require_admin();'),
    'payments_resolves_financial_service' => str_contains($paymentsController, '$container->get(FinancialService::class)'),
    'payments_no_sql' => !preg_match($sqlKeywordPattern, $paymentsController),
    'payments_no_pdo_calls' => !preg_match($pdoCallPattern, $paymentsController),
    'payments_pagination_limit' => str_contains($paymentsController, 'PAYMENTS_LIMIT_MAX') && str_contains($paymentsController, 'max(1'),
    'payments_status_filter' => str_contains($paymentsController, "'status' => \$status"),
    'payments_provider_filter' => str_contains($paymentsController, "'provider' => \$provider"),
    'payments_search_filter' => str_contains($paymentsController, "'search' => \$search"),
    'payments_date_filters' => str_contains($paymentsController, "'date_from' => \$dateFrom") && str_contains($paymentsController, "'date_to' => \$dateTo"),
    'payments_customer_order_filters' => str_contains($paymentsController, 'customer_id') && str_contains($paymentsController, 'order_id'),
    'payments_no_sensitive_data' => !str_contains($paymentsController, 'payload') && !str_contains($paymentsController, 'access_token') && !str_contains($paymentsController, 'webhook_secret') && !str_contains($paymentsController, 'gateway_response'),
    'payments_view_no_sensitive_data' => !str_contains($paymentsView, 'payload') && !str_contains($paymentsView, 'access_token') && !str_contains($paymentsView, 'webhook_secret') && !str_contains($paymentsView, 'gateway_response'),
    'payments_view_no_sql' => !preg_match($sqlKeywordPattern, $paymentsView) && !preg_match($pdoCallPattern, $paymentsView),
    'csrf_shared_helper' => str_contains($config, "require_once __DIR__ . '/includes/csrf.php';"),
    'csrf_verify_alias' => str_contains($csrf, 'function verify_csrf(): void'),
    'csrf_constant_time_compare' => str_contains($csrf, 'hash_equals('),
    'checkout_no_raw_gateway_return' => str_contains($checkout, "'gateway_data' => \$safeGateway"),
    'webhook_present' => file_exists(__DIR__ . '/../webhooks/webhook_handler.php'),
    'htaccess_present' => file_exists(__DIR__ . '/../.htaccess'),
    'logout_post_guard' => file_exists(__DIR__ . '/../logout.php') && str_contains($logout, $logoutPostGuard),
    'logout_csrf' => str_contains($logout, 'require_csrf();'),
    'logout_destroy' => str_contains($logout, 'session_destroy();'),
    'strict_session_mode' => str_contains($config, "ini_set('session.use_strict_mode', '1')"),
    'session_id_rotation' => str_contains($config, 'session_regenerate_id(true)'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) {
    echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
}

exit($failed ? 1 : 0);
