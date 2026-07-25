<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\DctfwebArtifactKind;
use App\Enums\DctfwebDeclarationState;
use App\Enums\FiscalSituation;
use App\Models\DctfwebDeclaration;
use App\Models\DctfwebEvidenceVersion;
use App\Models\Office;
use App\Models\TaxObligationProjection;
use Illuminate\Support\Collection;

/**
 * Enriquece o hub de declarações com dados locais DCTFWeb (recibo/estado/evidência).
 * Também gera linhas sintéticas quando há declaração local sem projeção de hub.
 */
final class DeclarationDctfwebEnrichmentService
{
    /**
     * @param  list<array<string, mixed>>  $rows  Já serializados (ex.: após enrichment PGDAS)
     * @return list<array<string, mixed>>
     */
    public function enrichPublicRows(Office $office, array $rows, ?int $clientId = null): array
    {
        $dctfwebRows = collect($rows)->filter(
            fn (array $row) => ($row['obligation_code'] ?? null) === 'DCTFWEB'
        );

        $clientIds = $dctfwebRows
            ->pluck('client_id')
            ->map(fn ($id) => (int) $id)
            ->when($clientId !== null, fn (Collection $c) => $c->push($clientId))
            ->unique()
            ->values()
            ->all();

        if ($clientIds === []) {
            return $rows;
        }

        $declarations = $this->loadDeclarations($office, $clientIds);
        $documents = $this->loadReciboDocuments($office, $clientIds);

        $enriched = collect($rows)->map(function (array $row) use ($declarations, $documents): array {
            if (($row['obligation_code'] ?? null) !== 'DCTFWEB') {
                return $row;
            }

            $key = (int) ($row['client_id'] ?? 0).'|'.(string) ($row['period_key'] ?? '');
            /** @var DctfwebDeclaration|null $declaration */
            $declaration = $declarations->get($key);
            if ($declaration === null) {
                return $row;
            }

            return $this->overlayDeclaration($row, $declaration, $documents->get($key));
        })->values();

        if ($clientId === null) {
            return $enriched->all();
        }

        $presentKeys = $enriched
            ->filter(fn (array $row) => ($row['obligation_code'] ?? null) === 'DCTFWEB')
            ->map(fn (array $row) => (int) ($row['client_id'] ?? 0).'|'.(string) ($row['period_key'] ?? ''))
            ->all();

        $synthetics = [];
        foreach ($declarations as $key => $declaration) {
            if ((int) $declaration->client_id !== $clientId) {
                continue;
            }
            if (in_array($key, $presentKeys, true)) {
                continue;
            }
            $synthetics[] = $this->syntheticRow($declaration, $documents->get($key));
        }

        return $enriched->concat($synthetics)->values()->all();
    }

    /**
     * Atalho a partir de models (testes / callers internos).
     *
     * @param  iterable<int, TaxObligationProjection>  $projections
     * @return list<array<string, mixed>>
     */
    public function enrichFromProjections(
        Office $office,
        iterable $projections,
        bool $withDeepLinks = true,
        ?int $clientId = null,
    ): array {
        $base = collect($projections)
            ->map(fn (TaxObligationProjection $p) => $p->toPublicArray($withDeepLinks))
            ->all();

        return $this->enrichPublicRows($office, $base, $clientId);
    }

    /**
     * @param  list<int>  $clientIds
     * @return Collection<string, DctfwebDeclaration>
     */
    private function loadDeclarations(Office $office, array $clientIds): Collection
    {
        $rows = DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('last_productive_consulted_at')
            ->orderByDesc('id')
            ->get();

        /** @var Collection<string, DctfwebDeclaration> $byKey */
        $byKey = collect();
        foreach ($rows as $row) {
            $key = (int) $row->client_id.'|'.(string) $row->period_key;
            if (! $byKey->has($key)) {
                $byKey->put($key, $row);
            }
        }

        return $byKey;
    }

    /**
     * @param  list<int>  $clientIds
     * @return Collection<string, array<string, mixed>>
     */
    private function loadReciboDocuments(Office $office, array $clientIds): Collection
    {
        $versions = DctfwebEvidenceVersion::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('client_id', $clientIds)
            ->where('artifact_kind', DctfwebArtifactKind::Recibo->value)
            ->where('is_current', true)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();

        $declarationIds = $versions->pluck('declaration_id')->filter()->unique()->values()->all();
        $periodByDecl = DctfwebDeclaration::query()
            ->withoutGlobalScopes()
            ->whereIn('id', $declarationIds)
            ->get(['id', 'client_id', 'period_key'])
            ->keyBy('id');

        /** @var Collection<string, array<string, mixed>> $byKey */
        $byKey = collect();
        foreach ($versions as $version) {
            $decl = $periodByDecl->get($version->declaration_id);
            if ($decl === null) {
                continue;
            }
            $key = (int) $decl->client_id.'|'.(string) $decl->period_key;
            if ($byKey->has($key)) {
                continue;
            }
            $byKey->put($key, $this->documentDescriptor(
                (int) $decl->client_id,
                (int) $version->id,
                $version->observed_at?->toIso8601String(),
                'Ver recibo DCTFWeb',
            ));
        }

        return $byKey;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>|null  $document
     * @return array<string, mixed>
     */
    private function overlayDeclaration(array $row, DctfwebDeclaration $declaration, ?array $document): array
    {
        $effective = $this->effectiveSituation($declaration);
        if ($effective !== null) {
            $row['situation'] = $effective;
            $row['delivery_status'] = $effective;
        }

        $receipt = filled($declaration->receipt_number) ? (string) $declaration->receipt_number : null;
        if ($receipt !== null) {
            $row['declaration_number'] = $receipt;
            $row['receipt_number'] = $receipt;
        }

        if ($declaration->declaration_state instanceof DctfwebDeclarationState) {
            $row['dctfweb_declaration_state'] = $declaration->declaration_state->value;
        }

        $row['dctfweb_declaration_id'] = $declaration->id;
        $row['source'] = 'DCTFWEB_CONSULT';
        $row['no_movement'] = (bool) $declaration->no_movement;

        if ($declaration->due_at !== null && empty($row['due_at'])) {
            $row['due_at'] = $declaration->due_at->toIso8601String();
        }

        if (is_array($document)) {
            $row['document'] = $document;
        }

        return $row;
    }

    /**
     * @param  array<string, mixed>|null  $document
     * @return array<string, mixed>
     */
    private function syntheticRow(DctfwebDeclaration $declaration, ?array $document): array
    {
        $effective = $this->effectiveSituation($declaration) ?? FiscalSituation::Unknown->value;
        $receipt = filled($declaration->receipt_number) ? (string) $declaration->receipt_number : null;

        $row = [
            'id' => 'dctfweb-decl-'.$declaration->id,
            'office_id' => $declaration->office_id,
            'client_id' => $declaration->client_id,
            'obligation_definition_id' => null,
            'obligation_code' => 'DCTFWEB',
            'obligation_name' => 'DCTFWeb',
            'module_key' => 'declaracoes',
            'system_code' => 'INTEGRA_DCTFWEB',
            'service_code' => 'DCTFWEB',
            'period_key' => $declaration->period_key,
            'period_year' => is_string($declaration->period_key) && strlen($declaration->period_key) >= 4
                ? (int) substr($declaration->period_key, 0, 4)
                : null,
            'period_month' => is_string($declaration->period_key) && preg_match('/^\d{4}-(\d{2})$/', $declaration->period_key, $m)
                ? (int) $m[1]
                : null,
            'situation' => $effective,
            'delivery_status' => $effective,
            'due_at' => $declaration->due_at?->toIso8601String(),
            'is_open' => true,
            'source' => 'DCTFWEB_CONSULT',
            'dctfweb_declaration_id' => $declaration->id,
            'no_movement' => (bool) $declaration->no_movement,
        ];

        if ($receipt !== null) {
            $row['declaration_number'] = $receipt;
            $row['receipt_number'] = $receipt;
        }
        if ($declaration->declaration_state instanceof DctfwebDeclarationState) {
            $row['dctfweb_declaration_state'] = $declaration->declaration_state->value;
        }
        if (is_array($document)) {
            $row['document'] = $document;
        }

        return $row;
    }

    private function effectiveSituation(DctfwebDeclaration $declaration): ?string
    {
        if ($declaration->situation instanceof FiscalSituation
            && ! in_array($declaration->situation, [FiscalSituation::Unknown, FiscalSituation::Pending], true)
        ) {
            return $declaration->situation->value;
        }

        $state = $declaration->declaration_state;
        if (filled($declaration->receipt_number)) {
            return FiscalSituation::UpToDate->value;
        }

        return match ($state) {
            DctfwebDeclarationState::Current,
            DctfwebDeclarationState::NoMovementValid => FiscalSituation::UpToDate->value,
            DctfwebDeclarationState::OverdueNotFound => FiscalSituation::Attention->value,
            DctfwebDeclarationState::DueWithinDeadline => FiscalSituation::Pending->value,
            default => null,
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function documentDescriptor(int $clientId, int $evidenceVersionId, ?string $observedAt, string $label): array
    {
        return [
            'available' => true,
            'kind' => 'PDF',
            'label' => $label,
            'content_type' => 'application/pdf',
            'observed_at' => $observedAt,
            'source_surface' => 'dctfweb',
            'source_label' => 'DCTFWeb',
            'href' => "/api/v1/fiscal/dctfweb/clients/{$clientId}/evidence/{$evidenceVersionId}/download",
            'unavailable_reason' => null,
        ];
    }
}
