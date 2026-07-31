<?php

namespace Tests\Unit\Communication;

use App\Enums\Communication\GatewayEventType;
use App\Services\Communication\Events\GatewayEventBoundaryValidator;
use InvalidArgumentException;
use Tests\TestCase;

final class CommunicationGatewayEventBoundaryValidatorTest extends TestCase
{
    public function test_valid_event_is_mapped_to_the_internal_contract_data(): void
    {
        $event = app(GatewayEventBoundaryValidator::class)->validate(json_encode([
            'contract_version' => 'v1',
            'gateway_event_id' => 'gateway-alert-boundary-0001',
            'session_id' => 'session-boundary-0001',
            'type' => GatewayEventType::GatewayAlert->value,
            'occurred_at' => '2026-07-28T12:00:00-03:00',
            'payload' => [
                'code' => 'GATEWAY_UNAVAILABLE',
                'severity' => 'WARNING',
                'retryable' => true,
            ],
        ], JSON_THROW_ON_ERROR));

        $this->assertSame('gateway-alert-boundary-0001', $event->gatewayEventId);
        $this->assertSame('session-boundary-0001', $event->sessionId);
        $this->assertSame(GatewayEventType::GatewayAlert, $event->type);
        $this->assertSame('GATEWAY_UNAVAILABLE', $event->payload['code']);
    }

    public function test_invalid_json_schema_type_and_tenant_override_are_rejected(): void
    {
        $valid = [
            'contract_version' => 'v1',
            'gateway_event_id' => 'gateway-alert-boundary-0002',
            'session_id' => 'session-boundary-0002',
            'type' => GatewayEventType::GatewayAlert->value,
            'occurred_at' => '2026-07-28T12:00:00-03:00',
            'payload' => [
                'code' => 'GATEWAY_UNAVAILABLE',
                'severity' => 'WARNING',
                'retryable' => true,
            ],
        ];
        $invalidPayloads = [
            '{"contract_version":',
            json_encode(['unexpected'], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'type' => 'UNKNOWN_EVENT'], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'tenant_id' => 999], JSON_THROW_ON_ERROR),
            json_encode([...$valid, 'payload' => 'not-an-object'], JSON_THROW_ON_ERROR),
        ];
        $validator = app(GatewayEventBoundaryValidator::class);

        foreach ($invalidPayloads as $body) {
            try {
                $validator->validate($body);
                $this->fail('O boundary aceitou um evento fora do contrato.');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
