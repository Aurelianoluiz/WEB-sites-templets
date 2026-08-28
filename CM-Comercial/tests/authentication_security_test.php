<?php
declare(strict_types=1);

$config = (string)file_get_contents(__DIR__ . '/../config.php');
$logout = (string)file_get_contents(__DIR__ . '/../logout.php');

$checks = [
    'strict_session_mode' => str_contains($config, "ini_set('session.use_strict_mode', '1')"),
    'session_cookie_only' => str_contains($config, "ini_set('session.use_only_cookies', '1')"),
    'session_timeout' => str_contains($config, 'SESSION_TIMEOUT'),
    'session_regeneration' => str_contains($config, 'session_regenerate_id(true)'),
    'logout_csrf' => str_contains($logout, 'require_csrf'),
    'logout_destroy' => str_contains($logout, 'session_destroy()'),
    'admin_guard' => str_contains($config, 'function require_admin()'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
