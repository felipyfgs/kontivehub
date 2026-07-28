<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\ClientProcuracaoAutoSyncPolicy;
use App\Services\Integra\ClientProcuracaoSyncService;
use App\Support\LogSanitizer;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sincronização oficial de procuração por cliente (Horizon, unique).
 */
final class SyncClientProcuracaoJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 180;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $clientId,
        public readonly string $environment,
        public readonly ?int $actorUserId = null,
        public readonly ?string $correlationId = null,
        public readonly bool $automatic = false,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function uniqueId(): string
    {
        return 'serpro-procuracao:'.$this->tenantId.':'.$this->clientId.':'.$this->environment;
    }

    public function handle(
        ClientProcuracaoSyncService $sync,
        AuditLogger $audit,
        ClientProcuracaoAutoSyncPolicy $automaticPolicy,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $client = Client::query()
            ->where('tenant_id', $this->tenantId)
            ->whereKey($this->clientId)
            ->firstOrFail();
        $env = SerproEnvironment::from(strtoupper($this->environment));

        if ($this->automatic) {
            $decision = $automaticPolicy->check($tenant, $env);
            if (! $decision['allowed']) {
                $audit->record('serpro.procuracao.job', 'BLOCKED', null, [
                    'environment' => $env->value,
                    'client_id' => $this->clientId,
                    'automatic' => true,
                    'block_code' => $decision['code'],
                ], $this->actorUserId, $tenant->id);

                return;
            }
        }

        try {
            $result = $sync->syncOfficial($tenant, $client, $env, $this->actorUserId);
            $audit->record('serpro.procuracao.job', 'SUCCESS', $result['sync'], [
                'environment' => $env->value,
                'status' => $result['sync']->status->value,
                'client_id' => $this->clientId,
                'automatic' => $this->automatic,
            ], $this->actorUserId, $tenant->id);
        } catch (Throwable $e) {
            $audit->record('serpro.procuracao.job', 'FAILED', null, [
                'environment' => $env->value,
                'client_id' => $this->clientId,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ], $this->actorUserId, $this->tenantId);

            throw $e;
        }
    }

    public function tags(): array
    {
        return ['job:'.class_basename(self::class)];
    }

    public function failed(?Throwable $e): void
    {
        Log::warning('job.failed', [
            'job' => class_basename(self::class),
            'message' => LogSanitizer::scrubString((string) ($e?->getMessage() ?? '')),
        ]);
    }
}
