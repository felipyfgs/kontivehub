<?php

namespace App\Actions\Communication;

use App\DTO\Communication\CommunicationGatewayCommandResult;
use App\DTO\Communication\CommunicationGatewayOperationData;
use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Exceptions\CommunicationGatewayApiException;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\User;
use App\Services\Communication\Gateway\CommunicationGatewayOperations;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use LogicException;

final readonly class ExecuteCommunicationConversationGatewayAction
{
    public function __construct(
        private CommunicationGatewayOperations $operations,
    ) {}

    public function handle(
        User $actor,
        CommunicationConversation $conversation,
        GatewayCommandType $type,
        CommunicationGatewayOperationData $data,
        ?CommunicationMessage $message = null,
    ): CommunicationGatewayCommandResult {
        $conversation->loadMissing(['inbox', 'identity']);
        $this->assertMessageBelongsToConversation($conversation, $message);
        $payload = $this->payload($conversation, $type, $data, $message);
        $entry = $this->operations->enqueue(
            $actor,
            $conversation->inbox,
            $type,
            $payload,
        );

        return CommunicationGatewayCommandResult::fromEntry($entry);
    }

    /** @return array<string, mixed> */
    private function payload(
        CommunicationConversation $conversation,
        GatewayCommandType $type,
        CommunicationGatewayOperationData $data,
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
        CommunicationGatewayOperationData $data,
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
        CommunicationGatewayOperationData $data,
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
        CommunicationGatewayOperationData $data,
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
        CommunicationGatewayOperationData $data,
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
        CommunicationGatewayOperationData $data,
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
        $this->assertInbound($message);
        $payload = [
            'to' => $this->conversationAddress($conversation),
            'target_message_id' => $this->providerId($message),
            'sender' => $this->sender($conversation, $message),
        ];
        if ($type === GatewayCommandType::RequestMediaRetry) {
            $payload['from_me'] = false;
        }

        return $payload;
    }

    /** @return array<string, mixed> */
    private function statePayload(
        CommunicationConversation $conversation,
        CommunicationGatewayOperationData $data,
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
