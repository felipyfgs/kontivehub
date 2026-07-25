<?php

namespace App\Services\Fiscal\Dctfweb;

use App\Enums\DctfwebCategory;
use App\Enums\DctfwebDeclarationState;
use App\Models\Client;
use App\Models\DctfwebConsultObservation;
use App\Models\DctfwebDeclaration;
use App\Models\DctfwebEvidenceVersion;
use App\Models\Office;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use RuntimeException;

/**
 * Consultas tenant-scoped da carteira/histórico DCTFWeb (somente leitura local).
 */
final class DctfwebMonitoringQueryService
{
    public function __construct(
        private readonly DctfwebCommunicationService $communication,
    ) {}

    /**
     * Detalhe de carteira por cliente (ModulePortfolio).
     *
     * @param  list<int>  $clientIds
     * @return array<int, array<string, mixed>>
     */
    public function portfolioDetails(Office $office, array $clientIds): array
    {
        if ($clientIds === []) {
            return [];
        }

        $tz = is_string($office->timezone) && $office->timezone !== ''
            ? $office->timezone
            : 'America/Sao_Paulo';
        $expectedPa = DctfwebPeriod::expectedPa(null, $tz);
        $periodKey = DctfwebPeriod::toPeriodKey($expectedPa);
        $category = DctfwebCategory::default();

        $definition = TaxObligationDefinition::query()->where('code', 'DCTFWEB')->first();
        $projections = TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('client_id', $clientIds)
            ->where('period_key', $periodKey)
            ->where('obligation_definition_id', $definition?->id ?? 0)
            ->get()
            ->keyBy('client_id');

        $communications = $this->communication->summariesForClients($office, $clientIds);

        $declarations = DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('client_id', $clientIds)
            ->where('category', $category->value)
            ->orderByDesc('period_key')
            ->orderByDesc('id')
            ->get()
            ->groupBy('client_id');

        $lastObs = DctfwebConsultObservation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('client_id', $clientIds)
            ->where('category', $category->value)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get()
            ->groupBy('client_id');

        $map = [];
        foreach ($clientIds as $cid) {
            $proj = $projections->get($cid);
            $clientDecls = $declarations->get($cid, collect());
            $lastDecl = $clientDecls->first(
                static fn (DctfwebDeclaration $d) => $d->period_key === $periodKey
            ) ?? $clientDecls->first();
            $obs = $lastObs->get($cid, collect())->first();

            $state = $proj?->dctfweb_declaration_state?->value
                ?? $lastDecl?->declaration_state?->value
                ?? DctfwebDeclarationState::Unverified->value;

            $lastPublic = $lastDecl?->toPublicArray();
            $lastSearchAt = $proj?->dctfweb_last_productive_consulted_at
                ?? $lastDecl?->last_productive_consulted_at
                ?? $obs?->observed_at;

            $comm = $communications[$cid] ?? null;

            $map[$cid] = [
                'module_key' => 'dctfweb',
                'submodule' => 'DCTFWEB',
                'category' => $category->value,
                'expected_period_key' => $periodKey,
                'expected_periodo_apuracao' => DctfwebPeriod::toPeriodoApuracao($expectedPa),
                'period_key' => $periodKey,
                'declaration_state' => $state,
                'declaration_state_reason' => $lastDecl?->state_reason
                    ?? $this->stateReason($proj),
                'last_declaration' => $lastPublic,
                'latest_declaration' => $lastPublic === null ? null : [
                    'id' => $lastPublic['id'] ?? null,
                    'period_key' => $lastPublic['period_key'] ?? null,
                    'category' => $lastPublic['category'] ?? $category->value,
                    'receipt_number' => $lastPublic['receipt_number'] ?? null,
                    'declaration_type' => $lastPublic['declaration_type'] ?? null,
                    'transmission_status' => $lastPublic['transmission_status'] ?? null,
                    'situation' => $lastPublic['situation'] ?? null,
                    'transmitted_at' => $lastPublic['transmitted_at'] ?? null,
                    'no_movement' => $lastPublic['no_movement'] ?? null,
                    'declaration_state' => $lastPublic['declaration_state'] ?? null,
                ],
                'last_productive_consulted_at' => $lastSearchAt?->toIso8601String(),
                'last_valid_query_at' => $lastSearchAt?->toIso8601String(),
                'last_search_at' => $lastSearchAt?->toIso8601String(),
                'calendar_verified' => (bool) ($proj?->dctfweb_calendar_verified
                    ?? $lastDecl?->calendar_verified
                    ?? false),
                'communication' => $comm,
                'has_history' => $clientDecls->isNotEmpty()
                    || $lastObs->get($cid, collect())->isNotEmpty(),
                'has_tracking' => is_array($comm)
                    && ($comm['tracking_status'] ?? null) !== null
                    && ($comm['tracking_status'] ?? '') !== 'NO_HISTORY'
                    && ($comm['tracking_status'] ?? '') !== 'NOT_CONFIGURED',
                'links' => [
                    'history' => "/api/v1/fiscal/dctfweb/clients/{$cid}/history",
                    'preferences' => "/api/v1/fiscal/dctfweb/clients/{$cid}/communication-preference",
                    'preview' => "/api/v1/fiscal/dctfweb/clients/{$cid}/communication-preview",
                    'tracking' => "/api/v1/fiscal/dctfweb/clients/{$cid}/communications",
                ],
            ];
        }

        return $map;
    }

    /**
     * Histórico local — sem SERPRO.
     *
     * @return array<string, mixed>
     */
    public function history(Office $office, Client $client, ?int $year = null): array
    {
        $this->assertClient($office, $client);
        if ($year !== null && ($year < 2000 || $year > 2100)) {
            throw new RuntimeException('Ano do histórico inválido.');
        }

        $category = DctfwebCategory::default();
        $tz = is_string($office->timezone) && $office->timezone !== ''
            ? $office->timezone
            : 'America/Sao_Paulo';
        $expectedPeriodKey = DctfwebPeriod::toPeriodKey(DctfwebPeriod::expectedPa(null, $tz));

        $declarations = DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->when($year !== null, fn ($q) => $q->where('period_key', 'like', sprintf('%04d-%%', $year)))
            ->orderByDesc('period_key')
            ->orderByDesc('id')
            ->get();

        $observations = DctfwebConsultObservation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->when($year !== null, fn ($q) => $q->where('period_key', 'like', sprintf('%04d-%%', $year)))
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();

        $evidence = DctfwebEvidenceVersion::query()
            ->withoutGlobalScopes()
            ->with('artifact:id,content_type,byte_size')
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->whereIn('declaration_id', $declarations->pluck('id')->filter()->all() ?: [0])
            ->orderByDesc('observed_at')
            ->get();

        $byPeriod = [];
        foreach ($declarations as $decl) {
            $pk = (string) $decl->period_key;
            if (! isset($byPeriod[$pk])) {
                $byPeriod[$pk] = $this->emptyPeriodBucket($pk);
            }
            $byPeriod[$pk]['declarations'][] = $decl->toPublicArray();
            $byPeriod[$pk]['declaration_state'] = $decl->declaration_state?->value
                ?? DctfwebDeclarationState::Unverified->value;
            $byPeriod[$pk]['last_valid_query_at'] = $decl->last_productive_consulted_at?->toIso8601String();
        }

        foreach ($observations as $obs) {
            $pk = (string) $obs->period_key;
            if (! isset($byPeriod[$pk])) {
                $byPeriod[$pk] = $this->emptyPeriodBucket($pk);
            }
            $byPeriod[$pk]['observations'][] = $obs->toPublicArray();
            if ($byPeriod[$pk]['last_valid_query_at'] === null && $obs->productive) {
                $byPeriod[$pk]['last_valid_query_at'] = $obs->observed_at?->toIso8601String();
            }
        }

        foreach ($evidence as $ev) {
            $decl = $declarations->firstWhere('id', $ev->declaration_id);
            $pk = $decl?->period_key ?? $expectedPeriodKey;
            if (! isset($byPeriod[$pk])) {
                $byPeriod[$pk] = $this->emptyPeriodBucket($pk);
            }
            $byPeriod[$pk]['documents'][] = $this->evidencePublicArray($ev, (int) $client->id);
            $byPeriod[$pk]['artifacts'][] = $this->evidencePublicArray($ev, (int) $client->id);
        }

        krsort($byPeriod);
        $periods = array_values($byPeriod);

        $proj = $this->currentProjection($office, $client, $expectedPeriodKey);
        $state = $proj?->dctfweb_declaration_state?->value
            ?? $declarations->firstWhere('period_key', $expectedPeriodKey)?->declaration_state?->value
            ?? DctfwebDeclarationState::Unverified->value;

        return [
            'client' => [
                'id' => $client->id,
                'legal_name' => $client->legal_name,
                'cnpj_masked' => $this->cnpjMasked($client),
            ],
            'expected_period_key' => $expectedPeriodKey,
            'category' => $category->value,
            'declaration_state' => $state,
            'last_valid_query_at' => $proj?->dctfweb_last_productive_consulted_at?->toIso8601String()
                ?? $declarations->first()?->last_productive_consulted_at?->toIso8601String(),
            'periods' => $periods,
            'history' => $periods,
            'declarations' => $declarations->map->toPublicArray()->values()->all(),
            'observations' => $observations->map->toPublicArray()->values()->all(),
            'artifacts' => $evidence->map(
                fn (DctfwebEvidenceVersion $ev): array => $this->evidencePublicArray($ev, (int) $client->id)
            )->values()->all(),
            'provenance' => [
                'source' => 'LOCAL_PROJECTION',
                'serpro_called' => false,
            ],
        ];
    }

    public function findEvidenceVersion(Office $office, Client $client, int $versionId): ?DctfwebEvidenceVersion
    {
        return DctfwebEvidenceVersion::query()
            ->withoutGlobalScopes()
            ->with('artifact')
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->whereKey($versionId)
            ->first();
    }

    public function findEvidenceVersionForOffice(Office $office, int $versionId): ?DctfwebEvidenceVersion
    {
        return DctfwebEvidenceVersion::query()
            ->withoutGlobalScopes()
            ->with('artifact')
            ->where('office_id', $office->id)
            ->whereKey($versionId)
            ->first();
    }

    private function currentProjection(Office $office, Client $client, string $periodKey): ?TaxObligationProjection
    {
        $definitionId = TaxObligationDefinition::query()->where('code', 'DCTFWEB')->value('id');

        return TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('period_key', $periodKey)
            ->where('obligation_definition_id', $definitionId ?? 0)
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyPeriodBucket(string $periodKey): array
    {
        return [
            'period_key' => $periodKey,
            'declaration_state' => null,
            'last_valid_query_at' => null,
            'declarations' => [],
            'observations' => [],
            'documents' => [],
            'artifacts' => [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function evidencePublicArray(DctfwebEvidenceVersion $ev, int $clientId): array
    {
        $meta = is_array($ev->metadata) ? $ev->metadata : [];
        $document = $this->documentMetadata($ev);

        return [
            'id' => $ev->id,
            'kind' => $ev->artifact_kind?->value ?? (string) $ev->artifact_kind,
            'version' => $ev->version,
            'is_current' => (bool) $ev->is_current,
            'is_retification' => (bool) $ev->is_retification,
            'declaration_id' => $ev->declaration_id,
            'filename' => $document['filename'],
            'content_type' => $document['content_type'],
            'byte_size' => $ev->artifact?->byte_size ?? $meta['byte_size'] ?? null,
            'observed_at' => $ev->observed_at?->toIso8601String(),
            'download_path' => "/api/v1/fiscal/dctfweb/clients/{$clientId}/evidence/{$ev->id}/download",
            // Sem content_sha256 / path interno em resposta pública.
        ];
    }

    /**
     * Metadados seguros para a resposta pública e o streaming autorizado.
     * Nunca usa nome fornecido externamente ou caminho do cofre.
     *
     * @return array{content_type:string,filename:string}
     */
    public function documentMetadata(DctfwebEvidenceVersion $version): array
    {
        $version->loadMissing('artifact');
        $contentType = match (strtolower(trim((string) $version->artifact?->content_type))) {
            'application/pdf' => 'application/pdf',
            'application/xml' => 'application/xml',
            'text/xml' => 'text/xml',
            default => 'application/octet-stream',
        };
        $extension = match ($contentType) {
            'application/pdf' => 'pdf',
            'application/xml', 'text/xml' => 'xml',
            default => 'bin',
        };
        $kind = strtolower((string) ($version->artifact_kind?->value ?? 'evidencia'));
        $kind = preg_replace('/[^a-z0-9]+/', '-', $kind) ?: 'evidencia';

        return [
            'content_type' => $contentType,
            'filename' => sprintf('dctfweb-%s-%d.%s', trim($kind, '-'), $version->id, $extension),
        ];
    }

    private function stateReason(?TaxObligationProjection $proj): ?string
    {
        if ($proj === null) {
            return null;
        }
        $meta = is_array($proj->metadata) ? $proj->metadata : [];

        return is_string($meta['dctfweb_state_reason'] ?? null)
            ? $meta['dctfweb_state_reason']
            : null;
    }

    private function cnpjMasked(Client $client): ?string
    {
        $raw = preg_replace('/\D/', '', (string) ($client->cnpj ?? $client->tax_id ?? '')) ?? '';
        if (strlen($raw) !== 14) {
            return null;
        }

        return substr($raw, 0, 2).'.***.***/****-'.substr($raw, -2);
    }

    private function assertClient(Office $office, Client $client): void
    {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new RuntimeException('Cliente não pertence ao escritório atual.');
        }
    }
}
