<?php

namespace App\Http\Controllers\Api\V1\Assistant;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\Assistant\AssistantAvailability;
use App\Services\Assistant\AssistantChatService;
use App\Services\Assistant\AssistantUiMessageStream;
use App\Support\CurrentOffice;
use App\Support\Work\RejectClientOfficeId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssistantChatController extends Controller
{
    public function __construct(
        private readonly AssistantAvailability $availability,
        private readonly AssistantChatService $chat,
        private readonly AssistantUiMessageStream $stream,
    ) {}

    public function chat(
        Request $request,
        AssistantConversation $conversation,
        CurrentOffice $currentOffice,
    ): JsonResponse|StreamedResponse {
        $this->availability->assertEnabled();
        RejectClientOfficeId::strip($request);
        $this->assertOwned($conversation, $request, $currentOffice);

        $data = $request->validate([
            'message' => ['required', 'string', 'max:8000'],
        ]);

        $turn = $this->chat->chat($conversation, $request->user(), $data['message']);

        $wantsJson = $request->expectsJson()
            && ! str_contains((string) $request->header('Accept'), 'text/event-stream')
            && $request->header('X-Assistant-Stream') !== '1';

        if ($wantsJson || $request->input('format') === 'json') {
            return response()->json([
                'data' => [
                    'assistant_text' => $turn['assistant_text'],
                    'pending_approvals' => $turn['pending_approvals'],
                    'messages' => collect($turn['messages'])->map(fn (AssistantMessage $m) => [
                        'id' => $m->id,
                        'role' => $m->role,
                        'content' => $m->content,
                        'tool_calls' => $m->tool_calls,
                        'tool_results' => $m->tool_results,
                        'created_at' => $m->created_at?->toIso8601String(),
                    ])->values(),
                ],
            ]);
        }

        $assistantMessage = collect($turn['messages'])->last(
            fn (AssistantMessage $m) => $m->role === 'assistant',
        );

        return response()->stream(function () use ($turn, $assistantMessage): void {
            foreach ($this->stream->encode([
                'assistant_text' => $turn['assistant_text'],
                'pending_approvals' => $turn['pending_approvals'],
                'message_id' => $assistantMessage ? 'msg_'.$assistantMessage->id : null,
            ]) as $chunk) {
                echo $chunk;
                if (function_exists('ob_flush')) {
                    @ob_flush();
                }
                flush();
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=utf-8',
            'Cache-Control' => 'no-cache, no-transform',
            'X-Accel-Buffering' => 'no',
            'X-Vercel-AI-UI-Message-Stream' => 'v1',
        ]);
    }

    public function approve(
        Request $request,
        AssistantConversation $conversation,
        CurrentOffice $currentOffice,
    ): JsonResponse {
        $this->availability->assertEnabled();
        RejectClientOfficeId::strip($request);
        $this->assertOwned($conversation, $request, $currentOffice);

        $data = $request->validate([
            'approval_token' => ['required', 'string', 'max:64'],
        ]);

        $outcome = $this->chat->approveTool($conversation, $request->user(), $data['approval_token']);

        return response()->json([
            'data' => [
                'status' => $outcome['status'],
                'result' => $outcome['result'] ?? null,
                'error' => $outcome['error'] ?? null,
                'message' => isset($outcome['message']) && $outcome['message'] instanceof AssistantMessage
                    ? [
                        'id' => $outcome['message']->id,
                        'role' => $outcome['message']->role,
                        'content' => $outcome['message']->content,
                        'created_at' => $outcome['message']->created_at?->toIso8601String(),
                    ]
                    : null,
            ],
        ], ($outcome['status'] ?? null) === 'ok' ? 201 : 422);
    }

    public function deny(
        Request $request,
        AssistantConversation $conversation,
        CurrentOffice $currentOffice,
    ): JsonResponse {
        $this->availability->assertEnabled();
        RejectClientOfficeId::strip($request);
        $this->assertOwned($conversation, $request, $currentOffice);

        $data = $request->validate([
            'approval_token' => ['required', 'string', 'max:64'],
        ]);

        $outcome = $this->chat->denyTool($conversation, $request->user(), $data['approval_token']);

        return response()->json([
            'data' => [
                'status' => $outcome['status'],
                'error' => $outcome['error'] ?? null,
                'message' => isset($outcome['message']) && $outcome['message'] instanceof AssistantMessage
                    ? [
                        'id' => $outcome['message']->id,
                        'role' => $outcome['message']->role,
                        'content' => $outcome['message']->content,
                        'created_at' => $outcome['message']->created_at?->toIso8601String(),
                    ]
                    : null,
            ],
        ], ($outcome['status'] ?? null) === 'denied' ? 200 : 422);
    }

    private function assertOwned(
        AssistantConversation $conversation,
        Request $request,
        CurrentOffice $currentOffice,
    ): void {
        if ((int) $conversation->office_id !== (int) $currentOffice->id()
            || (int) $conversation->user_id !== (int) $request->user()->id) {
            abort(404);
        }
    }
}
