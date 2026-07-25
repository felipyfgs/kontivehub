<?php

namespace App\Http\Controllers\Api\V1;

use App\Domain\Outbound\Competence;
use App\Domain\Outbound\OperationalSla;
use App\Enums\OfficeRole;
use App\Enums\OutboundRetrievalOrigin;
use App\Enums\OutboundUrgencyBand;
use App\Http\Controllers\Controller;
use App\Models\MaOutboundRetrievalRequest;
use App\Models\OutboundCapacitySnapshot;
use App\Services\Audit\AuditLogger;
use App\Services\Outbound\OutboundDeadlineSatisfactionService;
use App\Services\Outbound\OutboundMetrics;
use App\Services\Outbound\OutboundMonthlyExportService;
use App\Services\Outbound\OutboundMonthlyReadinessService;
use App\Services\Outbound\OutboundXmlCaptureCapacityPlanner;
use App\Support\CurrentOffice;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Visão de fechamento mensal / capacidade — tenancy do servidor.
 */
class OutboundDeadlineController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly OutboundMonthlyReadinessService $readiness,
        private readonly OutboundDeadlineSatisfactionService $satisfaction,
        private readonly OutboundXmlCaptureCapacityPlanner $capacity,
        private readonly OutboundMonthlyExportService $monthlyExport,
        private readonly OutboundMetrics $outboundMetrics,
        private readonly AuditLogger $audit,
    ) {}

    public function competenceSummary(Request $request): JsonResponse
    {
        $this->authorizeView();
        $officeId = (int) $this->currentOffice->id();
        $competence = (string) $request->query('competence', now()->format('Y-m'));

        try {
            Competence::fromString($competence);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $stats = $this->readiness->compute($officeId, $competence);
        $ready = $this->readiness->refresh($officeId, $competence);

        $bySource = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('competence', $competence)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereNotNull('capture_source')
            ->selectRaw('capture_source, count(*) as c')
            ->groupBy('capture_source')
            ->pluck('c', 'capture_source');

        return response()->json([
            'data' => [
                'competence' => $competence,
                'known_total' => $stats['known_total'],
                'captured_total' => $stats['captured_total'],
                'pending_total' => $stats['pending_total'],
                'by_band' => $stats['by_band'],
                'by_capture_source' => $bySource,
                'readiness' => $ready->toPublicArray(),
                'completeness_scope' => 'known_documents_only',
                'sla_note' => 'SLA operacional interno (dia 1) — não é prazo legal.',
            ],
        ]);
    }

    public function capacityForecast(Request $request): JsonResponse
    {
        $this->authorizeView();
        $officeId = (int) $this->currentOffice->id();
        $competence = (string) $request->query('competence', now()->format('Y-m'));

        try {
            $comp = Competence::fromString($competence);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $first = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('competence', $competence)
            ->where('svrs_transaction_count', 0)
            ->whereNotIn('urgency_band', [OutboundUrgencyBand::Captured->value])
            ->count();
        $second = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('competence', $competence)
            ->where('svrs_transaction_count', 1)
            ->whereNotIn('urgency_band', [OutboundUrgencyBand::Captured->value])
            ->count();

        $sla = OperationalSla::fromConfig($this->currentOffice->office()?->deadline_timezone);
        $deadlines = $sla->deadlinesFor($comp);
        $proj = $this->capacity->project(
            $comp,
            $first,
            $second,
            CarbonImmutable::now('UTC'),
            $deadlines['target_at'],
            $officeId,
        );

        $latestSnap = OutboundCapacitySnapshot::query()
            ->where('office_id', $officeId)
            ->where('competence', $competence)
            ->orderByDesc('id')
            ->first();

        return response()->json([
            'data' => [
                'competence' => $competence,
                'projection' => [
                    'demand_exchanges' => $proj['demand_exchanges'],
                    'safe_capacity_exchanges' => $proj['safe_capacity_exchanges'],
                    'nominal_capacity_exchanges' => $proj['nominal_capacity_exchanges'],
                    'slack_exchanges' => $proj['slack_exchanges'],
                    'at_risk' => $proj['at_risk'],
                    'items_capacity_at_risk' => $proj['items_capacity_at_risk'],
                    'safe_daily_exchanges' => $proj['safe_daily_exchanges'],
                    'auto_queue_fraction' => (float) config('outbound_deadline.auto_queue_capacity_fraction', 0.6),
                    'estimated_completion_at' => $proj['estimated_completion_at']?->toIso8601String(),
                    'target_at' => $deadlines['target_at']->toIso8601String(),
                    'due_at' => $deadlines['due_at']->toIso8601String(),
                ],
                'latest_snapshot' => $latestSnap?->toPublicArray(),
            ],
        ]);
    }

    public function pendingItems(Request $request): JsonResponse
    {
        $this->authorizeView();
        $officeId = (int) $this->currentOffice->id();
        $competence = $request->query('competence');
        $band = $request->query('urgency_band');
        $model = $request->query('model');
        $root = $request->query('root_cnpj');
        $clientId = $request->query('client_id');
        $source = $request->query('source');
        $perPage = min(100, max(1, (int) $request->query('per_page', 50)));
        $sort = match ($request->string('sort')->toString()) {
            'urgency_band' => 'urgency_band',
            'model' => 'model',
            'target_at' => 'target_at',
            'id' => 'id',
            default => 'due_at',
        };
        $direction = $request->string('direction')->lower()->toString() === 'desc' ? 'desc' : 'asc';

        $q = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('origin', OutboundRetrievalOrigin::SvrsPortalByKey)
            ->whereNotIn('urgency_band', [OutboundUrgencyBand::Captured->value]);

        if (is_string($competence) && $competence !== '') {
            $q->where('competence', $competence);
        }
        if (is_string($band) && $band !== '') {
            $q->where('urgency_band', strtoupper($band));
        }
        if (is_string($model) && $model !== '') {
            $m = strtoupper($model);
            if (in_array($m, ['55', 'NFE'], true)) {
                $q->where('model', '55');
            } elseif (in_array($m, ['65', 'NFCE'], true)) {
                $q->where('model', '65');
            }
        }
        if (is_string($root) && $root !== '') {
            $rootClean = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $root) ?? '');
            if ($rootClean !== '') {
                $q->where('root_cnpj', 'like', substr($rootClean, 0, 8).'%');
            }
        }
        if ($clientId !== null && $clientId !== '' && (int) $clientId > 0) {
            $cid = (int) $clientId;
            $q->whereHas('profile', fn ($p) => $p->where('client_id', $cid)->where('office_id', $officeId));
        }
        if (is_string($source) && $source !== '') {
            $q->where('capture_source', 'like', '%'.strtoupper($source).'%');
        }

        $q->orderBy($sort, $direction);
        if ($sort !== 'id') {
            $q->orderBy('id', $direction);
        }

        $paginator = $q->paginate($perPage);
        $items = collect($paginator->items())->map(function (MaOutboundRetrievalRequest $r) {
            $arr = $r->toPublicArray();
            $arr['due_at'] = $r->due_at?->toIso8601String();
            $arr['target_at'] = $r->target_at?->toIso8601String();
            $arr['urgency_band'] = $r->urgency_band?->value;
            $arr['root_cnpj'] = $r->root_cnpj;
            $arr['next_step'] = $r->capacity_at_risk
                ? 'PREFER_AUTXML_XML_ZIP_OR_OFFICIAL_PACKAGE'
                : match ($r->urgency_band) {
                    OutboundUrgencyBand::Contingency, OutboundUrgencyBand::Overdue => 'ASSISTED_IMPORT',
                    OutboundUrgencyBand::Attention => 'PREPARE_ASSISTED_BATCH',
                    default => 'WAIT_OR_PREFER_AUTXML',
                };
            // não expor prioridade remota editável, PFX, senha ou chave completa

            return $arr;
        });

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function contingencyBatch(Request $request): JsonResponse
    {
        $this->authorizeOperator();
        $officeId = (int) $this->currentOffice->id();
        $competence = $request->query('competence');

        $batch = $this->satisfaction->contingencyBatch(
            $officeId,
            is_string($competence) ? $competence : null,
        );

        return response()->json(['data' => $batch]);
    }

    public function confirmPartialExport(Request $request): JsonResponse
    {
        $this->authorizeOperator();
        $data = $request->validate([
            'competence' => ['required', 'string', 'max:7'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            Competence::fromString($data['competence']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $row = $this->readiness->confirmPartial(
            (int) $this->currentOffice->id(),
            $data['competence'],
            (int) auth()->id(),
            $data['notes'] ?? null,
        );

        return response()->json(['data' => $row->toPublicArray()]);
    }

    public function metrics(Request $request): JsonResponse
    {
        $this->authorizeView();
        $officeId = (int) $this->currentOffice->id();
        $competence = $request->query('competence');
        $comp = is_string($competence) && $competence !== '' ? $competence : null;
        if ($comp !== null) {
            try {
                Competence::fromString($comp);
            } catch (InvalidArgumentException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }
        }

        return response()->json([
            'data' => $this->outboundMetrics->deadlineSnapshot($officeId, $comp),
        ]);
    }

    /**
     * Exportação ZIP assíncrona da competência — exige COMPLETE_KNOWN ou PARTIAL_CONFIRMED.
     */
    public function exportMonthly(Request $request): JsonResponse
    {
        $this->authorizeOperator();
        $data = $request->validate([
            'competence' => ['required', 'string', 'max:7'],
            'include_events' => ['sometimes', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        try {
            Competence::fromString($data['competence']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        try {
            $result = $this->monthlyExport->createMonthlyExport(
                (int) $this->currentOffice->id(),
                (int) auth()->id(),
                $data['competence'],
                (bool) ($data['include_events'] ?? false),
                $data['notes'] ?? null,
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $export = $result['export'];

        return response()->json([
            'data' => [
                'export' => [
                    'id' => $export->id,
                    'status' => $export->status,
                    'filters' => $export->filters,
                    'include_events' => $export->include_events,
                    'created_at' => $export->created_at?->toIso8601String(),
                ],
                'readiness' => $result['readiness']->toPublicArray(),
                'has_manifest' => $result['manifest_path'] !== null,
                'completeness_scope' => 'known_documents_only',
            ],
        ], 202);
    }

    public function advanceTarget(Request $request): JsonResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'competence' => ['required', 'string', 'max:7'],
            'target_at' => ['required', 'date'],
        ]);

        try {
            $comp = Competence::fromString($data['competence']);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $sla = OperationalSla::fromConfig($this->currentOffice->office()?->deadline_timezone);
        $deadlines = $sla->deadlinesFor($comp);
        $newTarget = CarbonImmutable::parse($data['target_at'])->utc();

        // Só antecipar (não postergar além de due_at nem além do due do dia 1)
        if ($newTarget->greaterThanOrEqualTo($deadlines['due_at'])) {
            return response()->json([
                'message' => 'Não é permitido postergar a meta além do due_at (fim do dia 1).',
            ], 422);
        }
        if ($newTarget->greaterThan($deadlines['target_at'])) {
            return response()->json([
                'message' => 'Só é permitido antecipar a meta interna, não postergá-la.',
            ], 422);
        }
        $minBuffer = 24;
        if ($deadlines['due_at']->diffInHours($newTarget) < $minBuffer) {
            return response()->json([
                'message' => 'Buffer interno não pode ser inferior a 24 horas.',
            ], 422);
        }

        $officeId = (int) $this->currentOffice->id();
        $n = MaOutboundRetrievalRequest::query()
            ->where('office_id', $officeId)
            ->where('competence', $comp->value())
            ->whereNotIn('urgency_band', [OutboundUrgencyBand::Captured->value])
            ->update(['target_at' => $newTarget]);

        $this->audit->record('outbound.deadline.advance_target', 'SUCCESS', null, [
            'competence' => $comp->value(),
            'target_at' => $newTarget->toIso8601String(),
            'rows' => $n,
            // sem budget/coorte
        ], null, $officeId);

        return response()->json([
            'data' => [
                'competence' => $comp->value(),
                'target_at' => $newTarget->toIso8601String(),
                'due_at' => $deadlines['due_at']->toIso8601String(),
                'updated_rows' => $n,
            ],
        ]);
    }

    private function authorizeView(): void
    {
        if ($this->currentOffice->role() === null) {
            abort(403);
        }
    }

    private function authorizeOperator(): void
    {
        $role = $this->currentOffice->role();
        if ($role === null || ! in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }

    private function authorizeAdmin(): void
    {
        if ($this->currentOffice->role() !== OfficeRole::Admin) {
            abort(403, 'Apenas administradores com 2FA recente.');
        }
    }
}
