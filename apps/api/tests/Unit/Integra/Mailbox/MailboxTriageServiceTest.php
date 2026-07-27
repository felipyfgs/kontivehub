<?php

namespace Tests\Unit\Integra\Mailbox;

use App\Enums\MailboxSource;
use App\Enums\MailboxTriageStatus;
use App\Enums\TenantRole;
use App\Models\Client;
use App\Models\MailboxMessage;
use App\Models\Tenant;
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
        $tenant = Tenant::factory()->create();
        $actor = User::factory()->forTenant($tenant, TenantRole::TenantUser)->create();
        $client = Client::factory()->for($tenant)->create();
        $externalId = 'EXT-TRIAGE';

        $message = MailboxMessage::query()->create([
            'tenant_id' => $tenant->id,
            'client_id' => $client->id,
            'external_id' => $externalId,
            'message_hash' => MailboxIdempotency::messageHash((int) $tenant->id, (int) $client->id, $externalId),
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
            $tenant,
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
