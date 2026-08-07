<?php

namespace Tests\Unit\Communication;

use App\DTO\Communication\GatewayEventData;
use App\Enums\Communication\GatewayEventType;
use DateTimeImmutable;
use InvalidArgumentException;
use Tests\TestCase;

final class GatewayMessageActionResultContractTest extends TestCase
{
    public function test_action_result_is_closed_and_requires_a_stable_failed_code(): void
    {
        $event = new GatewayEventData(
            gatewayEventId: 'gateway-action-result-0001',
            sessionId: 'session-0001',
            type: GatewayEventType::MessageActionResult,
            occurredAt: new DateTimeImmutable('2026-08-04T12:00:00Z'),
            payload: [
                'command_id' => 'command-action-0001',
                'action' => 'EDIT',
                'status' => 'FAILED',
                'provider_message_id' => 'provider-action-0001',
                'target_message_id' => 'provider-target-0001',
                'error_code' => 'ACTION_REJECTED',
            ],
        );

        $this->assertSame('ACTION_REJECTED', $event->payload['error_code']);
    }

    public function test_action_result_rejects_text_and_missing_or_unknown_failure_codes(): void
    {
        foreach ([
            ['status' => 'SUCCEEDED', 'error_code' => 'ACTION_REJECTED'],
            ['status' => 'FAILED'],
            ['status' => 'FAILED', 'error_code' => 'OTHER_FAILURE'],
            ['status' => 'SUCCEEDED', 'text' => 'não permitido'],
        ] as $invalid) {
            try {
                new GatewayEventData(
                    gatewayEventId: 'gateway-action-invalid-'.count($invalid),
                    sessionId: 'session-0001',
                    type: GatewayEventType::MessageActionResult,
                    occurredAt: new DateTimeImmutable('2026-08-04T12:00:00Z'),
                    payload: [
                        'command_id' => 'command-action-0001',
                        'action' => 'REVOKE',
                        'provider_message_id' => 'provider-action-0001',
                        'target_message_id' => 'provider-target-0001',
                        ...$invalid,
                    ],
                );
                $this->fail('O contrato deveria rejeitar o resultado inválido.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_action_result_requires_action_and_status_strings_from_allowlist(): void
    {
        foreach ([
            [],
            ['status' => 'SUCCEEDED'],
            ['action' => 'EDIT'],
            ['action' => 123, 'status' => 'SUCCEEDED'],
            ['action' => 'EDIT', 'status' => ['FAILED']],
            ['action' => 'COMPLETED', 'status' => 'SUCCEEDED'],
            ['action' => 'EDIT', 'status' => 'DONE'],
        ] as $index => $invalid) {
            try {
                new GatewayEventData(
                    gatewayEventId: 'gateway-action-invalid-'.($index + 10),
                    sessionId: 'session-0001',
                    type: GatewayEventType::MessageActionResult,
                    occurredAt: new DateTimeImmutable('2026-08-04T12:00:00Z'),
                    payload: [
                        'command_id' => 'command-action-0001',
                        'provider_message_id' => 'provider-action-0001',
                        'target_message_id' => 'provider-target-0001',
                        ...$invalid,
                    ],
                );
                $this->fail('O contrato deveria rejeitar action/status ausentes ou inválidos.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
