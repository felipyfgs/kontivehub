<?php

namespace App\Http\Controllers\Api\V1\Assistant;

use App\Http\Controllers\Controller;
use App\Models\AssistantConversation;
use App\Models\AssistantMessage;
use App\Services\Assistant\AssistantAvailability;
use App\Support\CurrentOffice;
use App\Support\Work\RejectClientOfficeId;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AssistantConversationController extends Controller
{
    public function __construct(
        private readonly AssistantAvailability $availability,
    ) {}

    public function index(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $this->availability->assertEnabled();
        RejectClientOfficeId::strip($request);

        $user = $request->user();
        $perPage = min(max((int) $request->input('per_page', 20), 1), 50);

        $paginator = AssistantConversation::query()
            ->where('office_id', $currentOffice->id())
            ->where('user_id', $user->id)
            ->orderByDesc('updated_at')
            ->paginate($perPage);

        return response()->json([
            'data' => collect($paginator->items())->map(fn (AssistantConversation $c) => $this->publicConversation($c)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function store(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        $this->availability->assertEnabled();
        RejectClientOfficeId::strip($request);

        $data = $request->validate([
            'title' => ['nullable', 'string', 'max:200'],
        ]);

        $conversation = AssistantConversation::query()->create([
            'office_id' => $currentOffice->id(),
            'user_id' => $request->user()->id,
            'membership_id' => $currentOffice->membership()?->id,
            'title' => $data['title'] ?? null,
        ]);

        return response()->json(['data' => $this->publicConversation($conversation)], 201);
    }

    public function messages(
        Request $request,
        AssistantConversation $conversation,
        CurrentOffice $currentOffice,
    ): JsonResponse {
        $this->availability->assertEnabled();
        RejectClientOfficeId::strip($request);
        $this->assertOwned($conversation, $request, $currentOffice);

        $messages = AssistantMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('office_id', $currentOffice->id())
            ->orderBy('id')
            ->get();

        return response()->json([
            'data' => $messages->map(fn (AssistantMessage $m) => $this->publicMessage($m))->values(),
        ]);
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

    /**
     * @return array<string, mixed>
     */
    private function publicConversation(AssistantConversation $c): array
    {
        return [
            'id' => $c->id,
            'title' => $c->title,
            'created_at' => $c->created_at?->toIso8601String(),
            'updated_at' => $c->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function publicMessage(AssistantMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'tool_calls' => $m->tool_calls,
            'tool_results' => $m->tool_results,
            'created_at' => $m->created_at?->toIso8601String(),
        ];
    }
}
