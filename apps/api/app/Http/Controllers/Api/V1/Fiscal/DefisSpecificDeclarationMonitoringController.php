<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Models\Client;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Fiscal\SimplesMei\DefisSpecificDeclarationMonitoringQueryService;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DefisSpecificDeclarationMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly DefisSpecificDeclarationMonitoringQueryService $queries,
        private readonly TenantAuthorization $authorization,
        private readonly FiscalEvidenceStore $evidenceStore,
    ) {}

    public function history(Request $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $model = $this->findClient($client);
        if ($model === null) {
            return $this->notFound();
        }
        $this->can($request, $model, TenantPermission::FiscalMonitoringView);
        $validated = $request->validate(['reference_id' => ['sometimes', 'integer', 'min:1']]);

        return response()->json(['data' => $this->queries->history($this->currentOffice->office(), $model, isset($validated['reference_id']) ? (int) $validated['reference_id'] : null)]);
    }

    public function consult(Request $request, int $client): JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $this->assertEnabled();
        $validated = $request->validate(['confirmed' => ['required', 'accepted'], 'reference_id' => ['required', 'integer', 'min:1']]);
        $model = $this->findClient($client);
        if ($model === null) {
            return $this->notFound();
        }
        $this->can($request, $model, TenantPermission::FiscalSyncTrigger);

        return response()->json(['data' => $this->queries->enqueueManualConsult($this->currentOffice->office(), $model, (int) $validated['reference_id'], $request->user()?->id)], 201);
    }

    public function download(Request $request, int $artifact): Response|JsonResponse
    {
        if ($rejection = $this->rejectClientOfficeId($request)) {
            return $rejection;
        }
        $item = $this->queries->findArtifact($this->currentOffice->office(), $artifact);
        if ($item === null || ($client = $this->findClient((int) $item->client_id)) === null) {
            return $this->notFound();
        }
        $this->can($request, $client, TenantPermission::FiscalMonitoringView);
        $item->loadMissing('evidenceArtifact');
        if ($item->evidenceArtifact === null) {
            return $this->notFound();
        }
        try {
            $bytes = $this->evidenceStore->readAuthorized($item->evidenceArtifact, $this->currentOffice->office()->id);
        } catch (\Throwable) {
            return $this->notFound();
        }

        return response($bytes, 200, [
            'Content-Type' => $item->content_type ?: 'application/pdf',
            'Content-Disposition' => 'attachment; filename="defis-'.$item->id.'.pdf"',
            'X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, no-store, max-age=0', 'Pragma' => 'no-cache',
        ]);
    }

    private function findClient(int $client): ?Client
    {
        return Client::query()->withoutGlobalScopes()->where('office_id', $this->currentOffice->office()->id)->whereKey($client)->first();
    }

    private function can(Request $request, Client $client, TenantPermission $permission): void
    {
        if (! $request->user() instanceof User || ! $this->authorization->allows($request->user(), $permission, $client)) {
            abort(403, 'Sem permissão para monitoramento fiscal.');
        }
    }

    private function assertEnabled(): void
    {
        $office = $this->currentOffice->office();
        if (! FeatureFlags::isModuleEnabled('simples_mei', $office->id) && ! (bool) config('fiscal_monitoring.enabled', false)) {
            abort(403, 'Módulo simples_mei desabilitado.');
        }
    }

    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Artefato ou cliente não encontrado no escritório atual.', 'code' => 'NOT_FOUND'], 404);
    }

    private function rejectClientOfficeId(Request $request): ?JsonResponse
    {
        $values = [$request->query->all(), $request->request->all(), $request->isJson() && $request->json() !== null ? $request->json()->all() : []];
        $has = $request->attributes->get(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED) === true;
        foreach ($values as $value) {
            $has = $has || $this->hasOfficeId($value);
        }

        return $has ? response()->json(['message' => 'office_id não é aceito; o escritório é obtido do contexto autenticado.', 'code' => 'CLIENT_OFFICE_ID_REJECTED'], 422) : null;
    }

    /** @param array<array-key,mixed> $values */
    private function hasOfficeId(array $values): bool
    {
        foreach ($values as $key => $value) {
            if ((is_string($key) && strtolower($key) === 'office_id') || (is_array($value) && $this->hasOfficeId($value))) {
                return true;
            }
        }

        return false;
    }
}
