<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
$container = require __DIR__ . '/bootstrap.php';

use App\Services\OrderService;

require_login();

$orderId = (int)($_GET['id'] ?? 0);
$userId = (int)(user()['id'] ?? 0);
$message = '';
$error = '';

/** @var OrderService $orderService */
$orderService = $container->get(OrderService::class);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    if (($_POST['action'] ?? '') === 'cancel') {
        $result = $orderService->cancelByCustomer($orderId, $userId);
        if ($result['success']) {
            $message = (string)$result['message'];
        } else {
            $error = (string)$result['message'];
        }
    }
}

$order = $orderService->getOrderForCustomer($orderId, $userId);
if ($order === null) {
    http_response_code(404);
    $title = 'Pedido não encontrado — ' . APP_NAME;
    include __DIR__ . '/includes/header.php';
    echo '<div class="empty"><h1>Pedido não encontrado.</h1><a class="btn primary" href="account.php">Voltar para minha conta</a></div>';
    include __DIR__ . '/includes/footer.php';
    exit;
}

$items = is_array($order['items'] ?? null) ? $order['items'] : [];
$title = 'Pedido #' . $orderId . ' — ' . APP_NAME;
$statusLabels = [
    'pending' => 'Aguardando confirmação',
    'confirmed' => 'Confirmado',
    'preparing' => 'Em preparação',
    'shipped' => 'Enviado',
    'delivered' => 'Entregue',
    'cancelled' => 'Cancelado',
];
include __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <span class="eyebrow">MEUS PEDIDOS</span>
        <h1>Pedido #<?= e((string)$orderId) ?></h1>
        <p>Realizado em <?= e(date('d/m/Y H:i', strtotime((string)$order['created_at']))) ?></p>
    </div>
    <a class="btn" href="account.php">← Minha conta</a>
</div>

<?php if ($message !== ''): ?><div class="alert success"><?= e($message) ?></div><?php endif; ?>
<?php if ($error !== ''): ?><div class="alert error"><?= e($error) ?></div><?php endif; ?>

<div class="order-detail-grid">
    <section class="panel">
        <div class="order-status">
            <span class="status-pill <?= e((string)$order['status']) ?>"><?= e($statusLabels[$order['status']] ?? (string)$order['status']) ?></span>
            <strong><?= money((float)($order['total'] ?? $order['total_amount'] ?? 0)) ?></strong>
        </div>
        <h2>Itens</h2>
        <div class="order-items">
            <?php foreach ($items as $item): ?>
                <?php $quantity = (int)($item['qty'] ?? $item['quantity'] ?? 0); $unitPrice = (float)($item['price'] ?? $item['unit_price'] ?? 0); ?>
                <div class="order-item">
                    <div>
                        <strong><?= e((string)($item['name'] ?? $item['product_name'] ?? 'Produto')) ?></strong>
                        <small><?= e((string)$quantity) ?> × <?= money($unitPrice) ?></small>
                    </div>
                    <b><?= money($quantity * $unitPrice) ?></b>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if (in_array((string)$order['status'], ['pending', 'confirmed'], true) && ($order['payment_status'] ?? 'pending') !== 'paid'): ?>
            <div class="order-cancel-box">
                <strong>Precisa desistir da compra?</strong>
                <p>Você pode cancelar enquanto o pedido ainda não estiver em preparação e o pagamento não tiver sido confirmado.</p>
                <form method="post" onsubmit="return confirm('Tem certeza que deseja cancelar este pedido?')">
                    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="action" value="cancel">
                    <button class="btn danger" type="submit">Cancelar pedido</button>
                </form>
            </div>
        <?php endif; ?>
    </section>

    <aside class="panel">
        <h2>Pagamento</h2>
        <div class="detail-list">
            <div><span>Método</span><strong><?= (($order['payment_method'] ?? '') === 'pix' ? 'Pix' : 'Cartão') ?></strong></div>
            <div><span>Status</span><strong><?= e((string)($order['payment_status'] ?? 'pending')) ?></strong></div>
        </div>
        <h2>Recebimento</h2>
        <div class="detail-list">
            <div><span>Modalidade</span><strong><?= (($order['shipping_method'] ?? '') === 'delivery' ? 'Entrega' : 'Retirada na loja') ?></strong></div>
            <div><span>Cliente</span><strong><?= e((string)($order['customer_name'] ?? $order['user_name'] ?? '')) ?></strong></div>
            <?php if (!empty($order['phone'])): ?><div><span>Telefone</span><strong><?= e((string)$order['phone']) ?></strong></div><?php endif; ?>
            <?php if (!empty($order['shipping_label'])): ?>
                <div><span>Frete</span><strong><?= e((string)$order['shipping_label']) ?><?= ((int)($order['shipping_eta'] ?? 0) > 0) ? ' · até ' . e((string)$order['shipping_eta']) . ' dias úteis' : '' ?></strong></div>
            <?php endif; ?>
            <?php if (($order['shipping_method'] ?? '') === 'delivery'): ?>
                <div><span>Endereço</span><strong><?= e(trim((string)($order['address'] ?? '') . ', ' . (string)($order['number'] ?? ''), ' ,')) ?></strong></div>
                <div><span>Local</span><strong><?= e(trim((string)($order['neighborhood'] ?? '') . ' — ' . (string)($order['city'] ?? '') . '/' . (string)($order['state'] ?? ''), ' —/')) ?></strong></div>
                <?php if (!empty($order['complement'])): ?><div><span>Complemento</span><strong><?= e((string)$order['complement']) ?></strong></div><?php endif; ?>
                <div><span>CEP</span><strong><?= e((string)($order['zip'] ?? '')) ?></strong></div>
            <?php endif; ?>
        </div>
    </aside>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
