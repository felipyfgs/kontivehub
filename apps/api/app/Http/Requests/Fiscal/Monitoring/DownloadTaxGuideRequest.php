<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class DownloadTaxGuideRequest extends TaxGuideReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
