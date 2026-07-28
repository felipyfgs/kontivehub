<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clients\ActivateClientCredentialAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ShowClientCredentialRequest;
use App\Http\Requests\Clients\StoreClientCredentialRequest;
use App\Http\Resources\ClientCredentialResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;

final class ClientCredentialController extends Controller
{
    public function __construct(
        private readonly ActivateClientCredentialAction $credentials,
    ) {}

    public function show(
        ShowClientCredentialRequest $request,
        Client $client,
    ): JsonResponse {
        $credential = $this->credentials->activeFor($client);
        if ($credential === null) {
            return response()->json(['data' => null]);
        }

        return ClientCredentialResource::make($credential)->response();
    }

    public function store(
        StoreClientCredentialRequest $request,
        Client $client,
    ): JsonResponse {
        return ClientCredentialResource::make(
            $this->credentials->activate($client, $request->toDto()),
        )->response()->setStatusCode(201);
    }
}
