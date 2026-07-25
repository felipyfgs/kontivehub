<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\CaptureChannel;
use App\Enums\OfficeAutXmlEnrollmentStatus;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\Establishment;
use App\Models\OfficeAutXmlEnrollment;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeDistributionRun;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Certificates\OfficeFiscalIdentityService;
use App\Services\Sefaz\AutXmlCircuitBreaker;
use App\Support\CurrentOffice;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Onboarding autXML e status do cursor central do escritório (sem reset de NSU).
 */
class OfficeAutXmlController extends Controller
{
    public function __construct(private readonly TenantAuthorization $authorization) {}

    public function overview(
        Request $request,
        CurrentOffice $office,
        OfficeFiscalIdentityService $identities,
    ): JsonResponse {
        $this->authorizeView($office);
        $identity = $identities->activeForCurrentOffice();
        $cursor = $this->primaryCursor($office->id());
        $stream = $this->streamGate($cursor);

        $perPage = min(max($request->integer('per_page', 25), 1), 100);
        $establishments = Establishment::query()
            ->where('office_id', $office->id())
            ->where('is_active', true)
            ->with('client:id,legal_name,display_name')
            ->orderBy('cnpj')
            ->orderBy('id')
            ->paginate($perPage);

        $establishmentIds = collect($establishments->items())->pluck('id');
        $enrollmentsByEst = collect();
        if ($identity !== null && $establishmentIds->isNotEmpty()) {
            $enrollmentsByEst = OfficeAutXmlEnrollment::query()
                ->where('office_id', $office->id())
                ->where('office_fiscal_identity_id', $identity->id)
                ->whereIn('establishment_id', $establishmentIds)
                ->get()
                ->keyBy('establishment_id');
        }

        $checklist = collect($establishments->items())
            ->map(function (Establishment $est) use ($enrollmentsByEst): array {
                /** @var OfficeAutXmlEnrollment|null $enrollment */
                $enrollment = $enrollmentsByEst->get($est->id);

                return $this->enrollmentArray($est, $enrollment);
            })
            ->values()
            ->all();

        return response()->json([
            'data' => [
                'identity' => $identity?->toPublicArray(),
                'office_cnpj' => $identity?->cnpj,
                'enrollments' => $checklist,
                'cursor' => $cursor?->toPublicArray(),
                'stream' => $stream,
                'coverage' => [
                    'channel' => 'NFE_AUTXML_DISTDFE',
                    'model' => '55',
                    'label' => 'NF-e modelo 55',
                    'not_retroactive' => true,
                    'nfce_note' => 'NFC-e 65 e histórico/lacunas: import XML/ZIP.',
                ],
                'checklist' => [
                    'coverage' => 'NF-e modelo 55 apenas (NFC-e 65 não usa DistDFe autXML)',
                    'not_retroactive' => true,
                    'erp_instruction' => 'Inclua o CNPJ completo do escritório na tag autXML do ERP antes de autorizar NF-e 55. Sem efeito retroativo.',
                    'stream_activated' => $cursor?->activated_at !== null,
                    'can_confirm_enrollment' => $stream['stream_ready'],
                    'stream_reason' => $stream['stream_reason'],
                    'quiet_hours' => $stream['quiet_hours'],
                    'ready_at' => $stream['ready_at'],
                ],
            ],
            'meta' => [
                'current_page' => $establishments->currentPage(),
                'last_page' => $establishments->lastPage(),
                'per_page' => $establishments->perPage(),
                'total' => $establishments->total(),
            ],
        ]);
    }

    public function cursor(CurrentOffice $office): JsonResponse
    {
        $this->authorizeView($office);
        $breaker = app(AutXmlCircuitBreaker::class);

        $cursors = OfficeDistributionCursor::query()
            ->where('office_id', $office->id())
            ->where('channel', CaptureChannel::NfeAutXmlDistDfe)
            ->orderBy('id')
            ->get();

        $primary = $cursors->first();
        $mapped = $cursors->map(function (OfficeDistributionCursor $c) use ($breaker) {
            $public = $c->toPublicArray();
            $public['backoff'] = $c->next_sync_at && $c->next_sync_at->isFuture();
            $public['circuit_breaker_open'] = $breaker->isOpen($c);
            $public['circuit_open'] = $public['circuit_breaker_open'] || ($public['circuit_open'] ?? false);

            return $public;
        })->values()->all();

        $recentRuns = [];
        if ($primary !== null) {
            $recentRuns = OfficeDistributionRun::query()
                ->where('office_distribution_cursor_id', $primary->id)
                ->orderByDesc('id')
                ->limit(10)
                ->get()
                ->map(fn (OfficeDistributionRun $r) => $r->toPublicArray())
                ->values()
                ->all();
        }

        return response()->json([
            'data' => [
                // Compat: lista + primário para o card da UI
                'cursors' => $mapped,
                'cursor' => $mapped[0] ?? null,
                'stream' => $this->streamGate($primary),
                'recent_runs' => $recentRuns,
            ],
        ]);
    }

    public function enroll(
        Request $request,
        CurrentOffice $office,
        OfficeFiscalIdentityService $identities,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorizeManage($office);
        $data = $request->validate([
            'establishment_id' => ['required', 'integer'],
        ]);

        $identity = $identities->activeForCurrentOffice();
        if ($identity === null) {
            return response()->json(['message' => 'Cadastre a identidade fiscal do escritório primeiro.'], 422);
        }

        $estab = Establishment::query()
            ->where('office_id', $office->id())
            ->whereKey((int) $data['establishment_id'])
            ->first();
        if ($estab === null) {
            return response()->json(['message' => 'Estabelecimento não encontrado.'], 404);
        }

        if (! $estab->is_active) {
            return response()->json(['message' => 'Estabelecimento inativo não pode ser enrolled em autXML.'], 422);
        }

        $enrollment = OfficeAutXmlEnrollment::query()->firstOrCreate(
            [
                'office_fiscal_identity_id' => $identity->id,
                'establishment_id' => $estab->id,
            ],
            [
                'office_id' => $office->id(),
                'status' => OfficeAutXmlEnrollmentStatus::Pending,
            ]
        );

        if ($enrollment->status === OfficeAutXmlEnrollmentStatus::Inactive) {
            $enrollment->status = OfficeAutXmlEnrollmentStatus::Pending;
            $enrollment->activated_at = null;
            $enrollment->confirmed_by = null;
            $enrollment->save();
        }

        $audit->record('office_autxml.enroll', 'SUCCESS', $enrollment, [
            'establishment_id' => $estab->id,
            'status' => $enrollment->status->value,
        ]);

        $estab->loadMissing('client:id,legal_name,display_name');

        return response()->json([
            'data' => $this->enrollmentArray($estab, $enrollment->fresh()),
        ], 201);
    }

    public function confirm(
        Request $request,
        OfficeAutXmlEnrollment $enrollment,
        CurrentOffice $office,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorizeManage($office);
        if ((int) $enrollment->office_id !== $office->id()) {
            abort(404);
        }

        if ($enrollment->status === OfficeAutXmlEnrollmentStatus::Inactive) {
            return response()->json([
                'message' => 'Enrollment inativo — reative como PENDING antes de confirmar.',
            ], 422);
        }

        $cursor = $this->primaryCursor($office->id());
        $gate = $this->streamGate($cursor);
        if (! $gate['stream_ready']) {
            return response()->json([
                'message' => 'Confirmação bloqueada: ative o stream autXML (primeira distNSU) e aguarde o quiet mínimo de 1 hora.',
                'stream' => $gate,
            ], 422);
        }

        $enrollment->status = OfficeAutXmlEnrollmentStatus::Confirmed;
        $enrollment->activated_at = $enrollment->activated_at ?? now();
        $enrollment->confirmed_by = $request->user()?->id;
        $enrollment->save();

        $audit->record('office_autxml.enrollment_confirm', 'SUCCESS', $enrollment, [
            'establishment_id' => $enrollment->establishment_id,
            'first_seen_at' => $enrollment->first_seen_at?->toIso8601String(),
        ]);

        $estab = Establishment::query()
            ->with('client:id,legal_name,display_name')
            ->find($enrollment->establishment_id);

        return response()->json([
            'data' => $estab
                ? $this->enrollmentArray($estab, $enrollment->fresh())
                : $enrollment->toPublicArray(),
        ]);
    }

    public function inactivate(
        OfficeAutXmlEnrollment $enrollment,
        CurrentOffice $office,
        AuditLogger $audit,
    ): JsonResponse {
        $this->authorizeManage($office);
        if ((int) $enrollment->office_id !== $office->id()) {
            abort(404);
        }

        $enrollment->status = OfficeAutXmlEnrollmentStatus::Inactive;
        $enrollment->save();

        $audit->record('office_autxml.enrollment_inactivate', 'SUCCESS', $enrollment, [
            'establishment_id' => $enrollment->establishment_id,
        ]);

        $estab = Establishment::query()
            ->with('client:id,legal_name,display_name')
            ->find($enrollment->establishment_id);

        return response()->json([
            'data' => $estab
                ? $this->enrollmentArray($estab, $enrollment->fresh())
                : $enrollment->toPublicArray(),
        ]);
    }

    /**
     * @return array{
     *   stream_ready: bool,
     *   stream_reason: string|null,
     *   quiet_hours: float,
     *   activated_at: string|null,
     *   ready_at: string|null
     * }
     */
    private function streamGate(?OfficeDistributionCursor $cursor): array
    {
        $quietHours = max(0.0, (float) config('sefaz.autxml.quiet_hours_after_empty', 1));

        if ($cursor === null) {
            return [
                'stream_ready' => false,
                'stream_reason' => 'CURSOR_MISSING',
                'quiet_hours' => $quietHours,
                'activated_at' => null,
                'ready_at' => null,
            ];
        }

        if ($cursor->activated_at === null) {
            return [
                'stream_ready' => false,
                'stream_reason' => 'NOT_ACTIVATED',
                'quiet_hours' => $quietHours,
                'activated_at' => null,
                'ready_at' => null,
            ];
        }

        $activated = $cursor->activated_at instanceof CarbonImmutable
            ? $cursor->activated_at
            : CarbonImmutable::parse($cursor->activated_at);
        $readyAt = $activated->addHours($quietHours);
        $ready = $quietHours <= 0 || $readyAt->lte(CarbonImmutable::now());

        return [
            'stream_ready' => $ready,
            'stream_reason' => $ready ? null : 'QUIET_PENDING',
            'quiet_hours' => $quietHours,
            'activated_at' => $activated->toIso8601String(),
            'ready_at' => $readyAt->toIso8601String(),
        ];
    }

    private function primaryCursor(int $officeId): ?OfficeDistributionCursor
    {
        return OfficeDistributionCursor::query()
            ->where('office_id', $officeId)
            ->where('channel', CaptureChannel::NfeAutXmlDistDfe)
            ->orderBy('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function enrollmentArray(Establishment $est, ?OfficeAutXmlEnrollment $e): array
    {
        $client = $est->relationLoaded('client') ? $est->client : null;
        if ($client === null && $est->client_id) {
            $client = Client::query()->select(['id', 'legal_name', 'display_name'])->find($est->client_id);
        }

        return [
            'id' => $e?->id,
            'establishment_id' => $est->id,
            'establishment_cnpj' => $est->cnpj,
            'establishment_name' => $est->trade_name,
            'trade_name' => $est->trade_name,
            'client_id' => $est->client_id,
            'client_name' => $client?->display_name ?: $client?->legal_name,
            'status' => $e?->status instanceof OfficeAutXmlEnrollmentStatus
                ? $e->status->value
                : ($e?->status ?? 'NONE'),
            'activated_at' => $e?->activated_at?->toIso8601String(),
            'first_seen_at' => $e?->first_seen_at?->toIso8601String(),
            'last_seen_at' => $e?->last_seen_at?->toIso8601String(),
            'observed' => $e?->first_seen_at !== null,
            'channel_coverage' => 'NFE_55',
            'channel_coverage_label' => 'NF-e modelo 55 (autXML DistDFe)',
            'nfce_hint' => 'NFC-e modelo 65 não é capturada por este canal — use import XML/ZIP.',
            'erp_instruction' => 'Inclua o CNPJ completo do escritório na tag autXML do ERP antes de autorizar NF-e 55. Sem efeito retroativo sobre NSU já consumido.',
        ];
    }

    private function authorizeView(CurrentOffice $office): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::FiscalMonitoringView)) {
            abort(403);
        }
    }

    private function authorizeManage(CurrentOffice $office): void
    {
        $actor = request()->user();
        if (! $actor instanceof User
            || ! $this->authorization->allows($actor, TenantPermission::ClientsManage)) {
            abort(403, 'Ação não autorizada para o perfil atual.');
        }
    }
}
