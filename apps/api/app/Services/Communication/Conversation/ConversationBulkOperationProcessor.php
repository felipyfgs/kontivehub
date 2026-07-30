<?php

namespace App\Services\Communication\Conversation;

use App\Actions\Communication\AssignCommunicationConversationLabelAction;
use App\Actions\Communication\MarkCommunicationConversationReadAction;
use App\Actions\Communication\MarkCommunicationConversationUnreadAction;
use App\Actions\Communication\RemoveCommunicationConversationLabelAction;
use App\Actions\Communication\UpdateCommunicationConversationAction;
use App\DTO\Communication\CommunicationConversationUpdateData;
use App\Enums\Communication\ConversationBulkAction;
use App\Enums\Communication\ConversationBulkItemStatus;
use App\Enums\Communication\ConversationBulkOperationStatus;
use App\Enums\Communication\ConversationStatus;
use App\Enums\TenantAccessMode;
use App\Exceptions\CommunicationConversationApiException;
use App\Jobs\Communication\ProcessConversationBulkOperationJob;
use App\Models\CommunicationConversation;
use App\Models\CommunicationConversationBulkOperation;
use App\Models\CommunicationConversationBulkOperationItem;
use App\Models\CommunicationInbox;
use App\Models\CommunicationLabel;
use App\Models\Tenant;
use App\Models\TenantMembership;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use App\Support\LogSanitizer;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class ConversationBulkOperationProcessor
{
    private const CHUNK_SIZE = 100;

    public function __construct(
        private CurrentTenant $currentTenant,
        private CommunicationAccess $access,
        private CommunicationConversationCanonicalizer $canonicalizer,
        private UpdateCommunicationConversationAction $updateConversation,
        private AssignCommunicationConversationLabelAction $assignLabel,
        private RemoveCommunicationConversationLabelAction $removeLabel,
        private MarkCommunicationConversationReadAction $markRead,
        private MarkCommunicationConversationUnreadAction $markUnread,
    ) {}

    public function process(int $operationId): void
    {
        if (! $this->acquireOperationLock($operationId)) {
            $this->dispatchContinuation($operationId);

            return;
        }

        $shouldContinue = false;
        try {
            $operation = CommunicationConversationBulkOperation::query()
                ->withoutGlobalScopes()
                ->find($operationId);
            if ($operation === null || $operation->status->isTerminal()) {
                return;
            }

            if (! $this->bindActorContext($operation)) {
                $this->failOperation($operation, 'ACTOR_CONTEXT_UNAVAILABLE', 'Contexto do ator indisponível.');

                return;
            }

            $this->markRunning($operation);

            $items = CommunicationConversationBulkOperationItem::query()
                ->withoutGlobalScopes()
                ->where('bulk_operation_id', $operation->id)
                ->where('status', ConversationBulkItemStatus::Queued)
                ->orderBy('item_index')
                ->limit(self::CHUNK_SIZE)
                ->get();

            foreach ($items as $item) {
                $this->processItem($operation, $item);
            }

            $shouldContinue = $this->finalizeOrContinue($operation);
        } finally {
            $this->currentTenant->clear();
            $this->releaseOperationLock($operationId);
        }

        if ($shouldContinue) {
            $this->dispatchContinuation($operationId);
        }
    }

    public function failOperation(
        CommunicationConversationBulkOperation $operation,
        string $code,
        string $message,
    ): void {
        $hasCompletedItems = (int) $operation->succeeded_count > 0
            || (int) $operation->skipped_count > 0;
        CommunicationConversationBulkOperation::query()
            ->withoutGlobalScopes()
            ->whereKey($operation->id)
            ->whereNotIn('status', [
                ConversationBulkOperationStatus::Completed->value,
                ConversationBulkOperationStatus::CompletedWithErrors->value,
                ConversationBulkOperationStatus::Failed->value,
            ])
            ->update([
                'status' => $hasCompletedItems
                    ? ConversationBulkOperationStatus::CompletedWithErrors->value
                    : ConversationBulkOperationStatus::Failed->value,
                'error_code' => $code,
                'error_message' => $message,
                'completed_at' => now(),
                'updated_at' => now(),
            ]);

        Log::warning('communication.bulk_operation.failed', [
            'operation_id' => $operation->public_id,
            'error_code' => $code,
            'error' => LogSanitizer::scrubString($message),
        ]);
    }

    private function bindActorContext(CommunicationConversationBulkOperation $operation): bool
    {
        $actor = User::query()->find($operation->requested_by_user_id);
        $tenant = Tenant::query()->find($operation->tenant_id);
        if ($actor === null || ! $actor->is_active || $tenant === null || ! $tenant->is_active) {
            return false;
        }

        $accessMode = $operation->access_mode instanceof TenantAccessMode
            ? $operation->access_mode
            : TenantAccessMode::tryFrom((string) $operation->access_mode);

        if ($accessMode === TenantAccessMode::PlatformPrivileged) {
            if (! $actor->isPlatformAdmin() || ! FeatureFlags::isPlatformPrivilegedContextEnabled()) {
                return false;
            }
            $this->currentTenant->bindPlatformPrivileged($actor, $tenant);

            return true;
        }

        if ($operation->requested_by_membership_id === null) {
            return false;
        }

        $membership = TenantMembership::query()
            ->withoutGlobalScopes()
            ->whereKey($operation->requested_by_membership_id)
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $actor->id)
            ->where('is_active', true)
            ->first();

        if ($membership === null) {
            return false;
        }

        $this->currentTenant->bind($actor, $membership);

        return true;
    }

    private function markRunning(CommunicationConversationBulkOperation $operation): void
    {
        if ($operation->status === ConversationBulkOperationStatus::Queued) {
            $operation->forceFill([
                'status' => ConversationBulkOperationStatus::Running,
                'started_at' => $operation->started_at ?? now(),
            ])->save();
        }
    }

    private function processItem(
        CommunicationConversationBulkOperation $operation,
        CommunicationConversationBulkOperationItem $item,
    ): void {
        DB::transaction(function () use ($operation, $item): void {
            $locked = CommunicationConversationBulkOperationItem::query()
                ->withoutGlobalScopes()
                ->whereKey($item->id)
                ->lockForUpdate()
                ->first();
            if ($locked === null || $locked->status->isTerminal()) {
                return;
            }

            $locked->forceFill([
                'status' => ConversationBulkItemStatus::Processing,
                'attempts' => (int) $locked->attempts + 1,
            ])->save();

            try {
                $outcome = DB::transaction(
                    fn (): array => $this->applyMutation($operation, $locked),
                );
                $locked->forceFill([
                    'status' => $outcome['status'],
                    'result_code' => $outcome['result_code'],
                    'result_message' => $outcome['result_message'],
                    'resolved_conversation_id' => $outcome['resolved_conversation_id'],
                    'processed_at' => now(),
                ])->save();
                $this->bumpCounters($operation, $outcome['status']);
            } catch (CommunicationConversationApiException $error) {
                $code = $error->stableCode();
                $locked->forceFill([
                    'status' => ConversationBulkItemStatus::Failed,
                    'result_code' => $this->mapDomainCode($code),
                    'result_message' => $error->safeMessage(),
                    'processed_at' => now(),
                ])->save();
                $this->bumpCounters($operation, ConversationBulkItemStatus::Failed);
            } catch (AuthorizationException) {
                $locked->forceFill([
                    'status' => ConversationBulkItemStatus::Failed,
                    'result_code' => 'AUTHORIZATION_DENIED',
                    'result_message' => 'Permissão revogada ou insuficiente.',
                    'processed_at' => now(),
                ])->save();
                $this->bumpCounters($operation, ConversationBulkItemStatus::Failed);
            } catch (Throwable $error) {
                if ($this->isRetryableConcurrencyError($error)) {
                    throw $error;
                }

                $locked->forceFill([
                    'status' => ConversationBulkItemStatus::Failed,
                    'result_code' => 'PROCESSING_FAILED',
                    'result_message' => 'Falha ao processar item.',
                    'processed_at' => now(),
                ])->save();
                $this->bumpCounters($operation, ConversationBulkItemStatus::Failed);
                Log::warning('communication.bulk_operation.item_failed', [
                    'operation_id' => $operation->public_id,
                    'item_id' => $locked->id,
                    'error' => LogSanitizer::scrubString($error->getMessage()),
                ]);
            }
        }, attempts: 3);
    }

    private function acquireOperationLock(int $operationId): bool
    {
        $keys = $this->operationLockKeys($operationId);
        $result = DB::selectOne(
            'SELECT pg_try_advisory_lock(CAST(? AS INTEGER), CAST(? AS INTEGER)) AS acquired',
            $keys,
            false,
        );

        return ($result->acquired ?? false) === true;
    }

    private function releaseOperationLock(int $operationId): void
    {
        $keys = $this->operationLockKeys($operationId);
        DB::select(
            'SELECT pg_advisory_unlock(CAST(? AS INTEGER), CAST(? AS INTEGER))',
            $keys,
            false,
        );
    }

    /** @return array{int,int} */
    private function operationLockKeys(int $operationId): array
    {
        $digest = hash('sha256', 'communication:conversation-bulk-processor:'.$operationId);

        return [
            $this->signedInt32(substr($digest, 0, 8)),
            $this->signedInt32(substr($digest, 8, 8)),
        ];
    }

    private function signedInt32(string $hex): int
    {
        $value = (int) hexdec($hex);

        return $value > 2_147_483_647 ? $value - 4_294_967_296 : $value;
    }

    private function isRetryableConcurrencyError(Throwable $error): bool
    {
        if (! $error instanceof QueryException) {
            return false;
        }

        return in_array((string) ($error->errorInfo[0] ?? $error->getCode()), ['40001', '40P01'], true);
    }

    /**
     * @return array{
     *     status: ConversationBulkItemStatus,
     *     result_code: string|null,
     *     result_message: string|null,
     *     resolved_conversation_id: int|null
     * }
     */
    private function applyMutation(
        CommunicationConversationBulkOperation $operation,
        CommunicationConversationBulkOperationItem $item,
    ): array {
        $actor = $this->currentTenant->actor();
        if ($actor === null || ! $this->access->canView($actor)) {
            throw new AuthorizationException;
        }

        $conversation = CommunicationConversation::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $operation->tenant_id)
            ->find($item->conversation_id);
        if ($conversation === null) {
            return [
                'status' => ConversationBulkItemStatus::Failed,
                'result_code' => 'CONVERSATION_NOT_FOUND',
                'result_message' => 'Conversa não encontrada.',
                'resolved_conversation_id' => null,
            ];
        }

        $canonical = $this->canonicalizer->conversation($conversation);
        if ($canonical->purged_at !== null) {
            return [
                'status' => ConversationBulkItemStatus::Failed,
                'result_code' => 'CONVERSATION_PURGED',
                'result_message' => 'Conversa removida.',
                'resolved_conversation_id' => (int) $canonical->id,
            ];
        }

        if ($this->alreadySucceededForSurvivor($operation, (int) $canonical->id, (int) $item->id)) {
            return [
                'status' => ConversationBulkItemStatus::Skipped,
                'result_code' => 'DUPLICATE_SURVIVOR',
                'result_message' => 'Survivor já processado nesta operação.',
                'resolved_conversation_id' => (int) $canonical->id,
            ];
        }

        $inbox = CommunicationInbox::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $operation->tenant_id)
            ->find($canonical->inbox_id);
        if ($inbox === null || ! $this->access->canView($actor, $inbox)) {
            throw new AuthorizationException;
        }

        $action = $operation->action instanceof ConversationBulkAction
            ? $operation->action
            : ConversationBulkAction::from((string) $operation->action);

        if ($action->requiresReplyPermission() && ! $this->access->canReply($actor, $inbox)) {
            throw new AuthorizationException;
        }

        $params = is_array($operation->params) ? $operation->params : [];

        return match ($action) {
            ConversationBulkAction::SetStatus,
            ConversationBulkAction::SetAssignee,
            ConversationBulkAction::SetDepartment => $this->applyTriage(
                $action,
                $canonical,
                $item,
                $params,
            ),
            ConversationBulkAction::AddLabels => $this->applyAddLabels($canonical, $params),
            ConversationBulkAction::RemoveLabels => $this->applyRemoveLabels($canonical, $params),
            ConversationBulkAction::MarkRead => $this->applyMarkRead($canonical, $item),
            ConversationBulkAction::MarkUnread => $this->applyMarkUnread($canonical, $item),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{
     *     status: ConversationBulkItemStatus,
     *     result_code: string|null,
     *     result_message: string|null,
     *     resolved_conversation_id: int|null
     * }
     */
    private function applyTriage(
        ConversationBulkAction $action,
        CommunicationConversation $conversation,
        CommunicationConversationBulkOperationItem $item,
        array $params,
    ): array {
        $lockVersion = (int) ($item->lock_version ?? 0);
        $data = match ($action) {
            ConversationBulkAction::SetStatus => new CommunicationConversationUpdateData(
                lockVersion: $lockVersion,
                status: ConversationStatus::from((string) $params['status']),
                hasStatus: true,
                assigneeMembershipId: null,
                hasAssigneeMembershipId: false,
                workDepartmentId: null,
                hasWorkDepartmentId: false,
                priority: null,
                hasPriority: false,
                snoozedUntil: isset($params['snoozed_until']) ? (string) $params['snoozed_until'] : null,
                hasSnoozedUntil: array_key_exists('snoozed_until', $params),
            ),
            ConversationBulkAction::SetAssignee => new CommunicationConversationUpdateData(
                lockVersion: $lockVersion,
                status: null,
                hasStatus: false,
                assigneeMembershipId: array_key_exists('assignee_membership_id', $params)
                    ? ($params['assignee_membership_id'] !== null
                        ? (int) $params['assignee_membership_id']
                        : null)
                    : null,
                hasAssigneeMembershipId: true,
                workDepartmentId: null,
                hasWorkDepartmentId: false,
                priority: null,
                hasPriority: false,
                snoozedUntil: null,
                hasSnoozedUntil: false,
            ),
            ConversationBulkAction::SetDepartment => new CommunicationConversationUpdateData(
                lockVersion: $lockVersion,
                status: null,
                hasStatus: false,
                assigneeMembershipId: null,
                hasAssigneeMembershipId: false,
                workDepartmentId: array_key_exists('work_department_id', $params)
                    ? ($params['work_department_id'] !== null
                        ? (int) $params['work_department_id']
                        : null)
                    : null,
                hasWorkDepartmentId: true,
                priority: null,
                hasPriority: false,
                snoozedUntil: null,
                hasSnoozedUntil: false,
            ),
            default => throw new \LogicException('Ação de triagem inválida.'),
        };

        $this->updateConversation->handle($conversation, $data);

        return [
            'status' => ConversationBulkItemStatus::Succeeded,
            'result_code' => 'SUCCEEDED',
            'result_message' => null,
            'resolved_conversation_id' => (int) $conversation->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{
     *     status: ConversationBulkItemStatus,
     *     result_code: string|null,
     *     result_message: string|null,
     *     resolved_conversation_id: int|null
     * }
     */
    private function applyAddLabels(CommunicationConversation $conversation, array $params): array
    {
        $labelIds = array_map(static fn ($id): int => (int) $id, $params['label_ids'] ?? []);
        $existing = $conversation->labels()->pluck('communication_labels.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $toAdd = array_values(array_diff($labelIds, $existing));
        if ($toAdd === []) {
            return [
                'status' => ConversationBulkItemStatus::Skipped,
                'result_code' => 'NO_OP',
                'result_message' => 'Rótulos já atribuídos.',
                'resolved_conversation_id' => (int) $conversation->id,
            ];
        }

        foreach ($toAdd as $labelId) {
            $label = CommunicationLabel::query()->findOrFail($labelId);
            $this->assignLabel->handle($conversation, $label);
        }

        return [
            'status' => ConversationBulkItemStatus::Succeeded,
            'result_code' => 'SUCCEEDED',
            'result_message' => null,
            'resolved_conversation_id' => (int) $conversation->id,
        ];
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array{
     *     status: ConversationBulkItemStatus,
     *     result_code: string|null,
     *     result_message: string|null,
     *     resolved_conversation_id: int|null
     * }
     */
    private function applyRemoveLabels(CommunicationConversation $conversation, array $params): array
    {
        $labelIds = array_map(static fn ($id): int => (int) $id, $params['label_ids'] ?? []);
        $existing = $conversation->labels()->pluck('communication_labels.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $toRemove = array_values(array_intersect($labelIds, $existing));
        if ($toRemove === []) {
            return [
                'status' => ConversationBulkItemStatus::Skipped,
                'result_code' => 'NO_OP',
                'result_message' => 'Rótulos já ausentes.',
                'resolved_conversation_id' => (int) $conversation->id,
            ];
        }

        foreach ($toRemove as $labelId) {
            $label = CommunicationLabel::query()->findOrFail($labelId);
            $this->removeLabel->handle($conversation, $label);
        }

        return [
            'status' => ConversationBulkItemStatus::Succeeded,
            'result_code' => 'SUCCEEDED',
            'result_message' => null,
            'resolved_conversation_id' => (int) $conversation->id,
        ];
    }

    /**
     * @return array{
     *     status: ConversationBulkItemStatus,
     *     result_code: string|null,
     *     result_message: string|null,
     *     resolved_conversation_id: int|null
     * }
     */
    private function applyMarkRead(
        CommunicationConversation $conversation,
        CommunicationConversationBulkOperationItem $item,
    ): array {
        $this->markRead->handle($conversation, (int) $item->through_message_id);

        return [
            'status' => ConversationBulkItemStatus::Succeeded,
            'result_code' => 'SUCCEEDED',
            'result_message' => null,
            'resolved_conversation_id' => (int) $conversation->id,
        ];
    }

    /**
     * @return array{
     *     status: ConversationBulkItemStatus,
     *     result_code: string|null,
     *     result_message: string|null,
     *     resolved_conversation_id: int|null
     * }
     */
    private function applyMarkUnread(
        CommunicationConversation $conversation,
        CommunicationConversationBulkOperationItem $item,
    ): array {
        $this->markUnread->handle($conversation, (int) $item->read_state_version);

        return [
            'status' => ConversationBulkItemStatus::Succeeded,
            'result_code' => 'SUCCEEDED',
            'result_message' => null,
            'resolved_conversation_id' => (int) $conversation->id,
        ];
    }

    private function alreadySucceededForSurvivor(
        CommunicationConversationBulkOperation $operation,
        int $survivorId,
        int $currentItemId,
    ): bool {
        return CommunicationConversationBulkOperationItem::query()
            ->withoutGlobalScopes()
            ->where('bulk_operation_id', $operation->id)
            ->where('id', '!=', $currentItemId)
            ->where('resolved_conversation_id', $survivorId)
            ->where('status', ConversationBulkItemStatus::Succeeded)
            ->exists();
    }

    private function bumpCounters(
        CommunicationConversationBulkOperation $operation,
        ConversationBulkItemStatus $status,
    ): void {
        $column = match ($status) {
            ConversationBulkItemStatus::Succeeded => 'succeeded_count',
            ConversationBulkItemStatus::Skipped => 'skipped_count',
            ConversationBulkItemStatus::Failed => 'failed_count',
            default => null,
        };
        if ($column === null) {
            return;
        }

        CommunicationConversationBulkOperation::query()
            ->withoutGlobalScopes()
            ->whereKey($operation->id)
            ->increment($column);
    }

    private function finalizeOrContinue(CommunicationConversationBulkOperation $operation): bool
    {
        $hasQueued = CommunicationConversationBulkOperationItem::query()
            ->withoutGlobalScopes()
            ->where('bulk_operation_id', $operation->id)
            ->where('status', ConversationBulkItemStatus::Queued->value)
            ->exists();
        if ($hasQueued) {
            return true;
        }

        $hasProcessing = CommunicationConversationBulkOperationItem::query()
            ->withoutGlobalScopes()
            ->where('bulk_operation_id', $operation->id)
            ->where('status', ConversationBulkItemStatus::Processing->value)
            ->exists();
        if ($hasProcessing) {
            return false;
        }

        $fresh = CommunicationConversationBulkOperation::query()
            ->withoutGlobalScopes()
            ->find($operation->id);
        if ($fresh === null || $fresh->status->isTerminal()) {
            return false;
        }

        $failed = (int) $fresh->failed_count;
        $succeeded = (int) $fresh->succeeded_count;
        $skipped = (int) $fresh->skipped_count;
        $processed = $failed + $succeeded + $skipped;

        if ($processed === 0) {
            $status = ConversationBulkOperationStatus::Failed;
        } elseif ($failed > 0 && ($succeeded > 0 || $skipped > 0)) {
            $status = ConversationBulkOperationStatus::CompletedWithErrors;
        } elseif ($failed > 0 && $succeeded === 0 && $skipped === 0) {
            $status = ConversationBulkOperationStatus::Failed;
        } else {
            $status = ConversationBulkOperationStatus::Completed;
        }

        $fresh->forceFill([
            'status' => $status,
            'completed_at' => now(),
        ])->save();

        return false;
    }

    private function dispatchContinuation(int $operationId): void
    {
        ProcessConversationBulkOperationJob::dispatch($operationId)
            ->delay(now()->addSeconds(2));
    }

    private function mapDomainCode(string $code): string
    {
        return match ($code) {
            'version_conflict' => 'VERSION_CONFLICT',
            'READ_STATE_VERSION_CONFLICT' => 'READ_STATE_VERSION_CONFLICT',
            'conversation_purged' => 'CONVERSATION_PURGED',
            'snoozed_until_required' => 'SNOOZED_UNTIL_REQUIRED',
            'assignee_inbox_access_required' => 'ASSIGNEE_INBOX_ACCESS_REQUIRED',
            default => strtoupper(str_replace(['.', ' '], '_', $code)),
        };
    }
}
