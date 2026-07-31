<?php

namespace App\DTO\Work;

final readonly class ProcessLockData
{
    public function __construct(public int $lockVersion) {}
}
