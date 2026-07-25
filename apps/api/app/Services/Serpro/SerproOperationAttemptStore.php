<?php

namespace App\Services\Serpro;

use App\DTO\Serpro\IntegraResponse;
use App\Enums\FiscalSourceProvenance;
use App\Enums\SerproAttemptState;
use App\Models\SerproOperationAttempt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persistência da state machine de tentativas do executor central.
 *
 * Garantia: no máximo um HTTP por chave lógica namespaced para resultados
 * definitivos; falhas de pré-condição local recuperáveis permitem reclaim
 * (novo dispatch) na mesma chave; concorrentes em dispatched bloqueiam.
 */
final class SerproOperationAttemptStore
{
    /**
     * @return array{
     *   action: 'dispatch'|'replay'|'wait',
     *   attempt: SerproOperationAttempt|null,
     *   response: IntegraResponse|null
     * }
     */
    public function beginOrReplay(
        int $officeId,
        string $environment,
        string $operationKey,
        string $entityKey,
        string $idempotencyKey,
        string $requestTag,
        ?string $correlationId,
        ?int $clientId,
    ): array {
        if (! Schema::hasTable('serpro_operation_attempts')) {
            return [
                'action' => 'dispatch',
                'attempt' => null,
                'response' => null,
            ];
        }

        return DB::transaction(function () use (
            $officeId,
            $environment,
            $operationKey,
            $entityKey,
            $idempotencyKey,
            $requestTag,
            $correlationId,
            $clientId,
        ): array {
            $existing = SerproOperationAttempt::query()
                ->withoutGlobalScopes()
                ->where('idempotency_key', $idempotencyKey)
                ->lockForUpdate()
                ->first();

            if ($existing !== null) {
                if ((int) $existing->office_id !== $officeId) {
                    return [
                        'action' => 'wait',
                        'attempt' => $existing,
                        'response' => $this->blocked(
                            $operationKey,
                            'IDEMPOTENCY_CROSS_TENANT',
                            'Chave de idempotência pertence a outro escritório.',
                            $correlationId,
                            $requestTag,
                            409,
                        ),
                    ];
                }

                if ($existing->isTerminal()) {
                    $sticky = SerproAttemptReplayPolicy::isStickyReplay(
                        $existing->error_code,
                        (bool) $existing->success,
                    );
                    // Sucesso sticky SITFIS com protocolo omitido no store não correlaciona — reclaim.
                    if ($sticky && $this->sitfisSolicitProtocolOmitted($existing)) {
                        $sticky = false;
                    }
                    if ($sticky) {
                        return [
                            'action' => 'replay',
                            'attempt' => $existing,
                            'response' => $this->toResponse($existing),
                        ];
                    }

                    // Pré-condição local recuperável: reclaim e novo dispatch.
                    $existing->forceFill([
                        'attempt_state' => SerproAttemptState::Dispatched,
                        'success' => null,
                        'http_status' => null,
                        'error_code' => null,
                        'error_message' => null,
                        'simulated' => false,
                        'latency_ms' => null,
                        'source_provenance' => null,
                        'business_status' => null,
                        'functional_route' => null,
                        'mensagens' => null,
                        'dados' => null,
                        'body' => null,
                        'headers' => null,
                        'reservation_id' => null,
                        'request_tag' => $requestTag,
                        'correlation_id' => $correlationId ?? $existing->correlation_id,
                        'dispatched_at' => now(),
                        'acknowledged_at' => null,
                        'reconciled_at' => null,
                    ])->save();

                    return [
                        'action' => 'dispatch',
                        'attempt' => $existing->fresh(),
                        'response' => null,
                    ];
                }

                if ($existing->isInFlight()) {
                    return [
                        'action' => 'wait',
                        'attempt' => $existing,
                        'response' => $this->blocked(
                            $operationKey,
                            'ATTEMPT_IN_FLIGHT',
                            'Operação lógica já despachada; aguarde ou reconcilie.',
                            $correlationId,
                            $requestTag,
                            409,
                        ),
                    ];
                }

                // reserved → claim for dispatch
                $existing->forceFill([
                    'attempt_state' => SerproAttemptState::Dispatched,
                    'dispatched_at' => now(),
                    'request_tag' => $existing->request_tag ?: $requestTag,
                    'correlation_id' => $existing->correlation_id ?: $correlationId,
                ])->save();

                return [
                    'action' => 'dispatch',
                    'attempt' => $existing->fresh(),
                    'response' => null,
                ];
            }

            $attempt = SerproOperationAttempt::query()->create([
                'office_id' => $officeId,
                'environment' => $environment,
                'operation_key' => $operationKey,
                'entity_key' => $entityKey,
                'idempotency_key' => $idempotencyKey,
                'request_tag' => $requestTag,
                'correlation_id' => $correlationId,
                'attempt_state' => SerproAttemptState::Dispatched,
                'client_id' => $clientId,
                'reserved_at' => now(),
                'dispatched_at' => now(),
            ]);

            return [
                'action' => 'dispatch',
                'attempt' => $attempt,
                'response' => null,
            ];
        });
    }

    public function markReserved(SerproOperationAttempt $attempt, ?int $reservationId = null): void
    {
        if (! Schema::hasTable('serpro_operation_attempts')) {
            return;
        }

        $attempt->forceFill([
            'attempt_state' => SerproAttemptState::Reserved,
            'reservation_id' => $reservationId ?? $attempt->reservation_id,
            'reserved_at' => $attempt->reserved_at ?? now(),
            'dispatched_at' => null,
        ])->save();
    }

    public function attachReservation(SerproOperationAttempt $attempt, int $reservationId): void
    {
        if (! Schema::hasTable('serpro_operation_attempts')) {
            return;
        }

        $attempt->forceFill(['reservation_id' => $reservationId])->save();
    }

    public function acknowledge(SerproOperationAttempt $attempt, IntegraResponse $response): void
    {
        $this->persistTerminal($attempt, $response, SerproAttemptState::Acknowledged);
    }

    /**
     * Remove attempt de falha local recuperável para não gerar replay sticky.
     */
    public function abandonLocalPrecondition(SerproOperationAttempt $attempt): void
    {
        if (! Schema::hasTable('serpro_operation_attempts')) {
            return;
        }

        SerproOperationAttempt::query()
            ->withoutGlobalScopes()
            ->whereKey($attempt->id)
            ->delete();
    }

    /**
     * Após refresh do token: remove bloqueios locais de token do office/ambiente.
     *
     * @param  list<string>|null  $errorCodes
     */
    public function purgeNonStickyTokenFailures(
        int $officeId,
        string $environment,
        ?array $errorCodes = null,
    ): int {
        if (! Schema::hasTable('serpro_operation_attempts')) {
            return 0;
        }

        $codes = $errorCodes ?? SerproAttemptReplayPolicy::tokenRelatedNonStickyCodes();

        return SerproOperationAttempt::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->where('environment', $environment)
            ->where('success', false)
            ->whereIn('error_code', $codes)
            ->delete();
    }

    public function markUncertain(SerproOperationAttempt $attempt, IntegraResponse $response): void
    {
        $this->persistTerminal($attempt, $response, SerproAttemptState::Uncertain);
    }

    public function reconcile(SerproOperationAttempt $attempt, IntegraResponse $response): void
    {
        $this->persistTerminal($attempt, $response, SerproAttemptState::Reconciled);
    }

    private function persistTerminal(
        SerproOperationAttempt $attempt,
        IntegraResponse $response,
        SerproAttemptState $state,
    ): void {
        if (! Schema::hasTable('serpro_operation_attempts')) {
            return;
        }

        if ($response->hasSimulatedSource()) {
            $response = $response->rejectSimulatedSource();
        }

        $now = now();
        $documental = $this->isDocumentalOperationKey($attempt->operation_key);
        $dados = $this->sanitizeAttemptDados($attempt->operation_key, $response->dados);
        $dados = $this->canonicalizeSitfisSolicitProtocol($attempt->operation_key, $response, $dados);
        $headers = $this->sanitizeResponseArray($response->headers, $attempt->operation_key);
        $headers = $this->persistSitfisExpiresHeader($headers, $response);

        $attempt->forceFill([
            'attempt_state' => $state,
            'success' => $response->success,
            'http_status' => $response->httpStatus > 0 ? $response->httpStatus : null,
            'error_code' => $response->errorCode,
            'error_message' => $response->errorMessage !== null
                ? $this->sanitizeErrorMessage($response->errorMessage, $documental)
                : null,
            'simulated' => $response->simulated,
            'latency_ms' => $response->latencyMs,
            'source_provenance' => $response->sourceProvenance,
            'business_status' => $response->businessStatus,
            'functional_route' => $response->functionalRoute,
            'mensagens' => $this->sanitizeResponseArray($response->mensagens, $attempt->operation_key),
            'dados' => $dados,
            'body' => $this->sanitizeAttemptBody($attempt->operation_key, $response->body),
            'headers' => $headers,
            'request_tag' => $response->requestTag ?? $attempt->request_tag,
            'correlation_id' => $response->correlationId ?? $attempt->correlation_id,
            'acknowledged_at' => in_array($state, [
                SerproAttemptState::Acknowledged,
                SerproAttemptState::Uncertain,
                SerproAttemptState::Reconciled,
            ], true) ? $now : $attempt->acknowledged_at,
            'reconciled_at' => $state === SerproAttemptState::Reconciled ? $now : $attempt->reconciled_at,
        ])->save();
    }

    public function toResponse(SerproOperationAttempt $attempt): IntegraResponse
    {
        $headers = is_array($attempt->headers) ? $attempt->headers : [];
        $dados = $attempt->dados;
        $etag = $this->headerValue($headers, 'etag');
        $expires = $this->headerValue($headers, 'expires');

        // Replay SITFIS 304: protocolo canônico em dados (ETag não fica em publicHeaders).
        if ($etag === null || $etag === '') {
            $protocol = $this->sitfisProtocolFromDados($dados);
            if ($protocol !== null) {
                $etag = 'protocoloRelatorio:'.$protocol;
            }
        }

        $response = new IntegraResponse(
            success: (bool) $attempt->success,
            httpStatus: (int) ($attempt->http_status ?? 0),
            body: is_array($attempt->body) ? $attempt->body : [],
            headers: $headers,
            errorCode: $attempt->error_code,
            errorMessage: $attempt->error_message,
            simulated: false,
            correlationId: $attempt->correlation_id,
            latencyMs: $attempt->latency_ms,
            etag: $etag,
            expiresHeader: $expires,
            businessStatus: $attempt->business_status,
            mensagens: is_array($attempt->mensagens) ? $attempt->mensagens : [],
            dados: $dados,
            operationKey: $attempt->operation_key,
            requestTag: $attempt->request_tag,
            functionalRoute: $attempt->functional_route,
            sourceProvenance: $attempt->source_provenance ?? FiscalSourceProvenance::Unverified->value,
        );

        // Replay idempotente também deve falhar fechado: attempts legados
        // com SIMULATED não podem reaparecer como evidência produtiva.
        if ($response->hasSimulatedSource()) {
            return $response->rejectSimulatedSource();
        }

        return $response;
    }

    /**
     * HTTP 304 SITFIS: protocolo só no ETag — grava em dados.protocoloRelatorio para replay.
     *
     * @param  array<string, mixed>|null  $dados
     * @return array<string, mixed>|null
     */
    private function canonicalizeSitfisSolicitProtocol(
        ?string $operationKey,
        IntegraResponse $response,
        ?array $dados,
    ): ?array {
        if ($operationKey !== 'sitfis.solicitar_protocolo') {
            return $dados;
        }

        $existing = $this->sitfisProtocolFromDados($dados);
        if ($existing !== null) {
            return $dados;
        }

        $fromEtag = $this->extractSitfisProtocolFromEtag($response->etag);
        if ($fromEtag === null) {
            return $dados;
        }

        $dados = is_array($dados) ? $dados : [];
        $dados['protocoloRelatorio'] = mb_substr($fromEtag, 0, 512);

        return $dados;
    }

    /**
     * @param  array<string, mixed>  $headers
     * @return array<string, mixed>
     */
    private function persistSitfisExpiresHeader(array $headers, IntegraResponse $response): array
    {
        if ($this->headerValue($headers, 'expires') !== null) {
            return $headers;
        }

        $expires = $response->expiresHeader;
        if ($expires === null || trim($expires) === '') {
            return $headers;
        }

        $headers['expires'] = mb_substr(trim($expires), 0, 200);

        return $headers;
    }

    private function sitfisProtocolFromDados(mixed $dados): ?string
    {
        if (! is_array($dados)) {
            return null;
        }

        foreach (['protocoloRelatorio', 'protocolo_relatorio', 'protocolo', 'protocol'] as $key) {
            if (! array_key_exists($key, $dados)) {
                continue;
            }
            $value = $dados[$key];
            if ($this->isOmittedDescriptor($value)) {
                continue;
            }
            if (is_scalar($value) && ! is_bool($value)) {
                $token = trim((string) $value);
                if ($token !== '' && ! str_contains($token, 'omitted_from_attempt_store')) {
                    return $token;
                }
            }
        }

        return null;
    }

    private function extractSitfisProtocolFromEtag(?string $etag): ?string
    {
        if ($etag === null) {
            return null;
        }

        $raw = trim($etag);
        if ($raw === '') {
            return null;
        }

        $raw = trim($raw, "\"'");
        if (! preg_match('/protocoloRelatorio\s*[=:]\s*(.+)$/i', $raw, $matches)) {
            return null;
        }

        $token = trim($matches[1], " \t\n\r\0\x0B\"'");
        if ($token === '' || str_contains($token, 'omitted_from_attempt_store')) {
            return null;
        }

        return $token;
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strcasecmp((string) $key, $name) === 0 && is_scalar($value)) {
                $string = trim((string) $value);

                return $string !== '' ? $string : null;
            }
        }

        return null;
    }

    private function blocked(
        string $operationKey,
        string $code,
        string $message,
        ?string $correlationId,
        ?string $requestTag,
        int $status,
    ): IntegraResponse {
        return new IntegraResponse(
            success: false,
            httpStatus: $status,
            body: [],
            errorCode: $code,
            errorMessage: $message,
            correlationId: $correlationId,
            operationKey: $operationKey,
            requestTag: $requestTag,
            sourceProvenance: FiscalSourceProvenance::Unverified->value,
        );
    }

    /**
     * Remove Base64 de PDFs PGDAS-D 14–16 antes de persistir no attempt store.
     *
     * @return array<string, mixed>|null
     */
    private function sanitizeAttemptDados(?string $operationKey, mixed $dados): ?array
    {
        if ($this->isPagtowebArrecadacaoReceiptKey($operationKey)) {
            return $this->sanitizePagtowebReceiptDescriptor($dados);
        }

        if (! is_array($dados)) {
            if (is_string($dados) && $dados !== '') {
                $decoded = json_decode($dados, true);
                if (is_array($decoded)) {
                    $dados = $decoded;
                } else {
                    return null;
                }
            } else {
                return null;
            }
        }

        $dados = $this->sanitizeResponseArray($dados, $operationKey);

        return $this->isDocumentalOperationKey($operationKey)
            ? $this->stripPdfBase64Fields($dados)
            : $dados;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function sanitizeAttemptBody(?string $operationKey, array $body): array
    {
        $body = $this->sanitizeResponseArray($body, $operationKey);

        if ($this->isPagtowebArrecadacaoReceiptKey($operationKey)) {
            $body['dados'] = $this->sanitizePagtowebReceiptDescriptor($body['dados'] ?? null);

            return $this->stripPdfBase64Fields($body);
        }

        return $this->isDocumentalOperationKey($operationKey)
            ? $this->stripPdfBase64Fields($body)
            : $body;
    }

    private function isDocumentalOperationKey(?string $operationKey): bool
    {
        return in_array($operationKey, [
            'pgdasd.consultimadecrec',
            'pgdasd.consdecrec',
            'pgdasd.consextrato',
            'pagtoweb.comparrecadacao',
        ], true);
    }

    private function isPagtowebArrecadacaoReceiptKey(?string $operationKey): bool
    {
        return $operationKey === 'pagtoweb.comparrecadacao';
    }

    /**
     * Preserva somente o descritor público já produzido pela captura pré-ACK.
     * Qualquer resposta bruta (inclusive Base64) vira marcador não reutilizável.
     *
     * @return array<string, mixed>
     */
    private function sanitizePagtowebReceiptDescriptor(mixed $dados): array
    {
        if (! is_array($dados) || ! isset($dados['receipt_id']) || ! is_int($dados['receipt_id']) || $dados['receipt_id'] < 1) {
            return $this->omittedDescriptor();
        }

        $allowed = ['receipt_id', 'available', 'id', 'mime_type', 'byte_size', 'source_provenance', 'observed_at'];
        $descriptor = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $dados) && (is_scalar($dados[$key]) || $dados[$key] === null)) {
                $descriptor[$key] = is_string($dados[$key])
                    ? $this->sanitizeScalar($dados[$key], false, 200)
                    : $dados[$key];
            }
        }

        return $descriptor['receipt_id'] ?? null ? $descriptor : $this->omittedDescriptor();
    }

    /**
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function stripPdfBase64Fields(array $node): array
    {
        $binaryFields = ['pdf', 'pdfNotificacao', 'pdfDarf'];
        foreach ($node as $field => &$value) {
            if (in_array((string) $field, $binaryFields, true) && is_string($value)) {
                $value = $this->omittedDescriptor();

                continue;
            }
            if (is_string($value)) {
                $value = $this->sanitizeScalar($value, true, 2000);

                continue;
            }
            if (is_array($value)) {
                $value = $this->stripPdfBase64Fields($value);
            }
        }
        unset($value);

        return $node;
    }

    /**
     * Sanitiza metadados laterais de respostas documentais (mensagens/headers),
     * inclusive blobs fora dos campos oficiais.
     *
     * @param  array<string|int, mixed>  $node
     * @return array<string|int, mixed>
     */
    private function sanitizeDocumentalArray(array $node): array
    {
        return $this->stripPdfBase64Fields($this->sanitizeResponseArray($node));
    }

    /**
     * Remove segredos e XML/Base64 ecoados antes de qualquer resposta ir para
     * o attempt store. O material sensível permanece exclusivamente no vault.
     * Protocolo SITFIS (protocoloRelatorio) é preservado (truncado), não omitido como blob.
     *
     * @param  array<string|int, mixed>  $node
     * @return array<string|int, mixed>
     */
    private function sanitizeResponseArray(array $node, ?string $operationKey = null): array
    {
        $preserveSitfisProtocol = $this->isSitfisOperationKey($operationKey);

        foreach ($node as $field => &$value) {
            $fieldName = (string) $field;
            if ($preserveSitfisProtocol && $this->isSitfisProtocolField($fieldName) && is_string($value) && $value !== '') {
                $value = mb_substr($value, 0, 512);

                continue;
            }

            if ($this->isSensitiveResponseField($fieldName)) {
                $value = $this->omittedDescriptor();

                continue;
            }

            if (is_array($value)) {
                $value = $this->sanitizeResponseArray($value, $operationKey);

                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $this->sanitizeResponseArray($decoded, $operationKey);

                continue;
            }

            $value = $this->sanitizeScalar($value, true, 2000);
        }
        unset($value);

        return $node;
    }

    private function isSitfisOperationKey(?string $operationKey): bool
    {
        return in_array($operationKey, [
            'sitfis.solicitar_protocolo',
            'sitfis.emitir_relatorio',
        ], true);
    }

    private function isSitfisProtocolField(string $field): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $field));

        return in_array($normalized, [
            'protocolorelatorio',
            'protocolo_relatorio',
            'protocolo',
            'protocol',
            'numeroprotocolo',
            'protocolnumber',
            'numero_protocolo',
        ], true);
    }

    /**
     * Sucesso sticky de solicit cuja resposta no store perdeu o protocolo (omit descriptor).
     */
    private function sitfisSolicitProtocolOmitted(SerproOperationAttempt $attempt): bool
    {
        if ($attempt->operation_key !== 'sitfis.solicitar_protocolo' || ! $attempt->success) {
            return false;
        }

        $payloads = [];
        if (is_array($attempt->dados)) {
            $payloads[] = $attempt->dados;
        }
        if (is_array($attempt->body)) {
            $payloads[] = $attempt->body;
            if (isset($attempt->body['dados']) && is_array($attempt->body['dados'])) {
                $payloads[] = $attempt->body['dados'];
            }
        }

        $sawOmitted = false;
        $sawUsable = false;
        foreach ($payloads as $payload) {
            foreach (['protocoloRelatorio', 'protocolo_relatorio', 'protocolo', 'protocol'] as $key) {
                if (! array_key_exists($key, $payload)) {
                    continue;
                }
                $value = $payload[$key];
                if ($this->isOmittedDescriptor($value)) {
                    $sawOmitted = true;

                    continue;
                }
                if (is_scalar($value) && trim((string) $value) !== '' && ! is_bool($value)) {
                    $sawUsable = true;
                }
            }
        }

        return $sawOmitted && ! $sawUsable;
    }

    private function isOmittedDescriptor(mixed $value): bool
    {
        if (is_string($value) && str_contains($value, 'omitted_from_attempt_store')) {
            return true;
        }

        return is_array($value)
            && (($value['omitted_from_attempt_store'] ?? false) === true
                || ($value['sanitized'] ?? false) === true);
    }

    private function isSensitiveResponseField(string $field): bool
    {
        $normalized = strtolower(str_replace(['-', ' '], '_', $field));

        if (in_array($normalized, ['pdf', 'pdfnotificacao', 'pdfdarf', 'xml', 'xmlassinado'], true)) {
            return true;
        }

        return (bool) preg_match(
            '/(?:^|_)(?:token|access_token|refresh_token|jwt_token|authorization|password|secret|pfx)(?:$|_)/',
            $normalized,
        );
    }

    private function sanitizeScalar(string $value, bool $documental, int $maxLength): mixed
    {
        if ($documental && $this->looksLikeEncodedBlob($value)) {
            return $this->omittedDescriptor();
        }

        return mb_substr($value, 0, $maxLength);
    }

    private function sanitizeErrorMessage(string $value, bool $documental): string
    {
        if ($documental && $this->looksLikeEncodedBlob($value)) {
            return '[conteúdo documental omitido]';
        }

        return mb_substr($value, 0, 500);
    }

    private function looksLikeEncodedBlob(string $value): bool
    {
        $candidate = preg_replace('/\s+/', '', $value) ?? '';
        $length = strlen($candidate);
        if ($length < 8 || $length % 4 !== 0) {
            return false;
        }
        if (strspn($candidate, 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789+/=') !== $length) {
            return false;
        }

        $decoded = base64_decode($candidate, true);
        if (! is_string($decoded)) {
            return false;
        }

        return str_starts_with($decoded, '%PDF') || $length >= 128;
    }

    /** @return array{sanitized: true, omitted_from_attempt_store: true} */
    private function omittedDescriptor(): array
    {
        return [
            'sanitized' => true,
            'omitted_from_attempt_store' => true,
        ];
    }
}
