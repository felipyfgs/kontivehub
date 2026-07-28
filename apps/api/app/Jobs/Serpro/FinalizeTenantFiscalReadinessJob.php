<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Models\Tenant;
use App\Services\Integra\TenantSerproOnboardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FinalizeTenantFiscalReadinessJob implements ShouldBeUnique, ShouldQueue
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
        public readonly ?string $batchId = null,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function uniqueId(): string
    {
        return 'tenant-fiscal-finalize:'.$this->tenantId.':'.$this->environment.':'.$this->onboardingIdempotencyKey;
    }

    public function handle(TenantSerproOnboardingService $onboarding): void
    {
        $onboarding->finalizeReadiness(
            Tenant::query()->findOrFail($this->tenantId),
            SerproEnvironment::from(strtoupper($this->environment)),
            $this->onboardingIdempotencyKey,
            $this->actorUserId,
            $this->correlationId,
            $this->batchId,
        );
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
