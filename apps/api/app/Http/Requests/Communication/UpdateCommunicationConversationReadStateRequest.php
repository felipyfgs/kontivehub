<?php

namespace App\Http\Requests\Communication;

use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use Illuminate\Validation\Rule;

final class UpdateCommunicationConversationReadStateRequest extends CommunicationRequest
{
    protected function prepareCommunicationValidation(): void
    {
        if ($this->has('state')) {
            $this->merge(['state' => strtoupper(trim((string) $this->input('state')))]);
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
            'state' => ['required', Rule::in(['READ', 'UNREAD'])],
            'through_message_id' => ['required_if:state,READ', 'prohibited_if:state,UNREAD', 'integer', 'min:1'],
            'expected_version' => ['required_if:state,UNREAD', 'prohibited_if:state,READ', 'integer', 'min:0'],
        ];
    }

    public function state(): string
    {
        return (string) $this->validated('state');
    }

    public function throughMessageId(): int
    {
        return (int) $this->validated('through_message_id');
    }

    public function expectedVersion(): int
    {
        return (int) $this->validated('expected_version');
    }
}
