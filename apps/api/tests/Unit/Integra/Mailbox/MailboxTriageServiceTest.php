<?php

namespace Tests\Unit\Integra\Mailbox;

use App\Enums\MailboxSource;
use App\Enums\MailboxTriageStatus;
use App\Enums\OfficeRole;
use App\Models\Client;
use App\Models\MailboxMessage;
use App\Models\Office;
use App\Models\User;
use App\Services\Integra\Mailbox\MailboxIdempotency;
use App\Services\Integra\Mailbox\MailboxTriageService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxTriageServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_triage_does_not_change_official_read_indicator(): void
    {
        $office = Office::factory()->create();
        $actor = User::factory()->forOffice($office, OfficeRole::Operator)->create();
        $client = Client::factory()->for($office)->create();
        $externalId = 'EXT-TRIAGE';

        $message = MailboxMessage::query()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'external_id' => $externalId,
            'message_hash' => MailboxIdempotency::messageHash((int) $office->id, (int) $client->id, $externalId),
            'source' => MailboxSource::CaixaPostal,
            'sensitivity_class' => 'FISCAL_RESTRICTED',
            'subject_preview' => 'Triagem',
            'official_read_indicator' => false,
            'official_read_observed_at' => null,
            'triage_status' => MailboxTriageStatus::New,
            'has_body' => false,
            'attachment_count' => 0,
        ]);

        $updated = app(MailboxTriageService::class)->update(
            $office,
            $message,
            MailboxTriageStatus::Resolved,
            $actor,
            'Resolvido em teste',
        );

        $this->assertSame(MailboxTriageStatus::Resolved, $updated->triage_status);
        $this->assertFalse($updated->official_read_indicator);
        $this->assertNull($updated->official_read_observed_at);
        $this->assertSame('Resolvido em teste', $updated->triage_note);
    }
}
