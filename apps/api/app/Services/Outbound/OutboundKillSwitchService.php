<?php

namespace App\Services\Outbound;

use App\Enums\OutboundProfileStatus;
use App\Enums\OutboundSeriesStatus;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundSeriesCursor;
use App\Services\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;

/**
 * Kill switch global e por raiz/perfil — preserva estado e XML.
 */
final class OutboundKillSwitchService
{
    private const CACHE_KEY = 'sefaz.ma_outbound.kill_switch.runtime';

    public function __construct(
        private readonly AuditLogger $audit,
    ) {}

    public function isGlobalActive(): bool
    {
        if ((bool) config('sefaz.ma_outbound.kill_switch', false)) {
            return true;
        }

        return (bool) Cache::get(self::CACHE_KEY, false);
    }

    public function isBlocked(?OutboundCaptureProfile $profile = null): bool
    {
        if ($this->isGlobalActive()) {
            return true;
        }

        return $profile !== null && $profile->kill_switch;
    }

    public function activateGlobal(string $reason, int $userId, ?int $officeId = null): void
    {
        Cache::forever(self::CACHE_KEY, true);
        $this->audit->record(
            'outbound.kill_switch.global_on',
            'SUCCESS',
            null,
            ['reason' => mb_substr($reason, 0, 500)],
            $userId,
            $officeId,
        );
    }

    public function deactivateGlobal(string $reason, int $userId, ?int $officeId = null): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->audit->record(
            'outbound.kill_switch.global_off',
            'SUCCESS',
            null,
            ['reason' => mb_substr($reason, 0, 500)],
            $userId,
            $officeId,
        );
    }

    public function activateProfile(OutboundCaptureProfile $profile, string $reason, int $userId): void
    {
        $profile->forceFill([
            'kill_switch' => true,
            'kill_switch_reason' => mb_substr($reason, 0, 500),
            'kill_switch_at' => now(),
            'status' => OutboundProfileStatus::KillSwitched,
        ])->save();

        $this->audit->record(
            'outbound.kill_switch.profile_on',
            'SUCCESS',
            $profile,
            ['profile_id' => $profile->id, 'reason' => mb_substr($reason, 0, 500)],
            $userId,
            $profile->office_id,
        );
    }

    public function deactivateProfile(OutboundCaptureProfile $profile, string $reason, int $userId): void
    {
        $profile->forceFill([
            'kill_switch' => false,
            'kill_switch_reason' => null,
            'kill_switch_at' => null,
            'status' => OutboundProfileStatus::Paused,
        ])->save();

        $this->audit->record(
            'outbound.kill_switch.profile_off',
            'SUCCESS',
            $profile,
            ['profile_id' => $profile->id, 'reason' => mb_substr($reason, 0, 500)],
            $userId,
            $profile->office_id,
        );
    }

    public function blockSeries(OutboundSeriesCursor $series, string $reason, ?string $cstat = null): void
    {
        $series->forceFill([
            'status' => OutboundSeriesStatus::Blocked,
            'last_error' => mb_substr($reason, 0, 1000),
            'last_cstat' => $cstat,
            'locked_at' => null,
            'lock_owner' => null,
        ])->save();
    }
}
