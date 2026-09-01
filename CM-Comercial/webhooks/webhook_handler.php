<?php
declare(strict_types=1);

$container = require dirname(__DIR__) . '/bootstrap.php';

use App\Repositories\PaymentAuditRepositoryInterface;
use App\Repositories\PaymentTransactionRepositoryInterface;
use App\Security\WebhookValidator;
use App\Services\PaymentService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$respond = static function (int $status, array $body): never {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    $respond(405, ['received' => false, 'error' => 'method_not_allowed']);
}

$rawBody = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

try {
    /** @var WebhookValidator $validator */
    $validator = $container->get(WebhookValidator::class);
    if (!$validator->validate($rawBody, $headers)) {
        $respond(401, ['received' => false, 'error' => 'invalid_signature']);
    }

    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($payload)) {
        throw new InvalidArgumentException('Invalid webhook payload.');
    }

    $dataId = trim((string)($payload['data']['id'] ?? ''));
    if (!ctype_digit($dataId) || $dataId === '0') {
        throw new InvalidArgumentException('Invalid data.id.');
    }

    $eventType = trim((string)($payload['type'] ?? 'payment'));
    $action = trim((string)($payload['action'] ?? 'payment.updated'));
    if ($eventType === '' || $action === '') {
        throw new InvalidArgumentException('Invalid webhook event type.');
    }

    // Mercado Pago documents the top-level notification id as the unique event id.
    // The deterministic fallback keeps idempotency safe for legacy/simulated events
    // that omit it without persisting the raw notification body.
    $notificationId = trim((string)($payload['id'] ?? ''));
    $idempotencyKey = $notificationId !== ''
        ? 'mp:webhook:' . $notificationId
        : 'mp:webhook:' . hash('sha256', implode('|', [$eventType, $action, $dataId]));

    /** @var PaymentService $paymentService */
    $paymentService = $container->get(PaymentService::class);
    /** @var PaymentAuditRepositoryInterface $auditRepository */
    $auditRepository = $container->get(PaymentAuditRepositoryInterface::class);
    /** @var PaymentTransactionRepositoryInterface $transactionRepository */
    $transactionRepository = $container->get(PaymentTransactionRepositoryInterface::class);

    $gatewayPayment = $paymentService->getWebhookPayment($dataId);
    $gatewayStatus = trim((string)($gatewayPayment['status'] ?? ''));
    $gatewayProviderId = trim((string)($gatewayPayment['provider_payment_id'] ?? $dataId));
    $externalReference = trim((string)($gatewayPayment['raw']['external_reference'] ?? ''));
    $gatewayAmount = round((float)($gatewayPayment['raw']['transaction_amount'] ?? 0), 2);

    if (!in_array($gatewayStatus, ['pending', 'authorized', 'paid', 'failed', 'cancelled', 'refunded'], true)) {
        throw new InvalidArgumentException('Unsupported normalized payment status.');
    }
    if ($gatewayProviderId === '' || !ctype_digit($gatewayProviderId)) {
        throw new InvalidArgumentException('Invalid provider payment id.');
    }
    if ($externalReference === '') {
        throw new InvalidArgumentException('Missing payment external reference.');
    }
    if ($gatewayAmount <= 0) {
        throw new InvalidArgumentException('Invalid gateway amount.');
    }

    $safeContext = [
        'notification_id' => $notificationId !== '' ? $notificationId : null,
        'action' => substr($action, 0, 100),
        'type' => substr($eventType, 0, 100),
        'data_id' => $dataId,
        'live_mode' => isset($payload['live_mode']) ? (bool)$payload['live_mode'] : null,
        'provider_status' => $gatewayStatus,
    ];

    $result = $auditRepository->transaction(function () use (
        $auditRepository,
        $transactionRepository,
        $externalReference,
        $gatewayProviderId,
        $gatewayStatus,
        $gatewayAmount,
        $idempotencyKey,
        $eventType,
        $safeContext
    ): array {
        // Fast idempotency path. The unique index remains the final database guard.
        if ($auditRepository->isEventProcessed($idempotencyKey)) {
            return ['duplicate' => true];
        }

        $payment = $transactionRepository->findByExternalReference($externalReference, true);
        if ($payment === null) {
            throw new RuntimeException('Internal payment transaction not found.');
        }

        $expectedAmount = round((float)$payment['amount'], 2);
        if ($expectedAmount !== $gatewayAmount) {
            throw new RuntimeException('Payment amount mismatch.');
        }

        $transactionId = (int)$payment['id'];
        $oldStatus = (string)$payment['status'];
        $orderId = (int)$payment['order_id'];

        $auditId = $auditRepository->logEvent([
            'payment_transaction_id' => $transactionId,
            'event_type' => 'webhook.' . $eventType . '_updated',
            'old_status' => $oldStatus,
            'new_status' => $gatewayStatus,
            'actor' => 'webhook:mercadopago',
            'idempotency_key' => $idempotencyKey,
            'payload' => array_merge($safeContext, [
                'transaction_id' => $transactionId,
                'order_id' => $orderId,
            ]),
        ]);

        // A concurrent duplicate can return the existing audit id after the first
        // transaction commits. Re-read the locked payment and avoid reapplying effects.
        $current = $transactionRepository->findByExternalReference($externalReference, true);
        if ($current === null) {
            throw new RuntimeException('Payment transaction disappeared during webhook processing.');
        }

        if ((string)$current['status'] === $gatewayStatus) {
            return [
                'duplicate' => true,
                'transaction_id' => $transactionId,
                'order_id' => $orderId,
                'audit_id' => $auditId,
            ];
        }

        $transition = $transactionRepository->applyWebhookTransition(
            $transactionId,
            $gatewayProviderId,
            $gatewayStatus
        );

        return [
            'duplicate' => false,
            'transaction_id' => $transition['transaction_id'],
            'order_id' => $transition['order_id'],
            'old_status' => $transition['old_status'],
            'new_status' => $transition['new_status'],
            'audit_id' => $auditId,
        ];
    });

    $respond(200, [
        'received' => true,
        'idempotent' => (bool)($result['duplicate'] ?? false),
    ]);
} catch (InvalidArgumentException $e) {
    error_log('[cm-comercial webhook validation] ' . $e->getMessage());
    $respond(422, ['received' => false, 'error' => 'invalid_payload']);
} catch (Throwable $e) {
    error_log('[cm-comercial webhook] ' . $e->getMessage());
    $respond(500, ['received' => false, 'error' => 'processing_failed']);
}
