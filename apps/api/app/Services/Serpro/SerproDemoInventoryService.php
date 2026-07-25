<?php

namespace App\Services\Serpro;

use App\Enums\SerproDataSegregationClass;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproApiUsageReservation;
use App\Models\SerproContract;
use App\Models\TaxProxyPower;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Inventário e segregação de demo/shadow/fake sem apagar trilha histórica
 * e sem promover registros por inferência.
 */
final class SerproDemoInventoryService
{
    /**
     * @return array{
     *   offices: list<array<string, mixed>>,
     *   contracts: list<array<string, mixed>>,
     *   authorizations: list<array<string, mixed>>,
     *   ledger: array{reservations_shadow: int, entries_shadow: int, total_reservations: int, total_entries: int},
     *   powers: array{total: int, simulated_or_unverified: int},
     *   actions_applied: list<string>
     * }
     */
    public function inventory(bool $applySegregation = false): array
    {
        $actions = [];
        $demoSlug = strtolower((string) config('fiscal_demo.office_slug', 'demo'));

        $buildOfficeRow = function (Office $o) use ($demoSlug): array {
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
                'effective_segregation_class' => $stored
                    ?? ($isDemo ? SerproDataSegregationClass::Demo->value : null),
            ];
        };

        if ($applySegregation) {
            foreach (Office::query()->orderBy('id')->get() as $office) {
                $slug = strtolower((string) $office->slug);
                $isDemo = $slug === $demoSlug || str_contains($slug, 'demo');
                if ($isDemo && ($office->serpro_segregation_class === null || $office->serpro_segregation_class === '')) {
                    $office->forceFill([
                        'serpro_segregation_class' => SerproDataSegregationClass::Demo->value,
                    ])->save();
                    $actions[] = "office:{$office->id}:segregation=DEMO";
                }
            }
        }

        $offices = Office::query()->orderBy('id')->get()->map($buildOfficeRow)->all();

        $contracts = SerproContract::query()->orderBy('id')->get()->map(function (SerproContract $c) {
            return [
                'id' => $c->id,
                'environment' => $c->environment->value ?? (string) $c->environment,
                'status' => $c->status->value ?? (string) $c->status,
                'credentials_exposed' => (bool) ($c->credentials_exposed ?? false),
                'segregation_class' => $c->segregation_class ?? null,
                'health_status' => $c->health_status,
            ];
        })->all();

        if ($applySegregation) {
            foreach (SerproContract::query()->whereNull('segregation_class')->orWhere('segregation_class', '')->get() as $c) {
                $c->forceFill([
                    'segregation_class' => SerproDataSegregationClass::HistoricalUnverified->value,
                    'credentials_exposed' => $c->credentials_exposed ?? true,
                ])->save();
                $actions[] = "contract:{$c->id}:segregation=HISTORICAL_UNVERIFIED";
            }
        }

        $authorizations = OfficeSerproAuthorization::query()->orderBy('id')->get()->map(function (OfficeSerproAuthorization $a) {
            return [
                'id' => $a->id,
                'office_id' => $a->office_id,
                'environment' => $a->environment,
                'status' => $a->status->value ?? (string) $a->status,
                'has_token' => $a->procurador_token_vault_object_id !== null,
                'has_termo' => $a->termo_vault_object_id !== null,
            ];
        })->all();

        $reservationsShadow = 0;
        $entriesShadow = 0;
        $totalReservations = 0;
        $totalEntries = 0;

        if (Schema::hasTable('serpro_api_usage_reservations')) {
            $totalReservations = (int) SerproApiUsageReservation::query()->count();
            if (Schema::hasColumn('serpro_api_usage_reservations', 'shadow_mode')) {
                $reservationsShadow = (int) SerproApiUsageReservation::query()->where('shadow_mode', true)->count();
            }
            if ($applySegregation && Schema::hasColumn('serpro_api_usage_reservations', 'segregation_class')) {
                $updated = SerproApiUsageReservation::query()
                    ->where(function ($q) {
                        $q->whereNull('segregation_class')
                            ->orWhere('segregation_class', '')
                            ->orWhere('segregation_class', 'SHADOW');
                    })
                    ->where('shadow_mode', true)
                    ->update(['segregation_class' => SerproDataSegregationClass::Shadow->value]);
                if ($updated > 0) {
                    $actions[] = "reservations:shadow_segregated={$updated}";
                }
            }
        }

        if (Schema::hasTable('serpro_api_usage_entries')) {
            $totalEntries = (int) SerproApiUsageEntry::query()->count();
            if (Schema::hasColumn('serpro_api_usage_entries', 'shadow_mode')) {
                $entriesShadow = (int) SerproApiUsageEntry::query()->where('shadow_mode', true)->count();
            }
            if ($applySegregation && Schema::hasColumn('serpro_api_usage_entries', 'segregation_class')) {
                $updated = DB::table('serpro_api_usage_entries')
                    ->where(function ($q) {
                        $q->whereNull('segregation_class')
                            ->orWhere('segregation_class', '');
                    })
                    ->update(['segregation_class' => SerproDataSegregationClass::Shadow->value]);
                if ($updated > 0) {
                    $actions[] = "entries:shadow_segregated={$updated}";
                }
            }
        }

        $powersTotal = 0;
        $powersSim = 0;
        if (Schema::hasTable('tax_proxy_powers')) {
            $powersTotal = (int) TaxProxyPower::query()->count();
            $powersSim = (int) TaxProxyPower::query()
                ->where(function ($q) {
                    $q->where('source', 'like', '%SIMUL%')
                        ->orWhere('source', 'like', '%FAKE%')
                        ->orWhere('source', 'like', '%MANUAL%');
                })
                ->count();
            if ($applySegregation && Schema::hasColumn('tax_proxy_powers', 'segregation_class')) {
                $updated = TaxProxyPower::query()
                    ->where(function ($q) {
                        $q->whereNull('segregation_class')
                            ->orWhere('segregation_class', '');
                    })
                    ->update(['segregation_class' => SerproDataSegregationClass::HistoricalUnverified->value]);
                if ($updated > 0) {
                    $actions[] = "powers:unverified_segregated={$updated}";
                }
            }
        }

        return [
            'offices' => $offices,
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
            'actions_applied' => $actions,
        ];
    }
}
