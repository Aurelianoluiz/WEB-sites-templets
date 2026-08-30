<?php
declare(strict_types=1);

$envExample = (string)file_get_contents(__DIR__ . '/../.env.example');
$handler = (string)file_get_contents(__DIR__ . '/../webhooks/webhook_handler.php');
$signature = (string)file_get_contents(__DIR__ . '/../includes/mp_webhook_signature.php');

$required = ['MP_ACCESS_TOKEN', 'MP_WEBHOOK_SECRET', 'MP_WEBHOOK_MAX_SKEW'];
foreach ($required as $name) {
    if (!str_contains($envExample, $name . '=')) {
        fwrite(STDERR, "FAIL: .env.example missing $name\n");
        exit(1);
    }
}

foreach (['MP_WEBHOOK_SECRET'] as $name) {
    if (!str_contains($handler, "getenv('$name')")) {
        fwrite(STDERR, "FAIL: webhook handler missing $name\n");
        exit(1);
    }
}

if (!str_contains($signature, 'MP_WEBHOOK_MAX_SKEW')) {
    fwrite(STDERR, "FAIL: signature helper missing MP_WEBHOOK_MAX_SKEW\n");
    exit(1);
}

if (!preg_match('/MP_WEBHOOK_MAX_SKEW=([0-9]+)/', $envExample, $m) || (int)$m[1] < 1) {
    fwrite(STDERR, "FAIL: invalid documented MP_WEBHOOK_MAX_SKEW default\n");
    exit(1);
}

if (preg_match('/MP_(?:ACCESS_TOKEN|WEBHOOK_SECRET)=([^\r\n]*)/', $envExample, $m) && trim($m[1]) !== '') {
    fwrite(STDERR, "FAIL: .env.example must not contain a real payment secret\n");
    exit(1);
}

echo "PASS: payment configuration surface\n";
