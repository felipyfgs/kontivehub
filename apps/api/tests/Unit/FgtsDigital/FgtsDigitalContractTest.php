<?php

namespace Tests\Unit\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalPortalRequest;
use App\DTO\FgtsDigital\FgtsDigitalPortalResult;
use App\Enums\FgtsDigitalOperation;
use App\Enums\FgtsDigitalRunStatus;
use App\Services\FgtsDigital\Clients\FixtureFgtsDigitalPortalClient;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use Tests\TestCase;

class FgtsDigitalContractTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fgts_digital.contract_version', 1);
        config()->set('fgts_digital.runtime.fixtures', base_path('rpa/fgts_digital/fixtures'));
    }

    public function test_fixture_contract_decodes_pdf_and_public_result_never_contains_bytes_or_session(): void
    {
        $result = app(FixtureFgtsDigitalPortalClient::class)->execute(new FgtsDigitalPortalRequest(
            operation: FgtsDigitalOperation::QueryGuides,
            officeId: 10,
            clientId: 20,
            targetIdentifier: '12345678',
            pfx: 'sensitive-pfx',
            pfxPassword: 'sensitive-password',
            storageState: ['cookies' => [['value' => 'sensitive-cookie']], 'origins' => []],
        ));

        $this->assertSame('SUCCEEDED', $result->status);
        $this->assertStringStartsWith('%PDF-', $result->artifacts[0]['bytes']);
        $public = json_encode($result->toPublicArray(), JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('sensitive', $public);
        $this->assertStringNotContainsString('%PDF-', $public);
        $this->assertGreaterThan(0, $result->toPublicArray()['artifacts'][0]['byte_size']);
    }

    public function test_contract_rejects_artifact_digest_mismatch(): void
    {
        $this->expectException(FgtsDigitalException::class);
        $this->expectExceptionMessage('Hash do artefato RPA diverge.');

        FgtsDigitalPortalResult::fromTransportArray([
            'contract_version' => 1,
            'status' => 'SUCCEEDED',
            'code' => 'OK',
            'message' => 'ok',
            'data' => [],
            'artifacts' => [[
                'name' => '../escape.pdf',
                'content_type' => 'application/pdf',
                'content_base64' => base64_encode('%PDF-test'),
                'sha256' => str_repeat('0', 64),
            ]],
        ]);
    }

    public function test_portal_solver_runs_in_worker_context_instead_of_php_retry_wrapper(): void
    {
        $worker = strtolower((string) file_get_contents(base_path('rpa/fgts_digital/worker.py')));

        $this->assertSame('disabled', config('fgts_digital.captcha.driver'));
        $this->assertStringContainsString('api.nopecha.com/token/', $worker);
        $this->assertStringContainsString('authenticated_portal', $worker);
        $this->assertFileDoesNotExist(app_path('Services/FgtsDigital/FgtsDigitalCaptchaAwareClient.php'));
    }

    public function test_human_challenge_and_ambiguous_emission_are_terminal_without_automatic_retry(): void
    {
        $this->assertTrue(FgtsDigitalRunStatus::HumanChallengeRequired->isTerminal());
        $this->assertTrue(FgtsDigitalRunStatus::ReconciliationRequired->isTerminal());
        $this->assertFalse(FgtsDigitalRunStatus::Authorized->isTerminal());
    }
}
