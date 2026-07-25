<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\MessageDirection;
use App\Enums\Communication\MessageKind;
use App\Http\Controllers\Controller;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use App\Models\CommunicationOutboxEntry;
use App\Models\User;
use App\Services\Communication\Gateway\CommunicationGatewayOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Operações remotas de uma conversa 1:1 já projetada no Office atual.
 * Endereço, inbox e provider IDs são sempre derivados do domínio; o caller
 * nunca escolhe session_id, office_id ou JID arbitrário.
 */
final class CommunicationConversationGatewayController extends Controller
{
    public function __construct(private readonly CommunicationGatewayOperations $operations) {}

    public function edit(Request $request, int $conversation, int $message): JsonResponse
    {
        [$model, $target] = $this->target($conversation, $message);
        abort_unless($target->direction === MessageDirection::Outbound, 422, 'Somente mensagem enviada pode ser editada.');
        $data = $request->validate(['text' => ['required', 'string', 'max:65536']]);

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::EditMessage,
            [
                'to' => $this->address($model),
                'target_message_id' => $this->providerId($target),
                'text' => trim((string) $data['text']),
            ],
        ));
    }

    public function revoke(Request $request, int $conversation, int $message): JsonResponse
    {
        [$model, $target] = $this->target($conversation, $message);
        abort_unless($target->direction === MessageDirection::Outbound, 422, 'Somente mensagem enviada pode ser revogada.');

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::RevokeMessage,
            [
                'to' => $this->address($model),
                'target_message_id' => $this->providerId($target),
            ],
        ));
    }

    public function react(Request $request, int $conversation, int $message): JsonResponse
    {
        [$model, $target] = $this->target($conversation, $message);
        $data = $request->validate(['emoji' => ['present', 'nullable', 'string', 'max:32']]);

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::ReactMessage,
            [
                'to' => $this->address($model),
                'target_message_id' => $this->providerId($target),
                'sender' => $this->sender($model, $target),
                // null representa remoção de reação no contrato público.
                'emoji' => $data['emoji'] === null ? '' : trim((string) $data['emoji']),
            ],
        ));
    }

    public function votePoll(Request $request, int $conversation, int $message): JsonResponse
    {
        [$model, $target] = $this->target($conversation, $message);
        abort_unless($target->kind === MessageKind::Poll, 422, 'A mensagem alvo não é uma enquete.');
        $data = $request->validate([
            'option_names' => ['required', 'array', 'min:1', 'max:12'],
            'option_names.*' => ['required', 'string', 'max:256', 'distinct'],
        ]);

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::VotePoll,
            [
                'to' => $this->address($model),
                'target_message_id' => $this->providerId($target),
                'sender' => $this->sender($model, $target),
                'option_names' => array_values($data['option_names']),
            ],
        ));
    }

    public function receipt(Request $request, int $conversation, int $message): JsonResponse
    {
        [$model, $target] = $this->target($conversation, $message);
        abort_unless($target->direction === MessageDirection::Inbound, 422, 'Receipt exige mensagem recebida.');
        $data = $request->validate([
            'receipt' => ['required', Rule::in(['READ', 'PLAYED'])],
        ]);

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::MarkMessage,
            [
                'to' => $this->address($model),
                'message_ids' => [$this->providerId($target)],
                'receipt' => (string) $data['receipt'],
                'sender' => $this->sender($model, $target),
                'timestamp' => $target->occurred_at?->getTimestamp() ?? now()->getTimestamp(),
                'protocol' => false,
            ],
        ));
    }

    public function subscribePresence(Request $request, int $conversation): JsonResponse
    {
        $model = $this->conversation($conversation);

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::SubscribePresence,
            ['to' => $this->address($model)],
        ));
    }

    public function chatPresence(Request $request, int $conversation): JsonResponse
    {
        $model = $this->conversation($conversation);
        $data = $request->validate([
            'presence' => ['required', Rule::in(['COMPOSING', 'PAUSED', 'RECORDING'])],
            'media' => ['nullable', Rule::in(['TEXT', 'AUDIO'])],
        ]);
        $payload = [
            'to' => $this->address($model),
            'presence' => (string) $data['presence'],
        ];
        if (isset($data['media'])) {
            $payload['media'] = (string) $data['media'];
        }

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::SetChatPresence,
            $payload,
        ));
    }

    public function disappearing(Request $request, int $conversation): JsonResponse
    {
        $model = $this->conversation($conversation);
        $data = $request->validate([
            'timer_seconds' => ['required', 'integer', Rule::in([0, 86400, 604800, 7776000])],
        ]);

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::SetChatDisappearing,
            ['to' => $this->address($model), 'timer_seconds' => (int) $data['timer_seconds']],
        ));
    }

    public function history(Request $request, int $conversation, int $message): JsonResponse
    {
        [$model, $cursor] = $this->target($conversation, $message);
        $data = $request->validate(['count' => ['required', 'integer', 'min:1', 'max:50']]);

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::RequestHistorySync,
            [
                'to' => $this->address($model),
                'last_message_id' => $this->providerId($cursor),
                'last_message_from' => $this->sender($model, $cursor),
                'last_message_timestamp' => $cursor->occurred_at?->getTimestamp() ?? now()->getTimestamp(),
                'last_message_from_me' => $cursor->direction === MessageDirection::Outbound,
                'count' => (int) $data['count'],
            ],
        ));
    }

    public function recovery(Request $request, int $conversation, int $message): JsonResponse
    {
        [$model, $target] = $this->target($conversation, $message);
        abort_unless($target->direction === MessageDirection::Inbound, 422, 'Recovery exige mensagem recebida.');
        $data = $request->validate([
            'operation' => ['required', Rule::in(['UNAVAILABLE', 'MEDIA_RETRY'])],
        ]);
        $type = $data['operation'] === 'MEDIA_RETRY'
            ? GatewayCommandType::RequestMediaRetry
            : GatewayCommandType::RequestUnavailableMessage;
        $payload = [
            'to' => $this->address($model),
            'target_message_id' => $this->providerId($target),
            'sender' => $this->sender($model, $target),
        ];
        if ($type === GatewayCommandType::RequestMediaRetry) {
            $payload['from_me'] = false;
        }

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            $type,
            $payload,
        ));
    }

    public function state(Request $request, int $conversation): JsonResponse
    {
        $model = $this->conversation($conversation);
        $data = $request->validate([
            'action' => ['required', Rule::in(['ARCHIVE', 'MUTE', 'PIN', 'STAR', 'MARK_READ', 'DELETE_CHAT'])],
            'value' => ['nullable', 'boolean'],
            'message_id' => ['nullable', 'integer', 'min:1'],
            'duration_seconds' => ['nullable', 'integer', 'min:0', 'max:31536000'],
            'delete_media' => ['nullable', 'boolean'],
        ]);
        $action = (string) $data['action'];
        if (in_array($action, ['ARCHIVE', 'MUTE', 'PIN', 'STAR', 'MARK_READ'], true)) {
            abort_unless(array_key_exists('value', $data), 422, 'value é obrigatório para esta ação.');
        }
        if ($action === 'STAR') {
            abort_unless(isset($data['message_id']), 422, 'message_id é obrigatório para STAR.');
        }
        if ($action === 'MUTE' && ($data['value'] ?? false)) {
            abort_unless(isset($data['duration_seconds']), 422, 'duration_seconds é obrigatório para ativar mute.');
        }

        $target = isset($data['message_id'])
            ? $this->message($model, (int) $data['message_id'])
            : null;
        $payload = [
            'to' => $this->address($model),
            'action' => $action,
            'value' => (bool) ($data['value'] ?? false),
            'timestamp' => $target?->occurred_at?->getTimestamp() ?? now()->getTimestamp(),
            'duration_seconds' => (int) ($data['duration_seconds'] ?? 0),
            'delete_media' => (bool) ($data['delete_media'] ?? false),
            'from_me' => $target?->direction === MessageDirection::Outbound,
        ];
        if ($target !== null) {
            $payload['target_message_id'] = $this->providerId($target);
            $payload['sender'] = $this->sender($model, $target);
        }

        return $this->queued($this->operations->enqueue(
            $this->actor($request),
            $model->inbox,
            GatewayCommandType::UpdateChatState,
            $payload,
        ));
    }

    /** @return array{CommunicationConversation, CommunicationMessage} */
    private function target(int $conversationId, int $messageId): array
    {
        $conversation = $this->conversation($conversationId);

        return [$conversation, $this->message($conversation, $messageId)];
    }

    private function conversation(int $id): CommunicationConversation
    {
        return CommunicationConversation::query()->with(['inbox', 'identity'])->findOrFail($id);
    }

    private function message(CommunicationConversation $conversation, int $id): CommunicationMessage
    {
        return CommunicationMessage::query()
            ->where('conversation_id', $conversation->id)
            ->findOrFail($id);
    }

    private function address(CommunicationConversation $conversation): string
    {
        $address = trim((string) $conversation->identity->address_encrypted);
        abort_if($address === '', 422, 'Identidade da conversa sem endereço utilizável.');

        return $address;
    }

    private function providerId(CommunicationMessage $message): string
    {
        $providerId = trim((string) $message->provider_message_id);
        abort_if($providerId === '', 422, 'Mensagem sem identificador remoto.');

        return $providerId;
    }

    private function sender(
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): string {
        return $message->direction === MessageDirection::Inbound
            ? $this->address($conversation)
            : '';
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function queued(CommunicationOutboxEntry $entry): JsonResponse
    {
        return response()->json(['data' => [
            'command_id' => $entry->command_id,
            'type' => $entry->type->value,
            'status' => $entry->status->value,
        ]], 202);
    }
}
