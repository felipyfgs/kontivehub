<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\ContactCreationData;
use App\Models\User;
use App\Rules\ValidWhatsAppAddress;
use App\Services\Communication\Authorization\Access;
use App\Services\Communication\WhatsAppAddressNormalizer;

final class StoreContactRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(Access::class)->canManageContacts($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'name' => ['nullable', 'string', 'max:160'],
            'phone' => [
                'required',
                'string',
                'max:40',
                new ValidWhatsAppAddress(app(WhatsAppAddressNormalizer::class)),
            ],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'client_contact_id' => ['nullable', 'integer', 'min:1'],
            'is_primary' => ['sometimes', 'boolean'],
            'receives_automatic' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): ContactCreationData
    {
        $validated = $this->validated();
        $name = array_key_exists('name', $validated) && $validated['name'] !== null
            ? trim((string) $validated['name'])
            : null;

        return new ContactCreationData(
            name: $name,
            phone: $validated['phone'],
            clientId: isset($validated['client_id'])
                ? (int) $validated['client_id']
                : null,
            clientContactId: isset($validated['client_contact_id'])
                ? (int) $validated['client_contact_id']
                : null,
            isPrimary: $this->boolean('is_primary'),
            receivesAutomatic: ! array_key_exists('receives_automatic', $validated)
                || $this->boolean('receives_automatic'),
        );
    }
}
