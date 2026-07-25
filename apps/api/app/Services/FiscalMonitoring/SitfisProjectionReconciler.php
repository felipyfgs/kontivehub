<?php

namespace App\Services\FiscalMonitoring;

use App\Enums\FiscalPendingStatus;
use App\Models\FiscalFinding;
use App\Models\FiscalPendingItem;
use App\Models\FiscalSnapshot;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/** Mantém findings e pendências SITFIS alinhados ao snapshot corrente. */
final class SitfisProjectionReconciler
{
    public function __construct(private readonly FiscalSnapshotPersistence $persistence) {}

    /**
     * @param  list<array{code:string,creates_pending?:bool}>  $findings
     * @return array{findings_count:int,pending_count:int}
     */
    public function reconcile(FiscalSnapshot $snapshot, array $findings): array
    {
        $system = (string) config('fiscal_monitoring.sitfis.system_code', 'INTEGRA_SITFIS');
        $service = (string) config('fiscal_monitoring.sitfis.service_code', 'SITFIS');
        if ($snapshot->system_code !== $system || $snapshot->service_code !== $service) {
            return ['findings_count' => 0, 'pending_count' => 0];
        }

        $pendingCodes = collect($findings)
            ->filter(fn (array $row): bool => (bool) ($row['creates_pending'] ?? false))
            ->pluck('code')->filter()->map(fn ($code): string => (string) $code)->values()->all();

        return DB::transaction(function () use ($snapshot, $findings, $pendingCodes, $system, $service): array {
            $now = CarbonImmutable::now();

            FiscalFinding::query()->withoutGlobalScopes()
                ->where('office_id', $snapshot->office_id)
                ->where('client_id', $snapshot->client_id)
                ->where('is_active', true)
                ->where('snapshot_id', '<>', $snapshot->id)
                ->whereHas('snapshot', fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->where('system_code', $system)
                    ->where('service_code', $service))
                ->update(['is_active' => false, 'resolved_at' => $now]);

            FiscalPendingItem::query()->withoutGlobalScopes()
                ->where('office_id', $snapshot->office_id)
                ->where('client_id', $snapshot->client_id)
                ->where('status', FiscalPendingStatus::Open->value)
                ->whereHas('run', fn ($query) => $query
                    ->withoutGlobalScopes()
                    ->where('system_code', $system)
                    ->where('service_code', $service))
                ->when($pendingCodes !== [], fn ($query) => $query->whereNotIn('code', $pendingCodes))
                ->update([
                    'status' => FiscalPendingStatus::Resolved->value,
                    'resolved_at' => $now,
                    'open_dedupe_key' => null,
                ]);

            return $this->persistence->reproject($snapshot, $findings);
        });
    }
}
