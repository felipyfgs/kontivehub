<?php

namespace App\Console\Commands;

use App\Enums\SerproEnvironment;
use App\Models\SerproContract;
use App\Services\Serpro\SerproCatalogService;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproHealthService;
use App\Services\Serpro\SerproKillSwitchService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * CLI protegido (console) para gerir contrato SERPRO global.
 * Nunca imprime PFX, senha, Consumer Secret, tokens ou Termo XML.
 */
class SerproContractManageCommand extends Command
{
    protected $signature = 'serpro:contract
        {action : list|show|register|activate|replace|trial-token-store|deactivate|block|health|catalog|kill-on|kill-off}
        {--id= : ID do contrato}
        {--serpro-env=TRIAL : Ambiente SERPRO TRIAL|PRODUCTION}
        {--pfx= : Caminho do arquivo PFX}
        {--password= : Senha do PFX (prefira prompt interativo)}
        {--consumer-key= : Consumer Key}
        {--consumer-secret= : Consumer Secret}
        {--trial-token-file= : Arquivo contendo o bearer do gateway Trial (nunca informe em argv)}
        {--name= : Nome do contratante}
        {--notes= : Notas operacionais}
        {--reason= : Motivo (block/deactivate/kill)}
        {--replace : Permitir substituir ACTIVE no activate}';

    protected $description = 'Gerencia contrato SERPRO global (metadados sanitizados; sem recuperação de segredo)';

    public function handle(
        SerproContractService $contracts,
        SerproHealthService $health,
        SerproCatalogService $catalog,
        SerproKillSwitchService $killSwitch,
    ): int {
        $action = strtolower((string) $this->argument('action'));

        try {
            return match ($action) {
                'list' => $this->doList($contracts),
                'show' => $this->doShow($contracts),
                'register' => $this->doRegister($contracts),
                'activate' => $this->doActivate($contracts),
                'replace' => $this->doReplace($contracts),
                'trial-token-store' => $this->doTrialTokenStore($contracts),
                'deactivate' => $this->doDeactivate($contracts),
                'block' => $this->doBlock($contracts),
                'health' => $this->doHealth($health),
                'catalog' => $this->doCatalog($catalog),
                'kill-on' => $this->doKill($killSwitch, true),
                'kill-off' => $this->doKill($killSwitch, false),
                default => $this->invalidAction($action),
            };
        } catch (Throwable $e) {
            $this->error($this->sanitize($e->getMessage()));

            return self::FAILURE;
        }
    }

    private function doList(SerproContractService $contracts): int
    {
        $env = $this->environment();
        $rows = $contracts->listSanitized($env);
        if ($rows === []) {
            $this->info('Nenhum contrato para '.$env->value);

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'env', 'status', 'cnpj', 'fingerprint', 'health', 'has_pfx', 'has_oauth'],
            array_map(fn (array $r) => [
                $r['id'],
                $r['environment'],
                $r['status'],
                $r['contractor_cnpj_masked'],
                substr((string) $r['fingerprint_sha256'], 0, 12).'…',
                $r['health_status'],
                $r['has_pfx'] ? 'yes' : 'no',
                $r['has_oauth'] ? 'yes' : 'no',
            ], $rows),
        );

        return self::SUCCESS;
    }

    private function doShow(SerproContractService $contracts): int
    {
        $contract = $this->resolveContract($contracts);
        $this->line(json_encode($contract->toSanitizedArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doRegister(SerproContractService $contracts): int
    {
        [$pfx, $password, $key, $secret] = $this->requireSecrets();
        $contract = $contracts->register(
            $this->environment(),
            $pfx,
            $password,
            $key,
            $secret,
            $this->option('name') ? (string) $this->option('name') : null,
            $this->option('notes') ? (string) $this->option('notes') : null,
        );
        $this->info('Contrato cadastrado id='.$contract->id.' status='.$contract->status->value);
        $this->line(json_encode($contract->toSanitizedArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doActivate(SerproContractService $contracts): int
    {
        $contract = $this->resolveContract($contracts);
        $replace = (bool) $this->option('replace');
        $contract = $contracts->activate($contract, $replace);
        $this->info('Contrato ativado id='.$contract->id);

        return self::SUCCESS;
    }

    private function doReplace(SerproContractService $contracts): int
    {
        [$pfx, $password, $key, $secret] = $this->requireSecrets();
        $contract = $contracts->replaceActive(
            $this->environment(),
            $pfx,
            $password,
            $key,
            $secret,
            $this->option('name') ? (string) $this->option('name') : null,
            $this->option('notes') ? (string) $this->option('notes') : null,
        );
        $this->info('Contrato substituído id='.$contract->id);
        $this->line(json_encode($contract->toSanitizedArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doDeactivate(SerproContractService $contracts): int
    {
        $contract = $this->resolveContract($contracts);
        $contracts->deactivate($contract, $this->option('reason') ? (string) $this->option('reason') : null);
        $this->info('Contrato desativado id='.$contract->id);

        return self::SUCCESS;
    }

    private function doTrialTokenStore(SerproContractService $contracts): int
    {
        $contract = $this->resolveContract($contracts);
        $path = (string) $this->option('trial-token-file');
        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException('Informe --trial-token-file= com arquivo legível.');
        }

        $token = trim((string) file_get_contents($path));
        $contracts->storeTrialGatewayBearer($contract, $token);
        unset($token);

        $this->info('Bearer Trial armazenado no cofre para o contrato id='.$contract->id.'.');

        return self::SUCCESS;
    }

    private function doBlock(SerproContractService $contracts): int
    {
        $contract = $this->resolveContract($contracts);
        $reason = (string) ($this->option('reason') ?: 'Bloqueio operacional');
        $contracts->block($contract, $reason);
        $this->info('Contrato bloqueado id='.$contract->id);

        return self::SUCCESS;
    }

    private function doHealth(SerproHealthService $health): int
    {
        $data = $health->globalHealth($this->environment());
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doCatalog(SerproCatalogService $catalog): int
    {
        $rows = $catalog->listForEnvironment($this->environment());
        $this->table(
            ['solution', 'service', 'operation', 'mutating', 'enabled', 'power', 'class'],
            array_map(fn (array $r) => [
                $r['solution_code'],
                $r['service_code'],
                $r['operation_code'],
                $r['is_mutating'] ? 'yes' : 'no',
                $r['is_enabled'] ? 'yes' : 'no',
                $r['required_proxy_power'] ?? '-',
                $r['billable_class'],
            ], $rows),
        );

        return self::SUCCESS;
    }

    private function doKill(SerproKillSwitchService $killSwitch, bool $on): int
    {
        $reason = (string) ($this->option('reason') ?: ($on ? 'manual_on' : 'manual_off'));
        if ($on) {
            $killSwitch->activateGlobal($reason);
            $this->warn('Kill switch SERPRO GLOBAL ATIVO');

            return self::SUCCESS;
        }

        // Desligar exige confirmação OWNER via API HTTP; CLI não fabrica aprovação.
        if (! app()->environment(['local', 'testing'])) {
            throw new RuntimeException(
                'kill-off via CLI exige confirmação OWNER (platform API / SerproRolloutApprovalService KILL_SWITCH_OFF). '.
                'Desativação imediata só é permitida em local/testing; produção usa frase + senha recente + janela.'
            );
        }

        $killSwitch->deactivateGlobal($reason);
        $this->info('Kill switch SERPRO global desativado (ambiente local/testing)');

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error('Ação inválida: '.$action);

        return self::FAILURE;
    }

    private function environment(): SerproEnvironment
    {
        $raw = strtoupper((string) $this->option('serpro-env'));

        return SerproEnvironment::from($raw);
    }

    private function resolveContract(SerproContractService $contracts): SerproContract
    {
        $id = $this->option('id');
        if ($id) {
            $contract = SerproContract::query()->find((int) $id);
            if ($contract === null) {
                throw new RuntimeException('Contrato não encontrado.');
            }

            return $contract;
        }

        $active = $contracts->activeFor($this->environment());
        if ($active === null) {
            throw new RuntimeException('Nenhum contrato ACTIVE; informe --id=');
        }

        return $active;
    }

    /**
     * @return array{0: string, 1: string, 2: string, 3: string}
     */
    private function requireSecrets(): array
    {
        $path = (string) $this->option('pfx');
        if ($path === '' || ! is_readable($path)) {
            throw new RuntimeException('Informe --pfx= com arquivo legível.');
        }
        $pfx = file_get_contents($path);
        if ($pfx === false) {
            throw new RuntimeException('Falha ao ler PFX.');
        }

        $password = (string) ($this->option('password') ?: $this->secret('Senha do PFX'));
        $key = (string) ($this->option('consumer-key') ?: $this->ask('Consumer Key'));
        $secret = (string) ($this->option('consumer-secret') ?: $this->secret('Consumer Secret'));

        return [$pfx, $password, $key, $secret];
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;

        return mb_substr($message, 0, 400);
    }
}
