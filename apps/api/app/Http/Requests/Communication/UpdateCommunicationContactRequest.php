<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\ContactUpdateData;
use App\Models\CommunicationContact;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class UpdateCommunicationContactRequest extends CommunicationRequest
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
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function payload(): ContactUpdateData
    {
        return new ContactUpdateData($this->validated());
    }
}
