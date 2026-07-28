<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\ListDeclarationProjectionsAction;
use App\Actions\Fiscal\Mutations\OperateDeclarationHubAction;
use App\Actions\Fiscal\ViewDeclarationCatalogAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ListDeclarationProjectionsRequest;
use App\Http\Requests\Fiscal\Monitoring\SummarizeDeclarationProjectionsRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewDeclarationCatalogRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewDeclarationEvidenceRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewDeclarationProjectionRequest;
use App\Http\Requests\Fiscal\Mutations\AttachDeclarationEvidenceRequest;
use App\Http\Requests\Fiscal\Mutations\ProjectDeclarationRequest;
use App\Http\Requests\Fiscal\Mutations\PublishDeclarationCalendarRequest;
use App\Http\Resources\Fiscal\DeclarationCatalogResource;
use App\Http\Resources\Fiscal\DeclarationProjectionPageResource;
use App\Http\Resources\Fiscal\TaxDeliveryEvidenceResource;
use App\Http\Resources\Fiscal\TaxObligationProjectionDetailResource;
use App\Services\Fiscal\Declarations\DeclarationHubQueryService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;

/**
 * Central agregada de declarações (tenant-scoped) — task 11.5.
 */
class DeclarationHubController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly DeclarationHubQueryService $hub,
        private readonly OperateDeclarationHubAction $operations,
    ) {}

    /** Catálogo versionado (global, leitura). */
    public function catalog(
        ViewDeclarationCatalogRequest $request,
        ViewDeclarationCatalogAction $action,
    ): DeclarationCatalogResource {
        return new DeclarationCatalogResource($action->handle());
    }

    /** Lista agregada com filtros e deep-links. */
    public function index(
        ListDeclarationProjectionsRequest $request,
        ListDeclarationProjectionsAction $action,
    ): DeclarationProjectionPageResource {
        $tenant = $this->currentTenant->tenant();

        return new DeclarationProjectionPageResource(
            $action->handle($tenant, $request->filters()),
        );
    }

    public function summary(
        SummarizeDeclarationProjectionsRequest $request,
    ): JsonResponse {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return response()->json([
            'data' => $this->hub->summaryByObligation(
                $tenant,
                $filters->clientId,
                $filters->periodKey,
            ),
        ]);
    }

    public function show(
        ViewDeclarationProjectionRequest $request,
        int $projection,
    ): JsonResponse|TaxObligationProjectionDetailResource {
        $tenant = $this->currentTenant->tenant();

        $model = $this->hub->find($tenant, $projection);
        if ($model === null) {
            return response()->json(['message' => 'Projeção de declaração não encontrada.'], 404);
        }

        return new TaxObligationProjectionDetailResource($model);
    }

    /** Materializa projeção(ões) para contribuinte/competência. */
    public function project(ProjectDeclarationRequest $request): JsonResponse
    {
        try {
            $result = $this->operations->project($request->projectData());
        } catch (NotFoundHttpException $e) {
            return $this->failure($e, 404);
        } catch (UnprocessableEntityHttpException $e) {
            return $this->failure($e, 422);
        } catch (RuntimeException|\InvalidArgumentException $e) {
            return $this->failure($e, 422);
        }

        if (isset($result['items'])) {
            return response()->json(['data' => $result['items']], 201);
        }

        return response()->json(['data' => $result['item']], 201);
    }

    /** Anexa recibo/protocolo/artefato interno à projeção. */
    public function attachEvidence(
        AttachDeclarationEvidenceRequest $request,
        int $projection,
    ): JsonResponse {
        try {
            $result = $this->operations->attachEvidence(
                $projection,
                $request->evidenceData(),
            );
        } catch (NotFoundHttpException $e) {
            return $this->failure($e, 404);
        } catch (RuntimeException $e) {
            return $this->failure($e, 422);
        }

        return response()->json(['data' => $result], 201);
    }

    public function showEvidence(
        ViewDeclarationEvidenceRequest $request,
        int $projection,
        int $evidence,
    ): JsonResponse|TaxDeliveryEvidenceResource {
        $tenant = $this->currentTenant->tenant();

        $model = $this->hub->findEvidence($tenant, $projection, $evidence);
        if ($model === null) {
            return response()->json(['message' => 'Evidência não encontrada.'], 404);
        }

        return new TaxDeliveryEvidenceResource($model);
    }

    /**
     * Publica prorrogação de calendário (ADMIN) e recalcula competências abertas.
     * Uso operacional/plataforma; tenants leem o efeito nas projeções.
     */
    public function publishCalendar(PublishDeclarationCalendarRequest $request): JsonResponse
    {
        try {
            $result = $this->operations->publishCalendar($request->calendarData());
        } catch (\InvalidArgumentException|RuntimeException $e) {
            return $this->failure($e, 422);
        }

        return response()->json(['data' => $result], 201);
    }

    private function failure(\Throwable $error, int $status): JsonResponse
    {
        $text = $error->getMessage();

        return response()->json(['message' => $text], $status);
    }
}
