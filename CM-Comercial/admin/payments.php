<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
$container = require __DIR__ . '/../bootstrap.php';

use App\Services\FinancialService;
use Throwable;

require_admin();

const PAYMENTS_LIMIT_MAX = 100;
const PAYMENTS_LIMIT_DEFAULT = 50;

$statusLabels = [
    'pending' => 'Pendente',
    'authorized' => 'Autorizado',
    'paid' => 'Pago',
    'failed' => 'Falhou',
    'cancelled' => 'Cancelado',
    'refunded' => 'Estornado',
];

$status = trim((string)($_GET['status'] ?? ''));
$provider = trim((string)($_GET['provider'] ?? ''));
$search = trim((string)($_GET['search'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$filters = [
    'status' => $status,
    'provider' => $provider,
    'search' => $search,
    'date_from' => $dateFrom,
    'date_to' => $dateTo,
];

if (isset($_GET['customer_id'])) {
    $rawCustomerId = trim((string)$_GET['customer_id']);
    if ($rawCustomerId !== '' && ctype_digit($rawCustomerId) && (int)$rawCustomerId > 0) {
        $filters['customer_id'] = (int)$rawCustomerId;
    }
}

if (isset($_GET['order_id'])) {
    $rawOrderId = trim((string)$_GET['order_id']);
    if ($rawOrderId !== '' && ctype_digit($rawOrderId) && (int)$rawOrderId > 0) {
        $filters['order_id'] = (int)$rawOrderId;
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(
    PAYMENTS_LIMIT_MAX,
    max(1, (int)($_GET['limit'] ?? PAYMENTS_LIMIT_DEFAULT))
);

$financialService = $container->get(FinancialService::class);
$error = null;
$payments = [];
$summary = [
    'count' => 0,
    'total' => 0.0,
    'paid' => 0.0,
    'refunded' => 0.0,
    'pending' => 0.0,
    'failed' => 0.0,
    'cancelled' => 0.0,
    'authorized' => 0.0,
];

try {
    $summary = $financialService->getReconciliationSummary($filters);
    $total = (int)($summary['count'] ?? 0);
    $totalPages = max(1, (int)ceil($total / $limit));
    $page = min($page, $totalPages);
    $offset = ($page - 1) * $limit;

    $payments = $financialService->listReconciliation(
        $filters,
        $limit,
        $offset
    );
} catch (Throwable $e) {
    http_response_code(400);
    $error = $e->getMessage();
    $total = 0;
    $totalPages = 1;
    $offset = 0;
}

$queryFilters = array_filter(
    [
        'status' => $status,
        'provider' => $provider,
        'search' => $search,
        'date_from' => $dateFrom,
        'date_to' => $dateTo,
        'customer_id' => $filters['customer_id'] ?? '',
        'order_id' => $filters['order_id'] ?? '',
        'limit' => $limit,
    ],
    static fn (mixed $value): bool => $value !== '' && $value !== null
);

$gateway = (string)(getenv('PAYMENT_GATEWAY') ?: 'none');
$title = 'Pagamentos — CM Comercial';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/views/payments.php';
include __DIR__ . '/../includes/footer.php';
