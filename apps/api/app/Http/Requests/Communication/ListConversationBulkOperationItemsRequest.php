<?php

namespace App\Http\Requests\Communication;

use App\Enums\Communication\ConversationBulkItemStatus;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Validation\Rule;

final class ListConversationBulkOperationItemsRequest extends CommunicationRequest
{
    public function authorize(): bool
    {
        $actor = $this->user();

        return $actor instanceof User
            && app(CommunicationAccess::class)->canView($actor);
    }

    protected function prepareCommunicationValidation(): void
    {
        if ($this->query->has('status') && is_string($this->query('status'))) {
            $this->merge(['status' => strtoupper(trim($this->string('status')->toString()))]);
        }
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', Rule::enum(ConversationBulkItemStatus::class)],
            'per_page' => ['sometimes', 'integer', 'between:1,100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function statusFilter(): ?ConversationBulkItemStatus
    {
        $status = $this->validated('status');

        return is_string($status) ? ConversationBulkItemStatus::from($status) : null;
    }

    public function perPage(): int
    {
        return (int) ($this->validated('per_page') ?? 50);
    }

    public function page(): int
    {
        return (int) ($this->validated('page') ?? 1);
    }
}
