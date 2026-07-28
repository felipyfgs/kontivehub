<?php

namespace App\Rules;

use App\Services\Communication\Canned\CannedResponseRenderer;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

final class AllowedCommunicationCannedResponsePlaceholders implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            return;
        }

        $disallowed = CannedResponseRenderer::disallowedPlaceholders($value);
        if ($disallowed === []) {
            return;
        }

        $fail('O corpo contém placeholders fora da allowlist: '.implode(', ', array_map(
            static fn (string $token): string => '{{'.$token.'}}',
            $disallowed,
        )));
    }
}
