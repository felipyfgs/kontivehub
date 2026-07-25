<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\OfficeRole;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Services\Fiscal\Mutations\FiscalMutationException;
use App\Services\Fiscal\Mutations\FiscalMutationService;
use App\Services\Fiscal\Mutations\RecentTwoFactorGate;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Preflight, execução e reconciliação de operações fiscais mutantes (13.2–13.6).
 */
class FiscalMutationController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly FiscalMutationService $mutations,
        private readonly RecentTwoFactorGate $totp,
    ) {}

    /**
     * Confirma TOTP recente (janela de alto risco).
     */
    public function confirmTotp(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $user = $request->user();
        $data = $request->validate([
            'code' => ['required', 'string', 'max:16'],
        ]);

        try {
            $this->totp->confirmWithCode($user, $data['code'], $request);
        } catch (\RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'TOTP_INVALID',
            ], 422);
        }

        return response()->json([
            'data' => [
                'confirmed' => true,
                'window_minutes' => $this->totp->windowMinutes(),
                'seconds_remaining' => $this->totp->secondsRemaining($request),
            ],
        ]);
    }

    public function preflight(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $office = $this->currentOffice->office();
        $user = $request->user();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'solution_code' => ['required', 'string', 'max:80'],
            'service_code' => ['required', 'string', 'max:120'],
            'operation_code' => ['required', 'string', 'max:120'],
            'competence_period_key' => ['nullable', 'string', 'max:20'],
            'idempotency_key' => ['nullable', 'string', 'max:160'],
            'environment' => ['nullable', 'string', 'max:20'],
            'module' => ['nullable', 'string', 'max:40'],
            'payload' => ['nullable', 'array'],
        ]);

        $client = $this->resolveClient($office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $idempotency = $data['idempotency_key']
            ?? $request->header('Idempotency-Key');

        $result = $this->mutations->preflight(
            office: $office,
            client: $client,
            user: $user,
            solutionCode: $data['solution_code'],
            serviceCode: $data['service_code'],
            operationCode: $data['operation_code'],
            competencePeriodKey: $data['competence_period_key'] ?? null,
            idempotencyKey: is_string($idempotency) ? $idempotency : null,
            environment: $data['environment'] ?? null,
            requestPayload: $data['payload'] ?? [],
            module: $data['module'] ?? null,
        );

        $status = $result->eligible ? 200 : 422;

        return response()->json(['data' => $result->toArray()], $status);
    }

    public function execute(Request $request): JsonResponse
    {
        $this->assertAdmin();
        $office = $this->currentOffice->office();
        $user = $request->user();

        $data = $request->validate([
            'client_id' => ['required', 'integer'],
            'solution_code' => ['required', 'string', 'max:80'],
            'service_code' => ['required', 'string', 'max:120'],
            'operation_code' => ['required', 'string', 'max:120'],
            'competence_period_key' => ['nullable', 'string', 'max:20'],
            'idempotency_key' => ['nullable', 'string', 'max:160'],
            'preflight_token' => ['nullable', 'string', 'max:64'],
            'environment' => ['nullable', 'string', 'max:20'],
            'module' => ['nullable', 'string', 'max:40'],
            'payload' => ['nullable', 'array'],
            'confirmation_phrase' => ['required', 'string', 'max:120'],
            'confirmed' => ['required', 'boolean'],
        ]);

        $client = $this->resolveClient($office->id, (int) $data['client_id']);
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $idempotency = $data['idempotency_key']
            ?? $request->header('Idempotency-Key');

        try {
            $operation = $this->mutations->execute(
                office: $office,
                client: $client,
                user: $user,
                solutionCode: $data['solution_code'],
                serviceCode: $data['service_code'],
                operationCode: $data['operation_code'],
                confirmationPhrase: $data['confirmation_phrase'],
                confirmed: (bool) $data['confirmed'],
                competencePeriodKey: $data['competence_period_key'] ?? null,
                idempotencyKey: is_string($idempotency) ? $idempotency : null,
                preflightToken: $data['preflight_token'] ?? null,
                environment: $data['environment'] ?? null,
                requestPayload: $data['payload'] ?? [],
                module: $data['module'] ?? null,
            );
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        }

        return response()->json(['data' => $operation->toPublicArray()], 201);
    }

    public function show(int $mutation): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $model = $this->mutations->findForOffice($office, $mutation);
        if ($model === null) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }

        return response()->json(['data' => $model->toPublicArray()]);
    }

    public function reconcile(Request $request, int $mutation): JsonResponse
    {
        $this->assertAdmin();
        $office = $this->currentOffice->office();
        $user = $request->user();

        $model = $this->mutations->findForOffice($office, $mutation);
        if ($model === null) {
            return response()->json(['message' => 'Operação não encontrada.'], 404);
        }

        try {
            $result = $this->mutations->reconcile($office, $model, $user);
        } catch (FiscalMutationException $e) {
            return response()->json($e->toArray(), $e->httpStatus());
        }

        return response()->json(['data' => $result->toPublicArray()]);
    }

    private function resolveClient(int $officeId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->first();
    }

    private function assertAdmin(): void
    {
        if ($this->currentOffice->role() !== OfficeRole::Admin) {
            abort(403, 'Somente ADMIN pode executar mutações fiscais.');
        }
    }

    private function assertCanRead(): void
    {
        if ($this->currentOffice->role() === null) {
            abort(403, 'Perfil não resolvido.');
        }
    }
}
