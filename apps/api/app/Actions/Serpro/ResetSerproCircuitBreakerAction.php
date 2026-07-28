<?php

namespace App\Actions\Serpro;

use App\DTO\Serpro\SerproCircuitBreakerResetData;
use App\Models\User;
use App\Services\Serpro\SerproCircuitBreaker;

final readonly class ResetSerproCircuitBreakerAction
{
    public function __construct(
        private SerproCircuitBreaker $breaker,
    ) {}

    /** @return array{state: string, open_until: int|null, failures: int, half_open_probes?: int} */
    public function __invoke(SerproCircuitBreakerResetData $data, User $actor): array
    {
        $this->breaker->resetGlobal($data->reason, $actor->id);

        return $this->breaker->globalStatus();
    }
}
