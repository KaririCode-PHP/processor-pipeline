<?php

declare(strict_types=1);

/*
 * Secret scan (Spec V4.3.3 §20.3, §32): fails when a credential-shaped string
 * appears in the package. Patterns are assembled at run time so this file
 * never matches itself; a self-test proves each pattern still fires.
 */

$patterns = [
    'AWS access key' => '/' . 'AKIA' . '[0-9A-Z]{16}/',
    'private key block' => '/-----BEGIN ' . '(?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----/',
    'GitHub token' => '/' . 'gh[pousr]_' . '[A-Za-z0-9]{36,}/',
    'Slack token' => '/' . 'xox[abprs]-' . '[A-Za-z0-9-]{10,}/',
    'credential assignment' => '/(?i)(?:password|passwd|secret|api[_-]?key|access[_-]?token)\s*[:=]\s*[\'"][^\'"\s]{12,}[\'"]/',
];

$samples = [
    'AWS access key' => 'AKIA' . str_repeat('Q', 16),
    'private key block' => '-----BEGIN ' . 'PRIVATE KEY-----',
    'GitHub token' => 'ghp_' . str_repeat('a', 36),
    'Slack token' => 'xoxb-' . str_repeat('1', 12),
    'credential assignment' => "api_key = '" . str_repeat('z', 16) . "'",
];
foreach ($patterns as $name => $pattern) {
    if (preg_match($pattern, $samples[$name]) !== 1) {
        fwrite(STDERR, "self-test failed: {$name}\n");
        exit(2);
    }
}

$root = dirname(__DIR__);
$skip = '#/(vendor|build|\.git|\.phpunit\.cache)/#';
$findings = 0;
$scanned = 0;
$files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));

foreach ($files as $file) {
    $path = $file->getPathname();
    if (preg_match($skip, $path) === 1 || !preg_match('/\.(php|md|json|neon|xml|dist|yml|yaml|txt)$/', $path)) {
        continue;
    }
    ++$scanned;
    foreach (file($path) ?: [] as $number => $line) {
        foreach ($patterns as $name => $pattern) {
            if (preg_match($pattern, $line) === 1) {
                ++$findings;
                fwrite(STDERR, sprintf("%s:%d: %s\n", substr($path, strlen($root) + 1), $number + 1, $name));
            }
        }
    }
}

printf("secret-scan: %d files, %d findings (self-test %d of %d)\n", $scanned, $findings, count($patterns), count($patterns));
exit($findings === 0 ? 0 : 1);
