<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\OfficeRole;
use App\Enums\OutboundProfileStatus;
use App\Enums\OutboundSeriesStatus;
use App\Http\Controllers\Controller;
use App\Jobs\QueryOutboundSequenceJob;
use App\Models\Establishment;
use App\Models\OutboundCaptureProfile;
use App\Models\OutboundCaptureRun;
use App\Models\OutboundNumberState;
use App\Models\OutboundSeriesCursor;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\RecentPasswordConfirmationGate;
use App\Services\Outbound\CscVaultService;
use App\Services\Outbound\MaOfficialPackageIngestionService;
use App\Services\Outbound\OutboundKillSwitchService;
use App\Services\Outbound\OutboundSeedService;
use App\Support\CurrentOffice;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class OutboundCaptureController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $office,
        private readonly OutboundSeedService $seeds,
        private readonly CscVaultService $csc,
        private readonly MaOfficialPackageIngestionService $packages,
        private readonly OutboundKillSwitchService $killSwitch,
        private readonly AuditLogger $audit,
    ) {}

    public function indexProfiles(Request $request): JsonResponse
    {
        $this->authorizeView();
        $q = OutboundCaptureProfile::query()->orderByDesc('id');
        if ($request->filled('establishment_id')) {
            $q->where('establishment_id', (int) $request->input('establishment_id'));
        }
        if ($request->filled('client_id')) {
            $q->where('client_id', (int) $request->input('client_id'));
        }

        return response()->json([
            'data' => $q->limit(100)->get()->map->toPublicArray(),
        ]);
    }

    public function showProfile(OutboundCaptureProfile $profile): JsonResponse
    {
        $this->ensureProfile($profile);

        return response()->json(['data' => $profile->toPublicArray()]);
    }

    public function storeSeed(Request $request, Establishment $establishment): JsonResponse
    {
        $this->authorizeOperator();
        $this->ensureEstablishment($establishment);

        $data = $request->validate([
            'environment' => ['required', Rule::in(['production', 'homologation'])],
            'xml' => ['required_without:file', 'string'],
            'file' => ['required_without:xml', 'file', 'max:5120'],
        ]);

        $xml = $data['xml'] ?? (string) file_get_contents($request->file('file')->getRealPath());

        try {
            $result = $this->seeds->registerSeed(
                $establishment,
                $xml,
                $data['environment'],
                (int) $request->user()->id,
            );
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'data' => [
                'profile' => $result['profile']->toPublicArray(),
                'series' => $result['series']->toPublicArray(),
            ],
        ], 201);
    }

    public function storeCsc(Request $request, OutboundCaptureProfile $profile): JsonResponse
    {
        $this->authorizeAdminWithTwoFactor();
        $this->ensureProfile($profile);

        $data = $request->validate([
            'csc' => ['required', 'string', 'min:1', 'max:100'],
            'csc_id' => ['required', 'string', 'min:1', 'max:20'],
        ]);

        $state = $this->csc->storeCsc(
            $profile,
            $data['csc'],
            $data['csc_id'],
            (int) $request->user()->id,
        );

        // ADMIN+2FA: devolve valor para UI; audit outbound.csc.replaced + .revealed sem o token.
        return response()->json(['data' => $state]);
    }

    public function showCsc(Request $request, OutboundCaptureProfile $profile): JsonResponse
    {
        $this->authorizeAdminWithTwoFactor();
        $this->ensureProfile($profile);

        return response()->json([
            'data' => $this->csc->revealCsc($profile, (int) $request->user()->id),
        ]);
    }

    public function activate(Request $request, OutboundCaptureProfile $profile): JsonResponse
    {
        $this->authorizeAdmin();
        $this->ensureProfile($profile);

        $data = $request->validate([
            'mandate_reference' => ['required', 'string', 'max:255'],
            'allowlisted' => ['sometimes', 'boolean'],
        ]);

        $allowlisted = (bool) ($data['allowlisted'] ?? false);

        $profile->forceFill([
            'mandate_reference' => $data['mandate_reference'],
            'consent_recorded' => true,
            'consent_recorded_at' => now(),
            'allowlisted' => $allowlisted,
            'allowlisted_at' => $allowlisted ? now() : null,
            'status' => OutboundProfileStatus::Active,
            'activated_by' => $request->user()->id,
            'activated_at' => now(),
        ])->save();

        $this->audit->record('outbound.profile.activated', 'SUCCESS', $profile, [
            'profile_id' => $profile->id,
            'mandate_reference' => $data['mandate_reference'],
        ], (int) $request->user()->id, $profile->office_id);

        return response()->json(['data' => $profile->fresh()->toPublicArray()]);
    }

    public function resetSeries(Request $request, OutboundSeriesCursor $series): JsonResponse
    {
        $this->authorizeAdmin();
        $this->ensureSeries($series);

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'discovery_position' => ['required', 'integer', 'min:1'],
            'confirm' => ['required', 'accepted'],
        ]);

        $series->forceFill([
            'discovery_position' => (int) $data['discovery_position'],
            'status' => OutboundSeriesStatus::Idle,
            'last_error' => null,
        ])->save();

        $this->audit->record('outbound.series.reset', 'SUCCESS', $series, [
            'series_id' => $series->id,
            'discovery_position' => (int) $data['discovery_position'],
            'reason' => $data['reason'],
            'position_kind' => 'nNF',
        ], (int) $request->user()->id, $series->office_id);

        return response()->json(['data' => $series->fresh()->toPublicArray()]);
    }

    public function triggerQuery(Request $request, OutboundSeriesCursor $series): JsonResponse
    {
        $this->authorizeOperator();
        $this->ensureSeries($series);

        if (! (bool) config('sefaz.ma_outbound.protocol_query_enabled', false)) {
            return response()->json(['message' => 'Consulta de protocolo desabilitada.'], 403);
        }

        QueryOutboundSequenceJob::dispatch($series->id, 'operator', (int) $request->user()->id);

        return response()->json(['data' => ['queued' => true, 'series_id' => $series->id]]);
    }

    public function uploadPackage(Request $request, OutboundCaptureProfile $profile): JsonResponse
    {
        $this->authorizeOperator();
        $this->ensureProfile($profile);

        $request->validate([
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'max:51200'],
        ]);

        $establishment = Establishment::query()->findOrFail($profile->establishment_id);
        $result = $this->packages->ingest(
            $profile,
            $establishment,
            $request->file('files', []),
            (int) $request->user()->id,
        );

        return response()->json(['data' => $result]);
    }

    public function listSeries(OutboundCaptureProfile $profile): JsonResponse
    {
        $this->ensureProfile($profile);
        $rows = OutboundSeriesCursor::query()
            ->where('outbound_capture_profile_id', $profile->id)
            ->orderBy('series')
            ->get()
            ->map->toPublicArray();

        return response()->json(['data' => $rows]);
    }

    public function listNumbers(Request $request, OutboundSeriesCursor $series): JsonResponse
    {
        $this->ensureSeries($series);
        $q = OutboundNumberState::query()
            ->where('outbound_series_cursor_id', $series->id)
            ->orderBy('nnf');

        if ($request->boolean('gaps_only')) {
            $q->whereIn('status', [
                'GAP_PENDING', 'RETRY_SCHEDULED', 'EXHAUSTED_VISIBLE',
                'XML_PENDING', 'KEY_DISCOVERED', 'LIMITED_NO_KEY',
            ]);
        }

        return response()->json([
            'data' => $q->limit(200)->get()->map->toPublicArray(),
        ]);
    }

    public function listRuns(Request $request): JsonResponse
    {
        $this->authorizeView();
        $q = OutboundCaptureRun::query()->orderByDesc('id')->limit(50);
        if ($request->filled('series_cursor_id')) {
            $q->where('outbound_series_cursor_id', (int) $request->input('series_cursor_id'));
        }

        return response()->json([
            'data' => $q->get()->map->toPublicArray(),
        ]);
    }

    public function killSwitch(Request $request): JsonResponse
    {
        $this->authorizeAdmin();
        $data = $request->validate([
            'active' => ['required', 'boolean'],
            'reason' => ['required', 'string', 'min:5', 'max:500'],
            'profile_id' => ['sometimes', 'integer', 'exists:outbound_capture_profiles,id'],
        ]);

        $officeId = $this->office->id();
        $userId = (int) $request->user()->id;

        if (! empty($data['profile_id'])) {
            $profile = OutboundCaptureProfile::query()->findOrFail($data['profile_id']);
            $this->ensureProfile($profile);
            if ($data['active']) {
                $this->killSwitch->activateProfile($profile, $data['reason'], $userId);
            } else {
                $this->killSwitch->deactivateProfile($profile, $data['reason'], $userId);
            }

            return response()->json(['data' => $profile->fresh()->toPublicArray()]);
        }

        if ($data['active']) {
            $this->killSwitch->activateGlobal($data['reason'], $userId, $officeId);
        } else {
            $this->killSwitch->deactivateGlobal($data['reason'], $userId, $officeId);
        }

        return response()->json([
            'data' => [
                'global_active' => $this->killSwitch->isGlobalActive(),
                'position_kind' => 'nNF',
            ],
        ]);
    }

    public function killSwitchStatus(): JsonResponse
    {
        $this->authorizeView();

        return response()->json([
            'data' => [
                'global_active' => $this->killSwitch->isGlobalActive(),
                'config_flag' => (bool) config('sefaz.ma_outbound.kill_switch', false),
                'enabled' => (bool) config('sefaz.ma_outbound.enabled', false),
                'protocol_query_enabled' => (bool) config('sefaz.ma_outbound.protocol_query_enabled', false),
                'm2m_status' => (string) config('sefaz.ma_outbound.m2m_status', 'NO_GO_M2M'),
                'mutating_probe_enabled' => (bool) config('sefaz.ma_outbound.mutating_probe_enabled', false),
            ],
        ]);
    }

    private function authorizeView(): void
    {
        abort_unless($this->office->role() !== null, 403);
    }

    private function authorizeOperator(): void
    {
        $role = $this->office->role();
        abort_unless(in_array($role, [OfficeRole::Admin, OfficeRole::Operator], true), 403);
    }

    private function authorizeAdmin(): void
    {
        abort_unless($this->office->role() === OfficeRole::Admin, 403);
    }

    /**
     * ADMIN + senha recente (defesa em profundidade para materializar segredos fiscais).
     */
    private function authorizeAdminWithTwoFactor(): void
    {
        abort_unless($this->office->role() === OfficeRole::Admin, 403);

        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        $gate = app(RecentPasswordConfirmationGate::class);
        abort_unless(
            $gate->isRecentlyConfirmed($user),
            403,
            'Reconfirme sua senha para acessar funções administrativas.',
        );
    }

    private function ensureProfile(OutboundCaptureProfile $profile): void
    {
        abort_unless($profile->office_id === $this->office->id(), 404);
    }

    private function ensureSeries(OutboundSeriesCursor $series): void
    {
        abort_unless($series->office_id === $this->office->id(), 404);
    }

    private function ensureEstablishment(Establishment $establishment): void
    {
        abort_unless($establishment->office_id === $this->office->id(), 404);
    }
}
