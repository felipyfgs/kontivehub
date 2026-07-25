<?php

namespace App\Services\Serpro\Usage;

use App\Enums\SerproDataSegregationClass;
use App\Models\SerproApiUsageEntry;
use App\Models\SerproApiUsageReservation;
use App\Models\SerproUsageIncident;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Schema;

/**
 * Segrega entradas shadow/legadas e impede que offboarding apague ledger sob retenção.
 */
final class UsageShadowSegregationService
{
    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    /**
     * Marca entradas/reservas ainda sem segregation_class adequada.
     *
     * @return array{entries: int, reservations: int}
     */
    public function segregateLegacyShadow(): array
    {
        $entries = 0;
        $reservations = 0;

        if (Schema::hasColumn('serpro_api_usage_entries', 'segregation_class')) {
            $entries = SerproApiUsageEntry::query()
                ->withoutGlobalScopes()
                ->where(function ($q): void {
                    $q->whereNull('segregation_class')
                        ->orWhere('segregation_class', '')
                        ->orWhere(function ($s): void {
                            $s->where('shadow_mode', true)
                                ->where('segregation_class', SerproDataSegregationClass::Production->value);
                        });
                })
                ->update([
                    'segregation_class' => SerproDataSegregationClass::Shadow->value,
                ]);
        }

        if (Schema::hasColumn('serpro_api_usage_reservations', 'segregation_class')) {
            $reservations = SerproApiUsageReservation::query()
                ->withoutGlobalScopes()
                ->where(function ($q): void {
                    $q->whereNull('segregation_class')
                        ->orWhere('segregation_class', '')
                        ->orWhere(function ($s): void {
                            $s->where('shadow_mode', true)
                                ->where('segregation_class', SerproDataSegregationClass::Production->value);
                        });
                })
                ->update([
                    'segregation_class' => SerproDataSegregationClass::Shadow->value,
                ]);
        }

        if ($entries > 0 || $reservations > 0) {
            if (Schema::hasTable('serpro_usage_incidents')) {
                SerproUsageIncident::query()->create([
                    'kind' => SerproUsageIncident::KIND_SHADOW_SEGREGATION,
                    'severity' => SerproUsageIncident::SEVERITY_RESOLVED,
                    'sanitized_summary' => sprintf(
                        'Segregação shadow/legado: %d entries, %d reservations.',
                        $entries,
                        $reservations,
                    ),
                    'opened_at' => now(),
                    'resolved_at' => now(),
                    'metadata' => ['entries' => $entries, 'reservations' => $reservations],
                ]);
            }

            $this->audit->record(
                action: 'serpro.usage.shadow_segregated',
                result: 'SUCCESS',
                context: [
                    'entries' => $entries,
                    'reservations' => $reservations,
                ],
            );
        }

        return ['entries' => $entries, 'reservations' => $reservations];
    }

    /**
     * Offboarding NÃO deve apagar ledger dentro da retenção configurada.
     *
     * @return array{allowed: bool, reason: string|null, retention_days: int, youngest_entry_days: int|null}
     */
    public function assertLedgerRetentionAllowsPurge(int $officeId): array
    {
        $days = (int) config('serpro_usage.ledger_retention_days', 2555);
        $youngest = SerproApiUsageEntry::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->orderByDesc('occurred_at')
            ->value('occurred_at');

        if ($youngest === null) {
            return [
                'allowed' => true,
                'reason' => null,
                'retention_days' => $days,
                'youngest_entry_days' => null,
            ];
        }

        $ageDays = now()->diffInDays($youngest);
        if ($ageDays < $days) {
            return [
                'allowed' => false,
                'reason' => 'LEDGER_UNDER_RETENTION',
                'retention_days' => $days,
                'youngest_entry_days' => $ageDays,
            ];
        }

        return [
            'allowed' => true,
            'reason' => null,
            'retention_days' => $days,
            'youngest_entry_days' => $ageDays,
        ];
    }
}
