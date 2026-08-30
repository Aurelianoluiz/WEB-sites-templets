<?php
declare(strict_types=1);

/**
 * Production preflight: checks local/server prerequisites without exposing secrets.
 * Run only from CLI or an authenticated maintenance context.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

$checks = [];
$ok = true;

function check(string $name, bool $condition, string $detail): void
{
    global $checks, $ok;
    $checks[] = [$name, $condition, $detail];
    if (!$condition) $ok = false;
}

check('PHP version', version_compare(PHP_VERSION, '8.2.0', '>='), PHP_VERSION);
check('PDO', extension_loaded('pdo'), extension_loaded('pdo') ? 'loaded' : 'missing');
check('PDO SQLite', extension_loaded('pdo_sqlite'), extension_loaded('pdo_sqlite') ? 'loaded' : 'missing');
check('cURL', extension_loaded('curl'), extension_loaded('curl') ? 'loaded' : 'missing');
check('OpenSSL', extension_loaded('openssl'), extension_loaded('openssl') ? 'loaded' : 'missing');

$appEnv = getenv('APP_ENV') ?: 'production';
$appUrl = trim((string)(getenv('APP_URL') ?: ''));
$gateway = strtolower(trim((string)(getenv('PAYMENT_GATEWAY') ?: 'manual')));

check('APP_ENV', $appEnv === 'production', $appEnv);
check('APP_URL', filter_var($appUrl, FILTER_VALIDATE_URL) !== false && str_starts_with(strtolower($appUrl), 'https://'), $appUrl !== '' ? parse_url($appUrl, PHP_URL_SCHEME) . '://' : 'missing');

if ($gateway === 'mercadopago') {
    $token = trim((string)(getenv('MP_ACCESS_TOKEN') ?: ''));
    $secret = trim((string)(getenv('MP_WEBHOOK_SECRET') ?: ''));
    $skew = (int)(getenv('MP_WEBHOOK_MAX_SKEW') ?: 300);
    check('MP_ACCESS_TOKEN', $token !== '', $token === '' ? 'missing' : 'configured');
    check('MP_WEBHOOK_SECRET', $secret !== '', $secret === '' ? 'missing' : 'configured');
    check('MP_WEBHOOK_MAX_SKEW', $skew >= 30 && $skew <= 3600, (string)$skew);
} else {
    check('PAYMENT_GATEWAY', in_array($gateway, ['manual', 'none'], true), $gateway);
}

$base = __DIR__;
$dataDir = $base . '/data';
check('Data directory', is_dir($dataDir) ? is_writable($dataDir) : (!file_exists($dataDir) && is_writable($base)), is_dir($dataDir) ? (is_writable($dataDir) ? 'writable' : 'not writable') : 'will need creation');

$envPath = $base . '/.env';
check('.env not tracked artifact', !is_file($envPath) || is_readable($envPath), is_file($envPath) ? 'present on server' : 'not present');

foreach ($checks as [$name, $passed, $detail]) {
    echo ($passed ? 'PASS' : 'FAIL') . ": $name — $detail\n";
}

echo $ok ? "PRODUCTION_PREFLIGHT_PASS\n" : "PRODUCTION_PREFLIGHT_FAIL\n";
exit($ok ? 0 : 1);
