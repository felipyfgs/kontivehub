<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Actions\Fiscal\Mutations\RefreshSitfisSituationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\ShowSitfisSituationRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewSitfisHistoryRequest;
use App\Http\Requests\Fiscal\Mutations\RefreshSitfisSituationRequest;
use App\Services\Fiscal\Sitfis\SitfisHistoryQueryService;
use App\Services\Integra\Sitfis\SitfisSnapshotService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Situação Fiscal (SITFIS): leitura com idade do snapshot; refresh respeita TTL.
 */
class SitfisSituationController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly SitfisSnapshotService $sitfis,
        private readonly SitfisHistoryQueryService $historyQueries,
        private readonly RefreshSitfisSituationAction $refresh,
    ) {}

    /**
     * Histórico local consolidado por consulta. Nunca dispara refresh/Integra.
     */
    public function history(ViewSitfisHistoryRequest $request, int $client): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $model = $request->client();
        if ($model === null) {
            return response()->json([
                'message' => 'Cliente não encontrado no escritório atual.',
                'code' => 'CLIENT_NOT_FOUND',
            ], 404);
        }

        return response()->json([
            'data' => $this->historyQueries->history($tenant, $model),
        ]);
    }

    /**
     * GET — devolve snapshot existente + idade. Nunca dispara nova chamada só por abrir a tela.
     */
    public function show(ShowSitfisSituationRequest $request): JsonResponse
    {
        $tenant = $this->currentTenant->tenant();
        $client = $request->client();
        if ($client === null) {
            return response()->json(['message' => 'Cliente não encontrado.'], 404);
        }

        $view = $this->sitfis->current($tenant, $client);

        return response()->json([
            'data' => $this->sitfis->publicView($view),
        ]);
    }

    /**
     * POST — solicita nova emissão só se TTL expirado ou ausente.
     */
    public function refresh(RefreshSitfisSituationRequest $request): JsonResponse
    {
        try {
            $result = $this->refresh->handle(
                $request->actor(),
                $request->refreshData(),
            );
        } catch (NotFoundHttpException $e) {
            return $this->failure($e, 404);
        } catch (RuntimeException $e) {
            return $this->failure($e, 422);
        }

        $status = $result['enqueued'] ? 202 : 200;

        return response()->json([
            'data' => [
                'enqueued' => $result['enqueued'],
                'reused_snapshot' => $result['reused_snapshot'],
                'reason' => $result['reason'],
                'run' => $result['run']?->toPublicArray(),
                'situation' => $result['view'],
            ],
        ], $status);
    }

    private function failure(\Throwable $error, int $status): JsonResponse
    {
        $text = $error->getMessage();

        return response()->json(['message' => $text], $status);
    }
}
