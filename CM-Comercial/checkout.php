<?php
declare(strict_types=1);

$container = require __DIR__ . '/bootstrap.php';

use App\Security\CsrfManager;
use App\Services\PaymentService;

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['user']) || !is_array($_SESSION['user'])) {
    http_response_code(401);
    exit('Authentication required.');
}

$csrf = $container->get(CsrfManager::class);
$paymentService = $container->get(PaymentService::class);
$user = $_SESSION['user'];
$error = null;
$result = null;
$cart = $_SESSION['cart'] ?? [];

if (!is_array($cart) || $cart === []) {
    $error = 'Seu carrinho está vazio.';
} else {
    $items = [];
    foreach ($cart as $key => $value) {
        $item = is_array($value)
            ? ['product_id' => (int)($value['product_id'] ?? $value['id'] ?? 0), 'quantity' => (int)($value['quantity'] ?? 0)]
            : ['product_id' => (int)$key, 'quantity' => (int)$value];
        $items[] = $item;
    }

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            $csrf->requireValid($_POST['_csrf'] ?? null);
            $email = trim((string)($user['email'] ?? ''));
            $name = trim((string)($user['name'] ?? 'Cliente'));
            // Freight must be calculated server-side; never trust a browser-submitted amount.
            $shippingAmount = 0.0;
            $idempotencyKey = trim((string)($_POST['idempotency_key'] ?? ''));
            if ($idempotencyKey === '') $idempotencyKey = 'web-' . bin2hex(random_bytes(18));

            $result = $paymentService->createPixOrder(
                isset($user['id']) ? (int)$user['id'] : null,
                $items,
                $email,
                $name,
                $idempotencyKey,
                $shippingAmount
            );
            if ($result['payment_status'] !== 'failed') $_SESSION['last_order_id'] = $result['order_id'];
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    }
}

function pageEscape(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <title>Checkout — CM Comercial</title>
  <link rel="stylesheet" href="assets/css/app.css">
</head>
<body>
<header class="header"><div class="container header-inner">
  <a class="brand" href="index.php">CM <span>Comercial</span></a>
  <div class="actions"><button class="btn ghost" type="button" data-theme="light">Claro</button><button class="btn ghost" type="button" data-theme="dark">Escuro</button></div>
</div></header>
<main class="container section">
  <div class="page-head"><span class="eyebrow">PAGAMENTO SEGURO</span><h1>Finalizar compra</h1><p class="muted">Revise seus dados e gere sua cobrança PIX.</p></div>
  <?php if ($error !== null): ?><div class="panel" style="padding:18px;margin:18px 0;border-color:var(--danger)"><strong><?=pageEscape($error)?></strong></div><?php endif; ?>

  <?php if ($result !== null): ?>
    <section class="checkout-grid" data-payment-poll data-order-id="<?= (int)$result['order_id'] ?>" data-poll-interval="4000" data-redirect="order.php?id=<?= (int)$result['order_id'] ?>">
      <div class="panel" style="padding:26px">
        <span class="badge <?= $result['payment_status'] === 'paid' ? 'success' : 'warning' ?>" data-payment-status-text><?= pageEscape($result['payment_status']) ?></span>
        <h2>Pedido #<?= (int)$result['order_id'] ?></h2>
        <p class="muted">O status será atualizado automaticamente a cada 4 segundos.</p>
        <?php if ($result['pix_qr_code_base64'] !== ''): ?>
          <div class="pix-box" style="margin-top:20px">
            <strong>Escaneie o QR Code PIX</strong>
            <img class="pix-qr" src="data:image/png;base64,<?=pageEscape($result['pix_qr_code_base64'])?>" alt="QR Code PIX">
            <?php if ($result['pix_qr_code'] !== ''): ?><div class="copy-row"><input id="pix-code" value="<?=pageEscape($result['pix_qr_code'])?>" readonly aria-label="Código PIX copia e cola"><button class="btn primary" type="button" data-copy-pix="#pix-code">Copiar PIX</button></div><?php endif; ?>
            <?php if ($result['pix_expires_at'] !== null): ?><small class="muted">Expira em <?=pageEscape($result['pix_expires_at'])?></small><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <aside class="panel" style="padding:24px"><h3>Resumo</h3><p>Pagamento: <strong><?=pageEscape($result['payment_status'])?></strong></p><p>ID da transação: <strong><?=pageEscape((string)($result['provider_payment_id'] ?? '—'))?></strong></p><a class="btn" href="order.php?id=<?= (int)$result['order_id'] ?>">Acompanhar pedido</a></aside>
    </section>
  <?php elseif ($error === null): ?>
    <form class="checkout-grid" method="post" novalidate>
      <div class="panel" style="padding:26px"><div class="form-grid">
        <div class="field"><label for="name">Nome</label><input id="name" name="name" value="<?=pageEscape((string)($user['name'] ?? ''))?>" readonly autocomplete="name"></div>
        <div class="field"><label for="email">E-mail</label><input id="email" name="email" type="email" value="<?=pageEscape((string)($user['email'] ?? ''))?>" readonly autocomplete="email"></div>
        <div class="field full"><button class="btn primary" type="submit">Gerar cobrança PIX</button></div>
      </div></div>
      <aside class="panel" style="padding:24px"><h3>Proteção</h3><p class="muted">O servidor calcula o total, bloqueia o estoque durante a criação e usa uma chave de idempotência por tentativa.</p><input type="hidden" name="_csrf" value="<?=pageEscape($csrf->token())?>"><input type="hidden" name="idempotency_key" value="<?=pageEscape('web-' . bin2hex(random_bytes(10)))?>"></aside>
    </form>
  <?php endif; ?>
</main>
<div id="toast" class="toast" role="status" aria-live="polite"></div>
<script src="assets/js/checkout.js" defer></script>
</body>
</html>
