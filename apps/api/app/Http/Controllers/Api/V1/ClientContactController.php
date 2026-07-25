<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreClientContactRequest;
use App\Http\Requests\Clients\UpdateClientContactRequest;
use App\Models\Client;
use App\Models\ClientContact;
use App\Services\Audit\AuditLogger;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ClientContactController extends Controller
{
    public function index(Client $client): JsonResponse
    {
        $this->authorize('view', $client);

        $contacts = $client->contacts()
            ->orderByDesc('is_primary')
            ->orderBy('name')
            ->get()
            ->map(fn (ClientContact $c) => $this->serialize($c));

        return response()->json(['data' => $contacts]);
    }

    public function store(
        StoreClientContactRequest $request,
        Client $client,
        CurrentOffice $currentOffice,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('update', $client);
        $this->authorize('create', ClientContact::class);

        $data = $request->validated();
        $officeId = $currentOffice->office()->id;

        $isPrimary = (bool) ($data['is_primary'] ?? false);
        $isActive = array_key_exists('is_active', $data) ? (bool) $data['is_active'] : true;

        // Principal só faz sentido se ativo — evita demover o vigente e deixar o cliente sem principal.
        if ($isPrimary && ! $isActive) {
            throw ValidationException::withMessages([
                'is_primary' => ['Contato principal precisa estar ativo.'],
                'is_active' => ['Não é possível marcar como principal um contato inativo.'],
            ]);
        }

        $contact = DB::transaction(function () use ($data, $client, $officeId, $isPrimary, $isActive): ClientContact {
            if ($isPrimary) {
                ClientContact::query()
                    ->where('client_id', $client->id)
                    ->where('is_primary', true)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->update(['is_primary' => false]);
            }

            return ClientContact::query()->create([
                'office_id' => $officeId,
                'client_id' => $client->id,
                'name' => $data['name'],
                'role' => $data['role'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_whatsapp' => $data['is_whatsapp'] ?? false,
                'is_primary' => $isPrimary,
                'receives_alerts' => $data['receives_alerts'] ?? false,
                'notes' => $data['notes'] ?? null,
                'is_active' => $isActive,
            ]);
        });

        $audit->record('client_contact.create', 'SUCCESS', $contact, [
            'client_id' => $client->id,
            'fields' => ['name', 'is_primary'],
            'is_primary' => $contact->is_primary,
        ]);

        return response()->json(['data' => $this->serialize($contact)], 201);
    }

    public function update(
        UpdateClientContactRequest $request,
        Client $client,
        ClientContact $contact,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorize('update', $client);
        $this->authorize('update', $contact);

        if ($contact->client_id !== $client->id) {
            abort(404);
        }

        $data = $request->validated();

        $contact = DB::transaction(function () use ($data, $contact, $client): ClientContact {
            $locked = ClientContact::query()->whereKey($contact->id)->lockForUpdate()->firstOrFail();

            $willBePrimary = array_key_exists('is_primary', $data)
                ? (bool) $data['is_primary']
                : (bool) $locked->is_primary;
            $willBeActive = array_key_exists('is_active', $data)
                ? (bool) $data['is_active']
                : (bool) $locked->is_active;

            // Principal só se ativo — não demover o vigente nem deixar cliente sem principal ativo.
            if ($willBePrimary && ! $willBeActive) {
                throw ValidationException::withMessages([
                    'is_primary' => ['Contato principal precisa estar ativo.'],
                    'is_active' => ['Não é possível marcar como principal um contato inativo.'],
                ]);
            }

            $becomingPrimary = $willBePrimary && ! $locked->is_primary;

            if ($becomingPrimary && $willBeActive) {
                ClientContact::query()
                    ->where('client_id', $client->id)
                    ->where('id', '!=', $locked->id)
                    ->where('is_primary', true)
                    ->where('is_active', true)
                    ->lockForUpdate()
                    ->update(['is_primary' => false]);
            }

            $locked->fill($data);
            $locked->save();

            return $locked->fresh() ?? $locked;
        });

        $audit->record('client_contact.update', 'SUCCESS', $contact, [
            'client_id' => $client->id,
            'fields' => array_keys($data),
        ]);

        return response()->json(['data' => $this->serialize($contact)]);
    }

    public function destroy(Client $client, ClientContact $contact, AuditLogger $audit): JsonResponse
    {
        $this->authorize('update', $client);
        $this->authorize('delete', $contact);

        if ($contact->client_id !== $client->id) {
            abort(404);
        }

        $contact->delete();

        $audit->record('client_contact.delete', 'SUCCESS', $contact, [
            'client_id' => $client->id,
        ]);

        return response()->json([], 204);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(ClientContact $contact): array
    {
        return [
            'id' => $contact->id,
            'client_id' => $contact->client_id,
            'name' => $contact->name,
            'role' => $contact->role,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'is_whatsapp' => $contact->is_whatsapp,
            'is_primary' => $contact->is_primary,
            'receives_alerts' => $contact->receives_alerts,
            'notes' => $contact->notes,
            'is_active' => $contact->is_active,
            'created_at' => $contact->created_at?->toIso8601String(),
            'updated_at' => $contact->updated_at?->toIso8601String(),
        ];
    }
}
