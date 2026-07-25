<?php

namespace App\Console\Commands;

use App\Models\InstanceBackupRun;
use App\Services\Backup\InstanceBackupService;
use Illuminate\Console\Command;

class OpsBackupRunCommand extends Command
{
    protected $signature = 'ops:backup-run
        {--kind=full : full|database|vault}';

    protected $description = 'Executa backup da instância (PostgreSQL e/ou cofre cifrado) e grava metadados';

    public function handle(InstanceBackupService $backups): int
    {
        $kind = strtolower((string) $this->option('kind'));
        if (! in_array($kind, [
            InstanceBackupRun::KIND_FULL,
            InstanceBackupRun::KIND_DATABASE,
            InstanceBackupRun::KIND_VAULT,
        ], true)) {
            $this->error('Kind inválido. Use full, database ou vault.');

            return self::FAILURE;
        }

        $result = $backups->run($kind);
        $run = $result['run'];

        if (! $result['acquired']) {
            $this->error('Backup já em andamento (concorrência rejeitada).');

            return self::FAILURE;
        }

        if ($run->status === InstanceBackupRun::STATUS_SUCCESS) {
            $this->info(sprintf(
                'Backup #%d SUCCESS kind=%s bytes=%s checksum=%s',
                $run->id,
                $run->kind,
                $run->byte_size ?? 'n/a',
                $run->checksum ?? 'n/a',
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Backup #%d FAILED kind=%s: %s',
            $run->id,
            $run->kind,
            $run->message ?? 'erro desconhecido',
        ));

        return self::FAILURE;
    }
}
