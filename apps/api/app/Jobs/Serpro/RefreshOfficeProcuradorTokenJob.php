<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\OfficeSerproAuthorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Renovação idempotente de token/ETag do procurador (Horizon).
 */
final class RefreshOfficeProcuradorTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $officeId,
        public readonly string $environment,
        public readonly ?int $actorUserId = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function uniqueId(): string
    {
        return 'serpro-token-refresh:'.$this->officeId.':'.$this->environment;
    }

    public function handle(
        OfficeSerproAuthorizationService $authorizations,
        AuditLogger $audit,
    ): void {
        $office = Office::query()->findOrFail($this->officeId);
        $env = SerproEnvironment::from(strtoupper($this->environment));
        $lock = Cache::lock(sprintf('serpro:token-refresh:%d:%s', $this->officeId, $env->value), 90);

        if (! $lock->get()) {
            return;
        }

        try {
            $auth = $authorizations->refreshProcuradorToken($office, $env, $this->actorUserId);
            $audit->record('serpro.authorization.token_refresh_job', 'SUCCESS', $auth, [
                'environment' => $env->value,
                'status' => $auth->status->value,
            ], $this->actorUserId, $office->id);
        } catch (Throwable $e) {
            $audit->record('serpro.authorization.token_refresh_job', 'FAILED', null, [
                'environment' => $env->value,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ], $this->actorUserId, $this->officeId);

            throw $e;
        } finally {
            $lock->release();
        }
    }
}
