<?php

namespace App\Services\Fiscal\Mutations;

use App\Enums\FiscalMutationDenialCode;
use App\Models\FiscalMutationOperation;
use RuntimeException;

final class FiscalMutationException extends RuntimeException
{
    public function __construct(
        public readonly FiscalMutationDenialCode $denialCode,
        public readonly ?FiscalMutationOperation $operation = null,
        ?string $message = null,
    ) {
        parent::__construct($message ?? $denialCode->message());
    }

    public function httpStatus(): int
    {
        return match ($this->denialCode) {
            FiscalMutationDenialCode::NotFound => 404,
            FiscalMutationDenialCode::RoleForbidden,
            FiscalMutationDenialCode::TotpRequired,
            FiscalMutationDenialCode::TotpExpired,
            FiscalMutationDenialCode::SubscriptionBlocked,
            FiscalMutationDenialCode::DemoMode,
            FiscalMutationDenialCode::KillSwitch => 403,
            FiscalMutationDenialCode::RetryBlocked,
            FiscalMutationDenialCode::UncertainResultOpen,
            FiscalMutationDenialCode::AntiRepeatWindow,
            FiscalMutationDenialCode::IdempotencyConflict => 409,
            default => 422,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'message' => $this->getMessage(),
            'code' => $this->denialCode->value,
            'mutation_operation_id' => $this->operation?->id,
            'status' => $this->operation?->status->value,
        ];
    }
}
