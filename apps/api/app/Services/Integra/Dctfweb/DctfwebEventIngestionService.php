<?php

namespace App\Services\Integra\Dctfweb;

use App\Models\Client;
use App\Models\FiscalCategory;
use App\Models\FiscalLastUpdateEvent;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalLastUpdateEventService;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Ingestão/dedupe de eventos DCTFWeb e reconciliação dirigida (9.1).
 * Não varre contribuintes sem evento — só enfileira o par contribuinte/competência afetado.
 */
final class DctfwebEventIngestionService
{
    public function __construct(
        private readonly FiscalLastUpdateEventService $events,
        private readonly DctfwebCompetenceResolver $competences,
        private readonly DctfwebDeclarationService $declarations,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array{
     *     event: FiscalLastUpdateEvent,
     *     duplicate: bool,
     *     run: ?FiscalMonitoringRun,
     *     period_key: string
     * }
     */
    public function ingestAndDirect(
        Office $office,
        Client $client,
        string $periodKey,
        string $eventType = DctfwebCodes::EVENT_ULTIMA_ATUALIZACAO,
        ?string $externalId = null,
        ?string $payloadDigest = null,
        ?CarbonImmutable $occurredAt = null,
        ?array $metadata = null,
        bool $enqueue = true,
        string $operationCode = DctfwebCodes::OP_MONITOR,
    ): array {
        if ((int) $client->office_id !== (int) $office->id) {
            throw new InvalidArgumentException('Cliente não pertence ao escritório ativo.');
        }

        $periodKey = $this->competences->normalizePeriodKey($periodKey);
        $competence = $this->competences->resolve(
            $office,
            $client,
            $periodKey,
            DctfwebCodes::CATEGORY_DCTFWEB,
        );

        // Garante projeção local mínima antes da reconciliação
        $this->declarations->findOrCreate($office, $client, $periodKey);

        $meta = array_merge($metadata ?? [], [
            'period_key' => $periodKey,
            'competence_id' => $competence->id,
            'module' => DctfwebCodes::MODULE,
        ]);

        // externalId inclui competência para dedupe por contribuinte+competência+evento
        $external = $externalId ?? sprintf('%s:%s:%s', $client->id, $periodKey, strtoupper($eventType));

        $result = $this->events->ingestAndDirect(
            office: $office,
            systemCode: DctfwebCodes::SYSTEM_DCTFWEB,
            eventType: $eventType,
            client: $client,
            serviceCode: DctfwebCodes::SERVICE_DCTFWEB,
            externalId: $external,
            payloadDigest: $payloadDigest,
            occurredAt: $occurredAt,
            metadata: $meta,
            enqueue: $enqueue,
            operationCode: $operationCode,
        );

        $run = $result['run'];
        if ($run !== null) {
            $category = FiscalCategory::query()->where('code', DctfwebCodes::CATEGORY_DCTFWEB)->first();
            $run->forceFill([
                'competence_id' => $competence->id,
                'fiscal_category_id' => $category?->id ?? $run->fiscal_category_id,
            ])->save();
            $run = $run->fresh();
        }

        return [
            'event' => $result['event'],
            'duplicate' => $result['duplicate'],
            'run' => $run,
            'period_key' => $periodKey,
        ];
    }
}
