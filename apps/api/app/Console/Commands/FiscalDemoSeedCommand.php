<?php

namespace App\Console\Commands;

use App\Models\Client;
use App\Models\FiscalMonitoringRun;
use App\Models\FiscalSnapshot;
use App\Models\MailboxMessage;
use App\Models\Office;
use App\Models\TaxGuide;
use App\Models\TaxInstallmentOrder;
use App\Models\TaxObligationProjection;
use App\Services\Fiscal\Demo\DemoEnvironmentGuard;
use Database\Seeders\FiscalMonitoringDemoSeeder;
use Illuminate\Console\Command;
use LogicException;
use Throwable;

/**
 * Recria o dataset fiscal demonstrativo de forma idempotente.
 * Imprime apenas contagens sanitizadas (sem vault IDs / segredos).
 */
class FiscalDemoSeedCommand extends Command
{
    protected $signature = 'fiscal:demo-seed
                            {--force : Confirma recriação mesmo se já houver fixtures}';

    protected $description = 'Recria fixtures fiscais do office demo (local/testing only)';

    public function handle(DemoEnvironmentGuard $guard): int
    {
        try {
            $office = $guard->assertCanSeed();
        } catch (LogicException|Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Recriando fixtures fiscais demo (office=%s env=%s version=%s anchor=%s)…',
            $office->slug,
            app()->environment(),
            $guard->manifestVersion(),
            (string) config('fiscal_demo.anchor_at'),
        ));

        try {
            $this->callSilent('db:seed', [
                '--class' => FiscalMonitoringDemoSeeder::class,
                '--force' => true,
            ]);
        } catch (Throwable $e) {
            $this->error('Falha ao seedar: '.$e->getMessage());

            return self::FAILURE;
        }

        $marker = $guard->fixtureMarker();
        $counts = [
            'office' => $office->slug,
            'manifest_version' => $guard->manifestVersion(),
            'clients' => Client::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('notes', 'like', '%'.$marker.'%')
                ->count(),
            'runs' => FiscalMonitoringRun::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('correlation_id', 'like', $guard->correlationPrefix().'%')
                ->count(),
            'snapshots' => FiscalSnapshot::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->count(),
            'mailbox_messages' => MailboxMessage::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('external_id', 'like', $guard->correlationPrefix().'%')
                ->count(),
            'guides' => TaxGuide::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('logical_key', 'like', 'demo.guide.%')
                ->count(),
            'installment_orders' => TaxInstallmentOrder::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('external_order_id', 'like', 'DEMO-ORD-%')
                ->count(),
            'declarations' => TaxObligationProjection::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->count(),
            'sentinel_office' => Office::query()
                ->where('slug', $guard->sentinelOfficeSlug())
                ->exists() ? 1 : 0,
        ];

        $this->table(['métrica', 'valor'], collect($counts)->map(fn ($v, $k) => [$k, $v])->values()->all());
        $this->info('Concluído. Dados marcados como demonstrativos — sem validade fiscal.');

        return self::SUCCESS;
    }
}
