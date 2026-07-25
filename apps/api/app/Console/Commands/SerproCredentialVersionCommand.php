<?php

namespace App\Console\Commands;

use App\Enums\SerproEnvironment;
use App\Models\SerproContract;
use App\Models\SerproCredentialVersion;
use App\Services\Serpro\SerproCredentialVersionService;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * CLI de versões de credencial SERPRO.
 * NUNCA aceita Consumer Secret/senha PFX por argumento de linha de comando
 * (somente arquivo + prompt interativo / env de caminho).
 * NUNCA reexibe segredos — só metadados sanitizados.
 */
class SerproCredentialVersionCommand extends Command
{
    protected $signature = 'serpro:credential-version
        {action : list|show|register-pending|verify|approve-cutover|cutover|retire|compromise}
        {--id= : ID da versão}
        {--contract-id= : ID do contrato}
        {--serpro-env=TRIAL : Ambiente SERPRO}
        {--pfx-file= : Caminho do arquivo PFX (não o conteúdo)}
        {--consumer-key-file= : Arquivo com Consumer Key (uma linha)}
        {--consumer-secret-file= : Arquivo com Consumer Secret (uma linha)}
        {--approver-user-id= : ID do proprietário PLATFORM_ADMIN (consumo)}
        {--approval-id= : ID de confirmação OWNER HTTP vigente (cutover)}
        {--reason= : Motivo (retire/compromise)}
        {--notes= : Notas}
        {--skip-oauth : Apenas em local/testing}';

    protected $description = 'Versões de credencial SERPRO (PENDING/VERIFIED/cutover) sem segredo em argv/resposta';

    public function handle(SerproCredentialVersionService $versions): int
    {
        $action = strtolower((string) $this->argument('action'));

        try {
            return match ($action) {
                'list' => $this->doList(),
                'show' => $this->doShow(),
                'register-pending' => $this->doRegisterPending($versions),
                'verify' => $this->doVerify($versions),
                'approve-cutover' => $this->doApproveCutover($versions),
                'cutover' => $this->doCutover($versions),
                'retire' => $this->doRetire($versions),
                'compromise' => $this->doCompromise($versions),
                default => $this->invalid($action),
            };
        } catch (Throwable $e) {
            $this->error($this->sanitize($e->getMessage()));

            return self::FAILURE;
        }
    }

    private function doList(): int
    {
        $env = $this->environment();
        $rows = SerproCredentialVersion::query()
            ->where('environment', $env->value)
            ->orderByDesc('version_number')
            ->get()
            ->map(fn (SerproCredentialVersion $v) => $v->toSanitizedArray())
            ->all();

        if ($rows === []) {
            $this->info('Nenhuma versão para '.$env->value);

            return self::SUCCESS;
        }

        $this->table(
            ['id', 'ver', 'status', 'exposed', 'fingerprint', 'has_pfx', 'has_oauth'],
            array_map(fn (array $r) => [
                $r['id'],
                $r['version_number'],
                $r['status'],
                $r['was_exposed'] ? 'yes' : 'no',
                substr((string) $r['fingerprint_sha256'], 0, 12).'…',
                $r['has_pfx'] ? 'yes' : 'no',
                $r['has_oauth'] ? 'yes' : 'no',
            ], $rows),
        );

        return self::SUCCESS;
    }

    private function doShow(): int
    {
        $version = $this->resolveVersion();
        $payload = $version->toSanitizedArray();
        $this->assertNoSecrets($payload);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doRegisterPending(SerproCredentialVersionService $versions): int
    {
        // Segredos NUNCA via --password / --consumer-secret argv.
        if ($this->optionWasPassedAsSecretArg()) {
            throw new RuntimeException(
                'Recusado: não informe segredos por argumento CLI. Use --pfx-file, --consumer-key-file, --consumer-secret-file e prompt de senha.'
            );
        }

        $pfxPath = (string) $this->option('pfx-file');
        if ($pfxPath === '' || ! is_readable($pfxPath)) {
            throw new RuntimeException('Informe --pfx-file= com arquivo legível.');
        }

        $pfx = file_get_contents($pfxPath);
        if ($pfx === false || $pfx === '') {
            throw new RuntimeException('Falha ao ler PFX.');
        }

        $password = (string) $this->secret('Senha do PFX');
        $key = $this->readSecretFile('consumer-key-file', 'Consumer Key');
        $secret = $this->readSecretFile('consumer-secret-file', 'Consumer Secret', hidden: true);

        $contract = null;
        if ($this->option('contract-id')) {
            $contract = SerproContract::query()->find((int) $this->option('contract-id'));
            if ($contract === null) {
                throw new RuntimeException('Contrato não encontrado.');
            }
        }

        $version = $versions->registerPending(
            $this->environment(),
            $pfx,
            $password,
            $key,
            $secret,
            $contract,
            $this->option('notes') ? (string) $this->option('notes') : null,
        );

        unset($pfx, $password, $key, $secret);

        $payload = $version->toSanitizedArray();
        $this->assertNoSecrets($payload);
        $this->info('Versão PENDING id='.$version->id.' ver='.$version->version_number);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doVerify(SerproCredentialVersionService $versions): int
    {
        $version = $versions->verifyPending($this->resolveVersion());
        $payload = $version->toSanitizedArray();
        $this->assertNoSecrets($payload);
        $this->info('Versão VERIFIED id='.$version->id);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doApproveCutover(SerproCredentialVersionService $versions): int
    {
        unset($versions);
        // CLI NÃO fabrica confirmação humana (OWNER_CONFIRMATION).
        throw new RuntimeException(
            'approve-cutover via CLI é bloqueado: confirmação do proprietário só via API HTTP '.
            '(POST /platform/serpro/rollouts + approve com senha recente, frase, motivo e janela). '.
            'Para executar cutover técnico, use action=cutover com --approval-id= da aprovação HTTP vigente.'
        );
    }

    private function doCutover(SerproCredentialVersionService $versions): int
    {
        $version = $this->resolveVersion();
        $contract = null;
        if ($this->option('contract-id')) {
            $contract = SerproContract::query()->find((int) $this->option('contract-id'));
        } elseif ($version->serpro_contract_id) {
            $contract = SerproContract::query()->find($version->serpro_contract_id);
        }

        $approvalId = $this->option('approval-id') ? (int) $this->option('approval-id') : null;
        if ($approvalId === null || $approvalId <= 0) {
            throw new RuntimeException(
                'Cutover via CLI exige --approval-id= de confirmação OWNER HTTP persistida e vigente; CLI não fabrica aprovação.'
            );
        }

        $actorId = $this->option('approver-user-id') ? (int) $this->option('approver-user-id') : null;
        if ($actorId === null || $actorId <= 0) {
            throw new RuntimeException('Informe --approver-user-id= do proprietário que confirmou a aprovação.');
        }

        $version = $versions->cutover(
            $version,
            $contract,
            actorUserId: $actorId,
            skipOauth: (bool) $this->option('skip-oauth'),
            approvalId: $approvalId,
        );

        $payload = $version->toSanitizedArray();
        $this->assertNoSecrets($payload);
        $this->info('Cutover ACTIVE id='.$version->id.' ver='.$version->version_number);
        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }

    private function doRetire(SerproCredentialVersionService $versions): int
    {
        $reason = (string) ($this->option('reason') ?: 'retired via CLI');
        $version = $versions->markRetired($this->resolveVersion(), $reason);
        $this->info('Versão RETIRED id='.$version->id);

        return self::SUCCESS;
    }

    private function doCompromise(SerproCredentialVersionService $versions): int
    {
        $reason = (string) ($this->option('reason') ?: 'compromised via CLI');
        $version = $versions->markCompromised($this->resolveVersion(), $reason);
        $this->info('Versão COMPROMISED id='.$version->id);

        return self::SUCCESS;
    }

    private function resolveVersion(): SerproCredentialVersion
    {
        $id = $this->option('id');
        if (! $id) {
            throw new RuntimeException('Informe --id= da versão.');
        }
        $version = SerproCredentialVersion::query()->find((int) $id);
        if ($version === null) {
            throw new RuntimeException('Versão não encontrada.');
        }

        return $version;
    }

    private function environment(): SerproEnvironment
    {
        return SerproEnvironment::from(strtoupper((string) $this->option('serpro-env')));
    }

    private function readSecretFile(string $option, string $label, bool $hidden = false): string
    {
        $path = (string) $this->option($option);
        if ($path !== '') {
            if (! is_readable($path)) {
                throw new RuntimeException("Arquivo de {$label} ilegível.");
            }
            $value = trim((string) file_get_contents($path));
            if ($value === '') {
                throw new RuntimeException("Arquivo de {$label} vazio.");
            }

            return $value;
        }

        $value = $hidden
            ? (string) $this->secret($label)
            : (string) $this->ask($label);

        if (trim($value) === '') {
            throw new RuntimeException("{$label} obrigatório.");
        }

        return trim($value);
    }

    /**
     * Detecta tentativas de passar segredo por flags legadas/argv.
     */
    private function optionWasPassedAsSecretArg(): bool
    {
        foreach ($_SERVER['argv'] ?? [] as $arg) {
            if (! is_string($arg)) {
                continue;
            }
            if (preg_match('/^--(password|consumer-secret|consumer-key|secret)=/i', $arg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function assertNoSecrets(array $payload): void
    {
        $encoded = json_encode($payload) ?: '';
        foreach (['pfx_vault_object_id', 'oauth_vault_object_id', 'token_vault_object_id', 'BEGIN ', 'PRIVATE KEY'] as $needle) {
            if (str_contains($encoded, $needle)) {
                throw new RuntimeException('Resposta sanitizada continha material proibido.');
            }
        }
    }

    private function invalid(string $action): int
    {
        $this->error('Ação inválida: '.$action);

        return self::FAILURE;
    }

    private function sanitize(string $message): string
    {
        $message = preg_replace('/Bearer\s+\S+/i', 'Bearer [redacted]', $message) ?? $message;

        return mb_substr($message, 0, 400);
    }
}
