<?php

namespace App\Services\Communication\Outbox;

use App\DTO\Communication\GatewayCommandData;
use App\DTO\Communication\GatewayContractPayload;
use App\DTO\Communication\PayloadDigest;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\OperationFailure;
use App\Enums\Communication\OutboxStatus;
use App\Exceptions\CommunicationOperationException;
use App\Jobs\Communication\DispatchOutboxJob;
use App\Models\CommunicationInbox;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Services\Communication\Availability;
use App\Services\Communication\Gateway\GatewayOperationPolicy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final readonly class OutboxService
{
    public function __construct(
        private Availability $availability,
        private GatewayOperationPolicy $policy,
    ) {}

    /** @param array<string, mixed> $payload */
    public function enqueue(
        CommunicationInbox $inbox,
        GatewayCommandType $type,
        array $payload,
        ?CommunicationMessage $message = null,
        ?string $commandId = null,
        ?string $effectKey = null,
    ): CommunicationOutboxEntry {
        return $this->persist(
            $inbox,
            $type,
            $payload,
            $message,
            $commandId,
            $effectKey,
            true,
        );
    }

    /** @param array<string, mixed> $payload */
    public function enqueueAcceptedFollowUp(
        CommunicationInbox $inbox,
        GatewayCommandType $type,
        array $payload,
        CommunicationMessage $message,
        string $effectKey,
    ): CommunicationOutboxEntry {
        return $this->persist(
            $inbox,
            $type,
            $payload,
            $message,
            null,
            $effectKey,
            false,
        );
    }

    /** @param array<string, mixed> $payload */
    private function persist(
        CommunicationInbox $inbox,
        GatewayCommandType $type,
        array $payload,
        ?CommunicationMessage $message,
        ?string $commandId,
        ?string $effectKey,
        bool $assertAvailability,
    ): CommunicationOutboxEntry {
        $this->assertTenantConsistency($inbox, $message);
        if ($effectKey !== null && $effectKey !== '') {
            $existing = CommunicationOutboxEntry::query()
                ->withoutGlobalScopes()
                ->where('effect_key', $effectKey)
                ->first();
            if ($existing !== null) {
                return $existing;
            }
        }

        if ($assertAvailability) {
            if ($this->policy->allowsDisabledInbox($type)) {
                $this->availability->assertGatewayAvailable();
            } else {
                $this->availability->assertEnabled($inbox, $this->policy->requiresConnectedInbox($type));
            }
        }

        $commandId ??= $effectKey ?? ('command-'.strtolower((string) Str::ulid()));
        $providerMessageId = $message?->provider_message_id;
        if ($providerMessageId === null && GatewayContractPayload::requiresProviderMessageId($type)) {
            // Ações (edit/revoke/reaction/vote) não criam uma nova mensagem
            // de timeline. O command_id persistido fornece o ID remoto estável
            // sem reaproveitar indevidamente o ID da mensagem alvo.
            $providerMessageId = $commandId;
        }
        $command = new GatewayCommandData(
            commandId: $commandId,
            sessionId: (string) $inbox->session_id,
            type: $type,
            payload: $payload,
            providerMessageId: is_string($providerMessageId) ? $providerMessageId : null,
        );

        $entry = DB::transaction(function () use ($inbox, $message, $commandId, $effectKey, $type, $payload, $command): CommunicationOutboxEntry {
            return CommunicationOutboxEntry::query()->create([
                'tenant_id' => $inbox->tenant_id,
                'inbox_id' => $inbox->id,
                'message_id' => $message?->id,
                'command_id' => $commandId,
                'effect_key' => $effectKey,
                'session_id' => $inbox->session_id,
                'type' => $type,
                'payload_encrypted' => $payload,
                'payload_digest' => PayloadDigest::make($command->toArray()),
                'status' => OutboxStatus::Pending,
                'available_at' => now(),
            ]);
        });

        DB::afterCommit(static fn () => DispatchOutboxJob::dispatch((int) $entry->id));

        return $entry;
    }

    private function assertTenantConsistency(
        CommunicationInbox $inbox,
        ?CommunicationMessage $message,
    ): void {
        if (! $inbox->exists || trim((string) $inbox->session_id) === '') {
            throw new CommunicationOperationException(OperationFailure::InboxSessionInvalid);
        }

        if ($message !== null && (
            ! $message->exists
            || (int) $message->tenant_id !== (int) $inbox->tenant_id
            || (int) $message->inbox_id !== (int) $inbox->id
        )) {
            throw new CommunicationOperationException(OperationFailure::OutboxTenantScopeInvalid);
        }
    }
}
