<?php

namespace App\Actions\Outbound;

use App\DTO\Outbound\OutboundCscData;
use App\DTO\Outbound\OutboundKillSwitchData;
use App\DTO\Outbound\OutboundKillSwitchResult;
use App\DTO\Outbound\OutboundPackageUploadData;
use App\DTO\Outbound\OutboundProfileActivationData;
use App\DTO\Outbound\OutboundSeedData;
use App\DTO\Outbound\OutboundSeedResult;
use App\DTO\Outbound\OutboundSeriesResetData;
use App\Enums\OutboundProfileStatus;
use App\Enums\OutboundSeriesStatus;
use App\Exceptions\OutboundApiException;
use App\Jobs\QueryOutboundSequenceJob;
use App\Models\Establishment;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundSeriesCursor;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Outbound\CscVaultService;
use App\Services\Outbound\MaOfficialPackageIngestionService;
use App\Services\Outbound\OutboundKillSwitchService;
use App\Services\Outbound\OutboundSeedService;
use App\Support\CurrentTenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ManageOutboundCaptureAction
{
    public function __construct(
        private CurrentTenant $tenant,
        private OutboundSeedService $seeds,
        private CscVaultService $csc,
        private MaOfficialPackageIngestionService $packages,
        private OutboundKillSwitchService $killSwitch,
        private AuditLogger $audit,
    ) {}

    public function registerSeed(
        User $actor,
        Establishment $establishment,
        OutboundSeedData $data,
    ): OutboundSeedResult {
        try {
            $result = $this->seeds->registerSeed(
                $establishment,
                $data->xml,
                $data->environment,
                (int) $actor->id,
            );
        } catch (RuntimeException) {
            throw OutboundApiException::invalidSeed();
        }

        return new OutboundSeedResult(
            profile: $result['profile'],
            series: $result['series'],
        );
    }

    /** @return array<string, mixed> */
    public function storeCsc(
        User $actor,
        OutboundCaptureProfile $profile,
        OutboundCscData $data,
    ): array {
        try {
            return $this->csc->storeCsc(
                $profile,
                $data->token,
                $data->identifier,
                (int) $actor->id,
            );
        } catch (RuntimeException) {
            throw OutboundApiException::invalidCsc();
        }
    }

    /** @return array<string, mixed> */
    public function revealCsc(
        User $actor,
        OutboundCaptureProfile $profile,
    ): array {
        return $this->csc->revealCsc($profile, (int) $actor->id);
    }

    public function activate(
        User $actor,
        OutboundCaptureProfile $profile,
        OutboundProfileActivationData $data,
    ): OutboundCaptureProfile {
        return DB::transaction(function () use ($actor, $profile, $data) {
            $locked = OutboundCaptureProfile::query()
                ->whereKey($profile->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->forceFill([
                'mandate_reference' => $data->mandateReference,
                'consent_recorded' => true,
                'consent_recorded_at' => now(),
                'allowlisted' => $data->allowlisted,
                'allowlisted_at' => $data->allowlisted ? now() : null,
                'status' => OutboundProfileStatus::Active,
                'activated_by' => $actor->id,
                'activated_at' => now(),
            ])->save();

            $this->audit->record(
                'outbound.profile.activated',
                'SUCCESS',
                $locked,
                [
                    'profile_id' => $locked->id,
                    'mandate_reference' => $data->mandateReference,
                ],
                (int) $actor->id,
                (int) $locked->tenant_id,
            );

            return $locked->refresh();
        });
    }

    public function resetSeries(
        User $actor,
        OutboundSeriesCursor $series,
        OutboundSeriesResetData $data,
    ): OutboundSeriesCursor {
        return DB::transaction(function () use ($actor, $series, $data) {
            $locked = OutboundSeriesCursor::query()
                ->whereKey($series->id)
                ->lockForUpdate()
                ->firstOrFail();
            $locked->forceFill([
                'discovery_position' => $data->discoveryPosition,
                'status' => OutboundSeriesStatus::Idle,
                'last_error' => null,
            ])->save();

            $this->audit->record(
                'outbound.series.reset',
                'SUCCESS',
                $locked,
                [
                    'series_id' => $locked->id,
                    'discovery_position' => $data->discoveryPosition,
                    'reason' => $data->reason,
                    'position_kind' => 'nNF',
                ],
                (int) $actor->id,
                (int) $locked->tenant_id,
            );

            return $locked->refresh();
        });
    }

    /** @return array{queued: true, series_id: int} */
    public function triggerQuery(
        User $actor,
        OutboundSeriesCursor $series,
    ): array {
        if (! (bool) config(
            'sefaz.ma_outbound.protocol_query_enabled',
            false,
        )) {
            throw OutboundApiException::protocolQueryDisabled();
        }

        QueryOutboundSequenceJob::dispatch(
            (int) $series->id,
            'operator',
            (int) $actor->id,
        );

        return [
            'queued' => true,
            'series_id' => (int) $series->id,
        ];
    }

    /** @return array<string, mixed> */
    public function uploadPackage(
        User $actor,
        OutboundCaptureProfile $profile,
        OutboundPackageUploadData $data,
    ): array {
        $establishment = $profile->establishment()->firstOrFail();

        return $this->packages->ingest(
            $profile,
            $establishment,
            $data->files,
            (int) $actor->id,
        );
    }

    public function updateKillSwitch(
        User $actor,
        OutboundKillSwitchData $data,
    ): OutboundKillSwitchResult {
        $tenantId = (int) $this->tenant->id();
        if ($data->profileId !== null) {
            $profile = OutboundCaptureProfile::query()
                ->findOrFail($data->profileId);
            if ($data->active) {
                $this->killSwitch->activateProfile(
                    $profile,
                    $data->reason,
                    (int) $actor->id,
                );
            } else {
                $this->killSwitch->deactivateProfile(
                    $profile,
                    $data->reason,
                    (int) $actor->id,
                );
            }

            return new OutboundKillSwitchResult(
                profile: $profile->refresh(),
                globalActive: null,
            );
        }

        if (! $this->tenant->isPlatformPrivileged()) {
            throw new AuthorizationException;
        }

        if ($data->active) {
            $this->killSwitch->activateGlobal(
                $data->reason,
                (int) $actor->id,
                $tenantId,
            );
        } else {
            $this->killSwitch->deactivateGlobal(
                $data->reason,
                (int) $actor->id,
                $tenantId,
            );
        }

        return new OutboundKillSwitchResult(
            profile: null,
            globalActive: $this->killSwitch->isGlobalActive(),
        );
    }
}
