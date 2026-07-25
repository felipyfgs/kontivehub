<?php

namespace App\Services\Serpro;

use App\Models\SerproDocumentSnapshot;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registro versionado das fontes oficiais SERPRO (URL, hash, revisão, capacidades).
 */
final class SerproDocumentRegistry
{
    public function __construct(
        private readonly SerproOfficialSourceIntegrity $integrity,
    ) {}

    public function manifestPath(): string
    {
        return (string) config(
            'serpro.official_sources_manifest',
            resource_path('serpro/official-sources.v2026-07-18.json')
        );
    }

    /**
     * @return array{
     *   version: string,
     *   retrieved_on: string,
     *   sources: list<array<string, mixed>>
     * }
     */
    public function loadManifest(): array
    {
        $path = $this->manifestPath();
        if (! is_file($path)) {
            throw new RuntimeException('Manifesto de fontes oficiais SERPRO ausente.');
        }

        $validated = $this->integrity->loadAndValidate($path);
        $data = $validated['manifest'];

        return [
            'version' => (string) $data['version'],
            'retrieved_on' => (string) $data['retrieved_on'],
            'sources' => $data['sources'],
        ];
    }

    /**
     * Importa o manifesto para a tabela de snapshots (idempotente por source_key+hash).
     *
     * @return array{created: int, existing: int, total: int}
     */
    public function syncFromManifest(): array
    {
        $manifest = $this->loadManifest();
        $httpSources = array_values(array_filter(
            $manifest['sources'],
            static fn (array $source): bool => ($source['verification_kind'] ?? null) === 'HTTP_CONTENT',
        ));

        return DB::transaction(function () use ($manifest, $httpSources): array {
            $created = 0;
            $existing = 0;

            foreach ($httpSources as $source) {
                $key = (string) $source['source_key'];
                $hash = (string) $source['content_sha256'];
                $row = SerproDocumentSnapshot::query()->firstOrCreate(
                    [
                        'source_key' => $key,
                        'content_sha256' => $hash,
                    ],
                    [
                        'title' => (string) $source['title'],
                        'url' => (string) $source['url'],
                        'document_type' => (string) $source['document_type'],
                        'revision' => isset($source['revision']) ? (string) $source['revision'] : null,
                        'retrieved_on' => $source['retrieved_on'],
                        'affected_capabilities' => $source['affected_capabilities'] ?? [],
                        'segregation_class' => (string) ($source['segregation_class'] ?? 'PRODUCTION'),
                        'notes' => isset($source['notes']) ? (string) $source['notes'] : null,
                        'metadata' => [
                            'manifest_version' => $manifest['version'],
                            'canonical' => true,
                            'verification_kind' => 'HTTP_CONTENT',
                        ],
                    ]
                );

                if ($row->wasRecentlyCreated) {
                    $created++;
                } else {
                    $existing++;
                }
            }

            return [
                'created' => $created,
                'existing' => $existing,
                'total' => $created + $existing,
            ];
        });
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listSanitized(): array
    {
        return SerproDocumentSnapshot::query()
            ->orderBy('source_key')
            ->orderByDesc('id')
            ->get()
            ->map->toSanitizedArray()
            ->all();
    }
}
