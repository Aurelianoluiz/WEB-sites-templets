<?php
declare(strict_types=1);

/** Static regression checks for secure password handling. */
$root = dirname(__DIR__);
$phpFiles = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if ($file->isFile() && $file->getExtension() === 'php' && str_contains($file->getPathname(), DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR) === false) {
        $phpFiles[] = $file->getPathname();
    }
}

$all = '';
foreach ($phpFiles as $file) {
    $all .= "\n" . (string)file_get_contents($file);
}

$checks = [
    'password_hash_used' => str_contains($all, 'password_hash('),
    'password_verify_used' => str_contains($all, 'password_verify('),
    'no_md5_password_storage' => !preg_match('/(?:md5|sha1)\s*\([^\n]*password/i', $all),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
foreach ($checks as $name => $ok) echo ($ok ? 'PASS' : 'FAIL') . ": $name\n";
exit($failed ? 1 : 0);
