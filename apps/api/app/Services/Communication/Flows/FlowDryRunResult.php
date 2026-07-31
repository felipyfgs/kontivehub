<?php

namespace App\Services\Communication\Flows;

/**
 * @phpstan-type DryRunStep array{
 *   seq: int,
 *   node_id: string,
 *   node_type: string,
 *   status: string,
 *   detail: array<string, mixed>
 * }
 */
final class FlowDryRunResult
{
    /**
     * @param  list<DryRunStep>  $steps
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    public function __construct(
        public readonly bool $valid,
        public readonly string $graphDigest,
        public readonly string $outcome,
        public readonly array $steps,
        public readonly array $errors = [],
    ) {}

    /**
     * @param  list<DryRunStep>  $steps
     */
    public static function ok(string $digest, string $outcome, array $steps): self
    {
        return new self(true, $digest, $outcome, $steps);
    }

    /**
     * @param  list<array{path: string, code: string, message: string}>  $errors
     */
    public static function invalid(string $digest, array $errors): self
    {
        return new self(false, $digest, 'invalid', [], $errors);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'valid' => $this->valid,
            'graph_digest' => $this->graphDigest,
            'outcome' => $this->outcome,
            'steps' => $this->steps,
            'errors' => $this->errors,
            'side_effects' => [
                'outbox_created' => false,
                'flow_run_persisted' => false,
                'correlation_jobs_dispatched' => false,
                'gateway_called' => false,
            ],
        ];
    }
}
