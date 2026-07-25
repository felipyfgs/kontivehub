<?php

namespace App\Services\Integra;

use App\Enums\SerproEnvironment;
use App\Models\Office;
use App\Models\OfficeSerproAuthorization;
use App\Services\Serpro\SerproJobFlagGuard;

/**
 * Política exclusiva do modo automático. O fluxo manual não é alterado.
 * Nenhum método desta classe cria credenciais, autorizações ou snapshots.
 */
final class ClientProcuracaoAutoSyncPolicy
{
    public function __construct(private readonly SerproJobFlagGuard $jobFlags) {}

    public function configuredEnvironment(): ?SerproEnvironment
    {
        return SerproEnvironment::tryFrom(strtoupper((string) config(
            'serpro.procuracoes_scheduler.environment',
            'TRIAL',
        )));
    }

    /** @return array{allowed: bool, code: string} */
    public function check(Office $office, SerproEnvironment $environment): array
    {
        if (! (bool) config('serpro.procuracoes_scheduler.enabled', false)) {
            return ['allowed' => false, 'code' => 'SCHEDULER_DISABLED'];
        }

        if ($environment !== $this->configuredEnvironment()) {
            return ['allowed' => false, 'code' => 'ENVIRONMENT_NOT_CONFIGURED'];
        }

        $allowlist = array_map('intval', (array) config('serpro.procuracoes_scheduler.office_allowlist', []));
        if (! in_array((int) $office->id, $allowlist, true)) {
            return ['allowed' => false, 'code' => 'OFFICE_NOT_ALLOWLISTED'];
        }

        $flag = $this->jobFlags->assertAllowed('SyncClientProcuracaoJob', (int) $office->id);
        if (! $flag['allowed']) {
            return ['allowed' => false, 'code' => (string) $flag['code']];
        }

        $authorization = OfficeSerproAuthorization::query()
            ->where('office_id', $office->id)
            ->where('environment', $environment->value)
            ->first();

        if ($authorization === null || ! $authorization->status->allowsExternalCalls()) {
            return ['allowed' => false, 'code' => 'AUTHORIZATION_NOT_READY'];
        }

        return ['allowed' => true, 'code' => 'ALLOWED'];
    }
}
