<?php

namespace Tests\Unit\Communication;

use App\Enums\Communication\MessageStatus;
use Tests\TestCase;

class CommunicationMessageStatusTest extends TestCase
{
    public function test_receipts_never_regress_a_successful_status(): void
    {
        $status = MessageStatus::Queued
            ->merge(MessageStatus::Played)
            ->merge(MessageStatus::Read)
            ->merge(MessageStatus::Delivered)
            ->merge(MessageStatus::Sent)
            ->merge(MessageStatus::Failed);

        $this->assertSame(MessageStatus::Played, $status);
    }

    public function test_late_positive_evidence_resolves_ambiguous_or_failed_result(): void
    {
        $this->assertSame(MessageStatus::Delivered, MessageStatus::Unknown->merge(MessageStatus::Delivered));
        $this->assertSame(MessageStatus::Sent, MessageStatus::Failed->merge(MessageStatus::Sent));
        $this->assertSame(MessageStatus::Failed, MessageStatus::Failed->merge(MessageStatus::Accepted));
    }
}
