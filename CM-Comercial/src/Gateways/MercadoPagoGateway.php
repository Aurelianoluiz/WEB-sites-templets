<?php
declare(strict_types=1);

namespace App\Gateways;

use InvalidArgumentException;
use RuntimeException;

final class MercadoPagoGateway implements PaymentGatewayInterface
{
    private const API_BASE = 'https://api.mercadopago.com/v1';
    private readonly string $accessToken;

    public function __construct(string $accessToken = '')
    {
        $token = $accessToken !== '' ? $accessToken : trim((string)(getenv('MP_ACCESS_TOKEN') ?: ''));
        if ($token === '') throw new RuntimeException('MP_ACCESS_TOKEN is not configured.');
        $this->accessToken = $token;
    }

    public function createPixCharge(int $orderId, float $amount, string $payerEmail, string $payerName, string $idempotencyKey): array
    {
        $this->validateMoney($amount);
        if ($orderId < 1 || !filter_var($payerEmail, FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid PIX charge data.');
        $this->validateIdempotencyKey($idempotencyKey);

        $parts = preg_split('/\s+/', trim($payerName)) ?: ['Cliente'];
        $firstName = array_shift($parts) ?: 'Cliente';
        $lastName = trim(implode(' ', $parts));
        $expiresAt = (new \DateTimeImmutable('+30 minutes'))->format(DATE_ATOM);
        $body = [
            'transaction_amount' => round($amount, 2),
            'description' => 'Pedido #' . $orderId . ' — CM Comercial',
            'external_reference' => (string)$orderId,
            'payment_method_id' => 'pix',
            'date_of_expiration' => $expiresAt,
            'payer' => ['email' => $payerEmail, 'first_name' => $firstName, 'last_name' => $lastName],
        ];

        $response = $this->request('POST', '/payments', $body, $idempotencyKey);
        $transactionId = (string)($response['id'] ?? '');
        if ($transactionId === '') throw new RuntimeException('Mercado Pago did not return a payment id.');
        $td = (array)($response['point_of_interaction']['transaction_data'] ?? []);
        return [
            'provider_payment_id' => $transactionId,
            'status' => $this->normalizeStatus((string)($response['status'] ?? 'pending')),
            'transaction_id' => $transactionId,
            'pix_qr_code' => (string)($td['qr_code'] ?? ''),
            'pix_qr_code_base64' => (string)($td['qr_code_base64'] ?? ''),
            'pix_expires_at' => $expiresAt,
            'raw' => $response,
        ];
    }

    public function getPayment(string $paymentId): array
    {
        if (!ctype_digit($paymentId) || $paymentId === '0') throw new InvalidArgumentException('Invalid Mercado Pago payment id.');
        $response = $this->request('GET', '/payments/' . rawurlencode($paymentId));
        $td = (array)($response['point_of_interaction']['transaction_data'] ?? []);
        return [
            'provider_payment_id' => $paymentId,
            'status' => $this->normalizeStatus((string)($response['status'] ?? 'pending')),
            'transaction_id' => (string)($response['id'] ?? $paymentId),
            'pix_qr_code' => (string)($td['qr_code'] ?? ''),
            'pix_qr_code_base64' => (string)($td['qr_code_base64'] ?? ''),
            'pix_expires_at' => isset($response['date_of_expiration']) ? (string)$response['date_of_expiration'] : null,
            'raw' => $response,
        ];
    }

    public function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'pending', 'in_process', 'in_mediation' => 'pending',
            'approved' => 'paid',
            'authorized' => 'authorized',
            'rejected' => 'failed',
            'cancelled', 'canceled' => 'cancelled',
            'refunded', 'charged_back' => 'refunded',
            default => throw new InvalidArgumentException('Unsupported Mercado Pago status: ' . $status),
        };
    }

    private function validateMoney(float $amount): void
    {
        if (!is_finite($amount) || $amount <= 0) throw new InvalidArgumentException('Invalid payment amount.');
    }

    private function validateIdempotencyKey(string $key): void
    {
        if (!preg_match('/^[A-Za-z0-9._:-]{1,255}$/', $key)) throw new InvalidArgumentException('Invalid idempotency key.');
    }

    private function request(string $method, string $path, array $body = [], string $idempotencyKey = ''): array
    {
        $handle = curl_init(self::API_BASE . $path);
        if ($handle === false) throw new RuntimeException('Unable to initialize HTTP client.');
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $this->accessToken];
        if ($idempotencyKey !== '') $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => 20, CURLOPT_FOLLOWLOCATION => false]);
        if ($method === 'POST') curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        $raw = curl_exec($handle);
        $code = (int)curl_getinfo($handle, CURLINFO_HTTP_CODE);
        $error = curl_error($handle);
        curl_close($handle);
        if ($raw === false || $error !== '') throw new RuntimeException('Mercado Pago HTTP request failed.');
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) throw new RuntimeException('Mercado Pago returned invalid JSON.');
        if ($code < 200 || $code >= 300) throw new RuntimeException('Mercado Pago returned HTTP ' . $code . '.');
        return $data;
    }
}
