<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/mp_webhook_signature.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException('FAIL: ' . $message);
}

$body = json_encode(['data' => ['id' => '12345']], JSON_UNESCAPED_SLASHES);
$secret = 'test-secret';
$now = 1_700_000_000;
$requestId = 'request-123';
$ts = (string)$now;
$manifest = 'id:12345;request-id:' . $requestId . ';ts:' . $ts . ';';
$v1 = hash_hmac('sha256', $manifest, $secret);
$headers = [
    'x-signature' => 'ts=' . $ts . ',v1=' . $v1,
    'x-request-id' => $requestId,
];

assert_true(verify_mp_webhook_signature($body, $headers, $secret, $now, 300), 'current valid signature');
assert_true(verify_mp_webhook_signature($body, $headers, $secret, $now + 300, 300), 'boundary timestamp accepted');
assert_true(!verify_mp_webhook_signature($body, $headers, $secret, $now + 301, 300), 'expired timestamp rejected');
assert_true(!verify_mp_webhook_signature($body, $headers, $secret, $now - 301, 300), 'future/stale timestamp rejected');
assert_true(!verify_mp_webhook_signature($body, $headers, 'wrong-secret', $now, 300), 'wrong secret rejected');

echo "PASS: webhook signature runtime freshness\n";
