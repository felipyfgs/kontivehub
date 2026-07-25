<?php

namespace App\Services\Fiscal\Declarations;

use App\Enums\FiscalSituation;
use App\Enums\PgdasdDeclarationState;
use App\Enums\PgdasdOperationKind;
use App\Models\Office;
use App\Models\PgdasdArtifact;
use App\Models\PgdasdOperation;
use App\Models\TaxObligationProjection;
use Illuminate\Support\Collection;

/**
 * Enriquece o hub de declarações com dados locais da consulta PGDAS-D.
 * Não persiste overwrite em tax_obligation_projections; só a resposta pública.
 */
final class DeclarationPgdasdEnrichmentService
{
    /**
     * @param  iterable<int, TaxObligationProjection>  $projections
     * @return list<array<string, mixed>>
     */
    public function enrichPublicList(Office $office, iterable $projections, bool $withDeepLinks = true): array
    {
        /** @var Collection<int, TaxObligationProjection> $items */
        $items = collect($projections)->values();
        if ($items->isEmpty()) {
            return [];
        }

        $pgdasItems = $items->filter(function (TaxObligationProjection $p): bool {
            $code = $p->relationLoaded('obligation')
                ? $p->obligation?->code
                : null;

            return $code === 'PGDAS_D';
        });

        $declarationsByClientPeriod = $this->loadDeclarations($office, $pgdasItems);
        $documentsByClientPeriod = $this->loadDocuments($office, $pgdasItems);

        return $items->map(function (TaxObligationProjection $projection) use (
            $withDeepLinks,
            $declarationsByClientPeriod,
            $documentsByClientPeriod,
        ): array {
            $row = $projection->toPublicArray($withDeepLinks);
            $code = $row['obligation_code'] ?? null;
            if ($code !== 'PGDAS_D') {
                return $row;
            }

            $clientId = (int) $projection->client_id;
            $periodKey = (string) $projection->period_key;
            $key = $clientId.'|'.$periodKey;

            /** @var PgdasdOperation|null $declaration */
            $declaration = $declarationsByClientPeriod->get($key);
            $state = $projection->pgdasd_declaration_state;

            $effective = $this->effectiveSituation($declaration, $state);
            if ($effective !== null) {
                $row['situation'] = $effective;
                $row['delivery_status'] = $effective;
            }

            if ($declaration !== null) {
                $row['declaration_number'] = $declaration->declaration_number;
                $row['pgdasd_operation_id'] = $declaration->id;
                $row['source'] = 'PGDASD_CONSULT';
                if ($declaration->transmitted_at !== null) {
                    $row['transmitted_at'] = $declaration->transmitted_at->toIso8601String();
                }
            }

            if ($state instanceof PgdasdDeclarationState) {
                $row['pgdasd_declaration_state'] = $state->value;
            }

            $doc = $documentsByClientPeriod->get($key);
            if (is_array($doc)) {
                $row['document'] = $doc;
            }

            return $row;
        })->all();
    }

    /**
     * @param  Collection<int, TaxObligationProjection>  $pgdasItems
     * @return Collection<string, PgdasdOperation>
     */
    private function loadDeclarations(Office $office, Collection $pgdasItems): Collection
    {
        if ($pgdasItems->isEmpty()) {
            return collect();
        }

        $clientIds = $pgdasItems->pluck('client_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $periodKeys = $pgdasItems->pluck('period_key')->map(fn ($k) => (string) $k)->unique()->values()->all();

        $ops = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('client_id', $clientIds)
            ->whereIn('period_key', $periodKeys)
            ->where('kind', PgdasdOperationKind::Declaration->value)
            ->orderByDesc('transmitted_at')
            ->orderByDesc('id')
            ->get();

        /** @var Collection<string, PgdasdOperation> $byKey */
        $byKey = collect();
        foreach ($ops as $op) {
            $key = (int) $op->client_id.'|'.(string) $op->period_key;
            if (! $byKey->has($key)) {
                $byKey->put($key, $op);
            }
        }

        return $byKey;
    }

    /**
     * @param  Collection<int, TaxObligationProjection>  $pgdasItems
     * @return Collection<string, array<string, mixed>>
     */
    private function loadDocuments(Office $office, Collection $pgdasItems): Collection
    {
        if ($pgdasItems->isEmpty()) {
            return collect();
        }

        $clientIds = $pgdasItems->pluck('client_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        $periodKeys = $pgdasItems->pluck('period_key')->map(fn ($k) => (string) $k)->unique()->values()->all();

        $artifacts = PgdasdArtifact::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereIn('client_id', $clientIds)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();

        /** @var Collection<string, array<string, mixed>> $byKey */
        $byKey = collect();
        foreach ($artifacts as $art) {
            $metaPeriod = is_array($art->metadata) ? (string) ($art->metadata['period_key'] ?? '') : '';
            $period = $metaPeriod !== '' ? $metaPeriod : null;
            if ($period === null || ! in_array($period, $periodKeys, true)) {
                continue;
            }
            $key = (int) $art->client_id.'|'.$period;
            if ($byKey->has($key)) {
                continue;
            }
            $byKey->put($key, [
                'available' => true,
                'kind' => str_contains(strtolower((string) $art->content_type), 'pdf') ? 'PDF' : 'FILE',
                'label' => 'Ver declaração/recibo',
                'content_type' => $art->content_type,
                'observed_at' => $art->observed_at?->toIso8601String(),
                'source_surface' => 'simples_mei_pgdasd',
                'source_label' => 'PGDAS-D',
                'href' => '/api/v1/fiscal/simples-mei/pgdasd/artifacts/'.$art->id.'/download',
                'unavailable_reason' => null,
            ]);
        }

        return $byKey;
    }

    private function effectiveSituation(
        ?PgdasdOperation $declaration,
        ?PgdasdDeclarationState $state,
    ): ?string {
        if ($declaration !== null && filled($declaration->declaration_number)) {
            return FiscalSituation::UpToDate->value;
        }

        if ($state instanceof PgdasdDeclarationState) {
            return $state->toFiscalSituation()->value;
        }

        return null;
    }
}
