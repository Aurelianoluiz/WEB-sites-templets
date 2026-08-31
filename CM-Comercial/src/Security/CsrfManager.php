<?php
declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class CsrfManager
{
    private const SESSION_KEY = '_cm_csrf';
    private const TOKEN_BYTES = 32;

    public function token(): string
    {
        $this->ensureSession();
        $token = $_SESSION[self::SESSION_KEY] ?? null;
        if (!is_string($token) || strlen($token) !== self::TOKEN_BYTES * 2) {
            $token = bin2hex(random_bytes(self::TOKEN_BYTES));
            $_SESSION[self::SESSION_KEY] = $token;
        }
        return $token;
    }

    public function validate(?string $token): bool
    {
        $this->ensureSession();
        $expected = $_SESSION[self::SESSION_KEY] ?? '';
        return is_string($token)
            && is_string($expected)
            && $token !== ''
            && hash_equals($expected, $token);
    }

    public function requireValid(?string $token = null): void
    {
        $token ??= $_POST['_csrf'] ?? $_POST['csrf'] ?? null;
        if (!$this->validate($token)) {
            throw new RuntimeException('Invalid CSRF token.');
        }
    }

    private function ensureSession(): void
    {
        if (session_status() !== PHP_SESSION_ACTIVE && !session_start()) {
            throw new RuntimeException('Unable to start secure session.');
        }
    }
}
