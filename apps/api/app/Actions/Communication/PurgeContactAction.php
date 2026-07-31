<?php

namespace App\Actions\Communication;

use App\DTO\Communication\ContactPurgeResult;
use App\Enums\Communication\ConversationStatus;
use App\Enums\Communication\FlowRunStatus;
use App\Enums\Communication\OutboxStatus;
use App\Models\CommunicationAttachment;
use App\Models\CommunicationContact;
use App\Models\CommunicationConversation;
use App\Models\CommunicationFlowRun;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationMessage;
use App\Services\Communication\ContactCanonicalizer;
use App\Services\Communication\Conversation\ConversationReadStateService;
use App\Services\Communication\Events\EventRecorder;
use App\Services\Communication\Media\MediaDeletionService;
use App\Services\Communication\Media\MediaStore;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Throwable;

final class PurgeContactAction
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly MediaStore $media,
        private readonly MediaDeletionService $deletions,
        private readonly EventRecorder $events,
        private readonly ContactCanonicalizer $canonicalizer,
        private readonly ConversationReadStateService $readState,
    ) {}

    public function execute(CommunicationContact $contact): ContactPurgeResult
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $contactId = (int) $contact->id;
        $contactIds = [$contactId];
        $profileObjectIds = [];
        $now = now();
        $tombstone = hash(
            'sha256',
            'communication-contact-purge|'.$contactId.'|'.random_bytes(32),
        );

        DB::transaction(function () use (
            $contact,
            $tenantId,
            &$contactId,
            &$contactIds,
            &$profileObjectIds,
            $now,
            $tombstone,
        ): void {
            [$lockedContact, $contactIds] = $this->canonicalizer->lockContactClass($contact);
            $contactId = (int) $lockedContact->id;
            $identities = CommunicationIdentity::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('contact_id', $contactIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $identityIds = $identities
                ->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();
            $conversations = CommunicationConversation::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('identity_id', $identityIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $conversationIds = $conversations->pluck('id')
                ->map(static fn ($id): int => (int) $id)
                ->all();

            $profileObjectIds = DB::table('communication_inbox_identity_profiles')
                ->where('tenant_id', $tenantId)
                ->whereIn('identity_id', $identityIds)
                ->whereNotNull('profile_picture_object_id')
                ->pluck('profile_picture_object_id')
                ->filter()
                ->map(static fn ($id): string => (string) $id)
                ->all();
            DB::table('communication_inbox_identity_profiles')
                ->where('tenant_id', $tenantId)
                ->whereIn('identity_id', $identityIds)
                ->delete();
            foreach ($profileObjectIds as $objectId) {
                $this->deletions->request($objectId, $tenantId);
            }
            $conversations->each(fn (CommunicationConversation $conversation) => $this->readState->purge($conversation));

            $identities->each(function (CommunicationIdentity $identity) use ($now, $tombstone): void {
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
                ->whereIn('id', $conversationIds)
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
                ->whereIn('message_id', $this->messageIds($tenantId, $contactIds))
                ->update([
                    'original_name_encrypted' => null,
                    'purged_at' => $now,
                    'updated_at' => $now,
                ]);
            CommunicationMessage::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('conversation_id', $this->conversationIds($tenantId, $contactIds))
                ->update([
                    'body_encrypted' => null,
                    'content_encrypted' => null,
                    'metadata' => null,
                    'content_digest' => $tombstone,
                    'purged_at' => $now,
                    'updated_at' => $now,
                ]);
            DB::table('communication_outbox_entries')
                ->where('tenant_id', $tenantId)
                ->whereIn('message_id', $this->messageIds($tenantId, $contactIds))
                ->whereIn('status', [OutboxStatus::Pending->value, OutboxStatus::Retry->value])
                ->update([
                    'status' => OutboxStatus::Canceled->value,
                    'locked_at' => null,
                    'last_error_code' => 'CONTACT_PURGED',
                    'last_error_message' => null,
                    'updated_at' => $now,
                ]);

            $runIds = $this->flowRunIds($tenantId, $contactIds);
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

            CommunicationContact::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereIn('id', $contactIds)
                ->update([
                    'name' => null,
                    'metadata' => null,
                    'is_provisional' => false,
                    'is_active' => false,
                    'purged_at' => $now,
                    'updated_at' => $now,
                ]);
            $this->events->record(
                $tenantId,
                'CONTACT_PURGED',
                [
                    'contact_id' => $contactId,
                    'tombstone_digest' => $tombstone,
                ],
                actorMembershipId: $this->currentTenant->realMembership()?->id,
            );
        }, 3);

        $deletedBlobs = 0;
        CommunicationAttachment::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('message_id', $this->messageIds($tenantId, $contactIds))
            ->select(['id', 'object_id'])
            ->orderBy('id')
            ->lazyById(100)
            ->each(function (CommunicationAttachment $attachment) use (&$deletedBlobs, $tenantId): void {
                $objectId = (string) $attachment->object_id;
                if (preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/i', $objectId) !== 1) {
                    return;
                }
                try {
                    $exists = $this->media->exists($objectId);
                    $this->media->delete($objectId);
                    if ($exists) {
                        $deletedBlobs++;
                    }
                } catch (Throwable $error) {
                    report($error);
                    $this->deletions->request($objectId, $tenantId);
                }
            });

        return new ContactPurgeResult(
            contactId: $contactId,
            purgedAt: $now->toIso8601String(),
            deletedBlobs: $deletedBlobs,
            tombstoneDigest: $tombstone,
        );
    }

    /** @return Builder<CommunicationIdentity> */
    private function identityIds(int $tenantId, array $contactIds): Builder
    {
        return CommunicationIdentity::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('contact_id', $contactIds)
            ->select('id');
    }

    /** @return Builder<CommunicationConversation> */
    private function conversationIds(int $tenantId, array $contactIds): Builder
    {
        return CommunicationConversation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('identity_id', $this->identityIds($tenantId, $contactIds))
            ->select('id');
    }

    /** @return Builder<CommunicationMessage> */
    private function messageIds(int $tenantId, array $contactIds): Builder
    {
        return CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('conversation_id', $this->conversationIds($tenantId, $contactIds))
            ->select('id');
    }

    /** @return Builder<CommunicationFlowRun> */
    private function flowRunIds(int $tenantId, array $contactIds): Builder
    {
        return CommunicationFlowRun::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('conversation_id', $this->conversationIds($tenantId, $contactIds))
            ->select('id');
    }
}
