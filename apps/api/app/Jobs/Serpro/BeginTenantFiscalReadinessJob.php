<?php

namespace App\Jobs\Serpro;

use App\Models\Client;
use App\Models\Tenant;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/** Materializa o lote oficial de procurações de todos os clientes ativos. */
final class BeginTenantFiscalReadinessJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;


    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $environment,
        public readonly string $onboardingIdempotencyKey,
        public readonly ?int $actorUserId = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function uniqueId(): string
    {
        return 'tenant-fiscal-readiness:'.$this->tenantId.':'.$this->environment.':'.$this->onboardingIdempotencyKey;
    }

    public function handle(): void
    {
        Tenant::query()->findOrFail($this->tenantId);
        $jobs = Client::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($clientId): SyncClientProcuracaoJob => new SyncClientProcuracaoJob(
                tenantId: $this->tenantId,
                clientId: (int) $clientId,
                environment: $this->environment,
                actorUserId: $this->actorUserId,
                correlationId: $this->correlationId,
                automatic: false,
            ))
            ->all();

        if ($jobs === []) {
            FinalizeTenantFiscalReadinessJob::dispatch(
                $this->tenantId,
                $this->environment,
                $this->onboardingIdempotencyKey,
                $this->actorUserId,
                $this->correlationId,
            );

            return;
        }

        $tenantId = $this->tenantId;
        $environment = $this->environment;
        $idempotencyKey = $this->onboardingIdempotencyKey;
        $actorUserId = $this->actorUserId;
        $correlationId = $this->correlationId;

        Bus::batch($jobs)
            ->name("tenant-fiscal-readiness:{$tenantId}:{$environment}")
            ->allowFailures()
            ->finally(static function (Batch $batch) use (
                $tenantId,
                $environment,
                $idempotencyKey,
                $actorUserId,
                $correlationId,
            ): void {
                FinalizeTenantFiscalReadinessJob::dispatch(
                    $tenantId,
                    $environment,
                    $idempotencyKey,
                    $actorUserId,
                    $correlationId,
                    $batch->id,
                );
            })
            ->onQueue((string) config('serpro.queues.fiscal', 'fiscal'))
            ->dispatch();
    }

    public function tags(): array
    {
        return ['job:'.class_basename(static::class)];
    }

    public function failed(?\Throwable $e): void
    {
        \Illuminate\Support\Facades\Log::warning('job.failed', [
            'job' => class_basename(static::class),
            'message' => \App\Support\LogSanitizer::scrubString((string) ($e?->getMessage() ?? '')),
        ]);
    }
}
