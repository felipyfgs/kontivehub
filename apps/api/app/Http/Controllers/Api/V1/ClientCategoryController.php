<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Clients\CreateClientCategoryAction;
use App\Actions\Clients\ListClientCategoriesAction;
use App\Actions\Clients\UpdateClientCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Clients\ListClientCategoriesRequest;
use App\Http\Requests\Clients\StoreClientCategoryRequest;
use App\Http\Requests\Clients\UpdateClientCategoryRequest;
use App\Http\Resources\ClientCategoryResource;
use App\Models\ClientCategory;
use Illuminate\Http\JsonResponse;

class ClientCategoryController extends Controller
{
    public function index(
        ListClientCategoriesRequest $request,
        ListClientCategoriesAction $listCategories,
    ): JsonResponse {
        return ClientCategoryResource::collection(
            $listCategories($request->toDto()),
        )->response();
    }

    public function store(
        StoreClientCategoryRequest $request,
        CreateClientCategoryAction $createCategory,
    ): JsonResponse {
        return ClientCategoryResource::make(
            $createCategory($request->toDto()),
        )->response()->setStatusCode(201);
    }

    public function update(
        UpdateClientCategoryRequest $request,
        ClientCategory $clientCategory,
        UpdateClientCategoryAction $updateCategory,
    ): JsonResponse {
        return ClientCategoryResource::make(
            $updateCategory($clientCategory, $request->toDto()),
        )->response();
    }
}
