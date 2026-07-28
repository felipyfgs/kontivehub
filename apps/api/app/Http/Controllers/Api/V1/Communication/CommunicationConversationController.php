<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\AssignCommunicationConversationLabelAction;
use App\Actions\Communication\CreateCommunicationMessageAction;
use App\Actions\Communication\RemoveCommunicationConversationLabelAction;
use App\Actions\Communication\UpdateCommunicationConversationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListCommunicationConversationsRequest;
use App\Http\Requests\Communication\ManageCommunicationConversationLabelRequest;
use App\Http\Requests\Communication\SendMessageRequest;
use App\Http\Requests\Communication\UpdateConversationRequest;
use App\Http\Requests\Communication\ViewCommunicationConversationRequest;
use App\Http\Resources\Communication\CommunicationConversationCollection;
use App\Http\Resources\Communication\CommunicationConversationLabelAssignmentResource;
use App\Http\Resources\Communication\CommunicationConversationResource;
use App\Http\Resources\Communication\CommunicationMessageResource;
use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Services\Communication\Conversation\CommunicationConversationQuery;
use Illuminate\Http\JsonResponse;

final class CommunicationConversationController extends Controller
{
    public function index(
        ListCommunicationConversationsRequest $request,
        CommunicationConversationQuery $query,
    ): JsonResponse {
        return (new CommunicationConversationCollection(
            $query->paginate($request->actor(), $request->filters()),
        ))->response();
    }

    public function show(
        ViewCommunicationConversationRequest $request,
        CommunicationConversation $conversation,
        CommunicationConversationQuery $query,
    ): CommunicationConversationResource {
        return new CommunicationConversationResource(
            $query->detail($conversation),
        );
    }

    public function update(
        UpdateConversationRequest $request,
        CommunicationConversation $conversation,
        UpdateCommunicationConversationAction $action,
    ): JsonResponse {
        return (new CommunicationConversationResource(
            $action->handle($conversation, $request->updateData()),
        ))->response();
    }

    public function send(
        SendMessageRequest $request,
        CommunicationConversation $conversation,
        CreateCommunicationMessageAction $action,
    ): JsonResponse {
        $result = $action->handle($conversation, $request->messageData());

        return (new CommunicationMessageResource(
            $result->message,
        ))->response()->setStatusCode($result->httpStatus);
    }

    public function addLabel(
        ManageCommunicationConversationLabelRequest $request,
        CommunicationConversation $conversation,
        CommunicationLabel $label,
        AssignCommunicationConversationLabelAction $action,
    ): JsonResponse {
        return (new CommunicationConversationLabelAssignmentResource(
            $action->handle($conversation, $label),
        ))->response()->setStatusCode(201);
    }

    public function removeLabel(
        ManageCommunicationConversationLabelRequest $request,
        CommunicationConversation $conversation,
        CommunicationLabel $label,
        RemoveCommunicationConversationLabelAction $action,
    ): JsonResponse {
        $action->handle($conversation, $label);

        return response()->json(status: 204);
    }
}
