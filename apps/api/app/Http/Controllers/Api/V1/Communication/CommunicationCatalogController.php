<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreCannedResponseRequest;
use App\Http\Requests\Communication\UpdateCannedResponseRequest;
use App\Models\CommunicationCannedResponse;
use App\Models\CommunicationConversation;
use App\Models\CommunicationLabel;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\Canned\CannedResponseRenderer;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Support\CurrentTenant;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CommunicationCatalogController extends Controller
{
    public function __construct(
        private readonly CommunicationAccess $access,
        private readonly CurrentTenant $currentTenant,
        private readonly CommunicationEventRecorder $events,
        private readonly CannedResponseRenderer $renderer,
    ) {}

    public function labels(Request $request): JsonResponse
    {
        $this->access->assertView($this->actor($request));

        return response()->json(['data' => CommunicationLabel::query()->orderBy('name')->get()->map(fn ($label) => [
            'id' => $label->id,
            'name' => $label->name,
            'color' => $label->color,
        ])]);
    }

    public function outboundCapabilities(Request $request): JsonResponse
    {
        $this->access->assertView($this->actor($request));
        $enabled = (bool) config('communication.enabled')
            && (bool) config('communication.gateway.enabled')
            && (bool) $this->currentTenant->tenant()->communication_enabled;

        return response()->json(['data' => [
            'enabled' => $enabled,
            'requires_permission' => 'communication.reply',
            'kinds' => [
                'TEXT' => ['supported' => true, 'max_text_bytes' => 4096, 'link_preview' => true],
                'IMAGE' => ['supported' => true, 'mime_types' => ['image/jpeg', 'image/png', 'image/webp']],
                'AUDIO' => ['supported' => true, 'ptt' => true, 'mime_types' => ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/webm']],
                'VIDEO' => ['supported' => true, 'gif' => false, 'mime_types' => ['video/mp4', 'video/webm']],
                'DOCUMENT' => ['supported' => true, 'mime_types' => ['application/pdf', 'text/plain', 'application/zip']],
                'STICKER' => ['supported' => true, 'mime_types' => ['image/webp']],
                'LOCATION' => ['supported' => true],
                'CONTACT' => ['supported' => true, 'multiple' => false],
                'POLL' => ['supported' => true, 'max_options' => 12],
                'INTERACTIVE' => ['supported' => true, 'modes' => ['BUTTONS', 'LIST'], 'max_options' => 20],
                'UNSUPPORTED' => ['supported' => false, 'error_code' => 'MESSAGE_KIND_UNSUPPORTED'],
            ],
            'max_media_bytes' => (int) config('communication.media.max_bytes', 20_971_520),
        ]])->header('Cache-Control', 'private, no-store');
    }

    public function storeLabel(Request $request): JsonResponse
    {
        $this->access->assertManage($this->actor($request));
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'color' => ['nullable', 'string', 'regex:/^(neutral|red|orange|amber|yellow|lime|green|emerald|teal|cyan|sky|blue|indigo|violet|purple|fuchsia|pink|rose)$/'],
        ]);
        $label = CommunicationLabel::query()->create([
            'tenant_id' => $this->currentTenant->tenant()->id,
            'name' => trim($data['name']),
            'color' => $data['color'] ?? 'neutral',
        ]);

        return response()->json(['data' => ['id' => $label->id, 'name' => $label->name, 'color' => $label->color]], 201);
    }

    public function deleteLabel(Request $request, int $label): JsonResponse
    {
        $model = CommunicationLabel::query()->findOrFail($label);
        $this->access->assertManage($this->actor($request), $model);
        $model->delete();

        return response()->json(status: 204);
    }

    public function cannedResponses(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $manageMode = $request->boolean('manage') || $request->has('is_active');

        if ($manageMode) {
            $this->access->assertManageQuickReplies($actor);
        } else {
            $this->access->assertView($actor);
        }

        $query = CommunicationCannedResponse::query();

        if ($manageMode) {
            if ($request->has('is_active')) {
                $query->where('is_active', $request->boolean('is_active'));
            }
        } else {
            $query->where('is_active', true);
        }

        if ($search = trim($request->string('q')->toString())) {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(fn ($builder) => $builder
                ->whereRaw('LOWER(title) LIKE ?', [$needle])
                ->orWhereRaw('LOWER(shortcut) LIKE ?', [$needle]));
        }

        $query->orderBy('shortcut');

        if (! $manageMode && ! $request->has('page') && ! $request->has('per_page')) {
            return response()->json([
                'data' => $query->get()->map(fn (CommunicationCannedResponse $item) => $this->cannedPayload($item)),
            ]);
        }

        $paginator = $query->paginate(min(100, max(1, $request->integer('per_page', 30))));

        return response()->json([
            'data' => collect($paginator->items())->map(fn (CommunicationCannedResponse $item) => $this->cannedPayload($item)),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function storeCannedResponse(StoreCannedResponseRequest $request): JsonResponse
    {
        $this->access->assertManageQuickReplies($this->actor($request));
        $data = $request->validated();
        $shortcut = strtolower(trim($data['shortcut']));
        $tenantId = (int) $this->currentTenant->tenant()->id;

        if ($this->shortcutExists($tenantId, $shortcut)) {
            return $this->shortcutConflictResponse();
        }

        try {
            $item = CommunicationCannedResponse::query()->create([
                'tenant_id' => $tenantId,
                'title' => trim($data['title']),
                'shortcut' => $shortcut,
                'body_encrypted' => $data['body'],
                'is_active' => (bool) ($data['is_active'] ?? true),
                'lock_version' => 1,
                'created_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->shortcutConflictResponse();
            }
            throw $e;
        }

        $this->events->record($tenantId, 'CANNED_RESPONSE_CREATED', [
            'canned_response_id' => (int) $item->id,
            'shortcut' => $item->shortcut,
            'lock_version' => (int) $item->lock_version,
            'is_active' => (bool) $item->is_active,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->cannedPayload($item)], 201);
    }

    public function updateCannedResponse(UpdateCannedResponseRequest $request, int $canned): JsonResponse
    {
        $model = CommunicationCannedResponse::query()->findOrFail($canned);
        $this->access->assertManageQuickReplies($this->actor($request), $model);
        $data = $request->validated();
        $shortcut = strtolower(trim($data['shortcut']));
        $tenantId = (int) $model->tenant_id;

        if ($this->shortcutExists($tenantId, $shortcut, (int) $model->id)) {
            return $this->shortcutConflictResponse();
        }

        try {
            $updated = DB::transaction(function () use ($model, $data, $shortcut): ?CommunicationCannedResponse {
                $fresh = CommunicationCannedResponse::query()
                    ->whereKey($model->id)
                    ->lockForUpdate()
                    ->first();
                if ($fresh === null || (int) $fresh->lock_version !== (int) $data['lock_version']) {
                    return null;
                }

                $fresh->fill([
                    'title' => trim($data['title']),
                    'shortcut' => $shortcut,
                    'body_encrypted' => $data['body'],
                    'is_active' => (bool) ($data['is_active'] ?? $fresh->is_active),
                    'lock_version' => (int) $data['lock_version'] + 1,
                ]);
                $fresh->save();

                return $fresh;
            });
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->shortcutConflictResponse();
            }
            throw $e;
        }

        if ($updated === null) {
            return response()->json([
                'message' => 'Resposta rápida foi alterada por outro usuário.',
                'code' => 'version_conflict',
            ], 409);
        }

        $this->events->record($tenantId, 'CANNED_RESPONSE_UPDATED', [
            'canned_response_id' => (int) $updated->id,
            'shortcut' => $updated->shortcut,
            'lock_version' => (int) $updated->lock_version,
            'is_active' => (bool) $updated->is_active,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->cannedPayload($updated)]);
    }

    public function duplicateCannedResponse(Request $request, int $canned): JsonResponse
    {
        $source = CommunicationCannedResponse::query()->findOrFail($canned);
        $this->access->assertManageQuickReplies($this->actor($request), $source);
        $data = $request->validate([
            'shortcut' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/'],
            'title' => ['sometimes', 'string', 'max:120'],
        ]);
        $shortcut = strtolower(trim($data['shortcut']));
        $tenantId = (int) $source->tenant_id;

        if ($this->shortcutExists($tenantId, $shortcut)) {
            return $this->shortcutConflictResponse();
        }

        try {
            $item = CommunicationCannedResponse::query()->create([
                'tenant_id' => $tenantId,
                'title' => isset($data['title']) ? trim($data['title']) : $source->title,
                'shortcut' => $shortcut,
                'body_encrypted' => $source->body_encrypted,
                'is_active' => true,
                'lock_version' => 1,
                'created_by_membership_id' => $this->currentTenant->realMembership()?->id,
            ]);
        } catch (QueryException $e) {
            if ($this->isUniqueConstraintViolation($e)) {
                return $this->shortcutConflictResponse();
            }
            throw $e;
        }

        $this->events->record($tenantId, 'CANNED_RESPONSE_DUPLICATED', [
            'canned_response_id' => (int) $item->id,
            'source_canned_response_id' => (int) $source->id,
            'shortcut' => $item->shortcut,
            'lock_version' => (int) $item->lock_version,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->cannedPayload($item)], 201);
    }

    public function deactivateCannedResponse(Request $request, int $canned): JsonResponse
    {
        $model = CommunicationCannedResponse::query()->findOrFail($canned);
        $this->access->assertManageQuickReplies($this->actor($request), $model);

        if (! $model->is_active) {
            return response()->json(['data' => $this->cannedPayload($model)]);
        }

        $model->forceFill([
            'is_active' => false,
            'lock_version' => (int) $model->lock_version + 1,
        ])->save();

        $this->events->record((int) $model->tenant_id, 'CANNED_RESPONSE_DEACTIVATED', [
            'canned_response_id' => (int) $model->id,
            'shortcut' => $model->shortcut,
            'lock_version' => (int) $model->lock_version,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => $this->cannedPayload($model->fresh() ?? $model)]);
    }

    public function renderCannedResponse(Request $request, int $canned): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertView($actor);
        $model = CommunicationCannedResponse::query()->findOrFail($canned);
        abort_unless((bool) $model->is_active, 404);

        $data = $request->validate([
            'conversation_id' => ['required', 'integer', 'min:1'],
        ]);

        $conversation = CommunicationConversation::query()->findOrFail((int) $data['conversation_id']);

        try {
            $body = $this->renderer->render($model, $conversation, $actor);
        } catch (\InvalidArgumentException $e) {
            if ($e->getMessage() === 'cross_tenant') {
                abort(404);
            }
            throw $e;
        }

        return response()->json([
            'data' => [
                'canned_response_id' => (int) $model->id,
                'conversation_id' => (int) $conversation->id,
                'body' => $body,
            ],
        ]);
    }

    public function deleteCannedResponse(Request $request, int $canned): JsonResponse
    {
        $model = CommunicationCannedResponse::query()->findOrFail($canned);
        $this->access->assertManageQuickReplies($this->actor($request), $model);
        $model->delete();

        return response()->json(status: 204);
    }

    /** @return array{id: int, title: string, shortcut: string, body: string, is_active: bool, lock_version: int} */
    private function cannedPayload(CommunicationCannedResponse $item): array
    {
        return [
            'id' => (int) $item->id,
            'title' => $item->title,
            'shortcut' => $item->shortcut,
            'body' => (string) $item->body_encrypted,
            'is_active' => (bool) $item->is_active,
            'lock_version' => (int) $item->lock_version,
        ];
    }

    private function shortcutExists(int $tenantId, string $shortcut, ?int $exceptId = null): bool
    {
        $query = CommunicationCannedResponse::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('shortcut', $shortcut);
        if ($exceptId !== null) {
            $query->whereKeyNot($exceptId);
        }

        return $query->exists();
    }

    private function shortcutConflictResponse(): JsonResponse
    {
        return response()->json([
            'message' => 'Já existe uma resposta rápida com este atalho neste escritório.',
            'code' => 'shortcut_conflict',
        ], 409);
    }

    private function isUniqueConstraintViolation(QueryException $e): bool
    {
        $sqlState = (string) ($e->errorInfo[0] ?? '');
        if ($sqlState === '23505' || $sqlState === '23000') {
            return true;
        }
        $message = strtolower($e->getMessage());

        return str_contains($message, 'unique') || str_contains($message, 'duplicate');
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
