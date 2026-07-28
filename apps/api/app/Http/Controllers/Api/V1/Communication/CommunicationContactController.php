<?php

namespace App\Http\Controllers\Api\V1\Communication;

use App\Enums\CommunicationChannel;
use App\Http\Controllers\Controller;
use App\Http\Requests\Communication\StoreContactRequest;
use App\Http\Resources\Communication\CommunicationContactCollection;
use App\Http\Resources\Communication\CommunicationContactResource;
use App\Models\Client;
use App\Models\ClientContact;
use App\Models\CommunicationContact;
use App\Models\CommunicationIdentity;
use App\Models\CommunicationIdentityLink;
use App\Models\User;
use App\Services\Communication\Authorization\CommunicationAccess;
use App\Services\Communication\Events\CommunicationEventRecorder;
use App\Services\Communication\WhatsappAddressNormalizer;
use App\Support\CurrentTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CommunicationContactController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly CommunicationAccess $access,
        private readonly WhatsappAddressNormalizer $normalizer,
        private readonly CommunicationEventRecorder $events,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->access->assertView($this->actor($request));
        $query = CommunicationContact::query()->with([
            'identities.clientLinks.client',
            'identities.clientLinks.clientContact',
        ]);
        if ($search = trim($request->string('q')->toString())) {
            $needle = '%'.mb_strtolower($search).'%';
            $query->where(fn ($builder) => $builder
                ->whereRaw("LOWER(COALESCE(name, '')) LIKE ?", [$needle])
                ->orWhereHas('identities', fn ($identities) => $identities->where('address_masked', 'like', '%'.$search.'%')));
        }
        if ($request->exists('is_active')) {
            $query->where('is_active', $request->boolean('is_active'));
        } elseif (! $request->boolean('include_inactive')) {
            $query->where('is_active', true);
        }
        if ($request->exists('is_provisional')) {
            $query->where('is_provisional', $request->boolean('is_provisional'));
        }
        if ($request->exists('linked')) {
            if ($request->boolean('linked')) {
                $query->whereHas('identities.clientLinks');
            } else {
                $query->whereDoesntHave('identities.clientLinks');
            }
        }
        $this->applyCatalogSort($query, $request);
        $paginator = $query->paginate(min(100, max(1, $request->integer('per_page', 30))));

        return (new CommunicationContactCollection($paginator))->response();
    }

    public function show(Request $request, int $contact): CommunicationContactResource
    {
        $this->access->assertView($this->actor($request));

        return new CommunicationContactResource($this->contact($contact)->load([
            'identities.clientLinks.client',
            'identities.clientLinks.clientContact',
        ]));
    }

    public function store(StoreContactRequest $request): JsonResponse
    {
        $this->access->assertManageContacts($this->actor($request));
        $tenant = $this->currentTenant->tenant();
        $data = $request->validated();
        $address = $this->normalizer->normalize($data['phone']);
        if (CommunicationIdentity::query()->where('channel', CommunicationChannel::Whatsapp->value)
            ->where('address_hash', hash('sha256', $address))->exists()) {
            return response()->json(['message' => 'Este WhatsApp já pertence a um contato.', 'code' => 'identity_conflict'], 409);
        }
        [$contact, $identity] = DB::transaction(function () use ($tenant, $data, $address): array {
            $contact = CommunicationContact::query()->create([
                'tenant_id' => $tenant->id,
                'name' => isset($data['name']) ? trim((string) $data['name']) : null,
                'is_provisional' => empty($data['name']),
                'is_active' => true,
            ]);
            $identity = CommunicationIdentity::query()->create([
                'tenant_id' => $tenant->id,
                'contact_id' => $contact->id,
                'channel' => CommunicationChannel::Whatsapp,
                'address_encrypted' => $address,
                'address_hash' => hash('sha256', $address),
                'address_masked' => $this->mask($address),
                'is_active' => true,
            ]);
            if (isset($data['client_id'])) {
                $this->link($identity, $data);
            }

            return [$contact, $identity];
        });
        $this->events->record((int) $tenant->id, 'CONTACT_CREATED', [
            'contact_id' => (int) $contact->id,
            'identity_id' => (int) $identity->id,
        ], actorMembershipId: $this->currentTenant->realMembership()?->id);

        return (new CommunicationContactResource($contact->load([
            'identities.clientLinks.client',
            'identities.clientLinks.clientContact',
        ])))->response()->setStatusCode(201);
    }

    public function update(Request $request, int $contact): CommunicationContactResource
    {
        $model = $this->contact($contact);
        $this->access->assertManageContacts($this->actor($request), $model);
        $data = $request->validate([
            'name' => ['sometimes', 'nullable', 'string', 'max:160'],
            'is_active' => ['sometimes', 'boolean'],
        ]);
        if (array_key_exists('name', $data)) {
            $data['name'] = $data['name'] !== null ? trim((string) $data['name']) : null;
            $data['is_provisional'] = $data['name'] === null || $data['name'] === '';
        }
        $model->fill($data)->save();

        return new CommunicationContactResource($model->fresh()->load([
            'identities.clientLinks.client',
            'identities.clientLinks.clientContact',
        ]));
    }

    public function addIdentity(Request $request, int $contact): JsonResponse
    {
        $model = $this->contact($contact);
        $this->access->assertManageContacts($this->actor($request), $model);
        $data = $request->validate(['phone' => ['required', 'string', 'max:40']]);
        $address = $this->normalizer->normalize($data['phone']);
        if (CommunicationIdentity::query()->where('channel', CommunicationChannel::Whatsapp->value)
            ->where('address_hash', hash('sha256', $address))->exists()) {
            return response()->json(['message' => 'Identidade já cadastrada.', 'code' => 'identity_conflict'], 409);
        }
        $identity = CommunicationIdentity::query()->create([
            'tenant_id' => $model->tenant_id,
            'contact_id' => $model->id,
            'channel' => CommunicationChannel::Whatsapp,
            'address_encrypted' => $address,
            'address_hash' => hash('sha256', $address),
            'address_masked' => $this->mask($address),
            'is_active' => true,
        ]);

        return response()->json(['data' => ['id' => $identity->id, 'address_masked' => $identity->address_masked]], 201);
    }

    public function linkIdentity(Request $request, int $identity): JsonResponse
    {
        $model = CommunicationIdentity::query()->findOrFail($identity);
        $this->access->assertManageContacts($this->actor($request), $model);
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'min:1'],
            'client_contact_id' => ['nullable', 'integer', 'min:1'],
            'is_primary' => ['sometimes', 'boolean'],
            'receives_automatic' => ['sometimes', 'boolean'],
        ]);
        $link = DB::transaction(fn () => $this->link($model, $data));
        $link->load(['client', 'clientContact']);

        return response()->json(['data' => [
            'id' => $link->id,
            'identity_id' => $link->identity_id,
            'client_id' => $link->client_id,
            'client_name' => $link->client?->displayLabel(),
            'client_contact_id' => $link->client_contact_id,
            'client_contact_name' => $link->clientContact?->name,
            'is_primary' => (bool) $link->is_primary,
            'receives_automatic' => (bool) $link->receives_automatic,
        ]], 201);
    }

    public function unlinkIdentity(Request $request, int $identity, int $link): JsonResponse
    {
        $model = CommunicationIdentity::query()->findOrFail($identity);
        $this->access->assertManageContacts($this->actor($request), $model);
        $linked = CommunicationIdentityLink::query()->where('identity_id', $model->id)->findOrFail($link);
        $linked->delete();

        return response()->json(status: 204);
    }

    /** @param array<string, mixed> $data */
    private function link(CommunicationIdentity $identity, array $data): CommunicationIdentityLink
    {
        $client = Client::query()->findOrFail((int) $data['client_id']);
        $clientContactId = isset($data['client_contact_id']) ? (int) $data['client_contact_id'] : null;
        if ($clientContactId !== null) {
            ClientContact::query()->where('client_id', $client->id)->findOrFail($clientContactId);
        }
        if (($data['is_primary'] ?? false) === true) {
            CommunicationIdentityLink::query()->where('client_id', $client->id)->update(['is_primary' => false]);
        }

        return CommunicationIdentityLink::query()->updateOrCreate([
            'identity_id' => $identity->id,
            'client_id' => $client->id,
            'client_contact_id' => $clientContactId,
        ], [
            'tenant_id' => $identity->tenant_id,
            'is_primary' => (bool) ($data['is_primary'] ?? false),
            'receives_automatic' => (bool) ($data['receives_automatic'] ?? true),
        ]);
    }

    private function contact(int $id): CommunicationContact
    {
        return CommunicationContact::query()->findOrFail($id);
    }

    private function actor(Request $request): User
    {
        $actor = $request->user();
        abort_unless($actor instanceof User, 401);

        return $actor;
    }

    private function mask(string $address): string
    {
        return substr($address, 0, min(3, strlen($address))).'•••••'.substr($address, -4);
    }

    private function applyCatalogSort(Builder $query, Request $request): void
    {
        $sort = $request->string('sort')->toString();
        $directionRaw = strtolower((string) $request->query('sort_direction', 'asc'));
        $direction = $directionRaw === 'desc' ? 'desc' : 'asc';

        if (! in_array($sort, ['name', 'id', 'created_at'], true)) {
            $query->orderByRaw('name IS NULL')->orderBy('name')->orderBy('id');

            return;
        }

        if ($sort === 'name') {
            $query->orderByRaw('name IS NULL')->orderBy('name', $direction)->orderBy('id', $direction);

            return;
        }

        $query->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $query->orderBy('id', $direction);
        }
    }
}
