<?php

namespace Tests\Unit\Integra\Mailbox;

use Tests\TestCase;

class MailboxEconomicConfigTest extends TestCase
{
    public function test_defaults_are_economic_and_fail_closed(): void
    {
        $this->assertFalse(config('fiscal_monitoring.mailbox.economic_monitoring.enabled'));
        $this->assertSame('ECONOMICO', config('fiscal_monitoring.mailbox.economic_monitoring.default_mode'));
        $this->assertSame(30, config('fiscal_monitoring.mailbox.economic_monitoring.reconciliation_days'));
        $this->assertSame(0, config('fiscal_monitoring.mailbox.max_detail_fetches_per_sync'));
        $this->assertSame('evento', config('serpro.eventos.pj_event_field'));
    }
}
