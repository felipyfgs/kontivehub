<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Models\Tenant;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\TenantSerproAuthorizationService;
use App\Support\LogSanitizer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renovação idempotente de token/ETag do procurador (Horizon).
 */
final class RefreshTenantProcuradorTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    public int $uniqueFor = 300;

    public function __construct(
        public readonly int $tenantId,
        public readonly string $environment,
        public readonly ?int $actorUserId = null,
        public readonly ?string $correlationId = null,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function uniqueId(): string
    {
        return 'serpro-token-refresh:'.$this->tenantId.':'.$this->environment;
    }

    public function handle(
        TenantSerproAuthorizationService $authorizations,
        AuditLogger $audit,
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $env = SerproEnvironment::from(strtoupper($this->environment));
        $lock = Cache::lock(sprintf('serpro:token-refresh:%d:%s', $this->tenantId, $env->value), 90);

        if (! $lock->get()) {
            return;
        }

        try {
            $auth = $authorizations->refreshProcuradorToken($tenant, $env, $this->actorUserId);
            $audit->record('serpro.authorization.token_refresh_job', 'SUCCESS', $auth, [
                'environment' => $env->value,
                'status' => $auth->status->value,
            ], $this->actorUserId, $tenant->id);
        } catch (Throwable $e) {
            $audit->record('serpro.authorization.token_refresh_job', 'FAILED', null, [
                'environment' => $env->value,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ], $this->actorUserId, $this->tenantId);

            throw $e;
        } finally {
            $lock->release();
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
