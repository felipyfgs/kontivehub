<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Mei\ConsultDasnHistoryRequest;
use App\Http\Requests\Fiscal\Mei\ListDasnHistoryRequest;
use App\Models\Client;
use App\Services\Fiscal\SimplesMei\DasnSimeiQueryService;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

final class DasnSimeiController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly DasnSimeiQueryService $queries,
    ) {}

    public function history(ListDasnHistoryRequest $request, int $client): JsonResponse
    {
        $office = $this->currentOffice->office();
        $model = $this->client((int) $office->id, $client);
        if ($model === null) {
            return $this->clientNotFound();
        }

        return response()->json(['data' => $this->queries->history(
            $office,
            $model,
            $request->validated('calendar_year'),
        )]);
    }

    public function consult(ConsultDasnHistoryRequest $request): JsonResponse
    {
        $this->assertModuleEnabled();
        try {
            $runs = $this->queries->enqueueManualConsult(
                $this->currentOffice->office(),
                $request->validated('client_ids'),
                $request->validated('calendar_year'),
                (bool) ($request->validated('include_full_receipt') ?? false),
                $request->user()?->id,
            );
        } catch (HttpException $error) {
            return response()->json(['message' => $error->getMessage()], $error->getStatusCode());
        }

        return response()->json([
            'data' => $runs,
            'enqueued_count' => count($runs),
        ], 201);
    }

    private function client(int $officeId, int $clientId): ?Client
    {
        return Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $officeId)
            ->whereKey($clientId)
            ->first();
    }

    private function clientNotFound(): JsonResponse
    {
        return response()->json([
            'message' => 'Cliente não encontrado no escritório atual.',
            'code' => 'CLIENT_NOT_FOUND',
        ], 404);
    }

    private function assertModuleEnabled(): void
    {
        $office = $this->currentOffice->office();
        if (! FeatureFlags::isModuleEnabled('simples_mei', $office->id)
            && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo simples_mei desabilitado.');
        }
    }
}
