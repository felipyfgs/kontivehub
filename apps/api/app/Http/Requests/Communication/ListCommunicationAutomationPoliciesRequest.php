<?php

namespace App\Http\Requests\Communication;

use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class ListCommunicationAutomationPoliciesRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(CommunicationAccess::class)->canManage($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
