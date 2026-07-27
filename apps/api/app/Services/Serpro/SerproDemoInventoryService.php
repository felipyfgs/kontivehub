<?php

namespace App\Services\Serpro;

use App\Models\SerproApiUsageEntry;
use App\Models\SerproApiUsageReservation;
use App\Models\SerproContract;
use App\Models\TaxProxyPower;
use App\Models\Tenant;
use App\Models\TenantSerproAuthorization;

/**
 * Inventário de demo/shadow/fake sem alterar a trilha histórica.
 */
final class SerproDemoInventoryService
{
    /**
     * @return array{
     *   tenants: list<array<string, mixed>>,
     *   contracts: list<array<string, mixed>>,
     *   authorizations: list<array<string, mixed>>,
     *   ledger: array{reservations_shadow: int, entries_shadow: int, total_reservations: int, total_entries: int},
     *   powers: array{total: int, simulated_or_unverified: int},
     * }
     */
    public function inventory(): array
    {
        $demoSlug = strtolower((string) config('fiscal_demo.tenant_slug', 'demo'));

        $buildTenantRow = function (Tenant $o) use ($demoSlug): array {
            $slug = strtolower((string) $o->slug);
            $isDemo = $slug === $demoSlug || str_contains($slug, 'demo');
            $stored = $o->serpro_segregation_class;

            return [
                'id' => $o->id,
                'slug' => $o->slug,
                'name' => $o->name,
                'is_active' => (bool) $o->is_active,
                'inferred_demo' => $isDemo,
                'serpro_segregation_class' => $stored,
                'effective_segregation_class' => $stored,
            ];
        };

        $tenants = Tenant::query()->orderBy('id')->get()->map($buildTenantRow)->all();

        $contracts = SerproContract::query()->orderBy('id')->get()->map(function (SerproContract $c) {
            return [
                'id' => $c->id,
                'environment' => $c->environment->value ?? (string) $c->environment,
                'status' => $c->status->value ?? (string) $c->status,
                'segregation_class' => $c->segregation_class ?? null,
                'health_status' => $c->health_status,
            ];
        })->all();

        $authorizations = TenantSerproAuthorization::query()->orderBy('id')->get()->map(function (TenantSerproAuthorization $a) {
            return [
                'id' => $a->id,
                'tenant_id' => $a->tenant_id,
                'environment' => $a->environment,
                'status' => $a->status->value ?? (string) $a->status,
                'has_token' => $a->procurador_token_vault_object_id !== null,
                'has_termo' => $a->termo_vault_object_id !== null,
            ];
        })->all();

        $totalReservations = (int) SerproApiUsageReservation::query()->count();
        $reservationsShadow = (int) SerproApiUsageReservation::query()->where('shadow_mode', true)->count();
        $totalEntries = (int) SerproApiUsageEntry::query()->count();
        $entriesShadow = (int) SerproApiUsageEntry::query()->where('shadow_mode', true)->count();

        $powersTotal = (int) TaxProxyPower::query()->count();
        $powersSim = (int) TaxProxyPower::query()
            ->where(function ($q) {
                $q->where('source', 'like', '%SIMUL%')
                    ->orWhere('source', 'like', '%FAKE%')
                    ->orWhere('source', 'like', '%MANUAL%');
            })
            ->count();

        return [
            'tenants' => $tenants,
            'contracts' => $contracts,
            'authorizations' => $authorizations,
            'ledger' => [
                'reservations_shadow' => $reservationsShadow,
                'entries_shadow' => $entriesShadow,
                'total_reservations' => $totalReservations,
                'total_entries' => $totalEntries,
            ],
            'powers' => [
                'total' => $powersTotal,
                'simulated_or_unverified' => $powersSim,
            ],
        ];
    }
}
