<?php
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/payment_core.php';
require_once __DIR__ . '/../includes/payment_order_policy.php';
require_once __DIR__ . '/../includes/checkout_payment.php';
require_once __DIR__ . '/../integrations/payment_service.php';
require_once __DIR__ . '/../integrations/payment_adapter.php';
require_once __DIR__ . '/../integrations/mercadopago_adapter.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    http_response_code(405);
    exit('Method Not Allowed');
}

$rawBody = (string)file_get_contents('php://input');
$headers = function_exists('getallheaders') ? (getallheaders() ?: []) : [];
$normalized = [];
foreach ($headers as $key => $value) $normalized[strtolower((string)$key)] = trim((string)$value);
$headers = $normalized;

$secret = trim((string)(getenv('MP_WEBHOOK_SECRET') ?: ''));
$env = strtolower(trim((string)(getenv('APP_ENV') ?: 'production')));
if ($secret === '') {
    error_log('[webhook] MP_WEBHOOK_SECRET is not configured.');
    http_response_code($env === 'production' ? 503 : 401);
    exit('Webhook not configured');
}

if (!verify_mp_webhook_signature($rawBody, $headers, $secret)) {
    http_response_code(401);
    exit('Unauthorized');
}

try {
    process_gateway_webhook($rawBody, $headers);
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(200);
    echo json_encode(['received' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[webhook] processing failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['received' => false], JSON_UNESCAPED_UNICODE);
}

function verify_mp_webhook_signature(string $body, array $headers, string $secret): bool
{
    $signature = $headers['x-signature'] ?? '';
    $requestId = $headers['x-request-id'] ?? '';
    if ($signature === '' || $requestId === '') return false;

    $parts = [];
    foreach (explode(',', $signature) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $parts[strtolower(trim($key))] = trim($value);
    }
    $ts = $parts['ts'] ?? '';
    $v1 = $parts['v1'] ?? '';
    $dataId = '';
    $decoded = json_decode($body, true);
    if (is_array($decoded)) $dataId = (string)($decoded['data']['id'] ?? '');
    if ($dataId === '') $dataId = (string)($_GET['data.id'] ?? $_GET['id'] ?? '');
    if ($ts === '' || $v1 === '' || $dataId === '') return false;

    $manifest = 'id:' . $dataId . ';request-id:' . $requestId . ';ts:' . $ts . ';';
    $expected = hash_hmac('sha256', $manifest, $secret);
    return hash_equals($expected, $v1);
}

function process_gateway_webhook(string $rawBody, array $headers): void
{
    if (payment_gateway_name() !== 'mercadopago') return;
    $adapter = resolve_payment_adapter('mercadopago');
    if ($adapter === null) throw new RuntimeException('Mercado Pago adapter indisponível.');
    $event = $adapter->parseWebhook($rawBody, $headers);

    $orderId = (int)($event['payment_id'] ?? 0);
    if ($orderId < 1) throw new RuntimeException('external_reference inválida.');

    $pdo = db();
    ensure_payment_schema($pdo);
    $stmt = $pdo->prepare('SELECT id, amount FROM payments WHERE order_id=? LIMIT 1');
    $stmt->execute([$orderId]);
    $paymentRow = $stmt->fetch();
    $paymentId = (int)($paymentRow['id'] ?? 0);
    if ($paymentId < 1) throw new RuntimeException('Pagamento interno não encontrado.');

    // Never change the internal payment state from a signed webhook whose
    // provider amount does not match the amount created internally.
    $gatewayAmount = isset($event['raw']['transaction_amount']) ? (float)$event['raw']['transaction_amount'] : null;
    $internalAmount = round((float)($paymentRow['amount'] ?? 0), 2);
    if ($gatewayAmount === null || round($gatewayAmount, 2) !== $internalAmount) {
        throw new RuntimeException('Valor do pagamento divergente da cobrança interna.');
    }

    $isNewEvent = apply_gateway_event(
        $pdo,
        $paymentId,
        (string)$event['event_id'],
        (string)$event['type'],
        (string)$event['status'],
        $event['transaction_id'] ?? null,
        (array)($event['raw'] ?? []),
        static function (PDO $transactionalPdo, int $paymentId, string $paymentStatus) use ($orderId): void {
            $orderStmt = $transactionalPdo->prepare('SELECT status FROM orders WHERE id=?');
            $orderStmt->execute([$orderId]);
            $orderStatus = (string)($orderStmt->fetchColumn() ?: 'pending');
            $decision = payment_order_decision($paymentStatus, $orderStatus);
            apply_order_decision($transactionalPdo, $orderId, $decision, null);
            $transactionalPdo->prepare('UPDATE orders SET payment_status=? WHERE id=?')->execute([$paymentStatus, $orderId]);
        }
    );

    // Exact webhook retries are acknowledged but must not repeat downstream
    // order-state updates or any future downstream side effects.
    if (!$isNewEvent) return;
}
