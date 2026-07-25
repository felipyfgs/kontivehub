<?php

namespace Tests\Unit\Integra\Eventos;

use App\Services\Integra\Eventos\EventosPjCodec;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class EventosPjCodecTest extends TestCase
{
    public function test_builds_one_explicit_event_field_without_fallback(): void
    {
        $this->assertSame(['evento' => 'E0601'], (new EventosPjCodec('evento'))->solicit('e0601'));
        $this->assertSame(['eventValue' => 'E0601'], (new EventosPjCodec('eventValue'))->solicit('E0601'));
    }

    public function test_rejects_unreconciled_field_before_egress(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('EVENTOS_PJ_CONTRACT_UNRECONCILED');

        (new EventosPjCodec('unknown'))->solicit('E0601');
    }

    public function test_obtain_always_uses_official_protocol_and_evento_shape(): void
    {
        $this->assertSame(
            ['protocolo' => 'protocol-1', 'evento' => 'E0601'],
            (new EventosPjCodec('eventValue'))->obtain(' protocol-1 ', 'e0601'),
        );
    }
}
