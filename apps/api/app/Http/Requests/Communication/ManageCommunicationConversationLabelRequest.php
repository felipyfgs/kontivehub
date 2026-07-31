<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ManageCommunicationConversationLabelRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $conversation = $this->route('conversation');
        $label = $this->route('label');
        if (! $actor instanceof User
            || ! $conversation instanceof CommunicationConversation
            || ! $label instanceof CommunicationLabel) {
            return false;
        }

        $inbox = $conversation->inbox()->first();

        return $inbox !== null
            && app(Access::class)->canReply($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
