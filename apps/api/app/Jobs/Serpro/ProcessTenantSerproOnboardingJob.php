<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\TenantSerproOnboardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Onboarding SERPRO automatizado por escritório — Horizon, unique + lock no service.
 */
final class ProcessTenantSerproOnboardingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $environment,
        public readonly string $idempotencyKey,
        public readonly ?int $actorUserId = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function uniqueId(): string
    {
        return 'serpro-onboarding:'.$this->tenantId.':'.$this->environment.':'.$this->idempotencyKey;
    }

    public function handle(
        TenantSerproOnboardingService $onboarding,
        AuditLogger $audit,
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $env = SerproEnvironment::from(strtoupper($this->environment));

        try {
            $state = $onboarding->process(
                $tenant,
                $env,
                $this->idempotencyKey,
                $this->actorUserId,
                $this->correlationId,
            );

            $audit->record('serpro.onboarding.job', 'SUCCESS', $state, [
                'environment' => $env->value,
                'status' => $state->status->value,
                'last_step' => $state->last_step,
            ], $this->actorUserId, $tenant->id);
        } catch (Throwable $e) {
            $audit->record('serpro.onboarding.job', 'FAILED', null, [
                'environment' => $env->value,
                'error' => mb_substr($e->getMessage(), 0, 200),
                'tenant_id' => $this->tenantId,
            ], $this->actorUserId, $this->tenantId);

            throw $e;
        }
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
