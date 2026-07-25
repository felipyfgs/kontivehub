<?php

namespace App\Jobs\Serpro;

use App\Enums\SerproEnvironment;
use App\Models\Client;
use App\Models\Office;
use App\Services\Audit\AuditLogger;
use App\Services\Integra\ClientProcuracaoAutoSyncPolicy;
use App\Services\Integra\ClientProcuracaoSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Sincronização oficial de procuração por cliente (Horizon, unique).
 */
final class SyncClientProcuracaoJob implements ShouldBeUnique, ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $uniqueFor = 180;

    public function __construct(
        public readonly int $officeId,
        public readonly int $clientId,
        public readonly string $environment,
        public readonly ?int $actorUserId = null,
        public readonly ?string $correlationId = null,
        public readonly bool $automatic = false,
    ) {
        $this->onQueue((string) config('serpro.queues.fiscal', 'fiscal'));
    }

    public function uniqueId(): string
    {
        return 'serpro-procuracao:'.$this->officeId.':'.$this->clientId.':'.$this->environment;
    }

    public function handle(
        ClientProcuracaoSyncService $sync,
        AuditLogger $audit,
        ClientProcuracaoAutoSyncPolicy $automaticPolicy,
    ): void {
        if ($this->batch()?->cancelled()) {
            return;
        }

        $office = Office::query()->findOrFail($this->officeId);
        $client = Client::query()
            ->where('office_id', $this->officeId)
            ->whereKey($this->clientId)
            ->firstOrFail();
        $env = SerproEnvironment::from(strtoupper($this->environment));

        if ($this->automatic) {
            $decision = $automaticPolicy->check($office, $env);
            if (! $decision['allowed']) {
                $audit->record('serpro.procuracao.job', 'BLOCKED', null, [
                    'environment' => $env->value,
                    'client_id' => $this->clientId,
                    'automatic' => true,
                    'block_code' => $decision['code'],
                ], $this->actorUserId, $office->id);

                return;
            }
        }

        try {
            $result = $sync->syncOfficial($office, $client, $env, $this->actorUserId);
            $audit->record('serpro.procuracao.job', 'SUCCESS', $result['snapshot'], [
                'environment' => $env->value,
                'status' => $result['snapshot']->status->value,
                'client_id' => $this->clientId,
                'automatic' => $this->automatic,
            ], $this->actorUserId, $office->id);
        } catch (Throwable $e) {
            $audit->record('serpro.procuracao.job', 'FAILED', null, [
                'environment' => $env->value,
                'client_id' => $this->clientId,
                'error' => mb_substr($e->getMessage(), 0, 200),
            ], $this->actorUserId, $this->officeId);

            throw $e;
        }
    }
}
