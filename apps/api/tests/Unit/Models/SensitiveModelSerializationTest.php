<?php

namespace Tests\Unit\Models;

use App\Enums\ActivationMethod;
use App\Enums\ActivationPurpose;
use App\Enums\FiscalMutationStatus;
use App\Enums\FiscalVerificationKind;
use App\Enums\MeiAutomationStatus;
use App\Enums\MeiProvider;
use App\Enums\SerproEnvironment;
use App\Models\AccountActivation;
use App\Models\FiscalMutationOperation;
use App\Models\MeiAutomationAttempt;
use App\Models\SerproOperationAttempt;
use App\Models\TaxGuideDownloadToken;
use Illuminate\Database\Eloquent\Model;
use Tests\TestCase;

final class SensitiveModelSerializationTest extends TestCase
{
    public function test_account_activation_hides_recipient_and_secret_hash(): void
    {
        $activation = (new AccountActivation)->forceFill([
            'purpose' => ActivationPurpose::OfficeMember,
            'method' => ActivationMethod::ManualLink,
            'email_normalized' => 'fiscal@example.test',
            'secret_hash' => 'activation-secret-hash',
            'generation' => 1,
        ]);

        $this->assertHiddenFromAutomaticSerialization($activation, [
            'email_normalized',
            'secret_hash',
        ]);
        self::assertSame('activation-secret-hash', $activation->secret_hash);
        self::assertSame('f***@example.test', $activation->toSanitizedArray()['email_masked']);
    }

    public function test_tax_guide_download_hides_token_hash(): void
    {
        $token = (new TaxGuideDownloadToken)->forceFill([
            'token_hash' => 'download-token-hash',
        ]);

        $this->assertHiddenFromAutomaticSerialization($token, ['token_hash']);
        self::assertSame('download-token-hash', $token->token_hash);
    }

    public function test_mei_attempt_hides_internal_context_artifacts_and_decrypted_result(): void
    {
        $attempt = (new MeiAutomationAttempt)->forceFill([
            'external_job_id' => 'external-job-secret',
            'operation_key' => 'pgmei.consultar',
            'provider' => MeiProvider::ReceitaPortal,
            'status' => MeiAutomationStatus::Succeeded,
            'idempotency_key' => 'mei-idempotency-secret',
            'request_fingerprint' => str_repeat('a', 64),
            'source_provenance' => 'PORTAL_ARTIFACT',
            'verification_kind' => FiscalVerificationKind::PortalArtifact,
            'portal_version' => 'portal-internal-version',
            'parser_version' => 'parser-internal-version',
            'captcha_driver' => 'captcha-internal-driver',
            'captcha_cost_micros' => 123,
            'safe_metadata' => ['cnpj' => '11222333000181'],
            'vault_artifacts' => [['id' => 'private-vault-id', 'name' => 'guia.pdf']],
            'result_payload_encrypted' => ['cnpj' => '11222333000181', 'raw' => 'fiscal-result'],
        ]);

        $this->assertHiddenFromAutomaticSerialization($attempt, [
            'external_job_id',
            'idempotency_key',
            'request_fingerprint',
            'portal_version',
            'parser_version',
            'captcha_driver',
            'captcha_cost_micros',
            'safe_metadata',
            'vault_artifacts',
            'result_payload_encrypted',
        ]);
        self::assertSame('fiscal-result', $attempt->result_payload_encrypted['raw']);
        self::assertArrayNotHasKey('cnpj', $attempt->toPublicArray()['metadata']);
    }

    public function test_fiscal_mutation_hides_preflight_and_request_internals(): void
    {
        $operation = (new FiscalMutationOperation)->forceFill([
            'status' => FiscalMutationStatus::Pending,
            'environment' => SerproEnvironment::Trial,
            'preflight_token' => 'preflight-capability-secret',
            'request_sanitized' => ['period_key' => '2026-07'],
            'request_payload_encrypted' => ['cnpj' => '11222333000181'],
            'request_payload_digest' => str_repeat('b', 64),
        ]);

        $this->assertHiddenFromAutomaticSerialization($operation, [
            'preflight_token',
            'request_sanitized',
            'request_payload_encrypted',
            'request_payload_digest',
        ]);
        self::assertSame('11222333000181', $operation->request_payload_encrypted['cnpj']);
        self::assertSame('preflight-capability-secret', $operation->toPublicArray()['preflight_token']);
    }

    public function test_serpro_attempt_hides_raw_response_envelopes(): void
    {
        $attempt = (new SerproOperationAttempt)->forceFill([
            'mensagens' => [['codigo' => 'SUCESSO', 'texto' => 'mensagem fiscal']],
            'dados' => ['cnpj' => '11222333000181'],
            'body' => ['dados' => ['raw' => 'body fiscal']],
            'headers' => ['x-correlation-id' => 'private-correlation'],
        ]);

        $this->assertHiddenFromAutomaticSerialization($attempt, [
            'mensagens',
            'dados',
            'body',
            'headers',
        ]);
        self::assertSame('11222333000181', $attempt->dados['cnpj']);
    }

    /** @param list<string> $keys */
    private function assertHiddenFromAutomaticSerialization(Model $model, array $keys): void
    {
        $representations = [$model->toArray(), $model->jsonSerialize()];

        foreach ($representations as $representation) {
            self::assertIsArray($representation);

            foreach ($keys as $key) {
                self::assertArrayNotHasKey($key, $representation);
            }
        }
    }
}
