<?php

namespace App\DTO\FgtsDigital;

use App\Models\FgtsDigitalRun;

final readonly class FgtsDigitalPreviewResult
{
    public function __construct(
        public FgtsDigitalRun $run,
        public ?string $previewToken,
    ) {}
}
