<?php

namespace App\Services\Esocial;

use App\Contracts\SecureObjectStore;
use App\DTO\Esocial\EsocialEventDto;
use App\Enums\EsocialEventCode;
use App\Enums\SecureObjectPurpose;
use App\Models\Client;
use App\Models\EsocialEventEvidence;
use App\Models\Establishment;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Persiste evidências de eventos eSocial (S-5003, S-5013, S-1299) por competência/estabelecimento.
 * Idempotente por office+client+competência+event_code+sha256.
 */
final class EsocialEvidencePersistence
{
    public function __construct(
        private readonly SecureObjectStore $vault,
        private readonly FiscalEvidenceStore $fiscalEvidenceStore,
    ) {}

    /**
     * @param  list<EsocialEventDto>  $events
     * @return list<EsocialEventEvidence>
     */
    public function persistMany(
        Office $office,
        Client $client,
        array $events,
        ?FiscalMonitoringRun $run = null,
        ?Establishment $establishment = null,
        ?FiscalEvidenceArtifact $sharedFiscalArtifact = null,
    ): array {
        $saved = [];
        foreach ($events as $event) {
            if (! $event instanceof EsocialEventDto) {
                continue;
            }
            $row = $this->persistOne(
                $office,
                $client,
                $event,
                $run,
                $establishment,
                $sharedFiscalArtifact,
            );
            if ($row !== null) {
                $saved[] = $row;
            }
        }

        return $saved;
    }

    public function persistOne(
        Office $office,
        Client $client,
        EsocialEventDto $event,
        ?FiscalMonitoringRun $run = null,
        ?Establishment $establishment = null,
        ?FiscalEvidenceArtifact $sharedFiscalArtifact = null,
    ): ?EsocialEventEvidence {
        if (! in_array($event->eventCode, EsocialEventCode::supported(), true)) {
            return null;
        }

        if ((int) $client->office_id !== (int) $office->id) {
            throw new \RuntimeException('Cliente não pertence ao escritório.');
        }

        $sha = $event->contentSha256();
        $isSynthetic = $this->isSyntheticEvent($event);

        $existing = EsocialEventEvidence::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('competence_period_key', $event->competencePeriodKey)
            ->where('event_code', $event->eventCode->value)
            ->where('content_sha256', $sha)
            ->first();

        if ($existing !== null) {
            if ($isSynthetic && ! $existing->is_quarantined) {
                $existing->forceFill([
                    'is_quarantined' => true,
                    'quarantine_reason' => 'SYNTHETIC_ESOCIAL_TEST_DOUBLE',
                    'quarantined_at' => CarbonImmutable::now(),
                ])->save();
            }

            return $existing;
        }

        return DB::transaction(function () use (
            $office,
            $client,
            $event,
            $run,
            $establishment,
            $sharedFiscalArtifact,
            $sha,
            $isSynthetic,
        ) {
            $aad = SecureObjectPurpose::FiscalEvidence->aadBase([
                'office_id' => (int) $office->id,
                'sha256' => $sha,
                'esocial_event' => $event->eventCode->value,
            ]);
            $objectId = $this->vault->put($event->payloadBytes, $aad);

            $artifactId = $sharedFiscalArtifact?->id;
            if (! $isSynthetic && $artifactId === null && $run !== null) {
                // Artefato fiscal opcional por evento (quando run disponível)
                try {
                    $artifact = $this->fiscalEvidenceStore->store(
                        run: $run,
                        bytes: $event->payloadBytes,
                        contentType: (string) config('fgts_esocial.evidence.content_type', 'application/json'),
                        source: (string) config('fgts_esocial.evidence.source', 'esocial'),
                        sourceVersion: $event->eventVersion
                            ?? (string) config('fgts_esocial.evidence.source_version', 'unverified-1'),
                        observedAt: $event->observedAt ?? CarbonImmutable::now(),
                    );
                    $artifactId = $artifact->id;
                } catch (\Throwable) {
                    // Dedup do fiscal_evidence_artifacts por run+sha pode colidir se mesmo bytes;
                    // evidência eSocial própria no vault permanece suficiente.
                    $artifactId = null;
                }
            }

            $estId = $establishment?->id;
            $estCnpj = $event->establishmentCnpj;
            if ($estCnpj === null && $establishment !== null) {
                $estCnpj = (string) $establishment->cnpj;
            }

            return EsocialEventEvidence::query()->create([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'establishment_id' => $estId,
                'run_id' => $run?->id,
                'fiscal_evidence_artifact_id' => $artifactId,
                'competence_period_key' => $event->competencePeriodKey,
                'event_code' => $event->eventCode->value,
                'event_version' => $event->eventVersion,
                'receipt_number' => $event->receiptNumber,
                'establishment_cnpj' => $estCnpj !== null
                    ? strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $estCnpj) ?? $estCnpj)
                    : null,
                'content_sha256' => $sha,
                'vault_object_id' => $objectId,
                'content_type' => (string) config('fgts_esocial.evidence.content_type', 'application/json'),
                'byte_size' => strlen($event->payloadBytes),
                'source' => (string) config('fgts_esocial.evidence.source', 'esocial'),
                'source_version' => $event->eventVersion
                    ?? (string) config('fgts_esocial.evidence.source_version', 'unverified-1'),
                'occurred_at' => $event->occurredAt,
                'observed_at' => $event->observedAt ?? CarbonImmutable::now(),
                'metadata' => $event->metadata,
                'is_quarantined' => $isSynthetic,
                'quarantine_reason' => $isSynthetic ? 'SYNTHETIC_ESOCIAL_TEST_DOUBLE' : null,
                'quarantined_at' => $isSynthetic ? CarbonImmutable::now() : null,
            ]);
        });
    }

    public function isSyntheticEvent(EsocialEventDto $event): bool
    {
        return ($event->metadata['simulated'] ?? false) === true;
    }

    /**
     * @return list<EsocialEventEvidence>
     */
    public function listForCompetence(
        Office $office,
        Client $client,
        string $competencePeriodKey,
        ?int $establishmentId = null,
    ): array {
        $q = EsocialEventEvidence::query()
            ->withoutGlobalScopes()
            ->operationallyEligible()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('competence_period_key', $competencePeriodKey)
            ->orderBy('event_code')
            ->orderBy('id');

        if ($establishmentId !== null) {
            $q->where(function ($inner) use ($establishmentId) {
                $inner->where('establishment_id', $establishmentId)
                    ->orWhereNull('establishment_id');
            });
        }

        return $q->get()->all();
    }
}
