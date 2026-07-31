<?php

namespace App\Actions\Communication;

use App\DTO\Communication\GatewayCommandResult;
use App\DTO\Communication\GatewayOperationData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\MessageAvailabilityState;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Enums\Communication\OutboxStatus;
use App\Exceptions\CommunicationGatewayApiException;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\User;
use App\Services\Communication\ConversationCanonicalizer;
use App\Services\Communication\Gateway\GatewayOperations;
use App\Services\Communication\MessageAvailability;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class ExecuteConversationGatewayAction
{
    public function __construct(
        private GatewayOperations $operations,
        private ConversationCanonicalizer $canonicalizer,
        private MessageAvailability $availability,
    ) {}

    public function handle(
        User $actor,
        CommunicationConversation $conversation,
        GatewayCommandType $type,
        GatewayOperationData $data,
        ?CommunicationMessage $message = null,
    ): GatewayCommandResult {
        $conversation = $this->canonicalizer->conversation($conversation);

        if ($type === GatewayCommandType::RequestMediaRetry) {
            return $this->enqueueMediaRetry($actor, $conversation, $message);
        }

        $conversation->loadMissing(['inbox', 'identity']);
        $this->assertMessageBelongsToConversation($conversation, $message);
        $payload = $this->payload($conversation, $type, $data, $message);
        $entry = $this->operations->enqueue(
            $actor,
            $conversation->inbox,
            $type,
            $payload,
        );

        return GatewayCommandResult::fromEntry($entry);
    }

    /** @return array<string, mixed> */
    private function payload(
        CommunicationConversation $conversation,
        GatewayCommandType $type,
        GatewayOperationData $data,
        ?CommunicationMessage $message,
    ): array {
        return match ($type) {
            GatewayCommandType::EditMessage => $this->editPayload(
                $conversation,
                $this->target($message),
                $data,
            ),
            GatewayCommandType::RevokeMessage => $this->revokePayload(
                $conversation,
                $this->target($message),
            ),
            GatewayCommandType::ReactMessage => $this->reactionPayload(
                $conversation,
                $this->target($message),
                $data,
            ),
            GatewayCommandType::VotePoll => $this->pollVotePayload(
                $conversation,
                $this->target($message),
                $data,
            ),
            GatewayCommandType::MarkMessage => $this->receiptPayload(
                $conversation,
                $this->target($message),
                $data,
            ),
            GatewayCommandType::RequestHistorySync => $this->historyPayload(
                $conversation,
                $this->target($message),
                $data,
            ),
            GatewayCommandType::RequestUnavailableMessage,
            GatewayCommandType::RequestMediaRetry => $this->recoveryPayload(
                $conversation,
                $this->target($message),
                $type,
            ),
            GatewayCommandType::SubscribePresence => [
                'to' => $this->conversationAddress($conversation),
            ],
            GatewayCommandType::SetChatPresence => [
                'to' => $this->conversationAddress($conversation),
                ...$data->parameters,
            ],
            GatewayCommandType::SetChatDisappearing => [
                'to' => $this->conversationAddress($conversation),
                ...$data->parameters,
            ],
            GatewayCommandType::UpdateChatState => $this->statePayload(
                $conversation,
                $data,
            ),
            default => throw new LogicException('Operação de conversa não suportada.'),
        };
    }

    /** @return array<string, mixed> */
    private function editPayload(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        GatewayOperationData $data,
    ): array {
        $this->assertOutbound($message);

        return [
            'to' => $this->conversationAddress($conversation),
            'target_message_id' => $this->providerId($message),
            'text' => (string) $data->parameters['text'],
        ];
    }

    /** @return array<string, mixed> */
    private function revokePayload(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): array {
        $this->assertOutbound($message);

        return [
            'to' => $this->conversationAddress($conversation),
            'target_message_id' => $this->providerId($message),
        ];
    }

    /** @return array<string, mixed> */
    private function reactionPayload(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        GatewayOperationData $data,
    ): array {
        return [
            'to' => $this->conversationAddress($conversation),
            'target_message_id' => $this->providerId($message),
            'sender' => $this->sender($conversation, $message),
            'emoji' => (string) $data->parameters['emoji'],
        ];
    }

    /** @return array<string, mixed> */
    private function pollVotePayload(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        GatewayOperationData $data,
    ): array {
        if ($message->kind !== MessageKind::Poll) {
            throw CommunicationGatewayApiException::pollMessageRequired();
        }

        return [
            'to' => $this->conversationAddress($conversation),
            'target_message_id' => $this->providerId($message),
            'sender' => $this->sender($conversation, $message),
            'option_names' => $data->parameters['option_names'],
        ];
    }

    /** @return array<string, mixed> */
    private function receiptPayload(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        GatewayOperationData $data,
    ): array {
        $this->assertInbound($message);

        return [
            'to' => $this->conversationAddress($conversation),
            'message_ids' => [$this->providerId($message)],
            'receipt' => (string) $data->parameters['receipt'],
            'sender' => $this->sender($conversation, $message),
            'timestamp' => $message->occurred_at?->getTimestamp() ?? now()->getTimestamp(),
            'protocol' => false,
        ];
    }

    /** @return array<string, mixed> */
    private function historyPayload(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        GatewayOperationData $data,
    ): array {
        return [
            'to' => $this->conversationAddress($conversation),
            'last_message_id' => $this->providerId($message),
            'last_message_from' => $this->sender($conversation, $message),
            'last_message_timestamp' => $message->occurred_at?->getTimestamp() ?? now()->getTimestamp(),
            'last_message_from_me' => $message->direction === MessageDirection::Outbound,
            'count' => (int) $data->parameters['count'],
        ];
    }

    /** @return array<string, mixed> */
    private function recoveryPayload(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
        GatewayCommandType $type,
    ): array {
        if ($type === GatewayCommandType::RequestMediaRetry) {
            return [
                'to' => $this->conversationAddress($conversation),
                'target_message_id' => $this->providerId($message),
                'expected_direction' => $message->direction instanceof MessageDirection
                    ? $message->direction->value
                    : (string) $message->direction,
            ];
        }

        $this->assertInbound($message);

        return [
            'to' => $this->conversationAddress($conversation),
            'target_message_id' => $this->providerId($message),
            'sender' => $this->sender($conversation, $message),
        ];
    }

    private function enqueueMediaRetry(
        User $actor,
        CommunicationConversation $conversation,
        ?CommunicationMessage $message,
    ): GatewayCommandResult {
        $target = $this->target($message);

        return DB::transaction(function () use ($actor, $conversation, $target): GatewayCommandResult {
            $lockedConversation = $this->canonicalizer->lockConversation($conversation);
            $lockedConversation->loadMissing(['inbox', 'identity']);
            $this->operations->authorizeCommand(
                $actor,
                $lockedConversation->inbox,
                GatewayCommandType::RequestMediaRetry,
                [],
            );
            $lockedMessage = CommunicationMessage::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $lockedConversation->tenant_id)
                ->where('inbox_id', $lockedConversation->inbox_id)
                ->where('conversation_id', $lockedConversation->id)
                ->visibleToWorkspace()
                ->whereKey($target->id)
                ->lockForUpdate()
                ->firstOrFail();
            $availability = $this->availability->forMessage($lockedMessage);
            $active = CommunicationOutboxEntry::query()
                ->withoutGlobalScopes()
                ->where('tenant_id', $lockedMessage->tenant_id)
                ->where('inbox_id', $lockedMessage->inbox_id)
                ->where('message_id', $lockedMessage->id)
                ->where('type', GatewayCommandType::RequestMediaRetry->value)
                ->whereIn('status', array_map(
                    static fn (OutboxStatus $status): string => $status->value,
                    [
                        OutboxStatus::Pending,
                        OutboxStatus::Dispatching,
                        OutboxStatus::Retry,
                        OutboxStatus::Accepted,
                    ],
                ))
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if ($active !== null && (
                $active->status !== OutboxStatus::Accepted
                || $availability->state === MessageAvailabilityState::MediaRequested
            )) {
                return GatewayCommandResult::fromEntry($active);
            }
            if (! $availability->recoverable) {
                throw CommunicationGatewayApiException::mediaNotRecoverable();
            }

            $metadata = is_array($lockedMessage->metadata) ? $lockedMessage->metadata : [];
            $attempt = max(0, (int) ($metadata['media_request_generation'] ?? 0)) + 1;
            $metadata['media_request_generation'] = $attempt;
            $lockedMessage->forceFill(['metadata' => $metadata])->save();
            $payload = $this->recoveryPayload(
                $lockedConversation,
                $lockedMessage,
                GatewayCommandType::RequestMediaRetry,
            );
            $entry = $this->operations->enqueue(
                $actor,
                $lockedConversation->inbox,
                GatewayCommandType::RequestMediaRetry,
                $payload,
                $lockedMessage,
                effectKey: implode(':', [
                    'media-retry',
                    $lockedMessage->tenant_id,
                    $lockedMessage->inbox_id,
                    $lockedMessage->id,
                    $attempt,
                ]),
            );

            return GatewayCommandResult::fromEntry($entry);
        }, 3);
    }

    /** @return array<string, mixed> */
    private function statePayload(
        CommunicationConversation $conversation,
        GatewayOperationData $data,
    ): array {
        $message = isset($data->parameters['message_id'])
            ? $conversation->messages()->findOrFail((int) $data->parameters['message_id'])
            : null;
        $payload = [
            'to' => $this->conversationAddress($conversation),
            'action' => (string) $data->parameters['action'],
            'value' => (bool) ($data->parameters['value'] ?? false),
            'timestamp' => $message?->occurred_at?->getTimestamp() ?? now()->getTimestamp(),
            'duration_seconds' => (int) ($data->parameters['duration_seconds'] ?? 0),
            'delete_media' => (bool) ($data->parameters['delete_media'] ?? false),
            'from_me' => $message?->direction === MessageDirection::Outbound,
        ];
        if ($message !== null) {
            $payload['target_message_id'] = $this->providerId($message);
            $payload['sender'] = $this->sender($conversation, $message);
        }

        return $payload;
    }

    private function assertMessageBelongsToConversation(
        CommunicationConversation $conversation,
        ?CommunicationMessage $message,
    ): void {
        if ($message !== null
            && (int) $message->conversation_id !== (int) $conversation->id) {
            throw (new ModelNotFoundException)->setModel(
                CommunicationMessage::class,
                [$message->id],
            );
        }
    }

    private function target(?CommunicationMessage $message): CommunicationMessage
    {
        if (! $message instanceof CommunicationMessage) {
            throw (new ModelNotFoundException)->setModel(CommunicationMessage::class);
        }

        return $message;
    }

    private function conversationAddress(CommunicationConversation $conversation): string
    {
        $address = trim((string) $conversation->identity->address_encrypted);
        if ($address === '') {
            throw CommunicationGatewayApiException::conversationAddressUnavailable();
        }

        return $address;
    }

    private function providerId(CommunicationMessage $message): string
    {
        $providerId = trim((string) $message->provider_message_id);
        if ($providerId === '') {
            throw CommunicationGatewayApiException::remoteMessageIdentifierUnavailable();
        }

        return $providerId;
    }

    private function sender(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): string {
        return $message->direction === MessageDirection::Inbound
            ? $this->conversationAddress($conversation)
            : '';
    }

    private function assertOutbound(CommunicationMessage $message): void
    {
        if ($message->direction !== MessageDirection::Outbound) {
            throw CommunicationGatewayApiException::outboundMessageRequired();
        }
    }

    private function assertInbound(CommunicationMessage $message): void
    {
        if ($message->direction !== MessageDirection::Inbound) {
            throw CommunicationGatewayApiException::inboundMessageRequired();
        }
    }
}
