<?php

namespace App\Actions\Fiscal\Mutations;

use App\Actions\Fiscal\ViewMailboxMonitoringAction;
use App\DTO\Fiscal\Monitoring\MailboxMonitoringOverviewData;
use App\DTO\Fiscal\Mutations\ConfirmMailboxSyncData;
use App\DTO\Fiscal\Mutations\UpdateMailboxMonitoringSettingsData;
use App\Models\Client;
use App\Models\MailboxMonitoringSetting;
use App\Services\Integra\Mailbox\MailboxCostPolicy;
use App\Services\Integra\Mailbox\MailboxDetailEnqueueService;
use App\Services\Integra\Mailbox\MailboxQueryService;
use App\Services\Integra\Mailbox\MailboxSyncOrchestrator;
use App\Support\CurrentTenant;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Cache;

final readonly class OperateMailboxMonitoringAction
{
    public function __construct(
        private CurrentTenant $currentTenant,
        private MailboxSyncOrchestrator $sync,
        private MailboxCostPolicy $cost,
        private MailboxQueryService $queries,
        private MailboxDetailEnqueueService $details,
        private ViewMailboxMonitoringAction $overview,
    ) {}

    public function updateSettings(UpdateMailboxMonitoringSettingsData $data): MailboxMonitoringOverviewData
    {
        $tenant = $this->currentTenant->tenant();
        MailboxMonitoringSetting::query()->withoutGlobalScopes()->updateOrCreate(
            ['tenant_id' => $tenant->id],
            $data->attributes(),
        );

        return $this->overview->handle($tenant);
    }

    /** @return array<string, mixed> */
    public function preview(bool $forceAll): array
    {
        $tenant = $this->currentTenant->tenant();
        $setting = MailboxMonitoringSetting::query()
            ->withoutGlobalScopes()
            ->firstOrNew(['tenant_id' => $tenant->id]);
        $preview = $this->sync->preview($tenant, $setting, $forceAll);

        return $this->publicPreview($preview);
    }

    /** @return array{status: int, data: array<string, mixed>} */
    public function confirm(ConfirmMailboxSyncData $data): array
    {
        $tenant = $this->currentTenant->tenant();
        $cacheKey = 'mailbox-sync-confirm:'.$tenant->id.':'.hash('sha256', $data->idempotencyKey);
        if (! Cache::add($cacheKey, true, now()->addDay())) {
            return [
                'status' => 202,
                'data' => ['duplicate' => true, 'status' => 'ACCEPTED'],
            ];
        }

        try {
            $setting = MailboxMonitoringSetting::query()
                ->withoutGlobalScopes()
                ->firstOrNew(['tenant_id' => $tenant->id]);
            $result = $this->sync->confirm($tenant, $setting, $data->forceAll);
        } catch (\RuntimeException $e) {
            Cache::forget($cacheKey);

            throw new HttpResponseException(response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getMessage(),
            ], 409));
        }

        return [
            'status' => 202,
            'data' => [
                'duplicate' => false,
                'status' => 'ACCEPTED',
                'runs_enqueued' => count($result['runs']),
                'preview' => $this->publicPreview($result['preview']),
            ],
        ];
    }

    /** @return array{status: int, data: array<string, mixed>} */
    public function enqueueDetail(int $messageId): array
    {
        $tenant = $this->currentTenant->tenant();
        $model = $this->queries->message($tenant, $messageId);
        if ($model === null) {
            throw new HttpResponseException(response()->json([
                'message' => 'Mensagem não encontrada.',
            ], 404));
        }

        try {
            $preview = $this->cost->assertAllowed((int) $tenant->id, 'DETALHE');
            $client = Client::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->findOrFail($model->client_id);
            $run = $this->details->enqueueOnDemand($tenant, $client, $model);
        } catch (\RuntimeException $e) {
            throw new HttpResponseException(response()->json([
                'message' => $e->getMessage(),
                'code' => $e->getMessage(),
            ], 409));
        }

        return [
            'status' => 202,
            'data' => [
                'status' => 'ACCEPTED',
                'run_id' => $run->id,
                'cost' => $preview,
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function publicPreview(array $preview): array
    {
        unset($preview['client_ids'], $preview['reasons']);

        return $preview;
    }
}
