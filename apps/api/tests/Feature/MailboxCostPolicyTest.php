<?php

namespace Tests\Feature;

use App\Enums\SerproConsumptionClass;
use App\Models\MailboxMonitoringSetting;
use App\Models\Office;
use App\Models\SerproPriceTier;
use App\Models\SerproPriceVersion;
use App\Services\Integra\Mailbox\MailboxCostPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MailboxCostPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_unknown_price_blocks_and_shadow_price_is_disclosed(): void
    {
        $office = Office::factory()->create();
        SerproPriceVersion::query()->update(['is_active' => false]);
        $policy = app(MailboxCostPolicy::class);
        $this->assertSame('PRICE_UNKNOWN', $policy->preview($office->id, 'LISTAR')['block_reason']);

        $version = SerproPriceVersion::query()->create([
            'version_code' => 'shadow-mailbox-1',
            'name' => 'Tabela estimada',
            'effective_from' => now()->subDay(),
            'is_active' => true,
            'currency' => 'BRL',
            'eligibility' => 'SHADOW',
            'authorizes_production' => false,
        ]);
        SerproPriceTier::query()->create([
            'price_version_id' => $version->id,
            'consumption_class' => SerproConsumptionClass::Consulta,
            'system_code' => 'INTEGRA_CAIXAPOSTAL',
            'service_code' => 'CAIXA_POSTAL',
            'operation_code' => 'LISTAR',
            'min_quantity' => 1,
            'max_quantity' => null,
            'unit_cost_micros' => 500_000,
            'sort_order' => 1,
        ]);

        $preview = $policy->preview($office->id, 'LISTAR', 2);
        $this->assertTrue($preview['allowed']);
        $this->assertSame('SHADOW', $preview['price_source']);
        $this->assertSame(1_000_000, $preview['estimated_cost_micros']);

        MailboxMonitoringSetting::query()->create([
            'office_id' => $office->id,
            'monthly_budget_micros' => 999_999,
        ]);
        $blocked = $policy->preview($office->id, 'LISTAR', 2);
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('MAILBOX_MONTHLY_BUDGET_EXCEEDED', $blocked['block_reason']);
    }
}
