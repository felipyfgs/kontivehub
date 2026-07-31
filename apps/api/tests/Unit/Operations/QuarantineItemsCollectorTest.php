<?php

namespace Tests\Unit\Operations;

use App\Enums\DocumentAcquisitionSource;
use App\Enums\QuarantineReason;
use App\Enums\QuarantineResolutionStatus;
use App\Models\FiscalDocumentQuarantine;
use App\Models\Tenant;
use App\Services\Clients\CaptureEligibilityService;
use App\Services\Operations\Inbox\InboxCapabilities;
use App\Services\Operations\Inbox\InboxItemFactory;
use App\Services\Operations\Inbox\QuarantineItemsCollector;
use App\Support\CurrentTenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class QuarantineItemsCollectorTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_quarantine_uses_the_canonical_health_type_path(): void
    {
        $tenant = Tenant::factory()->create();
        app(CurrentTenant::class)->bindSystem($tenant);

        FiscalDocumentQuarantine::query()->create([
            'tenant_id' => $tenant->id,
            'sha256' => str_repeat('a', 64),
            'vault_object_id' => '01J00000000000000000000000',
            'byte_size' => 128,
            'reason' => QuarantineReason::UnmatchedIssuer,
            'source' => DocumentAcquisitionSource::ManualXml,
            'resolution_status' => QuarantineResolutionStatus::Open,
        ]);

        $collector = new QuarantineItemsCollector(
            new InboxItemFactory(new CaptureEligibilityService),
        );

        $item = $collector->collect($tenant->id, new InboxCapabilities)->sole();

        $this->assertSame(
            '/health/type/quarantine_unmatched_issuer',
            $item['links']['quarantine'],
        );
        $this->assertStringNotContainsString('?', $item['links']['quarantine']);
    }
}
