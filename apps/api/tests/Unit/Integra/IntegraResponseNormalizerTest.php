<?php

namespace Tests\Unit\Integra;

use App\DTO\Serpro\IntegraRequest;
use App\DTO\Serpro\IntegraResponse;
use App\Enums\SerproEnvironment;
use App\Services\Integra\IntegraResponseNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class IntegraResponseNormalizerTest extends TestCase
{
    private IntegraResponseNormalizer $normalizer;

    private IntegraRequest $request;

    protected function setUp(): void
    {
        parent::setUp();

        $this->normalizer = new IntegraResponseNormalizer;
        $this->request = new IntegraRequest(
            officeId: 10,
            clientId: 20,
            environment: SerproEnvironment::Trial->value,
            contractorCnpj: '11222333000181',
            authorIdentity: '11222333000181',
            contributorCnpj: '11222333000181',
            operationKey: 'dctfweb.consrecibo',
            correlationId: 'corr-test',
            requestTag: 'tag-test',
        );
    }

    public function test_parses_official_stringified_dados_envelope(): void
    {
        $response = $this->normalize(200, json_encode([
            'status' => 200,
            'dados' => json_encode(['numeroRecibo' => 24573], JSON_THROW_ON_ERROR),
            'mensagens' => [['codigo' => 'Sucesso-DCTFWEB-MG00', 'texto' => 'Sucesso']],
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($response->success);
        $this->assertSame(['numeroRecibo' => 24573], $response->dados);
        $this->assertSame('SERPRO_TRIAL', $response->sourceProvenance);
    }

    #[DataProvider('invalidEnvelopeProvider')]
    public function test_fails_closed_for_empty_or_non_json_http_200(string $body): void
    {
        $response = $this->normalize(200, $body);

        $this->assertFalse($response->success);
        $this->assertSame('INVALID_RESPONSE_FORMAT', $response->errorCode);
        $this->assertSame([], $response->body);
    }

    /** @return array<string, array{string}> */
    public static function invalidEnvelopeProvider(): array
    {
        return [
            'empty' => [''],
            'html' => ['<html>proxy error</html>'],
            'scalar json' => ['"unexpected"'],
        ];
    }

    public function test_rejects_malformed_dados_inside_valid_envelope(): void
    {
        $response = $this->normalize(200, json_encode([
            'status' => 200,
            'dados' => '{broken-json',
        ], JSON_THROW_ON_ERROR));

        $this->assertFalse($response->success);
        $this->assertSame('INVALID_DADOS_FORMAT', $response->errorCode);
        $this->assertNull($response->dados);
    }

    public function test_accepts_direct_array_dados_used_by_official_eprocesso_example(): void
    {
        $response = $this->normalize(200, json_encode([
            'status' => 200,
            'dados' => [['numeroProcesso' => '10000.000001/2026-00']],
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($response->success);
        $this->assertSame('10000.000001/2026-00', $response->dados[0]['numeroProcesso']);
    }

    public function test_recognizes_bracketed_manager_business_error(): void
    {
        $response = $this->normalize(200, json_encode([
            'status' => 200,
            'dados' => '{}',
            'mensagens' => [[
                'codigo' => '[Erro-ICGERENCIADOR-001]',
                'texto' => 'Serviço informado em rota incorreta.',
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->assertFalse($response->success);
        $this->assertSame('BUSINESS_ERROR', $response->errorCode);
    }

    public function test_treats_202_as_successful_async_state_and_converts_sitfis_milliseconds(): void
    {
        $response = $this->normalize(202, json_encode([
            'status' => 202,
            'dados' => json_encode([
                'protocoloRelatorio' => 'PROTOCOLO-1',
                'tempoEspera' => 5000,
            ], JSON_THROW_ON_ERROR),
        ], JSON_THROW_ON_ERROR));

        $this->assertTrue($response->success);
        $this->assertTrue($response->isStillProcessing());
        $this->assertSame(5, $response->retryAfterSeconds);
        $this->assertSame(5, $response->waitSeconds());
        $this->assertNull($response->errorCode);
    }

    public function test_treats_empty_204_as_successful_async_state(): void
    {
        $response = $this->normalize(204, '');

        $this->assertTrue($response->success);
        $this->assertTrue($response->isStillProcessing());
        $this->assertSame(30, $response->retryAfterSeconds);
    }

    public function test_preserves_sanitized_manager_messages_on_503(): void
    {
        $response = $this->normalize(503, json_encode([
            'status' => 503,
            'dados' => '{}',
            'mensagens' => [[
                'codigo' => '[Erro-ICGERENCIADOR-056]',
                'texto' => 'Resposta fora do padrão.',
            ]],
        ], JSON_THROW_ON_ERROR));

        $this->assertFalse($response->success);
        $this->assertSame('UPSTREAM_UNAVAILABLE', $response->errorCode);
        $this->assertSame('[Erro-ICGERENCIADOR-056]', $response->mensagens[0]['codigo']);
        $this->assertSame(503, $response->body['status']);
    }

    public function test_preserves_messages_and_retry_after_on_429(): void
    {
        $response = $this->normalize(429, json_encode([
            'status' => 429,
            'mensagens' => [['codigo' => 'LIMITE', 'texto' => 'Aguarde']],
        ], JSON_THROW_ON_ERROR), retryAfter: 17);

        $this->assertFalse($response->success);
        $this->assertSame('RATE_LIMITED', $response->errorCode);
        $this->assertSame(17, $response->retryAfterSeconds);
        $this->assertSame('LIMITE', $response->mensagens[0]['codigo']);
    }

    private function normalize(int $status, string $body, ?int $retryAfter = null): IntegraResponse
    {
        return $this->normalizer->normalize(
            response: [
                'status' => $status,
                'body' => $body,
                'headers' => ['content-type' => 'application/json'],
                'retry_after' => $retryAfter,
                'latency_ms' => 12,
            ],
            request: $this->request,
            operationKey: 'dctfweb.consrecibo',
            requestTag: 'tag-test',
            route: 'Consultar',
            environment: SerproEnvironment::Trial,
        );
    }
}
