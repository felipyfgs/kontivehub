<?php

namespace App\Services\Fiscal\Guides;

use App\DTO\Serpro\IntegraResponse;

/** Remove o número efêmero 7.2 de qualquer eco remoto antes de persistência ou retorno. */
final class PagtowebEphemeralResponseRedactor
{
    public function redact(IntegraResponse $response, mixed $numeroDocumento): IntegraResponse
    {
        if (! is_string($numeroDocumento) || $numeroDocumento === '') {
            return $response;
        }

        return new IntegraResponse(
            success: $response->success,
            httpStatus: $response->httpStatus,
            body: $this->redactValue($response->body, $numeroDocumento),
            headers: $this->redactValue($response->headers, $numeroDocumento),
            errorCode: $response->errorCode,
            errorMessage: $response->errorMessage === null ? null : $this->redactString($response->errorMessage, $numeroDocumento),
            simulated: $response->simulated,
            retryAfterSeconds: $response->retryAfterSeconds,
            correlationId: $response->correlationId,
            latencyMs: $response->latencyMs,
            etag: $response->etag,
            expiresHeader: $response->expiresHeader,
            businessStatus: $response->businessStatus,
            mensagens: $this->redactValue($response->mensagens, $numeroDocumento),
            dados: $this->redactValue($response->dados, $numeroDocumento),
            operationKey: $response->operationKey,
            requestTag: $response->requestTag,
            functionalRoute: $response->functionalRoute,
            sourceProvenance: $response->sourceProvenance,
        );
    }

    private function redactValue(mixed $value, string $numeroDocumento): mixed
    {
        if (is_string($value)) {
            return $this->redactString($value, $numeroDocumento);
        }
        if (! is_array($value)) {
            return $value;
        }

        foreach ($value as $key => $child) {
            $value[$key] = $this->redactValue($child, $numeroDocumento);
        }

        return $value;
    }

    private function redactString(string $value, string $numeroDocumento): string
    {
        return str_replace($numeroDocumento, '[número de documento omitido]', $value);
    }
}
