<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Services\FiscalMonitoring\FiscalQueryService;
use App\Support\CurrentOffice;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FiscalSnapshotController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly FiscalQueryService $queries,
        private readonly FiscalEvidenceStore $evidenceStore,
        private readonly TenantAuthorization $authorization,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();

        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');
        $currentOnly = filter_var($request->query('current_only', true), FILTER_VALIDATE_BOOL);

        $page = $this->queries->snapshots(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
            $currentOnly,
        );
        $page->getCollection()->transform(fn ($s) => $s->toPublicArray());

        return response()->json($page);
    }

    public function show(int $snapshot): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $model = $this->queries->snapshot($office, $snapshot);
        if ($model === null) {
            return response()->json(['message' => 'Snapshot não encontrado.'], 404);
        }

        return response()->json(['data' => $model->toPublicArray()]);
    }

    /**
     * Download autorizado de evidência — stream sem path interno/URL permanente.
     */
    public function downloadEvidence(int $evidence): StreamedResponse|JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $artifact = $this->queries->evidence($office, $evidence);
        if ($artifact === null) {
            return response()->json(['message' => 'Evidência não encontrada.'], 404);
        }
        $this->assertCanRead($artifact);

        try {
            $bytes = $this->evidenceStore->readAuthorized($artifact, (int) $office->id);
        } catch (RuntimeException) {
            // Não vazar existência, vault_object_id, hash ou path.
            return response()->json(['message' => 'Evidência não encontrada.'], 404);
        }

        $contentType = is_string($artifact->content_type) && $artifact->content_type !== ''
            ? $artifact->content_type
            : 'application/octet-stream';
        $extension = str_contains(strtolower($contentType), 'pdf') ? 'pdf' : 'bin';
        $filename = 'fiscal-evidence-'.$artifact->id.'.'.$extension;

        return response()->streamDownload(function () use ($bytes): void {
            echo $bytes;
        }, $filename, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'no-store',
        ]);
    }

    public function findings(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');
        $activeOnly = filter_var($request->query('active_only', true), FILTER_VALIDATE_BOOL);

        $page = $this->queries->findings(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
            $activeOnly,
        );
        $page->getCollection()->transform(fn ($f) => $f->toPublicArray());

        return response()->json($page);
    }

    public function pending(Request $request): JsonResponse
    {
        $this->assertCanRead();
        $office = $this->currentOffice->office();
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $clientId = $request->query('client_id');
        $status = $request->query('status', 'OPEN');

        $page = $this->queries->pendingItems(
            $office,
            $perPage,
            is_numeric($clientId) ? (int) $clientId : null,
            is_string($status) ? $status : 'OPEN',
        );
        $page->getCollection()->transform(fn ($p) => $p->toPublicArray());

        return response()->json($page);
    }

    private function assertCanRead(?Model $target = null): void
    {
        $actor = auth()->user();
        // Não exigir realMembership(): em platform_privileged sem vínculo dual
        // TenantAuthorization já autoriza PLATFORM_ADMIN (e barra o restante).
        if (! $actor instanceof User
            || ! $this->authorization->allows(
                $actor,
                TenantPermission::FiscalMonitoringView,
                $target,
            )
        ) {
            abort(403, 'Sem permissão para monitoramento fiscal.');
        }
    }
}
