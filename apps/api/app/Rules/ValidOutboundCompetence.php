<?php

namespace App\Rules;

use App\Domain\Outbound\Competence;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

final class ValidOutboundCompetence implements ValidationRule
{
    public function validate(
        string $attribute,
        mixed $value,
        Closure $fail,
    ): void {
        if (! is_string($value)) {
            $fail('A competência deve usar o formato YYYY-MM.');

            return;
        }

        try {
            Competence::fromString($value);
        } catch (InvalidArgumentException) {
            $fail('A competência deve usar um mês válido no formato YYYY-MM.');
        }
    }
}
