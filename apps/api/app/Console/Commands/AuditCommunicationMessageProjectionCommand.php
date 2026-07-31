<?php

namespace App\Console\Commands;

use App\DTO\Communication\MaintenanceContext;
use App\Services\Communication\Maintenance\MessageProjectionMaintenance;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class AuditCommunicationMessageProjectionCommand extends Command
{
    protected $signature = 'communication:audit-message-projection
        {--tenant= : ID confiável do tenant}
        {--inbox= : ID confiável da inbox}
        {--operation= : Identificador opaco da operação}
        {--actor= : ID do platform admin exigido em execução}
        {--reverse= : Operação de quarentena a reverter}
        {--execute : Aplica a quarentena ou reversão; ausente mantém dry-run}';

    protected $description = 'Audita e, com autorização explícita, quarentena projeções técnicas do WhatsApp';

    public function handle(MessageProjectionMaintenance $maintenance): int
    {
        try {
            $context = new MaintenanceContext(
                tenantId: (int) $this->option('tenant'),
                inboxId: (int) $this->option('inbox'),
                operationId: (string) ($this->option('operation') ?: 'projection-'.strtolower((string) Str::ulid())),
                execute: (bool) $this->option('execute'),
                actorId: $this->option('actor') !== null ? (int) $this->option('actor') : null,
            );
            $result = $maintenance->run(
                $context,
                is_string($this->option('reverse')) && $this->option('reverse') !== ''
                    ? (string) $this->option('reverse')
                    : null,
            );
            $this->line(json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (Throwable $error) {
            $this->line(json_encode([
                'status' => 'error',
                'code' => preg_match('/^[A-Z][A-Z0-9_]{2,79}$/', $error->getMessage()) === 1
                    ? $error->getMessage()
                    : 'MAINTENANCE_CONTEXT_INVALID',
            ], JSON_THROW_ON_ERROR));

            return self::FAILURE;
        }
    }
}
