<?php

declare(strict_types=1);

/*
 * CycloneDX 1.5 SBOM from the Composer lock (Spec V4.3.3 §32). Runtime
 * packages are `required`; development packages are `excluded`.
 * Usage: php tools/sbom.php <output.json>
 */

$root = dirname(__DIR__);
$lockName = preg_replace('/\.json$/', '.lock', getenv('COMPOSER') ?: 'composer.json');
$lock = json_decode((string) file_get_contents("{$root}/{$lockName}"), true, flags: JSON_THROW_ON_ERROR);
$package = json_decode((string) file_get_contents("{$root}/composer.json"), true, flags: JSON_THROW_ON_ERROR);

$component = static function (array $p, string $scope): array {
    [$group, $name] = explode('/', $p['name'], 2);
    $version = ltrim((string) $p['version'], 'v');

    return [
        'type' => 'library',
        'bom-ref' => "pkg:composer/{$p['name']}@{$version}",
        'group' => $group,
        'name' => $name,
        'version' => $version,
        'scope' => $scope,
        'purl' => "pkg:composer/{$p['name']}@{$version}",
        'licenses' => array_map(static fn(string $id): array => ['license' => ['id' => $id]], $p['license'] ?? []),
    ];
};

$components = [
    ...array_map(static fn(array $p): array => $component($p, 'required'), $lock['packages'] ?? []),
    ...array_map(static fn(array $p): array => $component($p, 'excluded'), $lock['packages-dev'] ?? []),
];

$bom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.5',
    'serialNumber' => 'urn:uuid:' . vsprintf('%s%s-%s-4%s-%s-%s%s%s', str_split(substr(bin2hex(random_bytes(16)), 0, 31), 4)),
    'version' => 1,
    'metadata' => [
        'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
        'component' => ['type' => 'library', 'name' => $package['name'], 'bom-ref' => "pkg:composer/{$package['name']}"],
    ],
    'components' => $components,
];

$output = $argv[1] ?? 'sbom.cdx.json';
if (!is_dir(dirname($output)) && !mkdir(dirname($output), 0o755, true) && !is_dir(dirname($output))) {
    fwrite(STDERR, "sbom: cannot create the directory of {$output}\n");
    exit(1);
}
if (file_put_contents($output, json_encode($bom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false) {
    fwrite(STDERR, "sbom: cannot write {$output}\n");
    exit(1);
}
printf(
    "sbom: %s — %d runtime, %d development (excluded)\n",
    $output,
    count($lock['packages'] ?? []),
    count($lock['packages-dev'] ?? []),
);
