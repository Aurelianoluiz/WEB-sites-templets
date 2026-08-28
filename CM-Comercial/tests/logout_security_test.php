<?php
declare(strict_types=1);

$logout = (string)file_get_contents(__DIR__ . '/../logout.php');
$checks = [
    'post_required_for_state_change' => str_contains($logout, "\$_SERVER['REQUEST_METHOD'] === 'POST'"),
    'csrf_required' => str_contains($logout, 'require_csrf();'),
    'session_cleared' => str_contains($logout, '$_SESSION = [];'),
    'session_destroyed' => str_contains($logout, 'session_destroy();'),
    'redirect_after_logout' => str_contains($logout, "redirect('index.php');"),
];
$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
