<?php

namespace App\DTO\Serpro;

use App\Models\SerproRolloutApproval;

final readonly class SerproRolloutApprovalResult
{
    /**
     * @param  array<string, mixed>  $killSwitch
     */
    public function __construct(
        public SerproRolloutApproval $approval,
        public bool $executed,
        public array $killSwitch,
    ) {}
}
