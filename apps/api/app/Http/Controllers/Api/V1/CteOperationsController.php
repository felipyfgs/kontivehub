<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CaptureChannel;
use App\Enums\CteCoverageStatus;
use App\Enums\QuarantineResolutionStatus;
use App\Http\Controllers\Controller;
use App\Jobs\RepairKnownCteNsuJob;
use App\Models\ChannelSyncCursor;
use App\Models\Client;
use App\Models\CteCoverageSnapshot;
use App\Models\FiscalDocumentQuarantine;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeFiscalIdentity;
use App\Services\Sefaz\CteCoverageService;
use App\Services\Sefaz\CteOperationsMetrics;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/** APIs CT-e somente com metadados sanitizados e tenant derivado da sessão. */
class CteOperationsController extends Controller
{
    public function onboarding(CurrentOffice $currentOffice): JsonResponse
    {
        $officeId = $currentOffice->office()->id;
        $identity = OfficeFiscalIdentity::query()
            ->where('office_id', $officeId)
            ->with(['credentials' => fn ($query) => $query->orderByDesc('id')])
            ->orderByDesc('id')
            ->first();
        $credential = $identity?->credentials->first();

        return response()->json(['data' => [
            'office_cnpj' => $identity?->cnpj,
            'identity' => $identity?->toPublicArray(),
            'credential' => $credential?->toPublicArray(),
            'enabled' => (bool) config('sefaz.cte_autxml.enabled', false),
            'instructions' => [
                'include_before_authorization' => true,
                'not_retroactive' => true,
                'message' => 'Inclua o CNPJ completo do escritório em autXML antes de autorizar o CT-e.',
                'issuer_fallback' => 'Sem autXML, use XML/ZIP ou EMITTER_PUSH do XML autorizado.',
            ],
        ]]);
    }

    public function health(CurrentOffice $currentOffice, CteOperationsMetrics $metrics): JsonResponse
    {
        $officeId = $currentOffice->office()->id;
        $clientStreams = ChannelSyncCursor::query()
            ->where('office_id', $officeId)
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
        $officeStreams = OfficeDistributionCursor::query()
            ->where('office_id', $officeId)
            ->where('channel', CaptureChannel::CteAutXmlDistDfe->value)
            ->orderBy('id')
            ->get()
            ->map(fn (OfficeDistributionCursor $cursor) => $cursor->toPublicArray())
            ->values();

        return response()->json(['data' => [
            'channels' => [
                CaptureChannel::CteDistDfe->value => $clientStreams,
                CaptureChannel::CteAutXmlDistDfe->value => $officeStreams,
            ],
            'summary' => [
                'client_streams' => $clientStreams->count(),
                'office_streams' => $officeStreams->count(),
                'blocked' => $clientStreams->where('status', 'BLOCKED')->count()
                    + $officeStreams->where('status', 'BLOCKED')->count(),
            ],
            'metrics' => $metrics->snapshot($officeId),
        ]]);
    }

    public function coverage(
        Request $request,
        CurrentOffice $currentOffice,
        CteCoverageService $coverage,
    ): JsonResponse {
        $data = $request->validate([
            'period' => ['nullable', 'date_format:Y-m'],
            'client_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string'],
        ]);
        $officeId = $currentOffice->office()->id;
        $period = $data['period'] ?? now()->format('Y-m');
        $clients = Client::query()
            ->where('office_id', $officeId)
            ->when(isset($data['client_id']), fn ($query) => $query->whereKey((int) $data['client_id']))
            ->orderBy('id')
            ->limit(200)
            ->get();

        foreach ($clients as $client) {
            $coverage->recompute($officeId, $client->id, $period);
        }

        $snapshots = CteCoverageSnapshot::query()
            ->where('office_id', $officeId)
            ->where('period', $period)
            ->when(isset($data['client_id']), fn ($query) => $query->where('client_id', (int) $data['client_id']))
            ->when(isset($data['status']), fn ($query) => $query->where('status', strtoupper((string) $data['status'])))
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

    public function pending(CurrentOffice $currentOffice): JsonResponse
    {
        $items = FiscalDocumentQuarantine::query()
            ->where('office_id', $currentOffice->office()->id)
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

    public function repairKnownNsu(Request $request, CurrentOffice $currentOffice): JsonResponse
    {
        abort_unless(in_array($currentOffice->role()?->value, ['ADMIN', 'OPERATOR'], true), 403);
        $data = $request->validate([
            'cursor_id' => ['required', 'integer'],
            'nsu' => ['required', 'integer', 'min:1'],
        ]);
        $cursor = ChannelSyncCursor::query()
            ->where('office_id', $currentOffice->office()->id)
            ->where('channel', CaptureChannel::CteDistDfe->value)
            ->find((int) $data['cursor_id']);
        if ($cursor === null) {
            abort(404);
        }
        if ($cursor->status->value === 'BLOCKED' || ($cursor->next_sync_at?->isFuture() ?? false)) {
            return response()->json([
                'message' => 'Reparo recusado durante circuito ou quiet period.',
            ], 422);
        }

        $correlationId = (string) Str::uuid();
        RepairKnownCteNsuJob::dispatch($cursor->id, (int) $data['nsu'], $correlationId);

        return response()->json(['data' => [
            'queued' => true,
            'cursor_id' => $cursor->id,
            'nsu' => (int) $data['nsu'],
            'correlation_id' => $correlationId,
            'cursor_last_nsu' => $cursor->last_nsu,
        ]], 202);
    }
}
