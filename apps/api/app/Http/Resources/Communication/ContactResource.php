<?php

namespace App\Http\Resources\Communication;

use App\Enums\Communication\ProfilePictureState;
use App\Models\User;
use App\Services\Communication\Contact\IdentityPhonePresenter;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class ContactResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $phonePresenter = app(IdentityPhonePresenter::class);
        $actor = $request->user();
        $rawProfilePictureState = $this->resource->profile_picture_state ?? null;
        $profilePictureState = $rawProfilePictureState instanceof ProfilePictureState
            ? $rawProfilePictureState->value
            : (is_string($rawProfilePictureState)
                ? $rawProfilePictureState
                : ProfilePictureState::Unknown->value);

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_provisional' => (bool) $this->is_provisional,
            'is_active' => (bool) $this->is_active,
            'profile_picture_url' => $this->resource->profile_picture_url
                ?? ($profilePictureState === ProfilePictureState::Ready->value
                    && ($this->resource->profile_picture_profile_id ?? null) !== null
                    ? route('communication.profile-pictures.show', [
                        'profile' => $this->resource->profile_picture_profile_id,
                        'version' => $this->resource->profile_picture_version,
                    ], false)
                    : null),
            'profile_picture_state' => $profilePictureState,
            'identities' => $this->whenLoaded('identities', fn () => $this->identities->map(fn ($identity) => [
                'id' => $identity->id,
                'channel' => $identity->channel?->value ?? $identity->channel,
                'address_masked' => $identity->address_masked,
                'phone' => $phonePresenter->present(
                    $identity,
                    $this->resource,
                    $actor instanceof User ? $actor : null,
                ),
                'is_active' => (bool) $identity->is_active,
                'links' => $identity->relationLoaded('clientLinks')
                    ? $identity->clientLinks->map(fn ($link) => [
                        'id' => $link->id,
                        'client_id' => $link->client_id,
                        'client_name' => $link->relationLoaded('client')
                            ? $link->client?->displayLabel()
                            : null,
                        'client_contact_id' => $link->client_contact_id,
                        'client_contact_name' => $link->relationLoaded('clientContact')
                            ? $link->clientContact?->name
                            : null,
                        'is_primary' => (bool) $link->is_primary,
                        'receives_automatic' => (bool) $link->receives_automatic,
                    ])->values()
                    : [],
            ])->values()),
            'purged_at' => $this->purged_at?->toIso8601String(),
        ];
    }
}
