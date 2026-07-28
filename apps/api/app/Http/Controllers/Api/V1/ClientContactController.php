<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clients\CreateClientContactAction;
use App\Actions\Clients\DeleteClientContactAction;
use App\Actions\Clients\ListClientContactsAction;
use App\Actions\Clients\UpdateClientContactAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\DeleteClientContactRequest;
use App\Http\Requests\Clients\ListClientContactsRequest;
use App\Http\Requests\Clients\StoreClientContactRequest;
use App\Http\Requests\Clients\UpdateClientContactRequest;
use App\Http\Resources\ClientContactResource;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Http\JsonResponse;

class ClientContactController extends Controller
{
    public function index(
        ListClientContactsRequest $request,
        Client $client,
        ListClientContactsAction $listContacts,
    ): JsonResponse {
        return ClientContactResource::collection(
            $listContacts($client),
        )->response();
    }

    public function store(
        StoreClientContactRequest $request,
        Client $client,
        CreateClientContactAction $createContact,
    ): JsonResponse {
        return ClientContactResource::make(
            $createContact($client, $request->toDto()),
        )->response()->setStatusCode(201);
    }

    public function update(
        UpdateClientContactRequest $request,
        Client $client,
        ClientContact $contact,
        UpdateClientContactAction $updateContact,
    ): JsonResponse {
        return ClientContactResource::make(
            $updateContact($client, $contact, $request->toDto()),
        )->response();
    }

    public function destroy(
        DeleteClientContactRequest $request,
        Client $client,
        ClientContact $contact,
        DeleteClientContactAction $deleteContact,
    ): JsonResponse {
        $deleteContact($client, $contact);

        return response()->json([], 204);
    }
}
