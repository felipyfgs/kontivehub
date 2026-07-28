<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationContactPurgeResult;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\FlowRunStatus;
use App\Jobs\Communication\DeleteCommunicationMediaObjectJob;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationMessage;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Media\CommunicationMediaStore;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PurgeCommunicationContactAction
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly CommunicationMediaStore $media,
        private readonly CommunicationEventRecorder $events,
    ) {}

    public function execute(CommunicationContact $contact): CommunicationContactPurgeResult
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $contactId = (int) $contact->id;
        $now = now();
        $tombstone = hash(
            'sha256',
            'communication-contact-purge|'.$contactId.'|'.random_bytes(32),
        );

        DB::transaction(function () use ($tenantId, $contactId, $now, $tombstone): void {
            $lockedContact = CommunicationContact::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($contactId)
                ->lockForUpdate()
                ->firstOrFail();

            CommunicationIdentity::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('contact_id', $contactId)
                ->orderBy('id')
                ->lockForUpdate()
                ->lazyById(100)
                ->each(function (CommunicationIdentity $identity) use ($now, $tombstone): void {
                    $identity->forceFill([
                        'address_encrypted' => null,
                        'address_hash' => hash(
                            'sha256',
                            'purged-identity|'.$identity->id.'|'.$tombstone,
                        ),
                        'address_masked' => '[removido]',
                        'is_active' => false,
                        'purged_at' => $now,
                    ])->save();
                });

            CommunicationConversation::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('identity_id', $this->identityIds($tenantId, $contactId))
                ->update([
                    'status' => ConversationStatus::Resolved,
                    'snoozed_until' => null,
                    'resolved_at' => $now,
                    'purged_at' => $now,
                    'tombstone_digest' => $tombstone,
                    'lock_version' => DB::raw('lock_version + 1'),
                    'updated_at' => $now,
                ]);

            CommunicationAttachment::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('message_id', $this->messageIds($tenantId, $contactId))
                ->update([
                    'original_name_encrypted' => null,
                    'purged_at' => $now,
                    'updated_at' => $now,
                ]);
            CommunicationMessage::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('conversation_id', $this->conversationIds($tenantId, $contactId))
                ->update([
                    'body_encrypted' => null,
                    'content_encrypted' => null,
                    'metadata' => null,
                    'content_digest' => $tombstone,
                    'purged_at' => $now,
                    'updated_at' => $now,
                ]);

            $runIds = $this->flowRunIds($tenantId, $contactId);
            DB::table('communication_flow_run_steps')
                ->where('tenant_id', $tenantId)
                ->whereIn('run_id', clone $runIds)
                ->update([
                    'result_meta' => null,
                    'updated_at' => $now,
                ]);
            CommunicationFlowRun::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', clone $runIds)
                ->update([
                    'context_encrypted' => null,
                    'updated_at' => $now,
                ]);
            CommunicationFlowRun::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $runIds)
                ->whereIn('status', FlowRunStatus::nonTerminalValues())
                ->update([
                    'status' => FlowRunStatus::Purged,
                    'finished_at' => $now,
                    'waiting_until' => null,
                    'waiting_effect_key' => null,
                    'waiting_outbox_entry_id' => null,
                    'updated_at' => $now,
                ]);

            $lockedContact->forceFill([
                'name' => null,
                'metadata' => null,
                'is_provisional' => false,
                'is_active' => false,
                'purged_at' => $now,
            ])->save();
            $this->events->record(
                $tenantId,
                'CONTACT_PURGED',
                [
                    'contact_id' => $contactId,
                    'tombstone_digest' => $tombstone,
                ],
                actorMembershipId: $this->currentTenant->realMembership()?->id,
            );
        });

        $deletedBlobs = 0;
        CommunicationAttachment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('message_id', $this->messageIds($tenantId, $contactId))
            ->select(['id', 'object_id'])
            ->orderBy('id')
            ->lazyById(100)
            ->each(function (CommunicationAttachment $attachment) use (&$deletedBlobs): void {
                $objectId = (string) $attachment->object_id;
                try {
                    $exists = $this->media->exists($objectId);
                    $this->media->delete($objectId);
                    if ($exists) {
                        $deletedBlobs++;
                    }
                } catch (Throwable $error) {
                    report($error);
                    DeleteCommunicationMediaObjectJob::dispatch($objectId);
                }
            });

        return new CommunicationContactPurgeResult(
            contactId: $contactId,
            purgedAt: $now->toIso8601String(),
            deletedBlobs: $deletedBlobs,
            tombstoneDigest: $tombstone,
        );
    }

    /** @return Builder<CommunicationIdentity> */
    private function identityIds(int $tenantId, int $contactId): Builder
    {
        return CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->select('id');
    }

    /** @return Builder<CommunicationConversation> */
    private function conversationIds(int $tenantId, int $contactId): Builder
    {
        return CommunicationConversation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('identity_id', $this->identityIds($tenantId, $contactId))
            ->select('id');
    }

    /** @return Builder<CommunicationMessage> */
    private function messageIds(int $tenantId, int $contactId): Builder
    {
        return CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('conversation_id', $this->conversationIds($tenantId, $contactId))
            ->select('id');
    }

    /** @return Builder<CommunicationFlowRun> */
    private function flowRunIds(int $tenantId, int $contactId): Builder
    {
        return CommunicationFlowRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('conversation_id', $this->conversationIds($tenantId, $contactId))
            ->select('id');
    }
}
