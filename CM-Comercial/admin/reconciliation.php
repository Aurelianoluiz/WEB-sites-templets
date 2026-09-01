<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
$container = require __DIR__ . '/../bootstrap.php';

use App\Services\ReconciliationService;

require_admin();

const RECONCILIATION_LIMIT_MAX = 100;
const RECONCILIATION_LIMIT_DEFAULT = 50;

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
    'search' => trim((string)($_GET['search'] ?? '')),
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
    max(1, (int)($_GET['limit'] ?? RECONCILIATION_LIMIT_DEFAULT))
);
$offset = ($page - 1) * $limit;

$reconciliationService = $container->get(ReconciliationService::class);
$error = null;
$summary = [
    'total' => 0,
    'payment_transactions' => 0,
    'reconciled' => 0,
    'divergent' => 0,
    'pending' => 0,
    'inconsistent' => 0,
    'orphan_transactions' => 0,
    'missing_transactions' => 0,
    'amount_mismatches' => 0,
    'status_mismatches' => 0,
    'total_amount' => 0.0,
    'paid_amount' => 0.0,
    'refunded_amount' => 0.0,
    'pending_amount' => 0.0,
    'failed_amount' => 0.0,
    'cancelled_amount' => 0.0,
    'authorized_amount' => 0.0,
];
$payments = [];
$total = 0;
$totalPages = 1;

try {
    $idempotencyKey = hash(
        'sha256',
        json_encode(
            [
                'filters' => $filters,
                'page' => $page,
                'limit' => $limit,
            ],
            JSON_THROW_ON_ERROR
        )
    );

    $snapshot = $reconciliationService->reconcile(
        $idempotencyKey,
        $filters,
        $limit,
        $offset
    );

    $summary = $snapshot['summary'];
    $payments = $snapshot['page']['items'];
    $total = $snapshot['page']['total'];
    $totalPages = $snapshot['page']['total_pages'];
    $page = $snapshot['page']['page'];
    $offset = $snapshot['page']['offset'];
} catch (\Throwable $e) {
    http_response_code($e instanceof \InvalidArgumentException ? 400 : 500);
    $error = 'Não foi possível carregar a conciliação financeira.';
}

if (isset($_GET['export']) && $_GET['export'] === 'csv' && $error === null) {
    try {
        $exportKey = hash('sha256', $idempotencyKey . ':export');
        $export = $reconciliationService->reconcile(
            $exportKey,
            $filters,
            RECONCILIATION_LIMIT_MAX,
            0
        );

        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="cm-comercial-reconciliation.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');

        $output = fopen('php://output', 'wb');
        if ($output === false) {
            throw new \RuntimeException('Unable to open CSV output stream.');
        }

        $csvSafe = static function (mixed $value): string {
            $text = is_scalar($value) || $value === null ? (string)$value : '';
            if ($text !== '' && in_array($text[0], ['=', '+', '-', '@'], true)) {
                return "'" . $text;
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
            'payment_status',
            'reconciliation_status',
            'divergence_reason',
            'created_at',
            'updated_at',
        ]);

        foreach ($export['page']['items'] as $row) {
            fputcsv($output, [
                $csvSafe($row['id'] ?? ''),
                $csvSafe($row['order_id'] ?? ''),
                $csvSafe($row['customer_name'] ?? ''),
                $csvSafe($row['provider'] ?? ''),
                $csvSafe($row['method'] ?? ''),
                number_format((float)($row['amount'] ?? 0), 2, '.', ''),
                $csvSafe($row['status'] ?? ''),
                $csvSafe($row['reconciliation_status'] ?? ''),
                $csvSafe($row['divergence_reason'] ?? ''),
                $csvSafe($row['created_at'] ?? ''),
                $csvSafe($row['updated_at'] ?? ''),
            ]);
        }

        fclose($output);
        exit;
    } catch (\Throwable) {
        http_response_code(500);
        $error = 'Unable to export reconciliation data.';
    }
}

$queryFilters = array_filter(
    array_merge($filters, ['limit' => $limit]),
    static fn (mixed $value): bool => $value !== '' && $value !== null
);

$title = 'Conciliação — CM Comercial';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/views/reconciliation.php';
include __DIR__ . '/../includes/footer.php';
