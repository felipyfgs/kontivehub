<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Services\Integra\OfficeSerproOnboardingService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

final class FinalizeOfficeFiscalReadinessJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $officeId,
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
        return 'office-fiscal-finalize:'.$this->officeId.':'.$this->environment.':'.$this->onboardingIdempotencyKey;
    }

    public function handle(OfficeSerproOnboardingService $onboarding): void
    {
        $onboarding->finalizeReadiness(
            Office::query()->findOrFail($this->officeId),
            SerproEnvironment::from(strtoupper($this->environment)),
            $this->onboardingIdempotencyKey,
            $this->actorUserId,
            $this->correlationId,
            $this->batchId,
        );
    }
}
