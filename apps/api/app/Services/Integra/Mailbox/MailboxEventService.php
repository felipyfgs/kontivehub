<?php

namespace App\Services\Integra\Mailbox;

use App\Models\Client;
use App\Models\FiscalLastUpdateEvent;
use App\Models\FiscalMonitoringRun;
use App\Models\Office;
use App\Services\FiscalMonitoring\FiscalLastUpdateEventService;
use Carbon\CarbonImmutable;

/**
 * Ingestão de eventos/indicadores de nova mensagem → consulta idempotente da Caixa Postal.
 */
final class MailboxEventService
{
    public const SYSTEM = 'INTEGRA_CAIXAPOSTAL';

    public const SERVICE_LIST = 'CAIXA_POSTAL';

    public const OPERATION_LIST = 'LISTAR';

    public const EVENT_NEW_MESSAGE = 'NOVA_MENSAGEM';

    public function __construct(
        private readonly FiscalLastUpdateEventService $events,
    ) {}

    /**
     * @param  array<string, mixed>|null  $metadata  sanitizado (sem corpo)
     * @return array{event: FiscalLastUpdateEvent, duplicate: bool, run: ?FiscalMonitoringRun}
     */
    public function ingestNewMessageEvent(
        Office $office,
        Client $client,
        ?string $externalEventId = null,
        ?string $payloadDigest = null,
        ?CarbonImmutable $occurredAt = null,
        ?array $metadata = null,
        bool $enqueue = true,
    ): array {
        // Metadados: só chaves seguras
        $safeMeta = $this->sanitizeMetadata($metadata);

        return $this->events->ingestAndDirect(
            office: $office,
            systemCode: self::SYSTEM,
            eventType: self::EVENT_NEW_MESSAGE,
            client: $client,
            serviceCode: self::SERVICE_LIST,
            externalId: $externalEventId,
            payloadDigest: $payloadDigest,
            occurredAt: $occurredAt,
            metadata: $safeMeta,
            enqueue: $enqueue,
            operationCode: self::OPERATION_LIST,
        );
    }

    /**
     * @param  array<string, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function sanitizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $blocked = [
            'body', 'content', 'subject', 'attachment', 'attachments',
            'token', 'password', 'pfx', 'xml', 'raw',
        ];
        $out = [];
        foreach ($metadata as $key => $value) {
            $lower = strtolower((string) $key);
            foreach ($blocked as $b) {
                if (str_contains($lower, $b)) {
                    continue 2;
                }
            }
            if (is_string($value) && mb_strlen($value) > 200) {
                $value = mb_substr($value, 0, 200);
            }
            if (is_scalar($value) || $value === null) {
                $out[$key] = $value;
            }
        }

        return $out;
    }
}
