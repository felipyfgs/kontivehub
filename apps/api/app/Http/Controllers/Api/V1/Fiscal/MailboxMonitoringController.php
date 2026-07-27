<?php

namespace App\Http\Controllers\Api\V1\Fiscal;

use App\Enums\MailboxMonitoringMode;
use App\Enums\TenantPermission;
use App\Http\Controllers\Controller;
use App\Http\Middleware\EnsureTenantContext;
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
use App\Support\CurrentTenant;
use App\Support\FeatureFlags;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;

final class MailboxMonitoringController extends Controller
{
    public function __construct(
        private readonly CurrentTenant $currentTenant,
        private readonly TenantAuthorization $authorization,
        private readonly MailboxSyncOrchestrator $sync,
        private readonly MailboxCostPolicy $cost,
        private readonly MailboxQueryService $queries,
        private readonly MailboxDetailEnqueueService $details,
    ) {}

    public function show(): JsonResponse
    {
        $this->assertAllowed(TenantPermission::OperationsView);
        $tenant = $this->currentTenant->tenant();
        $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()
            ->firstOrNew(['tenant_id' => $tenant->id]);
        $states = MailboxClientSyncState::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->get();
        $lastFree = SerproEventosRun::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)->where('evento', 'E0601')
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
        $this->rejectTenantId($request);
        $tenant = $this->currentTenant->tenant();
        $data = $request->validate([
            'tenant_id' => ['prohibited'],
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
            ['tenant_id' => $tenant->id],
            $data,
        );

        return $this->show()->setStatusCode(200);
    }

    public function preview(Request $request): JsonResponse
    {
        $this->assertAllowed(TenantPermission::FiscalSyncTrigger);
        $this->rejectTenantId($request);
        $request->validate(['tenant_id' => ['prohibited'], 'force_all' => ['sometimes', 'boolean']]);
        $tenant = $this->currentTenant->tenant();
        $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenant->id]);
        $preview = $this->sync->preview($tenant, $setting, $request->boolean('force_all'));

        return response()->json(['data' => $this->publicPreview($preview)])->header('Cache-Control', 'no-store');
    }

    public function sync(Request $request): JsonResponse
    {
        $this->assertAllowed(TenantPermission::FiscalSyncTrigger);
        $this->rejectTenantId($request);
        $data = $request->validate([
            'tenant_id' => ['prohibited'],
            'force_all' => ['sometimes', 'boolean'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:120'],
        ]);
        $tenant = $this->currentTenant->tenant();
        $cacheKey = 'mailbox-sync-confirm:'.$tenant->id.':'.hash('sha256', $data['idempotency_key']);
        if (! Cache::add($cacheKey, true, now()->addDay())) {
            return response()->json(['data' => ['duplicate' => true, 'status' => 'ACCEPTED']], 202);
        }
        try {
            $setting = MailboxMonitoringSetting::query()->withoutGlobalScopes()->firstOrNew(['tenant_id' => $tenant->id]);
            $result = $this->sync->confirm($tenant, $setting, (bool) ($data['force_all'] ?? false));
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
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->message($tenant, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }

        return response()->json(['data' => [
            'has_body' => (bool) $model->has_body,
            'cost' => $this->cost->preview((int) $tenant->id, 'DETALHE'),
        ]])->header('Cache-Control', 'no-store');
    }

    public function detail(int $message): JsonResponse
    {
        $this->assertAllowed(TenantPermission::FiscalSyncTrigger);
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->message($tenant, $message);
        if ($model === null) {
            return response()->json(['message' => 'Mensagem não encontrada.'], 404);
        }
        try {
            $preview = $this->cost->assertAllowed((int) $tenant->id, 'DETALHE');
            $client = Client::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)->findOrFail($model->client_id);
            $run = $this->details->enqueueOnDemand($tenant, $client, $model);
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
        $tenant = $this->currentTenant->tenant();
        if (! $actor instanceof User || ! $this->authorization->allows($actor, $permission)) {
            abort(403, 'Sem permissão para operar o monitoramento da Caixa Postal.');
        }
        if (! FeatureFlags::isModuleEnabled('mailbox', (int) $tenant->id)) {
            abort(403, 'Módulo Caixa Postal não disponível.');
        }
    }

    private function rejectTenantId(Request $request): void
    {
        if ($request->exists('tenant_id')
            || $request->attributes->getBoolean(EnsureTenantContext::CLIENT_TENANT_ID_SUPPLIED)) {
            throw ValidationException::withMessages([
                'tenant_id' => 'tenant_id é derivado do tenant autenticado e não pode ser enviado.',
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
