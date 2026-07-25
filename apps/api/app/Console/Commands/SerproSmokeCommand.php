<?php

namespace App\Console\Commands;

use App\Enums\SerproEnvironment;
use App\Models\SerproContract;
use App\Services\Serpro\SerproSmokeService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Smoke SERPRO opt-in (TLS / OAuth mTLS).
 *
 * Default: status/checklist offline. Live exige SERPRO_SMOKE_ENABLED + --confirm.
 * Nunca roda live em CI. Nunca chama Consultar/Emitir/Declarar.
 */
class SerproSmokeCommand extends Command
{
    protected $signature = 'serpro:smoke
        {mode=status : status|checklist|tls|oauth}
        {--serpro-env= : Ambiente SERPRO}
        {--contract-id= : ID do contrato (oauth)}
        {--confirm= : Frase I_UNDERSTAND_LIVE_SERPRO para live}
        {--record-readiness : Grava evidência TLS_OK/OAUTH_OK no readiness}
        {--json : Saída JSON sanitizada}';

    protected $description = 'Smoke SERPRO: status/checklist offline; TLS/OAuth live opt-in (nunca em CI)';

    public function handle(SerproSmokeService $smoke): int
    {
        $mode = strtolower((string) $this->argument('mode'));
        $envRaw = $this->option('serpro-env')
            ?: (string) config('serpro.default_environment', 'TRIAL');
        $environment = SerproEnvironment::tryFrom(strtoupper((string) $envRaw))
            ?? SerproEnvironment::Trial;

        try {
            return match ($mode) {
                'status' => $this->emit($smoke->status($environment)),
                'checklist' => $this->doChecklist($smoke),
                'tls' => $this->doTls($smoke, $environment),
                'oauth' => $this->doOauth($smoke, $environment),
                default => $this->invalid($mode),
            };
        } catch (Throwable $e) {
            $this->error(mb_substr($e->getMessage(), 0, 400));

            return self::FAILURE;
        }
    }

    private function doChecklist(SerproSmokeService $smoke): int
    {
        $payload = $smoke->cleanDeployChecklist();
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        } else {
            $this->info('Deploy limpo / contenção — checklist offline');
            $this->table(
                ['ok', 'id', 'detail'],
                array_map(fn (array $c) => [
                    $c['ok'] ? 'OK' : 'FAIL',
                    $c['id'],
                    $c['detail'],
                ], $payload['checks']),
            );
            $this->line($payload['ok'] ? 'CHECKLIST_OK' : 'CHECKLIST_ISSUES');
        }

        return $payload['ok'] ? self::SUCCESS : self::FAILURE;
    }

    private function doTls(SerproSmokeService $smoke, SerproEnvironment $environment): int
    {
        $confirm = $smoke->confirmMatches($this->option('confirm') ? (string) $this->option('confirm') : null);
        $result = $smoke->tlsHandshake(
            confirmLive: $confirm,
            recordReadiness: (bool) $this->option('record-readiness'),
            environment: $environment,
        );

        return $this->emit($result, $result['ok'] ?? false);
    }

    private function doOauth(SerproSmokeService $smoke, SerproEnvironment $environment): int
    {
        $confirm = $smoke->confirmMatches($this->option('confirm') ? (string) $this->option('confirm') : null);
        $contract = null;
        if ($this->option('contract-id')) {
            $contract = SerproContract::query()->find((int) $this->option('contract-id'));
            if ($contract === null) {
                $this->error('Contrato não encontrado.');

                return self::FAILURE;
            }
        }

        $result = $smoke->oauthMtls(
            confirmLive: $confirm,
            contract: $contract,
            environment: $environment,
            recordReadiness: (bool) $this->option('record-readiness'),
        );

        return $this->emit($result, $result['ok'] ?? false);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function emit(array $payload, ?bool $ok = null): int
    {
        $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $this->assertNoSecrets((string) $json);

        if ($this->option('json') || $ok !== null) {
            $this->line((string) $json);
        } else {
            $this->line((string) $json);
        }

        if ($ok === false) {
            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function invalid(string $mode): int
    {
        $this->error('Modo inválido: '.$mode.' (status|checklist|tls|oauth)');

        return self::FAILURE;
    }

    private function assertNoSecrets(string $payload): void
    {
        foreach (['BEGIN CERTIFICATE', 'consumer_secret', '-----BEGIN', 'access_token":"', '"access_token":'] as $needle) {
            if (str_contains($payload, $needle) && ! str_contains($payload, 'has_access_token')) {
                // allow has_access_token boolean keys
                if (in_array($needle, ['access_token":"', '"access_token":'], true)) {
                    $this->error('Saída bloqueada: possível token em texto.');
                    throw new \RuntimeException('Smoke recusou emitir possível segredo.');
                }
            }
            if (in_array($needle, ['BEGIN CERTIFICATE', 'consumer_secret', '-----BEGIN'], true)
                && str_contains($payload, $needle)
            ) {
                throw new \RuntimeException('Smoke recusou emitir possível segredo ('.$needle.').');
            }
        }
    }
}
