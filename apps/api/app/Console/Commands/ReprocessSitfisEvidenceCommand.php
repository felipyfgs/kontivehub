<?php

namespace App\Console\Commands;

use App\Services\FiscalMonitoring\SitfisSnapshotReprocessService;
use Illuminate\Console\Command;

final class ReprocessSitfisEvidenceCommand extends Command
{
    protected $signature = 'fiscal:reprocess-sitfis-evidence
        {--office= : Escritório obrigatório}
        {--client= : Cliente opcional do escritório}
        {--dry-run : Apenas relata mudanças (comportamento padrão)}
        {--apply : Cria snapshots sucessores e reconcilia projeções}';

    protected $description = 'Reprocessa PDFs SITFIS já armazenados localmente, sem consultar o SERPRO';

    public function handle(SitfisSnapshotReprocessService $service): int
    {
        $officeId = (int) $this->option('office');
        $clientId = $this->option('client') !== null ? (int) $this->option('client') : null;
        if ($officeId < 1 || ($clientId !== null && $clientId < 1)) {
            $this->error('Informe --office válido; --client, quando usado, também deve ser válido.');

            return self::FAILURE;
        }
        if ($this->option('dry-run') && $this->option('apply')) {
            $this->error('Use somente --dry-run ou --apply.');

            return self::FAILURE;
        }

        $apply = (bool) $this->option('apply');
        $result = $service->reprocess($officeId, $clientId, $apply);
        foreach ($result['rows'] as $row) {
            $this->line(sprintf(
                'snapshot=%d client=%d %s -> %s sections=%d changed=%s',
                $row['snapshot_id'], $row['client_id'], $row['from'] ?? 'UNKNOWN', $row['to'],
                count($row['sections']), $row['changed'] ? 'yes' : 'no',
            ));
        }
        $this->info(sprintf(
            'SITFIS local reprocess (%s): examined=%d changed=%d skipped=%d external_calls=0',
            $apply ? 'apply' : 'dry-run', $result['examined'], $result['changed'], $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
