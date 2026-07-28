<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\OperateDeclarationOperationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Mutations\ExecuteDeclarationOperationRequest;
use App\Http\Requests\Fiscal\Mutations\PreflightDeclarationOperationRequest;
use App\Http\Requests\Fiscal\Mutations\ReadDeclarationOperationRequest;
use App\Http\Requests\Fiscal\Mutations\ReconcileDeclarationOperationRequest;
use App\Http\Requests\Fiscal\Mutations\ShowDeclarationOperationRequest;
use App\Http\Resources\Fiscal\DeclarationOperationPayloadResource;
use App\Services\Fiscal\ManualConsult\ManualConsultNotReadyException;
use App\Services\Fiscal\Mutations\FiscalMutationException;
use Illuminate\Http\JsonResponse;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/** Endpoints action-id-only da central de declarações. */
final class DeclarationOperationController extends Controller
{
    public function __construct(
        private readonly OperateDeclarationOperationAction $operations,
    ) {}

    public function read(
        ReadDeclarationOperationRequest $request,
        string $action,
    ): JsonResponse|DeclarationOperationPayloadResource {
        try {
            $result = $this->operations->read(
                $request->actor(),
                $action,
                $request->readData(),
            );
        } catch (ManualConsultNotReadyException $e) {
            return $this->error($e->getMessage(), $e->eligibility->value, 422);
        } catch (InvalidArgumentException) {
            return $this->error('Operação declarativa não encontrada.', 'OPERATION_NOT_FOUND', 404);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getMessage(), $e->getStatusCode());
        }

        return (new DeclarationOperationPayloadResource($result['payload']))
            ->withStatus($result['status']);
    }

    public function preflight(
        PreflightDeclarationOperationRequest $request,
        string $action,
    ): JsonResponse|DeclarationOperationPayloadResource {
        try {
            $result = $this->operations->preflight(
                $request->actor(),
                $action,
                $request->preflightData(),
            );
        } catch (InvalidArgumentException) {
            return $this->error('Operação declarativa não encontrada.', 'OPERATION_NOT_FOUND', 404);
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getMessage(), $e->getStatusCode());
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'DECLARATION_OPERATION_REJECTED', 422);
        }

        $payload = $this->operations->presentPreflight($result->toArray(), $action);

        return (new DeclarationOperationPayloadResource($payload))
            ->withStatus($result->eligible ? 200 : 422);
    }

    public function execute(
        ExecuteDeclarationOperationRequest $request,
        string $action,
    ): JsonResponse|DeclarationOperationPayloadResource {
        try {
            $operation = $this->operations->execute(
                $request->actor(),
                $action,
                $request->executeData(),
            );
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        } catch (InvalidArgumentException) {
            return $this->error('Operação declarativa não encontrada.', 'OPERATION_NOT_FOUND', 404);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getMessage(), $e->getStatusCode());
        } catch (RuntimeException $e) {
            return $this->error($e->getMessage(), 'DECLARATION_OPERATION_REJECTED', 422);
        }

        return (new DeclarationOperationPayloadResource(
            $this->operations->presentMutation($operation, $action),
        ))->withStatus(201);
    }

    public function show(
        ShowDeclarationOperationRequest $request,
        int $mutation,
    ): JsonResponse|DeclarationOperationPayloadResource {
        try {
            $result = $this->operations->show($mutation);
        } catch (NotFoundHttpException) {
            return $this->error('Operação não encontrada.', 'OPERATION_NOT_FOUND', 404);
        }

        return new DeclarationOperationPayloadResource(
            $this->operations->presentMutation($result['operation'], $result['action']),
        );
    }

    public function reconcile(
        ReconcileDeclarationOperationRequest $request,
        int $mutation,
    ): JsonResponse|DeclarationOperationPayloadResource {
        try {
            $result = $this->operations->reconcile($request->actor(), $mutation);
        } catch (NotFoundHttpException) {
            return $this->error('Operação não encontrada.', 'OPERATION_NOT_FOUND', 404);
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        }

        return new DeclarationOperationPayloadResource(
            $this->operations->presentMutation($result['operation'], $result['action']),
        );
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
