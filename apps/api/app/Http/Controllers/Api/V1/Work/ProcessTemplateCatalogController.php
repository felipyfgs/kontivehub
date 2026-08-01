<?php

namespace App\Http\Controllers\Api\V1\Work;

use App\Actions\Work\InstallProcessTemplateCatalogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Work\InstallProcessTemplateCatalogRequest;
use App\Http\Requests\Work\ListProcessTemplateCatalogRequest;
use App\Http\Resources\Work\ProcessTemplateCatalogResource;
use App\Http\Resources\Work\ProcessTemplateInstallationResource;
use App\Services\Work\ProcessTemplateCatalogQuery;
use Illuminate\Http\JsonResponse;

class ProcessTemplateCatalogController extends Controller
{
    public function index(
        ListProcessTemplateCatalogRequest $request,
        ProcessTemplateCatalogQuery $query,
    ): JsonResponse {
        return ProcessTemplateCatalogResource::collection(
            $query->all(),
        )->response();
    }

    public function install(
        InstallProcessTemplateCatalogRequest $request,
        string $catalogKey,
        InstallProcessTemplateCatalogAction $action,
    ): JsonResponse {
        return (new ProcessTemplateInstallationResource(
            $action->execute($catalogKey, $request->payload()),
        ))->response()->setStatusCode(201);
    }
}
