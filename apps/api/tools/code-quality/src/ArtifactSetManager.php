<?php

namespace Tools\CodeQuality;

use RuntimeException;

class ArtifactSetManager
{
    public function __construct(private readonly AuditArtifactValidator $validator = new AuditArtifactValidator) {}

    /**
     * @param  array<string, mixed>  $apiInventory
     * @param  array<string, mixed>  $webInventory
     * @return array<string, mixed>
     */
    public function summary(array $apiInventory, array $webInventory): array
    {
        $apiDigest = (string) ($apiInventory['digest'] ?? '');
        $webDigest = (string) ($webInventory['digest'] ?? '');

        return [
            'schemaVersion' => 1,
            'combinedDigest' => hash('sha256', $apiDigest."\n".$webDigest),
            'inventoryDigests' => [
                'api' => $apiDigest,
                'web' => $webDigest,
            ],
            'api' => $apiInventory['summary'] ?? [],
            'web' => $webInventory['summary'] ?? [],
        ];
    }

    /** @param array<string, mixed> $inventory @return array<string, mixed> */
    public function pendingLedger(array $inventory): array
    {
        $entries = array_map(fn (array $symbol): array => [
            'symbolId' => $symbol['id'],
            'status' => 'pending',
            'categories' => [],
            'findingIds' => [],
            'note' => 'Aguardando revisão semântica.',
            'reviewBatch' => null,
        ], $inventory['symbols'] ?? []);

        return [
            'schemaVersion' => 1,
            'inventoryDigest' => $inventory['digest'] ?? '',
            'entries' => $entries,
        ];
    }

    /** @param array<string, mixed> $inventory @return array<string, mixed> */
    public function emptyFindings(array $inventory): array
    {
        return [
            'schemaVersion' => 1,
            'inventoryDigest' => $inventory['digest'] ?? '',
            'items' => [],
        ];
    }

    /**
     * @return array{
     *     api: array<string, mixed>,
     *     web: array<string, mixed>,
     *     summary: array<string, mixed>
     * }
     */
    public function loadAndValidate(string $artifactRoot, bool $final = false): array
    {
        $root = rtrim($artifactRoot, '/');
        $api = $this->loadArea($root, 'api');
        $web = $this->loadArea($root, 'web');
        $apiSummary = $this->readJson("{$root}/api/summary.json");
        $webSummary = $this->readJson("{$root}/web/summary.json");
        $expectedSummary = $this->summary($api['inventory'], $web['inventory']);

        $this->validator->assertValid($api['inventory'], $api['ledger'], $api['findings'], null, $final);
        $this->validator->assertValid($web['inventory'], $web['ledger'], $web['findings'], null, $final);
        if ($apiSummary !== $expectedSummary || $webSummary !== $expectedSummary) {
            throw new RuntimeException('Resumos espelhados estão ausentes, divergentes ou desatualizados.');
        }

        return ['api' => $api, 'web' => $web, 'summary' => $expectedSummary];
    }

    /**
     * @return array{
     *     inventory: array<string, mixed>,
     *     ledger: array<string, mixed>,
     *     findings: array<string, mixed>
     * }
     */
    private function loadArea(string $root, string $area): array
    {
        return [
            'inventory' => $this->readJson("{$root}/{$area}/inventory.json"),
            'ledger' => $this->readJson("{$root}/{$area}/ledger.json"),
            'findings' => $this->readJson("{$root}/{$area}/findings.json"),
        ];
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
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
}
