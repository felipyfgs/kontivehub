<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class DownloadCcmeiCertificateRequest extends CcmeiReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function certificateId(): int
    {
        return (int) $this->route('certificate');
    }
}
