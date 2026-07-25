<?php

namespace Tests\Unit\FgtsDigital;

use App\DTO\FgtsDigital\FgtsDigitalPortalRequest;
use App\Enums\FgtsDigitalOperation;
use App\Services\FgtsDigital\Clients\ProcessFgtsDigitalPortalClient;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use Tests\TestCase;

class ProcessFgtsDigitalPortalClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('fgts_digital.egress_enabled', true);
        config()->set('fgts_digital.mutations_enabled', false);
        config()->set('fgts_digital.captcha.driver', 'disabled');
        config()->set('fgts_digital.runtime.executable', '/bin/sh');
        config()->set('fgts_digital.runtime.max_output_bytes', 1_048_576);
    }

    public function test_invalid_process_json_is_mapped_to_stable_sanitized_error(): void
    {
        $worker = $this->worker("printf 'not-json'");
        config()->set('fgts_digital.runtime.worker', $worker);

        try {
            app(ProcessFgtsDigitalPortalClient::class)->execute($this->request());
            $this->fail('A resposta inválida deveria ser rejeitada.');
        } catch (FgtsDigitalException $e) {
            $this->assertSame('FGTS_DIGITAL_RESPONSE_INVALID', $e->codeKey);
            $this->assertStringNotContainsString('not-json', $e->getMessage());
        } finally {
            @unlink($worker);
        }
    }

    public function test_timeout_and_stderr_never_disclose_worker_material(): void
    {
        $worker = $this->worker("printf 'private-worker-secret' >&2\nsleep 2");
        config()->set('fgts_digital.runtime.worker', $worker);
        config()->set('fgts_digital.runtime.timeout_seconds', 0.01);

        try {
            app(ProcessFgtsDigitalPortalClient::class)->execute($this->request());
            $this->fail('O timeout deveria interromper o processo.');
        } catch (FgtsDigitalException $e) {
            $this->assertSame('FGTS_DIGITAL_TIMEOUT', $e->codeKey);
            $this->assertStringNotContainsString('private-worker-secret', $e->getMessage());
        } finally {
            @unlink($worker);
        }
    }

    public function test_mutation_is_blocked_before_process_when_flag_is_off(): void
    {
        $worker = $this->worker("printf 'must-not-run' >&2\nexit 7");
        config()->set('fgts_digital.runtime.worker', $worker);

        try {
            app(ProcessFgtsDigitalPortalClient::class)->execute(new FgtsDigitalPortalRequest(
                operation: FgtsDigitalOperation::EmitGuide,
                officeId: 1,
                clientId: 2,
                targetIdentifier: '00000000000000',
            ));
            $this->fail('A mutação desabilitada deveria ser bloqueada.');
        } catch (FgtsDigitalException $e) {
            $this->assertSame('FGTS_DIGITAL_MUTATIONS_DISABLED', $e->codeKey);
            $this->assertStringNotContainsString('must-not-run', $e->getMessage());
        } finally {
            @unlink($worker);
        }
    }

    public function test_process_receives_only_allowlisted_environment(): void
    {
        $key = 'FGTS_RPA_PARENT_SECRET';
        $previousProcessValue = getenv($key);
        $previousEnvValue = $_ENV[$key] ?? null;
        $previousServerValue = $_SERVER[$key] ?? null;
        putenv($key.'=must-not-reach-worker');
        $_ENV[$key] = 'must-not-reach-worker';
        $_SERVER[$key] = 'must-not-reach-worker';
        $worker = $this->worker(<<<'SH'
if [ -n "${FGTS_RPA_PARENT_SECRET:-}" ]; then
  code='PARENT_SECRET_LEAKED'
else
  code='ENVIRONMENT_ISOLATED'
fi
printf '{"contract_version":1,"status":"SUCCEEDED","code":"%s","message":"ok","data":{},"artifacts":[],"session":null}' "$code"
SH);
        config()->set('fgts_digital.runtime.worker', $worker);

        try {
            $result = app(ProcessFgtsDigitalPortalClient::class)->execute($this->request());
            $this->assertSame('ENVIRONMENT_ISOLATED', $result->code);
        } finally {
            if ($previousProcessValue === false) {
                putenv($key);
            } else {
                putenv($key.'='.$previousProcessValue);
            }

            if ($previousEnvValue === null) {
                unset($_ENV[$key]);
            } else {
                $_ENV[$key] = $previousEnvValue;
            }

            if ($previousServerValue === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $previousServerValue;
            }

            @unlink($worker);
        }
    }

    private function request(): FgtsDigitalPortalRequest
    {
        return new FgtsDigitalPortalRequest(
            operation: FgtsDigitalOperation::QueryGuides,
            officeId: 1,
            clientId: 2,
            targetIdentifier: '00000000000000',
        );
    }

    private function worker(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'fgts-worker-');
        if ($path === false) {
            $this->fail('Não foi possível criar worker temporário.');
        }
        file_put_contents($path, "#!/bin/sh\nset -eu\n".$body."\n");
        chmod($path, 0700);

        return $path;
    }
}
