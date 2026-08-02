<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationContact;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ViewContactRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $contact = $this->route('contact');

        return $actor instanceof User
            && $contact instanceof CommunicationContact
            && app(Access::class)->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'inbox_id' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function inboxId(): ?int
    {
        $validated = $this->validated();

        return isset($validated['inbox_id']) ? (int) $validated['inbox_id'] : null;
    }
}
