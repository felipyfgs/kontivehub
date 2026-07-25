<?php

namespace Database\Factories;

use App\Enums\CaptureChannel;
use App\Enums\SyncCursorStatus;
use App\Models\Office;
use App\Models\OfficeDistributionCursor;
use App\Models\OfficeFiscalIdentity;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OfficeDistributionCursor>
 */
class OfficeDistributionCursorFactory extends Factory
{
    protected $model = OfficeDistributionCursor::class;

    public function definition(): array
    {
        $cnpj = '11222333000181';

        return [
            'office_id' => Office::factory(),
            'office_fiscal_identity_id' => OfficeFiscalIdentity::factory(),
            'interested_root_cnpj' => substr($cnpj, 0, 8),
            'query_cnpj' => $cnpj,
            'environment' => 'production',
            'channel' => CaptureChannel::NfeAutXmlDistDfe,
            'last_nsu' => 0,
            'status' => SyncCursorStatus::Idle,
            'external_consumer_status' => 'DECLARED_CLEAR',
        ];
    }

    public function forIdentity(OfficeFiscalIdentity $identity): static
    {
        return $this->state(fn () => [
            'office_id' => $identity->office_id,
            'office_fiscal_identity_id' => $identity->id,
            'interested_root_cnpj' => $identity->root_cnpj,
            'query_cnpj' => $identity->cnpj,
        ]);
    }
}
