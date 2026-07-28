<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clients\RejectAdditionalEstablishmentAction;
use App\Actions\Clients\UpdateEstablishmentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\StoreEstablishmentRequest;
use App\Http\Requests\Clients\UpdateEstablishmentRequest;
use App\Http\Resources\EstablishmentUpdateResource;
use App\Models\Client;
use App\Models\Establishment;
use Illuminate\Http\JsonResponse;

class EstablishmentController extends Controller
{
    public function store(
        StoreEstablishmentRequest $request,
        Client $client,
        RejectAdditionalEstablishmentAction $rejectAdditionalEstablishment,
    ): never {
        $rejectAdditionalEstablishment($client);
    }

    public function update(
        UpdateEstablishmentRequest $request,
        Establishment $establishment,
        UpdateEstablishmentAction $updateEstablishment,
    ): JsonResponse {
        return EstablishmentUpdateResource::make(
            $updateEstablishment($establishment, $request->toDto()),
        )->response();
    }
}
