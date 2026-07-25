<?php

namespace App\Console\Commands;

use App\Models\InstanceBackupRun;
use App\Services\Backup\InstanceBackupService;
use Illuminate\Console\Command;

class OpsBackupRestoreDrillCommand extends Command
{
    protected $signature = 'ops:backup-restore-drill
        {--run=latest : latest ou id numérico do backup SUCCESS}';

    protected $description = 'Valida manifesto/checksums do artefato de backup (ensaio, sem master key)';

    public function handle(InstanceBackupService $backups): int
    {
        $option = (string) $this->option('run');
        $runId = null;
        if (strtolower($option) !== 'latest') {
            if (! ctype_digit($option)) {
                $this->error('Use --run=latest ou --run=<id>.');

                return self::FAILURE;
            }
            $runId = (int) $option;
        }

        $result = $backups->restoreDrill($runId);
        $run = $result['run'];

        if ($run->status === InstanceBackupRun::STATUS_SUCCESS) {
            $this->info(sprintf(
                'Restore drill #%d SUCCESS: %s',
                $run->id,
                $run->message ?? 'OK',
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf(
            'Restore drill #%d FAILED: %s',
            $run->id,
            $run->message ?? 'erro desconhecido',
        ));

        return self::FAILURE;
    }
}
