<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Controller;
use App\Models\SerproDteCanaryRequest;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Serpro\SerproDteCanaryService;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * Superfície global do canário DTE (Proprietário / PLATFORM_ADMIN).
 * Nunca devolve payload fiscal — apenas resumo sanitizado.
 */
class SerproDteCanaryController extends Controller
{
    public function __construct(
        private readonly SerproDteCanaryService $canary,
        private readonly RecentPasswordConfirmationGate $passwordGate,
    ) {}

    public function summary(Request $request): JsonResponse
    {
        $requestId = $request->query('request_id');
        $id = is_numeric($requestId) ? (int) $requestId : null;

        return response()->json([
            'data' => $this->canary->globalSummary($id),
        ]);
    }

    public function create(Request $request): JsonResponse
    {
        $row = $this->canary->createRequest((int) $request->user()->id);

        return response()->json([
            'data' => $row->toGlobalSanitizedArray(),
        ], 201);
    }

    public function selectTarget(Request $request, SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        // Aceita office_id e client_id somente neste comando de seleção global
        // (não no execute / approve). Rejeita operação/coordenadas livres.
        $data = $request->validate([
            'office_id' => ['required', 'integer', 'min:1'],
            'client_id' => ['required', 'integer', 'min:1'],
            'operation_key' => ['prohibited'],
            'id_sistema' => ['prohibited'],
            'id_servico' => ['prohibited'],
            'functional_route' => ['prohibited'],
            'business_data' => ['prohibited'],
            'payload' => ['prohibited'],
        ]);

        try {
            $row = $this->canary->selectTarget(
                $serproDteCanaryRequest,
                (int) $data['office_id'],
                (int) $data['client_id'],
                (int) $request->user()->id,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_target_error'], 422);
        }

        return response()->json(['data' => $row->toGlobalSanitizedArray()]);
    }

    public function approveOwner(Request $request, SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        $confirmed = $this->passwordGate->isRecentlyConfirmed($request->user(), $request);
        if (! $confirmed) {
            return response()->json([
                'message' => 'Reconfirmação de senha obrigatória (15 minutos).',
                'code' => 'password_confirmation_required',
            ], 403);
        }

        try {
            $row = $this->canary->approveAsOwner(
                $serproDteCanaryRequest,
                $request->user(),
                true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_owner_approve_error'], 422);
        }

        return response()->json(['data' => $row->toGlobalSanitizedArray()]);
    }

    public function execute(Request $request, SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        $confirmed = $this->passwordGate->isRecentlyConfirmed($request->user(), $request);
        if (! $confirmed) {
            return response()->json([
                'message' => 'Reconfirmação de senha obrigatória (15 minutos).',
                'code' => 'password_confirmation_required',
            ], 403);
        }

        // Rejeitar qualquer override de escopo/operação
        foreach (['office_id', 'client_id', 'operation_key', 'id_sistema', 'id_servico', 'payload', 'business_data'] as $forbidden) {
            if ($request->exists($forbidden)) {
                return response()->json([
                    'message' => "Campo {$forbidden} não é aceito na execução do canário DTE.",
                    'code' => 'forbidden_field',
                ], 422);
            }
        }

        try {
            $result = $this->canary->execute($serproDteCanaryRequest, (int) $request->user()->id);
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_execute_blocked'], 422);
        }

        return response()->json([
            'data' => $result['global'],
            'replay' => $result['replay'],
        ]);
    }

    public function reconcile(Request $request, SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        $confirmed = $this->passwordGate->isRecentlyConfirmed($request->user(), $request);
        if (! $confirmed) {
            return response()->json([
                'message' => 'Reconfirmação de senha obrigatória (15 minutos).',
                'code' => 'password_confirmation_required',
            ], 403);
        }

        $data = $request->validate([
            'reference' => ['required', 'string', 'max:200'],
            'summary' => ['required', 'string', 'max:1000'],
        ]);

        try {
            $row = $this->canary->reconcile(
                $serproDteCanaryRequest,
                (int) $request->user()->id,
                $data['reference'],
                $data['summary'],
                true,
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_reconcile_error'], 422);
        }

        return response()->json(['data' => $row->toGlobalSanitizedArray()]);
    }

    public function promoteLimited(Request $request, SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        $confirmed = $this->passwordGate->isRecentlyConfirmed($request->user(), $request);
        if (! $confirmed) {
            return response()->json([
                'message' => 'Reconfirmação de senha obrigatória (15 minutos).',
                'code' => 'password_confirmation_required',
            ], 403);
        }

        $data = $request->validate([
            'confirmation_phrase' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:500'],
            'change_window_start' => ['nullable', 'date'],
            'change_window_end' => ['nullable', 'date', 'after:change_window_start'],
            'max_quantity' => ['sometimes', 'integer', 'min:1', 'max:10'],
        ]);

        try {
            $control = $this->canary->promoteLimited(
                $serproDteCanaryRequest,
                $request->user(),
                true,
                $data['confirmation_phrase'],
                $data['reason'],
                isset($data['change_window_start'])
                    ? CarbonImmutable::parse($data['change_window_start'])
                    : null,
                isset($data['change_window_end'])
                    ? CarbonImmutable::parse($data['change_window_end'])
                    : null,
                (int) ($data['max_quantity'] ?? 10),
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_promote_error'], 422);
        }

        return response()->json(['data' => $control->toSanitizedArray()]);
    }

    public function disable(Request $request): JsonResponse
    {
        $confirmed = $this->passwordGate->isRecentlyConfirmed($request->user(), $request);
        if (! $confirmed) {
            return response()->json([
                'message' => 'Reconfirmação de senha obrigatória (15 minutos).',
                'code' => 'password_confirmation_required',
            ], 403);
        }

        $data = $request->validate([
            'confirmation_phrase' => ['required', 'string', 'max:80'],
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $control = $this->canary->disable(
                $request->user(),
                true,
                $data['confirmation_phrase'],
                $data['reason'],
            );
        } catch (RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'dte_disable_error'], 422);
        }

        return response()->json(['data' => $control->toSanitizedArray()]);
    }

    public function show(SerproDteCanaryRequest $serproDteCanaryRequest): JsonResponse
    {
        return response()->json([
            'data' => $serproDteCanaryRequest->toGlobalSanitizedArray(),
        ]);
    }
}
