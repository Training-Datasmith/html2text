#!/usr/bin/env php
<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$installedPhp = $root . '/vendor/composer/installed.php';
$installedJson = $root . '/vendor/composer/installed.json';

if (!is_readable($installedPhp)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.php not found; run composer update first.\n");
    exit(1);
}
if (!is_readable($installedJson)) {
    fwrite(STDERR, "check-cluster-pins: vendor/composer/installed.json not found; run composer update first.\n");
    exit(1);
}

/** @var array{versions?: array<string, array<string, mixed>>} $installedPhpData */
$installedPhpData = require $installedPhp;
$versions = $installedPhpData['versions'] ?? [];

$rawJson = file_get_contents($installedJson);
if ($rawJson === false) {
    fwrite(STDERR, "check-cluster-pins: could not read installed.json.\n");
    exit(1);
}
/** @var array<string, mixed> $jsonData */
$jsonData = json_decode($rawJson, true);
if (!is_array($jsonData)) {
    fwrite(STDERR, "check-cluster-pins: invalid installed.json.\n");
    exit(1);
}

$jsonPackages = array_merge(
    isset($jsonData['packages']) && is_array($jsonData['packages']) ? $jsonData['packages'] : [],
    isset($jsonData['packages-dev']) && is_array($jsonData['packages-dev']) ? $jsonData['packages-dev'] : []
);

/** @var array<string, array<string, mixed>> $jsonByName */
$jsonByName = [];
foreach ($jsonPackages as $pkg) {
    if (is_array($pkg) && isset($pkg['name'])) {
        $jsonByName[$pkg['name']] = $pkg;
    }
}

/** Org index tips — must not be installed (gap packages stay on Packagist). */
$indexForbiddenReferences = [
    'phpunit/phpunit' => 'a37d66fabf01af0eedabffe271b70dbe71723001',
    'phpstan/phpstan' => '63f848a4b1dbe2f2cb87acb3ff32596661e45417',
];

/**
 * @param array<string, array<string, mixed>> $versions
 */
function assert_gap_packagist(string $name, array $versions, array $jsonByName, array $indexForbiddenReferences): array
{
    $errors = [];
    if (!isset($versions[$name])) {
        $errors[] = "{$name} is not installed.";
        return $errors;
    }

    $reference = '';
    if (isset($versions[$name]['reference']) && is_string($versions[$name]['reference'])) {
        $reference = strtolower($versions[$name]['reference']);
    }

    $sourceUrl = '';
    if (isset($jsonByName[$name]['source']['url']) && is_string($jsonByName[$name]['source']['url'])) {
        $sourceUrl = $jsonByName[$name]['source']['url'];
    }

    if (isset($indexForbiddenReferences[$name])
        && $reference !== ''
        && hash_equals($indexForbiddenReferences[$name], $reference)) {
        $errors[] = "{$name} reference matches Training-Datasmith index tip {$reference}.";
    }

    if (stripos($sourceUrl, 'Training-Datasmith') !== false) {
        $errors[] = "{$name} source.url must not be Training-Datasmith fork (got {$sourceUrl}).";
    }

  return $errors;
}

/**
 * @param array<string, array<string, mixed>> $expected
 * @param array<string, array<string, mixed>> $versions
 */
function validate_installed_php(array $expected, array $versions): array
{
    $errors = [];
    foreach ($expected as $name => $want) {
        if (!isset($versions[$name])) {
            $errors[] = "{$name} is not installed (installed.php).";
            continue;
        }
        $aliases = $versions[$name]['aliases'] ?? [];
        if (!is_array($aliases) || !in_array($want['alias'], $aliases, true)) {
            $errors[] = "{$name} alias mismatch in installed.php.";
            continue;
        }
        $reference = $versions[$name]['reference'] ?? '';
        if (!is_string($reference) || $reference === '') {
            $errors[] = "{$name} has no reference in installed.php.";
            continue;
        }
        if (!hash_equals($want['reference'], $reference)) {
            $errors[] = "{$name} reference mismatch in installed.php.";
        }
    }

    return $errors;
}

/**
 * @param array<string, array<string, mixed>> $expected
 * @param array<string, array<string, mixed>> $jsonByName
 */
function validate_installed_json(array $expected, array $jsonByName): array
{
    $errors = [];
    foreach ($expected as $name => $want) {
        if (!isset($jsonByName[$name])) {
            $errors[] = "{$name} is not installed (installed.json).";
            continue;
        }
        $reference = $jsonByName[$name]['source']['reference'] ?? '';
        if (!is_string($reference) || $reference === '') {
            $errors[] = "{$name} has no source.reference in installed.json.";
            continue;
        }
        if (!hash_equals($want['reference'], strtolower($reference))) {
            $errors[] = "{$name} reference mismatch in installed.json.";
        }
    }

    return $errors;
}

$expected = [];
$goodReference = str_repeat('a', 40);
$wrongReference = str_repeat('b', 40);

$demoFixturePhp = [
    'versions' => [
        'demo/wrong-sha' => [
            'reference' => $wrongReference,
            'aliases' => ['9.9.9'],
        ],
    ],
];
$demoExpected = [
    'demo/wrong-sha' => [
        'alias' => '9.9.9',
        'reference' => $goodReference,
    ],
];
$phpDemoErrors = validate_installed_php($demoExpected, $demoFixturePhp['versions']);
if ($phpDemoErrors === [] || stripos(implode(' ', $phpDemoErrors), 'installed.php') === false) {
    fwrite(STDERR, "check-cluster-pins: wrong-SHA installed.php demo did not fail as expected.\n");
    exit(1);
}
fwrite(STDOUT, "check-cluster-pins: wrong-SHA installed.php failed on installed.php only\n");

$demoFixtureJson = [
    'demo/wrong-sha' => [
        'source' => ['reference' => $wrongReference],
    ],
];
$jsonDemoErrors = validate_installed_json($demoExpected, $demoFixtureJson);
if ($jsonDemoErrors === [] || stripos(implode(' ', $jsonDemoErrors), 'installed.json') === false) {
    fwrite(STDERR, "check-cluster-pins: wrong-SHA installed.json demo did not fail as expected.\n");
    exit(1);
}
fwrite(STDOUT, "check-cluster-pins: wrong-SHA installed.json failed on installed.json only\n");

$failures = [];
foreach ($indexForbiddenReferences as $gapName => $_) {
    foreach (assert_gap_packagist($gapName, $versions, $jsonByName, $indexForbiddenReferences) as $err) {
        $failures[] = $gapName . ': ' . $err;
    }
}

$failures = array_merge($failures, validate_installed_php($expected, $versions));
$failures = array_merge($failures, validate_installed_json($expected, $jsonByName));

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'check-cluster-pins: ' . $failure . "\n");
    }
    exit(1);
}

$count = count($expected);
fwrite(STDOUT, "check-cluster-pins: OK ({$count} packages; installed.php reference and installed.json source.reference)\n");
exit(0);
