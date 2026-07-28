<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\AssociateFiscalCategoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListFiscalCategoryLinksRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringSurfaceRequest;
use App\Http\Requests\Fiscal\Mutations\AssociateFiscalCategoryBatchRequest;
use App\Http\Requests\Fiscal\Mutations\AssociateFiscalCategoryRequest;
use App\Http\Resources\Fiscal\FiscalCategoryLinkResource;
use App\Http\Resources\Fiscal\FiscalCategoryResource;
use App\Services\FiscalMonitoring\FiscalCategoryService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Catálogo de categorias e vínculos tenant-scoped (associação em lote).
 */
class FiscalCategoryController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly FiscalCategoryService $categories,
        private readonly AssociateFiscalCategoryAction $associateAction,
    ) {}

    public function indexCategories(
        ViewFiscalMonitoringSurfaceRequest $request,
    ): AnonymousResourceCollection {
        return FiscalCategoryResource::collection(
            $this->categories->listCategories(true),
        );
    }

    public function indexLinks(
        ListFiscalCategoryLinksRequest $request,
    ): AnonymousResourceCollection {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return FiscalCategoryLinkResource::collection(
            $this->categories->listLinks(
                $tenant,
                $filters->clientId,
                $filters->status,
            ),
        );
    }

    public function associate(AssociateFiscalCategoryRequest $request): JsonResponse
    {
        try {
            $link = $this->associateAction->associate(
                $request->actor(),
                $request->associateData(),
            );
        } catch (NotFoundHttpException $e) {
            return $this->failure($e, 404);
        } catch (RuntimeException $e) {
            return $this->failure($e, 422);
        }

        return response()->json(['data' => $link], 201);
    }

    public function associateBatch(AssociateFiscalCategoryBatchRequest $request): JsonResponse
    {
        $result = $this->associateAction->associateBatch(
            $request->actor(),
            $request->batchData(),
        );

        return response()->json(['data' => $result]);
    }

    private function failure(\Throwable $error, int $status): JsonResponse
    {
        $text = $error->getMessage();

        return response()->json(['message' => $text], $status);
    }
}
