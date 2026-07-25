<?php

namespace App\Services\Fiscal\SimplesMei;

use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Fiscal\SimplesMei\SimplesMeiOperationDef;
use App\Enums\FiscalCoverage;
use App\Enums\FiscalFindingSeverity;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\TaxRegimeCode;
use App\Models\Client;
use App\Models\ClientTaxRegimePeriod;
use App\Models\FiscalCategory;
use App\Models\FiscalCompetence;
use App\Models\FiscalEvidenceArtifact;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Projeta regime por vigência e impede misturar competências SN ↔ MEI.
 */
final class RegimeApplicabilityService
{
    /**
     * Bloqueia operação se regime da competência for incompatível com o serviço.
     */
    public function assertOperationApplicable(
        Office $office,
        Client $client,
        SimplesMeiOperationDef $def,
        ?string $periodKey,
    ): ?FiscalAdapterResult {
        // REGIME_APURACAO sempre aplicável (é a fonte do regime)
        if (strtoupper($def->serviceCode) === 'REGIME_APURACAO') {
            return null;
        }

        if ($periodKey === null || $periodKey === '') {
            // Sem competência: permite consulta pontual (CCMEI etc.)
            return null;
        }

        $regimeAt = $this->regimeForPeriod($office, $client, $periodKey);
        if ($regimeAt === null || $regimeAt === TaxRegimeCode::Unknown) {
            // Sem projeção: não inventa — permite consulta (fonte dirá); não marca NOT_APPLICABLE
            return null;
        }

        if (! $regimeAt->compatibleWith($def->regimeFamily)) {
            return new FiscalAdapterResult(
                result: FiscalRunResult::Success,
                situation: FiscalSituation::NotApplicable,
                coverage: FiscalCoverage::NotApplicable,
                evidenceBytes: json_encode([
                    'not_applicable' => true,
                    'reason' => 'REGIME_MISMATCH',
                    'regime_at_period' => $regimeAt->value,
                    'operation_regime_family' => $def->regimeFamily->value,
                    'period_key' => $periodKey,
                ], JSON_THROW_ON_ERROR),
                normalized: [
                    'situation' => FiscalSituation::NotApplicable->value,
                    'regime_at_period' => $regimeAt->value,
                    'operation_regime_family' => $def->regimeFamily->value,
                    'reason' => 'REGIME_MISMATCH',
                ],
                findings: [[
                    'code' => 'REGIME_MISMATCH',
                    'severity' => FiscalFindingSeverity::Info->value,
                    'title' => 'Operação não aplicável ao regime da competência',
                    'detail' => "Regime {$regimeAt->value} ≠ família {$def->regimeFamily->value}",
                    'situation' => FiscalSituation::NotApplicable->value,
                    'creates_pending' => false,
                ]],
            );
        }

        return null;
    }

    public function regimeForPeriod(Office $office, Client $client, string $periodKey): ?TaxRegimeCode
    {
        $anchor = $this->periodAnchorDate($periodKey);
        if ($anchor === null) {
            return null;
        }

        $row = ClientTaxRegimePeriod::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('effective_from', '<=', $anchor)
            ->where(function ($q) use ($anchor): void {
                $q->whereNull('effective_to')
                    ->orWhere('effective_to', '>=', $anchor);
            })
            ->orderByDesc('effective_from')
            ->first();

        return $row?->regime_code;
    }

    /**
     * @param  array<string, mixed>  $normalized  saída de RegimeApuracaoDto::toNormalized()
     */
    public function projectFromNormalized(
        Office $office,
        Client $client,
        array $normalized,
        ?int $sourceRunId = null,
    ): void {
        $periods = is_array($normalized['periods'] ?? null) ? $normalized['periods'] : [];
        if ($periods === []) {
            $current = TaxRegimeCode::tryFrom((string) ($normalized['current_regime'] ?? ''))
                ?? TaxRegimeCode::Unknown;
            if ($current !== TaxRegimeCode::Unknown) {
                $this->upsertPeriod(
                    $office,
                    $client,
                    $current,
                    CarbonImmutable::now()->startOfMonth(),
                    null,
                    $sourceRunId,
                );
            }

            return;
        }

        foreach ($periods as $p) {
            if (! is_array($p)) {
                continue;
            }
            $code = TaxRegimeCode::tryFrom((string) ($p['regime'] ?? '')) ?? TaxRegimeCode::Unknown;
            if ($code === TaxRegimeCode::Unknown) {
                continue;
            }
            $from = CarbonImmutable::parse((string) $p['effective_from'])->startOfDay();
            $to = ! empty($p['effective_to'])
                ? CarbonImmutable::parse((string) $p['effective_to'])->endOfDay()
                : null;
            $this->upsertPeriod($office, $client, $code, $from, $to, $sourceRunId);
        }

        // Atualiza tax_regime do cliente com o vigente atual (somente exibição; fonte de verdade = períodos)
        $current = $this->regimeForPeriod($office, $client, now()->format('Y-m'));
        if ($current !== null && $current !== TaxRegimeCode::Unknown) {
            $client->forceFill(['tax_regime' => $current->value])->save();
        }
    }

    /**
     * Persiste os anos de COMPETENCIA/CAIXA sem confundi-los com o regime
     * tributário do cliente. A linha continua classificada como Simples
     * Nacional; o regime de apuração oficial fica nos metadados.
     *
     * @param  list<array{calendar_year:int,regime_apuracao:string}>  $options
     */
    public function projectCalendarOptions(
        Office $office,
        Client $client,
        array $options,
        ?int $sourceRunId = null,
    ): void {
        foreach ($options as $option) {
            $year = (int) ($option['calendar_year'] ?? 0);
            $regimeApuracao = strtoupper((string) ($option['regime_apuracao'] ?? ''));
            if ($year < 2000 || $year > 2100
                || ! in_array($regimeApuracao, ['COMPETENCIA', 'CAIXA'], true)) {
                continue;
            }

            $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
            $existing = ClientTaxRegimePeriod::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->where('regime_code', TaxRegimeCode::SimplesNacional->value)
                ->whereDate('effective_from', $from->toDateString())
                ->first();

            $baseMeta = is_array($existing?->metadata) ? $existing->metadata : [];
            // Preserva resolução 104 se já projetada no mesmo ano.
            $metadata = array_merge($baseMeta, [
                'operation_key' => 'regimeapuracao.consultaranoscalendarios',
                'calendar_year' => $year,
                'regime_apuracao' => $regimeApuracao,
            ]);

            $this->upsertPeriod(
                $office,
                $client,
                TaxRegimeCode::SimplesNacional,
                $from,
                CarbonImmutable::create($year, 12, 31)->endOfDay(),
                $sourceRunId,
                $metadata,
                $existing?->evidence_artifact_id !== null
                    ? (int) $existing->evidence_artifact_id
                    : null,
            );
        }
    }

    /**
     * Persiste a observação pontual do serviço 103 sem sobrescrever a origem
     * da listagem ampla do serviço 102. Ambos compartilham a vigência anual,
     * mas mantêm proveniência distinta nos metadados locais.
     *
     * @param  array{calendar_year:int,regime_apuracao:string}  $option
     */
    public function projectRegimeOption(
        Office $office,
        Client $client,
        array $option,
        ?int $sourceRunId = null,
    ): void {
        $year = (int) ($option['calendar_year'] ?? 0);
        $regimeApuracao = strtoupper((string) ($option['regime_apuracao'] ?? ''));
        if ($year < 2000 || $year > 2100
            || ! in_array($regimeApuracao, ['COMPETENCIA', 'CAIXA'], true)) {
            return;
        }

        $from = CarbonImmutable::create($year, 1, 1)->startOfDay();
        $existing = ClientTaxRegimePeriod::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('regime_code', TaxRegimeCode::SimplesNacional->value)
            ->whereDate('effective_from', $from->toDateString())
            ->first();
        $metadata = is_array($existing?->metadata) ? $existing->metadata : [];
        $metadata['regime_option_103'] = [
            'operation_key' => RegimeOptionCodec::OPERATION_KEY,
            'calendar_year' => $year,
            'regime_apuracao' => $regimeApuracao,
        ];

        $this->upsertPeriod(
            $office,
            $client,
            TaxRegimeCode::SimplesNacional,
            $from,
            CarbonImmutable::create($year, 12, 31)->endOfDay(),
            $sourceRunId,
            $metadata,
            $existing?->evidence_artifact_id !== null ? (int) $existing->evidence_artifact_id : null,
        );
    }

    /**
     * @return list<array{calendar_year:int,regime_apuracao:string,observed_at:?string}>
     */
    public function listCalendarOptions(Office $office, Client $client): array
    {
        return ClientTaxRegimePeriod::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('source_system', 'INTEGRA_SN')
            ->where('source_service', 'REGIME_APURACAO')
            ->orderByDesc('effective_from')
            ->get()
            ->map(static function (ClientTaxRegimePeriod $period): ?array {
                $metadata = is_array($period->metadata) ? $period->metadata : [];
                if (($metadata['operation_key'] ?? null) !== 'regimeapuracao.consultaranoscalendarios') {
                    return null;
                }
                $year = $metadata['calendar_year'] ?? $period->effective_from?->year;
                $regime = $metadata['regime_apuracao'] ?? null;
                if (! is_int($year) || ! in_array($regime, ['COMPETENCIA', 'CAIXA'], true)) {
                    return null;
                }

                return [
                    'calendar_year' => $year,
                    'regime_apuracao' => $regime,
                    'observed_at' => $period->observed_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /** @return list<array{calendar_year:int,regime_apuracao:string,observed_at:?string}> */
    public function listRegimeOptions(Office $office, Client $client): array
    {
        return ClientTaxRegimePeriod::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->where('source_system', 'INTEGRA_SN')
            ->where('source_service', 'REGIME_APURACAO')
            ->orderByDesc('observed_at')
            ->get()
            ->map(static function (ClientTaxRegimePeriod $period): ?array {
                $metadata = is_array($period->metadata) ? $period->metadata : [];
                $option = $metadata['regime_option_103'] ?? null;
                if (! is_array($option)
                    || ($option['operation_key'] ?? null) !== RegimeOptionCodec::OPERATION_KEY) {
                    return null;
                }
                $year = $option['calendar_year'] ?? null;
                $regime = $option['regime_apuracao'] ?? null;
                if (! is_int($year) || ! in_array($regime, ['COMPETENCIA', 'CAIXA'], true)) {
                    return null;
                }

                return [
                    'calendar_year' => $year,
                    'regime_apuracao' => $regime,
                    'observed_at' => $period->observed_at?->toIso8601String(),
                ];
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Projeção local da resolução 104: metadados no artefato de evidência.
     * Não altera regime tributário nem opções de calendário 102.
     *
     * @param  array{
     *   content_type?: string,
     *   byte_size?: int,
     *   content_sha256?: string,
     *   download_path?: string
     * }  $documentMeta
     */
    public function projectResolution(
        Office $office,
        Client $client,
        int $calendarYear,
        int $evidenceArtifactId,
        ?int $sourceRunId = null,
        array $documentMeta = [],
    ): void {
        if ($calendarYear < RegimeResolutionCodec::MIN_YEAR
            || $calendarYear > RegimeResolutionCodec::MAX_YEAR) {
            return;
        }

        $artifact = FiscalEvidenceArtifact::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->whereKey($evidenceArtifactId)
            ->first();
        if ($artifact === null) {
            return;
        }

        $meta = is_array($artifact->metadata) ? $artifact->metadata : [];
        $meta['operation_key'] = RegimeResolutionCodec::OPERATION_KEY;
        $meta['calendar_year'] = $calendarYear;
        $meta['client_id'] = $client->id;
        $meta['source_run_id'] = $sourceRunId;
        $meta['content_type'] = $documentMeta['content_type'] ?? $artifact->content_type;
        $meta['byte_size'] = $documentMeta['byte_size'] ?? $artifact->byte_size;
        if (isset($documentMeta['content_sha256'])) {
            $meta['content_sha256'] = $documentMeta['content_sha256'];
        }
        $meta['download_path'] = $documentMeta['download_path']
            ?? '/api/v1/fiscal/evidence/'.$artifact->id.'/download';
        $artifact->forceFill(['metadata' => $meta])->saveQuietly();
    }

    /**
     * Lista projeções locais de resolução 104 (sem bytes/Base64/vault path).
     *
     * @return list<array{
     *   calendar_year:int,
     *   observed_at:?string,
     *   content_type:?string,
     *   byte_size:?int,
     *   available:bool,
     *   document:array{
     *     available:bool,
     *     kind:string,
     *     label:string,
     *     content_type:?string,
     *     observed_at:?string,
     *     href:?string
     *   }
     * }>
     */
    public function listResolutions(Office $office, Client $client, ?int $year = null): array
    {
        $runIds = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->pluck('id');

        if ($runIds->isEmpty()) {
            return [];
        }

        $rows = FiscalEvidenceArtifact::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('operation_key', RegimeResolutionCodec::OPERATION_KEY)
            ->whereIn('run_id', $runIds)
            ->orderByDesc('observed_at')
            ->orderByDesc('id')
            ->get();

        $byYear = [];
        foreach ($rows as $artifact) {
            $meta = is_array($artifact->metadata) ? $artifact->metadata : [];
            $calendarYear = (int) ($meta['calendar_year'] ?? 0);
            if ($calendarYear < RegimeResolutionCodec::MIN_YEAR
                || $calendarYear > RegimeResolutionCodec::MAX_YEAR) {
                continue;
            }
            if ($year !== null && $calendarYear !== $year) {
                continue;
            }
            // Mais recente por ano (já ordenado desc).
            if (isset($byYear[$calendarYear])) {
                continue;
            }

            $contentType = is_string($artifact->content_type) && $artifact->content_type !== ''
                ? $artifact->content_type
                : (is_string($meta['content_type'] ?? null) ? $meta['content_type'] : 'text/plain; charset=UTF-8');
            $href = '/api/v1/fiscal/evidence/'.$artifact->id.'/download';

            $byYear[$calendarYear] = [
                'calendar_year' => $calendarYear,
                'observed_at' => $artifact->observed_at?->toIso8601String(),
                'content_type' => $contentType,
                'byte_size' => (int) ($artifact->byte_size ?? $meta['byte_size'] ?? 0),
                'available' => true,
                'document' => [
                    'available' => true,
                    'kind' => 'TEXT',
                    'label' => 'Ver resolução do Regime de Caixa',
                    'content_type' => $contentType,
                    'observed_at' => $artifact->observed_at?->toIso8601String(),
                    'href' => $href,
                ],
            ];
        }

        krsort($byYear);

        return array_values($byYear);
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function projectCompetenceSituation(
        Office $office,
        Client $client,
        SimplesMeiOperationDef $def,
        string $periodKey,
        FiscalSituation $situation,
        FiscalCoverage $coverage,
        ?array $metadata = null,
    ): ?FiscalCompetence {
        if ($periodKey === '') {
            return null;
        }

        // Não projetar obrigação SN em categoria MEI e vice-versa
        $categoryCode = $def->regimeFamily->fiscalCategoryCode();
        if ($categoryCode === null) {
            return null;
        }

        $category = FiscalCategory::query()->where('code', $categoryCode)->first();
        if ($category === null) {
            return null;
        }

        [$year, $month] = $this->parsePeriodKey($periodKey);

        $competence = FiscalCompetence::query()
            ->withoutGlobalScopes()
            ->firstOrNew([
                'office_id' => $office->id,
                'client_id' => $client->id,
                'fiscal_category_id' => $category->id,
                'period_key' => $periodKey,
            ]);

        $competence->fill([
            'period_year' => $year,
            'period_month' => $month,
            'situation' => $situation,
            'coverage' => $coverage,
            'metadata' => array_merge($competence->metadata ?? [], [
                'service_code' => $def->serviceCode,
                'operation_code' => $def->operationCode,
                'regime_family' => $def->regimeFamily->value,
                'last_normalized' => $metadata,
            ]),
        ]);
        $competence->save();

        return $competence;
    }

    /**
     * @return Collection<int, ClientTaxRegimePeriod>
     */
    public function listPeriods(Office $office, Client $client): Collection
    {
        return ClientTaxRegimePeriod::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->orderBy('effective_from')
            ->get();
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     */
    private function upsertPeriod(
        Office $office,
        Client $client,
        TaxRegimeCode $regime,
        CarbonImmutable $from,
        ?CarbonImmutable $to,
        ?int $sourceRunId,
        ?array $metadata = null,
        ?int $evidenceArtifactId = null,
    ): ClientTaxRegimePeriod {
        $payload = [
            'effective_to' => $to?->toDateString(),
            'source_system' => 'INTEGRA_SN',
            'source_service' => 'REGIME_APURACAO',
            'source_run_id' => $sourceRunId,
            'observed_at' => CarbonImmutable::now(),
            'metadata' => $metadata,
        ];
        if ($evidenceArtifactId !== null) {
            $payload['evidence_artifact_id'] = $evidenceArtifactId;
        }

        return ClientTaxRegimePeriod::query()->updateOrCreate(
            [
                'office_id' => $office->id,
                'client_id' => $client->id,
                'regime_code' => $regime->value,
                'effective_from' => $from->toDateString(),
            ],
            $payload,
        );
    }

    private function periodAnchorDate(string $periodKey): ?CarbonImmutable
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m)) {
            return CarbonImmutable::create((int) $m[1], (int) $m[2], 15);
        }
        if (preg_match('/^(\d{4})$/', $periodKey, $m)) {
            return CarbonImmutable::create((int) $m[1], 6, 30);
        }

        try {
            return CarbonImmutable::parse($periodKey);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{0: int, 1: int|null}
     */
    private function parsePeriodKey(string $periodKey): array
    {
        if (preg_match('/^(\d{4})-(\d{2})$/', $periodKey, $m)) {
            return [(int) $m[1], (int) $m[2]];
        }
        if (preg_match('/^(\d{4})$/', $periodKey, $m)) {
            return [(int) $m[1], null];
        }

        return [(int) now()->format('Y'), null];
    }
}
