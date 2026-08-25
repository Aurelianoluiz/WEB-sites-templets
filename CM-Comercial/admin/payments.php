<?php
declare(strict_types=1);
require_once '../config.php';
require_admin();
require_once '../includes/payment_core.php';

$pdo = db();
ensure_payment_schema($pdo);

$labels = [
    'pending' => 'Pendente',
    'authorized' => 'Autorizado',
    'paid' => 'Pago',
    'failed' => 'Falhou',
    'cancelled' => 'Cancelado',
    'refunded' => 'Estornado',
];
$filter = $_GET['status'] ?? '';
$sql = 'SELECT p.*, o.status AS order_status, o.total AS order_total, o.customer_name FROM payments p LEFT JOIN orders o ON o.id=p.order_id';
$args = [];
if (isset($labels[$filter])) { $sql .= ' WHERE p.status=?'; $args[] = $filter; }
$sql .= ' ORDER BY p.id DESC';
$st = $pdo->prepare($sql);
$st->execute($args);
$payments = $st->fetchAll();
$gateway = getenv('PAYMENT_GATEWAY') ?: 'none';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pagamentos — CM Comercial</title>
<style>
body{margin:0;font-family:Arial,sans-serif;background:#f5f5f5;color:#151515}.top{background:#111;color:#fff;padding:18px 24px;display:flex;justify-content:space-between;align-items:center}.top strong{color:#ffd400}.wrap{max-width:1200px;margin:32px auto;padding:0 18px}.card{background:#fff;border:1px solid #ddd;border-radius:12px;padding:20px;overflow:auto}.filters{display:flex;gap:8px;flex-wrap:wrap;margin:18px 0}.filters a{padding:9px 12px;border:1px solid #ddd;border-radius:8px;text-decoration:none;color:#111;background:#fff}.filters a.active{background:#d71920;color:#fff;border-color:#d71920}table{width:100%;border-collapse:collapse;min-width:780px}th,td{text-align:left;padding:13px 10px;border-bottom:1px solid #eee}th{font-size:12px;text-transform:uppercase;color:#666}.badge{display:inline-block;padding:5px 9px;border-radius:999px;background:#eee;font-size:12px;font-weight:700}.summary{display:flex;gap:12px;flex-wrap:wrap}.summary div{background:#111;color:#fff;border-radius:10px;padding:14px 18px}.muted{color:#777;font-size:13px}.back{color:#fff;text-decoration:none}
</style></head>
<body>
<header class="top"><strong>CM <span>COMERCIAL</span></strong><a class="back" href="orders.php">← Pedidos</a></header>
<main class="wrap">
<h1>Pagamentos</h1>
<p class="muted">Monitoramento financeiro. Esta tela não confirma pagamentos manualmente.</p>
<div class="summary"><div>Gateway: <strong><?=e($gateway)?></strong></div><div>Registros: <strong><?=count($payments)?></strong></div></div>
<nav class="filters"><a class="<?=($filter===''?'active':'')?>" href="payments.php">Todos</a><?php foreach($labels as $key=>$label): ?><a class="<?=($filter===$key?'active':'')?>" href="payments.php?status=<?=$key?>"><?=e($label)?></a><?php endforeach; ?></nav>
<section class="card"><table><thead><tr><th>ID</th><th>Pedido</th><th>Cliente</th><th>Método</th><th>Valor</th><th>Status financeiro</th><th>Transação</th><th>Atualizado</th></tr></thead><tbody>
<?php if(!$payments): ?><tr><td colspan="8">Nenhum pagamento encontrado.</td></tr><?php else: foreach($payments as $p): ?><tr><td>#<?=e((string)$p['id'])?></td><td>#<?=e((string)$p['order_id'])?></td><td><?=e((string)($p['customer_name'] ?? '—'))?></td><td><?=e((string)($p['method'] ?? '—'))?></td><td><?=money((float)$p['amount'])?></td><td><span class="badge"><?=e($labels[$p['status']] ?? $p['status'])?></span></td><td><?=e((string)($p['transaction_id'] ?? '—'))?></td><td><?=e((string)$p['updated_at'])?></td></tr><?php endforeach; endif; ?>
</tbody></table></section>
</main></body></html>
