<?php

namespace App\Services\Fiscal\Mutations;

use App\Enums\FiscalMutationDenialCode;

/**
 * Resultado da policy comum de operação mutante (13.1).
 */
final class MutationPolicyResult
{
    /**
     * @param  list<FiscalMutationDenialCode>  $codes
     * @param  array<string, mixed>  $context  sanitizado
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly array $codes = [],
        public readonly array $context = [],
        public readonly bool $confirmationRequired = true,
        public readonly bool $totpRequired = true,
    ) {}

    public static function allow(array $context = [], bool $confirmationRequired = true): self
    {
        return new self(
            allowed: true,
            codes: [],
            context: $context,
            confirmationRequired: $confirmationRequired,
            totpRequired: true,
        );
    }

    /**
     * @param  list<FiscalMutationDenialCode>|FiscalMutationDenialCode  $codes
     */
    public static function deny(array|FiscalMutationDenialCode $codes, array $context = []): self
    {
        $list = is_array($codes) ? $codes : [$codes];
        $list = array_values(array_filter(
            $list,
            fn ($c) => $c instanceof FiscalMutationDenialCode,
        ));

        return new self(
            allowed: false,
            codes: $list,
            context: $context,
            confirmationRequired: true,
            totpRequired: true,
        );
    }

    public function primaryCode(): ?FiscalMutationDenialCode
    {
        return $this->codes[0] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'allowed' => $this->allowed,
            'codes' => array_map(fn (FiscalMutationDenialCode $c) => $c->value, $this->codes),
            'primary_code' => $this->primaryCode()?->value,
            'messages' => array_map(fn (FiscalMutationDenialCode $c) => $c->message(), $this->codes),
            'confirmation_required' => $this->confirmationRequired,
            'totp_required' => $this->totpRequired,
            'context' => $this->context,
        ];
    }
}
