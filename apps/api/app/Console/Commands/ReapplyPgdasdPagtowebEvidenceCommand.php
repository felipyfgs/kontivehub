<?php

namespace App\Console\Commands;

use App\Services\Fiscal\SimplesMei\Pgdasd\PgdasdPagtowebEvidenceReapplyService;
use Illuminate\Console\Command;

/**
 * Reaplica evidência PAGTOWEB local com digest canônico (sem live SERPRO).
 *
 * Uso: php artisan fiscal:reapply-pgdasd-pagtoweb-evidence [--office=] [--client=]
 */
final class ReapplyPgdasdPagtowebEvidenceCommand extends Command
{
    protected $signature = 'fiscal:reapply-pgdasd-pagtoweb-evidence
        {--office= : Limita ao escritório}
        {--client= : Limita ao cliente (exige --office)}';

    protected $description = 'Reaplica evidência PAGTOWEB já persistida em DAS PGDAS-D (digest canônico; sem SERPRO)';

    public function handle(PgdasdPagtowebEvidenceReapplyService $reapply): int
    {
        $officeId = $this->option('office') !== null ? max(1, (int) $this->option('office')) : null;
        $clientId = $this->option('client') !== null ? max(1, (int) $this->option('client')) : null;
        if ($clientId !== null && $officeId === null) {
            $this->error('Use --office junto com --client.');

            return self::FAILURE;
        }

        $result = $reapply->reapply($officeId, $clientId);
        $this->info(sprintf(
            'PGDASD/PAGTOWEB reapply: observations=%d paid=%d not_found=%d skipped=%d',
            $result['observations'],
            $result['paid'],
            $result['not_found'],
            $result['skipped'],
        ));

        return self::SUCCESS;
    }
}
