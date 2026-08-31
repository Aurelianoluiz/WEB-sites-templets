<?php
declare(strict_types=1);

/**
 * @var list<array<string, mixed>> $payments
 * @var array<string, string> $statusLabels
 * @var string $status
 * @var string $provider
 * @var string $search
 * @var string $dateFrom
 * @var string $dateTo
 * @var int $page
 * @var int $limit
 * @var int $total
 * @var int $totalPages
 * @var string $gateway
 * @var ?string $error
 * @var array<string, scalar> $queryFilters
 */

$buildQuery = static function (array $overrides = []) use ($queryFilters): string {
    return http_build_query(array_filter(
        array_merge($queryFilters, $overrides),
        static fn (mixed $value): bool => $value !== '' && $value !== null
    ));
};
?>
<div class="admin-head">
    <div>
        <span class="eyebrow">FINANCEIRO</span>
        <h1>Pagamentos</h1>
        <p>Monitoramento das transações financeiras. Esta tela não confirma pagamentos manualmente.</p>
    </div>
    <a href="reconciliation.php" class="btn">Conciliação →</a>
</div>

<?php if ($error !== null): ?>
    <div class="alert error"><?= e($error) ?></div>
<?php endif; ?>

<div class="summary">
    <div class="panel"><span>Gateway configurado</span><strong><?= e($gateway) ?></strong></div>
    <div class="panel"><span>Registros encontrados</span><strong><?= e((string)$total) ?></strong></div>
    <div class="panel"><span>Página</span><strong><?= e((string)$page) ?> / <?= e((string)$totalPages) ?></strong></div>
</div>

<form class="panel" method="get" style="display:grid;grid-template-columns:1.5fr 1fr 1fr 1fr 1fr auto;gap:12px;align-items:end;margin:18px 0">
    <label>Busca
        <input type="search" name="search" value="<?= e($search) ?>" maxlength="120" placeholder="Pedido, e-mail ou transação">
    </label>
    <label>Status
        <select name="status">
            <option value="">Todos</option>
            <?php foreach ($statusLabels as $key => $label): ?>
                <option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <label>Gateway
        <input type="text" name="provider" value="<?= e($provider) ?>" maxlength="40">
    </label>
    <label>Data inicial
        <input type="date" name="date_from" value="<?= e($dateFrom) ?>">
    </label>
    <label>Data final
        <input type="date" name="date_to" value="<?= e($dateTo) ?>">
    </label>
    <button class="btn primary" type="submit">Filtrar</button>
</form>

<section class="admin-panel">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
            <tr>
                <th>ID</th>
                <th>Pedido</th>
                <th>Cliente</th>
                <th>Método</th>
                <th>Valor</th>
                <th>Status</th>
                <th>Transação</th>
                <th>Atualizado</th>
            </tr>
            </thead>
            <tbody>
            <?php if ($payments === []): ?>
                <tr><td colspan="8">Nenhum pagamento encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($payments as $payment): ?>
                    <?php $paymentStatus = (string)($payment['status'] ?? 'pending'); ?>
                    <tr>
                        <td>#<?= e((string)($payment['id'] ?? '')) ?></td>
                        <td>#<?= e((string)($payment['order_id'] ?? '')) ?></td>
                        <td>
                            <strong><?= e((string)($payment['customer_name'] ?? '—')) ?></strong>
                            <?php if (!empty($payment['order_email'])): ?>
                                <br><small><?= e((string)$payment['order_email']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td><?= e((string)($payment['method'] ?? '—')) ?></td>
                        <td><?= money((float)($payment['amount'] ?? 0)) ?></td>
                        <td><span class="status-pill <?= e($paymentStatus) ?>"><?= e($statusLabels[$paymentStatus] ?? $paymentStatus) ?></span></td>
                        <td><?= e((string)($payment['transaction_id'] ?? '—')) ?></td>
                        <td><?= e((string)($payment['updated_at'] ?? $payment['created_at'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
</section>

<nav class="pagination" aria-label="Paginação de pagamentos">
    <?php if ($page > 1): ?>
        <a class="btn" href="payments.php?<?= e($buildQuery(['page' => $page - 1])) ?>">← Anterior</a>
    <?php endif; ?>
    <?php if ($page < $totalPages): ?>
        <a class="btn" href="payments.php?<?= e($buildQuery(['page' => $page + 1])) ?>">Próxima →</a>
    <?php endif; ?>
</nav>
