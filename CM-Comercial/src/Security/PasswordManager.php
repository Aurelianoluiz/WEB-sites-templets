<?php
declare(strict_types=1);

namespace App\Security;

final class PasswordManager
{
    public function hash(string $password): string
    {
        if (strlen($password) < 8) {
            throw new \InvalidArgumentException('Password must contain at least 8 characters.');
        }
        $hash = password_hash($password, PASSWORD_DEFAULT);
        if ($hash === false) {
            throw new \RuntimeException('Unable to hash password.');
        }
        return $hash;
    }

    public function verify(string $password, string $hash): bool
    {
        if ($password === '' || $hash === '') return false;
        return password_verify($password, $hash);
    }

    public function needsRehash(string $hash): bool
    {
        return password_needs_rehash($hash, PASSWORD_DEFAULT);
    }
}
