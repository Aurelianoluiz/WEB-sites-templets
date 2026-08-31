<?php
declare(strict_types=1);

$checkout = (string)file_get_contents(__DIR__ . '/../includes/checkout_payment.php');
$adapter = (string)file_get_contents(__DIR__ . '/../integrations/mercadopago_adapter.php');
$enterpriseCheckout = (string)file_get_contents(__DIR__ . '/../checkout.php');
$enterpriseGateway = (string)file_get_contents(__DIR__ . '/../src/Gateways/MercadoPagoGateway.php');

$checks = [
    'legacy_checkout_scopes_gateway_key' => str_contains($checkout, "'idempotency_key' => 'cm-payment-' . $paymentId"),
    'legacy_adapter_accepts_key' => str_contains($adapter, "\$paymentData['idempotency_key'] ?? ''"),
    'legacy_adapter_fallback' => str_contains($adapter, "'cm-order-' . (int)\$order['id']"),
    'enterprise_checkout_uses_key' => str_contains($enterpriseCheckout, 'name="idempotency_key"'),
    'enterprise_gateway_sends_key' => str_contains($enterpriseGateway, 'X-Idempotency-Key: '),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
exit($failed ? 1 : 0);
