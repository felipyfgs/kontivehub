<?php

namespace App\Actions\Fiscal;

use App\Contracts\SecureObjectStore;
use App\DTO\MeiAutomation\MeiAutomationArtifactData;
use App\Models\MeiAutomationAttempt;

final readonly class ReadMeiAutomationArtifactAction
{
    public function __construct(
        private SecureObjectStore $objects,
    ) {}

    public function handle(
        MeiAutomationAttempt $attempt,
        string $artifactId,
    ): MeiAutomationArtifactData {
        $descriptor = collect($attempt->vault_artifacts ?? [])->first(
            static fn (mixed $item): bool => is_array($item)
                && ($item['id'] ?? null) === $artifactId,
        );

        if (! is_array($descriptor)
            || ! is_string($descriptor['object_id'] ?? null)
            || ! is_string($descriptor['content_type'] ?? null)
            || ! is_string($descriptor['sha256'] ?? null)) {
            abort(404, 'Artefato não encontrado.');
        }

        $bytes = $this->objects->get($descriptor['object_id'], [
            'purpose' => 'MEI_PORTAL_ARTIFACT',
            'tenant_id' => (int) $attempt->tenant_id,
            'client_id' => (int) $attempt->client_id,
            'attempt_id' => (int) $attempt->id,
            'artifact_id' => $artifactId,
            'content_type' => $descriptor['content_type'],
            'sha256' => $descriptor['sha256'],
        ]);
        $name = basename((string) ($descriptor['name'] ?? 'artefato-mei'));
        if ($name === '' || $name === '.' || $name === '..') {
            $name = 'artefato-mei';
        }

        return new MeiAutomationArtifactData(
            bytes: $bytes,
            name: $name,
            contentType: $descriptor['content_type'],
        );
    }
}
