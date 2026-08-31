<?php
declare(strict_types=1);

/** @var array<string, mixed> $summary */
/** @var list<array<string, mixed>> $payments */
/** @var array<string, scalar|null> $queryFilters */
/** @var int $page */
/** @var int $limit */
/** @var int $totalPages */
/** @var array<string, string> $statusLabels */
/** @var string|null $error */

$statusLabels = $statusLabels ?? [
    'pending' => 'Pendente',
    'authorized' => 'Autorizado',
    'paid' => 'Pago',
    'failed' => 'Falhou',
    'cancelled' => 'Cancelado',
    'refunded' => 'Estornado',
];

$money = static function (mixed $value): string {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
};

$statusClass = static function (mixed $status): string {
    return preg_replace('/[^a-z0-9_-]/i', '', (string)$status) ?: 'unknown';
};

$query = static function (array $extra = []) use ($queryFilters): string {
    return http_build_query(array_merge($queryFilters, $extra));
};

$summaryLabels = [
    'total' => 'Volume total',
    'paid' => 'Pago',
    'authorized' => 'Autorizado',
    'pending' => 'Pendente',
    'refunded' => 'Estornado',
    'failed' => 'Falhou',
    'cancelled' => 'Cancelado',
];
?>

<div class="admin-head">
    <div>
        <span class="eyebrow">FINANCEIRO</span>
        <h1>Conciliação</h1>
        <p>Consulta operacional das transações financeiras e seus respectivos estados.</p>
    </div>
    <div style="display:flex;gap:10px;flex-wrap:wrap">
        <a class="btn" href="payments.php">← Pagamentos</a>
        <a class="btn primary" href="reconciliation.php?<?= e($query(['export' => 'csv'])) ?>">Exportar CSV</a>
    </div>
</div>

<?php if (!empty($error)): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endif; ?>

<div class="summary">
    <div class="panel">
        <span>Total de registros</span>
        <strong><?= e((string)($summary['count'] ?? 0)) ?></strong>
    </div>
    <div class="panel">
        <span>Volume total</span>
        <strong><?= e($money($summary['total'] ?? 0)) ?></strong>
    </div>
    <div class="panel">
        <span>Recebido</span>
        <strong><?= e($money($summary['paid'] ?? 0)) ?></strong>
    </div>
    <div class="panel">
        <span>Estornado</span>
        <strong><?= e($money($summary['refunded'] ?? 0)) ?></strong>
    </div>
</div>

<div class="summary" style="margin-top:12px">
    <?php foreach (['authorized', 'pending', 'failed', 'cancelled'] as $key): ?>
        <div class="panel">
            <span><?= e($summaryLabels[$key]) ?></span>
            <strong><?= e($money($summary[$key] ?? 0)) ?></strong>
        </div>
    <?php endforeach; ?>
</div>

<form class="panel" method="get" style="display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:12px;align-items:end;margin:18px 0">
    <label>Status
        <select name="status">
            <option value="">Todos</option>
            <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= (($queryFilters['status'] ?? '') === $key) ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>

    <label>Gateway
        <input type="text" name="provider" value="<?= e((string)($queryFilters['provider'] ?? '')) ?>" maxlength="40">
    </label>

    <label>Cliente
        <input type="number" name="customer_id" min="1" step="1" value="<?= e((string)($queryFilters['customer_id'] ?? '')) ?>">
    </label>

    <label>Pedido
        <input type="number" name="order_id" min="1" step="1" value="<?= e((string)($queryFilters['order_id'] ?? '')) ?>">
    </label>

    <label>De
        <input type="date" name="date_from" value="<?= e((string)($queryFilters['date_from'] ?? '')) ?>">
    </label>

    <label>Até
        <input type="date" name="date_to" value="<?= e((string)($queryFilters['date_to'] ?? '')) ?>">
    </label>

    <label style="grid-column:span 5">Busca
        <input type="search" name="search" maxlength="120" value="<?= e((string)($queryFilters['search'] ?? '')) ?>" placeholder="ID, pedido, cliente ou transação">
    </label>

    <div style="display:flex;gap:8px">
        <button class="btn primary" type="submit">Filtrar</button>
        <a class="btn" href="reconciliation.php">Limpar</a>
    </div>
</form>

<section class="admin-panel">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>Transação</th>
                <th>Pedido</th>
                <th>Cliente</th>
                <th>Gateway</th>
                <th>Método</th>
                <th>Valor</th>
                <th>Status</th>
                <th>Criado</th>
                <th>Atualizado</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($payments === []): ?>
                <tr><td colspan="9">Nenhuma transação encontrada.</td></tr>
            <?php else: ?>
                <?php foreach ($payments as $payment): ?>
                    <?php $status = (string)($payment['status'] ?? ''); ?>
                    <tr>
                        <td>#<?= e((string)($payment['id'] ?? '')) ?></td>
                        <td>#<?= e((string)($payment['order_id'] ?? '')) ?></td>
                        <td><?= e((string)($payment['customer_name'] ?? '—')) ?></td>
                        <td><?= e((string)($payment['provider'] ?? '—')) ?></td>
                        <td><?= e((string)($payment['method'] ?? '—')) ?></td>
                        <td><?= e($money($payment['amount'] ?? 0)) ?></td>
                        <td><span class="status-pill <?= e($statusClass($status)) ?>"><?= e($statusLabels[$status] ?? $status) ?></span></td>
                        <td><?= e((string)($payment['created_at'] ?? '')) ?></td>
                        <td><?= e((string)($payment['updated_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<div style="display:flex;gap:8px;align-items:center;justify-content:flex-end;margin:18px 0">
    <?php if ($page > 1): ?>
        <a class="btn" href="reconciliation.php?<?= e($query(['page' => $page - 1, 'limit' => $limit])) ?>">← Anterior</a>
    <?php endif; ?>
    <span>Página <?= e((string)$page) ?> de <?= e((string)$totalPages) ?></span>
    <?php if ($page < $totalPages): ?>
        <a class="btn" href="reconciliation.php?<?= e($query(['page' => $page + 1, 'limit' => $limit])) ?>">Próxima →</a>
    <?php endif; ?>
</div>
