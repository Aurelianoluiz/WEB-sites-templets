<?php
declare(strict_types=1);

$envExample = (string)file_get_contents(__DIR__ . '/../.env.example');
$bootstrap = (string)file_get_contents(__DIR__ . '/../bootstrap.php');
$signature = (string)file_get_contents(__DIR__ . '/../src/Security/WebhookValidator.php');

$required = ['MP_ACCESS_TOKEN', 'MP_WEBHOOK_SECRET', 'MP_WEBHOOK_MAX_SKEW'];
foreach ($required as $name) {
    if (!str_contains($envExample, $name . '=')) {
        fwrite(STDERR, 'FAIL: .env.example missing ' . $name . PHP_EOL);
        exit(1);
    }
}

$checks = [
    'webhook_secret_wired' => str_contains($bootstrap, "getenv('MP_WEBHOOK_SECRET')"),
    'webhook_skew_wired' => str_contains($bootstrap, "getenv('MP_WEBHOOK_MAX_SKEW')"),
    'validator_requires_secret' => str_contains($signature, 'secret'),
];
foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, 'FAIL: ' . $name . PHP_EOL);
        exit(1);
    }
}

if (!preg_match('/MP_WEBHOOK_MAX_SKEW=([0-9]+)/', $envExample, $m) || (int)$m[1] < 1) {
    fwrite(STDERR, 'FAIL: invalid documented MP_WEBHOOK_MAX_SKEW default' . PHP_EOL);
    exit(1);
}

if (preg_match('/MP_(?:ACCESS_TOKEN|WEBHOOK_SECRET)=([^\r\n]*)/', $envExample, $m) && trim($m[1]) !== '') {
    fwrite(STDERR, 'FAIL: .env.example must not contain a real payment secret' . PHP_EOL);
    exit(1);
}

echo 'PASS: payment configuration surface' . PHP_EOL;
