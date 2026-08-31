<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$files = [
    'config.php' => $root . '/config.php',
    'logout.php' => $root . '/logout.php',
    'order.php' => $root . '/order.php',
    'csrf.php' => $root . '/includes/csrf.php',
];
$checks = [];
foreach ($files as $name => $path) $checks[$name . '_present'] = is_file($path);
$config = (string)@file_get_contents($files['config.php']);
$logout = (string)@file_get_contents($files['logout.php']);
$order = (string)@file_get_contents($files['order.php']);
$checks['strict_session_mode'] = str_contains($config, 'session.use_strict_mode');
$checks['session_id_rotation'] = str_contains($config, 'session_regenerate_id(true)');
$checks['admin_gate_present'] = str_contains($config, 'function require_admin');
$checks['logout_post_guard'] = str_contains($logout, '$_SERVER') && str_contains($logout, "REQUEST_METHOD");
$checks['logout_csrf'] = str_contains($logout, 'require_csrf');
$checks['order_scoped_to_user'] = str_contains($order, 'WHERE o.id=? AND o.user_id=? LIMIT 1');
$checks['order_mutation_csrf'] = str_contains($order, 'verify_csrf();');
$checks['csrf_constant_time_compare'] = str_contains((string)@file_get_contents($files['csrf.php']), 'hash_equals');
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
exit($failed ? 1 : 0);
