<?php
declare(strict_types=1);

require_once __DIR__ . '/payment_core.php';
require_once __DIR__ . '/payment_order_policy.php';
require_once __DIR__ . '/../integrations/payment_service.php';
require_once __DIR__ . '/../integrations/payment_adapter.php';
require_once __DIR__ . '/../integrations/mercadopago_adapter.php';

function initiate_checkout_payment(PDO $pdo, int $orderId, float $amount, string $method, array $customer = [], array $paymentData = []): array
{
    if ($orderId < 1 || $amount <= 0) throw new InvalidArgumentException('Pedido/valor inválido.');
    $paymentId = create_checkout_payment($pdo, $orderId, $amount, $method);
    $gatewayName = payment_gateway_name();
    $gatewayData = [];
    $paymentStatus = 'pending';

    if ($gatewayName === 'mercadopago') {
        $adapter = new MercadoPagoAdapter();
        try {
            $order = fetch_order_for_payment($pdo, $orderId);
            if (!$order) throw new RuntimeException('Pedido não encontrado.');
            $gatewayPaymentData = array_merge($paymentData, [
                'method' => $method,
                'idempotency_key' => 'cm-payment-' . $paymentId,
            ]);
            $result = $adapter->createPayment($order, $customer, $gatewayPaymentData);
            $paymentStatus = (string)($result['status'] ?? 'pending');
            $gatewayData = (array)($result['raw'] ?? []);
            apply_gateway_event(
                $pdo,
                $paymentId,
                'checkout-' . $paymentId . '-' . hash('sha256', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'checkout.initiated',
                $paymentStatus,
                $result['transaction_id'] ?? null,
                $gatewayData
            );
        } catch (Throwable $e) {
            error_log('[checkout_payment] gateway initialization failed: ' . $e->getMessage());
            $paymentStatus = 'pending';
        }
    } elseif (!in_array($gatewayName, ['none', 'manual'], true)) {
        throw new RuntimeException('Gateway não suportado: ' . $gatewayName);
    }

    $order = fetch_order_for_payment($pdo, $orderId);
    $orderStatus = (string)($order['status'] ?? 'pending');
    $decision = payment_order_decision($paymentStatus, $orderStatus);
    apply_order_decision($pdo, $orderId, $decision, null);

    return build_payment_response($paymentId, $paymentStatus, $decision, $orderId, $gatewayData);
}

function apply_order_decision(PDO $pdo, int $orderId, array $decision, ?int $actorId): void
{
    $action = $decision['action'] ?? 'no_change';
    $current = fetch_order_for_payment($pdo, $orderId);
    $from = (string)($current['status'] ?? 'pending');

    if ($action === 'confirm_order' && valid_order_transition($from, 'confirmed')) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE orders SET status='confirmed', payment_status='paid' WHERE id=?")->execute([$orderId]);
            record_order_status_change($orderId, $from, 'confirmed', $actorId, 'Pagamento confirmado automaticamente.');
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    } elseif ($action === 'cancel_order' && valid_order_transition($from, 'cancelled')) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare("UPDATE orders SET status='cancelled', payment_status='cancelled' WHERE id=?")->execute([$orderId]);
            record_order_status_change($orderId, $from, 'cancelled', $actorId, 'Pagamento cancelado.');
            $pdo->commit();
        } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    } elseif ($action === 'keep_order_pending') {
        $pdo->prepare("UPDATE orders SET payment_status='failed' WHERE id=? AND status='pending'")->execute([$orderId]);
    }
}

function fetch_order_for_payment(PDO $pdo, int $orderId): array
{
    $stmt = $pdo->prepare('SELECT o.*, u.email, u.name AS user_name FROM orders o JOIN users u ON u.id=o.user_id WHERE o.id=? LIMIT 1');
    $stmt->execute([$orderId]);
    return $stmt->fetch() ?: [];
}

function resolve_payment_adapter(string $name): ?PaymentGatewayAdapter
{
    return match (strtolower(trim($name))) {
        'mercadopago' => new MercadoPagoAdapter(),
        default => null,
    };
}

function build_payment_response(int $paymentId, string $paymentStatus, array $decision, int $orderId, array $gatewayData): array
{
    $action = $decision['action'] ?? 'no_change';
    $messages = [
        'confirm_order' => 'Pagamento confirmado! Seu pedido foi confirmado.',
        'cancel_order' => 'Pagamento cancelado. O pedido foi cancelado.',
        'keep_order_pending' => 'Pagamento não aprovado. Tente novamente.',
        'review_refund' => 'Estorno recebido e aguardando revisão.',
        'no_change' => 'Pedido registrado. Aguardando confirmação do pagamento.',
    ];
    $pix = [];
    if (!empty($gatewayData['pix_qr_code'])) {
        $pix = [
            'qr_code' => $gatewayData['pix_qr_code'],
            'qr_code_base64' => $gatewayData['pix_qr_code_base64'] ?? '',
            'expires_at' => $gatewayData['pix_expiration'] ?? '',
        ];
    }

    // Never expose the gateway's raw response. Only safe, normalized fields are returned.
    $safeGateway = [
        'status' => $paymentStatus,
        'transaction_id' => $gatewayData['transaction_id'] ?? null,
    ];

    return [
        'success' => in_array($action, ['confirm_order', 'no_change'], true),
        'payment_id' => $paymentId,
        'payment_status' => $paymentStatus,
        'order_action' => $action,
        'redirect' => 'order.php?id=' . $orderId,
        'message' => $messages[$action] ?? 'Pedido registrado.',
        'gateway_data' => $safeGateway,
        'pix' => $pix,
    ];
}
