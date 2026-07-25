<?php

namespace App\Casts;

use App\Enums\TermoAuthorizationState;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/** Estado SIMULATED legado é lido somente como rejeitado/quarentenado. */
final class TermoAuthorizationStateCast implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?TermoAuthorizationState
    {
        if ($value === null || $value === '') {
            return null;
        }

        return TermoAuthorizationState::tryFrom((string) $value) ?? TermoAuthorizationState::Rejected;
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $state = $value instanceof TermoAuthorizationState ? $value : TermoAuthorizationState::tryFrom((string) $value);

        return ($state ?? TermoAuthorizationState::Rejected)->value;
    }
}
