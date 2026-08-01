<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\AssignConversationLabelAction;
use App\Actions\Communication\MarkConversationReadAction;
use App\Actions\Communication\MarkConversationUnreadAction;
use App\Actions\Communication\RemoveConversationLabelAction;
use App\Actions\Communication\StartConversationAction;
use App\Actions\Communication\UpdateConversationAction;
use App\Contracts\CommunicationOutboundMessageWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListConversationMessagesRequest;
use App\Http\Requests\Communication\ListConversationsRequest;
use App\Http\Requests\Communication\ListSharedContentRequest;
use App\Http\Requests\Communication\ManageConversationLabelRequest;
use App\Http\Requests\Communication\SendMessageRequest;
use App\Http\Requests\Communication\StoreConversationRequest;
use App\Http\Requests\Communication\UpdateConversationReadStateRequest;
use App\Http\Requests\Communication\UpdateConversationRequest;
use App\Http\Requests\Communication\ViewConversationRequest;
use App\Http\Resources\Communication\ConversationCollection;
use App\Http\Resources\Communication\ConversationLabelAssignmentResource;
use App\Http\Resources\Communication\ConversationResource;
use App\Http\Resources\Communication\MessageResource;
use App\Http\Resources\Communication\SharedContentResource;
use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Services\Communication\Authorization\Access;
use App\Services\Communication\Conversation\ConversationMessageQuery;
use App\Services\Communication\Conversation\ConversationQuery;
use App\Services\Communication\Conversation\SharedContentQuery;
use App\Services\Communication\ConversationCanonicalizer;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;

final class ConversationController extends Controller
{
    public function index(
        ListConversationsRequest $request,
        ConversationQuery $query,
    ): JsonResponse {
        return (new ConversationCollection(
            $query->paginate($request->actor(), $request->filters()),
        ))->response();
    }

    public function store(
        StoreConversationRequest $request,
        StartConversationAction $action,
    ): JsonResponse {
        $result = $action->handle($request->contactId(), $request->identityId(), $request->inboxId(), $request->messageData());

        return response()->json([
            'data' => [
                'conversation' => (new ConversationResource($result['conversation']))->resolve(),
                'message' => (new MessageResource($result['message']))->resolve(),
                'reused_conversation' => $result['reused'],
            ],
        ], $result['status']);
    }

    public function show(
        ViewConversationRequest $request,
        CommunicationConversation $conversation,
        ConversationQuery $query,
    ): ConversationResource {
        return new ConversationResource(
            $query->detail($conversation, $request->includeMessages()),
        );
    }

    public function update(
        UpdateConversationRequest $request,
        CommunicationConversation $conversation,
        UpdateConversationAction $action,
        ConversationQuery $query,
    ): JsonResponse {
        $updated = $action->handle($conversation, $request->updateData());

        return (new ConversationResource(
            $query->detail($updated, false),
        ))->response();
    }

    public function send(
        SendMessageRequest $request,
        CommunicationConversation $conversation,
        CommunicationOutboundMessageWriter $action,
    ): JsonResponse {
        $result = $action->handle($conversation, $request->messageData());

        return (new MessageResource(
            $result->message,
        ))->response()->setStatusCode($result->httpStatus);
    }

    public function messages(
        ListConversationMessagesRequest $request,
        CommunicationConversation $conversation,
        ConversationCanonicalizer $canonicalizer,
        ConversationMessageQuery $query,
    ): JsonResponse {
        $resolved = $canonicalizer->conversation($conversation);

        try {
            $page = $query->paginate(
                conversation: $resolved,
                limit: $request->limit(),
                cursor: $request->cursor(),
                anchor: $request->anchor(),
                messageId: $request->messageId(),
            );
        } catch (InvalidArgumentException $error) {
            return response()->json([
                'message' => $error->getMessage(),
            ], 422);
        }

        return response()->json([
            'data' => MessageResource::collection($page['data']),
            'meta' => $page['meta'],
        ]);
    }

    public function sharedContent(
        ListSharedContentRequest $request,
        CommunicationConversation $conversation,
        ConversationCanonicalizer $canonicalizer,
        Access $access,
        SharedContentQuery $query,
    ): JsonResponse {
        $conversation = $canonicalizer->conversation($conversation);
        $actor = $request->user();
        $inbox = $conversation->inbox()->first();
        if ($actor === null || $inbox === null || ! $access->canView($actor, $inbox)) {
            abort(404);
        }
        try {
            $page = $query->paginate(
                (int) $conversation->tenant_id,
                [(int) $conversation->id],
                $request->category(),
                $request->limit(),
                $request->cursor(),
                'conversation:'.$conversation->id,
            );
        } catch (InvalidArgumentException $error) {
            return response()->json(['message' => $error->getMessage()], 422);
        }

        return response()->json(['data' => SharedContentResource::collection($page['data']), 'meta' => $page['meta']])
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
    }

    public function updateReadState(
        UpdateConversationReadStateRequest $request,
        CommunicationConversation $conversation,
        MarkConversationReadAction $markRead,
        MarkConversationUnreadAction $markUnread,
    ): JsonResponse {
        $updated = $request->state() === 'READ'
            ? $markRead->handle($conversation, $request->throughMessageId())
            : $markUnread->handle($conversation, $request->expectedVersion());

        return (new ConversationResource($updated))->response();
    }

    public function addLabel(
        ManageConversationLabelRequest $request,
        CommunicationConversation $conversation,
        CommunicationLabel $label,
        AssignConversationLabelAction $action,
    ): JsonResponse {
        return (new ConversationLabelAssignmentResource(
            $action->handle($conversation, $label),
        ))->response()->setStatusCode(201);
    }

    public function removeLabel(
        ManageConversationLabelRequest $request,
        CommunicationConversation $conversation,
        CommunicationLabel $label,
        RemoveConversationLabelAction $action,
    ): JsonResponse {
        $action->handle($conversation, $label);

        return response()->json(status: 204);
    }
}
