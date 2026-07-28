<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class ViewMailboxMonitoringRequest extends MailboxReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }
}
