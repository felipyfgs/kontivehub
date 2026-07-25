<?php

namespace App\DTO\Serpro;

use App\Enums\SerproEligibilityCode;

final class EligibilityResult
{
    /**
     * @param  list<SerproEligibilityCode>  $codes
     * @param  array<string, mixed>  $context  Metadados sanitizados
     */
    public function __construct(
        public readonly bool $eligible,
        public readonly array $codes,
        public readonly array $context = [],
    ) {}

    public static function ok(array $context = []): self
    {
        return new self(true, [SerproEligibilityCode::Eligible], $context);
    }

    public static function blocked(SerproEligibilityCode $code, array $context = []): self
    {
        return new self(false, [$code], $context);
    }

    /**
     * @param  list<SerproEligibilityCode>  $codes
     */
    public static function blockedMany(array $codes, array $context = []): self
    {
        $codes = array_values(array_filter(
            $codes,
            fn (SerproEligibilityCode $c) => $c !== SerproEligibilityCode::Eligible,
        ));

        if ($codes === []) {
            return self::ok($context);
        }

        return new self(false, $codes, $context);
    }

    public function primaryCode(): SerproEligibilityCode
    {
        return $this->codes[0] ?? SerproEligibilityCode::Eligible;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'codes' => array_map(fn (SerproEligibilityCode $c) => $c->value, $this->codes),
            'primary_code' => $this->primaryCode()->value,
            'context' => $this->context,
        ];
    }
}
