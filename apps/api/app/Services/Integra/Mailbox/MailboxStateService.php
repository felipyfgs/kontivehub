<?php

namespace App\Services\Integra\Mailbox;

use App\DTO\Mailbox\DteIndicatorResult;
use App\Enums\MailboxDteStatus;
use App\Enums\MailboxMessagesConsultStatus;
use App\Enums\MailboxSource;
use App\Models\Client;
use App\Models\MailboxContributorState;
use App\Models\Office;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Estado por contribuinte: DTE e mensagens com proveniência própria.
 */
final class MailboxStateService
{
    public function getOrCreate(Office $office, Client $client): MailboxContributorState
    {
        $state = MailboxContributorState::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $client->id)
            ->first();

        if ($state !== null) {
            return $state;
        }

        return MailboxContributorState::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'dte_status' => MailboxDteStatus::Unknown,
            'messages_status' => MailboxMessagesConsultStatus::Unknown,
        ]);
    }

    public function applyDte(
        Office $office,
        Client $client,
        DteIndicatorResult $result,
        ?int $runId = null,
    ): MailboxContributorState {
        return DB::transaction(function () use ($office, $client, $result, $runId) {
            $state = MailboxContributorState::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();

            if ($state === null) {
                $state = MailboxContributorState::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                ]);
            }

            // Atualiza SOMENTE campos DTE — messages_* intactos
            $status = $result->success ? $result->status : MailboxDteStatus::Error;
            $state->forceFill([
                'dte_status' => $status,
                'dte_source' => MailboxSource::DteIndicator,
                'dte_observed_at' => CarbonImmutable::now(),
                'last_dte_run_id' => $runId,
            ])->save();

            return $state->fresh();
        });
    }

    public function findForOffice(Office $office, int $clientId): ?MailboxContributorState
    {
        return MailboxContributorState::query()
            ->withoutGlobalScopes()
            ->where('office_id', $office->id)
            ->where('client_id', $clientId)
            ->first();
    }

    public function applyNewMessagesIndicator(
        Office $office,
        Client $client,
        int $indicator,
        ?int $runId = null,
    ): MailboxContributorState {
        if (! in_array($indicator, [0, 1, 2], true)) {
            throw new \InvalidArgumentException('Indicador de mensagens novas inválido.');
        }

        return DB::transaction(function () use ($office, $client, $indicator, $runId) {
            $state = MailboxContributorState::query()
                ->withoutGlobalScopes()
                ->where('office_id', $office->id)
                ->where('client_id', $client->id)
                ->lockForUpdate()
                ->first();
            if ($state === null) {
                $state = MailboxContributorState::query()->create([
                    'office_id' => $office->id,
                    'client_id' => $client->id,
                    'dte_status' => MailboxDteStatus::Unknown,
                    'messages_status' => MailboxMessagesConsultStatus::Unknown,
                ]);
            }

            // Diagnóstico de mensagens ainda não abertas: não altera messages_*.
            $state->forceFill([
                'new_messages_indicator' => $indicator,
                'new_messages_indicator_observed_at' => CarbonImmutable::now(),
                'last_new_messages_indicator_run_id' => $runId,
            ])->save();

            return $state->fresh();
        });
    }
}
