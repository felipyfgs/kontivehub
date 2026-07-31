<?php

namespace App\DTO\Work;

final readonly class EvidenceRemovalData
{
    public function __construct(public string $reason) {}
}
