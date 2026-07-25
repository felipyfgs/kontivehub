<?php

namespace App\Console\Commands;

use App\Services\Serpro\SerproLifecycleMonitor;
use Illuminate\Console\Command;

/**
 * Scan read-only de expiração (PFX, A1, Termo, token, procurações).
 * Não assina, não renova procuração e não executa mutação fiscal.
 */
class SerproLifecycleScanCommand extends Command
{
    protected $signature = 'serpro:lifecycle-scan';

    protected $description = 'Varre certificados/Termo/token/procurações e emite alertas de vencimento (sem mutar)';

    public function handle(SerproLifecycleMonitor $monitor): int
    {
        $result = $monitor->scan();

        if (! $result['lock_acquired']) {
            $this->warn('Lock serpro.lifecycle.scan ocupado — scan ignorado.');

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Lifecycle scan: contracts=%d authorizations=%d powers=%d alerts=%d',
            $result['scanned']['contracts'] ?? 0,
            $result['scanned']['authorizations'] ?? 0,
            $result['scanned']['proxy_powers'] ?? 0,
            count($result['alerts']),
        ));

        foreach ($result['alerts'] as $alert) {
            $this->line(sprintf(
                '  [%s] %s subject=%s office=%s days_left=%s',
                $alert['severity'],
                $alert['kind'],
                $alert['subject_id'],
                $alert['office_id'] ?? '—',
                $alert['days_left'],
            ));
        }

        return self::SUCCESS;
    }
}
