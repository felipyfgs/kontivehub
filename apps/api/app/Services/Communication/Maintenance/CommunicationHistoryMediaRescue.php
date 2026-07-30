<?php

namespace App\Services\Communication\Maintenance;

use App\DTO\Communication\CommunicationMaintenanceContext;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\OutboxStatus;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Communication\CommunicationMessageAvailability;
use App\Services\Communication\Outbox\CommunicationOutboxService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class CommunicationHistoryMediaRescue
{
    /** @var list<string> */
    private const MEDIA_KINDS = ['IMAGE', 'AUDIO', 'VIDEO', 'DOCUMENT', 'STICKER'];

    public function __construct(
        private CommunicationOutboxService $outbox,
        private CommunicationMessageAvailability $availability,
        private AuditLogger $audit,
    ) {}

    /** @return array<string,mixed> */
    public function run(CommunicationMaintenanceContext $context, int $requestedLimit): array
    {
        $inbox = $this->assertTrustedScope($context);
        $hardLimit = max(1, (int) config('communication.history_media_recovery.max_batch', 25));
        $limit = max(1, min($hardLimit, $requestedLimit));
        $candidates = $this->eligibleCandidates($context, $limit);
        $inventory = $this->inventory($candidates);
        $result = [
            'mode' => $context->execute ? 'execute' : 'dry-run',
            'operation_id' => $context->operationId,
            'tenant_id' => $context->tenantId,
            'inbox_id' => $context->inboxId,
            'limit' => $limit,
            ...$inventory,
            'requested_count' => 0,
            'skipped_count' => 0,
            'blocked_code' => null,
        ];
        if (! $context->execute) {
            $this->audit($context, $result);

            return $result;
        }

        if (! config('communication.history_media_recovery.enabled', false)
            || config('communication.history_media_recovery.kill_switch', true)) {
            $result['blocked_code'] = 'MEDIA_RESCUE_DISABLED';
            $this->audit($context, $result);

            return $result;
        }
        if (! $inbox->is_enabled || $inbox->status !== InboxStatus::Connected) {
            $result['blocked_code'] = 'INBOX_NOT_OPERATIONAL';
            $this->audit($context, $result);

            return $result;
        }
        if ($this->hasPendingResult($context)) {
            $result['blocked_code'] = 'PENDING_RESULT';
            $this->audit($context, $result);

            return $result;
        }
        if ($candidates->isEmpty()) {
            $this->audit($context, $result);

            return $result;
        }
        $sessionLimit = max(1, (int) config('communication.history_media_recovery.session_limit', 25));
        $cooldown = max(1, (int) config('communication.history_media_recovery.backoff_seconds', 300));
        $recent = CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('inbox_id', $context->inboxId)
            ->where('type', GatewayCommandType::RequestMediaRetry->value)
            ->where('created_at', '>=', now()->subSeconds($cooldown))
            ->count();
        if ($recent >= $sessionLimit) {
            $result['blocked_code'] = 'SESSION_LIMIT_REACHED';
            $this->audit($context, $result);

            return $result;
        }

        $allowed = min($candidates->count(), $sessionLimit - $recent);
        foreach ($candidates->take($allowed) as $candidate) {
            $entry = DB::transaction(function () use ($context, $inbox, $candidate): ?CommunicationOutboxEntry {
                $message = $this->candidateQuery($context)
                    ->whereKey($candidate->id)
                    ->lockForUpdate()
                    ->first();
                if ($message === null || ! $this->isEligible($message)) {
                    return null;
                }

                $active = CommunicationOutboxEntry::query()->withoutGlobalScopes()
                    ->where('tenant_id', $context->tenantId)
                    ->where('inbox_id', $context->inboxId)
                    ->where('message_id', $message->id)
                    ->where('type', GatewayCommandType::RequestMediaRetry->value)
                    ->whereIn('status', [
                        OutboxStatus::Pending->value,
                        OutboxStatus::Dispatching->value,
                        OutboxStatus::Retry->value,
                    ])
                    ->exists();
                if ($active) {
                    return null;
                }
                $attempt = CommunicationOutboxEntry::query()->withoutGlobalScopes()
                    ->where('tenant_id', $context->tenantId)
                    ->where('inbox_id', $context->inboxId)
                    ->where('message_id', $message->id)
                    ->where('type', GatewayCommandType::RequestMediaRetry->value)
                    ->count() + 1;
                $effectKey = implode(':', [
                    'media-rescue',
                    $context->tenantId,
                    $context->inboxId,
                    $message->id,
                    $attempt,
                ]);

                return $this->outbox->enqueue(
                    $inbox,
                    GatewayCommandType::RequestMediaRetry,
                    [
                        'to' => $this->conversationAddress($message),
                        'target_message_id' => (string) $message->provider_message_id,
                        'expected_direction' => $message->direction?->value ?? $message->direction,
                    ],
                    $message,
                    effectKey: $effectKey,
                );
            }, 3);
            if ($entry === null) {
                $result['skipped_count']++;
            } else {
                $result['requested_count']++;
            }
        }
        $result['skipped_count'] += $candidates->count() - $allowed;
        $this->audit($context, $result);

        return $result;
    }

    /** @return Builder<CommunicationMessage> */
    private function candidateQuery(CommunicationMaintenanceContext $context): Builder
    {
        return CommunicationMessage::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('inbox_id', $context->inboxId)
            ->whereIn('direction', [MessageDirection::Inbound->value, MessageDirection::Outbound->value])
            ->whereIn('kind', self::MEDIA_KINDS)
            ->whereNotNull('provider_message_id')
            ->whereNull('purged_at')
            ->whereNull('revoked_at')
            ->visibleToWorkspace()
            ->whereRaw("COALESCE((metadata->>'history')::boolean, false) = true")
            ->where(function (Builder $states): void {
                $states->whereIn(DB::raw("metadata->>'media_state'"), ['RETRY_AVAILABLE', 'FAILED'])
                    ->orWhereNull(DB::raw("metadata->>'media_state'"));
            })
            ->whereDoesntHave('attachments', fn (Builder $attachments) => $attachments
                ->withoutGlobalScopes()
                ->whereNull('purged_at'));
    }

    private function hasPendingResult(CommunicationMaintenanceContext $context): bool
    {
        return CommunicationOutboxEntry::query()->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->where('inbox_id', $context->inboxId)
            ->where('type', GatewayCommandType::RequestMediaRetry->value)
            ->where(function (Builder $query): void {
                $query->whereIn('status', [
                    OutboxStatus::Pending->value,
                    OutboxStatus::Dispatching->value,
                    OutboxStatus::Retry->value,
                ])->orWhere(function (Builder $accepted): void {
                    $accepted->where('status', OutboxStatus::Accepted->value)
                        ->where('accepted_at', '>=', now()->subSeconds(max(1, (int) config('communication.history_media_recovery.accepted_result_timeout_seconds', 900))))
                        ->whereHas('message', fn (Builder $messages) => $messages
                            ->withoutGlobalScopes()
                            ->whereRaw("metadata->>'media_state' = 'REQUESTED'"));
                });
            })
            ->exists();
    }

    private function isEligible(CommunicationMessage $message): bool
    {
        if ($this->availability->isRecoverable($message)) {
            return true;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];

        return ($metadata['history'] ?? false) === true
            && trim((string) ($metadata['media_state'] ?? '')) === '';
    }

    /** @param Collection<int,CommunicationMessage> $messages @return array<string,mixed> */
    private function inventory(Collection $messages): array
    {
        return [
            'eligible_count' => $messages->count(),
            'message_ids' => $messages->pluck('id')->map(static fn ($id): int => (int) $id)->all(),
            'by_direction' => $messages->countBy(
                static fn (CommunicationMessage $message): string => $message->direction?->value ?? (string) $message->direction,
            )->sortKeys()->all(),
            'by_kind' => $messages->countBy(
                static fn (CommunicationMessage $message): string => $message->kind?->value ?? (string) $message->kind,
            )->sortKeys()->all(),
        ];
    }

    private function conversationAddress(CommunicationMessage $message): string
    {
        $conversation = $message->conversation()->withoutGlobalScopes()
            ->where('tenant_id', $message->tenant_id)
            ->where('inbox_id', $message->inbox_id)
            ->with(['identity' => fn ($query) => $query->withoutGlobalScopes()->where('tenant_id', $message->tenant_id)])
            ->first();
        $address = trim((string) $conversation?->identity?->address_encrypted);
        if ($address === '') {
            throw new RuntimeException('CONVERSATION_ADDRESS_UNAVAILABLE');
        }

        return $address;
    }

    /** @return Collection<int,CommunicationMessage> */
    private function eligibleCandidates(CommunicationMaintenanceContext $context, int $limit): Collection
    {
        $eligible = collect();
        $lastOccurredAt = null;
        $lastId = null;

        while ($eligible->count() < $limit) {
            $query = $this->candidateQuery($context)
                ->orderBy('occurred_at')
                ->orderBy('id')
                ->limit(250);
            if ($lastOccurredAt !== null && $lastId !== null) {
                $query->where(function (Builder $cursor) use ($lastOccurredAt, $lastId): void {
                    $cursor->where('occurred_at', '>', $lastOccurredAt)
                        ->orWhere(fn (Builder $same) => $same->where('occurred_at', $lastOccurredAt)->where('id', '>', $lastId));
                });
            }
            $page = $query->get();
            if ($page->isEmpty()) {
                break;
            }
            $last = $page->last();
            $lastOccurredAt = $last->occurred_at;
            $lastId = $last->id;
            foreach ($page as $message) {
                if ($this->isEligible($message)) {
                    $eligible->push($message);
                    if ($eligible->count() >= $limit) {
                        break;
                    }
                }
            }
        }

        return $eligible;
    }

    private function assertTrustedScope(CommunicationMaintenanceContext $context): CommunicationInbox
    {
        if (! Tenant::query()->withoutGlobalScopes()->whereKey($context->tenantId)->where('is_active', true)->exists()) {
            throw new RuntimeException('MAINTENANCE_SCOPE_INVALID');
        }
        $inbox = CommunicationInbox::query()->withoutGlobalScopes()
            ->where('tenant_id', $context->tenantId)
            ->whereKey($context->inboxId)
            ->first();
        if ($inbox === null) {
            throw new RuntimeException('MAINTENANCE_SCOPE_INVALID');
        }
        if ($context->execute) {
            $actor = User::query()->find($context->actorId);
            if ($actor === null || ! $actor->is_active || ! $actor->isPlatformAdmin()) {
                throw new RuntimeException('EXECUTION_REQUIRES_PLATFORM_ADMIN');
            }
        }

        return $inbox;
    }

    /** @param array<string,mixed> $result */
    private function audit(CommunicationMaintenanceContext $context, array $result): void
    {
        $this->audit->record(
            action: 'communication.history_media.rescue',
            result: ($result['blocked_code'] ?? null) === null ? 'SUCCESS' : 'DENIED',
            context: $result,
            userId: $context->actorId,
            tenantId: $context->tenantId,
        );
    }
}
