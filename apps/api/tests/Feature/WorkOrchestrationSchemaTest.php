<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Office;
use App\Models\OperationalProcess;
use App\Models\ProcessTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WorkOrchestrationSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_work_orchestration_metadata_is_additive_and_casted(): void
    {
        $this->assertTrue(Schema::hasColumns('process_templates', [
            'catalog_key',
            'catalog_version',
            'monitoring_module_key',
            'audience_rules',
        ]));
        $this->assertTrue(Schema::hasColumn('operational_processes', 'monitoring_module_key'));

        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $template = ProcessTemplate::factory()->create([
            'office_id' => $office->id,
            'catalog_key' => 'PGDAS_MENSAL',
            'catalog_version' => 1,
            'monitoring_module_key' => 'PGDASD',
            'audience_rules' => [
                'tax_regimes' => ['SIMPLES_NACIONAL'],
                'category_match' => 'ANY',
            ],
        ]);
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
            'process_template_id' => $template->id,
            'monitoring_module_key' => 'PGDASD',
        ]);

        $this->assertSame(1, $template->fresh()->catalog_version);
        $this->assertSame(['SIMPLES_NACIONAL'], $template->fresh()->audience_rules['tax_regimes']);
        $this->assertTrue($client->operationalProcesses()->whereKey($process->id)->exists());
    }

    public function test_existing_manual_records_keep_nullable_defaults(): void
    {
        $office = Office::factory()->create();
        $client = Client::factory()->forOffice($office)->create();
        $template = ProcessTemplate::factory()->create(['office_id' => $office->id]);
        $process = OperationalProcess::factory()->create([
            'office_id' => $office->id,
            'client_id' => $client->id,
        ]);

        $this->assertNull($template->catalog_key);
        $this->assertNull($template->catalog_version);
        $this->assertNull($template->monitoring_module_key);
        $this->assertNull($template->audience_rules);
        $this->assertNull($process->monitoring_module_key);
    }
}
