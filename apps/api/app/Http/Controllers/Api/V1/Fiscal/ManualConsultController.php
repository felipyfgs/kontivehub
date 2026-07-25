<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\ManualConsult\ManualConsultExecutionService;
use App\Services\Fiscal\ManualConsult\ManualConsultInventoryService;
use App\Services\Fiscal\ManualConsult\ManualConsultNotReadyException;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Explorador de consultas manuais somente-leitura (inventário GET + execução POST).
 */
final class ManualConsultController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly ManualConsultInventoryService $inventory,
        private readonly ManualConsultExecutionService $execution,
        private readonly TenantAuthorization $authorization,
    ) {}

    /**
     * GET — inventário local; nunca dispara SERPRO.
     */
    public function index(Request $request): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->currentOffice->office();
        $validated = $request->validate([
            'client_id' => ['sometimes', 'nullable', 'integer'],
            'surface_key' => ['sometimes', 'nullable', 'string', 'max:80'],
            'module_key' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $client = null;
        if (isset($validated['client_id']) && $validated['client_id'] !== null) {
            $client = $this->findClient((int) $office->id, (int) $validated['client_id']);
            if ($client === null) {
                return $this->clientNotFound();
            }
        }
        $this->assertCanRead($request, $client);

        $data = $this->inventory->inventory(
            office: $office,
            client: $client,
            surfaceKey: isset($validated['surface_key']) ? (string) $validated['surface_key'] : null,
            moduleKey: isset($validated['module_key']) ? (string) $validated['module_key'] : null,
            actor: $request->user() instanceof User ? $request->user() : null,
        );

        return response()->json(['data' => $data]);
    }

    /**
     * POST — consulta confirmada; despacha adapter existente.
     */
    public function store(Request $request): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }

        $office = $this->currentOffice->office();
        $validated = $request->validate([
            'action_id' => ['required', 'string', 'max:160'],
            'client_id' => ['required', 'integer'],
            'confirmed' => ['required', 'accepted'],
            'params' => ['sometimes', 'array'],
        ]);

        $client = $this->findClient((int) $office->id, (int) $validated['client_id']);
        if ($client === null) {
            return $this->clientNotFound();
        }
        $this->assertCanWrite($request, $client);

        try {
            $payload = $this->execution->execute(
                office: $office,
                client: $client,
                actionId: (string) $validated['action_id'],
                params: (array) ($validated['params'] ?? []),
                confirmed: true,
                actorUserId: $request->user()?->id,
            );
        } catch (ManualConsultNotReadyException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => $e->eligibility->value,
            ], 422);
        } catch (ValidationException $e) {
            throw $e;
        } catch (HttpException $e) {
            return response()->json([
                'message' => $e->getMessage(),
                'code' => 'MANUAL_CONSULT_REJECTED',
            ], $e->getStatusCode());
        } catch (\Throwable $e) {
            Log::warning('manual_consult.execution_error', [
                'message' => $e->getMessage(),
                'exception' => $e::class,
            ]);

            return response()->json([
                'message' => 'Consulta manual indisponível.',
                'code' => 'MANUAL_CONSULT_ERROR',
            ], 500);
        }

        $status = ($payload['async'] ?? false) ? 202 : 201;

        return response()->json(['data' => $payload], $status);
    }

    private function findClient(int $officeId, int $clientId): ?Client
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

    private function rejectClientOfficeId(Request $request): ?JsonResponse
    {
        $suppliedAtTopLevel = $request->attributes->get(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED) === true;
        $suppliedNested = $this->containsOfficeIdKey($request->query->all())
            || $this->containsOfficeIdKey($request->request->all())
            || ($request->isJson() && $request->json() !== null && $this->containsOfficeIdKey($request->json()->all()));

        if (! $suppliedAtTopLevel && ! $suppliedNested) {
            return null;
        }

        return response()->json([
            'message' => 'office_id não é aceito; o escritório é obtido do contexto autenticado.',
            'code' => 'CLIENT_OFFICE_ID_REJECTED',
        ], 422);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function containsOfficeIdKey(array $payload): bool
    {
        foreach ($payload as $key => $value) {
            if (is_string($key) && strcasecmp($key, 'office_id') === 0) {
                return true;
            }
            if (is_array($value) && $this->containsOfficeIdKey($value)) {
                return true;
            }
        }

        return false;
    }

    private function assertCanRead(Request $request, ?Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView, $client)
        ) {
            abort(403, 'Sem permissão para consultar o monitoramento fiscal.');
        }
    }

    private function assertCanWrite(Request $request, Client $client): void
    {
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalSyncTrigger, $client)
        ) {
            abort(403, 'Sem permissão de sincronização.');
        }
    }
}
