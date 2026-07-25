<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\Guides\PagtowebPaymentListQueryService;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;
use RuntimeException;

final class PagtowebPaymentListController extends Controller
{
    public function __construct(private readonly CurrentOffice $currentOffice, private readonly PagtowebPaymentListQueryService $queries, private readonly TenantAuthorization $authorization) {}

    public function history(Request $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $model = $this->client($client);
        if ($model === null) {
            return $this->notFound();
        }
        $this->read($request, $model);
        $page = max(1, (int) $request->integer('page', 1));
        $perPage = min(100, max(1, (int) $request->integer('per_page', 50)));
        try {
            return response()->json(['data' => $this->queries->history($this->currentOffice->office(), $model, $page, $perPage)]);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Histórico de pagamentos indisponível.', 'code' => 'HISTORY_ERROR'], 422);
        }
    }

    public function consult(Request $request, int $client): JsonResponse
    {
        $this->enabled();
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $request->validate([
            'confirmed' => ['required', 'accepted'], 'filters' => ['required', 'array'],
            'filters.intervalo_data_arrecadacao' => ['sometimes', 'array'], 'filters.intervalo_data_arrecadacao.data_inicial' => ['required_with:filters.intervalo_data_arrecadacao', 'string'], 'filters.intervalo_data_arrecadacao.data_final' => ['required_with:filters.intervalo_data_arrecadacao', 'string'],
            'filters.intervalo_valor_total_documento' => ['sometimes', 'array'], 'filters.intervalo_valor_total_documento.valor_inicial' => ['required_with:filters.intervalo_valor_total_documento', 'numeric'], 'filters.intervalo_valor_total_documento.valor_final' => ['required_with:filters.intervalo_valor_total_documento', 'numeric'],
            'filters.codigo_receita_lista' => ['sometimes', 'array'], 'filters.codigo_receita_lista.*' => ['string'], 'filters.codigo_tipo_documento_lista' => ['sometimes', 'array'], 'filters.codigo_tipo_documento_lista.*' => ['string'],
            'filters.numero_documento_lista' => ['sometimes', 'array', 'min:1', 'max:100'], 'filters.numero_documento_lista.*' => ['string', 'regex:/^[0-9]{1,17}$/'],
            'filters.page' => ['sometimes', 'integer', 'min:1'], 'filters.per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);
        $model = $this->client($client);
        if ($model === null) {
            return $this->notFound();
        }
        $this->write($request, $model);
        try {
            $run = $this->queries->enqueueManualConsult($this->currentOffice->office(), $model, (array) $request->input('filters', []), $request->user()?->id);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => 'INVALID_PAYMENT_LIST_FILTERS'], 422);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Consulta de pagamentos indisponível.', 'code' => 'PAGTOWEB_UNAVAILABLE'], 422);
        }

        return response()->json(['data' => $run], 201);
    }

    private function client(int $id): ?Client
    {
        return Client::query()->withoutGlobalScopes()->where('office_id', $this->currentOffice->office()->id)->whereKey($id)->first();
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Cliente não encontrado no escritório atual.', 'code' => 'CLIENT_NOT_FOUND'], 404);
    }

    private function read(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView, $client)) {
            abort(403, 'Sem permissão para consultar o monitoramento fiscal.');
        }
    }

    private function write(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger, $client)) {
            abort(403, 'Sem permissão de sincronização.');
        }
    }

    private function enabled(): void
    {
        $office = $this->currentOffice->office();
        if (! FeatureFlags::isModuleEnabled('guias', $office->id) && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo guias desabilitado.');
        }
    }

    private function rejectClientOfficeId(Request $request): ?JsonResponse
    {
        $supplied = $request->attributes->get(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED) === true || $this->hasOfficeId($request->query->all()) || $this->hasOfficeId($request->request->all()) || ($request->isJson() && $request->json() !== null && $this->hasOfficeId($request->json()->all()));

        return $supplied ? response()->json(['message' => 'office_id não é aceito; o escritório é obtido do contexto autenticado.', 'code' => 'CLIENT_OFFICE_ID_REJECTED'], 422) : null;
    }

    /** @param array<array-key,mixed> $values */
    private function hasOfficeId(array $values): bool
    {
        foreach ($values as $key => $value) {
            if (is_string($key) && strtolower($key) === 'office_id') {
                return true;
            } if (is_array($value) && $this->hasOfficeId($value)) {
                return true;
            }
        }

        return false;
    }
}
