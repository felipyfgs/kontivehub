<?php

namespace App\Rules;

use App\Domain\Cnpj;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class ValidCnpj implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Cnpj::tryParse($value) === null) {
            $fail('O :attribute deve ser um CNPJ válido (14 caracteres, numérico ou alfanumérico).');
        }
    }
}
