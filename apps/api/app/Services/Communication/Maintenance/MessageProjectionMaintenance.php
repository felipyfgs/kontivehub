<?php

namespace App\Services\Communication\Maintenance;

use App\DTO\Communication\MaintenanceContext;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\MessageSource;
use App\Models\CommunicationConversation;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class MessageProjectionMaintenance
{
    public const QUARANTINE_REASON = 'WHATSAPP_PROTOCOL_CONTROL';

    private const MAX_ROWS = 500;

    public function __construct(private AuditLogger $audit) {}

    /** @return array<string,mixed> */
    public function run(
        MaintenanceContext $context,
        ?string $reverseOperationId = null,
    ): array {
        $this->assertTrustedScope($context);
        if ($reverseOperationId !== null) {
            return $this->reverse($context, $reverseOperationId);
        }

        $ids = $this->candidateQuery($context)
            ->orderBy('id')
            ->limit(self::MAX_ROWS + 1)
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
        $truncated = count($ids) > self::MAX_ROWS;
        $ids = array_slice($ids, 0, self::MAX_ROWS);
        if ($context->execute && $ids !== []) {
            DB::transaction(function () use ($context, &$ids): void {
                $rows = $this->candidateQuery($context)
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $ids = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                if ($ids === []) {
                    return;
                }
                CommunicationMessage::query()->withoutGlobalScopes()
                    ->where('tenant_id', $context->tenantId)
                    ->where('inbox_id', $context->inboxId)
                    ->whereIn('id', $ids)
                    ->update([
                        'quarantined_at' => now(),
                        'quarantine_reason' => self::QUARANTINE_REASON,
                        'quarantine_operation_id' => $context->operationId,
                        'updated_at' => now(),
                    ]);
                $this->recomputeConversationActivity($context->tenantId, $rows->pluck('conversation_id')->all());
            }, 3);
        }

        $result = [
            'mode' => $context->execute ? 'execute' : 'dry-run',
            'operation_id' => $context->operationId,
            'reason' => self::QUARANTINE_REASON,
            'eligible_count' => count($ids),
            'message_ids' => $ids,
            'truncated' => $truncated,
        ];
        $this->audit($context, 'communication.message_projection.quarantine', $result);

        return $result;
    }

    /** @return Builder<CommunicationMessage> */
    private function candidateQuery(MaintenanceContext $context): Builder
    {
        return CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('inbox_id', $context->inboxId)
            ->where('source', MessageSource::Gateway->value)
            ->where('kind', MessageKind::Unsupported->value)
            ->whereRaw('LOWER(provider_type) = ?', ['protocolmessage'])
            ->whereNull('body_encrypted')
            ->whereNull('content_encrypted')
            ->whereNull('quarantined_at')
            ->whereDoesntHave('attachments', fn (Builder $attachments) => $attachments->withoutGlobalScopes());
    }

    /** @return array<string,mixed> */
    private function reverse(MaintenanceContext $context, string $operationId): array
    {
        if (preg_match('/^[A-Za-z0-9][A-Za-z0-9._:-]{7,63}$/', $operationId) !== 1) {
            throw new RuntimeException('QUARANTINE_OPERATION_INVALID');
        }
        $query = CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('inbox_id', $context->inboxId)
            ->where('quarantine_reason', self::QUARANTINE_REASON)
            ->where('quarantine_operation_id', $operationId);
        $ids = (clone $query)->orderBy('id')->limit(self::MAX_ROWS + 1)->pluck('id')
            ->map(static fn ($id): int => (int) $id)->all();
        $truncated = count($ids) > self::MAX_ROWS;
        $ids = array_slice($ids, 0, self::MAX_ROWS);
        if ($context->execute && $ids !== []) {
            DB::transaction(function () use ($context, $operationId, &$ids): void {
                $rows = CommunicationMessage::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $context->tenantId)
                    ->where('inbox_id', $context->inboxId)
                    ->where('quarantine_reason', self::QUARANTINE_REASON)
                    ->where('quarantine_operation_id', $operationId)
                    ->whereIn('id', $ids)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();
                $ids = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
                if ($ids === []) {
                    return;
                }
                CommunicationMessage::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $context->tenantId)
                    ->where('inbox_id', $context->inboxId)
                    ->whereIn('id', $ids)
                    ->update([
                        'quarantined_at' => null,
                        'quarantine_reason' => null,
                        'quarantine_operation_id' => null,
                        'updated_at' => now(),
                    ]);
                $this->recomputeConversationActivity($context->tenantId, $rows->pluck('conversation_id')->all());
            }, 3);
        }

        $result = [
            'mode' => $context->execute ? 'execute' : 'dry-run',
            'operation_id' => $context->operationId,
            'reversed_operation_id' => $operationId,
            'eligible_count' => count($ids),
            'message_ids' => $ids,
            'truncated' => $truncated,
        ];
        $this->audit($context, 'communication.message_projection.quarantine_reversed', $result);

        return $result;
    }

    /** @param array<int,mixed> $conversationIds */
    private function recomputeConversationActivity(int $tenantId, array $conversationIds): void
    {
        $ordered = array_values(array_unique(array_map('intval', $conversationIds)));
        sort($ordered, SORT_NUMERIC);
        foreach ($ordered as $conversationId) {
            $conversation = CommunicationConversation::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($conversationId)
                ->lockForUpdate()
                ->first();
            if ($conversation === null) {
                continue;
            }
            $latest = CommunicationMessage::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('conversation_id', $conversationId)
                ->visibleToWorkspace()
                ->max('occurred_at');
            $conversation->forceFill([
                'last_message_at' => $latest,
                'lock_version' => (int) $conversation->lock_version + 1,
            ])->save();
        }
    }

    private function assertTrustedScope(MaintenanceContext $context): void
    {
        if (! Tenant::query()->withoutGlobalScopes()->whereKey($context->tenantId)->where('is_active', true)->exists()
            || ! CommunicationInbox::query()->withoutGlobalScopes()
                ->where('tenant_id', $context->tenantId)
                ->whereKey($context->inboxId)
                ->exists()) {
            throw new RuntimeException('MAINTENANCE_SCOPE_INVALID');
        }
        if ($context->execute) {
            $actor = User::query()->find($context->actorId);
            if ($actor === null || ! $actor->is_active || ! $actor->isPlatformAdmin()) {
                throw new RuntimeException('EXECUTION_REQUIRES_PLATFORM_ADMIN');
            }
        }
    }

    /** @param array<string,mixed> $result */
    private function audit(MaintenanceContext $context, string $action, array $result): void
    {
        $this->audit->record(
            action: $action,
            result: $context->execute ? 'SUCCESS' : 'DRY_RUN',
            context: $result,
            userId: $context->actorId,
            tenantId: $context->tenantId,
        );
    }
}
