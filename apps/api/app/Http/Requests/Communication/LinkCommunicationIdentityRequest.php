<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\IdentityLinkData;
use App\Models\CommunicationIdentity;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class LinkCommunicationIdentityRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $identity = $this->route('identity');

        return $actor instanceof User
            && $identity instanceof CommunicationIdentity
            && app(Access::class)->canManageContacts($actor, $identity);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
            'client_contact_id' => ['nullable', 'integer', 'min:1'],
            'is_primary' => ['sometimes', 'boolean'],
            'receives_automatic' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): IdentityLinkData
    {
        $validated = $this->validated();

        return new IdentityLinkData(
            clientId: (int) $validated['client_id'],
            clientContactId: isset($validated['client_contact_id'])
                ? (int) $validated['client_contact_id']
                : null,
            isPrimary: $this->boolean('is_primary'),
            receivesAutomatic: ! array_key_exists('receives_automatic', $validated)
                || $this->boolean('receives_automatic'),
        );
    }
}
