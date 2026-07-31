<?php

namespace App\Services\Communication\ProfilePicture;

use App\Enums\Communication\ProfilePictureState;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationUnavailableException;
use App\Jobs\Communication\RefreshCommunicationProfilePictureJob;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxIdentityProfile;
use App\Services\Communication\Availability;

/** Schedules native profile-picture refreshes without performing provider I/O inline. */
final readonly class ProfilePictureRefreshScheduler
{
    public function __construct(private Availability $availability) {}

    public function schedule(
        CommunicationInbox $inbox,
        CommunicationIdentity $identity,
    ): ?CommunicationInboxIdentityProfile {
        try {
            $this->availability->assertEnabled($inbox, true);
        } catch (CommunicationUnavailableException) {
            return null;
        }

        $canonicalIdentityId = (int) ($identity->canonical_identity_id ?: $identity->id);
        $canonicalIdentity = $canonicalIdentityId === (int) $identity->id
            ? $identity
            : CommunicationIdentity::query()->withoutGlobalScopes()
                ->where('tenant_id', $inbox->tenant_id)
                ->find($canonicalIdentityId);
        if (! $canonicalIdentity instanceof CommunicationIdentity
            || $canonicalIdentity->channel !== CommunicationChannel::WhatsApp
            || ! $canonicalIdentity->is_active
            || $canonicalIdentity->purged_at !== null
            || ! is_string($canonicalIdentity->address_encrypted)
            || $canonicalIdentity->address_encrypted === '') {
            return null;
        }

        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->createOrFirst(
            [
                'tenant_id' => (int) $inbox->tenant_id,
                'inbox_id' => (int) $inbox->id,
                'identity_id' => $canonicalIdentityId,
            ],
            [
                'field_versions' => [],
                'cleared_fields' => [],
                'profile_picture_state' => ProfilePictureState::Pending,
                'profile_picture_version' => 1,
            ],
        );
        if ((int) $profile->profile_picture_version < 1) {
            CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
                ->whereKey($profile->id)
                ->where('profile_picture_version', '<', 1)
                ->update(['profile_picture_version' => 1]);
        }
        $profile = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()->findOrFail($profile->id);
        if (! $this->isDue($profile)) {
            return $profile;
        }

        RefreshCommunicationProfilePictureJob::dispatch(
            (int) $profile->tenant_id,
            (int) $profile->id,
            (int) $profile->profile_picture_version,
        )->afterCommit();

        return $profile;
    }

    private function isDue(CommunicationInboxIdentityProfile $profile): bool
    {
        if (in_array($profile->profile_picture_state, [ProfilePictureState::Unknown, ProfilePictureState::Pending], true)) {
            return true;
        }
        if (in_array($profile->profile_picture_state, [ProfilePictureState::Unavailable, ProfilePictureState::Failed], true)) {
            return $profile->profile_picture_retry_at?->isPast() === true;
        }
        if ($profile->profile_picture_state !== ProfilePictureState::Ready) {
            return false;
        }
        if ($profile->profile_picture_retry_at !== null) {
            return $profile->profile_picture_retry_at->isPast();
        }

        return $profile->profile_picture_fetched_at === null
            || $profile->profile_picture_fetched_at->lte(
                now()->subSeconds((int) config('communication.profile_pictures.refresh_ttl_seconds', 86_400)),
            );
    }
}
