<?php

namespace App\Console\Commands;

use App\Actions\Communication\IngestGatewayEventAction;
use App\Contracts\GatewayEventQueue;
use App\Exceptions\GatewayEventConflictException;
use App\Exceptions\WhatsAppPeerCorrelationConflictException;
use App\Services\Communication\Transport\HttpTransport;
use Illuminate\Console\Command;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class ConsumeCommunicationGatewayEventsCommand extends Command
{
    public const HEALTHCHECK_FILE = '/tmp/kontivehub-communication-events-heartbeat';

    protected $signature = 'communication:consume-gateway-events {--once : Processa no máximo uma entrega disponível}';

    protected $description = 'Consome eventos duráveis do WhatsApp no NATS JetStream';

    private int $lastHeartbeatAt = 0;

    public function handle(
        GatewayEventQueue $queue,
        IngestGatewayEventAction $action,
        HttpTransport $gateway,
    ): int {
        if (! config('communication.enabled') || ! config('communication.gateway.enabled')) {
            $this->components->warn('Comunicação ou gateway desabilitado.');

            return self::SUCCESS;
        }

        while (true) {
            $this->heartbeat();
            $delivery = $queue->next(1.0);
            if ($delivery === null) {
                if ((bool) $this->option('once')) {
                    return self::SUCCESS;
                }

                continue;
            }

            try {
                $action->execute($delivery->body);
                $spoolId = $this->spoolId($delivery->body);
                if ($spoolId !== null) {
                    $gateway->acknowledgeMedia($spoolId);
                }
                $delivery->ack();
            } catch (InvalidArgumentException|GatewayEventConflictException|WhatsAppPeerCorrelationConflictException) {
                $delivery->term();
            } catch (Throwable $error) {
                report($error);
                $delivery->nack(1.0);
            }

            if ((bool) $this->option('once')) {
                return self::SUCCESS;
            }
        }
    }

    private function heartbeat(): void
    {
        $now = time();
        if ($now - $this->lastHeartbeatAt < 15) {
            return;
        }
        if (! @touch(self::HEALTHCHECK_FILE, $now)) {
            throw new RuntimeException('Não foi possível atualizar o heartbeat do consumidor de comunicação.');
        }
        $this->lastHeartbeatAt = $now;
    }

    private function spoolId(string $body): ?string
    {
        $event = json_decode($body, true);
        $spoolId = is_array($event) && is_array($event['payload'] ?? null)
            ? ($event['payload']['spool_id'] ?? null)
            : null;

        return is_string($spoolId)
            && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{7,127}$/', $spoolId) === 1
                ? $spoolId
                : null;
    }
}
