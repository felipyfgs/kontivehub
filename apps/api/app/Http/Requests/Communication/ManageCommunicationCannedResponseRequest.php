<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationCannedResponse;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class ManageCommunicationCannedResponseRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $canned = $this->route('canned');

        return $actor instanceof User
            && $canned instanceof CommunicationCannedResponse
            && app(CommunicationAccess::class)->canManageQuickReplies($actor, $canned);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
