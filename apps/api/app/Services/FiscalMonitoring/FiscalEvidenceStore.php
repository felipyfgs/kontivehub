<?php

namespace App\Services\FiscalMonitoring;

use App\Contracts\SecureObjectStore;
use App\Enums\DocumentUnavailableReason;
use App\Enums\FiscalVerificationState;
use App\Enums\SecureObjectPurpose;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use Carbon\CarbonImmutable;
use RuntimeException;
use Throwable;

/**
 * Storage seguro de artefatos no cofre (AAD com purpose + office_id + sha256).
 * Download autorizado não expõe paths internos nem URLs permanentes.
 */
final class FiscalEvidenceStore
{
    public function __construct(
        private readonly SecureObjectStore $vault,
    ) {}

    /**
     * @return array{office_id:int,sha256:string,purpose:string}
     */
    public static function aad(int $officeId, string $sha256): array
    {
        return SecureObjectPurpose::FiscalEvidence->aadBase([
            'office_id' => $officeId,
            'sha256' => $sha256,
        ]);
    }

    /**
     * Persiste bytes no cofre e retorna o modelo de artefato (ainda não commitado em outer TX se chamado dentro).
     */
    public function store(
        FiscalMonitoringRun $run,
        string $bytes,
        string $contentType,
        string $source,
        ?string $sourceVersion = null,
        ?CarbonImmutable $observedAt = null,
    ): FiscalEvidenceArtifact {
        $max = (int) config('fiscal_monitoring.evidence.max_bytes', 5_242_880);
        $size = strlen($bytes);
        if ($size > $max) {
            throw new RuntimeException("Evidência excede limite de {$max} bytes.");
        }
        if ($size === 0) {
            throw new RuntimeException('Evidência vazia não é armazenada.');
        }

        $sha256 = hash('sha256', $bytes);

        // Idempotente por (office, sha, run) — adapters de módulo + núcleo podem
        // reutilizar o mesmo artefato na mesma execução sem violar unique.
        $existing = FiscalEvidenceArtifact::query()
            ->withoutGlobalScopes()
            ->where('office_id', $run->office_id)
            ->where('run_id', $run->id)
            ->where('content_sha256', $sha256)
            ->first();
        if ($existing !== null) {
            return $existing;
        }

        $aad = self::aad((int) $run->office_id, $sha256);
        $objectId = $this->vault->put($bytes, $aad);

        $retentionDays = (int) config('fiscal_monitoring.evidence.retention_days', 2555);
        $observed = $observedAt ?? CarbonImmutable::now();

        return FiscalEvidenceArtifact::query()->create([
            'office_id' => $run->office_id,
            'run_id' => $run->id,
            'vault_object_id' => $objectId,
            'content_sha256' => $sha256,
            'content_type' => $contentType,
            'byte_size' => $size,
            'source' => $source,
            'source_version' => $sourceVersion,
            'operation_key' => $run->operation_key,
            'source_provenance' => $run->source_provenance,
            'verification_state' => $run->verification_state,
            'observed_at' => $observed,
            'retention_until' => $observed->addDays($retentionDays),
            'is_immutable' => true,
            'metadata' => [
                'system_code' => $run->system_code,
                'service_code' => $run->service_code,
                'operation_key' => $run->operation_key,
            ],
            'created_at' => CarbonImmutable::now(),
        ]);
    }

    /**
     * Lê bytes do cofre com checagem de tenant. Nunca retorna path interno.
     *
     * @throws RuntimeException
     */
    public function readAuthorized(FiscalEvidenceArtifact $artifact, int $officeId): string
    {
        $reason = $this->unavailableReason($artifact, $officeId);
        if ($reason !== null) {
            throw new RuntimeException('Evidência fiscal indisponível para download.');
        }

        $aad = self::aad($officeId, $artifact->content_sha256);
        $bytes = $this->vault->get($artifact->vault_object_id, $aad);
        if (! hash_equals((string) $artifact->content_sha256, hash('sha256', $bytes))) {
            throw new RuntimeException('Integridade da evidência fiscal rejeitada.');
        }
        if ($artifact->byte_size !== null && strlen($bytes) !== (int) $artifact->byte_size) {
            throw new RuntimeException('Tamanho da evidência fiscal divergente.');
        }

        return $bytes;
    }

    public function unavailableReason(
        FiscalEvidenceArtifact $artifact,
        int $officeId,
    ): ?DocumentUnavailableReason {
        if ((int) $artifact->office_id !== $officeId) {
            return DocumentUnavailableReason::NotAvailable;
        }
        if ($artifact->verification_state === FiscalVerificationState::Rejected) {
            return DocumentUnavailableReason::IntegrityRejected;
        }
        if ($artifact->retention_until !== null && $artifact->retention_until->isPast()) {
            return DocumentUnavailableReason::Expired;
        }

        $metadata = is_array($artifact->metadata) ? $artifact->metadata : [];
        if (isset($metadata['purged_at'])) {
            return DocumentUnavailableReason::Expired;
        }
        if (! is_string($artifact->vault_object_id) || trim($artifact->vault_object_id) === ''
            || ! is_string($artifact->content_sha256)
            || preg_match('/^[a-f0-9]{64}$/', strtolower($artifact->content_sha256)) !== 1
        ) {
            return DocumentUnavailableReason::IntegrityRejected;
        }

        $run = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->whereKey($artifact->run_id)
            ->first(['id', 'office_id']);
        if ($run === null || (int) $run->office_id !== $officeId) {
            return DocumentUnavailableReason::IntegrityRejected;
        }

        try {
            if (! $this->vault->exists($artifact->vault_object_id)) {
                return DocumentUnavailableReason::NotAvailable;
            }
        } catch (Throwable) {
            return DocumentUnavailableReason::NotAvailable;
        }

        return null;
    }

    /**
     * Remove do cofre se retenção expirou (metadado; não apaga ledger/snapshot).
     */
    public function purgeIfExpired(FiscalEvidenceArtifact $artifact): bool
    {
        if ($artifact->retention_until === null || $artifact->retention_until->isFuture()) {
            return false;
        }

        if ($this->vault->exists($artifact->vault_object_id)) {
            $this->vault->delete($artifact->vault_object_id);
        }

        // Mantém linha de metadados (auditoria); zera referência opaca se desejado — aqui só marca metadata.
        $meta = $artifact->metadata ?? [];
        $meta['purged_at'] = CarbonImmutable::now()->toIso8601String();
        // is_immutable bloqueia vault_object_id; usamos forceFill + saveQuietly em coluna metadata permitida.
        $artifact->forceFill(['metadata' => $meta])->saveQuietly();

        return true;
    }
}
