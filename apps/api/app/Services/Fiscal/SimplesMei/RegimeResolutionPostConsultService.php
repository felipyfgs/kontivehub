<?php

namespace App\Services\Fiscal\SimplesMei;

use App\DTO\Fiscal\FiscalAdapterRequest;
use App\DTO\Fiscal\FiscalAdapterResult;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalRunResult;
use App\Enums\FiscalSituation;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pós-consulta CONSULTARRESOLUCAO104: Base64 fail-closed, bytes no cofre,
 * projeção local sem expor texto/Base64/path.
 */
final class RegimeResolutionPostConsultService
{
    public function __construct(
        private readonly RegimeResolutionCodec $codec,
        private readonly FiscalEvidenceStore $evidenceStore,
        private readonly RegimeApplicabilityService $regimes,
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
        if ($operationKey !== RegimeResolutionCodec::OPERATION_KEY) {
            return ['result' => $result];
        }

        if ($result->result !== FiscalRunResult::Success || ! $response->success) {
            return ['result' => $result];
        }

        $year = $this->resolveYear($request);
        $normalized = is_array($result->normalized) ? $result->normalized : [];

        try {
            $payload = $this->resolvePayload($response);
            $decoded = $this->codec->decode($payload, $year);

            $calendarYear = $decoded['calendar_year'] ?? $year;
            if ($calendarYear === null) {
                throw new \RuntimeException('anoCalendario ausente na consulta 104.');
            }

            Log::info('regime.consultarresolucao.decoded', [
                'office_id' => $request->office->id,
                'client_id' => $request->client->id,
                'calendar_year' => $calendarYear,
                'byte_size' => $decoded['byte_size'],
                'code' => 'REGIME_RESOLUTION_DECODED',
            ]);

            $artifact = $this->evidenceStore->store(
                run: $request->run,
                bytes: $decoded['text_bytes'],
                contentType: $decoded['content_type'],
                source: 'REGIMEAPURACAO_CONSULTARRESOLUCAO104',
                sourceVersion: SimplesMeiCatalog::DTO_VERSION,
            );

            // Metadados do artefato (sem bytes/Base64/path de vault).
            $meta = is_array($artifact->metadata) ? $artifact->metadata : [];
            $meta['calendar_year'] = $calendarYear;
            $meta['operation_key'] = RegimeResolutionCodec::OPERATION_KEY;
            $meta['content_kind'] = 'RESOLUTION_TEXT';
            $artifact->forceFill([
                'operation_key' => RegimeResolutionCodec::OPERATION_KEY,
                'metadata' => $meta,
            ])->saveQuietly();

            $descriptor = [
                'sanitized' => true,
                'available' => true,
                'kind' => 'TEXT',
                'content_type' => $decoded['content_type'],
                'byte_size' => $decoded['byte_size'],
                'download_path' => '/api/v1/fiscal/evidence/'.$artifact->id.'/download',
                'evidence_id' => $artifact->id,
            ];

            $this->regimes->projectResolution(
                $request->office,
                $request->client,
                $calendarYear,
                $artifact->id,
                $request->run->id,
                [
                    'content_type' => $decoded['content_type'],
                    'byte_size' => $decoded['byte_size'],
                    'download_path' => $descriptor['download_path'],
                ],
            );

            $safeEvidence = json_encode(
                $this->codec->sanitizePublic(
                    is_array($response->body) ? $response->body : [],
                    [
                        'sanitized' => true,
                        'omitted' => true,
                        'available' => true,
                        'evidence_id' => $artifact->id,
                    ],
                ),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE,
            );

            $normalized = array_merge($normalized, [
                'dto' => 'regime_apuracao_resolucao',
                'dto_version' => SimplesMeiCatalog::DTO_VERSION,
                'operation_key' => RegimeResolutionCodec::OPERATION_KEY,
                'calendar_year' => $calendarYear,
                'resolution' => $descriptor,
                'evidence_id' => $artifact->id,
            ]);

            return ['result' => new FiscalAdapterResult(
                result: $result->result,
                situation: FiscalSituation::UpToDate,
                coverage: $result->coverage,
                evidenceBytes: $safeEvidence,
                evidenceContentType: 'application/json',
                sourceVersion: $result->sourceVersion ?? SimplesMeiCatalog::DTO_VERSION,
                normalized: $normalized,
                findings: $result->findings,
                itemsProcessed: $result->itemsProcessed,
                pagesProcessed: $result->pagesProcessed,
            )];
        } catch (Throwable $e) {
            Log::warning('regime.consultarresolucao.decode_failed', [
                'office_id' => $request->office->id,
                'client_id' => $request->client->id,
                'calendar_year' => $year,
                'code' => 'REGIME_RESOLUTION_DECODE_FAILED',
                'error' => $e->getMessage(),
            ]);

            return ['result' => FiscalAdapterResult::failed(
                'Resposta da resolução de regime inválida ou incompleta.',
                'REGIME_RESOLUTION_INVALID',
                $result->coverage,
            )];
        }
    }

    private function resolveYear(FiscalAdapterRequest $request): ?int
    {
        $ctx = $request->context;
        $progress = $request->progress;
        $raw = $ctx['anoCalendario']
            ?? $ctx['ano_calendario']
            ?? $ctx['year']
            ?? $progress['ano_calendario']
            ?? $progress['anoCalendario']
            ?? $progress['year']
            ?? $progress['period_key']
            ?? null;

        if ($raw === null || $raw === '') {
            return null;
        }

        try {
            return $this->codec->assertValidYear((string) $raw);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolvePayload(IntegraResponse $response): array
    {
        $dados = $response->dados;
        if (is_string($dados) && $dados !== '') {
            $decoded = json_decode($dados, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        if (is_array($dados)) {
            return $dados;
        }
        if (isset($response->body['dados'])) {
            $inner = $response->body['dados'];
            if (is_string($inner)) {
                $decoded = json_decode($inner, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
            if (is_array($inner)) {
                return $inner;
            }
        }

        return is_array($response->body) ? $response->body : [];
    }
}
