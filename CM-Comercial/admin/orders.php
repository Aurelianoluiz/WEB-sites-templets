<?php
declare(strict_types=1);

$admin_layout = true;
require_once __DIR__ . '/../config.php';
$container = require __DIR__ . '/../bootstrap.php';

use App\Services\AdminOrderService;

require_admin();

$statusLabels = [
    'pending' => 'Aguardando confirmação',
    'confirmed' => 'Confirmado',
    'preparing' => 'Em preparação',
    'shipped' => 'Enviado',
    'delivered' => 'Entregue',
    'cancelled' => 'Cancelado',
];

/** @var AdminOrderService $adminService */
$adminService = $container->get(AdminOrderService::class);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    $orderId = (int)($_POST['id'] ?? 0);
    $newStatus = (string)($_POST['status'] ?? '');

    if ($action === 'status' && isset($statusLabels[$newStatus])) {
        $result = $adminService->changeOrderStatus($orderId, $newStatus, (int)(user()['id'] ?? 0));
        if ($result['success']) {
            redirect('orders.php?saved=1');
        }
        redirect('orders.php?error=' . rawurlencode((string)$result['message']));
    }
}

$filter = (string)($_GET['status'] ?? '');
$orders = $adminService->listOrders($filter, 100, 0);
$title = 'Pedidos — Administração';
include __DIR__ . '/../includes/header.php';
?>
<div class="admin-head">
    <div>
        <span class="eyebrow">VENDAS</span>
        <h1>Pedidos</h1>
        <p>Acompanhe, atualize e consulte os pedidos dos clientes.</p>
    </div>
    <a href="index.php" class="btn">← Painel</a>
</div>

<?php if (isset($_GET['saved'])): ?>
    <div class="alert success">Status atualizado com sucesso.</div>
<?php elseif (isset($_GET['error'])): ?>
    <div class="alert error"><?= e((string)$_GET['error']) ?></div>
<?php endif; ?>

<div class="order-filters">
    <a class="btn <?= $filter === '' ? 'primary' : '' ?>" href="orders.php">Todos</a>
    <?php foreach ($statusLabels as $key => $label): ?>
        <a class="btn <?= $filter === $key ? 'primary' : '' ?>" href="orders.php?status=<?= e($key) ?>"><?= e($label) ?></a>
    <?php endforeach; ?>
</div>

<div class="admin-panel">
    <div class="admin-table-wrap">
        <table class="admin-table">
            <thead>
                <tr><th>Pedido</th><th>Cliente</th><th>Data</th><th>Recebimento</th><th>Total</th><th>Status</th><th></th></tr>
            </thead>
            <tbody>
            <?php if (!$orders): ?>
                <tr><td colspan="7">Nenhum pedido encontrado.</td></tr>
            <?php else: foreach ($orders as $order): ?>
                <tr>
                    <td><strong>#<?= e((string)$order['id']) ?></strong></td>
                    <td><strong><?= e((string)($order['customer_name'] ?? $order['user_name'] ?? '')) ?></strong><br><small><?= e((string)($order['email'] ?? $order['user_email'] ?? '')) ?></small></td>
                    <td><?= e(date('d/m/Y H:i', strtotime((string)$order['created_at']))) ?></td>
                    <td><?= (($order['shipping_method'] ?? '') === 'delivery' ? 'Entrega' : 'Retirada') ?></td>
                    <td><?= money((float)($order['total'] ?? $order['total_amount'] ?? 0)) ?></td>
                    <td>
                        <form method="post" class="status-form">
                            <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
                            <input type="hidden" name="action" value="status">
                            <input type="hidden" name="id" value="<?= e((string)$order['id']) ?>">
                            <select name="status" onchange="this.form.submit()">
                                <?php foreach ($statusLabels as $key => $label): ?>
                                    <option value="<?= e($key) ?>" <?= (($order['status'] ?? '') === $key ? 'selected' : '') ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </form>
                    </td>
                    <td><a href="../order.php?id=<?= e((string)$order['id']) ?>">Ver detalhes</a></td>
                </tr>
            <?php endforeach; endif; ?>
            </tbody>
        </table>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
