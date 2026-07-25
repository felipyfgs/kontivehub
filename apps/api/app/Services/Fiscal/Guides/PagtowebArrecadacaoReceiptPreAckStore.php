<?php

namespace App\Services\Fiscal\Guides;

use App\DTO\Serpro\IntegraResponse;
use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use RuntimeException;

/** Fecha a janela HTTP → ACK do comprovante 7.2 sem persistir o PDF no attempt. */
final class PagtowebArrecadacaoReceiptPreAckStore
{
    public function __construct(private readonly PagtowebArrecadacaoReceiptProjector $projector) {}

    public function capture(string $operationKey, string $entityKey, IntegraResponse $response, int $officeId, int $clientId): IntegraResponse
    {
        if ($operationKey !== 'pagtoweb.comparrecadacao' || ! $response->success) {
            return $response;
        }
        if (preg_match('/^fiscal-run:(\d+)$/', $entityKey, $matches) !== 1) {
            throw new RuntimeException('Run fiscal ausente para captura documental pré-ACK.');
        }

        $run = FiscalMonitoringRun::query()
            ->withoutGlobalScopes()
            ->whereKey((int) $matches[1])
            ->where('office_id', $officeId)
            ->where('client_id', $clientId)
            ->where('system_code', 'PAGTOWEB')
            ->first();
        if ($run === null) {
            throw new RuntimeException('Run fiscal PAGTOWEB inválida para captura documental pré-ACK.');
        }

        $office = Office::query()->findOrFail($officeId);
        $client = Client::query()->withoutGlobalScopes()->whereKey($clientId)->firstOrFail();
        $receipt = $this->projector->project(
            $office,
            $client,
            (string) $response->sourceProvenance,
            $response->dados ?? ($response->body['dados'] ?? null),
        );
        $descriptor = ['receipt_id' => $receipt->id, 'available' => true, ...$receipt->toPublicArray()];
        $body = $response->body;
        $body['dados'] = $descriptor;
        $body['document_capture'] = ['sanitized' => true, 'artifacts_count' => 1];

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
            dados: $descriptor,
            operationKey: $response->operationKey,
            requestTag: $response->requestTag,
            functionalRoute: $response->functionalRoute,
            sourceProvenance: $response->sourceProvenance,
        );
    }
}
