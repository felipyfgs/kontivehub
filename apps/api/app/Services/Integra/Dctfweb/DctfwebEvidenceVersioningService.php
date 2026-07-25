<?php

namespace App\Services\Integra\Dctfweb;

use App\Enums\DctfwebArtifactKind;
use App\Models\DctfwebDeclaration;
use App\Models\DctfwebEvidenceVersion;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Versiona evidências de retificação sem sobrescrever XML/relatório/recibo anterior (9.4).
 *
 * @return array{version: DctfwebEvidenceVersion, created: bool, artifact: FiscalEvidenceArtifact}
 */
final class DctfwebEvidenceVersioningService
{
    public function __construct(
        private readonly FiscalEvidenceStore $evidenceStore,
    ) {}

    /**
     * Persiste bytes no cofre e cria nova versão se o SHA diferir da corrente.
     * SHA idêntico → reutiliza versão corrente (idempotente).
     *
     * @param  array<string, mixed>|null  $metadata
     * @return array{version: DctfwebEvidenceVersion, created: bool, artifact: FiscalEvidenceArtifact, retification: bool}
     */
    public function storeVersioned(
        FiscalMonitoringRun $run,
        DctfwebDeclaration $declaration,
        DctfwebArtifactKind $kind,
        string $bytes,
        ?string $contentType = null,
        ?string $sourceVersion = null,
        ?string $declarationType = null,
        ?array $metadata = null,
        ?CarbonImmutable $observedAt = null,
    ): array {
        $sha = hash('sha256', $bytes);
        $observed = $observedAt ?? CarbonImmutable::now();
        $contentType ??= $kind->contentTypeHint();

        return DB::transaction(function () use (
            $run, $declaration, $kind, $bytes, $contentType, $sourceVersion,
            $declarationType, $metadata, $observed, $sha
        ) {
            $current = DctfwebEvidenceVersion::query()
                ->withoutGlobalScopes()
                ->where('office_id', $declaration->office_id)
                ->where('declaration_id', $declaration->id)
                ->where('artifact_kind', $kind->value)
                ->where('is_current', true)
                ->lockForUpdate()
                ->first();

            if ($current !== null && hash_equals($current->content_sha256, $sha)) {
                $artifact = FiscalEvidenceArtifact::query()
                    ->withoutGlobalScopes()
                    ->whereKey($current->evidence_artifact_id)
                    ->firstOrFail();

                return [
                    'version' => $current,
                    'created' => false,
                    'artifact' => $artifact,
                    'retification' => false,
                ];
            }

            $artifact = $this->evidenceStore->store(
                run: $run,
                bytes: $bytes,
                contentType: $contentType,
                source: 'INTEGRA_DCTFWEB/'.$kind->value,
                sourceVersion: $sourceVersion,
                observedAt: $observed,
            );

            $nextVersion = $current !== null ? $current->version + 1 : 1;
            $isRetification = $current !== null;

            if ($current !== null) {
                // Desliga corrente sem tocar em colunas imutáveis
                $current->forceFill(['is_current' => false])->save();
            }

            $version = DctfwebEvidenceVersion::query()->create([
                'office_id' => $declaration->office_id,
                'client_id' => $declaration->client_id,
                'declaration_id' => $declaration->id,
                'competence_id' => $declaration->competence_id,
                'run_id' => $run->id,
                'evidence_artifact_id' => $artifact->id,
                'artifact_kind' => $kind,
                'version' => $nextVersion,
                'content_sha256' => $sha,
                'is_current' => true,
                'declaration_type' => $declarationType ?? $declaration->declaration_type,
                'source_version' => $sourceVersion,
                'is_retification' => $isRetification,
                'observed_at' => $observed,
                'metadata' => $metadata,
                'created_at' => CarbonImmutable::now(),
            ]);

            $declaration->forceFill([
                'evidence_version' => max((int) $declaration->evidence_version, $nextVersion),
            ])->save();

            return [
                'version' => $version,
                'created' => true,
                'artifact' => $artifact,
                'retification' => $isRetification,
            ];
        });
    }

    /**
     * Lista versões (histórico) sem expor vault ids.
     *
     * @return list<array<string, mixed>>
     */
    public function history(DctfwebDeclaration $declaration, ?DctfwebArtifactKind $kind = null): array
    {
        $q = DctfwebEvidenceVersion::query()
            ->withoutGlobalScopes()
            ->where('office_id', $declaration->office_id)
            ->where('declaration_id', $declaration->id)
            ->orderBy('artifact_kind')
            ->orderBy('version');

        if ($kind !== null) {
            $q->where('artifact_kind', $kind->value);
        }

        return $q->get()->map(fn (DctfwebEvidenceVersion $v) => $v->toPublicArray())->all();
    }
}
