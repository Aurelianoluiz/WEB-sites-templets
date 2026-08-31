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
$financialLimit = 20;
$financialOffset = max(0, (int)($_GET['offset'] ?? 0));
$financialOverview = $financialService->getCustomerFinancialOverview(
    $customerId,
    $financialLimit,
    $financialOffset
);

$title = 'Financeiro — ' . APP_NAME;
include __DIR__ . '/includes/header.php';
include __DIR__ . '/views/account_financial.php';
include __DIR__ . '/includes/footer.php';
