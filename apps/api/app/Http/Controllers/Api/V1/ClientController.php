<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clients\BulkUpdateClientStatusAction;
use App\Actions\Clients\CreateClientAction;
use App\Actions\Clients\GetClientDetailAction;
use App\Actions\Clients\ListClientsAction;
use App\Actions\Clients\RefreshClientRegistrationAction;
use App\Actions\Clients\UpdateClientAction;
use App\Actions\Clients\UpdateClientCustomFieldAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\BulkUpdateClientStatusRequest;
use App\Http\Requests\Clients\ListClientsRequest;
use App\Http\Requests\Clients\RefreshClientRegistrationRequest;
use App\Http\Requests\Clients\ShowClientRequest;
use App\Http\Requests\Clients\StoreClientRequest;
use App\Http\Requests\Clients\UpdateClientCustomFieldRequest;
use App\Http\Requests\Clients\UpdateClientRequest;
use App\Http\Resources\BulkClientStatusResource;
use App\Http\Resources\ClientCollection;
use App\Http\Resources\ClientCreationResource;
use App\Http\Resources\ClientCustomFieldResource;
use App\Http\Resources\ClientDetailResource;
use App\Http\Resources\ClientRegistrationRefreshResource;
use App\Http\Resources\ClientResource;
use App\Models\Client;
use App\Models\ClientCustomField;
use Illuminate\Http\JsonResponse;

class ClientController extends Controller
{
    public function index(
        ListClientsRequest $request,
        ListClientsAction $listClients,
    ): JsonResponse {
        $result = $listClients($request->toDto());

        return (new ClientCollection(
            $result->paginator,
            $result->stats,
        ))->response();
    }

    public function store(
        StoreClientRequest $request,
        CreateClientAction $createClient,
    ): JsonResponse {
        return ClientCreationResource::make(
            $createClient($request->toDto()),
        )->response()->setStatusCode(201);
    }

    public function show(
        ShowClientRequest $request,
        Client $client,
        GetClientDetailAction $getClientDetail,
    ): JsonResponse {
        return ClientDetailResource::make(
            $getClientDetail($client),
        )->response();
    }

    public function update(
        UpdateClientRequest $request,
        Client $client,
        UpdateClientAction $updateClient,
    ): JsonResponse {
        return ClientResource::make(
            $updateClient($client, $request->toDto()),
        )->response();
    }

    public function updateCustomField(
        UpdateClientCustomFieldRequest $request,
        Client $client,
        ClientCustomField $customField,
        UpdateClientCustomFieldAction $updateCustomField,
    ): JsonResponse {
        return ClientCustomFieldResource::make(
            $updateCustomField($client, $customField, $request->toDto()),
        )->response();
    }

    public function bulkStatus(
        BulkUpdateClientStatusRequest $request,
        BulkUpdateClientStatusAction $bulkUpdate,
    ): JsonResponse {
        return BulkClientStatusResource::make(
            $bulkUpdate($request->toDto(), $request->actor()),
        )->response();
    }

    public function refreshRegistration(
        RefreshClientRegistrationRequest $request,
        Client $client,
        RefreshClientRegistrationAction $refreshRegistration,
    ): JsonResponse {
        return ClientRegistrationRefreshResource::make(
            $refreshRegistration($client, $request->toDto()),
        )->response();
    }
}
