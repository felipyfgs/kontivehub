<?php

namespace App\Services\Outbound;

use App\DTO\Outbound\OutboundCaptureProfileFilters;
use App\DTO\Outbound\OutboundNumberFilters;
use App\DTO\Outbound\OutboundRunFilters;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundCaptureRun;
use App\Models\OutboundNumberState;
use App\Models\OutboundSeriesCursor;
use Illuminate\Database\Eloquent\Collection;

final readonly class OutboundCaptureQuery
{
    /** @return Collection<int, OutboundCaptureProfile> */
    public function profiles(OutboundCaptureProfileFilters $filters): Collection
    {
        $query = OutboundCaptureProfile::query()->orderByDesc('id');
        if ($filters->establishmentId !== null) {
            $query->where('establishment_id', $filters->establishmentId);
        }
        if ($filters->clientId !== null) {
            $query->where('client_id', $filters->clientId);
        }

        return $query->limit(100)->get();
    }

    /** @return Collection<int, OutboundSeriesCursor> */
    public function series(OutboundCaptureProfile $profile): Collection
    {
        return $profile->seriesCursors()
            ->orderBy('series')
            ->get();
    }

    /** @return Collection<int, OutboundNumberState> */
    public function numbers(
        OutboundSeriesCursor $series,
        OutboundNumberFilters $filters,
    ): Collection {
        $query = $series->numberStates()->orderBy('nnf');
        if ($filters->gapsOnly) {
            $query->whereIn('status', [
                'GAP_PENDING',
                'RETRY_SCHEDULED',
                'EXHAUSTED_VISIBLE',
                'XML_PENDING',
                'KEY_DISCOVERED',
                'LIMITED_NO_KEY',
            ]);
        }

        return $query->limit(200)->get();
    }

    /** @return Collection<int, OutboundCaptureRun> */
    public function runs(OutboundRunFilters $filters): Collection
    {
        $query = OutboundCaptureRun::query()
            ->orderByDesc('id')
            ->limit(50);
        if ($filters->seriesCursorId !== null) {
            $query->where(
                'outbound_series_cursor_id',
                $filters->seriesCursorId,
            );
        }

        return $query->get();
    }

    /** @return array<string, mixed> */
    public function killSwitchStatus(OutboundKillSwitchService $killSwitch): array
    {
        return [
            'global_active' => $killSwitch->isGlobalActive(),
            'config_flag' => (bool) config('sefaz.ma_outbound.kill_switch', false),
            'enabled' => (bool) config('sefaz.ma_outbound.enabled', false),
            'protocol_query_enabled' => (bool) config(
                'sefaz.ma_outbound.protocol_query_enabled',
                false,
            ),
            'm2m_status' => (string) config(
                'sefaz.ma_outbound.m2m_status',
                'NO_GO_M2M',
            ),
            'mutating_probe_enabled' => (bool) config(
                'sefaz.ma_outbound.mutating_probe_enabled',
                false,
            ),
        ];
    }
}
