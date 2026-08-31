<?php
declare(strict_types=1);

/**
 * Presentation-only view for the customer's paginated financial history.
 * Expected variables:
 * @var list<array<string,mixed>> $financialItems
 * @var int $financialLimit
 * @var int $financialOffset
 */
?>
<section class="panel">
    <div class="panel-head">
        <div>
            <span class="eyebrow">HISTÓRICO</span>
            <h1>Histórico financeiro</h1>
        </div>
    </div>

    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>Pagamento</th><th>Pedido</th><th>Método</th><th>Status</th><th>Valor</th><th>Data</th></tr>
            </thead>
            <tbody>
            <?php if ($financialItems === []): ?>
                <tr><td colspan="6">Nenhum pagamento encontrado.</td></tr>
            <?php else: ?>
                <?php foreach ($financialItems as $item): ?>
                    <tr>
                        <td>#<?= e((string)$item['id']) ?></td>
                        <td>#<?= e((string)$item['order_id']) ?></td>
                        <td><?= e((string)$item['method']) ?></td>
                        <td><span class="status-pill <?= e((string)$item['status']) ?>"><?= e((string)$item['status']) ?></span></td>
                        <td><?= e(money((float)$item['amount'])) ?></td>
                        <td><?= e((string)$item['created_at']) ?></td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($financialOffset > 0 || count($financialItems) === $financialLimit): ?>
        <nav class="pagination" aria-label="Paginação do histórico financeiro">
            <?php if ($financialOffset > 0): ?>
                <a class="btn" href="financial_history.php?offset=<?= max(0, $financialOffset - $financialLimit) ?>&limit=<?= $financialLimit ?>">Anterior</a>
            <?php endif; ?>
            <?php if (count($financialItems) === $financialLimit): ?>
                <a class="btn primary" href="financial_history.php?offset=<?= $financialOffset + $financialLimit ?>&limit=<?= $financialLimit ?>">Próxima</a>
            <?php endif; ?>
        </nav>
    <?php endif; ?>
</section>
