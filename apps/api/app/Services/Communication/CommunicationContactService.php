<?php

namespace App\Services\Communication;

use App\DTO\Communication\CommunicationContactCreationData;
use App\DTO\Communication\CommunicationContactUpdateData;
use App\DTO\Communication\CommunicationIdentityCreationData;
use App\DTO\Communication\CommunicationIdentityLinkData;
use App\Enums\CommunicationChannel;
use App\Exceptions\CommunicationContactApiException;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CommunicationContact;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationIdentityLink;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class CommunicationContactService
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly WhatsappAddressNormalizer $normalizer,
        private readonly CommunicationEventRecorder $events,
        private readonly CommunicationContactCanonicalizer $contactCanonicalizer,
        private readonly CommunicationConversationCanonicalizer $peerCanonicalizer,
    ) {}

    public function create(CommunicationContactCreationData $data): CommunicationContact
    {
        $tenant = $this->currentTenant->tenant();
        $address = $this->normalizer->normalize($data->phone);

        try {
            return DB::transaction(function () use ($tenant, $data, $address): CommunicationContact {
                $contact = CommunicationContact::query()->create([
                    'tenant_id' => $tenant->id,
                    'name' => $data->name,
                    'is_provisional' => $data->name === null || $data->name === '',
                    'is_active' => true,
                ]);
                $identity = $this->createIdentity($contact, $address);

                if ($data->clientId !== null) {
                    $this->link($identity, new CommunicationIdentityLinkData(
                        clientId: $data->clientId,
                        clientContactId: $data->clientContactId,
                        isPrimary: $data->isPrimary,
                        receivesAutomatic: $data->receivesAutomatic,
                    ));
                }

                $this->events->record((int) $tenant->id, 'CONTACT_CREATED', [
                    'contact_id' => (int) $contact->id,
                    'identity_id' => (int) $identity->id,
                ], actorMembershipId: $this->currentTenant->realMembership()?->id);

                return $contact->load([
                    'identities.clientLinks.client',
                    'identities.clientLinks.clientContact',
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            throw CommunicationContactApiException::whatsappAlreadyAssigned();
        }
    }

    public function update(
        CommunicationContact $contact,
        CommunicationContactUpdateData $data,
    ): CommunicationContact {
        return DB::transaction(function () use ($contact, $data): CommunicationContact {
            $locked = $this->lockMutableContact($contact);
            $attributes = $data->attributes;
            if (array_key_exists('name', $attributes)) {
                $name = $attributes['name'] !== null
                    ? trim((string) $attributes['name'])
                    : null;
                $attributes['name'] = $name;
                $attributes['is_provisional'] = $name === null || $name === '';
            }

            $locked->fill($attributes)->save();

            return $locked->fresh()->load([
                'identities.clientLinks.client',
                'identities.clientLinks.clientContact',
            ]);
        }, 3);
    }

    public function addIdentity(
        CommunicationContact $contact,
        CommunicationIdentityCreationData $data,
    ): CommunicationIdentity {
        $address = $this->normalizer->normalize($data->phone);

        try {
            return DB::transaction(function () use ($contact, $address): CommunicationIdentity {
                return $this->createIdentity(
                    $this->lockMutableContact($contact),
                    $address,
                );
            }, 3);
        } catch (UniqueConstraintViolationException) {
            throw CommunicationContactApiException::identityAlreadyRegistered();
        }
    }

    public function linkIdentity(
        CommunicationIdentity $identity,
        CommunicationIdentityLinkData $data,
    ): CommunicationIdentityLink {
        return DB::transaction(
            fn () => $this->link($this->lockMutableIdentity($identity), $data),
            3,
        );
    }

    public function unlinkIdentity(
        CommunicationIdentity $identity,
        int $linkId,
    ): void {
        DB::transaction(function () use ($identity, $linkId): void {
            $lockedIdentity = $this->lockMutableIdentity($identity);
            CommunicationIdentityLink::query()
                ->where('identity_id', $lockedIdentity->id)
                ->findOrFail($linkId)
                ->delete();
        }, 3);
    }

    private function createIdentity(
        CommunicationContact $contact,
        string $address,
    ): CommunicationIdentity {
        return CommunicationIdentity::query()->create([
            'tenant_id' => $contact->tenant_id,
            'contact_id' => $contact->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => $this->mask($address),
            'is_active' => true,
        ]);
    }

    private function lockMutableContact(
        CommunicationContact $contact,
    ): CommunicationContact {
        [$locked] = $this->contactCanonicalizer->lockContactClass($contact);
        if ($locked->purged_at !== null) {
            throw CommunicationContactApiException::contactPurged();
        }

        return $locked;
    }

    private function lockMutableIdentity(
        CommunicationIdentity $identity,
    ): CommunicationIdentity {
        $freshIdentity = CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $identity->tenant_id)
            ->whereKey($identity->id)
            ->firstOrFail();
        $canonicalIdentity = $this->peerCanonicalizer->identity($freshIdentity);
        $contact = CommunicationContact::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $canonicalIdentity->tenant_id)
            ->findOrFail($canonicalIdentity->contact_id);
        [$lockedContact] = $this->contactCanonicalizer->lockContactClass($contact);
        if ($lockedContact->purged_at !== null) {
            throw CommunicationContactApiException::contactPurged();
        }

        $freshIdentity = CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $identity->tenant_id)
            ->whereKey($identity->id)
            ->firstOrFail();
        $canonicalIdentity = $this->peerCanonicalizer->identity($freshIdentity);
        if ((int) $canonicalIdentity->contact_id !== (int) $lockedContact->id) {
            throw new LogicException('Contact da identity mudou durante a canonicalização.');
        }

        return CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $identity->tenant_id)
            ->where('contact_id', $lockedContact->id)
            ->whereKey($canonicalIdentity->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function link(
        CommunicationIdentity $identity,
        CommunicationIdentityLinkData $data,
    ): CommunicationIdentityLink {
        $client = Client::query()->lockForUpdate()->findOrFail($data->clientId);
        if ($data->clientContactId !== null) {
            ClientContact::query()
                ->where('client_id', $client->id)
                ->findOrFail($data->clientContactId);
        }
        if ($data->isPrimary) {
            CommunicationIdentityLink::query()
                ->where('client_id', $client->id)
                ->update(['is_primary' => false]);
        }

        return CommunicationIdentityLink::query()->updateOrCreate([
            'identity_id' => $identity->id,
            'client_id' => $client->id,
            'client_contact_id' => $data->clientContactId,
        ], [
            'tenant_id' => $identity->tenant_id,
            'is_primary' => $data->isPrimary,
            'receives_automatic' => $data->receivesAutomatic,
        ])->load(['client', 'clientContact']);
    }

    private function mask(string $address): string
    {
        return substr($address, 0, min(3, strlen($address)))
            .'•••••'
            .substr($address, -4);
    }
}
