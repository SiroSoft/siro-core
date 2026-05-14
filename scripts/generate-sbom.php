#!/usr/bin/env php
<?php
declare(strict_types=1);

$composerJson = json_decode(file_get_contents(__DIR__ . '/../composer.json'), true);
$composerLock = json_decode(file_get_contents(__DIR__ . '/../composer.lock'), true);

$components = [];
foreach ($composerLock['packages'] ?? [] as $pkg) {
    $components[] = [
        'type' => 'library',
        'name' => $pkg['name'],
        'version' => $pkg['version'] ?? 'unknown',
        'licenses' => array_map(fn($l) => ['license' => ['id' => $l]], $pkg['license'] ?? ['Unknown']),
        'purl' => 'pkg:composer/' . $pkg['name'] . '@' . ($pkg['version'] ?? 'unknown'),
        'externalReferences' => [
            ['type' => 'vcs', 'url' => $pkg['source']['url'] ?? ''],
        ],
    ];
}
foreach ($composerLock['packages-dev'] ?? [] as $pkg) {
    $components[] = [
        'type' => 'library',
        'name' => $pkg['name'],
        'version' => $pkg['version'] ?? 'unknown',
        'licenses' => array_map(fn($l) => ['license' => ['id' => $l]], $pkg['license'] ?? ['Unknown']),
        'purl' => 'pkg:composer/' . $pkg['name'] . '@' . ($pkg['version'] ?? 'unknown'),
        'externalReferences' => [
            ['type' => 'vcs', 'url' => $pkg['source']['url'] ?? ''],
        ],
    ];
}

$sbom = [
    'bomFormat' => 'CycloneDX',
    'specVersion' => '1.4',
    'version' => 1,
    'metadata' => [
        'component' => [
            'type' => 'library',
            'name' => $composerJson['name'] ?? 'unknown',
            'version' => $composerJson['version'] ?? '0.0.0',
            'licenses' => [['license' => ['id' => $composerJson['license'] ?? 'Proprietary']]],
            'purl' => 'pkg:composer/' . ($composerJson['name'] ?? 'unknown'),
        ],
    ],
    'components' => $components,
];

$outDir = __DIR__ . '/../storage/sbom';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$outputPath = $outDir . '/' . str_replace('/', '-', $composerJson['name'] ?? 'unknown') . '-sbom.json';
file_put_contents($outputPath, json_encode($sbom, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
echo "SBOM generated: {$outputPath}\n";
