<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class DownloadDctfwebEvidenceRequest extends DctfwebReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function evidenceId(): int
    {
        return (int) $this->route('evidence');
    }

    public function clientId(): ?int
    {
        $client = $this->route('client');

        return $client !== null ? (int) $client : null;
    }
}
