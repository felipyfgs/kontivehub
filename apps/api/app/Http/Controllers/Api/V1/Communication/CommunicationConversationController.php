<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Actions\Communication\AssignCommunicationConversationLabelAction;
use App\Actions\Communication\MarkCommunicationConversationReadAction;
use App\Actions\Communication\MarkCommunicationConversationUnreadAction;
use App\Actions\Communication\RemoveCommunicationConversationLabelAction;
use App\Actions\Communication\StartCommunicationConversationAction;
use App\Actions\Communication\UpdateCommunicationConversationAction;
use App\Contracts\CommunicationOutboundMessageWriter;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\ListCommunicationConversationMessagesRequest;
use App\Http\Requests\Communication\ListCommunicationConversationsRequest;
use App\Http\Requests\Communication\ListCommunicationSharedContentRequest;
use App\Http\Requests\Communication\ManageCommunicationConversationLabelRequest;
use App\Http\Requests\Communication\SendMessageRequest;
use App\Http\Requests\Communication\StoreCommunicationConversationRequest;
use App\Http\Requests\Communication\UpdateCommunicationConversationReadStateRequest;
use App\Http\Requests\Communication\UpdateConversationRequest;
use App\Http\Requests\Communication\ViewCommunicationConversationRequest;
use App\Http\Resources\Communication\CommunicationConversationCollection;
use App\Http\Resources\Communication\CommunicationConversationLabelAssignmentResource;
use App\Http\Resources\Communication\CommunicationConversationResource;
use App\Http\Resources\Communication\CommunicationMessageResource;
use App\Http\Resources\Communication\CommunicationSharedContentResource;
use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\CommunicationConversationCanonicalizer;
use App\Services\Communication\Conversation\CommunicationConversationMessageQuery;
use App\Services\Communication\Conversation\CommunicationConversationQuery;
use App\Services\Communication\Conversation\CommunicationSharedContentQuery;
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

    public function store(
        StoreCommunicationConversationRequest $request,
        StartCommunicationConversationAction $action,
    ): JsonResponse {
        $result = $action->handle($request->contactId(), $request->identityId(), $request->inboxId(), $request->messageData());

        return response()->json([
            'data' => [
                'conversation' => (new CommunicationConversationResource($result['conversation']))->resolve(),
                'message' => (new CommunicationMessageResource($result['message']))->resolve(),
                'reused_conversation' => $result['reused'],
            ],
        ], $result['status']);
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
        CommunicationConversationQuery $query,
    ): JsonResponse {
        $updated = $action->handle($conversation, $request->updateData());

        return (new CommunicationConversationResource(
            $query->detail($updated, false),
        ))->response();
    }

    public function send(
        SendMessageRequest $request,
        CommunicationConversation $conversation,
        CommunicationOutboundMessageWriter $action,
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
                messageId: $request->messageId(),
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

    public function sharedContent(
        ListCommunicationSharedContentRequest $request,
        CommunicationConversation $conversation,
        CommunicationConversationCanonicalizer $canonicalizer,
        CommunicationAccess $access,
        CommunicationSharedContentQuery $query,
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

        return response()->json(['data' => CommunicationSharedContentResource::collection($page['data']), 'meta' => $page['meta']])
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('Pragma', 'no-cache');
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
