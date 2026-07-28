<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\OperateTaxGuideAction;
use App\DTO\Fiscal\Monitoring\TaxGuidePageData;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\DownloadTaxGuideRequest;
use App\Http\Requests\Fiscal\Monitoring\ListTaxGuidesRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewTaxGuideRequest;
use App\Http\Requests\Fiscal\Mutations\ConfirmTaxGuidePaymentRequest;
use App\Http\Requests\Fiscal\Mutations\IssueTaxGuideRequest;
use App\Http\Requests\Fiscal\Mutations\PreflightTaxGuideRequest;
use App\Http\Requests\Fiscal\Mutations\ReconcileTaxGuideRequest;
use App\Http\Resources\Fiscal\TaxGuideDetailResource;
use App\Http\Resources\Fiscal\TaxGuideDownloadTokenResource;
use App\Http\Resources\Fiscal\TaxGuideIssuanceResultResource;
use App\Http\Resources\Fiscal\TaxGuidePageResource;
use App\Http\Resources\Fiscal\TaxGuidePaymentResultResource;
use App\Http\Resources\Fiscal\TaxGuidePreflightResource;
use App\Http\Resources\Fiscal\TaxGuideReconcileResultResource;
use App\Models\User;
use App\Services\Fiscal\Guides\ClientGuidesQueryService;
use App\Services\Fiscal\Guides\Exceptions\GuideException;
use App\Services\Fiscal\Guides\GuideQueryService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Central de guias — tenant-scoped; mutações OFF por default.
 */
class TaxGuideController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly GuideQueryService $queries,
        private readonly ClientGuidesQueryService $clientGuides,
        private readonly OperateTaxGuideAction $guides,
    ) {}

    public function index(ListTaxGuidesRequest $request): TaxGuidePageResource
    {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        $result = $this->clientGuides->paginate(
            $tenant,
            $filters->clientId,
            $filters->perPage,
            $filters->paymentStatus,
            $filters->sort,
            $filters->sortDirection,
        );

        return new TaxGuidePageResource(new TaxGuidePageData(
            page: $result['page'],
            paymentCounters: $result['payment_counters'],
        ));
    }

    public function show(
        ViewTaxGuideRequest $request,
        int $guide,
    ): JsonResponse|TaxGuideDetailResource {
        $tenant = $this->currentTenant->tenant();

        try {
            $model = $this->queries->find($tenant, $guide);
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return new TaxGuideDetailResource($model);
    }

    public function preflight(
        PreflightTaxGuideRequest $request,
    ): JsonResponse|TaxGuidePreflightResource {
        try {
            $preflight = $this->guides->preflight(
                $request->actor(),
                $request->preflightData(),
            );
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return new TaxGuidePreflightResource($preflight);
    }

    public function store(
        IssueTaxGuideRequest $request,
    ): JsonResponse|TaxGuideIssuanceResultResource {
        try {
            $result = $this->guides->issue(
                $request->actor(),
                $request->issueData(),
            );
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return new TaxGuideIssuanceResultResource($result);
    }

    public function issueDownloadToken(
        ViewTaxGuideRequest $request,
        int $guide,
    ): JsonResponse|TaxGuideDownloadTokenResource {
        try {
            $token = $this->guides->issueDownloadToken(
                $request->actor(),
                $guide,
            );
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return new TaxGuideDownloadTokenResource($token);
    }

    public function download(
        DownloadTaxGuideRequest $request,
        string $token,
    ): StreamedResponse|JsonResponse {
        try {
            $actor = $request->user();
            $payload = $this->guides->consumeDownload(
                $token,
                $actor instanceof User ? $actor : null,
            );
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return response()->streamDownload(function () use ($payload): void {
            echo $payload['bytes'];
        }, $payload['filename'], [
            'Content-Type' => $payload['content_type'],
            'X-Content-SHA256' => $payload['sha256'],
            'Cache-Control' => 'no-store',
        ]);
    }

    public function confirmPayment(
        ConfirmTaxGuidePaymentRequest $request,
        int $guide,
    ): JsonResponse|TaxGuidePaymentResultResource {
        try {
            $result = $this->guides->confirmPayment(
                $request->actor(),
                $guide,
            );
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return new TaxGuidePaymentResultResource($result);
    }

    public function reconcile(
        ReconcileTaxGuideRequest $request,
        int $guide,
    ): JsonResponse|TaxGuideReconcileResultResource {
        try {
            $result = $this->guides->reconcile($guide);
        } catch (GuideException $e) {
            return $this->guideError($e);
        }

        return new TaxGuideReconcileResultResource($result);
    }

    private function guideError(GuideException $e): JsonResponse
    {
        return response()->json([
            'message' => $e->getMessage(),
            'code' => $e->codeKey,
            'context' => $e->context,
        ], $e->httpStatus);
    }
}
