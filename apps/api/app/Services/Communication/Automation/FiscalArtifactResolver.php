<?php

namespace App\Services\Communication\Automation;

use App\DTO\Communication\FiscalArtifactResolution;
use App\DTO\Communication\ResolvedFiscalArtifact;
use App\Enums\DctfwebArtifactKind;
use App\Enums\PgdasdDocumentKind;
use App\Enums\TaxGuideEmissionStatus;
use App\Models\Client;
use App\Models\DctfwebDarfDocument;
use App\Models\FiscalEvidenceArtifact;
use App\Models\PgdasdArtifact;
use App\Models\TaxGuide;
use App\Models\TaxGuideVersion;
use App\Models\Tenant;
use App\Services\Fiscal\Guides\GuideStorageService;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use DateTimeInterface;
use RuntimeException;

final readonly class FiscalArtifactResolver
{
    public function __construct(
        private FiscalEvidenceStore $evidenceStore,
        private GuideStorageService $guideStorage,
    ) {}

    public function resolve(
        Tenant $tenant,
        Client $client,
        string $moduleKey,
        string $submoduleKey,
        string $periodKey,
        ?DateTimeInterface $availableBy = null,
    ): FiscalArtifactResolution {
        if ((int) $client->tenant_id !== (int) $tenant->id || ! preg_match('/^\d{4}-\d{2}$/', $periodKey)) {
            return FiscalArtifactResolution::missing('INVALID_SCOPE_OR_PERIOD');
        }

        return match (strtolower($submoduleKey)) {
            'pgdasd' => $this->resolvePgdasd($tenant, $client, $periodKey, $availableBy),
            'pgmei' => $this->resolvePgmei($tenant, $client, $periodKey, $availableBy),
            'dctfweb' => $this->resolveDctfweb($tenant, $client, $periodKey, $availableBy),
            'fgts' => FiscalArtifactResolution::missing('FGTS_GUIDE_UNSUPPORTED'),
            default => FiscalArtifactResolution::missing('MODULE_DOCUMENT_UNSUPPORTED'),
        };
    }

    public function read(ResolvedFiscalArtifact $artifact, int $tenantId): string
    {
        $bytes = match ($artifact->storageKind) {
            'fiscal_evidence' => $this->readEvidence($artifact->storageId, $tenantId),
            'tax_guide_version' => $this->readGuide($artifact->storageId, $tenantId),
            default => throw new RuntimeException('Origem de documento fiscal não suportada.'),
        };

        if (! hash_equals($artifact->digest, hash('sha256', $bytes)) || strlen($bytes) !== $artifact->byteSize) {
            throw new RuntimeException('Documento fiscal divergiu do artefato congelado.');
        }

        return $bytes;
    }

    private function resolvePgdasd(
        Tenant $tenant,
        Client $client,
        string $periodKey,
        ?DateTimeInterface $availableBy,
    ): FiscalArtifactResolution {
        $query = PgdasdArtifact::query()
            ->withoutGlobalScopes()
            ->with([
                'evidenceArtifact' => fn ($query) => $query->withoutGlobalScopes(),
                'operation' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('metadata->period_key', $periodKey)
            ->where(function ($query): void {
                $query->where('kind', PgdasdDocumentKind::GuiaDasPreexistente->value)
                    ->orWhereHas('operation', fn ($operation) => $operation
                        ->withoutGlobalScopes()
                        ->where('kind', 'DAS'));
            });
        if ($availableBy !== null) {
            $query->where('observed_at', '<=', $availableBy);
        }
        $artifact = $query
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->first();
        $evidence = $artifact?->evidenceArtifact;
        if (! $artifact instanceof PgdasdArtifact
            || ! $evidence instanceof FiscalEvidenceArtifact
            || $this->evidenceStore->unavailableReason($evidence, (int) $tenant->id) !== null) {
            return FiscalArtifactResolution::missing();
        }

        return FiscalArtifactResolution::found(new ResolvedFiscalArtifact(
            type: PgdasdArtifact::class,
            id: (int) $artifact->id,
            digest: strtolower((string) $evidence->content_sha256),
            periodKey: $periodKey,
            contentType: (string) ($artifact->content_type ?: $evidence->content_type ?: 'application/pdf'),
            byteSize: (int) $evidence->byte_size,
            filename: $this->safeFilename($artifact->filename, 'pgdasd-'.$periodKey.'.pdf'),
            storageKind: 'fiscal_evidence',
            storageId: (int) $evidence->id,
        ));
    }

    private function resolvePgmei(
        Tenant $tenant,
        Client $client,
        string $periodKey,
        ?DateTimeInterface $availableBy,
    ): FiscalArtifactResolution {
        $query = TaxGuide::query()
            ->withoutGlobalScopes()
            ->with(['currentVersion' => fn ($query) => $query->withoutGlobalScopes()])
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('competence_period_key', $periodKey)
            ->where(function ($query): void {
                $query->whereIn('service_code', ['PGMEI', 'MEI'])
                    ->orWhere('system_code', 'INTEGRA_MEI');
            });
        if ($availableBy !== null) {
            $query->whereHas('currentVersion', fn ($version) => $version
                ->withoutGlobalScopes()
                ->where('created_at', '<=', $availableBy));
        }
        $guide = $query
            ->whereHas('currentVersion', function ($query): void {
                $query->withoutGlobalScopes()
                    ->where('is_current', true)
                    ->where('emission_status', TaxGuideEmissionStatus::Confirmed->value)
                    ->whereNotNull('vault_object_id')
                    ->whereNotNull('content_sha256')
                    ->where('byte_size', '>', 0);
            })
            ->orderByDesc('id')
            ->first();
        $version = $guide?->currentVersion;
        if (! $guide instanceof TaxGuide || ! $version instanceof TaxGuideVersion || ! $version->hasStoredDocument()) {
            return FiscalArtifactResolution::missing();
        }

        return FiscalArtifactResolution::found(new ResolvedFiscalArtifact(
            type: TaxGuideVersion::class,
            id: (int) $version->id,
            digest: strtolower((string) $version->content_sha256),
            periodKey: $periodKey,
            contentType: (string) ($version->content_type ?: 'application/pdf'),
            byteSize: (int) $version->byte_size,
            filename: 'pgmei-'.$periodKey.'-v'.$version->version_number.'.pdf',
            storageKind: 'tax_guide_version',
            storageId: (int) $version->id,
        ));
    }

    private function resolveDctfweb(
        Tenant $tenant,
        Client $client,
        string $periodKey,
        ?DateTimeInterface $availableBy,
    ): FiscalArtifactResolution {
        $query = DctfwebDarfDocument::query()
            ->withoutGlobalScopes()
            ->with([
                'declaration' => fn ($query) => $query->withoutGlobalScopes(),
                'evidenceVersion' => fn ($query) => $query->withoutGlobalScopes(),
                'evidenceVersion.artifact' => fn ($query) => $query->withoutGlobalScopes(),
            ])
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->whereNotNull('content_sha256')
            ->whereHas('declaration', fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('period_key', $periodKey))
            ->whereHas('evidenceVersion', fn ($query) => $query
                ->withoutGlobalScopes()
                ->where('artifact_kind', DctfwebArtifactKind::Darf->value)
                ->where('is_current', true));
        if ($availableBy !== null) {
            $query->where('created_at', '<=', $availableBy);
        }
        $document = $query
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->first();
        $evidence = $document?->evidenceVersion?->artifact;
        if (! $document instanceof DctfwebDarfDocument
            || ! $evidence instanceof FiscalEvidenceArtifact
            || ! hash_equals(strtolower((string) $document->content_sha256), strtolower((string) $evidence->content_sha256))
            || $this->evidenceStore->unavailableReason($evidence, (int) $tenant->id) !== null) {
            return FiscalArtifactResolution::missing();
        }

        return FiscalArtifactResolution::found(new ResolvedFiscalArtifact(
            type: DctfwebDarfDocument::class,
            id: (int) $document->id,
            digest: strtolower((string) $evidence->content_sha256),
            periodKey: $periodKey,
            contentType: (string) ($evidence->content_type ?: 'application/pdf'),
            byteSize: (int) $evidence->byte_size,
            filename: 'dctfweb-darf-'.$periodKey.'.pdf',
            storageKind: 'fiscal_evidence',
            storageId: (int) $evidence->id,
        ));
    }

    private function readEvidence(int $id, int $tenantId): string
    {
        $model = FiscalEvidenceArtifact::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->findOrFail($id);

        return $this->evidenceStore->readAuthorized($model, $tenantId);
    }

    private function readGuide(int $id, int $tenantId): string
    {
        $model = TaxGuideVersion::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->findOrFail($id);

        return $this->guideStorage->readDocumentAuthorized($model, $tenantId);
    }

    private function safeFilename(?string $value, string $fallback): string
    {
        $name = basename(str_replace(["\0", '\\'], ['', '/'], trim((string) $value)));
        $name = preg_replace('/[^\pL\pN._-]+/u', '-', $name) ?? '';

        return $name !== '' ? mb_substr($name, 0, 180) : $fallback;
    }
}
