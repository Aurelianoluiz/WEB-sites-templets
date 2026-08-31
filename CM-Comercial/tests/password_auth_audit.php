<?php
declare(strict_types=1);

/** Static regression checks for secure password handling. */
$security = (string)file_get_contents(__DIR__ . '/../src/Security/PasswordManager.php');
$checks = [
    'password_hash_used' => str_contains($security, 'password_hash('),
    'password_verify_used' => str_contains($security, 'password_verify('),
    'password_rehash_supported' => str_contains($security, 'password_needs_rehash('),
    'no_md5_password_storage' => !preg_match('/(?:md5|sha1)\s*\([^\n]*password/i', $security),
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
exit($failed ? 1 : 0);
