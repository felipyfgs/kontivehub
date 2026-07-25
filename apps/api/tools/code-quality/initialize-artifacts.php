#!/usr/bin/env php
<?php

use Tools\CodeQuality\ArtifactSetManager;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['root:', 'force']);
$root = rtrim((string) ($options['root'] ?? ''), '/');
$force = array_key_exists('force', $options);
if ($root === '') {
    fwrite(STDERR, "Informe --root para o diretório de artefatos.\n");
    exit(64);
}

$manager = new ArtifactSetManager;
$inventories = [];
foreach (['api', 'web'] as $area) {
    $inventoryPath = "{$root}/{$area}/inventory.json";
    $inventory = readJson($inventoryPath);
    $inventories[$area] = $inventory;
    writeJson("{$root}/{$area}/ledger.json", $manager->pendingLedger($inventory), $force);
    writeJson("{$root}/{$area}/findings.json", $manager->emptyFindings($inventory), $force);
}

$summary = $manager->summary($inventories['api'], $inventories['web']);
foreach (['api', 'web'] as $area) {
    writeJson("{$root}/{$area}/summary.json", $summary, $force);
}

$manager->loadAndValidate($root);
fwrite(STDOUT, "Artefatos inicializados: {$summary['combinedDigest']}\n");

/** @return array<string, mixed> */
function readJson(string $path): array
{
    $contents = file_get_contents($path);
    if ($contents === false) {
        throw new RuntimeException("Inventário ausente: {$path}");
    }
    $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException("Inventário inválido: {$path}");
    }

    return $decoded;
}

/** @param array<string, mixed> $payload */
function writeJson(string $path, array $payload, bool $force): void
{
    if (is_file($path) && ! $force) {
        throw new RuntimeException("Artefato já existe; reconcilie em vez de sobrescrever: {$path}");
    }
    if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0775, true) && ! is_dir(dirname($path))) {
        throw new RuntimeException('Não foi possível criar '.dirname($path));
    }
    $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";
    if (file_put_contents($path, $json) === false) {
        throw new RuntimeException("Não foi possível gravar {$path}");
    }
}
