<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\OfficeSerproOnboardingService;
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
final class ProcessOfficeSerproOnboardingJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $officeId,
        public readonly string $environment,
        public readonly string $idempotencyKey,
        public readonly ?int $actorUserId = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function uniqueId(): string
    {
        return 'serpro-onboarding:'.$this->officeId.':'.$this->environment.':'.$this->idempotencyKey;
    }

    public function handle(
        OfficeSerproOnboardingService $onboarding,
        AuditLogger $audit,
    ): void {
        $office = Office::query()->findOrFail($this->officeId);
        $env = SerproEnvironment::from(strtoupper($this->environment));

        try {
            $state = $onboarding->process(
                $office,
                $env,
                $this->idempotencyKey,
                $this->actorUserId,
                $this->correlationId,
            );

            $audit->record('serpro.onboarding.job', 'SUCCESS', $state, [
                'environment' => $env->value,
                'status' => $state->status->value,
                'last_step' => $state->last_step,
            ], $this->actorUserId, $office->id);
        } catch (Throwable $e) {
            $audit->record('serpro.onboarding.job', 'FAILED', null, [
                'environment' => $env->value,
                'error' => mb_substr($e->getMessage(), 0, 200),
                'office_id' => $this->officeId,
            ], $this->actorUserId, $this->officeId);

            throw $e;
        }
    }
}
