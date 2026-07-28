<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;

final class ListCommunicationConversationMessagesRequest extends CommunicationRequest
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
        return [
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'anchor' => ['sometimes', 'nullable', 'string', 'in:latest,first_unread'],
        ];
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? 50);
    }

    public function cursor(): ?string
    {
        $value = $this->validated()['cursor'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function anchor(): string
    {
        return (string) ($this->validated()['anchor'] ?? 'latest');
    }
}
