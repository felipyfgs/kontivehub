<?php

namespace App\Console\Commands;

use App\DTO\Communication\CommunicationMaintenanceContext;
use App\Services\Communication\Maintenance\CommunicationHistoryMediaRescue;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

final class RescueCommunicationHistoryMediaCommand extends Command
{
    protected $signature = 'communication:rescue-history-media
        {--tenant= : ID confiável do tenant}
        {--inbox= : ID confiável da inbox}
        {--limit=25 : Quantidade solicitada, limitada pela configuração}
        {--operation= : Identificador opaco da operação}
        {--actor= : ID do platform admin exigido em execução}
        {--execute : Enfileira retries; ausente mantém dry-run}';

    protected $description = 'Inventaria e, com autorização explícita, solicita mídia histórica recuperável';

    public function handle(CommunicationHistoryMediaRescue $rescue): int
    {
        try {
            $context = new CommunicationMaintenanceContext(
                tenantId: (int) $this->option('tenant'),
                inboxId: (int) $this->option('inbox'),
                operationId: (string) ($this->option('operation') ?: 'media-rescue-'.strtolower((string) Str::ulid())),
                execute: (bool) $this->option('execute'),
                actorId: $this->option('actor') !== null ? (int) $this->option('actor') : null,
            );
            $result = $rescue->run($context, (int) $this->option('limit'));
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
