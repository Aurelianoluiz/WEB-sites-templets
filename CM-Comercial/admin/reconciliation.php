<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
$container = require __DIR__ . '/../bootstrap.php';

use App\Services\FinancialService;
use Throwable;

require_admin();

const RECONCILIATION_LIMIT_MAX = 100;

/** @var array<string, string> $statusLabels */
$statusLabels = [
    'pending' => 'Pendente',
    'authorized' => 'Autorizado',
    'paid' => 'Pago',
    'failed' => 'Falhou',
    'cancelled' => 'Cancelado',
    'refunded' => 'Estornado',
];

$filters = [
    'status' => trim((string)($_GET['status'] ?? '')),
    'provider' => trim((string)($_GET['provider'] ?? '')),
    'date_from' => trim((string)($_GET['date_from'] ?? '')),
    'date_to' => trim((string)($_GET['date_to'] ?? '')),
];

foreach (['customer_id', 'order_id'] as $key) {
    $raw = trim((string)($_GET[$key] ?? ''));
    if ($raw !== '' && ctype_digit($raw) && (int)$raw > 0) {
        $filters[$key] = (int)$raw;
    }
}

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = min(
    RECONCILIATION_LIMIT_MAX,
    max(1, (int)($_GET['limit'] ?? 50))
);
$offset = ($page - 1) * $limit;

$financialService = $container->get(FinancialService::class);

try {
    $summary = $financialService->getReconciliationSummary($filters);

    if (isset($_GET['export']) && $_GET['export'] === 'csv') {
        $rows = $financialService->listReconciliation($filters, 100, 0);

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cm-comercial-reconciliation.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new RuntimeException('Unable to open CSV output stream.');
        }

        $csvSafe = static function (mixed $value): string {
            $text = is_scalar($value) || $value === null
                ? (string)$value
                : '';

            if ($text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
                $text = "'" . $text;
            }

            return $text;
        };

        fputcsv($output, [
            'transaction_id',
            'order_id',
            'customer',
            'provider',
            'method',
            'amount',
            'status',
            'created_at',
            'updated_at',
        ]);

        foreach ($rows as $row) {
            fputcsv($output, [
                $csvSafe($row['id'] ?? ''),
                $csvSafe($row['order_id'] ?? ''),
                $csvSafe($row['customer_name'] ?? ''),
                $csvSafe($row['provider'] ?? ''),
                $csvSafe($row['method'] ?? ''),
                number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                $csvSafe($row['status'] ?? ''),
                $csvSafe($row['created_at'] ?? ''),
                $csvSafe($row['updated_at'] ?? ''),
            ]);
        }

        fclose($output);
        exit;
    }

    $payments = $financialService->listReconciliation(
        $filters,
        $limit,
        $offset
    );
} catch (Throwable $e) {
    http_response_code(400);
    $error = $e->getMessage();
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
}

$total = (int)($summary['count'] ?? 0);
$totalPages = max(1, (int)ceil($total / $limit));
$queryFilters = array_filter(
    $filters,
    static fn (mixed $value): bool => $value !== '' && $value !== null
);

$title = 'Conciliação — CM Comercial';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/views/reconciliation.php';
include __DIR__ . '/../includes/footer.php';
