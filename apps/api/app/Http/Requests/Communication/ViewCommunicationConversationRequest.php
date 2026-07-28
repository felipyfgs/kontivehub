<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class ViewCommunicationConversationRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $conversation = $this->route('conversation');
        if (! $actor instanceof User || ! $conversation instanceof CommunicationConversation) {
            return false;
        }

        $inbox = $conversation->inbox()->first();

        return $inbox !== null
            && app(CommunicationAccess::class)->canView($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
