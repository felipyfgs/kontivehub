<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class ListPnrRenunciationsRequest extends FiscalMonitoringViewRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function clientId(): int
    {
        return (int) $this->route('clientId');
    }
}
