<?php
declare(strict_types=1);

$config = (string)file_get_contents(__DIR__ . '/../config.php');
$checks = [
    'strict_session_mode' => str_contains($config, "ini_set('session.use_strict_mode', '1')"),
    'cookies_only' => str_contains($config, "ini_set('session.use_only_cookies', '1')"),
    'http_only' => str_contains($config, "'httponly' => true"),
    'same_site' => str_contains($config, "'samesite' => 'Lax'"),
    'idle_timeout' => str_contains($config, 'SESSION_TIMEOUT') && str_contains($config, '_last_activity'),
    'session_rotation' => str_contains($config, 'session_regenerate_id(true)'),
    'production_secure_cookie' => str_contains($config, "($APP_ENV === 'production')"),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
if ($failed) {
    fwrite(STDERR, 'FAILED_CHECKS: ' . implode(', ', $failed) . "\n");
    exit(1);
}
echo "SESSION_SECURITY_READY\n";
