<?php

namespace Tests\Unit\Serpro;

use App\DTO\Serpro\IntegraResponse;
use App\Enums\SerproAttemptState;
use App\Models\Office;
use App\Models\SerproOperationAttempt;
use App\Services\Serpro\SerproAttemptReplayPolicy;
use App\Services\Serpro\SerproOperationAttemptStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SerproOperationAttemptStoreReplayTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_marks_token_missing_as_non_sticky(): void
    {
        $this->assertTrue(SerproAttemptReplayPolicy::isNonStickyError('PROCURADOR_TOKEN_MISSING'));
        $this->assertTrue(SerproAttemptReplayPolicy::isNonStickyError('PROCURADOR_TOKEN_EXPIRED'));
        $this->assertTrue(SerproAttemptReplayPolicy::isNonStickyError('AUTHOR_IDENTITY_MISMATCH'));
        $this->assertFalse(SerproAttemptReplayPolicy::isStickyReplay('PROCURADOR_TOKEN_MISSING', false));
        $this->assertTrue(SerproAttemptReplayPolicy::isStickyReplay('REQUEST_FAILED', false));
        $this->assertTrue(SerproAttemptReplayPolicy::isStickyReplay(null, true));
    }

    public function test_token_missing_terminal_is_reclaimed_for_dispatch(): void
    {
        $office = Office::factory()->create();
        $key = 'idem-token-missing-'.uniqid();

        SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'PRODUCTION',
            'operation_key' => 'procuracoes.obter',
            'entity_key' => 'client:5',
            'idempotency_key' => $key,
            'request_tag' => str_repeat('a', 32),
            'correlation_id' => 'corr-old',
            'attempt_state' => SerproAttemptState::Acknowledged,
            'client_id' => 5,
            'success' => false,
            'http_status' => 422,
            'error_code' => 'PROCURADOR_TOKEN_MISSING',
            'error_message' => 'Token do procurador ausente ou expirado para o escritório.',
            'acknowledged_at' => now(),
        ]);

        $result = app(SerproOperationAttemptStore::class)->beginOrReplay(
            officeId: (int) $office->id,
            environment: 'PRODUCTION',
            operationKey: 'procuracoes.obter',
            entityKey: 'client:5',
            idempotencyKey: $key,
            requestTag: str_repeat('b', 32),
            correlationId: 'corr-new',
            clientId: 5,
        );

        $this->assertSame('dispatch', $result['action']);
        $this->assertNull($result['response']);
        $this->assertInstanceOf(SerproOperationAttempt::class, $result['attempt']);
        $this->assertSame(SerproAttemptState::Dispatched, $result['attempt']->attempt_state);
        $this->assertNull($result['attempt']->error_code);
        $this->assertSame('corr-new', $result['attempt']->correlation_id);
    }

    public function test_request_failed_terminal_stays_sticky_replay(): void
    {
        $office = Office::factory()->create();
        $key = 'idem-request-failed-'.uniqid();

        SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'PRODUCTION',
            'operation_key' => 'procuracoes.obter',
            'entity_key' => 'client:5',
            'idempotency_key' => $key,
            'request_tag' => str_repeat('c', 32),
            'correlation_id' => 'corr-fail',
            'attempt_state' => SerproAttemptState::Acknowledged,
            'client_id' => 5,
            'success' => false,
            'http_status' => 400,
            'error_code' => 'REQUEST_FAILED',
            'error_message' => 'Chamada Integra Contador rejeitada.',
            'acknowledged_at' => now(),
        ]);

        $result = app(SerproOperationAttemptStore::class)->beginOrReplay(
            officeId: (int) $office->id,
            environment: 'PRODUCTION',
            operationKey: 'procuracoes.obter',
            entityKey: 'client:5',
            idempotencyKey: $key,
            requestTag: str_repeat('d', 32),
            correlationId: 'corr-retry',
            clientId: 5,
        );

        $this->assertSame('replay', $result['action']);
        $this->assertNotNull($result['response']);
        $this->assertSame('REQUEST_FAILED', $result['response']->errorCode);
        $this->assertFalse($result['response']->success);
    }

    public function test_rate_limit_local_is_reclaimed(): void
    {
        $office = Office::factory()->create();
        $key = 'idem-rate-limit-'.uniqid();

        SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'PRODUCTION',
            'operation_key' => 'procuracoes.obter',
            'entity_key' => 'client:5',
            'idempotency_key' => $key,
            'request_tag' => str_repeat('e', 32),
            'attempt_state' => SerproAttemptState::Acknowledged,
            'success' => false,
            'http_status' => 429,
            'error_code' => 'RATE_LIMIT_LOCAL',
            'error_message' => 'RATE_LIMIT_LOCAL: limite da operação SERPRO atingido.',
            'acknowledged_at' => now(),
        ]);

        $result = app(SerproOperationAttemptStore::class)->beginOrReplay(
            officeId: (int) $office->id,
            environment: 'PRODUCTION',
            operationKey: 'procuracoes.obter',
            entityKey: 'client:5',
            idempotencyKey: $key,
            requestTag: str_repeat('f', 32),
            correlationId: null,
            clientId: 5,
        );

        $this->assertSame('dispatch', $result['action']);
    }

    public function test_cross_tenant_idempotency_still_blocked(): void
    {
        $owner = Office::factory()->create();
        $other = Office::factory()->create();
        $key = 'idem-cross-tenant-'.uniqid();

        SerproOperationAttempt::query()->create([
            'office_id' => $owner->id,
            'environment' => 'PRODUCTION',
            'operation_key' => 'procuracoes.obter',
            'entity_key' => 'client:5',
            'idempotency_key' => $key,
            'request_tag' => str_repeat('g', 32),
            'attempt_state' => SerproAttemptState::Acknowledged,
            'success' => false,
            'http_status' => 422,
            'error_code' => 'PROCURADOR_TOKEN_MISSING',
            'acknowledged_at' => now(),
        ]);

        $result = app(SerproOperationAttemptStore::class)->beginOrReplay(
            officeId: (int) $other->id,
            environment: 'PRODUCTION',
            operationKey: 'procuracoes.obter',
            entityKey: 'client:5',
            idempotencyKey: $key,
            requestTag: str_repeat('h', 32),
            correlationId: null,
            clientId: 5,
        );

        $this->assertSame('wait', $result['action']);
        $this->assertSame('IDEMPOTENCY_CROSS_TENANT', $result['response']?->errorCode);
    }

    public function test_abandon_local_precondition_deletes_attempt(): void
    {
        $office = Office::factory()->create();
        $attempt = SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'PRODUCTION',
            'operation_key' => 'procuracoes.obter',
            'entity_key' => 'client:3',
            'idempotency_key' => 'idem-abandon-'.uniqid(),
            'request_tag' => str_repeat('i', 32),
            'attempt_state' => SerproAttemptState::Dispatched,
            'client_id' => 3,
        ]);

        app(SerproOperationAttemptStore::class)->abandonLocalPrecondition($attempt);

        $this->assertNull(SerproOperationAttempt::query()->withoutGlobalScopes()->find($attempt->id));
    }

    public function test_purge_non_sticky_token_failures_for_office(): void
    {
        $office = Office::factory()->create();
        SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'PRODUCTION',
            'operation_key' => 'procuracoes.obter',
            'entity_key' => 'client:3',
            'idempotency_key' => 'idem-purge-a-'.uniqid(),
            'request_tag' => str_repeat('j', 32),
            'attempt_state' => SerproAttemptState::Acknowledged,
            'success' => false,
            'error_code' => 'PROCURADOR_TOKEN_MISSING',
            'acknowledged_at' => now(),
        ]);
        SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'PRODUCTION',
            'operation_key' => 'pgdasd.consultar',
            'entity_key' => 'client:3',
            'idempotency_key' => 'idem-purge-b-'.uniqid(),
            'request_tag' => str_repeat('k', 32),
            'attempt_state' => SerproAttemptState::Acknowledged,
            'success' => false,
            'error_code' => 'REQUEST_FAILED',
            'acknowledged_at' => now(),
        ]);

        $deleted = app(SerproOperationAttemptStore::class)
            ->purgeNonStickyTokenFailures((int) $office->id, 'PRODUCTION');

        $this->assertSame(1, $deleted);
        $this->assertSame(
            1,
            SerproOperationAttempt::query()->withoutGlobalScopes()->where('office_id', $office->id)->count(),
        );
    }

    public function test_sitfis_success_with_omitted_protocol_is_reclaimed(): void
    {
        $office = Office::factory()->create();
        $key = 'idem-sitfis-omitted-'.uniqid();

        SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'TRIAL',
            'operation_key' => 'sitfis.solicitar_protocolo',
            'entity_key' => 'client:1',
            'idempotency_key' => $key,
            'request_tag' => str_repeat('s', 32),
            'correlation_id' => 'corr-sitfis',
            'attempt_state' => SerproAttemptState::Acknowledged,
            'client_id' => 1,
            'success' => true,
            'http_status' => 200,
            'dados' => [
                'protocoloRelatorio' => [
                    'sanitized' => true,
                    'omitted_from_attempt_store' => true,
                ],
                'tempoEspera' => 4000,
            ],
            'body' => [
                'status' => 200,
                'dados' => [
                    'protocoloRelatorio' => [
                        'sanitized' => true,
                        'omitted_from_attempt_store' => true,
                    ],
                ],
            ],
            'acknowledged_at' => now(),
        ]);

        $result = app(SerproOperationAttemptStore::class)->beginOrReplay(
            officeId: (int) $office->id,
            environment: 'TRIAL',
            operationKey: 'sitfis.solicitar_protocolo',
            entityKey: 'client:1',
            idempotencyKey: $key,
            requestTag: str_repeat('t', 32),
            correlationId: 'corr-retry',
            clientId: 1,
        );

        $this->assertSame('dispatch', $result['action']);
        $this->assertNull($result['response']);
    }

    public function test_sitfis_acknowledge_preserves_long_protocol_scalar(): void
    {
        $office = Office::factory()->create();
        $protocol = str_repeat('A', 128);
        $attempt = SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'TRIAL',
            'operation_key' => 'sitfis.solicitar_protocolo',
            'entity_key' => 'client:1',
            'idempotency_key' => 'idem-sitfis-ack-'.uniqid(),
            'request_tag' => str_repeat('u', 32),
            'attempt_state' => SerproAttemptState::Dispatched,
            'client_id' => 1,
        ]);

        $response = new IntegraResponse(
            success: true,
            httpStatus: 200,
            body: [
                'status' => 200,
                'dados' => [
                    'protocoloRelatorio' => $protocol,
                    'tempoEspera' => 4000,
                ],
            ],
            dados: [
                'protocoloRelatorio' => $protocol,
                'tempoEspera' => 4000,
            ],
            operationKey: 'sitfis.solicitar_protocolo',
            sourceProvenance: 'SERPRO_REAL',
            businessStatus: '200',
        );

        app(SerproOperationAttemptStore::class)->acknowledge($attempt, $response);
        $attempt->refresh();

        $this->assertIsArray($attempt->dados);
        $this->assertSame($protocol, $attempt->dados['protocoloRelatorio'] ?? null);
        $this->assertFalse(isset($attempt->dados['protocoloRelatorio']['omitted_from_attempt_store']));
    }

    public function test_sitfis_304_ack_canonicalizes_protocol_from_etag_into_dados(): void
    {
        $office = Office::factory()->create();
        $protocol = str_repeat('C', 140);
        $expires = 'Tue, 21 Jul 2026 23:59:59 GMT';
        $attempt = SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'TRIAL',
            'operation_key' => 'sitfis.solicitar_protocolo',
            'entity_key' => 'client:1',
            'idempotency_key' => 'idem-sitfis-304-ack-'.uniqid(),
            'request_tag' => str_repeat('v', 32),
            'attempt_state' => SerproAttemptState::Dispatched,
            'client_id' => 1,
        ]);

        $response = new IntegraResponse(
            success: true,
            httpStatus: 304,
            body: [],
            headers: ['expires' => $expires],
            errorCode: 'NOT_MODIFIED',
            etag: '"protocoloRelatorio:'.$protocol.'"',
            expiresHeader: $expires,
            operationKey: 'sitfis.solicitar_protocolo',
            sourceProvenance: 'SERPRO_REAL',
            businessStatus: 'NOT_MODIFIED',
        );

        $store = app(SerproOperationAttemptStore::class);
        $store->acknowledge($attempt, $response);
        $attempt->refresh();

        $this->assertSame($protocol, $attempt->dados['protocoloRelatorio'] ?? null);
        $this->assertSame($expires, $attempt->headers['expires'] ?? null);

        $replayed = $store->toResponse($attempt);
        $this->assertSame('protocoloRelatorio:'.$protocol, $replayed->etag);
        $this->assertSame($expires, $replayed->expiresHeader);
        $this->assertSame($protocol, is_array($replayed->dados) ? ($replayed->dados['protocoloRelatorio'] ?? null) : null);
    }

    public function test_sitfis_to_response_restores_etag_from_dados_without_header(): void
    {
        $office = Office::factory()->create();
        $protocol = str_repeat('D', 96);
        $attempt = SerproOperationAttempt::query()->create([
            'office_id' => $office->id,
            'environment' => 'TRIAL',
            'operation_key' => 'sitfis.solicitar_protocolo',
            'entity_key' => 'client:1',
            'idempotency_key' => 'idem-sitfis-replay-'.uniqid(),
            'request_tag' => str_repeat('w', 32),
            'attempt_state' => SerproAttemptState::Acknowledged,
            'client_id' => 1,
            'success' => true,
            'http_status' => 304,
            'error_code' => 'NOT_MODIFIED',
            'dados' => ['protocoloRelatorio' => $protocol],
            'headers' => ['expires' => 'Wed, 22 Jul 2026 03:00:00 GMT'],
            'source_provenance' => 'SERPRO_REAL',
            'acknowledged_at' => now(),
        ]);

        $replayed = app(SerproOperationAttemptStore::class)->toResponse($attempt);

        $this->assertSame('protocoloRelatorio:'.$protocol, $replayed->etag);
        $this->assertSame('Wed, 22 Jul 2026 03:00:00 GMT', $replayed->expiresHeader);
        $this->assertSame(304, $replayed->httpStatus);
    }
}
