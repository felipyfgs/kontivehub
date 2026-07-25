<?php

namespace App\DTO\MeiAutomation;

use App\Enums\MeiAutomationStatus;

final readonly class MeiAutomationJobResult
{
    /**
     * @param  array<string, mixed>|null  $result
     * @param  array<string, mixed>|null  $error
     * @param  list<array<string, mixed>>  $artifacts
     */
    public function __construct(
        public string $id,
        public string $operationKey,
        public MeiAutomationStatus $status,
        public ?array $result,
        public ?array $error,
        public array $artifacts,
        public ?string $actionType,
        public ?string $captchaDriver = null,
        public int $captchaCostMicros = 0,
    ) {}

    /** @param array<string, mixed> $payload */
    public static function fromArray(array $payload): self
    {
        return new self(
            id: (string) ($payload['id'] ?? ''),
            operationKey: (string) ($payload['operation_key'] ?? ''),
            status: MeiAutomationStatus::from((string) ($payload['status'] ?? 'FAILED')),
            result: is_array($payload['result'] ?? null) ? $payload['result'] : null,
            error: is_array($payload['error'] ?? null) ? $payload['error'] : null,
            artifacts: is_array($payload['artifacts'] ?? null) ? array_values($payload['artifacts']) : [],
            actionType: isset($payload['action_type']) ? (string) $payload['action_type'] : null,
            captchaDriver: isset($payload['captcha_driver']) ? (string) $payload['captcha_driver'] : null,
            captchaCostMicros: max(0, (int) ($payload['captcha_cost_micros'] ?? 0)),
        );
    }
}
