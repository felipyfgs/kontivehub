<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ExecuteConversationGatewayAction;
use App\DTO\Communication\GatewayOperationData;
use App\Enums\Communication\GatewayCommandType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ConversationGatewayRequest;
use App\Http\Requests\Communication\EditMessageRequest;
use App\Http\Requests\Communication\OperateConversationGatewayRequest;
use App\Http\Requests\Communication\ReactToMessageRequest;
use App\Http\Requests\Communication\RecordMessageReceiptRequest;
use App\Http\Requests\Communication\RecoverMessageRequest;
use App\Http\Requests\Communication\RequestConversationHistoryRequest;
use App\Http\Requests\Communication\UpdateConversationDisappearingRequest;
use App\Http\Requests\Communication\UpdateConversationPresenceRequest;
use App\Http\Requests\Communication\UpdateConversationStateRequest;
use App\Http\Requests\Communication\VotePollRequest;
use App\Http\Resources\Communication\GatewayCommandResource;
use App\Models\CommunicationConversation;
use App\Models\CommunicationMessage;
use Illuminate\Http\JsonResponse;

/**
 * Operações remotas de uma conversa 1:1 já projetada no Tenant atual.
 * Endereço, inbox e provider IDs são sempre derivados do domínio; o caller
 * nunca escolhe session_id, tenant_id ou JID arbitrário.
 */
final class ConversationGatewayController extends Controller
{
    public function __construct(
        private readonly ExecuteConversationGatewayAction $gateway,
    ) {}

    public function edit(
        EditMessageRequest $request,
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::EditMessage,
            $request->gatewayData(),
            $message,
        );
    }

    public function revoke(
        OperateConversationGatewayRequest $request,
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::RevokeMessage,
            $request->gatewayData(),
            $message,
        );
    }

    public function react(
        ReactToMessageRequest $request,
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::ReactMessage,
            $request->gatewayData(),
            $message,
        );
    }

    public function votePoll(
        VotePollRequest $request,
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::VotePoll,
            $request->gatewayData(),
            $message,
        );
    }

    public function receipt(
        RecordMessageReceiptRequest $request,
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::MarkMessage,
            $request->gatewayData(),
            $message,
        );
    }

    public function subscribePresence(
        OperateConversationGatewayRequest $request,
        CommunicationConversation $conversation,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::SubscribePresence,
            $request->gatewayData(),
        );
    }

    public function chatPresence(
        UpdateConversationPresenceRequest $request,
        CommunicationConversation $conversation,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::SetChatPresence,
            $request->gatewayData(),
        );
    }

    public function disappearing(
        UpdateConversationDisappearingRequest $request,
        CommunicationConversation $conversation,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::SetChatDisappearing,
            $request->gatewayData(),
        );
    }

    public function history(
        RequestConversationHistoryRequest $request,
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::RequestHistorySync,
            $request->gatewayData(),
            $message,
        );
    }

    public function recovery(
        RecoverMessageRequest $request,
        CommunicationConversation $conversation,
        CommunicationMessage $message,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            $request->commandType(),
            $request->gatewayData(),
            $message,
        );
    }

    public function state(
        UpdateConversationStateRequest $request,
        CommunicationConversation $conversation,
    ): JsonResponse {
        return $this->command(
            $request,
            $conversation,
            GatewayCommandType::UpdateChatState,
            $request->gatewayData(),
        );
    }

    private function command(
        ConversationGatewayRequest $request,
        CommunicationConversation $conversation,
        GatewayCommandType $type,
        GatewayOperationData $data,
        ?CommunicationMessage $message = null,
    ): JsonResponse {
        return (new GatewayCommandResource(
            $this->gateway->handle(
                $request->actor(),
                $conversation,
                $type,
                $data,
                $message,
            ),
        ))->response()->setStatusCode(202);
    }
}
