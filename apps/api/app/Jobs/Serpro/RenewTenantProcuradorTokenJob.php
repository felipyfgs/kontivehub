<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Enums\TermRePresentationStrategy;
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
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Renova token do procurador do tenant quando a estratégia permite reuso do Termo.
 */
final class RenewTenantProcuradorTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $timeout = 300;

    public int $uniqueFor = 120;

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
        return 'serpro-procurador-token-renew:'.$this->tenantId.':'.$this->environment;
    }

    public function handle(
        TenantSerproAuthorizationService $authorizations,
        AuditLogger $audit,
    ): void {
        $tenant = Tenant::query()->findOrFail($this->tenantId);
        $env = SerproEnvironment::from(strtoupper($this->environment));

        if ($authorizations->representationStrategy($env) !== TermRePresentationStrategy::ReuseStoredTerm) {
            $audit->record('serpro.authorization.token_renew_skipped', 'SUCCESS', null, [
                'tenant_id' => $tenant->id,
                'environment' => $env->value,
                'reason' => 'strategy_forbids_reuse',
            ], $this->actorUserId, $tenant->id);

            return;
        }

        try {
            // force: token ainda válido no skew window — sem force o refresh é no-op.
            $auth = $authorizations->refreshProcuradorToken(
                $tenant,
                $env,
                $this->actorUserId,
                force: true,
            );
            $audit->record('serpro.authorization.token_renew_auto', 'SUCCESS', $auth, [
                'environment' => $env->value,
                'status' => $auth->status->value,
            ], $this->actorUserId, $tenant->id);
        } catch (Throwable $e) {
            $audit->record('serpro.authorization.token_renew_auto', 'FAILED', null, [
                'environment' => $env->value,
                'error' => mb_substr($e->getMessage(), 0, 200),
                'tenant_id' => $tenant->id,
            ], $this->actorUserId, $tenant->id);

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
