<?php

namespace App\Services\Fiscal\SimplesMei\Pgdasd;

use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalVerificationState;
use App\Models\FiscalMonitoringRun;
use RuntimeException;

/**
 * Fecha a janela resposta-HTTP → ACK: PDFs 14–16 chegam ao cofre antes de a
 * tentativa SERPRO se tornar terminal. O attempt recebe somente descritores.
 */
final class PgdasdPreAckDocumentStore
{
    public function __construct(
        private readonly PgdasdDocumentSanitizer $sanitizer,
        private readonly PgdasdOperationProjector $projector,
    ) {}

    public function capture(
        string $operationKey,
        string $entityKey,
        IntegraResponse $response,
        int $officeId,
        int $clientId,
    ): IntegraResponse {
        if (! in_array($operationKey, [
            'pgdasd.consultimadecrec',
            'pgdasd.consdecrec',
            'pgdasd.consextrato',
        ], true) || ! $response->success) {
            return $response;
        }

        if (preg_match('/^fiscal-run:(\d+)$/', $entityKey, $matches) !== 1) {
            throw new RuntimeException('Run fiscal ausente para captura documental pré-ACK.');
        }

        $run = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->with(['office', 'client', 'competence'])
            ->whereKey((int) $matches[1])
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('service_code', 'PGDASD')
            ->first();
        if ($run === null || $run->office === null || $run->client === null) {
            throw new RuntimeException('Run fiscal PGDAS-D inválida para captura documental pré-ACK.');
        }

        $progress = is_array($run->progress) ? $run->progress : [];
        $periodKey = $run->competence?->period_key ?? ($progress['period_key'] ?? null);
        if (! is_string($periodKey) || preg_match('/^\d{4}-\d{2}$/', $periodKey) !== 1) {
            $pa = $progress['periodo_apuracao'] ?? null;
            try {
                $periodKey = is_string($pa)
                    ? PgdasdPeriod::periodKeyFromPeriodoApuracao($pa)
                    : null;
            } catch (\Throwable) {
                $periodKey = null;
            }
        }
        if (! is_string($periodKey) || $periodKey === '') {
            throw new RuntimeException('PA ausente para captura documental pré-ACK.');
        }

        $run->forceFill([
            'operation_key' => $operationKey,
            'source_provenance' => FiscalSourceProvenance::SerproReal,
            'verification_state' => FiscalVerificationState::Verified,
        ])->save();

        $projection = $this->projector->ensureProjectionForPeriod(
            $run->office,
            $run->client,
            $periodKey,
            $run->competence?->period_key === $periodKey ? $run->competence?->id : null,
        );
        $dados = $response->dados ?? ($response->body['dados'] ?? null);
        $numeroDas = isset($progress['numero_das']) && is_scalar($progress['numero_das'])
            ? trim((string) $progress['numero_das'])
            : null;
        $pack = $this->sanitizer->sanitizeAndStore(
            run: $run,
            clientId: $clientId,
            dados: $dados,
            operationKey: $operationKey,
            projectionId: (int) $projection->id,
            periodKey: $periodKey,
            periodoApuracao: PgdasdPeriod::periodoApuracaoFromPeriodKey($periodKey),
            numeroDas: $numeroDas !== '' ? $numeroDas : null,
        );

        $body = $response->body;
        $body['dados'] = $pack['sanitized_dados'];
        $body['document_capture'] = [
            'sanitized' => true,
            'artifacts_count' => count($pack['artifacts']),
            'failures_count' => count($pack['failures']),
        ];

        return new IntegraResponse(
            success: $response->success,
            httpStatus: $response->httpStatus,
            body: $body,
            headers: $response->headers,
            errorCode: $response->errorCode,
            errorMessage: $response->errorMessage,
            simulated: $response->simulated,
            retryAfterSeconds: $response->retryAfterSeconds,
            correlationId: $response->correlationId,
            latencyMs: $response->latencyMs,
            etag: $response->etag,
            expiresHeader: $response->expiresHeader,
            businessStatus: $response->businessStatus,
            mensagens: $response->mensagens,
            dados: $pack['sanitized_dados'],
            operationKey: $response->operationKey,
            requestTag: $response->requestTag,
            functionalRoute: $response->functionalRoute,
            sourceProvenance: $response->sourceProvenance,
        );
    }
}
