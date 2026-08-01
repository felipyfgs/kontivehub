<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationCannedResponse;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ManageCannedResponseRequest extends TenantScopedRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $canned = $this->route('canned');

        return $actor instanceof User
            && $canned instanceof CommunicationCannedResponse
            && app(Access::class)->canManageQuickReplies($actor, $canned);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
