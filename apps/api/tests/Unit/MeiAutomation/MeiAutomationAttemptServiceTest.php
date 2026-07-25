<?php

namespace Tests\Unit\MeiAutomation;

use App\DTO\MeiAutomation\MeiAutomationJobResult;
use App\Enums\FiscalSourceProvenance;
use App\Enums\FiscalVerificationKind;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Models\Client;
use App\Models\Office;
use App\Services\MeiAutomation\MeiAutomationAttemptRepository;
use App\Services\MeiAutomation\MeiAutomationAttemptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LogicException;
use Tests\TestCase;

class MeiAutomationAttemptServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_builds_stable_fingerprint_and_opaque_client_reference(): void
    {
        config()->set('mei_automation.hmac.secret', 'shared-test-secret');
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $service = app(MeiAutomationAttemptService::class);

        $attempt = $service->start(
            $office,
            $client,
            'pgmei.dividaativa',
            MeiProvider::ReceitaPortal,
            'run:12345678',
            ['calendar_year' => 2026, 'cnpj' => '11222333000181'],
        );
        $request = $service->jobRequest($attempt, ['calendar_year' => 2026, 'cnpj' => '11222333000181']);

        self::assertSame($attempt->request_fingerprint, $request->requestFingerprint);
        self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $request->clientRef);
        self::assertNotSame((string) $client->id, $request->clientRef);
        self::assertStringNotContainsString('11222333000181', $request->clientRef);
        self::assertSame(
            $service->fingerprint('pgmei.dividaativa', ['cnpj' => '11222333000181', 'calendar_year' => 2026]),
            $attempt->request_fingerprint,
        );
    }

    public function test_synchronizes_safe_metadata_and_redacts_error(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $attempt = app(MeiAutomationAttemptService::class)->start(
            $office,
            $client,
            'pgmei.dividaativa',
            MeiProvider::ReceitaPortal,
            'run:87654321',
            [],
        );

        $attempt = app(MeiAutomationAttemptRepository::class)->synchronize(
            $attempt,
            new MeiAutomationJobResult(
                id: '0f82d5ec-d69f-4b2b-a2d6-b2c52e0e1b92',
                operationKey: 'pgmei.dividaativa',
                status: MeiAutomationStatus::Failed,
                result: null,
                error: ['code' => 'PORTAL_ERROR', 'message' => 'CNPJ 11222333000181 token=secret'],
                artifacts: [],
                actionType: null,
            ),
            ['portal_version' => '2026.07', 'raw_html' => '<html>fiscal</html>'],
        );

        self::assertSame(FiscalSourceProvenance::ReceitaPortal, $attempt->source_provenance);
        self::assertSame(FiscalVerificationKind::PortalArtifact, $attempt->verification_kind);
        self::assertStringNotContainsString('11222333000181', (string) $attempt->error_message);
        self::assertStringNotContainsString('secret', (string) $attempt->error_message);
        self::assertArrayNotHasKey('raw_html', $attempt->safe_metadata);
    }

    public function test_attempt_idempotency_reuses_same_input_and_rejects_collision(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $service = app(MeiAutomationAttemptService::class);
        $input = ['cnpj' => '11222333000181', 'calendar_year' => 2026];

        $first = $service->start(
            $office,
            $client,
            'pgmei.dividaativa',
            MeiProvider::ReceitaPortal,
            'same:12345678',
            $input,
        );
        $second = $service->start(
            $office,
            $client,
            'pgmei.dividaativa',
            MeiProvider::ReceitaPortal,
            'same:12345678',
            array_reverse($input, true),
        );
        self::assertSame($first->id, $second->id);

        $this->expectException(LogicException::class);
        $service->start(
            $office,
            $client,
            'pgmei.dividaativa',
            MeiProvider::ReceitaPortal,
            'same:12345678',
            ['cnpj' => '11222333000181', 'calendar_year' => 2025],
        );
    }

    public function test_success_result_is_validated_encrypted_and_versioned(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $attempt = app(MeiAutomationAttemptService::class)->start(
            $office,
            $client,
            'pgmei.dividaativa',
            MeiProvider::ReceitaPortal,
            'result:12345678',
            ['cnpj' => '11222333000181', 'calendar_year' => 2026],
        );

        $synced = app(MeiAutomationAttemptRepository::class)->synchronize(
            $attempt,
            new MeiAutomationJobResult(
                id: '0f82d5ec-d69f-4b2b-a2d6-b2c52e0e1b92',
                operationKey: 'pgmei.dividaativa',
                status: MeiAutomationStatus::Succeeded,
                result: [
                    'years' => [['year' => 2026, 'status' => 'Sem débitos', 'debt_count' => 0]],
                    'parser_version' => 'pgmei-1',
                    'portal_version' => 'fixture-1',
                ],
                error: null,
                artifacts: [],
                actionType: null,
                captchaDriver: 'nopecha',
                captchaCostMicros: 100,
            ),
        );

        self::assertSame('pgmei-1', $synced->parser_version);
        self::assertSame('fixture-1', $synced->portal_version);
        self::assertSame('nopecha', $synced->captcha_driver);
        self::assertSame(100, $synced->captcha_cost_micros);
        self::assertSame(2026, $synced->result_payload_encrypted['years'][0]['year']);
        $raw = \DB::table('mei_automation_attempts')->where('id', $synced->id)
            ->value('result_payload_encrypted');
        self::assertIsString($raw);
        self::assertStringNotContainsString('Sem débitos', $raw);
    }
}
