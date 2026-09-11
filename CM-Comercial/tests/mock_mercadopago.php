<?php
declare(strict_types=1);

// Deterministic provider stub for isolated MySQL webhook CI only.
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    exit;
}

if (preg_match('#/v1/payments/(\d+)$#', $path, $match)) {
    $id = $match[1];
    $status = $id === '900002' ? 'refunded' : 'approved';
    echo json_encode([
        'id' => (int)$id,
        'status' => $status,
        'external_reference' => '1',
        'transaction_amount' => 100.00,
        'point_of_interaction' => ['transaction_data' => ['qr_code' => '', 'qr_code_base64' => '']],
    ], JSON_THROW_ON_ERROR);
    exit;
}

if (preg_match('#/v1/payments$#', $path)) {
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    echo json_encode([
        'id' => 900001,
        'status' => 'pending',
        'external_reference' => (string)($body['external_reference'] ?? '1'),
        'transaction_amount' => (float)($body['transaction_amount'] ?? 100),
        'point_of_interaction' => ['transaction_data' => ['qr_code' => 'ci', 'qr_code_base64' => 'ci']],
    ], JSON_THROW_ON_ERROR);
    exit;
}

http_response_code(404);
echo json_encode(['error' => 'not_found'], JSON_THROW_ON_ERROR);
