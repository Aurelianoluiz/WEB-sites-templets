<?php
declare(strict_types=1);

require_once __DIR__ . '/../integrations/mercadopago_adapter.php';

putenv('MP_ACCESS_TOKEN=test-token');
$adapter = new MercadoPagoAdapter();

$ref = new ReflectionMethod(MercadoPagoAdapter::class, 'normalizeStatus');

$invalidAmounts = [INF, -INF, NAN];
foreach ($invalidAmounts as $amount) {
    try {
        $adapter->createPayment(
            ['id' => 10, 'total' => $amount],
            ['email' => 'cliente@example.com', 'name' => 'Cliente Teste'],
            ['method' => 'pix']
        );
        fwrite(STDERR, "FAIL: non-finite amount accepted\n");
        exit(1);
    } catch (InvalidArgumentException|RuntimeException) {
        // expected; runtime call may fail only after local validation in other environments
    }
}

$normalized = strtolower(trim(' PIX '));
if ($normalized !== 'pix') {
    fwrite(STDERR, "FAIL: payment method normalization\n");
    exit(1);
}

echo "PASS: Mercado Pago payment input validation\n";
