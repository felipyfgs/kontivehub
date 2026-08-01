<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationAttachment;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ViewAttachmentRequest extends TenantScopedRequest
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
            && app(Access::class)->canView(
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
