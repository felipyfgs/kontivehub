<?php

namespace App\Services\Integra;

use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SerproEnvironment;
use App\Support\LogSanitizer;

/**
 * Normaliza o envelope HTTP da Integra Contador sem efeitos colaterais.
 *
 * O circuit breaker é atualizado exclusivamente pelo SerproOperationService,
 * depois que a resposta já foi classificada por esta classe.
 */
final class IntegraResponseNormalizer
{
    /**
     * @param  array{status: int, body: string, headers: array<string, string>, retry_after: ?int, latency_ms: ?int}  $response
     */
    public function normalize(
        array $response,
        IntegraRequest $request,
        string $operationKey,
        string $requestTag,
        string $route,
        SerproEnvironment $environment,
    ): IntegraResponse {
        $status = (int) $response['status'];
        $headers = $response['headers'] ?? [];
        $etag = $this->headerValue($headers, 'etag');
        $expires = $this->headerValue($headers, 'expires');
        $provenance = $environment === SerproEnvironment::Trial
            ? FiscalSourceProvenance::SerproTrial->value
            : FiscalSourceProvenance::SerproReal->value;

        if ($status === 304) {
            return $this->response(
                request: $request,
                operationKey: $operationKey,
                requestTag: $requestTag,
                route: $route,
                provenance: $provenance,
                success: true,
                httpStatus: 304,
                body: [],
                headers: $headers,
                errorCode: 'NOT_MODIFIED',
                errorMessage: 'Conteúdo não modificado (cache/ETag).',
                etag: $etag,
                expires: $expires,
                businessStatus: 'NOT_MODIFIED',
                latencyMs: $response['latency_ms'],
            );
        }

        $rawBody = (string) ($response['body'] ?? '');
        $decoded = $this->decodeEnvelope($rawBody);
        if ($decoded === null && ! ($status === 204 && trim($rawBody) === '')) {
            return $this->response(
                request: $request,
                operationKey: $operationKey,
                requestTag: $requestTag,
                route: $route,
                provenance: $provenance,
                success: false,
                httpStatus: $status,
                body: [],
                headers: $headers,
                errorCode: 'INVALID_RESPONSE_FORMAT',
                errorMessage: 'Resposta SERPRO vazia ou fora do formato JSON esperado.',
                retryAfterSeconds: $response['retry_after'],
                etag: $etag,
                expires: $expires,
                latencyMs: $response['latency_ms'],
            );
        }

        $decoded ??= [];
        $mensagens = $this->extractMensagens($decoded);
        [$dados, $dadosValid] = $this->parseDados($decoded);
        $businessStatus = isset($decoded['status']) ? (string) $decoded['status'] : null;
        $waitSeconds = $this->waitSeconds($decoded, $dados, $etag, $response['retry_after']);

        if ($status === 429) {
            return $this->response(
                request: $request,
                operationKey: $operationKey,
                requestTag: $requestTag,
                route: $route,
                provenance: $provenance,
                success: false,
                httpStatus: 429,
                body: $decoded,
                headers: $headers,
                errorCode: 'RATE_LIMITED',
                errorMessage: 'Rate limit SERPRO.',
                retryAfterSeconds: $waitSeconds ?? 60,
                etag: $etag,
                expires: $expires,
                businessStatus: $businessStatus,
                mensagens: $mensagens,
                dados: $dados,
                latencyMs: $response['latency_ms'],
            );
        }

        if ($status >= 500) {
            return $this->response(
                request: $request,
                operationKey: $operationKey,
                requestTag: $requestTag,
                route: $route,
                provenance: $provenance,
                success: false,
                httpStatus: $status,
                body: $decoded,
                headers: $headers,
                errorCode: $status === 503 ? 'UPSTREAM_UNAVAILABLE' : 'UPSTREAM_ERROR',
                errorMessage: $status === 503
                    ? 'SERPRO temporariamente indisponível.'
                    : 'Erro upstream SERPRO.',
                retryAfterSeconds: $waitSeconds ?? ($status === 503 ? 30 : null),
                etag: $etag,
                expires: $expires,
                businessStatus: $businessStatus,
                mensagens: $mensagens,
                dados: $dados,
                latencyMs: $response['latency_ms'],
            );
        }

        if (in_array($status, [202, 204], true)) {
            return $this->response(
                request: $request,
                operationKey: $operationKey,
                requestTag: $requestTag,
                route: $route,
                provenance: $provenance,
                success: true,
                httpStatus: $status,
                body: $decoded,
                headers: $headers,
                retryAfterSeconds: $waitSeconds ?? 30,
                etag: $etag,
                expires: $expires,
                businessStatus: $businessStatus ?? 'PROCESSANDO',
                mensagens: $mensagens,
                dados: $dados,
                latencyMs: $response['latency_ms'],
            );
        }

        $httpSuccess = $status >= 200 && $status < 300;
        $businessError = $httpSuccess && $this->hasBusinessError($mensagens);
        if ($httpSuccess && ! $dadosValid) {
            return $this->response(
                request: $request,
                operationKey: $operationKey,
                requestTag: $requestTag,
                route: $route,
                provenance: $provenance,
                success: false,
                httpStatus: $status,
                body: $decoded,
                headers: $headers,
                errorCode: 'INVALID_DADOS_FORMAT',
                errorMessage: 'Campo dados da resposta SERPRO não contém JSON válido.',
                retryAfterSeconds: $waitSeconds,
                etag: $etag,
                expires: $expires,
                businessStatus: $businessStatus,
                mensagens: $mensagens,
                dados: null,
                latencyMs: $response['latency_ms'],
            );
        }

        $success = $httpSuccess && ! $businessError;

        return $this->response(
            request: $request,
            operationKey: $operationKey,
            requestTag: $requestTag,
            route: $route,
            provenance: $provenance,
            success: $success,
            httpStatus: $status,
            body: $decoded,
            headers: $headers,
            errorCode: $success ? null : ($businessError ? 'BUSINESS_ERROR' : 'REQUEST_FAILED'),
            errorMessage: $success ? null : ($businessError
                ? 'SERPRO rejeitou os dados da operação.'
                : 'Chamada Integra Contador rejeitada.'),
            retryAfterSeconds: $waitSeconds,
            etag: $etag,
            expires: $expires,
            businessStatus: $businessStatus,
            mensagens: $mensagens,
            dados: $dados,
            latencyMs: $response['latency_ms'],
        );
    }

    /** @return array<string, mixed>|null */
    private function decodeEnvelope(string $body): ?array
    {
        if (trim($body) === '') {
            return null;
        }

        $decoded = json_decode($body, true);

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return array{0: mixed, 1: bool}
     */
    private function parseDados(array $decoded): array
    {
        if (! array_key_exists('dados', $decoded)) {
            return [null, true];
        }

        $raw = $decoded['dados'];
        if (is_array($raw) || $raw === null || $raw === '') {
            return [$raw, true];
        }
        if (! is_string($raw)) {
            return [null, false];
        }

        $parsed = json_decode($raw, true);

        return json_last_error() === JSON_ERROR_NONE
            ? [$parsed, true]
            : [null, false];
    }

    /** @param list<array<string, mixed>> $mensagens */
    private function hasBusinessError(array $mensagens): bool
    {
        foreach ($mensagens as $mensagem) {
            $code = strtoupper(trim((string) ($mensagem['codigo'] ?? '')));
            $compact = preg_replace('/[^A-Z0-9]/', '', $code) ?? '';
            if ($compact === 'ERRO'
                || str_starts_with($compact, 'ERRO')
                || str_starts_with($compact, 'ACESSONEGADO')
                || str_starts_with($compact, 'ENTRADAINCORRETA')
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $decoded
     * @return list<array{codigo?: string, texto?: string}>
     */
    private function extractMensagens(array $decoded): array
    {
        $raw = $decoded['mensagens'] ?? $decoded['messages'] ?? [];
        if (! is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $message) {
            if (! is_array($message)) {
                continue;
            }
            $out[] = [
                'codigo' => isset($message['codigo'])
                    ? (string) $message['codigo']
                    : (isset($message['code']) ? (string) $message['code'] : null),
                'texto' => isset($message['texto'])
                    ? (string) $message['texto']
                    : (isset($message['mensagem']) ? (string) $message['mensagem'] : null),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $decoded
     */
    private function waitSeconds(array $decoded, mixed $dados, ?string $etag, ?int $retryAfter): ?int
    {
        foreach ([$decoded, is_array($dados) ? $dados : []] as $source) {
            foreach (['TempoEsperaMedioEmMs', 'tempoEsperaMedioEmMs'] as $key) {
                if (isset($source[$key]) && is_numeric($source[$key])) {
                    return max(1, (int) ceil(((int) $source[$key]) / 1000));
                }
            }
            foreach (['tempoEspera', 'tempo_espera', 'waitSeconds'] as $key) {
                if (isset($source[$key]) && is_numeric($source[$key])) {
                    $value = (int) $source[$key];
                    // SITFIS publica tempoEspera em milissegundos.
                    if ($key === 'tempoEspera' && $value > 180) {
                        return max(1, (int) ceil($value / 1000));
                    }

                    return max(1, $value);
                }
            }
        }

        if ($etag !== null && preg_match('/tempoEspera[=:](\d+)/i', $etag, $matches)) {
            $value = (int) $matches[1];

            return $value > 180 ? max(1, (int) ceil($value / 1000)) : max(1, $value);
        }

        return $retryAfter;
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     * @param  list<array{codigo?: string, texto?: string}>  $mensagens
     */
    private function response(
        IntegraRequest $request,
        string $operationKey,
        string $requestTag,
        string $route,
        string $provenance,
        bool $success,
        int $httpStatus,
        array $body,
        array $headers,
        ?string $errorCode = null,
        ?string $errorMessage = null,
        ?int $retryAfterSeconds = null,
        ?string $etag = null,
        ?string $expires = null,
        ?string $businessStatus = null,
        array $mensagens = [],
        mixed $dados = null,
        ?int $latencyMs = null,
    ): IntegraResponse {
        return new IntegraResponse(
            success: $success,
            httpStatus: $httpStatus,
            body: $this->sanitizeBodyKeys($body),
            headers: $this->publicHeaders($headers),
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            simulated: false,
            retryAfterSeconds: $retryAfterSeconds,
            correlationId: $request->correlationId,
            latencyMs: $latencyMs,
            etag: $etag,
            expiresHeader: $expires,
            businessStatus: $businessStatus,
            mensagens: $mensagens,
            dados: $dados,
            operationKey: $operationKey,
            requestTag: $requestTag,
            functionalRoute: $route,
            sourceProvenance: $provenance,
        );
    }

    /** @param array<string, string> $headers */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0) {
                return (string) $value;
            }
        }

        return null;
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function publicHeaders(array $headers): array
    {
        $allowed = ['retry-after', 'expires', 'content-type', 'x-request-id'];
        $out = [];
        foreach ($headers as $key => $value) {
            $normalized = strtolower((string) $key);
            if (in_array($normalized, $allowed, true)) {
                $out[$normalized] = (string) $value;
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function sanitizeBodyKeys(array $body): array
    {
        foreach ($body as $key => $value) {
            $lower = strtolower((string) $key);
            $blocked = [
                'access_token', 'jwt_token', 'autenticar_procurador_token',
                'xmlassinado', 'pfx', 'password', 'private_key',
                'consumer_secret', 'termo_xml',
            ];
            if (in_array($lower, $blocked, true)) {
                unset($body[$key]);

                continue;
            }
            if (is_array($value)) {
                $body[$key] = $this->sanitizeBodyKeys($value);
            } elseif (is_string($value) && LogSanitizer::looksLikeSecret($value)) {
                $body[$key] = '[redacted]';
            }
        }

        return $body;
    }
}
