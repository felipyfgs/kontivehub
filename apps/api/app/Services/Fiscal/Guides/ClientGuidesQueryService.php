<?php

namespace App\Services\Fiscal\Guides;

use App\Enums\DctfwebArtifactKind;
use App\Enums\FiscalPaymentStatus;
use App\Enums\PgdasdOperationKind;
use App\Enums\TaxGuideEmissionStatus;
use App\Enums\TaxGuidePaymentStatus;
use App\Models\DctfwebDarfDocument;
use App\Models\DctfwebEvidenceVersion;
use App\Models\Office;
use App\Models\PgdasdArtifact;
use App\Models\PgdasdOperation;
use App\Models\TaxGuide;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Collection;

/**
 * Lista guias (cliente ou office) unindo tax_guides + DAS PGDAS-D + DARF DCTFWeb.
 *
 * @phpstan-type PaymentCounters array{
 *   UNKNOWN: int,
 *   NOT_CONFIRMED: int,
 *   CONFIRMED: int,
 *   PARTIAL: int
 * }
 * @phpstan-type GuideIndexRow array{
 *   source_type: 'TAX_GUIDE'|'PGDASD_DAS'|'DCTFWEB_DARF',
 *   source_id: int,
 *   client_id: int,
 *   identifier_code: string|null,
 *   payment_status: string,
 *   competence_period_key: string|null,
 *   amount_cents: int|null,
 *   due_at: string|null,
 *   system_code: string|null
 * }
 */
final class ClientGuidesQueryService
{
    /**
     * @return array{page: LengthAwarePaginator, payment_counters: PaymentCounters}
     */
    public function paginate(
        Office $office,
        ?int $clientId = null,
        int $perPage = 50,
        ?string $paymentStatus = null,
        string $sort = '',
        string $direction = '',
    ): array {
        $perPage = min(100, max(1, $perPage));

        $index = $this->buildIndex($office, $clientId);
        $paymentCounters = $this->countPayments($index);

        $merged = $index;
        if ($paymentStatus !== null && $paymentStatus !== '') {
            $want = strtoupper($paymentStatus);
            $merged = $merged->filter(
                fn (array $row) => strtoupper((string) ($row['payment_status'] ?? '')) === $want
            )->values();
        }

        $merged = $this->sortRows($merged, $sort, $direction);

        $total = $merged->count();
        $page = max(1, (int) request()->query('page', 1));
        /** @var Collection<int, GuideIndexRow> $slice */
        $slice = $merged->slice(($page - 1) * $perPage, $perPage)->values();
        $hydrated = $this->hydratePage($office, $clientId, $slice);

        $paginator = new Paginator(
            $hydrated->all(),
            $total,
            $perPage,
            $page,
            [
                'path' => Paginator::resolveCurrentPath(),
                'query' => request()->query(),
            ],
        );

        return [
            'page' => $paginator,
            'payment_counters' => $paymentCounters,
        ];
    }

    /**
     * @return Collection<int, GuideIndexRow>
     */
    private function buildIndex(Office $office, ?int $clientId): Collection
    {
        $issuedQuery = TaxGuide::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->select([
                'id',
                'client_id',
                'identifier_code',
                'payment_status',
                'competence_period_key',
                'amount_cents',
                'due_at',
                'system_code',
            ]);
        if ($clientId !== null) {
            $issuedQuery->where('client_id', $clientId);
        }
        $issued = $issuedQuery->get();

        $issuedNumbers = $issued
            ->pluck('identifier_code')
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->map(fn ($v) => (string) $v)
            ->values()
            ->all();

        /** @var Collection<int, GuideIndexRow> $rows */
        $rows = $issued->map(static function (TaxGuide $guide): array {
            return [
                'source_type' => 'TAX_GUIDE',
                'source_id' => (int) $guide->id,
                'client_id' => (int) $guide->client_id,
                'identifier_code' => is_string($guide->identifier_code) && $guide->identifier_code !== ''
                    ? (string) $guide->identifier_code
                    : null,
                'payment_status' => strtoupper((string) ($guide->payment_status?->value ?? $guide->payment_status ?? TaxGuidePaymentStatus::Unknown->value)),
                'competence_period_key' => $guide->competence_period_key,
                'amount_cents' => $guide->amount_cents !== null ? (int) $guide->amount_cents : null,
                'due_at' => $guide->due_at?->toIso8601String(),
                'system_code' => $guide->system_code,
            ];
        });

        $dasQuery = PgdasdOperation::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('kind', PgdasdOperationKind::Das->value)
            ->select([
                'id',
                'client_id',
                'das_number',
                'period_key',
                'payment_located',
            ]);
        if ($clientId !== null) {
            $dasQuery->where('client_id', $clientId);
        }
        $dasOps = $dasQuery->get();

        $virtualDas = $dasOps
            ->filter(function (PgdasdOperation $op) use ($issuedNumbers): bool {
                $das = (string) ($op->das_number ?? '');

                return $das !== '' && ! in_array($das, $issuedNumbers, true);
            })
            ->map(static function (PgdasdOperation $op): array {
                $das = (string) ($op->das_number ?? '');
                $payment = match ($op->payment_located) {
                    true => TaxGuidePaymentStatus::Confirmed->value,
                    false => TaxGuidePaymentStatus::NotConfirmed->value,
                    default => TaxGuidePaymentStatus::Unknown->value,
                };

                return [
                    'source_type' => 'PGDASD_DAS',
                    'source_id' => (int) $op->id,
                    'client_id' => (int) $op->client_id,
                    'identifier_code' => $das !== '' ? $das : null,
                    'payment_status' => $payment,
                    'competence_period_key' => $op->period_key,
                    'amount_cents' => null,
                    'due_at' => null,
                    'system_code' => 'INTEGRA_SN',
                ];
            });

        $virtualNumbers = $virtualDas
            ->pluck('identifier_code')
            ->filter(fn ($v) => is_string($v) && $v !== '')
            ->map(fn ($v) => (string) $v)
            ->all();

        $takenNumbers = array_values(array_unique([...$issuedNumbers, ...$virtualNumbers]));

        $darfQuery = DctfwebDarfDocument::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->with(['declaration' => fn ($q) => $q->withoutGlobalScopes()->select(['id', 'period_key'])])
            ->select([
                'id',
                'client_id',
                'document_number',
                'payment_status',
                'amount',
                'due_at',
                'declaration_id',
            ]);
        if ($clientId !== null) {
            $darfQuery->where('client_id', $clientId);
        }
        $darfs = $darfQuery->get();

        $virtualDarf = $darfs
            ->filter(function (DctfwebDarfDocument $darf) use ($takenNumbers): bool {
                $num = (string) ($darf->document_number ?? '');

                return $num !== '' && ! in_array($num, $takenNumbers, true);
            })
            ->map(static function (DctfwebDarfDocument $darf): array {
                $number = (string) ($darf->document_number ?? '');
                $payment = match ($darf->payment_status) {
                    FiscalPaymentStatus::Paid => TaxGuidePaymentStatus::Confirmed->value,
                    FiscalPaymentStatus::Unpaid => TaxGuidePaymentStatus::NotConfirmed->value,
                    default => TaxGuidePaymentStatus::Unknown->value,
                };
                $amountCents = null;
                if ($darf->amount !== null && $darf->amount !== '') {
                    $amountCents = (int) round(((float) $darf->amount) * 100);
                }

                return [
                    'source_type' => 'DCTFWEB_DARF',
                    'source_id' => (int) $darf->id,
                    'client_id' => (int) $darf->client_id,
                    'identifier_code' => $number !== '' ? $number : null,
                    'payment_status' => $payment,
                    'competence_period_key' => $darf->declaration?->period_key,
                    'amount_cents' => $amountCents,
                    'due_at' => $darf->due_at?->toIso8601String(),
                    'system_code' => 'INTEGRA_DCTFWEB',
                ];
            });

        return $rows->concat($virtualDas)->concat($virtualDarf)->values();
    }

    /**
     * @param  Collection<int, GuideIndexRow>  $slice
     * @return Collection<int, array<string, mixed>>
     */
    private function hydratePage(Office $office, ?int $clientId, Collection $slice): Collection
    {
        if ($slice->isEmpty()) {
            return collect();
        }

        $taxIds = $slice->where('source_type', 'TAX_GUIDE')->pluck('source_id')->all();
        $dasIds = $slice->where('source_type', 'PGDASD_DAS')->pluck('source_id')->all();
        $darfIds = $slice->where('source_type', 'DCTFWEB_DARF')->pluck('source_id')->all();

        $taxGuides = $taxIds === []
            ? collect()
            : TaxGuide::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereIn('id', $taxIds)
                ->with(['currentVersion' => fn ($q) => $q->withoutGlobalScopes()])
                ->get()
                ->keyBy('id');

        $dasOps = $dasIds === []
            ? collect()
            : PgdasdOperation::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereIn('id', $dasIds)
                ->get()
                ->keyBy('id');

        $darfs = $darfIds === []
            ? collect()
            : DctfwebDarfDocument::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->whereIn('id', $darfIds)
                ->with(['declaration' => fn ($q) => $q->withoutGlobalScopes()])
                ->get()
                ->keyBy('id');

        $documentsByDas = $dasIds === [] ? collect() : $this->documentsByDasNumber($office, $clientId);
        $documentsByDarf = $darfIds === [] ? collect() : $this->documentsByDarfEvidence($office, $clientId);

        return $slice->map(function (array $row) use ($taxGuides, $dasOps, $darfs, $documentsByDas, $documentsByDarf): ?array {
            return match ($row['source_type']) {
                'TAX_GUIDE' => $this->hydrateTaxGuide($taxGuides->get($row['source_id'])),
                'PGDASD_DAS' => $this->hydrateDas($dasOps->get($row['source_id']), $documentsByDas),
                'DCTFWEB_DARF' => $this->hydrateDarf($darfs->get($row['source_id']), $documentsByDarf),
            };
        })->filter()->values();
    }

    /**
     * @return array<string, mixed>|null
     */
    private function hydrateTaxGuide(?TaxGuide $guide): ?array
    {
        if ($guide === null) {
            return null;
        }
        $row = $guide->toPublicArray();
        $row['source'] = ($guide->metadata['source'] ?? null) === 'FGTS_DIGITAL_PORTAL'
            ? 'FGTS_DIGITAL_PORTAL'
            : 'TAX_GUIDE';

        return $row;
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $documentsByDas
     * @return array<string, mixed>|null
     */
    private function hydrateDas(?PgdasdOperation $op, Collection $documentsByDas): ?array
    {
        if ($op === null) {
            return null;
        }

        return $this->dasToPublicGuide($op, $documentsByDas);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $documentsByDarf
     * @return array<string, mixed>|null
     */
    private function hydrateDarf(?DctfwebDarfDocument $darf, Collection $documentsByDarf): ?array
    {
        if ($darf === null) {
            return null;
        }

        return $this->darfToPublicGuide($darf, $documentsByDarf);
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return PaymentCounters
     */
    private function countPayments(Collection $rows): array
    {
        $counters = [
            TaxGuidePaymentStatus::Unknown->value => 0,
            TaxGuidePaymentStatus::NotConfirmed->value => 0,
            TaxGuidePaymentStatus::Confirmed->value => 0,
            TaxGuidePaymentStatus::Partial->value => 0,
        ];

        foreach ($rows as $row) {
            $status = strtoupper((string) ($row['payment_status'] ?? TaxGuidePaymentStatus::Unknown->value));
            if (! array_key_exists($status, $counters)) {
                $status = TaxGuidePaymentStatus::Unknown->value;
            }
            $counters[$status]++;
        }

        return $counters;
    }

    /**
     * @param  Collection<string, array<string, mixed>>|null  $documentsByDas
     * @return array<string, mixed>
     */
    public function dasToPublicGuide(PgdasdOperation $op, ?Collection $documentsByDas = null): array
    {
        $documentsByDas ??= collect();
        $das = (string) ($op->das_number ?? '');
        $payment = match ($op->payment_located) {
            true => TaxGuidePaymentStatus::Confirmed->value,
            false => TaxGuidePaymentStatus::NotConfirmed->value,
            default => TaxGuidePaymentStatus::Unknown->value,
        };

        $emission = $op->issued_at !== null
            ? TaxGuideEmissionStatus::Confirmed->value
            : TaxGuideEmissionStatus::UnknownResult->value;

        $document = $das !== '' ? $documentsByDas->get($das) : null;

        return [
            'id' => 'pgdasd-das-'.$op->id,
            'office_id' => $op->office_id,
            'client_id' => $op->client_id,
            'establishment_id' => null,
            'system_code' => 'INTEGRA_SN',
            'service_code' => 'PGDASD',
            'operation_code' => 'CONSULTAR_DECLARACAO',
            'competence_period_key' => $op->period_key,
            'period_key' => $op->period_key,
            'debit_ref' => null,
            'logical_key' => $op->logical_key,
            'payment_status' => $payment,
            'payment_confirmed_at' => $op->payment_located === true
                ? $op->payment_observed_at?->toIso8601String()
                : null,
            'payment_source' => 'PGDASD_CONSULT',
            'amount_cents' => null,
            'currency' => 'BRL',
            'due_at' => null,
            'identifier_code' => $das !== '' ? $das : null,
            'das_number' => $das !== '' ? $das : null,
            'current_version_id' => null,
            'current_version' => [
                'id' => null,
                'emission_status' => $emission,
                'emitted_at' => $op->issued_at?->toIso8601String(),
            ],
            'emission_status' => $emission,
            'source' => 'PGDASD_CONSULT',
            'pgdasd_operation_id' => $op->id,
            'document' => $document,
            'created_at' => $op->first_seen_at?->toIso8601String()
                ?? $op->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>|null  $documentsByDarf
     * @return array<string, mixed>
     */
    public function darfToPublicGuide(DctfwebDarfDocument $darf, ?Collection $documentsByDarf = null): array
    {
        $documentsByDarf ??= collect();
        $number = (string) ($darf->document_number ?? '');
        $period = $darf->relationLoaded('declaration')
            ? ($darf->declaration?->period_key)
            : null;

        $payment = match ($darf->payment_status) {
            FiscalPaymentStatus::Paid => TaxGuidePaymentStatus::Confirmed->value,
            FiscalPaymentStatus::Unpaid => TaxGuidePaymentStatus::NotConfirmed->value,
            default => TaxGuidePaymentStatus::Unknown->value,
        };

        $emission = $darf->issued_at !== null
            ? TaxGuideEmissionStatus::Confirmed->value
            : TaxGuideEmissionStatus::UnknownResult->value;

        $amountCents = null;
        if ($darf->amount !== null && $darf->amount !== '') {
            $amountCents = (int) round(((float) $darf->amount) * 100);
        }

        $document = null;
        if ($darf->evidence_version_id) {
            $document = $documentsByDarf->get((int) $darf->evidence_version_id);
        }

        return [
            'id' => 'dctfweb-darf-'.$darf->id,
            'office_id' => $darf->office_id,
            'client_id' => $darf->client_id,
            'establishment_id' => null,
            'system_code' => 'INTEGRA_DCTFWEB',
            'service_code' => 'DCTFWEB',
            'operation_code' => 'EMITIR_DARF',
            'competence_period_key' => $period,
            'period_key' => $period,
            'debit_ref' => null,
            'logical_key' => 'dctfweb-darf:'.$number,
            'payment_status' => $payment,
            'payment_confirmed_at' => $darf->payment_status === FiscalPaymentStatus::Paid
                ? $darf->issued_at?->toIso8601String()
                : null,
            'payment_source' => 'DCTFWEB_DARF',
            'amount_cents' => $amountCents,
            'currency' => 'BRL',
            'due_at' => $darf->due_at?->toIso8601String(),
            'identifier_code' => $number !== '' ? $number : null,
            'das_number' => null,
            'current_version_id' => null,
            'current_version' => [
                'id' => null,
                'emission_status' => $emission,
                'emitted_at' => $darf->issued_at?->toIso8601String(),
            ],
            'emission_status' => $emission,
            'source' => 'DCTFWEB_DARF',
            'dctfweb_darf_id' => $darf->id,
            'document' => $document,
            'created_at' => $darf->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function documentsByDarfEvidence(Office $office, ?int $clientId): Collection
    {
        $query = DctfwebEvidenceVersion::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('artifact_kind', DctfwebArtifactKind::Darf->value)
            ->orderByDesc('observed_at')
            ->orderByDesc('id');
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }
        $versions = $query->get();

        /** @var Collection<int, array<string, mixed>> $byId */
        $byId = collect();
        foreach ($versions as $version) {
            $id = (int) $version->id;
            if ($byId->has($id)) {
                continue;
            }
            $cid = (int) $version->client_id;
            $byId->put($id, [
                'available' => true,
                'kind' => 'PDF',
                'label' => 'Baixar DARF',
                'content_type' => 'application/pdf',
                'observed_at' => $version->observed_at?->toIso8601String(),
                'source_surface' => 'dctfweb',
                'source_label' => 'DCTFWeb',
                'href' => "/api/v1/fiscal/dctfweb/clients/{$cid}/evidence/{$id}/download",
                'unavailable_reason' => null,
            ]);
        }

        return $byId;
    }

    /**
     * @return Collection<string, array<string, mixed>>
     */
    private function documentsByDasNumber(Office $office, ?int $clientId): Collection
    {
        $query = PgdasdArtifact::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereNotNull('das_number')
            ->orderByDesc('observed_at')
            ->orderByDesc('id');
        if ($clientId !== null) {
            $query->where('client_id', $clientId);
        }
        $artifacts = $query->get();

        /** @var Collection<string, array<string, mixed>> $byDas */
        $byDas = collect();
        foreach ($artifacts as $art) {
            $das = (string) ($art->das_number ?? '');
            if ($das === '' || $byDas->has($das)) {
                continue;
            }
            $byDas->put($das, [
                'available' => true,
                'kind' => str_contains(strtolower((string) $art->content_type), 'pdf') ? 'PDF' : 'FILE',
                'label' => 'Baixar DAS',
                'content_type' => $art->content_type,
                'observed_at' => $art->observed_at?->toIso8601String(),
                'source_surface' => 'simples_mei_pgdasd',
                'source_label' => 'PGDAS-D',
                'href' => '/api/v1/fiscal/simples-mei/pgdasd/artifacts/'.$art->id.'/download',
                'unavailable_reason' => null,
            ]);
        }

        return $byDas;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function sortRows(Collection $rows, string $sort, string $direction): Collection
    {
        $dir = strtolower($direction) === 'asc' ? 'asc' : 'desc';
        $column = match ($sort) {
            'client_id' => 'client_id',
            'system_code' => 'system_code',
            'competence' => 'competence_period_key',
            'amount' => 'amount_cents',
            'due_at' => 'due_at',
            'payment_status' => 'payment_status',
            default => 'competence_period_key',
        };

        $sorted = $rows->sortBy(
            function (array $row) use ($column, $dir) {
                $value = $row[$column] ?? null;
                if ($value === null) {
                    return $dir === 'asc' ? "\xFF" : '';
                }

                return (string) $value;
            },
            SORT_NATURAL,
            $dir === 'desc',
        );

        return $sorted->values();
    }
}
