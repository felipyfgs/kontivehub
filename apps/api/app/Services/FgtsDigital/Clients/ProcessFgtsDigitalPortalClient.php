<?php

namespace App\Services\FgtsDigital\Clients;

use App\Contracts\FgtsDigitalPortalClient;
use App\DTO\FgtsDigital\FgtsDigitalPortalRequest;
use App\DTO\FgtsDigital\FgtsDigitalPortalResult;
use App\Services\FgtsDigital\Exceptions\FgtsDigitalException;
use App\Services\FgtsDigital\FgtsDigitalCaptchaConfig;
use Symfony\Component\Process\Exception\ProcessTimedOutException;
use Symfony\Component\Process\Process;

final class ProcessFgtsDigitalPortalClient implements FgtsDigitalPortalClient
{
    public function __construct(
        private readonly FgtsDigitalCaptchaConfig $captchaConfig,
    ) {}

    public function execute(FgtsDigitalPortalRequest $request): FgtsDigitalPortalResult
    {
        if (! (bool) config('fgts_digital.egress_enabled', false)) {
            throw new FgtsDigitalException('Egress do portal FGTS Digital desabilitado.', 'FGTS_DIGITAL_EGRESS_DISABLED', 503);
        }
        if ($request->operation->mutatesPortal() && ! (bool) config('fgts_digital.mutations_enabled', false)) {
            throw new FgtsDigitalException('Mutações no portal FGTS Digital desabilitadas.', 'FGTS_DIGITAL_MUTATIONS_DISABLED', 403);
        }
        if (($blocker = $this->captchaConfig->blocker()) !== null) {
            throw new FgtsDigitalException($blocker['message'], $blocker['code'], 503);
        }

        $python = (string) config('fgts_digital.runtime.executable');
        $worker = (string) config('fgts_digital.runtime.worker');
        if (! is_executable($python) || ! is_readable($worker)) {
            throw new FgtsDigitalException('Runtime RPA indisponível.', 'FGTS_DIGITAL_RUNTIME_UNAVAILABLE', 503);
        }

        $input = json_encode($request->toTransportArray(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $process = new Process(
            [$python, $worker],
            base_path(),
            $this->isolatedEnvironment(),
            $input,
            (float) config('fgts_digital.runtime.timeout_seconds', 120),
        );

        try {
            $process->mustRun();
        } catch (ProcessTimedOutException) {
            throw new FgtsDigitalException('Tempo limite do portal FGTS Digital excedido.', 'FGTS_DIGITAL_TIMEOUT', 504);
        } catch (\Throwable) {
            throw new FgtsDigitalException('Falha sanitizada no runtime FGTS Digital.', 'FGTS_DIGITAL_RUNTIME_FAILED', 502);
        } finally {
            $input = str_repeat("\0", strlen($input));
        }

        $output = $process->getOutput();
        $max = (int) config('fgts_digital.runtime.max_output_bytes', 8_388_608);
        if ($output === '' || strlen($output) > $max) {
            throw new FgtsDigitalException('Resposta RPA vazia ou acima do limite.', 'FGTS_DIGITAL_RESPONSE_LIMIT', 502);
        }
        try {
            $payload = json_decode($output, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new FgtsDigitalException('Resposta RPA não é JSON válido.', 'FGTS_DIGITAL_RESPONSE_INVALID', 502);
        }
        if (! is_array($payload)) {
            throw new FgtsDigitalException('Resposta RPA inválida.', 'FGTS_DIGITAL_RESPONSE_INVALID', 502);
        }

        return FgtsDigitalPortalResult::fromTransportArray($payload);
    }

    /** @return array<string, string|false> */
    private function isolatedEnvironment(): array
    {
        $inheritedKeys = [];
        foreach ([getenv(), $_ENV, $_SERVER] as $source) {
            if (is_array($source)) {
                $inheritedKeys = [...$inheritedKeys, ...array_keys($source)];
            }
        }

        $environment = array_fill_keys(array_unique($inheritedKeys), false);
        $environment['LANG'] = $this->environmentValue('LANG') ?? 'C.UTF-8';
        $environment['LC_ALL'] = $this->environmentValue('LC_ALL') ?? 'C.UTF-8';
        $environment['PATH'] = $this->environmentValue('PATH') ?? '/usr/local/bin:/usr/bin:/bin';
        $environment['HOME'] = $this->environmentValue('HOME') ?? sys_get_temp_dir();
        $environment['TMPDIR'] = $this->environmentValue('TMPDIR') ?? sys_get_temp_dir();
        $environment['PYTHONUNBUFFERED'] = '1';

        $playwrightBrowsers = $this->environmentValue('PLAYWRIGHT_BROWSERS_PATH');
        if ($playwrightBrowsers !== null) {
            $environment['PLAYWRIGHT_BROWSERS_PATH'] = $playwrightBrowsers;
        }

        return $environment;
    }

    private function environmentValue(string $key): ?string
    {
        $value = getenv($key);
        if (! is_string($value) || trim($value) === '') {
            $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;
        }

        return is_scalar($value) && trim((string) $value) !== ''
            ? (string) $value
            : null;
    }
}
