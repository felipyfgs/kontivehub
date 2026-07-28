<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CaptureChannel;
use App\Enums\CteCoverageStatus;
use App\Enums\QuarantineResolutionStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sefaz\ListCteCoverageRequest;
use App\Http\Requests\Sefaz\RepairKnownCteNsuRequest;
use App\Http\Resources\TenantCredentialResource;
use App\Http\Resources\TenantFiscalIdentityResource;
use App\Jobs\RepairKnownCteNsuJob;
use App\Models\ChannelSyncCursor;
use App\Models\Client;
use App\Models\CteCoverageSnapshot;
use App\Models\FiscalDocumentQuarantine;
use App\Models\TenantDistributionCursor;
use App\Models\TenantFiscalIdentity;
use App\Services\Sefaz\CteCoverageService;
use App\Services\Sefaz\CteOperationsMetrics;
use App\Support\CurrentTenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/** APIs CT-e somente com metadados sanitizados e tenant derivado da sessão. */
class CteOperationsController extends Controller
{
    public function onboarding(CurrentTenant $currentTenant): JsonResponse
    {
        $tenantId = $currentTenant->tenant()->id;
        $identity = TenantFiscalIdentity::query()
            ->where('tenant_id', $tenantId)
            ->with(['credentials' => fn ($query) => $query->orderByDesc('id')])
            ->orderByDesc('id')
            ->first();
        $credential = $identity?->credentials->first();

        return response()->json(['data' => [
            'tenant_cnpj' => $identity?->cnpj,
            'identity' => $identity === null
                ? null
                : TenantFiscalIdentityResource::make($identity)->resolve(),
            'credential' => $credential === null
                ? null
                : TenantCredentialResource::make($credential)->resolve(),
            'enabled' => (bool) config('sefaz.cte_autxml.enabled', false),
            'instructions' => [
                'include_before_authorization' => true,
                'not_retroactive' => true,
                'message' => 'Inclua o CNPJ completo do escritório em autXML antes de autorizar o CT-e.',
                'issuer_fallback' => 'Sem autXML, use XML/ZIP ou EMITTER_PUSH do XML autorizado.',
            ],
        ]]);
    }

    public function health(CurrentTenant $currentTenant, CteOperationsMetrics $metrics): JsonResponse
    {
        $tenantId = $currentTenant->tenant()->id;
        $clientStreams = ChannelSyncCursor::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', CaptureChannel::CteDistDfe->value)
            ->with('establishment.client:id,legal_name,display_name')
            ->orderBy('id')
            ->get()
            ->map(fn (ChannelSyncCursor $cursor) => [
                'id' => $cursor->id,
                'channel' => CaptureChannel::CteDistDfe->value,
                'establishment_id' => $cursor->establishment_id,
                'client_id' => $cursor->establishment?->client_id,
                'client_name' => $cursor->establishment?->client?->displayLabel(),
                'status' => $cursor->status->value,
                'last_nsu' => $cursor->last_nsu,
                'max_nsu_seen' => $cursor->max_nsu_seen,
                'last_cstat' => $cursor->last_cstat,
                'next_sync_at' => $cursor->next_sync_at?->toIso8601String(),
                'last_success_at' => $cursor->last_success_at?->toIso8601String(),
                'retry_allowed' => $cursor->status->value !== 'BLOCKED'
                    && ! ($cursor->next_sync_at?->isFuture() ?? false),
            ])->values();
        $tenantStreams = TenantDistributionCursor::query()
            ->where('tenant_id', $tenantId)
            ->where('channel', CaptureChannel::CteAutXmlDistDfe->value)
            ->orderBy('id')
            ->get()
            ->map(fn (TenantDistributionCursor $cursor) => $cursor->toPublicArray())
            ->values();

        return response()->json(['data' => [
            'channels' => [
                CaptureChannel::CteDistDfe->value => $clientStreams,
                CaptureChannel::CteAutXmlDistDfe->value => $tenantStreams,
            ],
            'summary' => [
                'client_streams' => $clientStreams->count(),
                'tenant_streams' => $tenantStreams->count(),
                'blocked' => $clientStreams->where('status', 'BLOCKED')->count()
                    + $tenantStreams->where('status', 'BLOCKED')->count(),
            ],
            'metrics' => $metrics->snapshot($tenantId),
        ]]);
    }

    public function coverage(
        ListCteCoverageRequest $request,
        CurrentTenant $currentTenant,
        CteCoverageService $coverage,
    ): JsonResponse {
        $tenantId = $currentTenant->tenant()->id;
        $period = $request->period();
        $clientId = $request->clientId();
        $status = $request->status();
        $clients = Client::query()
            ->where('tenant_id', $tenantId)
            ->when($clientId !== null, fn ($query) => $query->whereKey($clientId))
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($clients as $client) {
            $coverage->recompute($tenantId, $client->id, $period);
        }

        $snapshots = CteCoverageSnapshot::query()
            ->where('tenant_id', $tenantId)
            ->where('period', $period)
            ->when($clientId !== null, fn ($query) => $query->where('client_id', $clientId))
            ->when($status !== null, fn ($query) => $query->where('status', strtoupper($status)))
            ->with('client:id,legal_name,display_name')
            ->orderBy('client_id')
            ->get()
            ->map(fn (CteCoverageSnapshot $snapshot) => [
                'client_id' => $snapshot->client_id,
                'client_name' => $snapshot->client?->displayLabel(),
                'period' => $snapshot->period,
                'status' => $snapshot->status->value,
                'status_label' => $snapshot->status->label(),
                'documents_count' => $snapshot->documents_count,
                'original_count' => $snapshot->original_count,
                'autxml_redacted_count' => $snapshot->autxml_redacted_count,
                'pending_import_count' => $snapshot->pending_import_count,
                'computed_at' => $snapshot->computed_at?->toIso8601String(),
            ])->values();

        return response()->json(['data' => $snapshots, 'meta' => [
            'period' => $period,
            'statuses' => array_map(
                fn (CteCoverageStatus $status) => ['value' => $status->value, 'label' => $status->label()],
                CteCoverageStatus::cases(),
            ),
        ]]);
    }

    public function pending(CurrentTenant $currentTenant): JsonResponse
    {
        $items = FiscalDocumentQuarantine::query()
            ->where('tenant_id', $currentTenant->tenant()->id)
            ->where('resolution_status', QuarantineResolutionStatus::Open->value)
            ->where(function ($query): void {
                $query->where('model', '57')->orWhere('schema_family', 'like', '%CTe%');
            })
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (FiscalDocumentQuarantine $item) => $item->toPublicArray())
            ->values();

        return response()->json(['data' => $items]);
    }

    public function repairKnownNsu(
        RepairKnownCteNsuRequest $request,
        CurrentTenant $currentTenant,
    ): JsonResponse {
        $cursor = ChannelSyncCursor::query()
            ->where('tenant_id', $currentTenant->tenant()->id)
            ->where('channel', CaptureChannel::CteDistDfe->value)
            ->find($request->cursorId());
        if ($cursor === null) {
            abort(404);
        }
        if ($cursor->status->value === 'BLOCKED' || ($cursor->next_sync_at?->isFuture() ?? false)) {
            return response()->json([
                'message' => 'Reparo recusado durante circuito ou quiet period.',
            ], 422);
        }

        $correlationId = (string) Str::uuid();
        RepairKnownCteNsuJob::dispatch($cursor->id, $request->nsu(), $correlationId);

        return response()->json(['data' => [
            'queued' => true,
            'cursor_id' => $cursor->id,
            'nsu' => $request->nsu(),
            'correlation_id' => $correlationId,
            'cursor_last_nsu' => $cursor->last_nsu,
        ]], 202);
    }
}
