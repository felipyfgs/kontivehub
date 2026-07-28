<?php

namespace App\Rules;

use App\Services\Communication\WhatsappAddressNormalizer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final readonly class ValidWhatsappAddress implements ValidationRule
{
    public function __construct(
        private WhatsappAddressNormalizer $normalizer,
    ) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        try {
            $this->normalizer->normalize($value);
        } catch (InvalidArgumentException) {
            $fail('O campo :attribute deve conter um endereço WhatsApp válido.');
        }
    }
}
