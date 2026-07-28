<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clients\BulkUpdateClientCategoriesAction;
use App\Actions\Clients\ReplaceClientCategoriesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\BulkUpdateClientCategoriesRequest;
use App\Http\Requests\Clients\ReplaceClientCategoriesRequest;
use App\Http\Resources\BulkClientCategoryUpdateResource;
use App\Http\Resources\ClientCategoryReplacementResource;
use App\Models\Client;
use Illuminate\Http\JsonResponse;

class ClientCategoryAssignmentController extends Controller
{
    public function replace(
        ReplaceClientCategoriesRequest $request,
        Client $client,
        ReplaceClientCategoriesAction $replaceCategories,
    ): JsonResponse {
        return ClientCategoryReplacementResource::make(
            $replaceCategories($client, $request->toDto()),
        )->response();
    }

    public function bulk(
        BulkUpdateClientCategoriesRequest $request,
        BulkUpdateClientCategoriesAction $bulkUpdate,
    ): JsonResponse {
        return BulkClientCategoryUpdateResource::make(
            $bulkUpdate($request->toDto()),
        )->response();
    }
}
