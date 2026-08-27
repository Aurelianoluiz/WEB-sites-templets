<?php
declare(strict_types=1);

/** Centralized CSRF helpers for authenticated state-changing requests. */
function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['csrf'];
}

function csrf_validate(?string $token): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $expected = (string)($_SESSION['csrf'] ?? '');
    return $expected !== '' && is_string($token) && hash_equals($expected, $token);
}

function require_csrf(?string $token = null): void
{
    $token ??= $_POST['csrf'] ?? null;
    if (!csrf_validate($token)) {
        http_response_code(419);
        throw new RuntimeException('Token de segurança inválido. Recarregue a página e tente novamente.');
    }
}

/** Backward-compatible alias used by existing admin handlers. */
function verify_csrf(): void
{
    require_csrf();
}
