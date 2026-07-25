<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\MailboxMonitoringMode;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureOfficeContext;
use App\Models\Client;
use App\Models\MailboxClientSyncState;
use App\Models\MailboxMonitoringSetting;
use App\Models\SerproEventosRun;
use App\Models\User;
use App\Services\Authorization\TenantAuthorization;
use App\Services\Integra\Mailbox\MailboxCostPolicy;
use App\Services\Integra\Mailbox\MailboxDetailEnqueueService;
use App\Services\Integra\Mailbox\MailboxQueryService;
use App\Services\Integra\Mailbox\MailboxSyncOrchestrator;
use App\Support\CurrentOffice;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class MailboxMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentOffice $currentOffice,
        private readonly TenantAuthorization $authorization,
        private readonly MailboxSyncOrchestrator $sync,
        private readonly MailboxCostPolicy $cost,
        private readonly MailboxQueryService $queries,
        private readonly MailboxDetailEnqueueService $details,
    ) {}

    public function show(): JsonResponse
    {
        $this->assertAllowed(TenantPermission::OperationsView);
        $office = $this->currentOffice->office();
        $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()
            ->firstOrNew(['office_id' => $office->id]);
        $states = MailboxClientSyncState::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->get();
        $lastFree = SerproEventosRun::query()->withoutGlobalScopes()
            ->where('office_id', $office->id)->where('evento', 'E0601')
            ->whereNotNull('remote_result_received_at')->max('remote_result_received_at');

        return response()->json(['data' => [
            'enabled' => $setting->enabled,
            'runtime_enabled' => $setting->enabled
                && (bool) config('fiscal_monitoring.mailbox.economic_monitoring.enabled', false),
            'mode' => $setting->mode->value,
            'daily_time' => $setting->daily_time,
            'timezone' => $setting->timezone,
            'reconciliation_days' => $setting->reconciliation_days,
            'auto_detail_limit' => $setting->auto_detail_limit,
            'monthly_budget_micros' => $setting->monthly_budget_micros,
            'coverage' => [
                'initialized_clients' => $states->whereNotNull('bootstrap_completed_at')->count(),
                'pending_clients' => $states->whereNotNull('pending_event_date')->count(),
                'blocked_clients' => $states->where('authorization_status', 'DENIED')->count(),
                'failed_clients' => $states->whereNotNull('last_error_code')->count(),
            ],
            'last_free_check_at' => $lastFree,
            'last_paid_check_at' => $states->max('last_list_at')?->toIso8601String(),
            'last_full_reconciliation_at' => $states->max('last_full_reconciliation_at')?->toIso8601String(),
            'last_dispatched_at' => $setting->last_dispatched_at?->toIso8601String(),
            'next_due_at' => $setting->next_due_at?->toIso8601String(),
            'indicator_note' => 'O indicador gratuito é diagnóstico; zero não comprova caixa vazia.',
        ]])->header('Cache-Control', 'no-store');
    }

    public function update(Request $request): JsonResponse
    {
        $this->assertAllowed(TenantPermission::TenantSettingsManage);
        $this->rejectOfficeId($request);
        $office = $this->currentOffice->office();
        $data = $request->validate([
            'office_id' => ['prohibited'],
            'enabled' => ['sometimes', 'boolean'],
            'mode' => ['sometimes', 'string', 'in:ECONOMICO,DIARIO_COMPLETO'],
            'daily_time' => ['sometimes', 'date_format:H:i'],
            'timezone' => ['sometimes', 'in:America/Sao_Paulo'],
            'reconciliation_days' => ['sometimes', 'integer', 'between:1,365'],
            'auto_detail_limit' => ['sometimes', 'integer', 'between:0,100'],
            'monthly_budget_micros' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ]);
        if (isset($data['mode'])) {
            $data['mode'] = MailboxMonitoringMode::from($data['mode']);
        }
        $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()->updateOrCreate(
            ['office_id' => $office->id],
            $data,
        );

        return $this->show()->setStatusCode(200);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->assertAllowed(TenantPermission::FiscalSyncTrigger);
        $this->rejectOfficeId($request);
        $request->validate(['office_id' => ['prohibited'], 'force_all' => ['sometimes', 'boolean']]);
        $office = $this->currentOffice->office();
        $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()->firstOrNew(['office_id' => $office->id]);
        $preview = $this->sync->preview($office, $setting, $request->boolean('force_all'));

        return response()->json(['data' => $this->publicPreview($preview)])->header('Cache-Control', 'no-store');
    }

    public function sync(Request $request): JsonResponse
    {
        $this->assertAllowed(TenantPermission::FiscalSyncTrigger);
        $this->rejectOfficeId($request);
        $data = $request->validate([
            'office_id' => ['prohibited'],
            'force_all' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);
        $office = $this->currentOffice->office();
        $cacheKey = 'mailbox-sync-confirm:'.$office->id.':'.hash('sha256', $data['idempotency_key']);
        if (! Cache::add($cacheKey, true, now()->addDay())) {
            return response()->json(['data' => ['duplicate' => true, 'status' => 'ACCEPTED']], 202);
        }
        try {
            $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()->firstOrNew(['office_id' => $office->id]);
            $result = $this->sync->confirm($office, $setting, (bool) ($data['force_all'] ?? false));
        } catch (\RuntimeException $e) {
            Cache::forget($cacheKey);

            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 409);
        }

        return response()->json(['data' => [
            'duplicate' => false,
            'status' => 'ACCEPTED',
            'runs_enqueued' => count($result['runs']),
            'preview' => $this->publicPreview($result['preview']),
        ]], 202);
    }

    public function detailPreview(int $message): JsonResponse
    {
        $this->assertAllowed(TenantPermission::FiscalSyncTrigger);
        $office = $this->currentOffice->office();
        $model = $this->queries->message($office, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        return response()->json(['data' => [
            'has_body' => (bool) $model->has_body,
            'cost' => $this->cost->preview((int) $office->id, 'DETALHE'),
        ]])->header('Cache-Control', 'no-store');
    }

    public function detail(int $message): JsonResponse
    {
        $this->assertAllowed(TenantPermission::FiscalSyncTrigger);
        $office = $this->currentOffice->office();
        $model = $this->queries->message($office, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }
        try {
            $preview = $this->cost->assertAllowed((int) $office->id, 'DETALHE');
            $client = Client::query()->withoutGlobalScopes()
                ->where('office_id', $office->id)->findOrFail($model->client_id);
            $run = $this->details->enqueueOnDemand($office, $client, $model);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage(), 'code' => $e->getMessage()], 409);
        }

        return response()->json(['data' => [
            'status' => 'ACCEPTED', 'run_id' => $run->id, 'cost' => $preview,
        ]], 202);
    }

    private function assertAllowed(TenantPermission $permission): void
    {
        $actor = request()->user();
        $office = $this->currentOffice->office();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, $permission)) {
            abort(403, 'Sem permissão para operar o monitoramento da Caixa Postal.');
        }
        if (! FeatureFlags::isModuleEnabled('mailbox', (int) $office->id)) {
            abort(403, 'Módulo Caixa Postal não disponível.');
        }
    }

    private function rejectOfficeId(Request $request): void
    {
        if ($request->exists('office_id')
            || $request->attributes->getBoolean(EnsureOfficeContext::CLIENT_OFFICE_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'office_id' => 'office_id é derivado do tenant autenticado e não pode ser enviado.',
            ]);
        }
    }

    /** @return array<string,mixed> */
    private function publicPreview(array $preview): array
    {
        unset($preview['client_ids'], $preview['reasons']);

        return $preview;
    }
}
