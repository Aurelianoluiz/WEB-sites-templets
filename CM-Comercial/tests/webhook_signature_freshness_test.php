<?php
declare(strict_types=1);

/**
 * Static regression for webhook replay protection.
 * The handler must validate a numeric timestamp and reject signatures outside
 * the configured freshness window before comparing the HMAC.
 */
$handler = (string)file_get_contents(__DIR__ . '/../webhooks/webhook_handler.php');

foreach (['MP_WEBHOOK_MAX_SKEW', 'ctype_digit($ts)', 'abs(time() - (int)$ts) > $maxSkew'] as $needle) {
    if (!str_contains($handler, $needle)) {
        fwrite(STDERR, "FAIL: missing webhook freshness check: $needle\n");
        exit(1);
    }
}

$hmacPos = strpos($handler, '$expected = hash_hmac');
$skewPos = strpos($handler, '$maxSkew =');
if ($hmacPos === false || $skewPos === false || $skewPos > $hmacPos) {
    fwrite(STDERR, "FAIL: freshness must be checked before HMAC acceptance\n");
    exit(1);
}

echo "PASS: webhook signature freshness\n";
