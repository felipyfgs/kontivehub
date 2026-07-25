<?php

namespace App\Jobs\Serpro;

use App\Models\Client;
use App\Models\Office;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/** Materializa o lote oficial de procurações de todos os clientes ativos. */
final class BeginOfficeFiscalReadinessJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $uniqueFor = 600;

    public function __construct(
        public readonly int $officeId,
        public readonly string $environment,
        public readonly string $onboardingIdempotencyKey,
        public readonly ?int $actorUserId = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function uniqueId(): string
    {
        return 'office-fiscal-readiness:'.$this->officeId.':'.$this->environment.':'.$this->onboardingIdempotencyKey;
    }

    public function handle(): void
    {
        Office::query()->findOrFail($this->officeId);
        $jobs = Client::query()
            ->withoutGlobalScopes()
            ->where('office_id', $this->officeId)
            ->where('is_active', true)
            ->orderBy('id')
            ->pluck('id')
            ->map(fn ($clientId): SyncClientProcuracaoJob => new SyncClientProcuracaoJob(
                officeId: $this->officeId,
                clientId: (int) $clientId,
                environment: $this->environment,
                actorUserId: $this->actorUserId,
                correlationId: $this->correlationId,
                automatic: false,
            ))
            ->all();

        if ($jobs === []) {
            FinalizeOfficeFiscalReadinessJob::dispatch(
                $this->officeId,
                $this->environment,
                $this->onboardingIdempotencyKey,
                $this->actorUserId,
                $this->correlationId,
            );

            return;
        }

        $officeId = $this->officeId;
        $environment = $this->environment;
        $idempotencyKey = $this->onboardingIdempotencyKey;
        $actorUserId = $this->actorUserId;
        $correlationId = $this->correlationId;

        Bus::batch($jobs)
            ->name("office-fiscal-readiness:{$officeId}:{$environment}")
            ->allowFailures()
            ->finally(static function (Batch $batch) use (
                $officeId,
                $environment,
                $idempotencyKey,
                $actorUserId,
                $correlationId,
            ): void {
                FinalizeOfficeFiscalReadinessJob::dispatch(
                    $officeId,
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
}
