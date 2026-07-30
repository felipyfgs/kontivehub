<?php

namespace App\Http\Requests\Communication;

use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Validation\Rule;

final class ListCommunicationSharedContentRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User && app(CommunicationAccess::class)->canView($actor);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'category' => ['required', Rule::in(['media', 'links', 'documents'])],
            'limit' => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:1024'],
            'inbox_id' => [
                Rule::prohibitedIf($this->route('conversation') !== null),
                'sometimes',
                'integer',
                'min:1',
            ],
        ];
    }

    public function category(): string
    {
        return (string) $this->validated()['category'];
    }

    public function limit(): int
    {
        return (int) ($this->validated()['limit'] ?? 30);
    }

    public function cursor(): ?string
    {
        $value = $this->validated()['cursor'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public function inboxId(): ?int
    {
        return isset($this->validated()['inbox_id']) ? (int) $this->validated()['inbox_id'] : null;
    }
}
