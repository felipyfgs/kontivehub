<?php

namespace App\DTO\Work;

final readonly class WorkEvidenceRemovalData
{
    public function __construct(public string $reason) {}
}
