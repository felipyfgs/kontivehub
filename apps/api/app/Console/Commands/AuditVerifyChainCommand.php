<?php

namespace App\Console\Commands;

use App\Services\Audit\AuditIntegrityService;
use Illuminate\Console\Command;

/**
 * Verifica integridade da auditoria append-only (hash encadeado).
 * Não imprime payload, token ou PII — só códigos e contagens.
 */
class AuditVerifyChainCommand extends Command
{
    protected $signature = 'audit:verify-chain
        {--json : Saída JSON sanitizada}
        {--alert : Emite alerta se houver quebra}';

    protected $description = 'Verifica cadeia de hash da auditoria (sem expor payload/PII)';

    public function handle(AuditIntegrityService $integrity): int
    {
        $result = $this->option('alert')
            ? $integrity->verifyAndAlert()
            : $integrity->verify();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            if ($result['ok']) {
                $this->info('AUDIT_CHAIN_OK checked='.$result['checked']);
            } else {
                $this->error(sprintf(
                    'AUDIT_CHAIN_BREAK reason=%s seq=%s checked=%d',
                    (string) $result['reason_code'],
                    (string) ($result['broken_at_seq'] ?? '?'),
                    (int) $result['checked'],
                ));
            }
        }

        return $result['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
