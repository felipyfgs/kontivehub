<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\OperateFiscalMutationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Mutations\ExecuteFiscalMutationRequest;
use App\Http\Requests\Fiscal\Mutations\PreflightFiscalMutationRequest;
use App\Http\Requests\Fiscal\Mutations\ReconcileFiscalMutationRequest;
use App\Http\Requests\Fiscal\Mutations\ShowFiscalMutationRequest;
use App\Http\Resources\Fiscal\FiscalMutationOperationResource;
use App\Http\Resources\Fiscal\FiscalMutationPreflightResource;
use App\Services\Fiscal\Mutations\FiscalMutationException;
use Illuminate\Http\JsonResponse;

/**
 * Preflight, execução e reconciliação de operações fiscais mutantes (13.2–13.6).
 */
class FiscalMutationController extends Controller
{
    public function __construct(
        private readonly OperateFiscalMutationAction $mutations,
    ) {}

    public function preflight(PreflightFiscalMutationRequest $request): FiscalMutationPreflightResource
    {
        $result = $this->mutations->preflight(
            $request->actor(),
            $request->preflightData(),
        );

        return new FiscalMutationPreflightResource($result);
    }

    public function execute(ExecuteFiscalMutationRequest $request): JsonResponse|FiscalMutationOperationResource
    {
        try {
            $operation = $this->mutations->execute(
                $request->actor(),
                $request->executeData(),
            );
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        }

        return (new FiscalMutationOperationResource($operation))
            ->response()
            ->setStatusCode(201);
    }

    public function show(
        ShowFiscalMutationRequest $request,
        int $mutation,
    ): FiscalMutationOperationResource {
        return new FiscalMutationOperationResource(
            $this->mutations->show($mutation),
        );
    }

    public function reconcile(
        ReconcileFiscalMutationRequest $request,
        int $mutation,
    ): JsonResponse|FiscalMutationOperationResource {
        try {
            $result = $this->mutations->reconcile(
                $request->actor(),
                $mutation,
            );
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        }

        return new FiscalMutationOperationResource($result);
    }
}
