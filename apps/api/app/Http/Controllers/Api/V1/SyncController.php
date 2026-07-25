<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\SyncCursorStatus;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\Establishment;
use App\Models\SyncCursor;
use App\Models\SyncRun;
use App\Models\User;
use App\Services\Adn\SyncDispatchService;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Clients\CaptureEligibilityService;
use App\Services\Sefaz\ChannelSyncCursorService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SyncController extends Controller
{
    public function history(Request $request): JsonResponse
    {
        $query = SyncRun::query()->orderByDesc('id');
        if ($cursor = $request->string('cursor')->toString()) {
            $query->where('id', '<', (int) $cursor);
        }
        $limit = min(max((int) $request->input('limit', 25), 1), 100);
        $items = $query->limit($limit + 1)->get();
        $hasMore = $items->count() > $limit;
        if ($hasMore) {
            $items = $items->take($limit);
        }

        return response()->json([
            'data' => $items->values(),
            'meta' => ['next_cursor' => $hasMore ? (string) $items->last()->id : null],
        ]);
    }

    public function trigger(
        Request $request,
        TenantAuthorization $authorization,
        SyncDispatchService $dispatcher,
        ChannelSyncCursorService $channelCursors,
        AuditLogger $audit,
        CaptureEligibilityService $eligibility,
    ): JsonResponse {
        $data = $request->validate([
            'establishment_id' => ['required', 'integer'],
        ]);

        $establishment = Establishment::query()->with('client')->findOrFail($data['establishment_id']);
        $actor = $request->user();
        if (! $actor instanceof User
            || ! $authorization->allows($actor, TenantPermission::FiscalSyncTrigger, $establishment)) {
            abort(403);
        }

        $env = (string) config('adn.environment', 'restricted_production');

        $cursor = SyncCursor::query()->firstOrCreate(
            [
                'establishment_id' => $establishment->id,
                'environment' => $env,
            ],
            [
                'office_id' => $establishment->office_id,
                'last_nsu' => 0,
                'status' => SyncCursorStatus::Idle,
                'next_sync_at' => now(),
            ]
        );

        $eval = $eligibility->evaluate($establishment, $cursor);
        if (! $eval['eligible']) {
            $audit->record('sync.trigger', 'FAILED', $cursor, [
                'establishment_id' => $establishment->id,
                'reason' => 'ineligible',
                'reasons_codes' => $eval['reasons_codes'],
            ]);

            return response()->json([
                'message' => $eval['reasons'][0] ?? 'Estabelecimento inelegível para captura.',
                'data' => [
                    'eligible' => false,
                    'reasons' => $eval['reasons'],
                    'reasons_codes' => $eval['reasons_codes'],
                    'last_nsu' => $cursor->last_nsu,
                ],
            ], 422);
        }

        $dispatched = $dispatcher->claimAndDispatch(
            $cursor->id,
            'MANUAL',
            auth()->id(),
        );

        // NF-e / CT-e DistDFe: canais independentes do ADN (NSU e cursor próprios).
        $sefazChannels = $channelCursors->dispatchManualForEstablishment($establishment);
        $sefazDispatched = collect($sefazChannels)->contains(fn (array $row) => $row['dispatched']);

        if (! $dispatched && ! $sefazDispatched) {
            $audit->record('sync.trigger', 'FAILED', $cursor, [
                'establishment_id' => $establishment->id,
                'reason' => 'already_running',
            ]);

            return response()->json(['message' => 'Sincronização já enfileirada ou em execução.'], 409);
        }

        $audit->record('sync.trigger', 'SUCCESS', $cursor, [
            'establishment_id' => $establishment->id,
            'last_nsu' => $cursor->last_nsu,
            'adn_dispatched' => $dispatched,
            'sefaz_channels' => $sefazChannels,
        ]);

        return response()->json([
            'data' => [
                'sync_cursor_id' => $cursor->id,
                'adn_dispatched' => $dispatched,
                'sefaz_channels' => $sefazChannels,
            ],
        ], 202);
    }
}
