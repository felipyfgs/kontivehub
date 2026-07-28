<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationContactCreationData;
use App\Models\User;
use App\Rules\ValidWhatsappAddress;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\WhatsappAddressNormalizer;

final class StoreContactRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(CommunicationAccess::class)->canManageContacts($actor);
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
                new ValidWhatsappAddress(app(WhatsappAddressNormalizer::class)),
            ],
            'client_id' => ['nullable', 'integer', 'min:1'],
            'client_contact_id' => ['nullable', 'integer', 'min:1'],
            'is_primary' => ['sometimes', 'boolean'],
            'receives_automatic' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): CommunicationContactCreationData
    {
        $validated = $this->validated();
        $name = array_key_exists('name', $validated) && $validated['name'] !== null
            ? trim((string) $validated['name'])
            : null;

        return new CommunicationContactCreationData(
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
