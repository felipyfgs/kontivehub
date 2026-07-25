<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\SerproEnvironment;
use App\Enums\SerproExternalGateKind;
use App\Http\Controllers\Controller;
use App\Models\SerproContract;
use App\Models\SerproCredentialVersion;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Serpro\SerproCredentialVersionService;
use App\Services\Serpro\SerproExternalGateService;
use App\Services\Serpro\SerproPlatformConfigurationService;
use App\Services\Serpro\SerproQuantityUsageLimitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Configuração global SERPRO (PLATFORM_ADMIN / Proprietário).
 * Sem Office context; respostas sempre sanitizadas; sem transporte fiscal.
 */
class SerproPlatformConfigurationController extends Controller
{
    public function __construct(
        private readonly SerproPlatformConfigurationService $configuration,
        private readonly SerproCredentialVersionService $credentials,
        private readonly SerproExternalGateService $externalGates,
        private readonly SerproQuantityUsageLimitService $quantityLimits,
        private readonly AuditLogger $audit,
    ) {}

    public function show(Request $request): JsonResponse
    {
        $env = $this->parseEnv($request->query('environment')) ?? SerproEnvironment::Trial;

        return response()->json([
            'data' => $this->configuration->getConfiguration($env),
        ]);
    }

    public function storeCredentialVersion(Request $request): JsonResponse
    {
        if (! $this->passwordOk($request)) {
            return $this->passwordRequired();
        }

        $data = $request->validate([
            'environment' => ['required', 'string', Rule::enum(SerproEnvironment::class)],
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
            'consumer_key' => ['required', 'string', 'max:200'],
            'consumer_secret' => ['required', 'string', 'max:200'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'serpro_contract_id' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $binary = file_get_contents($data['pfx']->getRealPath());
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler arquivo PFX.');
            }

            $env = SerproEnvironment::from($data['environment']);
            $contract = null;
            if (! empty($data['serpro_contract_id'])) {
                $contract = SerproContract::query()->find((int) $data['serpro_contract_id']);
            }

            $version = $this->credentials->registerPending(
                $env,
                $binary,
                $data['password'],
                $data['consumer_key'],
                $data['consumer_secret'],
                $contract,
                $data['notes'] ?? null,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            $this->audit->record('serpro.credential.register_pending', 'FAILED', null, [
                'message' => $e->getMessage(),
            ], $request->user()?->id, null);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json(['message' => 'Falha ao cadastrar versão de credencial.'], 500);
        }

        return response()->json(['data' => $version->toSanitizedArray()], 201);
    }

    public function verifyCredentialVersion(
        Request $request,
        SerproCredentialVersion $serproCredentialVersion,
    ): JsonResponse {
        if (! $this->passwordOk($request)) {
            return $this->passwordRequired();
        }

        try {
            $version = $this->credentials->verifyPending(
                $serproCredentialVersion,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $version->toSanitizedArray()]);
    }

    public function testConnection(
        Request $request,
        SerproCredentialVersion $serproCredentialVersion,
    ): JsonResponse {
        if (! $this->passwordOk($request)) {
            return $this->passwordRequired();
        }

        try {
            $evidence = $this->credentials->testConnection(
                $serproCredentialVersion,
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'evidence' => $evidence->toSanitizedArray(),
                'credential_version' => $serproCredentialVersion->fresh()->toSanitizedArray(),
            ],
        ]);
    }

    public function cutoverCredentialVersion(
        Request $request,
        SerproCredentialVersion $serproCredentialVersion,
    ): JsonResponse {
        if (! $this->passwordOk($request)) {
            return $this->passwordRequired();
        }

        $data = $request->validate([
            'skip_oauth' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
            'approval_id' => ['sometimes', 'integer', 'min:1'],
            'serpro_contract_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        $contract = null;
        if (! empty($data['serpro_contract_id'])) {
            $contract = SerproContract::query()->find((int) $data['serpro_contract_id']);
        }

        try {
            $version = $this->credentials->cutover(
                $serproCredentialVersion,
                contract: $contract,
                actorUserId: $request->user()?->id,
                skipOauth: (bool) ($data['skip_oauth'] ?? false),
                approvalId: isset($data['approval_id']) ? (int) $data['approval_id'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $version->toSanitizedArray()]);
    }

    public function updateExternalGate(Request $request, string $gate): JsonResponse
    {
        if (! $this->passwordOk($request)) {
            return $this->passwordRequired();
        }

        $kind = SerproExternalGateKind::tryFrom(strtoupper($gate));
        if ($kind === null) {
            return response()->json(['message' => 'Gate desconhecido.'], 404);
        }

        $data = $request->validate([
            'ticket_ref' => ['required', 'string', 'max:120'],
            'answer_summary' => ['required', 'string', 'max:1000'],
            'responsible_name' => ['required', 'string', 'max:200'],
            'reference_date' => ['required', 'date'],
            'environment' => ['sometimes', 'string', Rule::enum(SerproEnvironment::class)],
        ]);

        try {
            $env = isset($data['environment'])
                ? SerproEnvironment::from($data['environment'])
                : SerproEnvironment::Production;

            $row = $this->externalGates->acceptGate(
                $kind,
                $data['ticket_ref'],
                $data['answer_summary'],
                $data['responsible_name'],
                $data['reference_date'],
                $request->user()?->id,
                $env,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $row->toSanitizedArray()]);
    }

    public function updateUsageLimits(Request $request): JsonResponse
    {
        if (! $this->passwordOk($request)) {
            return $this->passwordRequired();
        }

        $data = $request->validate([
            'environment' => ['required', 'string', Rule::enum(SerproEnvironment::class)],
            'cycle_start_day' => ['required', 'integer', 'min:1', 'max:28'],
            'alert_percent' => ['required', 'integer', 'min:1', 'max:100'],
            'global_limit_quantity' => ['nullable', 'integer', 'min:1'],
            'office_limits' => ['sometimes', 'array'],
            'office_limits.*.office_id' => ['required', 'integer', 'min:1'],
            'office_limits.*.limit_quantity' => ['nullable', 'integer', 'min:1'],
        ]);

        try {
            $env = SerproEnvironment::from($data['environment']);
            $row = $this->quantityLimits->upsert(
                $env,
                (int) $data['cycle_start_day'],
                (int) $data['alert_percent'],
                isset($data['global_limit_quantity']) ? (int) $data['global_limit_quantity'] : null,
                $data['office_limits'] ?? [],
                $request->user()?->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'config' => $row->toSanitizedArray(),
                'office_limits' => $this->quantityLimits->listOfficeLimits(
                    SerproEnvironment::from($data['environment']),
                ),
            ],
        ]);
    }

    /**
     * Rotas legadas de mutação de contrato: removidas (410).
     */
    public function legacyMutationRemoved(): JsonResponse
    {
        return response()->json([
            'message' => 'Interface legada removida. Use /api/v1/platform/serpro/credential-versions e cutover versionado.',
            'code' => 'legacy_contract_mutation_removed',
        ], 410);
    }

    private function parseEnv(mixed $raw): ?SerproEnvironment
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return SerproEnvironment::tryFrom(strtoupper((string) $raw));
    }

    private function passwordOk(Request $request): bool
    {
        return app(RecentPasswordConfirmationGate::class)
            ->isRecentlyConfirmed($request->user(), $request);
    }

    private function passwordRequired(): JsonResponse
    {
        return response()->json([
            'message' => 'Operação exige reconfirmação de senha recente.',
            'code' => 'password_confirmation_required',
        ], 403);
    }
}
