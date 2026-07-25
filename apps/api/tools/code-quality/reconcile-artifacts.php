#!/usr/bin/env php
<?php

use Tools\CodeQuality\ArtifactAreaReconciler;
use Tools\CodeQuality\ArtifactSetManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['root:', 'area:', 'inventory:']);
$root = rtrim((string) ($options['root'] ?? ''), '/');
$area = (string) ($options['area'] ?? '');
$inventoryPath = (string) ($options['inventory'] ?? '');
if ($root === '' || ! in_array($area, ['api', 'web'], true) || $inventoryPath === '') {
    fwrite(STDERR, "Informe --root, --area=api|web e --inventory.\n");
    exit(64);
}

$oldInventory = readJson("{$root}/{$area}/inventory.json");
$oldLedger = readJson("{$root}/{$area}/ledger.json");
$oldFindings = readJson("{$root}/{$area}/findings.json");
$newInventory = readJson($inventoryPath);

$result = (new ArtifactAreaReconciler)->reconcile(
    $oldInventory,
    $oldLedger,
    $oldFindings,
    $newInventory,
);

$otherArea = $area === 'api' ? 'web' : 'api';
$inventories = [
    $area => $newInventory,
    $otherArea => readJson("{$root}/{$otherArea}/inventory.json"),
];
$summary = (new ArtifactSetManager)->summary($inventories['api'], $inventories['web']);

writeJsonAtomically("{$root}/{$area}/inventory.json", $newInventory);
writeJsonAtomically("{$root}/{$area}/ledger.json", $result['ledger']);
writeJsonAtomically("{$root}/{$area}/findings.json", $result['findings']);
writeJsonAtomically("{$root}/api/summary.json", $summary);
writeJsonAtomically("{$root}/web/summary.json", $summary);

(new ArtifactSetManager)->loadAndValidate($root);
fwrite(STDOUT, json_encode([
    'area' => $area,
    'inventoryDigest' => $newInventory['digest'],
    'preservedOrRemapped' => count($result['idMap']),
    'orphanedSymbolIds' => $result['orphanedSymbolIds'],
    'collisions' => $result['collisions'],
    'combinedDigest' => $summary['combinedDigest'],
], JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Artefato ausente ou ilegível: {$path}");
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException("Artefato JSON inválido: {$path}");
    }

    return $decoded;
}

/** @param array<string, mixed> $payload */
function writeJsonAtomically(string $path, array $payload): void
{
    $directory = dirname($path);
    $temporary = tempnam($directory, '.code-quality-');
    if ($temporary === false) {
        throw new RuntimeException("Não foi possível criar temporário em {$directory}");
    }

    try {
        $json = json_encode(
            $payload,
            JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
        )."\n";
        if (file_put_contents($temporary, $json, LOCK_EX) === false) {
            throw new RuntimeException("Não foi possível gravar temporário para {$path}");
        }
        if (! chmod($temporary, 0664)) {
            throw new RuntimeException("Não foi possível ajustar permissão do temporário para {$path}");
        }
        if (! rename($temporary, $path)) {
            throw new RuntimeException("Não foi possível substituir {$path}");
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}
