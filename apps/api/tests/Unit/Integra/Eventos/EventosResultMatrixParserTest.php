<?php

namespace Tests\Unit\Integra\Eventos;

use App\Enums\MailboxEventItemClassification;
use App\Services\Integra\Eventos\EventosResultMatrixParser;
use Tests\TestCase;

class EventosResultMatrixParserTest extends TestCase
{
    public function test_parses_empty_denied_date_and_alphanumeric_ni(): void
    {
        $items = (new EventosResultMatrixParser)->parse([
            ['11222333000181', ''],
            ['11444777000161', 'x'],
            ['ABCDEF12000195', '260720'],
        ]);

        $this->assertSame(MailboxEventItemClassification::NoEvent, $items[0]['classification']);
        $this->assertSame(MailboxEventItemClassification::AccessDenied, $items[1]['classification']);
        $this->assertSame(MailboxEventItemClassification::EventDate, $items[2]['classification']);
        $this->assertSame('ABCDEF12000195', $items[2]['ni']);
        $this->assertSame('2026-07-20', $items[2]['event_date']?->toDateString());
    }

    public function test_isolates_malformed_rows(): void
    {
        $items = (new EventosResultMatrixParser)->parse([
            ['11222333000181'],
            ['short', '260720'],
            ['11222333000181', '999999'],
            ['11444777000161', ''],
        ]);

        $this->assertSame(MailboxEventItemClassification::Malformed, $items[0]['classification']);
        $this->assertSame(MailboxEventItemClassification::Malformed, $items[1]['classification']);
        $this->assertSame(MailboxEventItemClassification::Malformed, $items[2]['classification']);
        $this->assertSame(MailboxEventItemClassification::NoEvent, $items[3]['classification']);
    }

    public function test_invalid_calendar_date_is_malformed_instead_of_throwing(): void
    {
        $items = (new EventosResultMatrixParser)->parse([['11222333000181', '991332']]);

        $this->assertSame(MailboxEventItemClassification::Malformed, $items[0]['classification']);
        $this->assertSame('EVENTOS_MATRIX_DATE_INVALID', $items[0]['error_code']);
    }

    public function test_accepts_escaped_json_matrix(): void
    {
        $items = (new EventosResultMatrixParser)->parse('[["11222333000181","260720"]]');

        $this->assertCount(1, $items);
        $this->assertSame(MailboxEventItemClassification::EventDate, $items[0]['classification']);
    }
}
