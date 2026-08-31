<?php
declare(strict_types=1);

/**
 * Presentation-only view for the customer's financial overview.
 * Expected variables:
 * @var array{items:list<array<string,mixed>>,summary:array<string,mixed>,limit:int,offset:int} $financialOverview
 */
$summary = $financialOverview['summary'];
$items = $financialOverview['items'];
?>
<section class="financial-overview">
    <div class="financial-summary-grid">
        <div class="panel"><span>Total</span><strong><?= e(money((float)$summary['total'])) ?></strong></div>
        <div class="panel"><span>Pagos confirmados</span><strong><?= e(money((float)$summary['paid'])) ?></strong></div>
        <div class="panel"><span>Reembolsados</span><strong><?= e(money((float)$summary['refunded'])) ?></strong></div>
        <div class="panel"><span>Pendentes</span><strong><?= e(money((float)$summary['pending'])) ?></strong></div>
    </div>

    <div class="panel">
        <div class="panel-head">
            <div>
                <span class="eyebrow">FINANCEIRO</span>
                <h2>Histórico de pagamentos</h2>
            </div>
        </div>
        <div class="admin-table-wrap">
            <table class="admin-table">
                <thead>
                    <tr><th>Pagamento</th><th>Pedido</th><th>Método</th><th>Status</th><th>Valor</th><th>Data</th></tr>
                </thead>
                <tbody>
                <?php if ($items === []): ?>
                    <tr><td colspan="6">Nenhuma movimentação financeira encontrada.</td></tr>
                <?php else: ?>
                    <?php foreach ($items as $item): ?>
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
    </div>
</section>
