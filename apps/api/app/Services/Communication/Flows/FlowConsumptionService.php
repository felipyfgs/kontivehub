<?php

namespace App\Services\Communication\Flows;

use App\Models\CommunicationFlowConsumption;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

final class FlowConsumptionService
{
    /**
     * @return bool true se consumiu agora; false se já existia (no-op)
     */
    public function consumeOnce(
        int $tenantId,
        string $eventKey,
        ?int $runId = null,
        ?int $conversationId = null,
        ?string $eventDigest = null,
    ): bool {
        try {
            return DB::transaction(function () use ($tenantId, $eventKey, $runId, $conversationId, $eventDigest): bool {
                $existing = CommunicationFlowConsumption::query()
                    ->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('event_key', $eventKey)
                    ->lockForUpdate()
                    ->first();
                if ($existing !== null) {
                    return false;
                }

                CommunicationFlowConsumption::query()->withoutGlobalScopes()->create([
                    'tenant_id' => $tenantId,
                    'run_id' => $runId,
                    'conversation_id' => $conversationId,
                    'event_key' => $eventKey,
                    'event_digest' => $eventDigest,
                    'consumed_at' => now(),
                ]);

                return true;
            });
        } catch (QueryException $exception) {
            if ($this->isUniqueViolation($exception)) {
                return false;
            }

            throw $exception;
        }
    }

    public function wasConsumed(int $tenantId, string $eventKey): bool
    {
        return CommunicationFlowConsumption::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('event_key', $eventKey)
            ->exists();
    }

    private function isUniqueViolation(QueryException $exception): bool
    {
        $sqlState = (string) ($exception->errorInfo[0] ?? '');
        $driverCode = (string) ($exception->errorInfo[1] ?? '');

        return $sqlState === '23505' || $driverCode === '19' || str_contains(mb_strtolower($exception->getMessage()), 'unique');
    }
}
