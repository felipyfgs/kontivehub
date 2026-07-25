<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Models\Client;
use App\Models\FiscalPnrRenunciation;
use App\Models\Office;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Integra\Registrations\PnrRenunciationReadService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** APIs de leitura manual PNR; não inclui solicitação de renúncia. */
final class PnrRenunciationController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function index(Request $request, int $clientId): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->currentOffice->office();
        abort_if($office === null, 403);
        $client = $this->client($office->id, $clientId);
        $this->assertCanRead($request, $client);

        $rows = FiscalPnrRenunciation::query()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->latest('refreshed_at')
            ->latest('id')
            ->get();

        return response()->json(['data' => [
            'client_id' => $client->id,
            'renunciations' => $rows->map(static fn (FiscalPnrRenunciation $row) => $row->toPublicArray())->values(),
        ]]);
    }

    public function history(Request $request, int $clientId, PnrRenunciationReadService $service): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->office();
        $client = $this->client($office->id, $clientId);
        $this->assertCanWrite($request, $client);
        $result = $service->history($office, $client, $request->validate([
            'dt_inicio' => ['nullable', 'date_format:Y-m-d'],
            'dt_fim' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:0'],
            'page_size' => ['nullable', 'integer', 'min:1', 'max:50'],
        ]), bin2hex(random_bytes(8)));

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    public function status(Request $request, int $clientId, PnrRenunciationReadService $service): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->office();
        $client = $this->client($office->id, $clientId);
        $this->assertCanWrite($request, $client);
        $data = $request->validate(['id_solicitacao' => ['required', 'string', 'max:120']]);
        $result = $service->status($office, $client, $data['id_solicitacao'], bin2hex(random_bytes(8)));

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    public function receipt(Request $request, int $clientId, PnrRenunciationReadService $service): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->office();
        $client = $this->client($office->id, $clientId);
        $this->assertCanWrite($request, $client);
        $data = $request->validate(['renunciation_id' => ['required', 'integer', 'min:1']]);
        $result = $service->receipt($office, $client, (int) $data['renunciation_id'], bin2hex(random_bytes(8)));

        return response()->json(['data' => $result], ($result['success'] ?? false) ? 202 : 422);
    }

    private function office(): Office
    {
        $office = $this->currentOffice->office();
        abort_if($office === null, 403);

        return $office;
    }

    private function assertCanRead(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView, $client)) {
            abort(403, 'Sem permissão para consultar o monitoramento fiscal.');
        }
    }

    private function assertCanWrite(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger, $client)) {
            abort(403, 'Sem permissão de sincronização.');
        }
    }

    private function rejectClientOfficeId(Request $request): ?JsonResponse
    {
        $topLevel = $request->attributes->get(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED) === true;
        $nested = $this->containsOfficeId($request->query->all())
            || $this->containsOfficeId($request->request->all())
            || ($request->isJson() && $request->json() !== null && $this->containsOfficeId($request->json()->all()));
        if (! $topLevel && ! $nested) {
            return null;
        }

        return response()->json([
            'message' => 'office_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_OFFICE_ID_REJECTED',
        ], 422);
    }

    /** @param array<mixed> $payload */
    private function containsOfficeId(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'office_id') === 0) {
                return true;
            }
            if (is_array($value) && $this->containsOfficeId($value)) {
                return true;
            }
        }

        return false;
    }

    private function client(int $officeId, int $clientId): Client
    {
        return Client::query()->where('office_id', $officeId)->whereKey($clientId)->firstOrFail();
    }
}
