<?php
declare(strict_types=1);

require_once __DIR__ . '/payment_adapter.php';

final class MercadoPagoAdapter implements PaymentGatewayAdapter
{
    private const API_BASE = 'https://api.mercadopago.com/v1';
    private string $accessToken;

    public function __construct()
    {
        $token = getenv('MP_ACCESS_TOKEN');
        if ($token === false || trim($token) === '') {
            throw new RuntimeException('MP_ACCESS_TOKEN não configurado.');
        }
        $this->accessToken = trim($token);
    }

    public function createPayment(array $order, array $customer, array $paymentData): array
    {
        $method = (string)($paymentData['method'] ?? 'pix');
        $amount = round((float)($order['total'] ?? 0), 2);
        if ($amount <= 0) throw new InvalidArgumentException('Valor de pagamento inválido.');

        $email = trim((string)($customer['email'] ?? $order['email'] ?? ''));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('E-mail do pagador inválido.');
        }

        $body = [
            'transaction_amount' => $amount,
            'description' => 'Pedido #' . (int)$order['id'] . ' — CM Comercial',
            'external_reference' => (string)(int)$order['id'],
            'payer' => [
                'email' => $email,
                'first_name' => $this->firstName((string)($customer['name'] ?? $order['customer_name'] ?? 'Cliente')),
                'last_name' => $this->lastName((string)($customer['name'] ?? $order['customer_name'] ?? '')),
            ],
        ];

        if ($method === 'pix') {
            $body['payment_method_id'] = 'pix';
            $body['date_of_expiration'] = date('c', strtotime('+30 minutes'));
        } elseif ($method === 'credit_card') {
            $token = trim((string)($paymentData['card_token'] ?? ''));
            $paymentMethodId = trim((string)($paymentData['payment_method_id'] ?? ''));
            $installments = (int)($paymentData['installments'] ?? 1);
            if ($token === '' || $paymentMethodId === '' || $installments < 1 || $installments > 24) {
                throw new InvalidArgumentException('Dados de cartão tokenizado inválidos.');
            }
            $body['token'] = $token;
            $body['payment_method_id'] = $paymentMethodId;
            $body['installments'] = $installments;
            if (!empty($paymentData['issuer_id'])) $body['issuer_id'] = (string)$paymentData['issuer_id'];
        } else {
            throw new InvalidArgumentException('Método de pagamento não suportado.');
        }

        $key = 'cm-order-' . (int)$order['id'] . '-' . hash('sha256', $method . '|' . $amount);
        $response = $this->request('POST', '/payments', $body, $key);
        $status = $this->normalizeStatus((string)($response['status'] ?? 'pending'));

        $raw = $response;
        $td = $response['point_of_interaction']['transaction_data'] ?? [];
        if ($td) {
            $raw['pix_qr_code'] = $td['qr_code'] ?? '';
            $raw['pix_qr_code_base64'] = $td['qr_code_base64'] ?? '';
            $raw['pix_expiration'] = $body['date_of_expiration'] ?? '';
        }

        return [
            'payment_id' => (string)($response['id'] ?? ''),
            'status' => $status,
            'transaction_id' => isset($response['id']) ? (string)$response['id'] : null,
            'raw' => $raw,
        ];
    }

    public function parseWebhook(string $rawBody, array $headers): array
    {
        $data = json_decode($rawBody, true);
        if (!is_array($data)) throw new InvalidArgumentException('Webhook JSON inválido.');

        $type = (string)($data['type'] ?? '');
        $action = (string)($data['action'] ?? '');
        if ($type === '' && $action !== '') $type = $action;
        $dataId = (string)($data['data']['id'] ?? '');
        if ($dataId === '') throw new InvalidArgumentException('Webhook sem data.id.');

        $payment = $this->get('/payments/' . rawurlencode($dataId));
        $status = $this->normalizeStatus((string)($payment['status'] ?? 'pending'));
        $transactionId = (string)($payment['id'] ?? $dataId);

        // Keep retries of the same notification idempotent while allowing
        // legitimate lifecycle changes for the same payment to be distinct.
        $eventFingerprint = implode('|', [
            'mp',
            $type,
            $action,
            $dataId,
            $status,
            $transactionId,
        ]);
        $eventId = hash('sha256', $eventFingerprint);

        return [
            'event_id' => $eventId,
            'type' => $type,
            'payment_id' => (string)($payment['external_reference'] ?? ''),
            'status' => $status,
            'transaction_id' => $transactionId,
            'raw' => $payment,
        ];
    }

    public function queryPayment(string $transactionId): array
    {
        if (!ctype_digit($transactionId) || $transactionId === '0') throw new InvalidArgumentException('transaction_id inválido.');
        $payment = $this->get('/payments/' . $transactionId);
        return [
            'status' => $this->normalizeStatus((string)($payment['status'] ?? 'pending')),
            'transaction_id' => $transactionId,
            'raw' => $payment,
        ];
    }

    private function request(string $method, string $path, array $body = [], string $idempotencyKey = ''): array
    {
        $ch = curl_init(self::API_BASE . $path);
        $headers = ['Accept: application/json', 'Content-Type: application/json', 'Authorization: Bearer ' . $this->accessToken];
        if ($idempotencyKey !== '') $headers[] = 'X-Idempotency-Key: ' . $idempotencyKey;
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 20,
        ]);
        if ($method === 'POST') curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($raw === false || $err !== '') throw new RuntimeException('Mercado Pago HTTP error.');
        $data = json_decode((string)$raw, true);
        if (!is_array($data)) throw new RuntimeException('Resposta inválida do Mercado Pago.');
        if ($code < 200 || $code >= 300) throw new RuntimeException('Mercado Pago retornou HTTP ' . $code . '.');
        return $data;
    }

    private function get(string $path): array { return $this->request('GET', $path); }
    private function normalizeStatus(string $status): string
    {
        return match (strtolower($status)) {
            'approved' => 'paid', 'authorized' => 'authorized', 'rejected' => 'failed',
            'cancelled', 'canceled' => 'cancelled', 'refunded', 'charged_back' => 'refunded',
            default => 'pending',
        };
    }
    private function firstName(string $name): string { return trim(explode(' ', trim($name))[0] ?? 'Cliente'); }
    private function lastName(string $name): string { $p = preg_split('/\s+/', trim($name)); array_shift($p); return trim(implode(' ', $p)); }
}
