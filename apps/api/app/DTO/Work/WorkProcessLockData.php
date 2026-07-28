<?php

namespace App\DTO\Work;

final readonly class WorkProcessLockData
{
    public function __construct(public int $lockVersion) {}
}
