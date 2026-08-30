<?php
declare(strict_types=1);

$checkout = (string)file_get_contents(__DIR__ . '/../includes/checkout_payment.php');
$adapter = (string)file_get_contents(__DIR__ . '/../integrations/mercadopago_adapter.php');

$requiredCheckout = "'idempotency_key' => 'cm-payment-' . $paymentId";
$requiredAdapter = "\$paymentData['idempotency_key'] ?? ''";

if (!str_contains($checkout, $requiredCheckout)) {
    fwrite(STDERR, "FAIL: checkout does not scope gateway idempotency to payment attempt\n");
    exit(1);
}
if (!str_contains($adapter, $requiredAdapter)) {
    fwrite(STDERR, "FAIL: Mercado Pago adapter does not accept scoped idempotency key\n");
    exit(1);
}
if (!str_contains($adapter, "'cm-order-' . (int)\$order['id']")) {
    fwrite(STDERR, "FAIL: backward-compatible idempotency fallback is missing\n");
    exit(1);
}

echo "PASS: checkout idempotency scope\n";
