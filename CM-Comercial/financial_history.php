<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
$container = require __DIR__ . '/bootstrap.php';

use App\Services\FinancialService;

require_login();

$currentUser = user();
$customerId = (int)($currentUser['id'] ?? 0);
if ($customerId < 1) {
    http_response_code(401);
    exit('Authentication required.');
}

$financialService = $container->get(FinancialService::class);
$financialLimit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$financialOffset = max(0, (int)($_GET['offset'] ?? 0));
$financialItems = $financialService->getCustomerFinancialHistory(
    $customerId,
    $financialLimit,
    $financialOffset
);

$title = 'Histórico financeiro — ' . APP_NAME;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/views/financial_history.php';
include __DIR__ . '/includes/footer.php';
