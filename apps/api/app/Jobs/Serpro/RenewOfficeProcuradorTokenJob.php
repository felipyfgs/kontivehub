<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Enums\TermRePresentationStrategy;
use App\Models\Office;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\OfficeSerproAuthorizationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Renova token do procurador do office quando a estratégia permite reuso do Termo.
 */
final class RenewOfficeProcuradorTokenJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;

    public int $uniqueFor = 120;

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
        return 'serpro-procurador-token-renew:'.$this->officeId.':'.$this->environment;
    }

    public function handle(
        OfficeSerproAuthorizationService $authorizations,
        AuditLogger $audit,
    ): void {
        $office = Office::query()->findOrFail($this->officeId);
        $env = SerproEnvironment::from(strtoupper($this->environment));

        if ($authorizations->representationStrategy($env) !== TermRePresentationStrategy::ReuseStoredTerm) {
            $audit->record('serpro.authorization.token_renew_skipped', 'SUCCESS', null, [
                'office_id' => $office->id,
                'environment' => $env->value,
                'reason' => 'strategy_forbids_reuse',
            ], $this->actorUserId, $office->id);

            return;
        }

        try {
            // force: token ainda válido no skew window — sem force o refresh é no-op.
            $auth = $authorizations->refreshProcuradorToken(
                $office,
                $env,
                $this->actorUserId,
                force: true,
            );
            $audit->record('serpro.authorization.token_renew_auto', 'SUCCESS', $auth, [
                'environment' => $env->value,
                'status' => $auth->status->value,
            ], $this->actorUserId, $office->id);
        } catch (Throwable $e) {
            $audit->record('serpro.authorization.token_renew_auto', 'FAILED', null, [
                'environment' => $env->value,
                'error' => mb_substr($e->getMessage(), 0, 200),
                'office_id' => $office->id,
            ], $this->actorUserId, $office->id);

            throw $e;
        }
    }
}
