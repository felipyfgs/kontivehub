<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\Communication\RecipientMode;
use App\Enums\CommunicationChannel;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientCommunicationPreference;
use App\Models\CommunicationAutomationPolicy;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationInbox;
use App\Models\CommunicationPreferenceRecipient;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Support\CurrentOffice;
use DateTimeZone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

final class CommunicationAutomationController extends Controller
{
    private const SCOPES = [
        'simples_mei:pgdasd',
        'simples_mei:pgmei',
        'dctfweb:dctfweb',
        'fgts:fgts',
    ];

    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly CommunicationAccess $access,
        private readonly CommunicationEventRecorder $events,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertManage($this->actor($request));
        $office = $this->currentOffice->office();
        $policies = CommunicationAutomationPolicy::query()
            ->with('inbox')
            ->orderBy('module_key')->orderBy('submodule_key')->get();

        return response()->json([
            'data' => $policies->map(fn (CommunicationAutomationPolicy $policy): array => $this->policyArray($policy)),
            'meta' => [
                'supported_scopes' => self::SCOPES,
                'inboxes' => CommunicationInbox::query()->orderByDesc('is_default')->orderBy('name')->get()
                    ->map(fn (CommunicationInbox $inbox) => [
                        'id' => (int) $inbox->id,
                        'name' => $inbox->name,
                        'status' => $inbox->status?->value ?? $inbox->status,
                        'enabled' => (bool) $inbox->is_enabled,
                    ])->values(),
                'office_enabled' => (bool) $office->communication_enabled,
                'global_enabled' => (bool) config('communication.enabled'),
            ],
        ]);
    }

    public function upsert(Request $request): JsonResponse
    {
        $this->access->assertManage($this->actor($request));
        $office = $this->currentOffice->office();
        $data = $request->validate([
            'module_key' => ['required', 'string', 'max:40'],
            'submodule_key' => ['required', 'string', 'max:40'],
            'inbox_id' => ['nullable', 'integer', 'min:1'],
            'is_enabled' => ['required', 'boolean'],
            'send_day' => ['required', 'integer', 'between:1,28'],
            'send_time' => ['required', 'date_format:H:i'],
            'timezone' => ['required', 'string', 'max:64'],
            'recipient_mode' => ['required', Rule::enum(RecipientMode::class)],
            'template_key' => ['required', 'string', 'max:80', 'regex:/^[a-z0-9._-]+$/i'],
            'template_version' => ['required', 'string', 'max:40'],
            'lock_version' => ['required', 'integer', 'min:0'],
        ]);
        abort_unless(in_array($data['module_key'].':'.$data['submodule_key'], self::SCOPES, true), 422, 'Módulo de automação não suportado.');
        abort_unless(in_array($data['timezone'], DateTimeZone::listIdentifiers(), true), 422, 'Timezone inválido.');
        $inbox = $data['inbox_id'] !== null
            ? CommunicationInbox::query()->find((int) $data['inbox_id'])
            : null;
        abort_if($data['inbox_id'] !== null && $inbox === null, 422, 'Inbox inválida para este escritório.');
        abort_if($data['is_enabled'] && $inbox === null, 422, 'Política ativa exige uma inbox geral.');

        $policy = DB::transaction(function () use ($office, $data): ?CommunicationAutomationPolicy {
            $current = CommunicationAutomationPolicy::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('module_key', $data['module_key'])
                ->where('submodule_key', $data['submodule_key'])
                ->lockForUpdate()->first();
            if ($current === null) {
                if ((int) $data['lock_version'] !== 0) {
                    return null;
                }

                return CommunicationAutomationPolicy::query()->withoutGlobalScopes()->create([
                    ...$data,
                    'office_id' => $office->id,
                    'lock_version' => 1,
                ]);
            }
            if ((int) $current->lock_version !== (int) $data['lock_version']) {
                return null;
            }
            $current->forceFill([
                ...collect($data)->except('lock_version')->all(),
                'lock_version' => (int) $current->lock_version + 1,
            ])->save();

            return $current->refresh();
        });
        if (! $policy instanceof CommunicationAutomationPolicy) {
            return response()->json(['message' => 'Política alterada por outro usuário.', 'code' => 'version_conflict'], 409);
        }
        $this->events->record((int) $office->id, 'AUTOMATION_POLICY_UPDATED', [
            'policy_id' => (int) $policy->id,
            'module_key' => $policy->module_key,
            'submodule_key' => $policy->submodule_key,
            'enabled' => (bool) $policy->is_enabled,
            'lock_version' => (int) $policy->lock_version,
        ], inboxId: $policy->inbox_id !== null ? (int) $policy->inbox_id : null,
            actorMembershipId: $this->currentOffice->realMembership()?->id);

        return response()->json(['data' => $this->policyArray($policy->load('inbox'))]);
    }

    public function recipients(Request $request, int $client): JsonResponse
    {
        $this->access->assertManage($this->actor($request));
        [$model, $preference] = $this->clientAndPreference($request, $client);
        $identities = $this->eligibleIdentities((int) $model->id);
        $selected = $preference?->recipients()->withoutGlobalScopes()->pluck('identity_id') ?? collect();

        return response()->json(['data' => [
            'client_id' => (int) $model->id,
            'preference_id' => $preference?->id,
            'recipient_mode' => ($preference?->recipient_mode instanceof RecipientMode
                ? $preference->recipient_mode
                : RecipientMode::Primary)->value,
            'lock_version' => (int) ($preference?->lock_version ?? 0),
            'selected_identity_ids' => $selected->map(fn ($id) => (int) $id)->values(),
            'identities' => $identities->map(fn (CommunicationIdentity $identity) => [
                'id' => (int) $identity->id,
                'masked' => $identity->address_masked,
                'is_primary' => (bool) $identity->getAttribute('link_is_primary'),
                'receives_automatic' => (bool) $identity->getAttribute('link_receives_automatic'),
            ])->values(),
        ]]);
    }

    public function updateRecipients(Request $request, int $client): JsonResponse
    {
        $this->access->assertManage($this->actor($request));
        [$model, $preference] = $this->clientAndPreference($request, $client);
        abort_unless($preference instanceof ClientCommunicationPreference, 422, 'Configure a preferência fiscal antes dos destinatários.');
        $data = $request->validate([
            'recipient_mode' => ['required', Rule::enum(RecipientMode::class)],
            'identity_ids' => ['present', 'array', 'max:50'],
            'identity_ids.*' => ['integer', 'min:1'],
            'lock_version' => ['required', 'integer', 'min:1'],
        ]);
        $ids = array_values(array_unique(array_map('intval', $data['identity_ids'])));
        $valid = $this->eligibleIdentities((int) $model->id)->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
        abort_if(count($valid) !== count($ids), 422, 'Destinatário não elegível para este cliente.');
        abort_if($data['recipient_mode'] === RecipientMode::Selected->value && $ids === [], 422, 'Modo SELECTED exige ao menos um destinatário.');

        $updated = DB::transaction(function () use ($preference, $data, $ids): bool {
            $locked = ClientCommunicationPreference::query()->withoutGlobalScopes()->lockForUpdate()->find($preference->id);
            if (! $locked instanceof ClientCommunicationPreference || (int) $locked->lock_version !== (int) $data['lock_version']) {
                return false;
            }
            $locked->forceFill([
                'recipient_mode' => $data['recipient_mode'],
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();
            CommunicationPreferenceRecipient::query()->withoutGlobalScopes()->where('preference_id', $locked->id)->delete();
            foreach ($ids as $identityId) {
                CommunicationPreferenceRecipient::query()->withoutGlobalScopes()->create([
                    'office_id' => $locked->office_id,
                    'preference_id' => $locked->id,
                    'identity_id' => $identityId,
                ]);
            }

            return true;
        });
        if (! $updated) {
            return response()->json(['message' => 'Preferência alterada por outro usuário.', 'code' => 'version_conflict'], 409);
        }

        return $this->recipients($request, $client);
    }

    /** @return array{0:Client,1:?ClientCommunicationPreference} */
    private function clientAndPreference(Request $request, int $client): array
    {
        $office = $this->currentOffice->office();
        $data = $request->validate([
            'module_key' => ['required', 'string', 'max:40'],
            'submodule_key' => ['required', 'string', 'max:40'],
        ]);
        abort_unless(in_array($data['module_key'].':'.$data['submodule_key'], self::SCOPES, true), 422);
        $model = Client::query()->withoutGlobalScopes()->where('office_id', $office->id)->findOrFail($client);
        $preference = ClientCommunicationPreference::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->where('client_id', $model->id)
            ->where('module_key', $data['module_key'])->where('submodule_key', $data['submodule_key'])->first();

        return [$model, $preference];
    }

    /** @return Collection<int, CommunicationIdentity> */
    private function eligibleIdentities(int $clientId)
    {
        return CommunicationIdentity::query()->withoutGlobalScopes()
            ->select('communication_identities.*')
            ->selectRaw('links.is_primary as link_is_primary, links.receives_automatic as link_receives_automatic')
            ->join('communication_identity_links as links', 'links.identity_id', '=', 'communication_identities.id')
            ->join('communication_contacts as contacts', 'contacts.id', '=', 'communication_identities.contact_id')
            ->where('communication_identities.office_id', $this->currentOffice->id())
            ->where('links.client_id', $clientId)
            ->where('communication_identities.channel', CommunicationChannel::Whatsapp->value)
            ->where('communication_identities.is_active', true)
            ->whereNull('communication_identities.purged_at')
            ->where('contacts.is_active', true)
            ->whereNull('contacts.purged_at')
            ->orderByDesc('links.is_primary')->orderBy('communication_identities.id')->get();
    }

    /** @return array<string, mixed> */
    private function policyArray(CommunicationAutomationPolicy $policy): array
    {
        return [
            'id' => (int) $policy->id,
            'module_key' => $policy->module_key,
            'submodule_key' => $policy->submodule_key,
            'inbox_id' => $policy->inbox_id,
            'inbox_name' => $policy->relationLoaded('inbox') ? $policy->inbox?->name : null,
            'is_enabled' => (bool) $policy->is_enabled,
            'send_day' => (int) $policy->send_day,
            'send_time' => substr((string) $policy->send_time, 0, 5),
            'timezone' => $policy->timezone,
            'recipient_mode' => ($policy->recipient_mode instanceof RecipientMode
                ? $policy->recipient_mode
                : RecipientMode::Primary)->value,
            'template_key' => $policy->template_key,
            'template_version' => $policy->template_version,
            'lock_version' => (int) $policy->lock_version,
        ];
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }
}
