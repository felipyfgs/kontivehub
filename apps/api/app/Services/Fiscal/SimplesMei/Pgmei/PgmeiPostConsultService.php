<?php

namespace App\Services\Fiscal\SimplesMei\Pgmei;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalSourceProvenance;
use App\Enums\PgmeiDebtState;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pós-consulta DIVIDAATIVA24: só promove resposta produtiva válida.
 * Simulação, falha ou ambiguidade → UNVERIFIED no normalized, sem sobrescrever projeção.
 */
final class PgmeiPostConsultService
{
    public function __construct(
        private readonly PgmeiDividaAtiva24Codec $codec,
        private readonly PgmeiDebtProjector $projector,
    ) {}

    /**
     * @return array{result: FiscalAdapterResult}
     */
    public function handle(
        FiscalAdapterRequest $request,
        IntegraResponse $response,
        FiscalAdapterResult $result,
        string $operationKey,
    ): array {
        if ($operationKey !== 'pgmei.dividaativa') {
            return ['result' => $result];
        }

        $year = $this->resolveYear($request);
        $normalized = is_array($result->normalized) ? $result->normalized : [];
        $base = [
            'operation_key' => $operationKey,
            'calendar_year' => $year,
            'debt_state' => PgmeiDebtState::Unverified->value,
            'promoted' => false,
        ];

        if ($year === null) {
            $normalized['pgmei'] = $base + ['reason' => 'YEAR_MISSING'];

            return ['result' => $this->withNormalized($result, $normalized)];
        }

        $productive = $response->isProductiveEvidence()
            && $response->sourceProvenance === FiscalSourceProvenance::SerproReal->value;
        if (! $productive || $response->simulated) {
            $normalized['pgmei'] = $base + ['reason' => 'SIMULATED_OR_NOT_PRODUCTIVE'];

            return ['result' => $this->withNormalized($result, $normalized)];
        }

        if (! $response->success || $result->result->value !== 'SUCCESS') {
            $normalized['pgmei'] = $base + ['reason' => 'NOT_SUCCESS'];

            return ['result' => $this->withNormalized($result, $normalized)];
        }

        try {
            $dados = $response->dados ?? $response->body['dados'] ?? null;
            if ($dados === null) {
                $dados = $this->codec->extractDados($response->body);
            }
            $decoded = $this->codec->decodeDados($dados, $year);
            $projected = $this->projector->projectValid(
                $request->office,
                $request->client,
                $decoded,
                $request->run->id,
            );

            $projection = $projected['projection'];
            $state = $projection->debt_state instanceof PgmeiDebtState
                ? $projection->debt_state->value
                : (string) $projection->debt_state;

            $normalized['pgmei'] = [
                'operation_key' => $operationKey,
                'calendar_year' => $year,
                'debt_state' => $state,
                'items_count' => (int) $projection->items_count,
                'total_cents' => (int) $projection->total_cents,
                'digest' => $decoded['digest'],
                'promoted' => true,
                'observation_id' => $projected['observation']->id,
                'last_valid_query_at' => $projection->last_valid_query_at?->toIso8601String(),
            ];
        } catch (Throwable $e) {
            Log::warning('pgmei.dividaativa.decode_or_project_failed', [
                'office_id' => $request->office->id,
                'client_id' => $request->client->id,
                'year' => $year,
                'error' => $e->getMessage(),
            ]);
            $normalized['pgmei'] = $base + [
                'reason' => 'AMBIGUOUS_OR_INVALID',
                'error' => $e->getMessage(),
            ];
        }

        return ['result' => $this->withNormalized($result, $normalized)];
    }

    private function resolveYear(FiscalAdapterRequest $request): ?int
    {
        $ctx = $request->context;
        $progress = $request->progress;

        $raw = $ctx['anoCalendario']
            ?? $ctx['ano_calendario']
            ?? $progress['ano_calendario']
            ?? $progress['anoCalendario']
            ?? $progress['period_key']
            ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        $digits = preg_replace('/\D/', '', (string) $raw) ?? '';
        if (strlen($digits) >= 4) {
            try {
                return PgmeiYear::assertValid((int) substr($digits, 0, 4));
            } catch (Throwable) {
                return null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $normalized
     */
    private function withNormalized(FiscalAdapterResult $result, array $normalized): FiscalAdapterResult
    {
        return new FiscalAdapterResult(
            result: $result->result,
            situation: $result->situation,
            coverage: $result->coverage,
            evidenceBytes: $result->evidenceBytes,
            evidenceContentType: $result->evidenceContentType,
            sourceVersion: $result->sourceVersion,
            normalized: $normalized,
            findings: $result->findings,
            itemsProcessed: $result->itemsProcessed,
            pagesProcessed: $result->pagesProcessed,
        );
    }
}
