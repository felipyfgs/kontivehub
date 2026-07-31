<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\ExecuteConversationGatewayAction;
use App\DTO\Communication\GatewayOperationData;
use App\Enums\Communication\GatewayCommandType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\CommunicationConversationGatewayRequest;
use App\Http\Requests\Communication\EditCommunicationMessageRequest;
use App\Http\Requests\Communication\OperateCommunicationConversationGatewayRequest;
use App\Http\Requests\Communication\ReactToCommunicationMessageRequest;
use App\Http\Requests\Communication\RecordCommunicationMessageReceiptRequest;
use App\Http\Requests\Communication\RecoverCommunicationMessageRequest;
use App\Http\Requests\Communication\RequestCommunicationConversationHistoryRequest;
use App\Http\Requests\Communication\UpdateCommunicationConversationDisappearingRequest;
use App\Http\Requests\Communication\UpdateCommunicationConversationPresenceRequest;
use App\Http\Requests\Communication\UpdateCommunicationConversationStateRequest;
use App\Http\Requests\Communication\VoteCommunicationPollRequest;
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
        EditCommunicationMessageRequest $request,
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
        OperateCommunicationConversationGatewayRequest $request,
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
        ReactToCommunicationMessageRequest $request,
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
        VoteCommunicationPollRequest $request,
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
        RecordCommunicationMessageReceiptRequest $request,
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
        OperateCommunicationConversationGatewayRequest $request,
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
        UpdateCommunicationConversationPresenceRequest $request,
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
        UpdateCommunicationConversationDisappearingRequest $request,
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
        RequestCommunicationConversationHistoryRequest $request,
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
        RecoverCommunicationMessageRequest $request,
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
        UpdateCommunicationConversationStateRequest $request,
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
        CommunicationConversationGatewayRequest $request,
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
