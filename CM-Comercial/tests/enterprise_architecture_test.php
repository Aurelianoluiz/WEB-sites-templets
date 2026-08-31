<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$required = [
    $root . '/composer.json',
    $root . '/bootstrap.php',
    $root . '/config/database.php',
    $root . '/database/schema.sql',
    $root . '/src/Core/Container.php',
    $root . '/src/Security/CsrfManager.php',
    $root . '/src/Security/WebhookValidator.php',
    $root . '/src/Gateways/PaymentGatewayInterface.php',
    $root . '/src/Gateways/MercadoPagoGateway.php',
    $root . '/src/Services/PaymentService.php',
    $root . '/assets/css/app.css',
    $root . '/assets/js/checkout.js',
    $root . '/checkout.php',
    $root . '/api/order_status.php',
    $root . '/webhooks/webhook_handler.php',
];
foreach ($required as $file) {
    if (!is_file($file)) {
        fwrite(STDERR, 'FAIL: missing ' . $file . PHP_EOL);
        exit(1);
    }
}

$composer = json_decode((string)file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
if (($composer['autoload']['psr-4']['App\\'] ?? null) !== 'src/') {
    fwrite(STDERR, "FAIL: App\\ PSR-4 mapping missing" . PHP_EOL);
    exit(1);
}

$schema = (string)file_get_contents($root . '/database/schema.sql');
foreach (['products','orders','order_items','payment_transactions','FOR UPDATE'] as $needle) {
    if (!str_contains($schema, $needle)) {
        fwrite(STDERR, 'FAIL: schema missing ' . $needle . PHP_EOL);
        exit(1);
    }
}

$csrf = (string)file_get_contents($root . '/src/Security/CsrfManager.php');
$webhook = (string)file_get_contents($root . '/src/Security/WebhookValidator.php');
$js = (string)file_get_contents($root . '/assets/js/checkout.js');
foreach ([[$csrf,'hash_equals'],[$webhook,'hash_hmac'],[$webhook,'maxSkew'],[$js,"setTimeout(tick"]] as [$source,$needle]) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, 'FAIL: missing architecture control ' . $needle . PHP_EOL);
        exit(1);
    }
}

echo "PASS: enterprise architecture surface" . PHP_EOL;
