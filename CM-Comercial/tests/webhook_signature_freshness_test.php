<?php
declare(strict_types=1);

/**
 * Static regression for webhook replay protection.
 * Freshness validation is centralized in the dedicated signature helper.
 */
$helper = (string)file_get_contents(__DIR__ . '/../includes/mp_webhook_signature.php');

$checks = [
    'uses_configured_skew' => str_contains($helper, 'maxSkew'),
    'validates_numeric_timestamp' => str_contains($helper, 'ctype_digit'),
    'checks_timestamp_delta' => str_contains($helper, 'abs($now - $ts) > $maxSkew'),
    'uses_hmac' => str_contains($helper, 'hash_hmac'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
