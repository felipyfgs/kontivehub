<?php

namespace App\Services\Communication\Flows;

/**
 * @phpstan-type FlowGraphError array{path: string, code: string, message: string}
 */
final readonly class FlowGraphValidationResult
{
    /**
     * @param  list<FlowGraphError>  $errors
     */
    public function __construct(
        public bool $valid,
        public string $digest,
        public array $errors = [],
    ) {}

    public static function ok(string $digest): self
    {
        return new self(true, $digest, []);
    }

    /**
     * @param  list<FlowGraphError>  $errors
     */
    public static function invalid(string $digest, array $errors): self
    {
        return new self(false, $digest, $errors);
    }
}
