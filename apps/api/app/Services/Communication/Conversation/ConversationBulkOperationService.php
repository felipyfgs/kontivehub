<?php

namespace App\Services\Communication\Conversation;

use App\DTO\Communication\ConversationBulkOperationAdmissionData;
use App\DTO\Communication\ConversationBulkOperationAdmissionResult;
use App\Enums\Communication\ConversationBulkAction;
use App\Enums\Communication\ConversationBulkItemStatus;
use App\Enums\Communication\ConversationBulkOperationStatus;
use App\Enums\TenantAccessMode;
use App\Exceptions\CommunicationConversationBulkApiException;
use App\Jobs\Communication\ProcessConversationBulkOperationJob;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationBulkOperation;
use App\Models\CommunicationConversationBulkOperationItem;
use App\Models\CommunicationInbox;
use App\Models\CommunicationLabel;
use App\Models\CommunicationMessage;
use App\Models\User;
use App\Services\Communication\Authorization\Access;
use App\Services\Communication\ConversationCanonicalizer;
use App\Support\CurrentTenant;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class ConversationBulkOperationService
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private Access $access,
        private ConversationCanonicalizer $canonicalizer,
    ) {}

    public function admit(ConversationBulkOperationAdmissionData $data): ConversationBulkOperationAdmissionResult
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $digest = $this->payloadDigest($data);
        $existing = CommunicationConversationBulkOperation::query()
            ->where('tenant_id', $tenantId)
            ->where('idempotency_key', $data->idempotencyKey)
            ->first();

        if ($existing !== null) {
            if ((int) $existing->requested_by_user_id !== (int) $data->actor->id
                || ! hash_equals((string) $existing->payload_digest, $digest)) {
                throw CommunicationConversationBulkApiException::idempotencyKeyReused();
            }

            return new ConversationBulkOperationAdmissionResult($existing, false);
        }

        $this->assertActorMayCreate($data->actor, $data->action);
        $resolvedItems = $this->resolveAndValidateItems($data);

        /** @var array{operation: CommunicationConversationBulkOperation, created: bool} $result */
        $result = DB::transaction(function () use (
            $tenantId,
            $data,
            $digest,
            $resolvedItems,
        ): array {
            $this->lockIdempotencyKey($tenantId, $data->idempotencyKey);

            $again = CommunicationConversationBulkOperation::query()
                ->where('tenant_id', $tenantId)
                ->where('idempotency_key', $data->idempotencyKey)
                ->lockForUpdate()
                ->first();
            if ($again !== null) {
                if ((int) $again->requested_by_user_id !== (int) $data->actor->id
                    || ! hash_equals((string) $again->payload_digest, $digest)) {
                    throw CommunicationConversationBulkApiException::idempotencyKeyReused();
                }

                return ['operation' => $again, 'created' => false];
            }

            $operation = new CommunicationConversationBulkOperation;
            $operation->forceFill([
                'public_id' => (string) Str::uuid(),
                'tenant_id' => $tenantId,
                'requested_by_user_id' => $data->actor->id,
                'requested_by_membership_id' => $this->currentTenant->realMembership()?->id,
                'access_mode' => $this->currentTenant->accessMode() ?? TenantAccessMode::Membership,
                'idempotency_key' => $data->idempotencyKey,
                'payload_digest' => $digest,
                'action' => $data->action,
                'params' => $data->params,
                'status' => ConversationBulkOperationStatus::Queued,
                'item_count' => count($resolvedItems),
                'queued_at' => now(),
            ])->save();

            foreach (array_chunk($resolvedItems, 100) as $chunkIndex => $chunk) {
                $rows = [];
                foreach ($chunk as $offset => $item) {
                    $rows[] = [
                        'tenant_id' => $tenantId,
                        'bulk_operation_id' => $operation->id,
                        'item_index' => ($chunkIndex * 100) + $offset,
                        'conversation_id' => $item['conversation_id'],
                        'live_conversation_id' => $item['conversation_id'],
                        'resolved_conversation_id' => null,
                        'inbox_id' => $item['inbox_id'],
                        'live_inbox_id' => $item['inbox_id'],
                        'lock_version' => $item['lock_version'],
                        'through_message_id' => $item['through_message_id'],
                        'read_state_version' => $item['read_state_version'],
                        'status' => ConversationBulkItemStatus::Queued->value,
                        'attempts' => 0,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ];
                }
                CommunicationConversationBulkOperationItem::query()->insert($rows);
            }

            return ['operation' => $operation, 'created' => true];
        });

        if ($result['created']) {
            ProcessConversationBulkOperationJob::dispatch((int) $result['operation']->id)
                ->afterCommit();
        }

        return new ConversationBulkOperationAdmissionResult(
            $result['operation']->fresh() ?? $result['operation'],
            $result['created'],
        );
    }

    private function lockIdempotencyKey(int $tenantId, string $idempotencyKey): void
    {
        $digest = hash('sha256', 'communication:conversation-bulk:'.$tenantId.':'.$idempotencyKey);
        DB::select('SELECT pg_advisory_xact_lock(CAST(? AS INTEGER), CAST(? AS INTEGER))', [
            $this->signedInt32(substr($digest, 0, 8)),
            $this->signedInt32(substr($digest, 8, 8)),
        ]);
    }

    private function signedInt32(string $hex): int
    {
        $value = (int) hexdec($hex);

        return $value > 2_147_483_647 ? $value - 4_294_967_296 : $value;
    }

    public function findForActor(User $actor, string $publicId): CommunicationConversationBulkOperation
    {
        $tenantId = (int) $this->currentTenant->tenant()->id;
        $operation = CommunicationConversationBulkOperation::query()
            ->where('tenant_id', $tenantId)
            ->where('public_id', $publicId)
            ->first();

        if ($operation === null) {
            throw CommunicationConversationBulkApiException::operationNotFound();
        }

        $isRequester = (int) $operation->requested_by_user_id === (int) $actor->id;
        if (! $isRequester && ! $this->access->canManage($actor)) {
            throw CommunicationConversationBulkApiException::unauthorized();
        }

        return $operation;
    }

    private function assertActorMayCreate(User $actor, ConversationBulkAction $action): void
    {
        if (! $this->access->canView($actor)) {
            throw CommunicationConversationBulkApiException::unauthorized();
        }

        if ($action->requiresReplyPermission() && ! $this->actorHasAnyReplyAccess($actor)) {
            throw CommunicationConversationBulkApiException::unauthorized();
        }
    }

    private function actorHasAnyReplyAccess(User $actor): bool
    {
        if ($this->access->canManage($actor)) {
            return true;
        }

        $inboxIds = $this->access->visibleInboxIds($actor);
        $inboxes = CommunicationInbox::query()->whereIn('id', $inboxIds)->get();
        foreach ($inboxes as $inbox) {
            if ($this->access->canReply($actor, $inbox)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{
     *     conversation_id: int,
     *     inbox_id: int,
     *     lock_version: int|null,
     *     through_message_id: int|null,
     *     read_state_version: int|null
     * }>
     */
    private function resolveAndValidateItems(ConversationBulkOperationAdmissionData $data): array
    {
        $visibleInboxIds = $this->access->visibleInboxIds($data->actor);
        $conversationIds = array_map(
            static fn (array $item): int => (int) $item['conversation_id'],
            $data->items,
        );

        $conversations = CommunicationConversation::query()
            ->whereIn('id', $conversationIds)
            ->get()
            ->keyBy('id');
        $inboxes = CommunicationInbox::query()
            ->whereIn('id', $visibleInboxIds)
            ->get()
            ->keyBy('id');

        if ($conversations->count() !== count(array_unique($conversationIds))) {
            throw CommunicationConversationBulkApiException::invalidItems();
        }

        if ($data->action === ConversationBulkAction::AddLabels
            || $data->action === ConversationBulkAction::RemoveLabels) {
            $this->assertLabelsBelongToTenant($data->params['label_ids'] ?? []);
        }

        $resolved = [];
        foreach ($data->items as $item) {
            $conversationId = (int) $item['conversation_id'];
            /** @var CommunicationConversation|null $conversation */
            $conversation = $conversations->get($conversationId);
            if ($conversation === null || $conversation->purged_at !== null) {
                throw CommunicationConversationBulkApiException::invalidItems();
            }

            $canonical = $this->canonicalizer->conversation($conversation);
            if ($canonical->purged_at !== null) {
                throw CommunicationConversationBulkApiException::invalidItems();
            }

            $inboxId = (int) $canonical->inbox_id;
            if (! in_array($inboxId, $visibleInboxIds, true)) {
                throw CommunicationConversationBulkApiException::invalidItems();
            }

            $inbox = $inboxes->get($inboxId);
            if ($inbox === null || ! $this->access->canView($data->actor, $inbox)) {
                throw CommunicationConversationBulkApiException::invalidItems();
            }

            if ($data->action->requiresReplyPermission()
                && ! $this->access->canReply($data->actor, $inbox)) {
                throw CommunicationConversationBulkApiException::invalidItems();
            }

            if ($data->action === ConversationBulkAction::MarkRead) {
                $throughMessageId = (int) ($item['through_message_id'] ?? 0);
                $messageExists = CommunicationMessage::query()
                    ->whereKey($throughMessageId)
                    ->where(static fn ($query) => $query
                        ->where('conversation_id', $canonical->id)
                        ->orWhere('conversation_id', $conversationId))
                    ->exists();
                if (! $messageExists) {
                    throw CommunicationConversationBulkApiException::invalidItems();
                }
            }
            if ($data->action === ConversationBulkAction::MarkUnread
                && ! array_key_exists('read_state_version', $item)) {
                throw CommunicationConversationBulkApiException::invalidItems();
            }

            $resolved[] = [
                'conversation_id' => $conversationId,
                'inbox_id' => $inboxId,
                'lock_version' => isset($item['lock_version']) ? (int) $item['lock_version'] : null,
                'through_message_id' => isset($item['through_message_id'])
                    ? (int) $item['through_message_id']
                    : null,
                'read_state_version' => array_key_exists('read_state_version', $item)
                    ? (int) $item['read_state_version']
                    : null,
            ];
        }

        return $resolved;
    }

    /** @param list<int>|array<int, mixed> $labelIds */
    private function assertLabelsBelongToTenant(array $labelIds): void
    {
        $ids = array_values(array_unique(array_map(static fn ($id): int => (int) $id, $labelIds)));
        if ($ids === []) {
            throw CommunicationConversationBulkApiException::invalidParams();
        }

        $count = CommunicationLabel::query()->whereIn('id', $ids)->count();
        if ($count !== count($ids)) {
            throw CommunicationConversationBulkApiException::invalidItems();
        }
    }

    private function payloadDigest(ConversationBulkOperationAdmissionData $data): string
    {
        $items = $data->items;
        usort(
            $items,
            static fn (array $a, array $b): int => $a['conversation_id'] <=> $b['conversation_id'],
        );

        return hash('sha256', json_encode($this->canonicalize([
            'action' => $data->action->value,
            'params' => $data->params,
            'items' => $items,
        ]), JSON_THROW_ON_ERROR));
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $value = array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        if (! array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
