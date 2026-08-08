<?php

namespace App\Services\Communication\ProfilePicture;

use App\Enums\Communication\ProfilePictureState;
use App\Jobs\Communication\RefreshProfilePictureJob;
use App\Models\CommunicationInboxIdentityProfile;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Media\MediaStore;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * READY with a dangling object_id keeps advertising stream URLs that 404.
 * Demote to PENDING and re-queue a refresh so the projection stops leaking.
 */
final readonly class ProfilePictureMissingObjectHealer
{
    public function __construct(
        private MediaStore $media,
        private EventRecorder $events,
    ) {}

    public function heal(CommunicationInboxIdentityProfile $profile, int $version): void
    {
        if ($profile->profile_picture_state !== ProfilePictureState::Ready
            || (int) $profile->profile_picture_version !== $version
            || ! is_string($profile->profile_picture_object_id)
            || $profile->profile_picture_object_id === '') {
            return;
        }

        try {
            if ($this->media->exists($profile->profile_picture_object_id)) {
                return;
            }
        } catch (Throwable) {
            // Treat storage probe failures like a missing object: demote fail-closed.
        }

        $dispatched = false;
        $tenantId = (int) $profile->tenant_id;
        $profileId = (int) $profile->id;

        DB::transaction(function () use ($tenantId, $profileId, $version, &$dispatched): void {
            $current = CommunicationInboxIdentityProfile::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->lockForUpdate()
                ->find($profileId);
            if ($current === null
                || $current->profile_picture_state !== ProfilePictureState::Ready
                || (int) $current->profile_picture_version !== $version
                || ! is_string($current->profile_picture_object_id)
                || $current->profile_picture_object_id === '') {
                return;
            }

            try {
                if ($this->media->exists($current->profile_picture_object_id)) {
                    return;
                }
            } catch (Throwable) {
                // continue demotion
            }

            $current->forceFill([
                'profile_picture_state' => ProfilePictureState::Pending,
                'profile_picture_object_id' => null,
                'profile_picture_mime_type' => null,
                'profile_picture_size_bytes' => null,
                'profile_picture_sha256' => null,
                'profile_picture_storage_context' => null,
                'profile_picture_fetched_at' => null,
                'profile_picture_retry_at' => null,
                'profile_picture_error_code' => 'OBJECT_MISSING',
            ])->save();

            $this->events->record(
                $tenantId,
                'contact.profile_picture.updated',
                [
                    'inbox_id' => (int) $current->inbox_id,
                    'identity_id' => (int) $current->identity_id,
                    'state' => ProfilePictureState::Pending->value,
                    'version' => $version,
                ],
                (int) $current->inbox_id,
            );

            $dispatched = true;
        });

        if ($dispatched) {
            RefreshProfilePictureJob::dispatch($tenantId, $profileId, $version)->afterCommit();
        }
    }
}
