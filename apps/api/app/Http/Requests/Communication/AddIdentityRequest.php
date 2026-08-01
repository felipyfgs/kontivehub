<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\IdentityCreationData;
use App\Models\CommunicationContact;
use App\Models\User;
use App\Rules\ValidWhatsAppAddress;
use App\Services\Communication\Authorization\Access;
use App\Services\Communication\WhatsAppAddressNormalizer;

final class AddIdentityRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $contact = $this->route('contact');

        return $actor instanceof User
            && $contact instanceof CommunicationContact
            && app(Access::class)->canManageContacts($actor, $contact);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'phone' => [
                'required',
                'string',
                'max:40',
                new ValidWhatsAppAddress(app(WhatsAppAddressNormalizer::class)),
            ],
        ];
    }

    public function payload(): IdentityCreationData
    {
        return new IdentityCreationData(
            phone: (string) $this->validated('phone'),
        );
    }
}
