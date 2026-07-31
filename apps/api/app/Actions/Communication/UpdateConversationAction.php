<?php

namespace App\Actions\Communication;

use App\DTO\Communication\ConversationUpdateData;
use App\Enums\Communication\ConversationStatus;
use App\Exceptions\CommunicationConversationApiException;
use App\Models\CommunicationConversation;
use App\Models\CommunicationInboxMember;
use App\Models\TenantMembership;
use App\Models\WorkDepartment;
use App\Services\Communication\Events\EventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;

final readonly class UpdateConversationAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private EventRecorder $events,
    ) {}

    public function handle(
        CommunicationConversation $conversation,
        ConversationUpdateData $data,
    ): CommunicationConversation {
        return DB::transaction(function () use ($conversation, $data): CommunicationConversation {
            $fresh = CommunicationConversation::query()
                ->whereKey($conversation->id)
                ->lockForUpdate()
                ->firstOrFail();
            if ($fresh->merged_into_conversation_id !== null) {
                throw CommunicationConversationApiException::versionConflict();
            }
            if ((int) $fresh->lock_version !== $data->lockVersion) {
                throw CommunicationConversationApiException::versionConflict();
            }

            $this->assertAssigneeIsEligible($fresh, $data);
            $this->assertDepartmentBelongsToTenant($fresh, $data);

            $attributes = $this->attributes($data);
            $attributes['lock_version'] = $data->lockVersion + 1;
            $fresh->forceFill($attributes)->save();

            $this->events->record(
                (int) $fresh->tenant_id,
                'CONVERSATION_UPDATED',
                [
                    'status' => $fresh->status->value,
                    'lock_version' => (int) $fresh->lock_version,
                ],
                inboxId: (int) $fresh->inbox_id,
                conversationId: (int) $fresh->id,
                actorMembershipId: $this->currentTenant->realMembership()?->id,
            );

            return $fresh->load(['identity.contact', 'clients', 'labels']);
        });
    }

    private function assertAssigneeIsEligible(
        CommunicationConversation $conversation,
        ConversationUpdateData $data,
    ): void {
        if (! $data->hasAssigneeMembershipId || $data->assigneeMembershipId === null) {
            return;
        }

        $membership = TenantMembership::query()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('is_active', true)
            ->findOrFail($data->assigneeMembershipId);
        $hasInbox = CommunicationInboxMember::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('inbox_id', $conversation->inbox_id)
            ->where('tenant_membership_id', $membership->id)
            ->where('is_active', true)
            ->exists();

        if (! $hasInbox && ! $membership->role?->isAdmin()) {
            throw CommunicationConversationApiException::assigneeCannotAccessInbox();
        }
    }

    private function assertDepartmentBelongsToTenant(
        CommunicationConversation $conversation,
        ConversationUpdateData $data,
    ): void {
        if (! $data->hasWorkDepartmentId || $data->workDepartmentId === null) {
            return;
        }

        WorkDepartment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->findOrFail($data->workDepartmentId);
    }

    /** @return array<string, mixed> */
    private function attributes(
        ConversationUpdateData $data,
    ): array {
        $attributes = [];
        if ($data->hasPriority) {
            $attributes['priority'] = $data->priority;
        }
        if ($data->hasAssigneeMembershipId) {
            $attributes['assignee_membership_id'] = $data->assigneeMembershipId;
        }
        if ($data->hasWorkDepartmentId) {
            $attributes['work_department_id'] = $data->workDepartmentId;
        }

        if ($data->hasStatus) {
            $status = $data->status;
            if ($status === ConversationStatus::Snoozed && $data->snoozedUntil === null) {
                throw CommunicationConversationApiException::snoozedUntilRequired();
            }

            $attributes['status'] = $status;
            $attributes['resolved_at'] = $status === ConversationStatus::Resolved ? now() : null;
            $attributes['snoozed_until'] = $status === ConversationStatus::Snoozed
                ? $data->snoozedUntil
                : null;
        } elseif ($data->hasSnoozedUntil) {
            if ($data->snoozedUntil === null) {
                throw CommunicationConversationApiException::snoozedUntilRequired();
            }

            $attributes['snoozed_until'] = $data->snoozedUntil;
            $attributes['status'] = ConversationStatus::Snoozed;
            $attributes['resolved_at'] = null;
        }

        return $attributes;
    }
}
