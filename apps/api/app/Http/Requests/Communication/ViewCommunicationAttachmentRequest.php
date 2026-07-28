<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationAttachment;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class ViewCommunicationAttachmentRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();
        $attachment = $this->route('attachment');
        if (! $actor instanceof User || ! $attachment instanceof CommunicationAttachment) {
            return false;
        }

        $attachment->loadMissing('message.inbox');

        return $attachment->message?->inbox !== null
            && app(CommunicationAccess::class)->canView(
                $actor,
                $attachment->message->inbox,
            );
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
