<?php
declare(strict_types=1);

/** Static regression for centralized webhook replay protection. */
$helper = (string)file_get_contents(__DIR__ . '/../src/Security/WebhookValidator.php');

$checks = [
    'uses_configured_skew' => str_contains($helper, 'private readonly int $maxSkew'),
    'validates_numeric_timestamp' => str_contains($helper, 'ctype_digit($ts)'),
    'checks_timestamp_delta' => str_contains($helper, '$this->maxSkew'),
    'uses_hmac' => str_contains($helper, 'hash_hmac'),
    'constant_time_compare' => str_contains($helper, 'hash_equals'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ': ' . $name . PHP_EOL;
exit($failed ? 1 : 0);
