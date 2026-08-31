<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
$container = require __DIR__ . '/../bootstrap.php';

use App\Repositories\PaymentTransactionRepositoryInterface;

require_admin();

$labels = [
    'pending' => 'Pendente',
    'authorized' => 'Autorizado',
    'paid' => 'Pago',
    'failed' => 'Falhou',
    'cancelled' => 'Cancelado',
    'refunded' => 'Estornado',
];

$status = (string)($_GET['status'] ?? '');
$search = (string)($_GET['search'] ?? '');
$provider = (string)($_GET['provider'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

/** @var PaymentTransactionRepositoryInterface $paymentRepository */
$paymentRepository = $container->get(PaymentTransactionRepositoryInterface::class);
$payments = $paymentRepository->listWithFilters([
    'status' => $status,
    'search' => $search,
    'provider' => $provider,
], $limit, $offset);

$gateway = (string)(getenv('PAYMENT_GATEWAY') ?: 'none');
$title = 'Pagamentos — CM Comercial';
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-head">
    <div>
        <span class="eyebrow">FINANCEIRO</span>
        <h1>Pagamentos</h1>
        <p>Monitoramento financeiro. Esta tela não confirma pagamentos manualmente.</p>
    </div>
    <a href="orders.php" class="btn">← Pedidos</a>
</div>

<div class="summary">
    <div class="panel"><span>Gateway</span><strong><?= e($gateway) ?></strong></div>
    <div class="panel"><span>Registros desta página</span><strong><?= e((string)count($payments)) ?></strong></div>
</div>

<form class="panel" method="get" style="display:grid;grid-template-columns:2fr 1fr auto;gap:12px;align-items:end;margin:18px 0">
    <label>Busca<input type="search" name="search" value="<?= e($search) ?>" placeholder="ID, pedido ou transação"></label>
    <label>Status<select name="status"><option value="">Todos</option><?php foreach ($labels as $key => $label): ?><option value="<?= e($key) ?>" <?= $status === $key ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label>
    <button class="btn primary" type="submit">Filtrar</button>
</form>

<section class="admin-panel">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead><tr><th>ID</th><th>Pedido</th><th>Cliente</th><th>Método</th><th>Valor</th><th>Status</th><th>Transação</th><th>Atualizado</th></tr></thead>
            <tbody>
            <?php if (!$payments): ?>
                <tr><td colspan="8">Nenhum pagamento encontrado.</td></tr>
            <?php else: foreach ($payments as $payment): ?>
                <tr>
                    <td>#<?= e((string)$payment['id']) ?></td>
                    <td>#<?= e((string)$payment['order_id']) ?></td>
                    <td><?= e((string)($payment['customer_name'] ?? '—')) ?></td>
                    <td><?= e((string)($payment['method'] ?? '—')) ?></td>
                    <td><?= money((float)$payment['amount']) ?></td>
                    <td><span class="status-pill <?= e((string)$payment['status']) ?>"><?= e($labels[$payment['status']] ?? (string)$payment['status']) ?></span></td>
                    <td><?= e((string)($payment['transaction_id'] ?? '—')) ?></td>
                    <td><?= e((string)($payment['updated_at'] ?? '')) ?></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</section>

<?php if ($page > 1): ?>
    <a class="btn" href="payments.php?<?= http_build_query(['status'=>$status,'search'=>$search,'provider'=>$provider,'page'=>$page-1]) ?>">← Anterior</a>
<?php endif; ?>
<a class="btn" href="payments.php?<?= http_build_query(['status'=>$status,'search'=>$search,'provider'=>$provider,'page'=>$page+1]) ?>">Próxima →</a>
<?php include __DIR__ . '/../includes/footer.php'; ?>
