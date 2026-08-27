<?php
declare(strict_types=1);

$tests = [
    __DIR__ . '/payment_core_test.php',
    __DIR__ . '/payment_service_test.php',
];

$failed = 0;
foreach ($tests as $test) {
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($test);
    passthru($command, $exitCode);
    if ($exitCode !== 0) {
        $failed++;
    }
}

if ($failed > 0) {
    fwrite(STDERR, "FAIL: {$failed} test suite(s) failed.\n");
    exit(1);
}

echo "PASS: all registered test suites\n";
