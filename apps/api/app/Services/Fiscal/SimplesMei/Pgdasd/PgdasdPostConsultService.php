<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Enums\FiscalSourceProvenance;
use App\Enums\PgdasdDeclarationState;
use App\Enums\PgdasdRbt12Status;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\PgdasdArtifact;
use App\Models\PgdasdOperation;
use App\Models\PgdasdRbt12Projection;
use App\Models\TaxObligationDefinition;
use App\Models\TaxObligationProjection;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/** Projeta somente respostas PGDAS-D oficiais, completas e tenant-scoped. */
final class PgdasdPostConsultService
{
    public function __construct(
        private readonly PgdasdConsDeclaracao13Codec $codec13,
        private readonly PgdasdDocumentCodecs $documentCodecs,
        private readonly PgdasdDocumentSanitizer $sanitizer,
        private readonly PgdasdOperationProjector $projector,
        private readonly PgdasdDeclarationStateResolver $stateResolver,
        private readonly PgdasdRbt12Service $rbt12,
        private readonly FiscalEvidenceStore $evidenceStore,
        private readonly PgdasdOperationAmountService $operationAmounts,
    ) {}

    /** @return array{result:FiscalAdapterResult,sanitized_dados:mixed} */
    public function handle(
        FiscalAdapterRequest $request,
        IntegraResponse $response,
        FiscalAdapterResult $result,
        string $operationKey,
    ): array {
        if ($operationKey === 'pgdasd.consdeclaracao'
            && (! $response->success || $result->result !== FiscalRunResult::Success)
        ) {
            return $this->unverified13($request, $result, 'QUERY_FAILED');
        }
        if (! $response->success || $result->result !== FiscalRunResult::Success) {
            return ['result' => $result, 'sanitized_dados' => null];
        }

        return match ($operationKey) {
            'pgdasd.consdeclaracao' => $this->handle13($request, $response, $result),
            'pgdasd.consultimadecrec',
            'pgdasd.consdecrec',
            'pgdasd.consextrato' => $this->handleDocumental($request, $response, $result, $operationKey),
            'pgdasd.gerardas' => $this->handleGerarDas($request, $result),
            default => ['result' => $result, 'sanitized_dados' => null],
        };
    }

    public function attachSnapshotToValidProjections(
        FiscalMonitoringRun $run,
        ?FiscalSnapshot $snapshot,
    ): int {
        if ($snapshot === null
            || (int) $snapshot->run_id !== (int) $run->id
            || strtoupper((string) $run->service_code) !== 'PGDASD'
            || ! in_array(strtoupper((string) $run->operation_code), ['MONITOR', 'CONSULTAR_DECLARACAO'], true)
            || $run->source_provenance !== FiscalSourceProvenance::SerproReal
        ) {
            return 0;
        }

        return TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $run->office_id)
            ->where('client_id', $run->client_id)
            ->where('last_valid_run_id', $run->id)
            ->update([
                'last_valid_snapshot_id' => $snapshot->id,
                'updated_at' => CarbonImmutable::now(),
            ]);
    }

    /** @return array{result:FiscalAdapterResult,sanitized_dados:mixed} */
    private function handle13(
        FiscalAdapterRequest $request,
        IntegraResponse $response,
        FiscalAdapterResult $result,
    ): array {
        if (! $this->isRealProductive($response)) {
            return $this->unverified13($request, $result, 'SOURCE_NOT_SERPRO_REAL');
        }

        $expectedPa = $this->resolveExpectedPa($request);
        if ($expectedPa === null) {
            return $this->unverified13($request, $result, 'EXPECTED_PERIOD_UNAVAILABLE');
        }

        try {
            $decoded = $this->codec13->decodeDados($response->dados ?? ($response->body['dados'] ?? null));
        } catch (\Throwable $exception) {
            Log::warning('pgdasd.consdeclaracao.decode_failed', [
                'run_id' => $request->run->id,
                'reason_code' => 'DECODE_FAILED',
            ]);

            return $this->unverified13($request, $result, 'DECODE_FAILED');
        }

        if (($decoded['incomplete'] ?? true) === true || ! $this->codec13->coversPeriodo($decoded, $expectedPa)) {
            return $this->unverified13($request, $result, 'INCOMPLETE_OR_PERIOD_NOT_COVERED');
        }

        try {
            $projected = $this->projector->projectFromDecoded(
                $request->run,
                $request->office,
                $request->client,
                $decoded,
            );
            $expectedPeriodKey = PgdasdPeriod::periodKeyFromPeriodoApuracao($expectedPa);
            $expectedProjection = $projected['projections'][$expectedPeriodKey]
                ?? $this->projector->ensureProjectionForPeriod(
                    $request->office,
                    $request->client,
                    $expectedPeriodKey,
                    $request->competence?->period_key === $expectedPeriodKey
                        ? $request->competence?->id
                        : null,
                );
        } catch (\Throwable $exception) {
            Log::warning('pgdasd.consdeclaracao.project_failed', [
                'run_id' => $request->run->id,
                'reason_code' => 'PROJECTION_FAILED',
            ]);

            return $this->unverified13($request, $result, 'PROJECTION_FAILED');
        }

        $periodProjections = collect($projected['projections'])
            ->push($expectedProjection)
            ->unique('id')
            ->values();
        $observedAt = CarbonImmutable::now();
        foreach ($periodProjections as $projection) {
            $projection->forceFill([
                'last_valid_query_at' => $observedAt,
                'last_valid_run_id' => $request->run->id,
                'pgdasd_last_productive_consulted_at' => $observedAt,
            ])->save();
        }

        $declaration = $this->projector->latestDeclarationForPeriod(
            (int) $request->office->id,
            (int) $request->client->id,
            $expectedPeriodKey,
        );
        $statePack = $this->stateResolver->resolve(
            declarationForExpectedPa: $declaration,
            lastProductiveConsultedAt: $observedAt,
            projection: $expectedProjection->refresh(),
        );
        $situation = $statePack['state']->toFiscalSituation();
        $expectedMetadata = is_array($expectedProjection->metadata) ? $expectedProjection->metadata : [];
        $expectedMetadata['pgdasd_declaration_state_reason'] = $statePack['reason'];
        $expectedProjection->forceFill([
            'pgdasd_declaration_state' => $statePack['state'],
            'pgdasd_last_declaration_operation_id' => $declaration?->id,
            'pgdasd_calendar_version_code' => $statePack['calendar_version_code'],
            'pgdasd_calendar_verified' => $statePack['calendar_verified'],
            'situation' => $situation,
            'delivery_status' => $situation,
            'metadata' => $expectedMetadata,
        ])->save();

        $reservedRbt12 = $this->rbt12->reserveFromOperations(
            $request->run,
            $projected['upserted'],
            $periodProjections->all(),
            $expectedPeriodKey,
        );

        try {
            $this->operationAmounts->coverAmountGapsAfterMonitor(
                $request->office,
                $request->client,
                $request->run,
            );
        } catch (\Throwable $e) {
            Log::warning('pgdasd.das_amount_gap_cover_failed', [
                'office_id' => $request->office->id,
                'client_id' => $request->client->id,
                'run_id' => $request->run->id,
                'error' => $e->getMessage(),
            ]);
        }

        $normalized = is_array($result->normalized) ? $result->normalized : [];
        $normalized['pgdasd'] = [
            'expected_periodo_apuracao' => $expectedPa,
            'expected_period_key' => $expectedPeriodKey,
            'declaration_state' => $statePack['state']->value,
            'declaration_state_reason' => $statePack['reason'],
            'calendar_verified' => $statePack['calendar_verified'],
            'last_valid_query_at' => $observedAt->toIso8601String(),
            'latest_declaration' => $declaration?->toPublicArray(),
            'operations_count' => count($projected['upserted']),
            'rbt12_projection_ids' => array_map(
                static fn (PgdasdRbt12Projection $projection): int => (int) $projection->id,
                $reservedRbt12,
            ),
            'productive' => true,
            'incomplete' => false,
        ];

        return [
            'result' => $this->replaceResult(
                $result,
                $normalized,
                $situation,
                json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ),
            'sanitized_dados' => $response->dados,
        ];
    }

    /** @return array{result:FiscalAdapterResult,sanitized_dados:mixed} */
    private function handleDocumental(
        FiscalAdapterRequest $request,
        IntegraResponse $response,
        FiscalAdapterResult $result,
        string $operationKey,
    ): array {
        $dados = $response->dados ?? ($response->body['dados'] ?? null);
        if (! $this->isRealProductive($response)) {
            try {
                $sanitized = $this->documentCodecs->sanitizeDados($dados, []);
            } catch (\Throwable) {
                $sanitized = [];
            }

            return [
                'result' => $this->documentResult($result, [], [['reason' => 'SOURCE_NOT_SERPRO_REAL']]),
                'sanitized_dados' => $sanitized,
            ];
        }

        $periodKey = $this->resolvePeriodKey($request);
        if ($periodKey === null) {
            return [
                'result' => $this->documentResult($result, [], [['reason' => 'PERIOD_UNAVAILABLE']]),
                'sanitized_dados' => [],
            ];
        }

        $projection = $this->projector->ensureProjectionForPeriod(
            $request->office,
            $request->client,
            $periodKey,
            $request->competence?->period_key === $periodKey ? $request->competence?->id : null,
        );
        $numeroDas = $this->requestValue($request, 'numeroDas', 'numero_das');
        $pack = $this->sanitizer->sanitizeAndStore(
            run: $request->run,
            clientId: (int) $request->client->id,
            dados: $dados,
            operationKey: $operationKey,
            projectionId: (int) $projection->id,
            periodKey: $periodKey,
            periodoApuracao: PgdasdPeriod::periodoApuracaoFromPeriodKey($periodKey),
            numeroDas: $numeroDas,
        );
        if ($pack['artifacts'] === []) {
            $pack['artifacts'] = PgdasdArtifact::query()
                ->withoutGlobalScopes()
                ->where('office_id', $request->office->id)
                ->where('client_id', $request->client->id)
                ->where('projection_id', $projection->id)
                ->where('source_run_id', $request->run->id)
                ->orderBy('id')
                ->get()
                ->filter(static fn (PgdasdArtifact $artifact): bool => is_array($artifact->metadata)
                    && ($artifact->metadata['source_operation_key'] ?? null) === $operationKey)
                ->values()
                ->all();
        }

        if ($operationKey === 'pgdasd.consextrato') {
            $this->resolveReservedRbt12($request, $numeroDas, $pack['artifacts']);
            try {
                $this->operationAmounts->applyFromExtratoArtifacts(
                    $request->office,
                    (int) $request->client->id,
                    $numeroDas,
                    $pack['artifacts'],
                );
            } catch (\Throwable $e) {
                Log::warning('pgdasd.extrato_das_amount.apply_failed', [
                    'office_id' => $request->office->id,
                    'client_id' => $request->client->id,
                    'run_id' => $request->run->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        if ($operationKey === 'pgdasd.consdecrec' || $operationKey === 'pgdasd.consultimadecrec') {
            $numeroDeclaracao = $this->requestValue($request, 'numeroDeclaracao', 'numero_declaracao');
            $this->resolveReservedRbt12FromDeclaration($request, $numeroDeclaracao, $pack['artifacts']);
        }

        return [
            'result' => $this->documentResult($result, $pack['artifacts'], $pack['failures']),
            'sanitized_dados' => $pack['sanitized_dados'],
        ];
    }

    /** @return array{result:FiscalAdapterResult,sanitized_dados:mixed} */
    private function handleGerarDas(
        FiscalAdapterRequest $request,
        FiscalAdapterResult $result,
    ): array {
        $normalized = is_array($result->normalized) ? $result->normalized : [];
        try {
            $this->operationAmounts->applyFromGerarDasNormalized(
                $request->office,
                (int) $request->client->id,
                $normalized,
            );
        } catch (\Throwable $e) {
            Log::warning('pgdasd.gerar_das_amount.apply_failed', [
                'office_id' => $request->office->id,
                'client_id' => $request->client->id,
                'run_id' => $request->run->id,
                'error' => $e->getMessage(),
            ]);
        }

        return ['result' => $result, 'sanitized_dados' => null];
    }

    /** @param list<PgdasdArtifact> $artifacts */
    private function resolveReservedRbt12(
        FiscalAdapterRequest $request,
        ?string $numeroDas,
        array $artifacts,
    ): void {
        $reservationId = $request->progress['rbt12_projection_id']
            ?? $request->context['rbt12_projection_id']
            ?? null;
        $sourceReferenceKey = $request->progress['rbt12_source_reference_key']
            ?? $request->context['rbt12_source_reference_key']
            ?? null;
        if (! is_numeric($reservationId)
            || ! is_string($sourceReferenceKey)
            || $sourceReferenceKey === ''
            || $numeroDas === null
        ) {
            return;
        }

        $reservation = PgdasdRbt12Projection::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $reservationId)
            ->where('office_id', $request->office->id)
            ->where('client_id', $request->client->id)
            ->where('source_das_number', $numeroDas)
            ->where('source_reference_key', $sourceReferenceKey)
            ->where('source_run_id', $request->run->id)
            ->where('status', PgdasdRbt12Status::Pending->value)
            ->first();
        if ($reservation === null) {
            return;
        }

        if ($artifacts === []) {
            $this->rbt12->markFailed($reservation, 'EXTRATO_ARTIFACT_MISSING', (int) $request->run->id);

            return;
        }

        $artifact = collect($artifacts)->first(
            static fn (PgdasdArtifact $candidate): bool => (string) $candidate->kind === 'EXTRATO'
        );
        if (! $artifact instanceof PgdasdArtifact) {
            $this->rbt12->markFailed($reservation, 'EXTRATO_ARTIFACT_MISSING', (int) $request->run->id);

            return;
        }

        $artifact->loadMissing('evidenceArtifact');
        if ($artifact->evidenceArtifact === null) {
            $this->rbt12->markFailed($reservation, 'EXTRATO_EVIDENCE_MISSING', (int) $request->run->id);

            return;
        }

        try {
            $bytes = $this->evidenceStore->readAuthorized(
                $artifact->evidenceArtifact,
                (int) $request->office->id,
            );
            $this->rbt12->resolveFromPdfBytes(
                $reservation,
                $bytes,
                (int) $artifact->id,
                (int) $request->run->id,
            );
        } catch (\Throwable) {
            $this->rbt12->markFailed($reservation, 'READ_OR_PARSE_FAILED', (int) $request->run->id);
        }
    }

    /** @param list<PgdasdArtifact> $artifacts */
    private function resolveReservedRbt12FromDeclaration(
        FiscalAdapterRequest $request,
        ?string $numeroDeclaracao,
        array $artifacts,
    ): void {
        $reservationId = $request->progress['rbt12_projection_id']
            ?? $request->context['rbt12_projection_id']
            ?? null;
        $sourceReferenceKey = $request->progress['rbt12_source_reference_key']
            ?? $request->context['rbt12_source_reference_key']
            ?? null;
        $declarationNumber = is_string($numeroDeclaracao) ? trim($numeroDeclaracao) : '';
        if (! is_numeric($reservationId)
            || ! is_string($sourceReferenceKey)
            || $sourceReferenceKey === ''
            || $declarationNumber === ''
        ) {
            return;
        }

        $reservation = PgdasdRbt12Projection::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $reservationId)
            ->where('office_id', $request->office->id)
            ->where('client_id', $request->client->id)
            ->where('source_declaration_number', $declarationNumber)
            ->whereNull('source_das_number')
            ->where('source_reference_key', $sourceReferenceKey)
            ->where('source_run_id', $request->run->id)
            ->where('status', PgdasdRbt12Status::Pending->value)
            ->first();
        if ($reservation === null) {
            return;
        }

        if ($artifacts === []) {
            $this->rbt12->markFailed($reservation, 'EXTRATO_ARTIFACT_MISSING', (int) $request->run->id);

            return;
        }

        $artifact = collect($artifacts)->first(
            static fn (PgdasdArtifact $candidate): bool => (string) (is_object($candidate->kind) ? $candidate->kind->value : $candidate->kind) === 'DECLARACAO'
        );
        if (! $artifact instanceof PgdasdArtifact) {
            $artifact = collect($artifacts)->first(
                static fn (PgdasdArtifact $candidate): bool => (string) (is_object($candidate->kind) ? $candidate->kind->value : $candidate->kind) === 'RECIBO'
            );
        }
        if (! $artifact instanceof PgdasdArtifact) {
            $this->rbt12->markFailed($reservation, 'EXTRATO_ARTIFACT_MISSING', (int) $request->run->id);

            return;
        }

        $artifact->loadMissing('evidenceArtifact');
        if ($artifact->evidenceArtifact === null) {
            $this->rbt12->markFailed($reservation, 'EXTRATO_EVIDENCE_MISSING', (int) $request->run->id);

            return;
        }

        try {
            $bytes = $this->evidenceStore->readAuthorized(
                $artifact->evidenceArtifact,
                (int) $request->office->id,
            );
            $this->rbt12->resolveFromPdfBytes(
                $reservation,
                $bytes,
                (int) $artifact->id,
                (int) $request->run->id,
            );
        } catch (\Throwable) {
            $this->rbt12->markFailed($reservation, 'READ_OR_PARSE_FAILED', (int) $request->run->id);
        }
    }

    /** @return array{result:FiscalAdapterResult,sanitized_dados:null} */
    private function unverified13(
        FiscalAdapterRequest $request,
        FiscalAdapterResult $result,
        string $reason,
    ): array {
        $this->markExistingExpectedProjectionUnverified($request, $reason);
        $normalized = is_array($result->normalized) ? $result->normalized : [];
        $normalized['pgdasd'] = [
            'declaration_state' => PgdasdDeclarationState::Unverified->value,
            'declaration_state_reason' => $reason,
            'productive' => false,
            'incomplete' => true,
        ];

        return [
            'result' => $this->replaceResult(
                $result,
                $normalized,
                FiscalSituation::Unknown,
                json_encode($normalized, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            ),
            'sanitized_dados' => null,
        ];
    }

    private function markExistingExpectedProjectionUnverified(
        FiscalAdapterRequest $request,
        string $reason,
    ): void {
        $expectedPa = $this->resolveExpectedPa($request);
        if ($expectedPa === null) {
            return;
        }
        $definitionId = TaxObligationDefinition::query()->where('code', 'PGDAS_D')->value('id');
        if ($definitionId === null) {
            return;
        }
        $projection = TaxObligationProjection::query()
            ->withoutGlobalScopes()
            ->where('office_id', $request->office->id)
            ->where('client_id', $request->client->id)
            ->where('obligation_definition_id', $definitionId)
            ->where('period_key', PgdasdPeriod::periodKeyFromPeriodoApuracao($expectedPa))
            ->first();
        if ($projection === null) {
            return;
        }

        $metadata = is_array($projection->metadata) ? $projection->metadata : [];
        $metadata['pgdasd_declaration_state_reason'] = $reason;
        $unknown = PgdasdDeclarationState::Unverified->toFiscalSituation();
        $projection->forceFill([
            'pgdasd_declaration_state' => PgdasdDeclarationState::Unverified,
            'pgdasd_calendar_verified' => false,
            'situation' => $unknown,
            'delivery_status' => $unknown,
            'metadata' => $metadata,
        ])->save();
    }

    /** @param list<PgdasdArtifact> $artifacts @param list<array<string,mixed>> $failures */
    private function documentResult(
        FiscalAdapterResult $result,
        array $artifacts,
        array $failures,
    ): FiscalAdapterResult {
        $normalized = is_array($result->normalized) ? $result->normalized : [];
        $normalized['pgdasd_documents'] = [
            'artifacts' => array_map(
                static fn (PgdasdArtifact $artifact): array => $artifact->toPublicArray(),
                $artifacts,
            ),
            'failures' => $failures,
        ];

        return $this->replaceResult(
            $result,
            $normalized,
            $result->situation,
            json_encode($normalized['pgdasd_documents'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
        );
    }

    private function replaceResult(
        FiscalAdapterResult $result,
        array $normalized,
        FiscalSituation $situation,
        string $evidenceBytes,
    ): FiscalAdapterResult {
        return new FiscalAdapterResult(
            result: $result->result,
            situation: $situation,
            coverage: $result->coverage,
            evidenceBytes: $evidenceBytes,
            evidenceContentType: 'application/json',
            sourceVersion: $result->sourceVersion,
            normalized: $normalized,
            findings: $result->findings,
            itemsProcessed: $result->itemsProcessed,
            pagesProcessed: $result->pagesProcessed,
        );
    }

    private function isRealProductive(IntegraResponse $response): bool
    {
        return $response->success
            && ! $response->simulated
            && $response->sourceProvenance === FiscalSourceProvenance::SerproReal->value;
    }

    private function resolveExpectedPa(FiscalAdapterRequest $request): ?string
    {
        $candidate = $request->progress['expected_periodo_apuracao']
            ?? $request->context['expected_periodo_apuracao']
            ?? $request->progress['periodo_apuracao']
            ?? $request->context['periodoApuracao']
            ?? null;
        if (is_string($candidate) && preg_match('/^\d{6}$/', $candidate) === 1) {
            return $candidate;
        }

        $periodKey = $request->competence?->period_key
            ?? $request->progress['period_key']
            ?? $request->context['period_key']
            ?? null;
        if (is_string($periodKey) && $periodKey !== '') {
            try {
                return PgdasdPeriod::periodoApuracaoFromPeriodKey($periodKey);
            } catch (\Throwable) {
                return null;
            }
        }

        $timezone = (string) ($request->office->timezone ?: 'America/Sao_Paulo');

        return PgdasdPeriod::toPeriodoApuracao(PgdasdPeriod::expectedPa(null, $timezone));
    }

    private function resolvePeriodKey(FiscalAdapterRequest $request): ?string
    {
        $periodKey = $request->competence?->period_key
            ?? $request->progress['period_key']
            ?? $request->context['period_key']
            ?? null;
        if (is_string($periodKey) && preg_match('/^\d{4}-\d{2}$/', $periodKey) === 1) {
            return $periodKey;
        }

        $pa = $this->requestValue($request, 'periodoApuracao', 'periodo_apuracao');
        if ($pa !== null) {
            try {
                return PgdasdPeriod::periodKeyFromPeriodoApuracao($pa);
            } catch (\Throwable) {
                return null;
            }
        }

        $declarationNumber = $this->requestValue($request, 'numeroDeclaracao', 'numero_declaracao');
        if ($declarationNumber !== null) {
            $resolved = PgdasdOperation::query()
                ->withoutGlobalScopes()
                ->where('office_id', $request->office->id)
                ->where('client_id', $request->client->id)
                ->where('declaration_number', $declarationNumber)
                ->orderByDesc('transmitted_at')
                ->value('period_key');
            if (is_string($resolved) && preg_match('/^\d{4}-\d{2}$/', $resolved) === 1) {
                return $resolved;
            }
        }

        return null;
    }

    private function requestValue(FiscalAdapterRequest $request, string $contextKey, string $progressKey): ?string
    {
        $value = $request->context[$contextKey] ?? $request->progress[$progressKey] ?? null;
        if (! is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
