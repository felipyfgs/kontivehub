<?php

namespace App\Http\Requests\Fiscal\Mutations;

final class ConfirmTaxGuidePaymentRequest extends TaxGuideWriteRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tenant_id' => ['prohibited'],
        ];
    }
}
