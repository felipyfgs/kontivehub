#!/usr/bin/env php
<?php

use Tools\CodeQuality\BackendInventoryBuilder;
use Tools\CodeQuality\InventoryDriftDetector;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$options = getopt('', ['root:', 'output:', 'python:', 'expected:', 'allow-parse-errors']);
$apiRoot = (string) ($options['root'] ?? dirname(__DIR__, 2));
$outputPath = isset($options['output']) ? (string) $options['output'] : null;
$python = (string) ($options['python'] ?? getenv('CODE_QUALITY_PYTHON') ?: 'python3');
$allowParseErrors = array_key_exists('allow-parse-errors', $options);
$expectedPath = isset($options['expected']) ? (string) $options['expected'] : null;

$input = stream_get_contents(STDIN);
$paths = array_values(array_filter(array_map('trim', preg_split('/\R/', $input ?: '') ?: [])));
if ($paths === []) {
    fwrite(STDERR, "Forneça os paths canônicos pelo stdin.\n");
    exit(64);
}

$pythonPaths = array_values(array_filter($paths, fn (string $path): bool => str_ends_with(strtolower($path), '.py')));
$pythonResults = $pythonPaths === [] ? [] : collectPython($python, $apiRoot, $pythonPaths);
$inventory = (new BackendInventoryBuilder)->build($apiRoot, $paths, $pythonResults);
$json = json_encode($inventory, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n";

if ($outputPath === null) {
    fwrite(STDOUT, $json);
} else {
    $directory = dirname($outputPath);
    if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Não foi possível criar {$directory}");
    }
    if (file_put_contents($outputPath, $json) === false) {
        throw new RuntimeException("Não foi possível gravar {$outputPath}");
    }
}

if (! $allowParseErrors && (int) $inventory['summary']['parseErrors'] > 0) {
    fwrite(STDERR, "Inventário gerado com {$inventory['summary']['parseErrors']} erro(s) de parse.\n");
    exit(2);
}

if ($expectedPath !== null) {
    $expectedContents = file_get_contents($expectedPath);
    if ($expectedContents === false) {
        throw new RuntimeException("Inventário esperado ausente: {$expectedPath}");
    }
    $expected = json_decode($expectedContents, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($expected)) {
        throw new RuntimeException("Inventário esperado inválido: {$expectedPath}");
    }
    $detector = new InventoryDriftDetector;
    $drift = $detector->compare($expected, $inventory);
    if ($detector->hasDrift($drift)) {
        fwrite(STDERR, json_encode($drift, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)."\n");
        exit(3);
    }
}

fwrite(STDERR, sprintf(
    "API inventory %s: %d arquivos, %d símbolos.\n",
    $inventory['digest'],
    $inventory['summary']['files'],
    $inventory['summary']['symbols'],
));

/**
 * @param  list<string>  $paths
 * @return array<string, array{symbols: list<array<string, mixed>>, parseErrors: list<array<string, mixed>>}>
 */
function collectPython(string $python, string $apiRoot, array $paths): array
{
    $script = __DIR__.'/python_inventory.py';
    $process = proc_open(
        [$python, $script],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        dirname(__DIR__, 2),
        null,
        ['bypass_shell' => true],
    );
    if (! is_resource($process)) {
        throw new RuntimeException('Não foi possível iniciar o coletor Python.');
    }

    fwrite($pipes[0], json_encode([
        'root' => $apiRoot,
        'paths' => $paths,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    fclose($pipes[0]);

    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    if ($exitCode !== 0) {
        $message = mb_substr(trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $stderr ?: 'erro desconhecido')), 0, 500);
        throw new RuntimeException("Coletor Python falhou ({$exitCode}): {$message}");
    }

    $decoded = json_decode($stdout, true, 512, JSON_THROW_ON_ERROR);
    if (! is_array($decoded)) {
        throw new RuntimeException('Coletor Python retornou payload inválido.');
    }

    return $decoded;
}
