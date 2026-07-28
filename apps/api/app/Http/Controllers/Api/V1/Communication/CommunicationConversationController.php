<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\AssignCommunicationConversationLabelAction;
use App\Actions\Communication\CreateCommunicationMessageAction;
use App\Actions\Communication\MarkCommunicationConversationReadAction;
use App\Actions\Communication\MarkCommunicationConversationUnreadAction;
use App\Actions\Communication\RemoveCommunicationConversationLabelAction;
use App\Actions\Communication\UpdateCommunicationConversationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListCommunicationConversationMessagesRequest;
use App\Http\Requests\Communication\ListCommunicationConversationsRequest;
use App\Http\Requests\Communication\ManageCommunicationConversationLabelRequest;
use App\Http\Requests\Communication\SendMessageRequest;
use App\Http\Requests\Communication\UpdateConversationRequest;
use App\Http\Requests\Communication\UpdateCommunicationConversationReadStateRequest;
use App\Http\Requests\Communication\ViewCommunicationConversationRequest;
use App\Http\Resources\Communication\CommunicationConversationCollection;
use App\Http\Resources\Communication\CommunicationConversationLabelAssignmentResource;
use App\Http\Resources\Communication\CommunicationConversationResource;
use App\Http\Resources\Communication\CommunicationMessageResource;
use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use App\Services\Communication\Conversation\CommunicationConversationMessageQuery;
use App\Services\Communication\Conversation\CommunicationConversationQuery;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

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
            $query->detail($conversation, $request->includeMessages()),
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

    public function messages(
        ListCommunicationConversationMessagesRequest $request,
        CommunicationConversation $conversation,
        CommunicationConversationCanonicalizer $canonicalizer,
        CommunicationConversationMessageQuery $query,
    ): JsonResponse {
        $resolved = $canonicalizer->conversation($conversation);

        try {
            $page = $query->paginate(
                conversation: $resolved,
                limit: $request->limit(),
                cursor: $request->cursor(),
                anchor: $request->anchor(),
            );
        } catch (InvalidArgumentException $error) {
            return response()->json([
                'message' => $error->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => CommunicationMessageResource::collection($page['data']),
            'meta' => $page['meta'],
        ]);
    }

    public function updateReadState(
        UpdateCommunicationConversationReadStateRequest $request,
        CommunicationConversation $conversation,
        MarkCommunicationConversationReadAction $markRead,
        MarkCommunicationConversationUnreadAction $markUnread,
    ): JsonResponse {
        $updated = $request->state() === 'READ'
            ? $markRead->handle($conversation, $request->throughMessageId())
            : $markUnread->handle($conversation, $request->expectedVersion());

        return (new CommunicationConversationResource($updated))->response();
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
