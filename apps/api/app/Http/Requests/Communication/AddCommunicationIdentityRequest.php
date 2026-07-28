<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationIdentityCreationData;
use App\Models\CommunicationContact;
use App\Models\User;
use App\Rules\ValidWhatsappAddress;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\WhatsappAddressNormalizer;

final class AddCommunicationIdentityRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $contact = $this->route('contact');

        return $actor instanceof User
            && $contact instanceof CommunicationContact
            && app(CommunicationAccess::class)->canManageContacts($actor, $contact);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'phone' => [
                'required',
                'string',
                'max:40',
                new ValidWhatsappAddress(app(WhatsappAddressNormalizer::class)),
            ],
        ];
    }

    public function payload(): CommunicationIdentityCreationData
    {
        return new CommunicationIdentityCreationData(
            phone: (string) $this->validated('phone'),
        );
    }
}
