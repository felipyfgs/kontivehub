<?php

namespace Database\Factories;

use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Models\Tenant;
use App\Models\TenantDistributionCursor;
use App\Models\TenantFiscalIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDistributionCursor>
 */
class TenantDistributionCursorFactory extends Factory
{
    protected $model = TenantDistributionCursor::class;

    public function definition(): array
    {
        $cnpj = '11222333000181';

        return [
            'tenant_id' => Tenant::factory(),
            'tenant_fiscal_identity_id' => TenantFiscalIdentity::factory(),
            'interested_root_cnpj' => substr($cnpj, 0, 8),
            'query_cnpj' => $cnpj,
            'environment' => 'production',
            'channel' => CaptureChannel::NfeAutXmlDistDfe,
            'last_nsu' => 0,
            'status' => SyncCursorStatus::Idle,
            'external_consumer_status' => 'DECLARED_CLEAR',
        ];
    }

    public function forIdentity(TenantFiscalIdentity $identity): static
    {
        return $this->state(fn () => [
            'tenant_id' => $identity->tenant_id,
            'tenant_fiscal_identity_id' => $identity->id,
            'interested_root_cnpj' => $identity->root_cnpj,
            'query_cnpj' => $identity->cnpj,
        ]);
    }
}
