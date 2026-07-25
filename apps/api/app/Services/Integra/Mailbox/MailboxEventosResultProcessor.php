<?php

namespace App\Services\Integra\Mailbox;

use App\Enums\MailboxEventItemClassification;
use App\Enums\MailboxEventProcessingStatus;
use App\Models\Client;
use App\Models\MailboxClientSyncState;
use App\Models\Office;
use App\Models\SerproEventosRun;
use App\Models\SerproEventosRunItem;
use App\Services\Integra\Eventos\EventosResultArtifactStore;
use App\Services\Integra\Eventos\EventosResultMatrixParser;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Normaliza E0601 e direciona LISTAR sem voltar ao SERPRO no retry local. */
final class MailboxEventosResultProcessor
{
    public const LOCAL_PENDING = 'PENDING';

    public const LOCAL_PROCESSING = 'PROCESSING';

    public const LOCAL_SUCCEEDED = 'SUCCEEDED';

    public const LOCAL_FAILED = 'FAILED';

    public function __construct(
        private readonly EventosResultArtifactStore $artifacts,
        private readonly EventosResultMatrixParser $parser,
        private readonly MailboxContributorBatchBuilder $contributors,
        private readonly MailboxEventService $events,
    ) {}

    public function process(SerproEventosRun $run): SerproEventosRun
    {
        if (! $run->isOneShotConsumed()) {
            throw new RuntimeException('EVENTOS_REMOTE_RESULT_NOT_RECEIVED');
        }
        if ($run->local_processing_status === self::LOCAL_SUCCEEDED) {
            return $run;
        }

        $run->forceFill(['local_processing_status' => self::LOCAL_PROCESSING])->save();

        try {
            $dados = $this->artifacts->load($run);
            $parsed = $this->parser->parse($dados);
            $clientMap = $this->contributors->clientMap((int) $run->office_id);
            $items = $this->normalize($run, $parsed, $clientMap);
            $this->direct($run, $items);

            $run->forceFill([
                'local_processing_status' => self::LOCAL_SUCCEEDED,
                'local_processed_at' => now(),
                'status' => SerproEventosRun::STATUS_SUCCEEDED,
                'error_code' => null,
                'error_message' => null,
                'result_summary' => array_merge($run->result_summary ?? [], [
                    'normalized_items_count' => count($items),
                ]),
            ])->save();
        } catch (\Throwable $e) {
            $run->forceFill([
                'local_processing_status' => self::LOCAL_FAILED,
                'status' => SerproEventosRun::STATUS_FAILED,
                'error_code' => str_starts_with($e->getMessage(), 'EVENTOS_')
                    ? mb_substr($e->getMessage(), 0, 80)
                    : 'EVENTOS_LOCAL_PROCESSING_FAILED',
                'error_message' => 'Resultado remoto preservado; processamento local pode ser retomado.',
            ])->save();
            throw $e;
        }

        return $run->fresh() ?? $run;
    }

    /**
     * @param  list<array{index:int,ni:?string,classification:MailboxEventItemClassification,event_date:?CarbonImmutable,error_code:?string}>  $parsed
     * @param  array<string,int>  $clientMap
     * @return list<SerproEventosRunItem>
     */
    private function normalize(SerproEventosRun $run, array $parsed, array $clientMap): array
    {
        return DB::transaction(function () use ($run, $parsed, $clientMap): array {
            $items = [];
            foreach ($parsed as $row) {
                $ni = $row['ni'];
                $classification = $row['classification'];
                $clientId = $ni !== null ? ($clientMap[$ni] ?? null) : null;
                if ($ni !== null && $clientId === null && $classification !== MailboxEventItemClassification::Malformed) {
                    $classification = MailboxEventItemClassification::Unmatched;
                }
                $fingerprintSource = $ni ?? 'malformed-index:'.$row['index'];
                $fingerprint = hash_hmac('sha256', $fingerprintSource, $this->hmacKey());

                $item = SerproEventosRunItem::query()->withoutGlobalScopes()->firstOrCreate(
                    [
                        'serpro_eventos_run_id' => $run->id,
                        'ni_fingerprint' => $fingerprint,
                    ],
                    [
                        'office_id' => $run->office_id,
                        'client_id' => $clientId,
                        'classification' => $classification,
                        'event_date' => $row['event_date'],
                        'processing_status' => MailboxEventProcessingStatus::Pending,
                        'error_code' => $row['error_code'],
                        'error_message' => null,
                    ],
                );
                if (! $item->wasRecentlyCreated) {
                    $item->forceFill([
                        'office_id' => $run->office_id,
                        'client_id' => $clientId,
                        'classification' => $classification,
                        'event_date' => $row['event_date'],
                        'error_code' => $row['error_code'],
                    ])->save();
                }
                $items[] = $item;
            }

            return $items;
        });
    }

    /** @param list<SerproEventosRunItem> $items */
    private function direct(SerproEventosRun $run, array $items): void
    {
        $today = CarbonImmutable::now((string) config('serpro.eventos.timezone', 'America/Sao_Paulo'))->startOfDay();
        $office = Office::query()->withoutGlobalScopes()->findOrFail($run->office_id);

        foreach ($items as $item) {
            if ($item->processing_status !== MailboxEventProcessingStatus::Pending) {
                continue;
            }
            if ($item->classification === MailboxEventItemClassification::AccessDenied && $item->client_id !== null) {
                $this->syncState($run, $item)->forceFill([
                    'authorization_status' => 'DENIED',
                    'last_error_code' => 'EVENTOS_ACCESS_DENIED',
                ])->save();
                $item->forceFill(['processing_status' => MailboxEventProcessingStatus::Ignored])->save();

                continue;
            }
            if ($item->classification !== MailboxEventItemClassification::EventDate || $item->client_id === null || $item->event_date === null) {
                $item->forceFill([
                    'processing_status' => $item->classification === MailboxEventItemClassification::Malformed
                        ? MailboxEventProcessingStatus::Failed
                        : MailboxEventProcessingStatus::Ignored,
                ])->save();

                continue;
            }

            $state = $this->syncState($run, $item);
            // Coluna DATE não representa instante UTC; reconstitua no fuso operacional.
            $eventDate = CarbonImmutable::createFromFormat(
                '!Y-m-d',
                $item->event_date->toDateString(),
                (string) config('serpro.eventos.timezone', 'America/Sao_Paulo'),
            );
            $pending = $state->pending_event_date === null || $eventDate->greaterThan($state->pending_event_date)
                ? $eventDate
                : $state->pending_event_date;
            $state->forceFill([
                'authorization_status' => 'AUTHORIZED',
                'last_event_observed_date' => $eventDate,
                'pending_event_date' => $pending,
                'last_error_code' => null,
                'last_error_message' => null,
            ])->save();

            if ($eventDate->greaterThanOrEqualTo($today)) {
                continue;
            }

            $client = Client::query()->withoutGlobalScopes()
                ->where('office_id', $run->office_id)
                ->find($item->client_id);
            if ($client === null || ! $client->is_active) {
                $item->forceFill([
                    'classification' => MailboxEventItemClassification::Unmatched,
                    'processing_status' => MailboxEventProcessingStatus::Ignored,
                ])->save();

                continue;
            }

            $directed = $this->events->ingestNewMessageEvent(
                office: $office,
                client: $client,
                externalEventId: sprintf('E0601:%s:%d', $eventDate->format('Y-m-d'), $client->id),
                payloadDigest: $run->result_payload_sha256,
                occurredAt: $eventDate,
                metadata: ['source' => 'EVENTOS_ATUALIZACAO', 'event_date' => $eventDate->format('Y-m-d')],
            );
            $directedRunId = $directed['run']?->id ?? $directed['event']->directed_run_id;
            $item->forceFill([
                'processing_status' => $directedRunId === null
                    ? MailboxEventProcessingStatus::Ignored
                    : MailboxEventProcessingStatus::Directed,
                'directed_run_id' => $directedRunId,
            ])->save();
        }
    }

    private function syncState(SerproEventosRun $run, SerproEventosRunItem $item): MailboxClientSyncState
    {
        return MailboxClientSyncState::query()->withoutGlobalScopes()->firstOrCreate([
            'office_id' => $run->office_id,
            'client_id' => $item->client_id,
        ]);
    }

    private function hmacKey(): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('EVENTOS_FINGERPRINT_KEY_MISSING');
        }

        return $key;
    }
}
