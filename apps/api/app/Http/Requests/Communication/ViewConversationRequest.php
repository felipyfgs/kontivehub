<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\Access;

final class ViewConversationRequest extends TenantScopedRequest
{
    protected function prepareScopedValidation(): void
    {
        if ($this->query->has('include_messages')) {
            $this->merge(['include_messages' => $this->boolean('include_messages')]);
        }
    }

    public function authorize(): bool
    {
        $actor = $this->user();
        $conversation = $this->route('conversation');
        if (! $actor instanceof User || ! $conversation instanceof CommunicationConversation) {
            return false;
        }

        $inbox = $conversation->inbox()->first();

        return $inbox !== null
            && app(Access::class)->canView($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'include_messages' => ['sometimes', 'boolean'],
        ];
    }

    public function includeMessages(): bool
    {
        return (bool) ($this->validated()['include_messages'] ?? true);
    }
}
