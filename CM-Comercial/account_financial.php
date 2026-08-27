<?php
declare(strict_types=1);

require_once __DIR__ . '/financial_history.php';

/**
 * Customer-facing read model. Identity is taken from the authenticated
 * session; no customer id is accepted from request parameters.
 */
function get_my_financial_history(PDO $pdo, int $limit = 20, int $offset = 0): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $customerId = filter_var($_SESSION['customer_id'] ?? null, FILTER_VALIDATE_INT);
    if (!$customerId || $customerId < 1) {
        http_response_code(401);
        return ['error' => 'authentication_required', 'items' => []];
    }
    return ['error' => null, 'items' => customer_financial_history($pdo, $customerId, $limit, $offset)];
}
