<?php

namespace App\Http\Requests\Fiscal\Monitoring;

final class DownloadMeiAutomationArtifactRequest extends ViewMeiAutomationAttemptRequest
{
    public function artifactId(): string
    {
        return (string) $this->route('artifact');
    }
}
