<?php
declare(strict_types=1);

$container = require dirname(__DIR__) . '/bootstrap.php';

use App\Config\Database;
use App\Gateways\MercadoPagoGateway;
use App\Security\WebhookValidator;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    echo json_encode(['received' => false, 'error' => 'method_not_allowed']);
    exit;
}

$rawBody = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];

try {
    /** @var WebhookValidator $validator */
    $validator = $container->get(WebhookValidator::class);
    if (!$validator->validate($rawBody, $headers)) {
        http_response_code(401);
        echo json_encode(['received' => false, 'error' => 'invalid_signature']);
        exit;
    }

    $payload = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
    $dataId = trim((string)($payload['data']['id'] ?? ''));
    if (!ctype_digit($dataId) || $dataId === '0') throw new InvalidArgumentException('Invalid data.id.');

    /** @var MercadoPagoGateway $gateway */
    $gateway = $container->get(MercadoPagoGateway::class);
    $gatewayPayment = $gateway->getPayment($dataId);
    $externalReference = trim((string)($gatewayPayment['raw']['external_reference'] ?? ''));
    if (!ctype_digit($externalReference) || $externalReference === '0') throw new InvalidArgumentException('Invalid external_reference.');

    $eventType = trim((string)($payload['type'] ?? $payload['action'] ?? 'payment'));
    $action = trim((string)($payload['action'] ?? ''));
    $database = $container->get(Database::class);

    $database->transaction(function (PDO $pdo) use ($externalReference, $dataId, $gatewayPayment, $eventType, $action, $rawBody): void {
        $paymentQuery = $pdo->prepare('SELECT id,order_id,status,amount,webhook_event_id FROM payment_transactions WHERE order_id=? AND provider=\'mercadopago\' LIMIT 1 FOR UPDATE');
        $paymentQuery->execute([(int)$externalReference]);
        $payment = $paymentQuery->fetch();
        if ($payment === false) throw new RuntimeException('Internal payment transaction not found.');

        $gatewayAmount = round((float)($gatewayPayment['raw']['transaction_amount'] ?? 0), 2);
        if ($gatewayAmount <= 0 || $gatewayAmount !== round((float)$payment['amount'], 2)) throw new RuntimeException('Payment amount mismatch.');

        // Transport identifiers are used by signature validation, not business identity.
        $eventId = hash('sha256', implode('|', ['mp', $eventType, $action, $dataId, $gatewayPayment['status'], $gatewayPayment['transaction_id']]));
        if ((string)($payment['webhook_event_id'] ?? '') === $eventId) return;

        $oldStatus = (string)$payment['status'];
        $newStatus = $gatewayPayment['status'];
        $allowed = [
            'pending' => ['authorized','paid','failed','cancelled'],
            'authorized' => ['paid','failed','cancelled'],
            'paid' => ['refunded'],
            'failed' => [],
            'cancelled' => [],
            'refunded' => [],
        ];
        if ($oldStatus !== $newStatus && !in_array($newStatus, $allowed[$oldStatus] ?? [], true)) {
            throw new RuntimeException('Invalid payment state transition.');
        }

        $update = $pdo->prepare('UPDATE payment_transactions SET provider_payment_id=?,status=?,webhook_event_id=?,last_webhook_at=CURRENT_TIMESTAMP(6),gateway_payload=?,updated_at=CURRENT_TIMESTAMP(6) WHERE id=?');
        $update->execute([$dataId,$newStatus,$eventId,json_encode($gatewayPayment['raw'],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR),(int)$payment['id']]);

        if ($newStatus === 'paid' || $newStatus === 'authorized') {
            $pdo->prepare('UPDATE orders SET payment_status=?,status=CASE WHEN status=\'pending\' THEN \'confirmed\' ELSE status END WHERE id=?')->execute([$newStatus,(int)$payment['order_id']]);
        } elseif (in_array($newStatus,['failed','cancelled'],true) && !in_array($oldStatus,['failed','cancelled','refunded'],true)) {
            $items=$pdo->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=?'); $items->execute([(int)$payment['order_id']]);
            $restore=$pdo->prepare('UPDATE products SET stock_quantity=stock_quantity+? WHERE id=?');
            while($item=$items->fetch()) $restore->execute([(int)$item['quantity'],(int)$item['product_id']]);
            $pdo->prepare('UPDATE orders SET payment_status=?,status=\'cancelled\' WHERE id=? AND status<>\'delivered\'')->execute([$newStatus,(int)$payment['order_id']]);
        } elseif ($newStatus === 'refunded' && !in_array($oldStatus,['cancelled','failed','refunded'],true)) {
            $items=$pdo->prepare('SELECT product_id,quantity FROM order_items WHERE order_id=?'); $items->execute([(int)$payment['order_id']]);
            $restore=$pdo->prepare('UPDATE products SET stock_quantity=stock_quantity+? WHERE id=?');
            while($item=$items->fetch()) $restore->execute([(int)$item['quantity'],(int)$item['product_id']]);
            $pdo->prepare('UPDATE orders SET payment_status=? WHERE id=?')->execute([$newStatus,(int)$payment['order_id']]);
        }

        $audit=$pdo->prepare('INSERT INTO payment_audit_log(payment_transaction_id,event_type,old_status,new_status,actor,payload) VALUES(?,?,?,?,\'mercadopago-webhook\',?)');
        $audit->execute([(int)$payment['id'],$eventType,$oldStatus,$newStatus,json_encode(['event_id'=>$eventId,'body'=>$rawBody],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)]);
    });

    http_response_code(200);
    echo json_encode(['received' => true]);
} catch (InvalidArgumentException $e) {
    http_response_code(422);
    echo json_encode(['received' => false, 'error' => 'invalid_payload']);
} catch (Throwable $e) {
    error_log('[cm-comercial webhook] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['received' => false, 'error' => 'processing_failed']);
}
