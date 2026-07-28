<?php

namespace App\Http\Requests\Communication;

use App\DTO\Communication\CommunicationConversationUpdateData;
use App\Enums\Communication\ConversationStatus;
use App\Models\CommunicationConversation;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use Illuminate\Validation\Rule;

final class UpdateConversationRequest extends CommunicationRequest
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
            && app(CommunicationAccess::class)->canReply($actor, $inbox);
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'lock_version' => ['required', 'integer', 'min:1'],
            'status' => ['sometimes', Rule::enum(ConversationStatus::class)],
            'assignee_membership_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'work_department_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'priority' => ['sometimes', 'integer', 'between:0,100'],
            'snoozed_until' => ['nullable', 'date', 'after:now'],
        ];
    }

    public function updateData(): CommunicationConversationUpdateData
    {
        $validated = $this->validated();

        return new CommunicationConversationUpdateData(
            lockVersion: (int) $validated['lock_version'],
            status: isset($validated['status'])
                ? ConversationStatus::from((string) $validated['status'])
                : null,
            hasStatus: array_key_exists('status', $validated),
            assigneeMembershipId: isset($validated['assignee_membership_id'])
                ? (int) $validated['assignee_membership_id']
                : null,
            hasAssigneeMembershipId: array_key_exists('assignee_membership_id', $validated),
            workDepartmentId: isset($validated['work_department_id'])
                ? (int) $validated['work_department_id']
                : null,
            hasWorkDepartmentId: array_key_exists('work_department_id', $validated),
            priority: isset($validated['priority']) ? (int) $validated['priority'] : null,
            hasPriority: array_key_exists('priority', $validated),
            snoozedUntil: isset($validated['snoozed_until'])
                ? (string) $validated['snoozed_until']
                : null,
            hasSnoozedUntil: array_key_exists('snoozed_until', $validated),
        );
    }
}
