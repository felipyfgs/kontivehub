<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class DownloadPgdasdArtifactRequest extends SimplesMeiModuleReadRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function artifactId(): int
    {
        return (int) $this->route('artifact');
    }
}
