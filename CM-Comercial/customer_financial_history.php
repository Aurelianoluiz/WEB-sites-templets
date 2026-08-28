<?php
declare(strict_types=1);

require_once __DIR__ . '/financial_history.php';

/**
 * Resolve the customer id exclusively from the authenticated application
 * session. Never accept customer_id from the request for this endpoint.
 */
function authenticated_customer_financial_history(PDO $pdo, int $limit = 50, int $offset = 0): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();

    // The application authentication model stores the signed-in user in
    // $_SESSION['user']. Keep customer identity bound to that server-side
    // session and never trust a request-provided customer_id.
    $customerId = filter_var($_SESSION['user']['id'] ?? null, FILTER_VALIDATE_INT);
    if (!$customerId || $customerId < 1) {
        http_response_code(401);
        throw new RuntimeException('Authentication required.');
    }

    return customer_financial_history($pdo, $customerId, $limit, $offset);
}
