<?php

namespace App\Services\FiscalMonitoring;

use App\Enums\FiscalCoverage;
use App\Enums\FiscalLinkStatus;
use App\Enums\FiscalTrigger;
use App\Models\Client;
use App\Models\FiscalCategory;
use App\Models\FiscalMonitoringSchedule;
use App\Models\Tenant;
use App\Models\TenantFiscalCategoryLink;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class FiscalCategoryService
{
    public function __construct(
        private readonly FiscalMonitoringScheduler $scheduler,
    ) {}

    /**
     * @return Collection<int, FiscalCategory>
     */
    public function listCategories(bool $activeOnly = true): Collection
    {
        $q = FiscalCategory::query()->orderBy('sort_order')->orderBy('code');
        if ($activeOnly) {
            $q->where('is_active', true);
        }

        return $q->get();
    }

    /**
     * @return Collection<int, TenantFiscalCategoryLink>
     */
    public function listLinks(Tenant $tenant, ?int $clientId = null, ?string $status = null): Collection
    {
        $q = TenantFiscalCategoryLink::query()
            ->withoutGlobalScopes()
            ->with('category')
            ->where('tenant_id', $tenant->id)
            ->orderByDesc('id');

        if ($clientId !== null) {
            $q->where('client_id', $clientId);
        }
        if ($status !== null) {
            $q->where('status', $status);
        }

        return $q->get();
    }

    /**
     * Associa um cliente a uma categoria no tenant (sem auto-associar não comprovada).
     */
    public function associate(
        Tenant $tenant,
        Client $client,
        FiscalCategory $category,
        ?int $actorId = null,
        ?FiscalCoverage $coverage = null,
        FiscalLinkStatus $status = FiscalLinkStatus::Active,
        ?string $notes = null,
        bool $createSchedule = true,
    ): TenantFiscalCategoryLink {
        if ((int) $client->tenant_id !== (int) $tenant->id) {
            throw new RuntimeException('Cliente não pertence ao escritório ativo.');
        }
        if (! $category->is_active) {
            throw new RuntimeException('Categoria fiscal inativa.');
        }

        return DB::transaction(function () use ($tenant, $client, $category, $actorId, $coverage, $status, $notes, $createSchedule) {
            $link = TenantFiscalCategoryLink::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('client_id', $client->id)
                ->where('fiscal_category_id', $category->id)
                ->lockForUpdate()
                ->first();

            $now = CarbonImmutable::now();
            $resolvedCoverage = $coverage ?? $category->default_coverage ?? FiscalCoverage::Unknown;

            if ($link === null) {
                $link = TenantFiscalCategoryLink::query()->create([
                    'tenant_id' => $tenant->id,
                    'client_id' => $client->id,
                    'fiscal_category_id' => $category->id,
                    'status' => $status,
                    'coverage' => $resolvedCoverage,
                    'activated_at' => $status === FiscalLinkStatus::Active ? $now : null,
                    'deactivated_at' => null,
                    'notes' => $notes,
                    'created_by' => $actorId,
                ]);
            } else {
                $link->forceFill([
                    'status' => $status,
                    'coverage' => $resolvedCoverage,
                    'activated_at' => $status === FiscalLinkStatus::Active
                        ? ($link->activated_at ?? $now)
                        : $link->activated_at,
                    'deactivated_at' => $status === FiscalLinkStatus::Inactive ? $now : null,
                    'notes' => $notes ?? $link->notes,
                ])->save();
            }

            if ($createSchedule && $status === FiscalLinkStatus::Active) {
                $this->ensureSchedule($tenant, $client, $category, $link);
            }

            return $link->fresh(['category']);
        });
    }

    /**
     * Associação em lote. Falhas parciais não abortam o lote inteiro.
     *
     * @param  list<int>  $clientIds
     * @return array{created:int,updated:int,errors:list<array{client_id:int,message:string}>}
     */
    public function associateBatch(
        Tenant $tenant,
        FiscalCategory $category,
        array $clientIds,
        ?int $actorId = null,
        ?FiscalCoverage $coverage = null,
    ): array {
        $created = 0;
        $updated = 0;
        $errors = [];

        foreach (array_unique(array_map('intval', $clientIds)) as $clientId) {
            try {
                $client = Client::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->whereKey($clientId)
                    ->first();
                if ($client === null) {
                    $errors[] = ['client_id' => $clientId, 'message' => 'Cliente não encontrado no tenant.'];

                    continue;
                }

                $exists = TenantFiscalCategoryLink::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenant->id)
                    ->where('client_id', $clientId)
                    ->where('fiscal_category_id', $category->id)
                    ->exists();

                $this->associate($tenant, $client, $category, $actorId, $coverage);
                if ($exists) {
                    $updated++;
                } else {
                    $created++;
                }
            } catch (\Throwable $e) {
                $errors[] = ['client_id' => $clientId, 'message' => $e->getMessage()];
            }
        }

        return compact('created', 'updated', 'errors');
    }

    public function ensureSchedule(
        Tenant $tenant,
        Client $client,
        FiscalCategory $category,
        TenantFiscalCategoryLink $link,
    ): FiscalMonitoringSchedule {
        $system = (string) ($category->system_code ?? 'UNKNOWN');
        $service = (string) ($category->service_code ?? 'UNKNOWN');
        $operation = 'MONITOR';
        $interval = strtoupper($service) === 'SITFIS'
            ? (int) config('fiscal_monitoring.sitfis.interval_minutes', 1440)
            : (int) config('fiscal_monitoring.scheduler.default_interval_minutes', 60);
        $preferred = $this->scheduler->preferredMinute($tenant->id, $client->id, $system, $service);

        $schedule = FiscalMonitoringSchedule::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('client_id', $client->id)
            ->where('system_code', $system)
            ->where('service_code', $service)
            ->where('operation_code', $operation)
            ->first();

        if ($schedule !== null) {
            $schedule->forceFill([
                'fiscal_category_id' => $category->id,
                'category_link_id' => $link->id,
                'is_enabled' => true,
                'preferred_minute' => $preferred,
                'interval_minutes' => $interval,
            ])->save();

            return $schedule;
        }

        $next = $this->scheduler->firstRunAt($preferred);

        return FiscalMonitoringSchedule::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'fiscal_category_id' => $category->id,
            'category_link_id' => $link->id,
            'system_code' => $system,
            'service_code' => $service,
            'operation_code' => $operation,
            'is_enabled' => true,
            'interval_minutes' => $interval,
            'preferred_minute' => $preferred,
            'next_run_at' => $next,
            'metadata' => ['seeded_by' => 'category_link', 'trigger' => FiscalTrigger::Scheduled->value],
        ]);
    }
}
