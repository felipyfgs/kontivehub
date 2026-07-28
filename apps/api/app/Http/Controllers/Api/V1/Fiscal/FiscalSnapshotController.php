<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\Monitoring\DownloadFiscalEvidenceRequest;
use App\Http\Requests\Fiscal\Monitoring\ListFiscalFindingsRequest;
use App\Http\Requests\Fiscal\Monitoring\ListFiscalPendingItemsRequest;
use App\Http\Requests\Fiscal\Monitoring\ListFiscalSnapshotsRequest;
use App\Http\Requests\Fiscal\Monitoring\ViewFiscalMonitoringRequest;
use App\Http\Resources\Fiscal\FiscalFindingPageResource;
use App\Http\Resources\Fiscal\FiscalPendingItemPageResource;
use App\Http\Resources\Fiscal\FiscalSnapshotPaginatorResource;
use App\Http\Resources\Fiscal\FiscalSnapshotResource;
use App\Services\FiscalMonitoring\FiscalEvidenceStore;
use App\Services\FiscalMonitoring\FiscalQueryService;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class FiscalSnapshotController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly FiscalQueryService $queries,
        private readonly FiscalEvidenceStore $evidenceStore,
    ) {}

    public function index(
        ListFiscalSnapshotsRequest $request,
    ): FiscalSnapshotPaginatorResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new FiscalSnapshotPaginatorResource(
            $this->queries->snapshots(
                $tenant,
                $filters->perPage,
                $filters->clientId,
                $filters->currentOnly,
            ),
        );
    }

    public function show(
        ViewFiscalMonitoringRequest $request,
        int $snapshot,
    ): JsonResponse|FiscalSnapshotResource {
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->snapshot($tenant, $snapshot);
        if ($model === null) {
            return response()->json(['message' => 'Snapshot não encontrado.'], 404);
        }

        return new FiscalSnapshotResource($model);
    }

    /**
     * Download autorizado de evidência — stream sem path interno/URL permanente.
     */
    public function downloadEvidence(
        DownloadFiscalEvidenceRequest $request,
        int $evidence,
    ): StreamedResponse|JsonResponse {
        $tenant = $this->currentTenant->tenant();
        $artifact = $request->artifact();

        try {
            $bytes = $this->evidenceStore->readAuthorized($artifact, (int) $tenant->id);
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

    public function findings(
        ListFiscalFindingsRequest $request,
    ): FiscalFindingPageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new FiscalFindingPageResource(
            $this->queries->findings(
                $tenant,
                $filters->perPage,
                $filters->clientId,
                $filters->activeOnly,
            ),
        );
    }

    public function pending(
        ListFiscalPendingItemsRequest $request,
    ): FiscalPendingItemPageResource {
        $tenant = $this->currentTenant->tenant();
        $filters = $request->filters();

        return new FiscalPendingItemPageResource(
            $this->queries->pendingItems(
                $tenant,
                $filters->perPage,
                $filters->clientId,
                $filters->status,
            ),
        );
    }
}
