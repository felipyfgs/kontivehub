<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Enums\SerproEnvironment;
use App\Http\Controllers\Controller;
use App\Models\SerproContract;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Serpro\SerproCatalogService;
use App\Services\Serpro\SerproCircuitBreaker;
use App\Services\Serpro\SerproContractService;
use App\Services\Serpro\SerproHealthService;
use App\Services\Serpro\SerproKillSwitchService;
use App\Services\Serpro\SerproRolloutApprovalService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

/**
 * Administração global do contrato SERPRO (PLATFORM_ADMIN).
 * Nunca retorna PFX, senha, PEM, Consumer Secret, tokens ou Termo XML.
 */
class SerproContractController extends Controller
{
    public function __construct(
        private readonly SerproContractService $contracts,
        private readonly SerproHealthService $health,
        private readonly SerproCatalogService $catalog,
        private readonly SerproKillSwitchService $killSwitch,
        private readonly SerproCircuitBreaker $breaker,
        private readonly SerproRolloutApprovalService $rollouts,
        private readonly AuditLogger $audit,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $env = $this->parseEnv($request->query('environment'));

        return response()->json([
            'data' => $this->contracts->listSanitized($env),
        ]);
    }

    public function show(SerproContract $serproContract): JsonResponse
    {
        return response()->json([
            'data' => $serproContract->toSanitizedArray(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'environment' => ['required', 'string', Rule::enum(SerproEnvironment::class)],
            'pfx' => ['required', 'file', 'max:5120'],
            'password' => ['required', 'string'],
            'consumer_key' => ['required', 'string', 'max:200'],
            'consumer_secret' => ['required', 'string', 'max:200'],
            'contractor_name' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'activate' => ['sometimes', 'boolean'],
            'replace' => ['sometimes', 'boolean'],
        ]);

        try {
            $binary = file_get_contents($data['pfx']->getRealPath());
            if ($binary === false) {
                throw new RuntimeException('Falha ao ler arquivo PFX.');
            }

            $env = SerproEnvironment::from($data['environment']);
            $contract = $this->contracts->register(
                $env,
                $binary,
                $data['password'],
                $data['consumer_key'],
                $data['consumer_secret'],
                $data['contractor_name'] ?? null,
                $data['notes'] ?? null,
                $request->user()?->id,
            );

            if (! empty($data['activate'])) {
                $contract = $this->contracts->activate(
                    $contract,
                    replace: ! empty($data['replace']),
                    actorUserId: $request->user()?->id,
                );
            }
        } catch (RuntimeException $e) {
            $this->audit->record('serpro.contract.register', 'FAILED', null, [
                'message' => $e->getMessage(),
            ], $request->user()?->id, null);

            return response()->json(['message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            report($e);
            $this->audit->record('serpro.contract.register', 'FAILED', null, [
                'message' => 'Falha ao cadastrar contrato.',
            ], $request->user()?->id, null);

            return response()->json(['message' => 'Falha ao cadastrar contrato.'], 422);
        }

        return response()->json(['data' => $contract->toSanitizedArray()], 201);
    }

    public function activate(Request $request, SerproContract $serproContract): JsonResponse
    {
        $data = $request->validate([
            'replace' => ['sometimes', 'boolean'],
            'approval_id' => ['sometimes', 'integer', 'min:1'],
        ]);

        try {
            $contract = $this->contracts->activate(
                $serproContract,
                replace: ! empty($data['replace']),
                actorUserId: $request->user()?->id,
                approvalId: isset($data['approval_id']) ? (int) $data['approval_id'] : null,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json(['data' => $contract->toSanitizedArray()]);
    }

    public function deactivate(Request $request, SerproContract $serproContract): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['nullable', 'string', 'max:500'],
        ]);

        $contract = $this->contracts->deactivate(
            $serproContract,
            $data['reason'] ?? null,
            $request->user()?->id,
        );

        return response()->json(['data' => $contract->toSanitizedArray()]);
    }

    public function block(Request $request, SerproContract $serproContract): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $contract = $this->contracts->block(
            $serproContract,
            $data['reason'],
            $request->user()?->id,
        );

        return response()->json(['data' => $contract->toSanitizedArray()]);
    }

    public function health(Request $request): JsonResponse
    {
        $env = $this->parseEnv($request->query('environment'));

        return response()->json([
            'data' => $this->health->globalHealth($env),
        ]);
    }

    public function catalog(Request $request): JsonResponse
    {
        $env = $this->parseEnv($request->query('environment'))
            ?? SerproEnvironment::Trial;

        return response()->json([
            'data' => $this->catalog->listForEnvironment($env),
        ]);
    }

    public function killSwitch(Request $request): JsonResponse
    {
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'max:500'],
            'solution' => ['nullable', 'string', 'max:80'],
            'confirmation_phrase' => ['required_if:active,false', 'nullable', 'string', 'max:120'],
            'change_window_start' => ['required_if:active,false', 'nullable', 'date'],
            'change_window_end' => ['required_if:active,false', 'nullable', 'date', 'after:change_window_start'],
        ]);

        $userId = (int) $request->user()->id;

        // Ativar kill switch: imediato (fail-closed). Desativar: confirmação OWNER.
        if (! empty($data['solution'])) {
            if ($data['active']) {
                $this->killSwitch->activateSolution($data['solution'], $data['reason'], $userId);

                return response()->json(['data' => $this->killSwitch->status()]);
            }

            return $this->deactivateKillWithOwnerConfirmation(
                $request,
                action: SerproRolloutApprovalService::ACTION_KILL_SWITCH_SOLUTION_OFF,
                subjectType: 'KILL_SWITCH_SOLUTION',
                reason: $data['reason'],
                userId: $userId,
                context: ['solution' => strtoupper((string) $data['solution'])],
                confirmationPhrase: (string) ($data['confirmation_phrase'] ?? ''),
                windowStart: (string) ($data['change_window_start'] ?? ''),
                windowEnd: (string) ($data['change_window_end'] ?? ''),
                successMessage: 'Kill switch de solução desativado.',
            );
        }

        if ($data['active']) {
            $this->killSwitch->activateGlobal($data['reason'], $userId);

            return response()->json(['data' => $this->killSwitch->status()]);
        }

        return $this->deactivateKillWithOwnerConfirmation(
            $request,
            action: SerproRolloutApprovalService::ACTION_KILL_SWITCH_OFF,
            subjectType: 'KILL_SWITCH',
            reason: $data['reason'],
            userId: $userId,
            context: [],
            confirmationPhrase: (string) ($data['confirmation_phrase'] ?? ''),
            windowStart: (string) ($data['change_window_start'] ?? ''),
            windowEnd: (string) ($data['change_window_end'] ?? ''),
            successMessage: 'Kill switch global desativado.',
        );
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function deactivateKillWithOwnerConfirmation(
        Request $request,
        string $action,
        string $subjectType,
        string $reason,
        int $userId,
        array $context,
        string $confirmationPhrase,
        string $windowStart,
        string $windowEnd,
        string $successMessage,
    ): JsonResponse {
        $passwordOk = app(RecentPasswordConfirmationGate::class)
            ->isRecentlyConfirmed($request->user(), $request);

        if (! $passwordOk) {
            return response()->json([
                'message' => 'Retirada de kill switch exige reconfirmação de senha recente.',
                'code' => 'password_confirmation_required',
            ], 403);
        }

        try {
            $start = CarbonImmutable::parse($windowStart);
            $end = CarbonImmutable::parse($windowEnd);

            // Sem estado parcial: request + approve OWNER no mesmo fluxo; falha não deixa PARTIAL.
            $approval = $this->rollouts->request(
                action: $action,
                subjectType: $subjectType,
                subjectId: null,
                reason: $reason,
                requestedByUserId: $userId,
                context: $context,
                changeWindowStart: $start,
                changeWindowEnd: $end,
                fromHttp: true,
            );

            $result = $this->rollouts->approve(
                $approval,
                $userId,
                passwordRecentlyConfirmed: true,
                reason: $reason,
                confirmationPhrase: $confirmationPhrase,
                changeWindowStart: $start,
                changeWindowEnd: $end,
                fromHttp: true,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'owner_confirmation_failed',
            ], 422);
        }

        return response()->json([
            'data' => $this->killSwitch->status(),
            'approval' => $this->rollouts->toSanitized($result['approval']),
            'executed' => $result['executed'],
            'message' => $result['executed']
                ? $successMessage
                : 'Confirmação registrada; execução pendente de gates.',
        ]);
    }

    public function killSwitchStatus(): JsonResponse
    {
        return response()->json(['data' => $this->killSwitch->status()]);
    }

    public function breakerReset(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        $this->breaker->resetGlobal($data['reason'], $request->user()?->id);

        return response()->json(['data' => $this->breaker->globalStatus()]);
    }

    private function parseEnv(mixed $raw): ?SerproEnvironment
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }

        return SerproEnvironment::tryFrom(strtoupper($raw));
    }
}
