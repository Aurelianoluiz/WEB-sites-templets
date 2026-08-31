<?php
declare(strict_types=1);

$container = require dirname(__DIR__) . '/bootstrap.php';

use App\Config\Database;
use App\Services\PaymentService;

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$user = $_SESSION['user'] ?? null;
if (!is_array($user) || (int)($user['id'] ?? 0) < 1) {
    http_response_code(401);
    echo json_encode(['error' => 'Authentication required.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$orderId = filter_input(INPUT_GET, 'order_id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($orderId === false || $orderId === null) {
    http_response_code(422);
    echo json_encode(['error' => 'Invalid order_id.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $service = $container->get(PaymentService::class);
    $status = $service->orderStatus((int)$user['id'], $orderId);
    echo json_encode([
        'status' => $status['status'],
        'order_status' => $status['order_status'],
        'provider_payment_id' => $status['provider_payment_id'],
        'pix_expires_at' => $status['pix_expires_at'],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code($e instanceof RuntimeException ? 404 : 500);
    echo json_encode(['error' => 'Unable to read order status.'], JSON_UNESCAPED_UNICODE);
}
