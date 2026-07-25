<?php

namespace Tests\Unit\MeiAutomation;

use App\DTO\MeiAutomation\MeiAutomationJobRequest;
use App\Enums\MeiAutomationStatus;
use App\Exceptions\MeiAutomationTransportException;
use App\Services\MeiAutomation\MeiAutomationClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Tests\TestCase;

class MeiAutomationClientTest extends TestCase
{
    public function test_creates_signed_job_without_leaking_contract_fields(): void
    {
        config()->set('mei_automation.base_url', 'http://mei.test');
        config()->set('mei_automation.hmac.key_id', 'laravel');
        config()->set('mei_automation.hmac.secret', 'shared-test-secret');
        Http::fake([
            'http://mei.test/v1/jobs' => Http::response([
                'id' => '0f82d5ec-d69f-4b2b-a2d6-b2c52e0e1b92',
                'operation_key' => 'fixture.health',
                'status' => 'QUEUED',
                'result' => null,
                'error' => null,
                'artifacts' => [],
                'action_type' => null,
            ], 202),
        ]);

        $result = app(MeiAutomationClient::class)->create(new MeiAutomationJobRequest(
            operationKey: 'fixture.health',
            idempotencyKey: 'fixture:12345678',
            requestFingerprint: str_repeat('a', 64),
            clientRef: 'opaque-client-fixture',
        ));

        self::assertSame(MeiAutomationStatus::Queued, $result->status);
        Http::assertSent(function (Request $request): bool {
            return $request->hasHeader('X-MEI-Signature')
                && $request->hasHeader('X-MEI-Nonce')
                && $request->url() === 'http://mei.test/v1/jobs';
        });
    }

    public function test_downloads_artifact_with_signed_exact_path(): void
    {
        config()->set('mei_automation.base_url', 'http://mei.test');
        config()->set('mei_automation.hmac.key_id', 'laravel');
        config()->set('mei_automation.hmac.secret', 'shared-test-secret');
        $jobId = '0f82d5ec-d69f-4b2b-a2d6-b2c52e0e1b92';
        $artifactId = '3dfad6d4-f87c-44da-91eb-1e77cf53dd57';
        Http::fake([
            "http://mei.test/v1/jobs/{$jobId}/artifacts/{$artifactId}" => Http::response(
                '%PDF-1.7 fixture',
                200,
                ['Content-Type' => 'application/pdf'],
            ),
        ]);

        $response = app(MeiAutomationClient::class)->downloadArtifact($jobId, $artifactId);

        self::assertSame('%PDF-1.7 fixture', $response->body());
        Http::assertSent(fn (Request $request): bool => $request->hasHeader('X-MEI-Signature')
            && $request->url() === "http://mei.test/v1/jobs/{$jobId}/artifacts/{$artifactId}");
    }

    public function test_rejects_disallowed_input_before_http_and_hmac_transport(): void
    {
        config()->set('mei_automation.hmac.key_id', 'laravel');
        config()->set('mei_automation.hmac.secret', 'shared-test-secret');
        Http::fake();

        try {
            app(MeiAutomationClient::class)->create(new MeiAutomationJobRequest(
                operationKey: 'fixture.health',
                idempotencyKey: 'fixture:12345678',
                requestFingerprint: str_repeat('a', 64),
                clientRef: 'opaque-client-fixture',
                input: ['password' => 'nao-enviar'],
            ));
            self::fail('Input fora da allowlist deveria ser rejeitado.');
        } catch (InvalidArgumentException) {
            Http::assertNothingSent();
        }
    }

    public function test_connection_failure_is_wrapped_as_transport_exception(): void
    {
        config()->set('mei_automation.base_url', 'http://mei.test');
        config()->set('mei_automation.hmac.key_id', 'laravel');
        config()->set('mei_automation.hmac.secret', 'shared-test-secret');
        Http::fake(function () {
            throw new ConnectionException('Could not resolve host: mei');
        });

        try {
            app(MeiAutomationClient::class)->create(new MeiAutomationJobRequest(
                operationKey: 'fixture.health',
                idempotencyKey: 'fixture:12345678',
                requestFingerprint: str_repeat('a', 64),
                clientRef: 'opaque-client-fixture',
            ));
            self::fail('Falha de conexão deveria virar MeiAutomationTransportException.');
        } catch (MeiAutomationTransportException $error) {
            self::assertSame('AUTOMATION_TRANSPORT_ERROR', $error->errorCode);
            self::assertSame(0, $error->httpStatus);
        }
    }
}
