<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationInbox;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ManageCommunicationInboxRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $inbox = $this->route('inbox');

        return $actor instanceof User
            && $inbox instanceof CommunicationInbox
            && app(Access::class)->canManage($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
