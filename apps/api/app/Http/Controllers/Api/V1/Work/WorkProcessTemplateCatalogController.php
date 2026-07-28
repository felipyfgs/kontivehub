<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Actions\Work\InstallWorkProcessTemplateCatalogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\InstallWorkProcessTemplateCatalogRequest;
use App\Http\Requests\Work\ListWorkProcessTemplateCatalogRequest;
use App\Http\Resources\WorkProcessTemplateCatalogResource;
use App\Http\Resources\WorkProcessTemplateInstallationResource;
use App\Services\Work\WorkProcessTemplateCatalogQuery;
use Illuminate\Http\JsonResponse;

class WorkProcessTemplateCatalogController extends Controller
{
    public function index(
        ListWorkProcessTemplateCatalogRequest $request,
        WorkProcessTemplateCatalogQuery $query,
    ): JsonResponse {
        return WorkProcessTemplateCatalogResource::collection(
            $query->all(),
        )->response();
    }

    public function install(
        InstallWorkProcessTemplateCatalogRequest $request,
        string $catalogKey,
        InstallWorkProcessTemplateCatalogAction $action,
    ): JsonResponse {
        return (new WorkProcessTemplateInstallationResource(
            $action->execute($catalogKey, $request->payload()),
        ))->response()->setStatusCode(201);
    }
}
