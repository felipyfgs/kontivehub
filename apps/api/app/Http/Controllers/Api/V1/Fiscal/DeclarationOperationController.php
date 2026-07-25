<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\FiscalMutationOperation;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Declarations\DeclarationMutationService;
use App\Services\Fiscal\Declarations\DeclarationOperationReadService;
use App\Services\Fiscal\Declarations\DeclarationOperationRegistry;
use App\Services\Fiscal\ManualConsult\ManualConsultNotReadyException;
use App\Services\Fiscal\Mutations\FiscalMutationException;
use App\Services\Fiscal\Mutations\FiscalMutationService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/** Endpoints action-id-only da central de declarações. */
final class DeclarationOperationController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly TenantAuthorization $authorization,
        private readonly DeclarationOperationRegistry $registry,
        private readonly DeclarationOperationReadService $reads,
        private readonly DeclarationMutationService $declarationMutations,
        private readonly FiscalMutationService $mutations,
    ) {}

    public function read(Request $request, string $action): JsonResponse
    {
        $data = $this->validateExact($request, [
            'client_id' => ['required', 'integer'],
            'confirmed' => ['required', 'accepted'],
            'params' => ['sometimes', 'array'],
        ]);
        $client = $this->client((int) $data['client_id']);
        $this->assertPermission($request, TenantPermission::FiscalSyncTrigger, $client);

        try {
            $payload = $this->reads->execute(
                office: $this->currentOffice->office(),
                client: $client,
                actionId: $action,
                params: (array) ($data['params'] ?? []),
                confirmed: true,
                actorUserId: $request->user()?->id,
            );
            $payload['action_id'] = $action;
            $payload = $this->withoutTechnicalFields($payload);
        } catch (ManualConsultNotReadyException $e) {
            return $this->error($e->getMessage(), $e->eligibility->value, 422);
        } catch (InvalidArgumentException) {
            return $this->error('Operação declarativa não encontrada.', 'OPERATION_NOT_FOUND', 404);
        } catch (HttpException $e) {
            return $this->error($e->getMessage(), $e->getMessage(), $e->getStatusCode());
        }

        return response()->json(['data' => $payload], ($payload['async'] ?? false) ? 202 : 201);
    }

    public function preflight(Request $request, string $action): JsonResponse
    {
        $data = $this->validateExact($request, [
            'client_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'params' => ['required', 'array'],
        ]);
        $client = $this->client((int) $data['client_id']);
        $actor = $this->assertPermission($request, TenantPermission::FiscalMutationsExecute, $client);

        try {
            $result = $this->declarationMutations->preflight(
                $this->currentOffice->office(),
                $client,
                $actor,
                $action,
                $data['params'],
                $data['idempotency_key'],
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

        $payload = $this->publicPreflight($result->toArray(), $action);

        return response()->json(['data' => $payload], $result->eligible ? 200 : 422);
    }

    public function execute(Request $request, string $action): JsonResponse
    {
        $data = $this->validateExact($request, [
            'client_id' => ['required', 'integer'],
            'idempotency_key' => ['required', 'string', 'max:160'],
            'preflight_token' => ['required', 'string', 'max:64'],
            'confirmation_phrase' => ['required', 'string', 'max:120'],
            'confirmed' => ['required', 'boolean'],
            'params' => ['required', 'array'],
        ]);
        $client = $this->client((int) $data['client_id']);
        $actor = $this->assertPermission($request, TenantPermission::FiscalMutationsExecute, $client);

        try {
            $operation = $this->declarationMutations->execute(
                $this->currentOffice->office(),
                $client,
                $actor,
                $action,
                $data['params'],
                $data['idempotency_key'],
                $data['preflight_token'],
                $data['confirmation_phrase'],
                (bool) $data['confirmed'],
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

        return response()->json(['data' => $this->publicMutation($operation, $action)], 201);
    }

    public function show(Request $request, int $mutation): JsonResponse
    {
        $this->assertPermission($request, TenantPermission::FiscalMonitoringView);
        $operation = $this->mutations->findForOffice($this->currentOffice->office(), $mutation);
        if ($operation === null || $operation->provider_operation_key === null) {
            return $this->error('Operação não encontrada.', 'OPERATION_NOT_FOUND', 404);
        }

        try {
            $action = $this->registry->actionIdFor($operation->provider_operation_key);
        } catch (InvalidArgumentException) {
            return $this->error('Operação não encontrada.', 'OPERATION_NOT_FOUND', 404);
        }

        return response()->json(['data' => $this->publicMutation($operation, $action)]);
    }

    public function reconcile(Request $request, int $mutation): JsonResponse
    {
        $actor = $this->assertPermission($request, TenantPermission::FiscalMutationsExecute);
        $operation = $this->mutations->findForOffice($this->currentOffice->office(), $mutation);
        if ($operation === null || $operation->provider_operation_key === null) {
            return $this->error('Operação não encontrada.', 'OPERATION_NOT_FOUND', 404);
        }

        try {
            $action = $this->registry->actionIdFor($operation->provider_operation_key);
            $result = $this->mutations->reconcile($this->currentOffice->office(), $operation, $actor);
        } catch (InvalidArgumentException) {
            return $this->error('Operação não encontrada.', 'OPERATION_NOT_FOUND', 404);
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        }

        return response()->json(['data' => $this->publicMutation($result, $action)]);
    }

    /** @param array<string, array<int, mixed>> $rules @return array<string, mixed> */
    private function validateExact(Request $request, array $rules): array
    {
        $unknown = array_diff(array_keys($request->all()), array_keys($rules));
        if ($unknown !== []) {
            throw ValidationException::withMessages([
                'request' => ['Campos não permitidos: '.implode(', ', $unknown).'.'],
            ]);
        }

        return $request->validate($rules);
    }

    private function client(int $id): Client
    {
        $client = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $this->currentOffice->id())
            ->whereKey($id)
            ->first();
        if ($client === null) {
            abort(404, 'Cliente não encontrado no escritório atual.');
        }

        return $client;
    }

    private function assertPermission(
        Request $request,
        TenantPermission $permission,
        ?Client $client = null,
    ): User {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, $permission, $client)) {
            abort(403, 'Sem permissão para esta operação fiscal.');
        }

        return $actor;
    }

    /** @return array<string, mixed> */
    private function publicMutation(FiscalMutationOperation $operation, string $action): array
    {
        $payload = $operation->toPublicArray();
        foreach (['office_id', 'solution_code', 'service_code', 'operation_code', 'module_key', 'pre_operation_snapshot'] as $field) {
            unset($payload[$field]);
        }
        if (is_array($payload['eligibility'] ?? null)) {
            $payload['eligibility'] = $this->publicPolicy($payload['eligibility']);
        }
        $payload['action_id'] = $action;

        return $this->withoutTechnicalFields($payload);
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function publicPreflight(array $payload, string $action): array
    {
        $allowed = array_intersect_key($payload, array_flip([
            'eligible',
            'replayed',
            'confirmation_required',
            'confirmation_phrase',
            'effect',
            'contribuinte',
            'competence',
            'eligibility',
            'cost_estimate',
            'estimated_cost_micros',
            'preflight_token',
            'preflight_expires_at',
            'idempotency_key',
            'correlation_id',
            'mutation_operation_id',
            'status',
            'denial_code',
            'denial_message',
            'codes',
        ]));
        if (is_array($allowed['eligibility'] ?? null)) {
            $allowed['eligibility'] = $this->publicPolicy($allowed['eligibility']);
        }
        $allowed['action_id'] = $action;

        return $this->withoutTechnicalFields($allowed);
    }

    /** @param array<string, mixed> $policy @return array<string, mixed> */
    private function publicPolicy(array $policy): array
    {
        return array_intersect_key($policy, array_flip([
            'allowed',
            'codes',
            'primary_code',
            'messages',
            'confirmation_required',
            'totp_required',
        ]));
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function withoutTechnicalFields(array $payload): array
    {
        $blocked = [
            'office_id',
            'system_code',
            'solution_code',
            'service_code',
            'operation_code',
            'operation_key',
            'provider_operation_key',
            'id_sistema',
            'id_servico',
            'versao_sistema',
            'contractor_cnpj',
            'author_identity',
            'contributor_cnpj',
        ];
        $clean = [];
        foreach ($payload as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $blocked, true)) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->withoutTechnicalFields($value) : $value;
        }

        return $clean;
    }

    private function error(string $message, string $code, int $status): JsonResponse
    {
        return response()->json(['message' => $message, 'code' => $code], $status);
    }
}
