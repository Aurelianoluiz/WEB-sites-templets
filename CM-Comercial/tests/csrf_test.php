<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/csrf.php';

session_id('cm-csrf-test');
session_start();
$_SESSION = [];

$token = csrf_token();
if (strlen($token) !== 64 || !ctype_xdigit($token)) {
    fwrite(STDERR, "FAIL: token format\n"); exit(1);
}
if (!csrf_validate($token)) {
    fwrite(STDERR, "FAIL: valid token rejected\n"); exit(1);
}
if (csrf_validate('invalid-token')) {
    fwrite(STDERR, "FAIL: invalid token accepted\n"); exit(1);
}
if (csrf_token() !== $token) {
    fwrite(STDERR, "FAIL: token rotated unexpectedly within session\n"); exit(1);
}

echo "PASS: CSRF token validation\n";
