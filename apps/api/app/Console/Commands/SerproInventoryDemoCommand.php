<?php

namespace App\Console\Commands;

use App\Services\Serpro\SerproDemoInventoryService;
use Illuminate\Console\Command;

/**
 * Inventaria demo/shadow/fake sem alterar o histórico.
 */
class SerproInventoryDemoCommand extends Command
{
    protected $signature = 'serpro:inventory-demo
        {--json : Saída JSON}';

    protected $description = 'Inventário de Tenants demo, tokens, ledger shadow e evidências (sem apagar trilha)';

    public function handle(SerproDemoInventoryService $inventory): int
    {
        $result = $inventory->inventory();

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Tenants: '.count($result['tenants']));
        $this->table(
            ['id', 'slug', 'demo?', 'segregation'],
            collect($result['tenants'])->map(fn (array $o) => [
                $o['id'],
                $o['slug'],
                $o['inferred_demo'] ? 'yes' : 'no',
                $o['serpro_segregation_class'] ?? '—',
            ])->all()
        );

        $this->info('Contracts: '.count($result['contracts']));
        $this->info(sprintf(
            'Ledger: reservations=%d (shadow=%d) entries=%d (shadow=%d)',
            $result['ledger']['total_reservations'],
            $result['ledger']['reservations_shadow'],
            $result['ledger']['total_entries'],
            $result['ledger']['entries_shadow'],
        ));
        $this->info(sprintf(
            'Powers: total=%d simulated/manual-ish=%d',
            $result['powers']['total'],
            $result['powers']['simulated_or_unverified'],
        ));

        return self::SUCCESS;
    }
}
