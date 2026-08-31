<?php
declare(strict_types=1);

namespace App\Gateways;

interface PaymentGatewayInterface
{
    public function createPixCharge(int $orderId, float $amount, string $payerEmail, string $payerName, string $idempotencyKey): array;

    public function getPayment(string $paymentId): array;

    public function normalizeStatus(string $status): string;
}
