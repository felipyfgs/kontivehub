<?php

namespace App\Services\Integra;

use App\Models\Client;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/** Despacha somente candidatos vencidos de verificação, sem chamar a SERPRO. */
final class ClientProcuracaoAutoSyncDispatcher
{
    public function __construct(
        private readonly ClientProcuracaoAutoSyncPolicy $policy,
        private readonly ClientProcuracaoSyncService $sync,
    ) {}

    /** @return array{lock_acquired: bool, dispatched: int, skipped: array<string, int>} */
    public function dispatchDue(): array
    {
        $lock = Cache::lock('serpro:procuracao:auto-dispatch', 55);
        if (! $lock->get()) {
            return ['lock_acquired' => false, 'dispatched' => 0, 'skipped' => ['LOCK_BUSY' => 1]];
        }

        try {
            $environment = $this->policy->configuredEnvironment();
            if ($environment === null) {
                return ['lock_acquired' => true, 'dispatched' => 0, 'skipped' => ['INVALID_ENVIRONMENT' => 1]];
            }

            $limit = (int) config('serpro.procuracoes_scheduler.batch_size', 20);
            $threshold = CarbonImmutable::now()->subHours((int) config('serpro.procuracoes_scheduler.max_age_hours', 168));
            $dispatched = 0;
            $skipped = [];

            Client::query()
                ->with('tenant')
                ->where(fn ($query) => $query
                    ->whereDoesntHave(
                        'procuracaoSyncs',
                        fn ($sync) => $sync->where('environment', $environment->value),
                    )
                    ->orWhereHas('procuracaoSyncs', fn ($sync) => $sync
                        ->where('environment', $environment->value)
                        ->where(fn ($stale) => $stale
                            ->whereNull('last_verified_at')
                            ->orWhere('last_verified_at', '<=', $threshold))))
                ->orderBy('id')
                ->cursor()
                ->each(function (Client $client) use ($environment, $limit, &$dispatched, &$skipped): bool {
                    if ($dispatched >= $limit) {
                        return false;
                    }

                    /** @var Tenant $tenant */
                    $tenant = $client->tenant;
                    $decision = $this->policy->check($tenant, $environment);
                    if (! $decision['allowed']) {
                        $skipped[$decision['code']] = ($skipped[$decision['code']] ?? 0) + 1;

                        return true;
                    }

                    $this->sync->enqueueSync($tenant, $client, $environment, automatic: true);
                    $dispatched++;

                    return true;
                });

            return ['lock_acquired' => true, 'dispatched' => $dispatched, 'skipped' => $skipped];
        } finally {
            $lock->release();
        }
    }
}
