<?php

namespace App\Services\Communication\Contact;

use App\Enums\CommunicationChannel;
use App\Models\CommunicationContact;
use App\Models\CommunicationIdentity;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Throwable;

final readonly class CommunicationIdentityPhonePresenter
{
    public function __construct(
        private CommunicationAccess $access,
    ) {}

    public function present(
        CommunicationIdentity $identity,
        ?CommunicationContact $contact,
        ?User $actor,
    ): ?string {
        if (
            $identity->channel !== CommunicationChannel::Whatsapp
            || $actor === null
            || ! $this->access->canView($actor)
        ) {
            return null;
        }

        $contact ??= $identity->relationLoaded('contact') ? $identity->contact : null;

        if ($contact === null || $identity->purged_at !== null || $contact->purged_at !== null) {
            return null;
        }

        try {
            $address = $identity->address_encrypted;
        } catch (Throwable) {
            return null;
        }

        if (! is_string($address) || preg_match('/^\+[1-9]\d{7,14}$/', $address) !== 1) {
            return null;
        }

        return $address;
    }
}
