<?php
declare(strict_types=1);

/**
 * Verify a Mercado Pago-style webhook signature.
 * Time is injectable so replay-window behavior can be tested deterministically.
 */
function verify_mp_webhook_signature(
    string $body,
    array $headers,
    string $secret,
    ?int $now = null,
    ?int $maxSkew = null
): bool {
    $signature = trim((string)($headers['x-signature'] ?? ''));
    $requestId = trim((string)($headers['x-request-id'] ?? ''));
    if ($signature === '' || $requestId === '' || trim($secret) === '') return false;

    $parts = [];
    foreach (explode(',', $signature) as $part) {
        [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
        $key = strtolower(trim($key));
        if ($key !== '') $parts[$key] = trim($value);
    }

    $ts = $parts['ts'] ?? '';
    $v1 = $parts['v1'] ?? '';
    if ($ts === '' || $v1 === '' || !ctype_digit($ts)) return false;

    $dataId = '';
    $decoded = json_decode($body, true);
    if (is_array($decoded)) $dataId = (string)($decoded['data']['id'] ?? '');
    if ($dataId === '') $dataId = (string)($_GET['data.id'] ?? $_GET['id'] ?? '');
    $dataId = trim($dataId);
    if ($dataId === '') return false;

    $skew = $maxSkew ?? (int)(getenv('MP_WEBHOOK_MAX_SKEW') ?: 300);
    if ($skew < 1) $skew = 300;
    $clock = $now ?? time();
    if (abs($clock - (int)$ts) > $skew) return false;

    $manifest = 'id:' . $dataId . ';request-id:' . $requestId . ';ts:' . $ts . ';';
    $expected = hash_hmac('sha256', $manifest, trim($secret));
    return hash_equals($expected, $v1);
}
