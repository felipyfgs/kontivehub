<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\Communication\GatewayCommandType;
use App\Enums\Communication\InboxStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreInboxRequest;
use App\Http\Requests\Communication\UpdateInboxRequest;
use App\Http\Resources\Communication\CommunicationInboxResource;
use App\Models\CommunicationInbox;
use App\Models\CommunicationInboxMember;
use App\Models\CommunicationOutboxEntry;
use App\Models\TenantMembership;
use App\Models\User;
use App\Models\WorkDepartment;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\CommunicationAvailability;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\Outbox\CommunicationOutboxService;
use App\Services\Communication\Pairing\CommunicationPairingStateStore;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final class CommunicationInboxController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly CommunicationAccess $access,
        private readonly CommunicationOutboxService $outbox,
        private readonly CommunicationPairingStateStore $pairing,
        private readonly CommunicationEventRecorder $events,
        private readonly CommunicationAvailability $availability,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertView($actor);
        $ids = $this->access->visibleInboxIds($actor);
        $inboxes = CommunicationInbox::query()
            ->whereIn('id', $ids)
            ->with(['members' => fn ($query) => $query
                ->where('is_active', true)->with('membership.user')])
            ->withCount(['members' => fn ($query) => $query->where('is_active', true)])
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => CommunicationInboxResource::collection($inboxes),
            'meta' => [
                'global_enabled' => (bool) config('communication.enabled'),
                'gateway_enabled' => (bool) config('communication.gateway.enabled'),
                'tenant_enabled' => (bool) $this->currentTenant->tenant()->communication_enabled,
                'departments' => WorkDepartment::query()->where('is_active', true)
                    ->orderBy('name')->get(['id', 'name', 'code', 'color'])
                    ->map(fn (WorkDepartment $department) => [
                        'id' => (int) $department->id,
                        'name' => $department->name,
                        'code' => $department->code,
                        'color' => $department->color,
                        'is_active' => true,
                    ])->values(),
            ],
        ]);
    }

    public function store(StoreInboxRequest $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertManage($actor);
        $tenant = $this->currentTenant->tenant();
        $data = $request->validated();
        $departmentId = $this->departmentId($data['work_department_id'] ?? null, (int) $tenant->id);

        $inbox = DB::transaction(function () use ($tenant, $data, $departmentId): CommunicationInbox {
            if (($data['is_default'] ?? false) === true) {
                CommunicationInbox::query()->where('tenant_id', $tenant->id)->update(['is_default' => false]);
            }

            return CommunicationInbox::query()->create([
                'tenant_id' => $tenant->id,
                'name' => trim((string) $data['name']),
                'session_id' => 'session-'.strtolower((string) Str::ulid()),
                'status' => InboxStatus::Disconnected,
                'is_enabled' => (bool) ($data['is_enabled'] ?? false),
                'is_default' => (bool) ($data['is_default'] ?? false),
                'work_department_id' => $departmentId,
            ]);
        });
        $this->events->record((int) $tenant->id, 'INBOX_CREATED', [
            'inbox_id' => (int) $inbox->id,
            'name' => $inbox->name,
        ], inboxId: (int) $inbox->id, actorMembershipId: $this->currentTenant->realMembership()?->id);

        return (new CommunicationInboxResource($inbox))->response()->setStatusCode(201);
    }

    public function update(UpdateInboxRequest $request, int $inbox): JsonResponse
    {
        $actor = $this->actor($request);
        $model = $this->inbox($inbox);
        $this->access->assertManage($actor, $model);
        $data = $request->validated();
        $departmentId = array_key_exists('work_department_id', $data)
            ? $this->departmentId($data['work_department_id'], (int) $model->tenant_id)
            : $model->work_department_id;

        $updated = DB::transaction(function () use ($model, $data, $departmentId): ?CommunicationInbox {
            if (($data['is_default'] ?? false) === true) {
                CommunicationInbox::query()->where('tenant_id', $model->tenant_id)
                    ->where('id', '<>', $model->id)->update(['is_default' => false]);
            }
            $attributes = array_intersect_key($data, array_flip(['name', 'is_enabled', 'is_default']));
            $disabling = $model->is_enabled
                && array_key_exists('is_enabled', $data)
                && $data['is_enabled'] === false;
            if ($disabling && config('communication.enabled') && config('communication.gateway.enabled')) {
                $this->outbox->enqueue($model, GatewayCommandType::DisconnectSession, []);
                $attributes['status'] = InboxStatus::Disconnected;
            }
            $attributes['work_department_id'] = $departmentId;
            $attributes['lock_version'] = (int) $data['lock_version'] + 1;
            $changed = CommunicationInbox::query()
                ->whereKey($model->id)
                ->where('lock_version', $data['lock_version'])
                ->update($attributes);

            return $changed === 1 ? $model->fresh() : null;
        });
        if ($updated === null) {
            return response()->json(['message' => 'Inbox foi alterada por outro usuário.', 'code' => 'version_conflict'], 409);
        }
        $this->events->record((int) $updated->tenant_id, 'INBOX_UPDATED', [
            'inbox_id' => (int) $updated->id,
            'lock_version' => (int) $updated->lock_version,
        ], inboxId: (int) $updated->id, actorMembershipId: $this->currentTenant->realMembership()?->id);

        return (new CommunicationInboxResource($updated))->response();
    }

    public function updateTenantSettings(Request $request): JsonResponse
    {
        $actor = $this->actor($request);
        $this->access->assertManage($actor);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        $tenant = $this->currentTenant->tenant();

        if ($tenant->communication_enabled && ! $data['enabled']
            && config('communication.enabled') && config('communication.gateway.enabled')) {
            CommunicationInbox::query()
                ->where('tenant_id', $tenant->id)
                ->where('is_enabled', true)
                ->each(function (CommunicationInbox $inbox): void {
                    $this->outbox->enqueue($inbox, GatewayCommandType::DisconnectSession, []);
                    $inbox->forceFill([
                        'status' => InboxStatus::Disconnected,
                        'lock_version' => (int) $inbox->lock_version + 1,
                    ])->save();
                    $this->pairing->forget((int) $inbox->id);
                });
        }
        $tenant->forceFill(['communication_enabled' => (bool) $data['enabled']])->save();
        $this->events->record((int) $tenant->id, 'TENANT_COMMUNICATION_SWITCH_CHANGED', [
            'enabled' => (bool) $tenant->communication_enabled,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return response()->json(['data' => ['enabled' => (bool) $tenant->communication_enabled]]);
    }

    public function startPairing(Request $request, int $inbox): JsonResponse
    {
        $model = $this->inbox($inbox);
        $this->access->assertManage($this->actor($request), $model);
        $this->availability->assertEnabled($model);
        if ($model->status === InboxStatus::Connected) {
            $this->pairing->forget((int) $model->id);

            return response()->json(['data' => [
                'command_id' => null,
                'type' => GatewayCommandType::ConnectSession->value,
                'event' => 'success',
                'status' => InboxStatus::Connected->value,
                'commands' => [],
            ]], 202);
        }
        $state = [
            'event' => 'pending',
            'status' => InboxStatus::Connecting->value,
            'expires_at' => now()->addMinutes(2)->toIso8601String(),
            'commands' => [],
        ];
        if (! $this->pairing->reserve((int) $model->id, $state)) {
            return response()->json(['data' => $this->pairing->get((int) $model->id) ?? $state], 202);
        }

        try {
            $connect = DB::transaction(function () use ($model) {
                $entry = $this->outbox->enqueue($model, GatewayCommandType::ConnectSession, []);
                $model->forceFill([
                    'status' => InboxStatus::Connecting,
                    'revoked_at' => null,
                    'lock_version' => (int) $model->lock_version + 1,
                ])->save();

                return $entry;
            });
        } catch (Throwable $exception) {
            $this->pairing->forget((int) $model->id);

            throw $exception;
        }

        $state['command_id'] = $connect->command_id;
        $state['type'] = $connect->type->value;
        $state['commands'] = [$connect->command_id];
        $this->pairing->put((int) $model->id, $state);

        return response()->json(['data' => $state], 202);
    }

    public function revoke(Request $request, int $inbox): JsonResponse
    {
        $model = $this->inbox($inbox);
        $this->access->assertManage($this->actor($request), $model);
        if ($model->status === InboxStatus::Disconnected && $model->revoked_at !== null) {
            return response()->json(['data' => [
                'command_id' => null,
                'type' => GatewayCommandType::LogoutSession->value,
                'status' => InboxStatus::Disconnected->value,
            ]], 202);
        }
        $entry = $this->outbox->enqueue($model, GatewayCommandType::LogoutSession, []);
        $model->forceFill([
            'status' => InboxStatus::Disconnected,
            'revoked_at' => now(),
            'lock_version' => (int) $model->lock_version + 1,
        ])->save();
        $this->pairing->forget((int) $model->id);

        return response()->json(['data' => [
            'command_id' => $entry->command_id,
            'type' => $entry->type->value,
            'status' => InboxStatus::Disconnected->value,
        ]], 202);
    }

    public function replaceMembers(Request $request, int $inbox): JsonResponse
    {
        $model = $this->inbox($inbox);
        $this->access->assertManage($this->actor($request), $model);
        $data = $request->validate(['membership_ids' => ['present', 'array', 'max:500'], 'membership_ids.*' => ['integer', 'min:1']]);
        $ids = array_values(array_unique(array_map('intval', $data['membership_ids'])));
        $valid = TenantMembership::query()->where('tenant_id', $model->tenant_id)
            ->where('is_active', true)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        if (count($valid) !== count($ids)) {
            return response()->json(['message' => 'Membership inválida para este escritório.'], 422);
        }

        DB::transaction(function () use ($model, $ids): void {
            CommunicationInboxMember::query()->withoutGlobalScopes()->where('inbox_id', $model->id)->delete();
            foreach ($ids as $membershipId) {
                CommunicationInboxMember::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $model->tenant_id,
                    'inbox_id' => $model->id,
                    'tenant_membership_id' => $membershipId,
                    'is_active' => true,
                ]);
            }
        });

        return response()->json(['data' => ['membership_ids' => $ids]]);
    }

    public function destroy(Request $request, int $inbox): JsonResponse
    {
        $model = $this->inbox($inbox);
        $this->access->assertManage($this->actor($request), $model);
        $actorMembershipId = $this->currentTenant->realMembership()?->id;
        $tenantId = (int) $model->tenant_id;
        $inboxId = (int) $model->id;

        $entry = DB::transaction(function () use ($model): CommunicationOutboxEntry {
            $locked = CommunicationInbox::query()->lockForUpdate()->findOrFail($model->id);
            $entry = $this->outbox->enqueue($locked, GatewayCommandType::LogoutSession, []);
            $wasDefault = (bool) $locked->is_default;
            $lockedTenantId = (int) $locked->tenant_id;

            // Preserva o Logout despachável: session_id fica na outbox sem FK para a inbox.
            CommunicationOutboxEntry::query()->withoutGlobalScopes()
                ->where('inbox_id', $locked->id)
                ->update(['inbox_id' => null]);

            // Hard delete antes de promover o default — o índice parcial
            // comm_inboxes_one_default_per_tenant não permite dois is_default=true.
            $locked->delete();

            if ($wasDefault) {
                $replacement = CommunicationInbox::query()
                    ->where('tenant_id', $lockedTenantId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->first();
                if ($replacement !== null) {
                    $replacement->forceFill([
                        'is_default' => true,
                        'lock_version' => (int) $replacement->lock_version + 1,
                    ])->save();
                }
            }

            return $entry->refresh();
        });

        $this->pairing->forget($inboxId);
        $this->events->record($tenantId, 'INBOX_DELETED', [
            'inbox_id' => $inboxId,
            'history_preserved' => false,
        ], inboxId: null, actorMembershipId: $actorMembershipId);

        return response()->json(['data' => [
            'command_id' => $entry->command_id,
            'type' => $entry->type->value,
            'status' => InboxStatus::Disconnected->value,
            'deleted' => true,
        ]], 202);
    }

    private function inbox(int $id): CommunicationInbox
    {
        return CommunicationInbox::query()->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function departmentId(mixed $id, int $tenantId): ?int
    {
        if ($id === null) {
            return null;
        }
        $exists = WorkDepartment::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)->whereKey((int) $id)->exists();
        abort_unless($exists, 422, 'Departamento inválido para este escritório.');

        return (int) $id;
    }
}
