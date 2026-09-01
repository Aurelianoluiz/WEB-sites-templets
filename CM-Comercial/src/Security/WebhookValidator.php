<?php
declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class WebhookValidator
{
    public function __construct(
        private readonly string $secret,
        private readonly int $maxSkew = 300
    ) {
        if ($this->secret === '') {
            throw new RuntimeException('Webhook secret is not configured.');
        }
        if ($this->maxSkew < 1) {
            throw new RuntimeException('Webhook skew window must be positive.');
        }
    }

    /**
     * Mercado Pago x-signature format: ts=<unix>;v1=<hex-hmac>.
     * Current webhook schema signs the top-level notification id:
     * id:<notification-id>;request-id:<x-request-id>;ts:<ts>;
     * Legacy fixtures without a top-level id fall back to data.id.
     */
    public function validate(string $rawBody, array $headers): bool
    {
        $signature = strtolower(trim((string)$this->header($headers, 'x-signature')));
        $requestId = trim((string)$this->header($headers, 'x-request-id'));
        $ts = $this->extractPart($signature, 'ts');
        $v1 = strtolower($this->extractPart($signature, 'v1'));
        $body = json_decode($rawBody, true);
        $notificationId = is_array($body) ? trim((string)($body['id'] ?? '')) : '';
        $dataId = is_array($body) ? trim((string)($body['data']['id'] ?? '')) : '';
        $manifestId = $notificationId !== '' ? $notificationId : $dataId;

        if ($ts === '' || $v1 === '' || $requestId === '' || $manifestId === '' || !ctype_digit($ts)) {
            return false;
        }
        if (!preg_match('/^[a-f0-9]{64}$/', $v1)) {
            return false;
        }

        $now = time();
        if (abs($now - (int)$ts) > $this->maxSkew) {
            return false;
        }

        $manifest = 'id:' . $manifestId . ';request-id:' . $requestId . ';ts:' . $ts . ';';
        $expected = hash_hmac('sha256', $manifest, $this->secret);
        return hash_equals($expected, $v1);
    }

    private function extractPart(string $signature, string $name): string
    {
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if (trim($key) === $name) return trim($value);
        }
        return '';
    }

    private function header(array $headers, string $name): string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string)$key) === strtolower($name)) {
                return is_array($value) ? '' : (string)$value;
            }
        }
        return '';
    }
}
