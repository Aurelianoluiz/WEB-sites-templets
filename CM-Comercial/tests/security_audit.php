<?php
declare(strict_types=1);

$pdo = '$pdo';
$read = static fn(string $path): string => is_file($path) ? (string)file_get_contents($path) : '';
$financial = $read(__DIR__ . '/../financial_history.php');
$customer = $read(__DIR__ . '/../customer_financial_history.php');
$reconciliation = $read(__DIR__ . '/../admin/reconciliation.php');
$reconciliationView = $read(__DIR__ . '/../admin/views/reconciliation.php');
$reconciliationService = $read(__DIR__ . '/../src/Services/ReconciliationService.php');
$reconciliationInterface = $read(__DIR__ . '/../src/Repositories/ReconciliationRepositoryInterface.php');
$reconciliationRepository = $read(__DIR__ . '/../src/Repositories/ReconciliationRepository.php');
$paymentsController = $read(__DIR__ . '/../admin/payments.php');
$paymentsView = $read(__DIR__ . '/../admin/views/payments.php');
$csrf = $read(__DIR__ . '/../includes/csrf.php');
$checkout = $read(__DIR__ . '/../includes/checkout_payment.php');
$config = $read(__DIR__ . '/../config.php');
$logout = $read(__DIR__ . '/../logout.php');
$paymentRepository = $read(__DIR__ . '/../src/Repositories/PaymentTransactionRepository.php');
$paymentRepositoryInterface = $read(__DIR__ . '/../src/Repositories/PaymentTransactionRepositoryInterface.php');
$paymentAuditRepository = $read(__DIR__ . '/../src/Repositories/PaymentAuditRepository.php');
$paymentAuditStrictRepository = $read(__DIR__ . '/../src/Repositories/PaymentAuditIdempotencyRepository.php');
$paymentAuditInterface = $read(__DIR__ . '/../src/Repositories/PaymentAuditRepositoryInterface.php');
$idempotencyException = $read(__DIR__ . '/../src/Exceptions/IdempotencyConflictException.php');
$invalidTransitionException = $read(__DIR__ . '/../src/Exceptions/InvalidWebhookTransitionException.php');
$concurrencyException = $read(__DIR__ . '/../src/Exceptions/WebhookConcurrencyException.php');
$bootstrap = $read(__DIR__ . '/../bootstrap.php');
$webhook = $read(__DIR__ . '/../webhooks/webhook_handler.php');
$webhookValidator = $read(__DIR__ . '/../src/Security/WebhookValidator.php');
$paymentService = $read(__DIR__ . '/../src/Services/PaymentService.php');
$webhookIntegrationTest = $read(__DIR__ . '/webhook_audit_integration_test.php');
$webhookHttpIntegrationTest = $read(__DIR__ . '/webhook_http_integration_test.php');
$webhookConcurrencyTest = $read(__DIR__ . '/webhook_concurrency_test.php');
$webhookMysqlConcurrencyTest = $read(__DIR__ . '/webhook_mysql_concurrency_test.php');
$paymentAuditTest = $read(__DIR__ . '/payment_audit_repository_test.php');
$idempotencyHardeningTest = $read(__DIR__ . '/payment_audit_idempotency_hardening_test.php');
$reconciliationTest = $read(__DIR__ . '/reconciliation_repository_test.php');
$reconciliationServiceTest = $read(__DIR__ . '/reconciliation_service_test.php');
$validationRunner = $read(__DIR__ . '/validation_runner.php');
$releaseGate = $read(__DIR__ . '/release_gate.php');

$sqlKeywordPattern = '/\b(?:SELECT|INSERT|UPDATE|DELETE)\b\s+(?:FROM|INTO|SET|WHERE|JOIN)/i';
$pdoCallPattern = '/->(?:prepare|query|exec|beginTransaction|commit|rollBack)\s*\(/i';
$legacyPaymentsPattern = '/\b(?:FROM|JOIN|INTO|UPDATE|DELETE\s+FROM)\s+payments\b/i';

$checks = [
    'php_strict_types' => str_contains($financial, 'declare(strict_types=1);'),
    'customer_identity_from_authenticated_session' => str_contains($customer, '$_SESSION[\'user\'][\'id\']'),
    'prepared_statement_payment_repository' => (bool)preg_match('/->prepare\s*\(/', $paymentRepository),
    'financial_service_no_inline_sql' => !preg_match($sqlKeywordPattern, $financial),
    'reconciliation_service_present' => $reconciliationService !== '',
    'reconciliation_interface_present' => $reconciliationInterface !== '',
    'reconciliation_repository_present' => $reconciliationRepository !== '',
    'reconciliation_service_namespace' => str_contains($reconciliationService, 'namespace App\\Services;'),
    'reconciliation_service_no_sql' => !preg_match($sqlKeywordPattern, $reconciliationService),
    'reconciliation_service_no_pdo_calls' => !preg_match($pdoCallPattern, $reconciliationService),
    'reconciliation_service_dedicated_dependency' => str_contains($reconciliationService, 'ReconciliationRepositoryInterface'),
    'reconciliation_service_audit_dependency' => str_contains($reconciliationService, 'PaymentAuditRepositoryInterface'),
    'reconciliation_service_payment_dependency' => str_contains($reconciliationService, 'PaymentTransactionRepositoryInterface'),
    'reconciliation_service_has_resolution' => str_contains($reconciliationService, 'resolveDivergence('),
    'reconciliation_service_has_idempotency' => str_contains($reconciliationService, 'isEventProcessed(') && str_contains($reconciliationService, 'idempotencyKey'),
    'reconciliation_service_has_acid_delegate' => str_contains($reconciliationService, '$this->auditRepository->transaction('),
    'reconciliation_service_has_filters' => str_contains($reconciliationService, 'date_from') && str_contains($reconciliationService, 'customer_id') && str_contains($reconciliationService, 'order_id'),
    'reconciliation_service_has_status_classification' => str_contains($reconciliationService, 'reconciled') && str_contains($reconciliationService, 'divergent') && str_contains($reconciliationService, 'pending') && str_contains($reconciliationService, 'inconsistent'),
    'reconciliation_repository_canonical_payment_table' => str_contains($reconciliationRepository, 'payment_transactions') && !preg_match($legacyPaymentsPattern, $reconciliationRepository),
    'reconciliation_repository_canonical_order_total' => str_contains($reconciliationRepository, 'total_amount'),
    'reconciliation_repository_customer_id' => str_contains($reconciliationRepository, 'customer_id'),
    'reconciliation_repository_prepared_statements' => (bool)preg_match('/->prepare\s*\(/', $reconciliationRepository),
    'reconciliation_repository_no_gateway_payload_selection' => !str_contains($reconciliationRepository, 'pt.gateway_payload') && !str_contains($reconciliationRepository, 'pt.pix_qr_code_base64'),
    'reconciliation_test_present' => $reconciliationTest !== '',
    'reconciliation_service_test_present' => $reconciliationServiceTest !== '',
    'payment_audit_repository_present' => $paymentAuditRepository !== '',
    'payment_audit_interface_present' => $paymentAuditInterface !== '',
    'payment_audit_repository_canonical_table' => str_contains($paymentAuditRepository, 'payment_audit_log'),
    'payment_audit_repository_prepared_statements' => (bool)preg_match('/->prepare\s*\(/', $paymentAuditRepository),
    'payment_audit_repository_idempotency' => str_contains($paymentAuditRepository, 'idempotency_key') && str_contains($paymentAuditRepository, 'isEventProcessed'),
    'payment_audit_repository_sanitizes_sensitive_keys' => str_contains($paymentAuditRepository, 'access_token') && str_contains($paymentAuditRepository, 'gateway_payload') && str_contains($paymentAuditRepository, 'pix_qr_code'),
    'payment_audit_test_present' => $paymentAuditTest !== '',
    'payment_audit_registered_validation_runner' => str_contains($validationRunner, 'payment_audit_repository_test.php'),
    'payment_audit_registered_release_gate' => str_contains($releaseGate, 'payment_audit_repository_test.php'),
    'idempotency_exception_present' => str_contains($idempotencyException, 'class IdempotencyConflictException extends RuntimeException'),
    'idempotency_strict_repository_present' => $paymentAuditStrictRepository !== '',
    'idempotency_strict_repository_prepared_statements' => (bool)preg_match('/->prepare\s*\(/', $paymentAuditStrictRepository),
    'idempotency_explicit_sqlstate_23000' => str_contains($paymentAuditStrictRepository, "\$sqlState === '23000'"),
    'idempotency_explicit_mysql_1062' => str_contains($paymentAuditStrictRepository, '=== 1062'),
    'idempotency_no_insert_ignore' => !str_contains(strtoupper($paymentAuditStrictRepository), 'INSERT IGNORE'),
    'idempotency_strict_uses_unique_insert' => str_contains($paymentAuditStrictRepository, 'INSERT INTO payment_audit_log') && str_contains($paymentAuditStrictRepository, 'idempotency_key'),
    'bootstrap_binds_strict_idempotency_repository' => str_contains($bootstrap, 'PaymentAuditIdempotencyRepository') && str_contains($bootstrap, 'new PaymentAuditIdempotencyRepository('),
    'webhook_present' => $webhook !== '',
    'webhook_strict_types' => str_contains($webhook, 'declare(strict_types=1);'),
    'webhook_no_inline_sql' => !preg_match($sqlKeywordPattern, $webhook),
    'webhook_no_direct_pdo_calls' => !preg_match($pdoCallPattern, $webhook),
    'webhook_resolves_validator_from_container' => str_contains($webhook, '$container->get(WebhookValidator::class)'),
    'webhook_resolves_payment_service_from_container' => str_contains($webhook, '$container->get(PaymentService::class)'),
    'webhook_resolves_audit_interface_from_container' => str_contains($webhook, '$container->get(PaymentAuditRepositoryInterface::class)'),
    'webhook_resolves_payment_interface_from_container' => str_contains($webhook, '$container->get(PaymentTransactionRepositoryInterface::class)'),
    'webhook_uses_audit_repository_for_transaction' => str_contains($webhook, '$auditRepository->transaction('),
    'webhook_persistent_idempotency' => str_contains($webhook, 'idempotencyKey') && str_contains($webhook, 'isEventProcessed('),
    'webhook_duplicate_exception_handled' => str_contains($webhook, 'catch (IdempotencyConflictException $e)'),
    'webhook_invalid_transition_exception_handled' => str_contains($webhook, 'catch (InvalidWebhookTransitionException $e)'),
    'webhook_duplicate_returns_200' => str_contains($webhook, "\$respond(200, ['received' => true, 'idempotent' => true])"),
    'webhook_concurrency_returns_controlled_response' => str_contains($webhook, "'retry_safe' => true") && str_contains($webhook, '1213'),
    'webhook_canonical_audit_repository_only' => !str_contains($webhook, 'PaymentAuditRepository::class') && str_contains($webhook, 'PaymentAuditRepositoryInterface::class'),
    'webhook_no_raw_body_audit' => !str_contains($webhook, "'body' => $rawBody") && !str_contains($webhook, "'raw_body' => $rawBody"),
    'webhook_no_gateway_payload_audit' => !str_contains($webhook, 'gateway_payload'),
    'webhook_no_credentials_audit' => !str_contains($webhook, 'access_token') && !str_contains($webhook, 'authorization') && !str_contains($webhook, 'webhook_secret'),
    'webhook_no_pix_qr_audit' => !str_contains($webhook, 'pix_qr_code') && !str_contains($webhook, 'qr_code_base64'),
    'webhook_hmac_validator' => str_contains($webhookValidator, 'hash_hmac(\'sha256\''),
    'webhook_constant_time_compare' => str_contains($webhookValidator, 'hash_equals('),
    'webhook_timestamp_freshness' => str_contains($webhookValidator, 'maxSkew') && str_contains($webhookValidator, 'abs($now - (int)$ts)'),
    'webhook_event_id_idempotency' => str_contains($webhook, "'mp:webhook:'"),
    'webhook_gateway_access_through_service' => str_contains($paymentService, 'getWebhookPayment('),
    'webhook_integration_test_present' => $webhookIntegrationTest !== '',
    'webhook_http_integration_test_present' => $webhookHttpIntegrationTest !== '',
    'webhook_concurrency_test_present' => $webhookConcurrencyTest !== '',
    'webhook_mysql_concurrency_test_present' => $webhookMysqlConcurrencyTest !== '',
    'webhook_mysql_concurrency_requires_pdo_mysql' => str_contains($webhookMysqlConcurrencyTest, "extension_loaded('pdo_mysql')"),
    'webhook_mysql_concurrency_requires_mysql8' => str_contains($webhookMysqlConcurrencyTest, "preg_match('/^8\\./"),
    'webhook_mysql_concurrency_requires_innodb' => str_contains($webhookMysqlConcurrencyTest, "engine(\$pdo, 'payment_transactions')") && str_contains($webhookMysqlConcurrencyTest, "engine(\$pdo, 'orders')"),
    'webhook_mysql_concurrency_32_plus_requests' => str_contains($webhookMysqlConcurrencyTest, 'max(32,') && str_contains($webhookMysqlConcurrencyTest, 'curl_multi_init('),
    'webhook_mysql_concurrency_conflicting_paid_refunded' => str_contains($webhookMysqlConcurrencyTest, "'paid' : 'refunded'") && str_contains($webhookMysqlConcurrencyTest, 'conflictRequests'),
    'webhook_mysql_concurrency_zero_500' => str_contains($webhookMysqlConcurrencyTest, "$result['status'] !== 500"),
    'webhook_mysql_concurrency_stock_invariant' => str_contains($webhookMysqlConcurrencyTest, 'CM_MYSQL_EXPECTED_STOCK_MOVEMENT_DELTA') && str_contains($webhookMysqlConcurrencyTest, 'stockSnapshot'),
    'webhook_mysql_concurrency_history_invariant' => str_contains($webhookMysqlConcurrencyTest, 'order_status_history') && str_contains($webhookMysqlConcurrencyTest, 'illegal order history transition'),
    'webhook_mysql_concurrency_audit_invariant' => str_contains($webhookMysqlConcurrencyTest, 'duplicate payment_audit_log idempotency keys'),
    'webhook_mysql_concurrency_registered_validation_runner' => str_contains($validationRunner, 'webhook_mysql_concurrency_test.php'),
    'webhook_mysql_concurrency_registered_release_gate' => str_contains($releaseGate, 'webhook_mysql_concurrency_test.php'),
    'webhook_concurrency_uses_curl_multi' => str_contains($webhookConcurrencyTest, 'curl_multi_init(') && str_contains($webhookConcurrencyTest, 'curl_multi_exec('),
    'webhook_concurrency_uses_multiple_workers' => str_contains($webhookConcurrencyTest, 'PHP_CLI_SERVER_WORKERS') && str_contains($webhookConcurrencyTest, 'serverCount'),
    'webhook_concurrency_same_notification_coverage' => str_contains($webhookConcurrencyTest, 'race-same-request') && str_contains($webhookConcurrencyTest, '910001'),
    'webhook_concurrency_unique_audit_assertion' => str_contains($webhookConcurrencyTest, 'COUNT(*) FROM payment_audit_log') && str_contains($webhookConcurrencyTest, 'idempotency_key'),
    'webhook_concurrency_atomic_rollback_coverage' => str_contains($webhookConcurrencyTest, 'orphanAudit') && str_contains($webhookConcurrencyTest, 'rollback burst'),
    'webhook_concurrency_registered_validation_runner' => str_contains($validationRunner, 'webhook_concurrency_test.php'),
    'webhook_concurrency_registered_release_gate' => str_contains($releaseGate, 'webhook_concurrency_test.php'),
    'webhook_integration_test_registered' => str_contains($validationRunner, 'webhook_audit_integration_test.php') && str_contains($releaseGate, 'webhook_audit_integration_test.php'),
    'webhook_http_test_registered_validation_runner' => str_contains($validationRunner, 'webhook_http_integration_test.php'),
    'webhook_http_test_registered_release_gate' => str_contains($releaseGate, 'webhook_http_integration_test.php'),
    'idempotency_hardening_test_present' => $idempotencyHardeningTest !== '',
    'idempotency_hardening_test_registered' => str_contains($validationRunner, 'payment_audit_idempotency_hardening_test.php') && str_contains($releaseGate, 'payment_audit_idempotency_hardening_test.php'),
    'idempotency_hardening_test_checks_1062' => str_contains($idempotencyHardeningTest, '23000') && str_contains($idempotencyHardeningTest, '1062'),
    'idempotency_hardening_test_checks_rollback' => str_contains($idempotencyHardeningTest, 'Losing transaction mutation was not rolled back'),
    'payment_transaction_webhook_repository_method' => str_contains($paymentRepositoryInterface, 'applyWebhookTransition(') && str_contains($paymentRepository, 'applyWebhookTransition('),
    'webhook_invalid_transition_exception_present' => str_contains($invalidTransitionException, 'class InvalidWebhookTransitionException extends RuntimeException'),
    'webhook_concurrency_exception_present' => str_contains($concurrencyException, 'class WebhookConcurrencyException extends RuntimeException'),
    'webhook_double_pessimistic_lock' => substr_count(strtoupper($paymentRepository), 'FOR UPDATE') >= 2
        && str_contains($paymentRepository, 'FROM payment_transactions')
        && str_contains($paymentRepository, 'FROM orders'),
    'webhook_lock_order_payment_then_order' => strpos($paymentRepository, 'FROM payment_transactions') < strpos($paymentRepository, 'FROM orders')
        && str_contains($paymentRepository, 'lockPaymentForWebhook(')
        && str_contains($paymentRepository, 'lockOrderForWebhook('),
    'webhook_monotonic_state_matrix' => str_contains($paymentRepository, 'ALLOWED_TRANSITIONS')
        && str_contains($paymentRepository, "'paid' => ['refunded']")
        && str_contains($paymentRepository, "'refunded' => []")
        && str_contains($paymentRepository, "'cancelled' => []"),
    'webhook_illegal_transition_domain_exception' => str_contains($paymentRepository, 'throw new InvalidWebhookTransitionException('),
    'webhook_transaction_boundary_required' => str_contains($paymentRepository, '$this->db->inTransaction()') && str_contains($paymentRepository, 'existing ACID transaction'),
    'webhook_history_same_transaction' => str_contains($paymentRepository, 'INSERT INTO order_status_history') && str_contains($paymentRepository, 'applyWebhookTransition'),
    'webhook_stock_same_transaction' => str_contains($paymentRepository, 'restoreOrderStock(') && str_contains($paymentRepository, 'stock_movements'),
    'webhook_deadlock_1213_handled' => str_contains($paymentRepository, '1213') && str_contains($paymentRepository, "'40001'"),
    'webhook_lock_timeout_1205_handled' => str_contains($paymentRepository, '1205'),
    'webhook_concurrency_exception_propagated' => str_contains($paymentRepository, 'throw new WebhookConcurrencyException('),
    'webhook_no_blind_retry' => !str_contains($paymentRepository, 'retry(') && !str_contains($paymentRepository, 'sleep('),
    'reconciliation_controller_present' => $reconciliation !== '',
    'reconciliation_view_present' => $reconciliationView !== '',
    'reconciliation_admin_guard' => str_contains($reconciliation, 'require_admin();'),
    'reconciliation_resolves_service' => str_contains($reconciliation, '$container->get(ReconciliationService::class)'),
    'reconciliation_controller_no_sql' => !preg_match($sqlKeywordPattern, $reconciliation),
    'reconciliation_controller_no_pdo_calls' => !preg_match($pdoCallPattern, $reconciliation),
    'reconciliation_no_legacy_payments' => !preg_match($legacyPaymentsPattern, $reconciliation),
    'reconciliation_csv_content_type' => str_contains($reconciliation, "header('Content-Type: text/csv; charset=UTF-8');"),
    'reconciliation_csv_disposition' => str_contains($reconciliation, 'Content-Disposition'),
    'reconciliation_csv_injection_guard' => str_contains($reconciliation, "['=', '+', '-', '@']"),
    'reconciliation_no_sensitive_export' => !str_contains($reconciliation, 'gateway_payload') && !str_contains($reconciliation, 'access_token') && !str_contains($reconciliation, 'pix_qr_code_base64'),
    'reconciliation_view_no_sql_or_pdo' => !preg_match($sqlKeywordPattern, $reconciliationView) && !preg_match($pdoCallPattern, $reconciliationView),
    'reconciliation_bootstrap_binding' => str_contains($bootstrap, 'PaymentAuditRepositoryInterface::class') && str_contains($bootstrap, 'ReconciliationService::class'),
    'payments_controller_no_sql_or_pdo' => !preg_match($sqlKeywordPattern, $paymentsController) && !preg_match($pdoCallPattern, $paymentsController),
    'payments_view_no_sensitive_data' => !str_contains($paymentsView, 'payload') && !str_contains($paymentsView, 'access_token') && !str_contains($paymentsView, 'webhook_secret'),
    'csrf_constant_time_compare' => str_contains($csrf, 'hash_equals('),
    'checkout_no_raw_gateway_return' => str_contains($checkout, "'gateway_data' => \$safeGateway"),
    'htaccess_present' => file_exists(__DIR__ . '/../.htaccess'),
    'logout_csrf' => str_contains($logout, 'require_csrf();'),
    'logout_destroy' => str_contains($logout, 'session_destroy();'),
    'strict_session_mode' => str_contains($config, "ini_set('session.use_strict_mode', '1')"),
    'session_id_rotation' => str_contains($config, 'session_regenerate_id(true)'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": {$name}\n";
exit($failed ? 1 : 0);
