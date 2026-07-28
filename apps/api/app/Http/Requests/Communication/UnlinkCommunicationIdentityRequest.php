<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationIdentity;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class UnlinkCommunicationIdentityRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $identity = $this->route('identity');

        return $actor instanceof User
            && $identity instanceof CommunicationIdentity
            && app(CommunicationAccess::class)->canManageContacts($actor, $identity);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
