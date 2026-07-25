<?php

namespace App\Console\Commands;

use App\Services\Serpro\SerproDemoInventoryService;
use Illuminate\Console\Command;

/**
 * Inventaria e opcionalmente segrega demo/shadow/fake sem apagar histórico.
 */
class SerproInventoryDemoCommand extends Command
{
    protected $signature = 'serpro:inventory-demo
        {--apply : Aplica segregation_class sem promover estados}
        {--json : Saída JSON}';

    protected $description = 'Inventário de Offices demo, tokens, ledger shadow e evidências (sem apagar trilha)';

    public function handle(SerproDemoInventoryService $inventory): int
    {
        $result = $inventory->inventory(applySegregation: (bool) $this->option('apply'));

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Offices: '.count($result['offices']));
        $this->table(
            ['id', 'slug', 'demo?', 'segregation'],
            collect($result['offices'])->map(fn (array $o) => [
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

        if ($result['actions_applied'] !== []) {
            $this->warn('Ações de segregação:');
            foreach ($result['actions_applied'] as $a) {
                $this->line(' - '.$a);
            }
        } else {
            $this->line('Nenhuma segregação aplicada (use --apply).');
        }

        return self::SUCCESS;
    }
}
