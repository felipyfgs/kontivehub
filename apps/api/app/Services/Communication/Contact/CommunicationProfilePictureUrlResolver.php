<?php

namespace App\Services\Communication\Contact;

use App\Enums\Communication\ProfilePictureState;
use App\Models\CommunicationConversation;
use App\Models\CommunicationInboxIdentityProfile;

final readonly class CommunicationProfilePictureUrlResolver
{
    public function forConversation(CommunicationConversation $conversation): ?string
    {
        $identity = $conversation->identity;
        if ($identity === null) {
            return null;
        }
        $canonicalId = (int) ($identity->canonical_identity_id ?: $identity->id);
        $profiles = $identity->relationLoaded('inboxProfiles') ? $identity->inboxProfiles : collect();
        if ($canonicalId !== (int) $identity->id && $identity->relationLoaded('canonicalIdentity')) {
            $profiles = $identity->canonicalIdentity?->inboxProfiles ?? collect();
        }
        $profile = $profiles->first(fn (CommunicationInboxIdentityProfile $candidate): bool => (int) $candidate->inbox_id === (int) $conversation->inbox_id && $candidate->profile_picture_state === ProfilePictureState::Ready);

        return $profile ? route('communication.profile-pictures.show', ['profile' => $profile->id, 'version' => $profile->profile_picture_version], false) : null;
    }

    public function stateForConversation(CommunicationConversation $conversation): ?string
    {
        $identity = $conversation->identity;
        if ($identity === null) {
            return null;
        }
        $profiles = $identity->relationLoaded('inboxProfiles') ? $identity->inboxProfiles : collect();
        if ((int) ($identity->canonical_identity_id ?: $identity->id) !== (int) $identity->id && $identity->relationLoaded('canonicalIdentity')) {
            $profiles = $identity->canonicalIdentity?->inboxProfiles ?? collect();
        }
        $profile = $profiles->first(fn (CommunicationInboxIdentityProfile $candidate): bool => (int) $candidate->inbox_id === (int) $conversation->inbox_id);

        return $profile?->profile_picture_state?->value ?? $profile?->profile_picture_state;
    }
}
